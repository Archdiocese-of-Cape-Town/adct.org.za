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

    /**
     * The WordPress user a notice address belongs to, or null when there is none.
     *
     * An approver's approval address is deliberately allowed to differ from the
     * address on their WordPress account, so the mapping has to be read from the
     * assignment row rather than looked up by account email.
     *
     * Only live assignments count, on the same definition
     * findLiveAssignmentsForUser() uses: a dean who holds no live assignment
     * gets no preference link, because the handler would refuse it.
     *
     * @return list<int> The matching user IDs, so a shared address is not hidden.
     */
    public function findLiveWpUserIdsByNoticeEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        $table = $this->tableName();
        $deaneries = $this->database->prefix() . 'adct_pi_deaneries';
        $rows = $this->fetchRows($this->database->prepare(
            "SELECT a.wp_user_id FROM {$table} a "
            . "INNER JOIN {$deaneries} d ON d.id = a.deanery_id "
            . 'WHERE a.email = %s AND a.active = %d AND d.status = %s '
            . 'ORDER BY a.id ASC',
            $email,
            1,
            'active'
        ));

        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['wp_user_id'] ?? 0),
            $rows
        )));
    }

    /**
     * Every live assignment this WordPress user holds, ordered for a stable audit row.
     *
     * "Live" means the assignment row is still active *and* its deanery still
     * exists and is itself active. A deanery that has been deactivated or
     * deleted is not somewhere this person is still being notified about, so it
     * is not somewhere they can still be choosing an email mode for.
     *
     * This is the list #169's self-service link re-reads at the moment the
     * button is pressed, inside the transaction, rather than trusting what was
     * true when the notice was mailed. The caller updates exactly these rows by
     * id through the inherited update(), so the set that was authorised to
     * change and the set that changes cannot drift apart.
     *
     * @return list<array{id: int, deanery_id: int, notify_mode: string}>
     */
    public function findLiveAssignmentsForUser(int $wpUserId): array
    {
        if ($wpUserId < 1) {
            throw new InvalidArgumentException('A WordPress user ID must be positive.');
        }

        $table = $this->tableName();
        $deaneries = $this->database->prefix() . 'adct_pi_deaneries';
        $rows = $this->fetchRows($this->database->prepare(
            "SELECT a.id, a.deanery_id, a.notify_mode FROM {$table} a "
            . "INNER JOIN {$deaneries} d ON d.id = a.deanery_id "
            . 'WHERE a.wp_user_id = %d AND a.active = %d AND d.status = %s '
            . 'ORDER BY a.id ASC',
            $wpUserId,
            1,
            'active'
        ));

        return array_map(
            static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'deanery_id' => (int) ($row['deanery_id'] ?? 0),
                'notify_mode' => (string) ($row['notify_mode'] ?? ''),
            ],
            $rows
        );
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
