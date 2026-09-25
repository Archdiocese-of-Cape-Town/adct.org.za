<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Ports\SourceHealthStoreInterface;
use ADCT\ParishIntake\Core\Ports\SourceStoreInterface;
use ADCT\ParishIntake\Core\Sources\Source;
use ADCT\ParishIntake\Core\Sources\SourceHealthState;
use ADCT\ParishIntake\Core\Sources\SourceRole;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use ADCT\ParishIntake\Core\Sources\SourceType;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SourceRepository extends AbstractRepository implements SourceStoreInterface, SourceHealthStoreInterface
{
    protected const TABLE_SUFFIX = 'adct_pi_sources';

    protected const FIELD_FORMATS = [
        'parish_id' => '%d',
        'type' => '%s',
        'identifier' => '%s',
        'role' => '%s',
        'status' => '%s',
        'poll_interval_minutes' => '%d',
        'checkpoint' => '%s',
        'last_checked_at' => '%s',
        'last_success_at' => '%s',
        'last_item_at' => '%s',
        'consecutive_failures' => '%d',
        'last_error' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    public function findSource(int $sourceId): ?Source
    {
        if ($sourceId < 1) {
            return null;
        }

        $row = $this->fetchRow($this->database->prepare(
            'SELECT * FROM ' . $this->tableName() . ' WHERE id = %d LIMIT 1',
            $sourceId
        ));

        return $row === null ? null : $this->mapSource($row);
    }

    public function findGlobalEmailSource(string $email): ?Source
    {
        $identifier = SourceType::normalizeIdentifier(SourceType::EMAIL, $email);
        $row = $this->fetchRow($this->database->prepare(
            'SELECT * FROM ' . $this->tableName()
            . ' WHERE parish_id IS NULL AND type = %s AND identifier = %s ORDER BY id ASC LIMIT 1',
            SourceType::EMAIL,
            $identifier
        ));

        return $row === null ? null : $this->mapSource($row);
    }

    /**
     * @return list<Source>
     */
    public function findForParish(int $parishId): array
    {
        if ($parishId < 1) {
            throw new InvalidArgumentException('A parish ID must be positive.');
        }

        $table = $this->tableName();
        $rows = $this->fetchRows($this->database->prepare(
            "SELECT * FROM {$table} WHERE parish_id = %d "
            . "ORDER BY CASE WHEN role = 'official' THEN 0 ELSE 1 END, type ASC, id ASC",
            $parishId
        ));

        return array_map(fn (array $row): Source => $this->mapSource($row), $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function findForAdmin(array $filters, int $limit, int $offset): array
    {
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        [$where, $arguments] = $this->buildAdminWhere($filters);
        $query = 'SELECT s.*, p.name AS parish_name, p.slug AS parish_slug '
            . 'FROM ' . $this->tableName() . " s LEFT JOIN {$parishes} p ON p.id = s.parish_id"
            . $where
            . ' ORDER BY (s.parish_id IS NULL) DESC, p.name ASC, s.type ASC, s.identifier ASC, s.id ASC'
            . ' LIMIT %d OFFSET %d';
        $arguments[] = max(1, min(100, $limit));
        $arguments[] = max(0, $offset);

        return $this->fetchRows($this->database->prepare($query, ...$arguments));
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countForAdmin(array $filters): int
    {
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        [$where, $arguments] = $this->buildAdminWhere($filters);
        $query = 'SELECT COUNT(*) AS total FROM ' . $this->tableName()
            . " s LEFT JOIN {$parishes} p ON p.id = s.parish_id"
            . $where;
        $prepared = $arguments === []
            ? $query
            : $this->database->prepare($query, ...$arguments);
        $row = $this->fetchRow($prepared);

        return (int) ($row['total'] ?? 0);
    }

    public function saveSource(Source $source, string $timestamp): Source
    {
        $this->beginTransaction();

        try {
            $saved = $this->saveSourceWithoutTransaction($source, $timestamp);
            $this->commitTransaction();

            return $saved;
        } catch (Throwable $failure) {
            $this->rollbackTransaction();
            throw $failure;
        }
    }

    public function registerOfficialEmailSourceIfMissing(
        int $parishId,
        string $email,
        string $timestamp
    ): bool {
        if ($parishId < 1) {
            throw new InvalidArgumentException('A parish ID must be positive.');
        }

        $email = SourceType::normalizeIdentifier(SourceType::EMAIL, $email);
        $this->beginTransaction();

        try {
            $this->lockParish($parishId);
            $table = $this->tableName();
            $official = $this->fetchRow($this->database->prepare(
                "SELECT id FROM {$table} WHERE parish_id = %d AND role = %s ORDER BY id ASC LIMIT 1 FOR UPDATE",
                $parishId,
                SourceRole::OFFICIAL
            ));

            if ($official !== null) {
                $this->commitTransaction();

                return false;
            }

            $existingRow = $this->fetchRow($this->database->prepare(
                "SELECT * FROM {$table} WHERE parish_id = %d AND type = %s AND identifier = %s LIMIT 1 FOR UPDATE",
                $parishId,
                SourceType::EMAIL,
                $email
            ));

            if ($existingRow === null) {
                $source = new Source(
                    0,
                    $parishId,
                    SourceType::EMAIL,
                    $email,
                    SourceRole::OFFICIAL
                );
            } else {
                $existing = $this->mapSource($existingRow);
                $source = new Source(
                    $existing->id,
                    $parishId,
                    SourceType::EMAIL,
                    $email,
                    SourceRole::OFFICIAL,
                    $existing->status,
                    $existing->pollIntervalMinutes,
                    $existing->lastCheckedAt,
                    $existing->lastSuccessAt,
                    $existing->lastItemAt,
                    $existing->consecutiveFailures,
                    $existing->lastError
                );
            }

            $this->saveSourceWithoutTransaction($source, $timestamp, true);
            $this->commitTransaction();

            return true;
        } catch (Throwable $failure) {
            $this->rollbackTransaction();
            throw $failure;
        }
    }

    public function findHealth(int $sourceId): ?SourceHealthState
    {
        if ($sourceId < 1) {
            throw new InvalidArgumentException('A source ID must be positive.');
        }

        $row = $this->fetchRow($this->database->prepare(
            'SELECT status, last_checked_at, last_success_at, last_item_at, consecutive_failures, last_error '
            . 'FROM ' . $this->tableName() . ' WHERE id = %d LIMIT 1',
            $sourceId
        ));

        return $row === null ? null : $this->mapHealth($row);
    }

    public function saveHealthIfUnchanged(
        int $sourceId,
        SourceHealthState $expected,
        SourceHealthState $replacement,
        string $updatedAt
    ): bool {
        if ($sourceId < 1) {
            throw new InvalidArgumentException('A source ID must be positive.');
        }

        $table = $this->tableName();
        $arguments = [$replacement->status];
        $lastCheckedPlaceholder = $this->nullablePlaceholder($replacement->lastCheckedAt, $arguments);
        $lastSuccessPlaceholder = $this->nullablePlaceholder($replacement->lastSuccessAt, $arguments);
        $lastItemPlaceholder = $this->nullablePlaceholder($replacement->lastItemAt, $arguments);
        $arguments[] = $replacement->consecutiveFailures;
        $lastErrorPlaceholder = $this->nullablePlaceholder($replacement->lastError, $arguments);
        $arguments[] = $updatedAt;
        $conditions = ['id = %d', 'status = %s', 'consecutive_failures = %d'];
        $whereArguments = [$sourceId, $expected->status, $expected->consecutiveFailures];
        $conditions[] = $this->nullableCondition('last_checked_at', $expected->lastCheckedAt, $whereArguments);
        $conditions[] = $this->nullableCondition('last_success_at', $expected->lastSuccessAt, $whereArguments);
        $conditions[] = $this->nullableCondition('last_item_at', $expected->lastItemAt, $whereArguments);
        $conditions[] = $this->nullableCondition('last_error', $expected->lastError, $whereArguments);
        array_push($arguments, ...$whereArguments);
        $query = "UPDATE {$table} SET status = %s, last_checked_at = {$lastCheckedPlaceholder}, "
            . "last_success_at = {$lastSuccessPlaceholder}, last_item_at = {$lastItemPlaceholder}, "
            . "consecutive_failures = %d, last_error = {$lastErrorPlaceholder}, updated_at = %s "
            . 'WHERE ' . implode(' AND ', $conditions);
        $this->database->clearLastError();
        $result = $this->database->query($this->database->prepare($query, ...$arguments));

        if ($result === false) {
            throw new RuntimeException(
                'The source health could not be saved: ' . $this->database->lastError()
            );
        }

        return $result > 0;
    }

    private function saveSourceWithoutTransaction(
        Source $source,
        string $timestamp,
        bool $parishAlreadyLocked = false
    ): Source {
        if ($source->parishId !== null && ! $parishAlreadyLocked) {
            $this->lockParish($source->parishId);
        }

        if ($source->id > 0) {
            $current = $this->fetchRow($this->database->prepare(
                'SELECT id, parish_id FROM ' . $this->tableName() . ' WHERE id = %d LIMIT 1 FOR UPDATE',
                $source->id
            ));

            if (
                $current === null
                || $this->nullableId($current['parish_id'] ?? null) !== $source->parishId
            ) {
                throw new DomainException('The source could not be found in this parish.');
            }
        }

        $this->assertIdentifierAvailable($source);
        $values = [
            'parish_id' => $source->parishId,
            'type' => $source->type,
            'identifier' => $source->identifier,
            'role' => $source->role,
            'status' => $source->status,
            'poll_interval_minutes' => $source->pollIntervalMinutes,
            'updated_at' => $timestamp,
        ];

        if ($source->id > 0) {
            $this->update($source->id, $values);
            $sourceId = $source->id;
        } else {
            $values['created_at'] = $timestamp;
            $sourceId = $this->insert($values);
        }

        if ($source->parishId !== null) {
            if ($source->role === SourceRole::OFFICIAL) {
                $this->demotePreviousOfficial($source->parishId, $sourceId, $timestamp);
                $this->setOfficialSource($source->parishId, $sourceId, $timestamp);
            } else {
                $this->clearOfficialSource($source->parishId, $sourceId, $timestamp);
            }
        }

        $saved = $this->findSource($sourceId);

        if ($saved === null) {
            throw new RuntimeException('The saved source could not be read back.');
        }

        return $saved;
    }

    private function assertIdentifierAvailable(Source $source): void
    {
        $table = $this->tableName();
        $parishCondition = $source->parishId === null ? 'parish_id IS NULL' : 'parish_id = %d';
        $query = "SELECT id FROM {$table} WHERE {$parishCondition} AND type = %s "
            . 'AND identifier = %s AND id <> %d LIMIT 1 FOR UPDATE';
        $arguments = [];

        if ($source->parishId !== null) {
            $arguments[] = $source->parishId;
        }

        array_push($arguments, $source->type, $source->identifier, $source->id);
        $duplicate = $this->fetchRow($this->database->prepare($query, ...$arguments));

        if ($duplicate !== null) {
            throw new DomainException('This source identifier is already registered in the same scope.');
        }
    }

    private function lockParish(int $parishId): void
    {
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        $row = $this->fetchRow($this->database->prepare(
            "SELECT id FROM {$parishes} WHERE id = %d LIMIT 1 FOR UPDATE",
            $parishId
        ));

        if ($row === null || (int) ($row['id'] ?? 0) !== $parishId) {
            throw new DomainException('The parish for this source could not be found.');
        }
    }

    private function demotePreviousOfficial(int $parishId, int $sourceId, string $timestamp): void
    {
        $table = $this->tableName();
        $query = $this->database->prepare(
            "UPDATE {$table} SET role = %s, updated_at = %s "
            . 'WHERE parish_id = %d AND role = %s AND id <> %d',
            SourceRole::MONITORED,
            $timestamp,
            $parishId,
            SourceRole::OFFICIAL,
            $sourceId
        );
        $this->executeQuery($query, 'demote the previous official parish source');
    }

    private function setOfficialSource(int $parishId, int $sourceId, string $timestamp): void
    {
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        $query = $this->database->prepare(
            "UPDATE {$parishes} SET official_source_id = %d, updated_at = %s WHERE id = %d",
            $sourceId,
            $timestamp,
            $parishId
        );
        $this->executeQuery($query, 'set the parish official source');
    }

    private function clearOfficialSource(int $parishId, int $sourceId, string $timestamp): void
    {
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        $query = $this->database->prepare(
            "UPDATE {$parishes} SET official_source_id = NULL, updated_at = %s "
            . 'WHERE id = %d AND official_source_id = %d',
            $timestamp,
            $parishId,
            $sourceId
        );
        $this->executeQuery($query, 'clear the parish official source');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<scalar>}
     */
    private function buildAdminWhere(array $filters): array
    {
        $conditions = [];
        $arguments = [];
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $pattern = '%' . $this->database->escapeLike($search) . '%';
            $conditions[] = '(s.identifier LIKE %s OR p.name LIKE %s)';
            array_push($arguments, $pattern, $pattern);
        }

        $parishId = $filters['parish_id'] ?? null;

        if ($parishId !== null && $parishId !== '') {
            if (! is_numeric($parishId) || (int) $parishId < 0) {
                throw new InvalidArgumentException('The parish source filter is not valid.');
            }

            if ((int) $parishId === 0) {
                $conditions[] = 's.parish_id IS NULL';
            } else {
                $conditions[] = 's.parish_id = %d';
                $arguments[] = (int) $parishId;
            }
        }

        $type = trim((string) ($filters['type'] ?? ''));

        if ($type !== '') {
            SourceType::assertValid($type);
            $conditions[] = 's.type = %s';
            $arguments[] = $type;
        }

        $role = trim((string) ($filters['role'] ?? ''));

        if ($role !== '') {
            SourceRole::assertValid($role);
            $conditions[] = 's.role = %s';
            $arguments[] = $role;
        }

        $status = trim((string) ($filters['status'] ?? ''));

        if ($status !== '') {
            SourceStatus::assertValid($status);
            $conditions[] = 's.status = %s';
            $arguments[] = $status;
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $arguments,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapSource(array $row): Source
    {
        $sourceId = (int) ($row['id'] ?? 0);
        $parishId = $this->nullableId($row['parish_id'] ?? null);

        if ($sourceId < 1) {
            throw new RuntimeException('A stored source has an invalid ID.');
        }

        try {
            return new Source(
                $sourceId,
                $parishId,
                (string) ($row['type'] ?? ''),
                (string) ($row['identifier'] ?? ''),
                (string) ($row['role'] ?? ''),
                (string) ($row['status'] ?? ''),
                $this->nullableId($row['poll_interval_minutes'] ?? null),
                $this->nullableString($row['last_checked_at'] ?? null),
                $this->nullableString($row['last_success_at'] ?? null),
                $this->nullableString($row['last_item_at'] ?? null),
                (int) ($row['consecutive_failures'] ?? 0),
                $this->nullableString($row['last_error'] ?? null)
            );
        } catch (InvalidArgumentException $failure) {
            throw new RuntimeException('A stored source has invalid registry data.', 0, $failure);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapHealth(array $row): SourceHealthState
    {
        try {
            return new SourceHealthState(
                (string) ($row['status'] ?? ''),
                $this->nullableString($row['last_checked_at'] ?? null),
                $this->nullableString($row['last_success_at'] ?? null),
                $this->nullableString($row['last_item_at'] ?? null),
                (int) ($row['consecutive_failures'] ?? 0),
                $this->nullableString($row['last_error'] ?? null)
            );
        } catch (InvalidArgumentException $failure) {
            throw new RuntimeException('A stored source has invalid health data.', 0, $failure);
        }
    }

    /**
     * @param list<scalar> $arguments
     */
    private function nullablePlaceholder(?string $value, array &$arguments): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $arguments[] = $value;

        return '%s';
    }

    /**
     * @param list<scalar> $arguments
     */
    private function nullableCondition(string $column, ?string $value, array &$arguments): string
    {
        if ($value === null) {
            return $column . ' IS NULL';
        }

        $arguments[] = $value;

        return $column . ' = %s';
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        return $text === '' ? null : $text;
    }

    private function beginTransaction(): void
    {
        $this->executeQuery('START TRANSACTION', 'start a source update');
    }

    private function commitTransaction(): void
    {
        $this->executeQuery('COMMIT', 'commit a source update');
    }

    private function rollbackTransaction(): void
    {
        $this->database->clearLastError();
        $this->database->query('ROLLBACK');
    }

    private function executeQuery(string $query, string $operation): void
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false) {
            throw new RuntimeException(
                'Could not ' . $operation . ': ' . $this->database->lastError()
            );
        }
    }
}
