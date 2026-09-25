<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Directory\Venue;
use ADCT\ParishIntake\Core\Ports\VenueStoreInterface;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class VenueRepository extends AbstractRepository implements VenueStoreInterface
{
    protected const TABLE_SUFFIX = 'adct_pi_venues';

    protected const FIELD_FORMATS = [
        'parish_id' => '%d',
        'source_parish_id' => '%d',
        'name' => '%s',
        'aliases' => '%s',
        'address' => '%s',
        'suburb' => '%s',
        'latitude' => '%f',
        'longitude' => '%f',
        'is_default' => '%d',
        'status' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    public function findActiveVenues(): array
    {
        $venues = $this->database->prefix() . static::TABLE_SUFFIX;
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        $query = "SELECT v.* FROM {$venues} v "
            . "INNER JOIN {$parishes} p ON p.id = v.parish_id "
            . "WHERE v.status = 'active' AND p.status = 'active' "
            . 'ORDER BY v.parish_id ASC, v.id ASC';

        return array_map(
            fn (array $row): Venue => $this->mapVenue($row),
            $this->fetchRows($query)
        );
    }

    public function findForParish(int $parishId): array
    {
        $this->assertPositiveId($parishId, 'A parish ID must be positive.');
        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT * FROM {$table} WHERE parish_id = %d ORDER BY id ASC",
            $parishId
        );

        return array_map(
            fn (array $row): Venue => $this->mapVenue($row),
            $this->fetchRows($query)
        );
    }

    public function findVenue(int $venueId): ?Venue
    {
        $this->assertPositiveId($venueId, 'A venue ID must be positive.');
        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
            $venueId
        );
        $row = $this->fetchRow($query);

        return $row === null ? null : $this->mapVenue($row);
    }

    public function saveVenue(Venue $venue, string $timestamp): Venue
    {
        $this->assertVenue($venue);
        $this->beginTransaction();

        try {
            $this->lockParish($venue->parishId);
            $current = null;

            if ($venue->id > 0) {
                $current = $this->findVenue($venue->id);

                if ($current === null || $current->parishId !== $venue->parishId) {
                    throw new DomainException('The parish venue could not be found.');
                }

                if ($current->isDefault && ! $venue->isDefault && $venue->status === Venue::ACTIVE) {
                    throw new DomainException('Set another active venue as the parish default before unsetting this one.');
                }
            }

            $values = $this->values($venue, $timestamp);

            if ($venue->id > 0) {
                $this->update($venue->id, $values);
                $venueId = $venue->id;
            } else {
                $values['created_at'] = $timestamp;
                $venueId = $this->insert($values);
            }

            if ($venue->status === Venue::ACTIVE && $venue->isDefault) {
                $this->setDefaultWithoutTransaction($venue->parishId, $venueId, $timestamp);
            } else {
                $this->ensureDefaultFlagWithoutTransaction($venue->parishId, $timestamp);
            }

            $saved = $this->findVenue($venueId);

            if ($saved === null) {
                throw new RuntimeException('The saved parish venue could not be read back.');
            }

            $this->commitTransaction();

            return $saved;
        } catch (Throwable $failure) {
            $this->rollbackTransaction();
            throw $failure;
        }
    }

    public function setDefaultForParish(int $parishId, int $venueId, string $timestamp): void
    {
        $this->assertPositiveId($parishId, 'A parish ID must be positive.');
        $this->assertPositiveId($venueId, 'A venue ID must be positive.');
        $this->beginTransaction();

        try {
            $this->lockParish($parishId);
            $table = $this->tableName();
            $target = $this->fetchRow($this->database->prepare(
                "SELECT id, parish_id, status FROM {$table} WHERE id = %d LIMIT 1",
                $venueId
            ));

            if (
                $target === null
                || (int) ($target['parish_id'] ?? 0) !== $parishId
                || (string) ($target['status'] ?? '') !== Venue::ACTIVE
            ) {
                throw new DomainException('Choose an active venue belonging to this parish.');
            }

            $this->setDefaultWithoutTransaction($parishId, $venueId, $timestamp);
            $this->commitTransaction();
        } catch (Throwable $failure) {
            $this->rollbackTransaction();
            throw $failure;
        }
    }

    public function deactivateVenue(
        int $parishId,
        int $venueId,
        ?int $replacementDefaultVenueId,
        string $timestamp
    ): void {
        $this->assertPositiveId($parishId, 'A parish ID must be positive.');
        $this->assertPositiveId($venueId, 'A venue ID must be positive.');
        $this->beginTransaction();

        try {
            $this->lockParish($parishId);
            $venue = $this->findVenue($venueId);

            if ($venue === null || $venue->parishId !== $parishId) {
                throw new DomainException('The parish venue could not be found.');
            }

            if ($replacementDefaultVenueId === null && $venue->isDefault) {
                throw new DomainException('An active replacement venue is required before deactivation.');
            }

            if ($replacementDefaultVenueId !== null) {
                $this->assertActiveVenueForParish($parishId, $replacementDefaultVenueId);
            }

            $this->update($venueId, [
                'status' => Venue::INACTIVE,
                'is_default' => 0,
                'updated_at' => $timestamp,
            ]);

            if ($replacementDefaultVenueId !== null) {
                $this->setDefaultWithoutTransaction($parishId, $replacementDefaultVenueId, $timestamp);
            } else {
                $this->ensureDefaultFlagWithoutTransaction($parishId, $timestamp);
            }

            $this->commitTransaction();
        } catch (Throwable $failure) {
            $this->rollbackTransaction();
            throw $failure;
        }
    }

    public function reactivateVenue(
        int $parishId,
        int $venueId,
        bool $makeDefault,
        string $timestamp
    ): void {
        $this->assertPositiveId($parishId, 'A parish ID must be positive.');
        $this->assertPositiveId($venueId, 'A venue ID must be positive.');
        $this->beginTransaction();

        try {
            $this->lockParish($parishId);
            $venue = $this->findVenue($venueId);

            if ($venue === null || $venue->parishId !== $parishId) {
                throw new DomainException('The parish venue could not be found.');
            }

            $this->update($venueId, [
                'status' => Venue::ACTIVE,
                'is_default' => 0,
                'updated_at' => $timestamp,
            ]);

            if ($makeDefault) {
                $this->setDefaultWithoutTransaction($parishId, $venueId, $timestamp);
            } else {
                $this->ensureDefaultFlagWithoutTransaction($parishId, $timestamp);
            }

            $this->commitTransaction();
        } catch (Throwable $failure) {
            $this->rollbackTransaction();
            throw $failure;
        }
    }

    public function ensureDefaultVenue(Venue $venue, string $timestamp): bool
    {
        $this->assertVenue($venue);
        $this->assertPositiveId($venue->parishId, 'A parish ID must be positive.');
        $this->beginTransaction();

        try {
            $this->lockParish($venue->parishId);
            $existing = $this->findForParish($venue->parishId);
            $changed = false;

            if ($existing === []) {
                $values = $this->values($this->withDefault($venue), $timestamp);
                $values['created_at'] = $timestamp;
                $venueId = $this->insert($values);
                $this->setDefaultWithoutTransaction($venue->parishId, $venueId, $timestamp);
                $changed = true;
            } else {
                $active = array_values(array_filter(
                    $existing,
                    static fn (Venue $record): bool => $record->status === Venue::ACTIVE
                ));
                $defaults = array_values(array_filter(
                    $active,
                    static fn (Venue $record): bool => $record->isDefault
                ));

                if ($active !== [] && count($defaults) !== 1) {
                    $this->setDefaultWithoutTransaction(
                        $venue->parishId,
                        $defaults[0]->id ?? $active[0]->id,
                        $timestamp
                    );
                    $changed = true;
                }
            }

            $this->commitTransaction();

            return $changed;
        } catch (Throwable $failure) {
            $this->rollbackTransaction();
            throw $failure;
        }
    }

    public function insertImportedVenue(Venue $venue, string $timestamp): bool
    {
        $this->assertVenue($venue);

        if ($venue->sourceParishId === null || $venue->sourceParishId < 1 || $venue->id !== 0) {
            throw new InvalidArgumentException('An imported venue needs a source parish and no venue ID.');
        }

        if ($venue->isDefault) {
            throw new InvalidArgumentException('An imported outstation venue cannot replace the parish default.');
        }

        $values = $this->values($venue, $timestamp);
        $values['created_at'] = $timestamp;
        $columns = [];
        $placeholders = [];
        $arguments = [];

        foreach ($values as $column => $value) {
            $columns[] = '`' . $column . '`';

            if ($value === null) {
                $placeholders[] = 'NULL';
                continue;
            }

            $placeholders[] = static::FIELD_FORMATS[$column];
            $arguments[] = $value;
        }

        $table = $this->tableName();
        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE id = id',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $prepared = $arguments === []
            ? $query
            : $this->database->prepare($query, ...$arguments);
        $this->database->clearLastError();
        $result = $this->database->query($prepared);

        if ($result === false) {
            throw new RuntimeException(
                'The imported outstation venue could not be saved: ' . $this->database->lastError()
            );
        }

        return $result > 0;
    }

    private function setDefaultWithoutTransaction(int $parishId, int $venueId, string $timestamp): void
    {
        $table = $this->tableName();
        $clearQuery = $this->database->prepare(
            "UPDATE {$table} SET is_default = 0, updated_at = %s "
            . 'WHERE parish_id = %d AND is_default <> 0',
            $timestamp,
            $parishId
        );
        $this->executeQuery($clearQuery, 'clear the previous parish default');

        $setQuery = $this->database->prepare(
            "UPDATE {$table} SET is_default = 1, updated_at = %s "
            . "WHERE id = %d AND parish_id = %d AND status = 'active'",
            $timestamp,
            $venueId,
            $parishId
        );
        $this->executeQuery($setQuery, 'set the parish default');
    }

    private function lockParish(int $parishId): void
    {
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        $parish = $this->fetchRow($this->database->prepare(
            "SELECT id FROM {$parishes} WHERE id = %d FOR UPDATE",
            $parishId
        ));

        if ($parish === null) {
            throw new DomainException('The parish for this venue could not be found.');
        }
    }

    private function ensureDefaultFlagWithoutTransaction(int $parishId, string $timestamp): void
    {
        $active = array_values(array_filter(
            $this->findForParish($parishId),
            static fn (Venue $venue): bool => $venue->status === Venue::ACTIVE
        ));
        $defaults = array_values(array_filter(
            $active,
            static fn (Venue $venue): bool => $venue->isDefault
        ));

        if ($active === [] || count($defaults) === 1) {
            return;
        }

        $this->setDefaultWithoutTransaction(
            $parishId,
            $defaults[0]->id ?? $active[0]->id,
            $timestamp
        );
    }

    private function assertActiveVenueForParish(int $parishId, int $venueId): void
    {
        $venue = $this->findVenue($venueId);

        if (
            $venue === null
            || $venue->parishId !== $parishId
            || $venue->status !== Venue::ACTIVE
        ) {
            throw new DomainException('Choose an active replacement venue belonging to this parish.');
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function values(Venue $venue, string $timestamp): array
    {
        return [
            'parish_id' => $venue->parishId,
            'source_parish_id' => $venue->sourceParishId,
            'name' => $venue->name,
            'aliases' => json_encode($venue->aliases, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'address' => $venue->address,
            'suburb' => $venue->suburb,
            'latitude' => $venue->latitude,
            'longitude' => $venue->longitude,
            'is_default' => $venue->isDefault ? 1 : 0,
            'status' => $venue->status,
            'updated_at' => $timestamp,
        ];
    }

    private function withDefault(Venue $venue): Venue
    {
        return new Venue(
            $venue->id,
            $venue->parishId,
            $venue->name,
            $venue->aliases,
            $venue->address,
            $venue->suburb,
            $venue->latitude,
            $venue->longitude,
            true,
            Venue::ACTIVE,
            $venue->sourceParishId
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapVenue(array $row): Venue
    {
        $aliases = $row['aliases'] ?? null;

        if ($aliases === null || $aliases === '') {
            $decodedAliases = [];
        } else {
            $decodedAliases = json_decode((string) $aliases, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decodedAliases)) {
                throw new RuntimeException('Stored venue aliases are not a JSON list.');
            }
        }

        $normalizedAliases = [];

        foreach ($decodedAliases as $alias) {
            if (! is_string($alias) || trim($alias) === '') {
                throw new RuntimeException('Stored venue aliases must be non-empty text values.');
            }

            $normalizedAliases[] = trim($alias);
        }

        $status = (string) ($row['status'] ?? Venue::ACTIVE);

        if (! in_array($status, [Venue::ACTIVE, Venue::INACTIVE], true)) {
            throw new RuntimeException('A stored venue has an invalid status.');
        }

        $id = (int) ($row['id'] ?? 0);
        $parishId = (int) ($row['parish_id'] ?? 0);

        if ($id < 1 || $parishId < 1) {
            throw new RuntimeException('A stored venue has an invalid ID or parish ID.');
        }

        return new Venue(
            $id,
            $parishId,
            (string) ($row['name'] ?? ''),
            array_values($normalizedAliases),
            $this->nullableString($row['address'] ?? null),
            $this->nullableString($row['suburb'] ?? null),
            $this->nullableFloat($row['latitude'] ?? null),
            $this->nullableFloat($row['longitude'] ?? null),
            (int) ($row['is_default'] ?? 0) === 1,
            $status,
            isset($row['source_parish_id']) && $row['source_parish_id'] !== ''
                ? (int) $row['source_parish_id']
                : null
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        return $text === '' ? null : $text;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new RuntimeException('A stored venue coordinate is not numeric.');
        }

        return (float) $value;
    }

    private function assertVenue(Venue $venue): void
    {
        if ($venue->parishId < 1 || $venue->id < 0) {
            throw new InvalidArgumentException('A venue needs a positive parish ID and a non-negative venue ID.');
        }

        if (trim($venue->name) === '') {
            throw new InvalidArgumentException('A venue name is required.');
        }

        if (! in_array($venue->status, [Venue::ACTIVE, Venue::INACTIVE], true)) {
            throw new InvalidArgumentException('A venue status must be active or inactive.');
        }

        if ($venue->isDefault && $venue->status !== Venue::ACTIVE) {
            throw new InvalidArgumentException('An inactive venue cannot be the parish default.');
        }

        if (
            ($venue->latitude !== null && (! is_finite($venue->latitude) || $venue->latitude < -90 || $venue->latitude > 90))
            || ($venue->longitude !== null && (! is_finite($venue->longitude) || $venue->longitude < -180 || $venue->longitude > 180))
        ) {
            throw new InvalidArgumentException('Venue coordinates are outside their valid ranges.');
        }
    }

    private function assertPositiveId(int $id, string $message): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException($message);
        }
    }

    private function beginTransaction(): void
    {
        $this->executeQuery('START TRANSACTION', 'start a venue update');
    }

    private function commitTransaction(): void
    {
        $this->executeQuery('COMMIT', 'commit the venue update');
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
