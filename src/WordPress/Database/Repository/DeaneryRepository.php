<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

final class DeaneryRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_deaneries';

    protected const FIELD_FORMATS = [
        'name' => '%s',
        'slug' => '%s',
        'dean_name' => '%s',
        'vice_dean_name' => '%s',
        'secretary_name' => '%s',
        'status' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $table = $this->tableName();

        return $this->fetchRows("SELECT * FROM {$table} ORDER BY name ASC, slug ASC");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllWithActiveApproverCounts(): array
    {
        $table = $this->tableName();
        $approvers = $this->database->prefix() . 'adct_pi_deanery_approvers';
        $query = "SELECT d.*, "
            . "(SELECT COUNT(*) FROM {$approvers} a "
            . 'WHERE a.deanery_id = d.id AND a.active = 1) AS active_approver_count '
            . "FROM {$table} d ORDER BY d.name ASC, d.slug ASC";

        return $this->fetchRows($query);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT * FROM {$table} WHERE slug = %s LIMIT 1",
            $slug
        );

        return $this->fetchRow($query);
    }
}
