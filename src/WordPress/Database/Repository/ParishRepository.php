<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use InvalidArgumentException;
use RuntimeException;

final class ParishRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_parishes';

    protected const FIELD_FORMATS = [
        'name' => '%s',
        'slug' => '%s',
        'area' => '%s',
        'church' => '%s',
        'kind' => '%s',
        'parent_parish_id' => '%d',
        'deanery_id' => '%d',
        'address' => '%s',
        'suburb' => '%s',
        'latitude' => '%f',
        'longitude' => '%f',
        'website' => '%s',
        'phone' => '%s',
        'official_source_id' => '%d',
        'expected_cadence_days' => '%d',
        'reminders_enabled' => '%d',
        'status' => '%s',
        'notes' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function findForDirectory(array $filters, int $limit, int $offset): array
    {
        $table = $this->tableName();
        $deaneries = $this->database->prefix() . 'adct_pi_deaneries';
        [$where, $arguments] = $this->buildWhere($filters);
        $query = "SELECT p.*, d.name AS deanery_name, d.slug AS deanery_slug, "
            . "parent.name AS parent_name, parent.slug AS parent_slug "
            . "FROM {$table} p "
            . "LEFT JOIN {$deaneries} d ON d.id = p.deanery_id "
            . "LEFT JOIN {$table} parent ON parent.id = p.parent_parish_id"
            . $where
            . ' ORDER BY p.name ASC, p.slug ASC LIMIT %d OFFSET %d';
        $arguments[] = max(1, min(100, $limit));
        $arguments[] = max(0, $offset);

        return $this->fetchRows($this->database->prepare($query, ...$arguments));
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countForDirectory(array $filters): int
    {
        $table = $this->tableName();
        [$where, $arguments] = $this->buildWhere($filters);
        $query = "SELECT COUNT(*) AS total FROM {$table} p" . $where;
        $prepared = $arguments === []
            ? $query
            : $this->database->prepare($query, ...$arguments);
        $row = $this->fetchRow($prepared);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findWithRelations(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $table = $this->tableName();
        $deaneries = $this->database->prefix() . 'adct_pi_deaneries';
        $query = $this->database->prepare(
            "SELECT p.*, d.name AS deanery_name, d.slug AS deanery_slug, "
            . "parent.name AS parent_name, parent.slug AS parent_slug "
            . "FROM {$table} p "
            . "LEFT JOIN {$deaneries} d ON d.id = p.deanery_id "
            . "LEFT JOIN {$table} parent ON parent.id = p.parent_parish_id "
            . 'WHERE p.id = %d LIMIT 1',
            $id
        );

        return $this->fetchRow($query);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllForImport(): array
    {
        $table = $this->tableName();
        $deaneries = $this->database->prefix() . 'adct_pi_deaneries';
        $contacts = $this->database->prefix() . 'adct_pi_parish_contacts';
        $query = "SELECT p.*, d.slug AS deanery_slug, parent.slug AS parent_slug, "
            . "(SELECT c.email FROM {$contacts} c "
            . "WHERE c.parish_id = p.id AND c.trust = 'verified' ORDER BY c.id ASC LIMIT 1) AS office_email "
            . "FROM {$table} p "
            . "LEFT JOIN {$deaneries} d ON d.id = p.deanery_id "
            . "LEFT JOIN {$table} parent ON parent.id = p.parent_parish_id "
            . 'ORDER BY p.name ASC, p.slug ASC';

        return $this->fetchRows($query);
    }

    /**
     * @param list<int> $parishIds
     */
    public function updateDeaneryForParishes(array $parishIds, ?int $deaneryId, string $updatedAt): int
    {
        if ($parishIds === []) {
            throw new InvalidArgumentException('At least one parish ID is required for a bulk assignment.');
        }

        if ($deaneryId !== null && $deaneryId < 1) {
            throw new InvalidArgumentException('A deanery ID must be positive when set.');
        }

        foreach ($parishIds as $parishId) {
            if (! is_int($parishId) || $parishId < 1) {
                throw new InvalidArgumentException('Parish IDs must be positive integers.');
            }
        }

        $parishIds = array_values(array_unique($parishIds));
        $table = $this->tableName();
        $idPlaceholders = implode(', ', array_fill(0, count($parishIds), '%d'));
        $deaneryAssignment = $deaneryId === null ? 'NULL' : '%d';
        $query = "UPDATE {$table} SET deanery_id = {$deaneryAssignment}, updated_at = %s "
            . "WHERE id IN ({$idPlaceholders})";
        $arguments = [];

        if ($deaneryId !== null) {
            $arguments[] = $deaneryId;
        }

        $arguments[] = $updatedAt;
        array_push($arguments, ...$parishIds);
        $this->database->clearLastError();
        $result = $this->database->query($this->database->prepare($query, ...$arguments));

        if ($result === false) {
            throw new RuntimeException(
                'The parish deanery assignments could not be saved: ' . $this->database->lastError()
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, scalar>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $arguments = [];
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $pattern = '%' . $this->database->escapeLike($search) . '%';
            $conditions[] = '(p.name LIKE %s OR p.slug LIKE %s OR p.area LIKE %s OR p.suburb LIKE %s)';
            array_push($arguments, $pattern, $pattern, $pattern, $pattern);
        }

        $kind = trim((string) ($filters['kind'] ?? ''));

        if ($kind !== '') {
            $conditions[] = 'p.kind = %s';
            $arguments[] = $kind;
        }

        $deaneryId = (int) ($filters['deanery_id'] ?? 0);

        if ($deaneryId > 0) {
            $conditions[] = 'p.deanery_id = %d';
            $arguments[] = $deaneryId;
        }

        $status = trim((string) ($filters['status'] ?? ''));

        if ($status !== '') {
            $conditions[] = 'p.status = %s';
            $arguments[] = $status;
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $arguments,
        ];
    }
}
