<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Approval\ApproverSettings;
use InvalidArgumentException;

final class DeaneryApproverRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_deanery_approvers';

    protected const FIELD_FORMATS = [
        'deanery_id' => '%d',
        'wp_user_id' => '%d',
        'email' => '%s',
        'label' => '%s',
        'notify_mode' => '%s',
        'reminders_enabled' => '%d',
        'active' => '%d',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForDeanery(int $deaneryId): array
    {
        if ($deaneryId < 1) {
            throw new InvalidArgumentException('A deanery ID must be positive.');
        }

        $table = $this->tableName();
        $users = $this->database->prefix() . 'users';
        $query = "SELECT a.*, u.user_login, u.display_name AS user_display_name, "
            . "u.user_email AS account_email FROM {$table} a "
            . "LEFT JOIN {$users} u ON u.ID = a.wp_user_id "
            . 'WHERE a.deanery_id = %d ORDER BY a.active DESC, a.label ASC, a.id ASC';

        return $this->fetchRows($this->database->prepare($query, $deaneryId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByDeaneryAndUser(int $deaneryId, int $wpUserId): ?array
    {
        if ($deaneryId < 1 || $wpUserId < 1) {
            throw new InvalidArgumentException('Deanery and WordPress user IDs must be positive.');
        }

        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT * FROM {$table} WHERE deanery_id = %d AND wp_user_id = %d LIMIT 1",
            $deaneryId,
            $wpUserId
        );

        return $this->fetchRow($query);
    }

    public function countActiveForUser(int $wpUserId): int
    {
        if ($wpUserId < 1) {
            throw new InvalidArgumentException('A WordPress user ID must be positive.');
        }

        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT COUNT(*) AS total FROM {$table} WHERE wp_user_id = %d AND active = %d",
            $wpUserId,
            1
        );
        $row = $this->fetchRow($query);

        return (int) ($row['total'] ?? 0);
    }

    public function save(
        int $deaneryId,
        int $wpUserId,
        ApproverSettings $settings,
        string $timestamp
    ): int {
        $this->assertAssignmentIds($deaneryId, $wpUserId);
        $existing = $this->findByDeaneryAndUser($deaneryId, $wpUserId);
        $values = [
            'deanery_id' => $deaneryId,
            'wp_user_id' => $wpUserId,
            'email' => $settings->email,
            'label' => $settings->label,
            'notify_mode' => $settings->notifyMode,
            'reminders_enabled' => $settings->remindersEnabled ? 1 : 0,
            'active' => $settings->active ? 1 : 0,
            'updated_at' => $timestamp,
        ];

        if ($existing === null) {
            $values['created_at'] = $timestamp;

            return $this->insert($values);
        }

        $assignmentId = (int) ($existing['id'] ?? 0);
        $this->update($assignmentId, $values);

        return $assignmentId;
    }

    private function assertAssignmentIds(int $deaneryId, int $wpUserId): void
    {
        if ($deaneryId < 1 || $wpUserId < 1) {
            throw new InvalidArgumentException('Deanery and WordPress user IDs must be positive.');
        }
    }
}
