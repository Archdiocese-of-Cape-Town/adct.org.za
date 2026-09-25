<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use InvalidArgumentException;

final class ApprovalRouteRepository extends AbstractRepository implements ApprovalRouteRepositoryInterface
{
    protected const TABLE_SUFFIX = 'adct_pi_parishes';

    public function findForParish(int $parishId): ?ApprovalRouteSnapshot
    {
        if ($parishId < 1) {
            throw new InvalidArgumentException('A parish ID must be positive.');
        }

        $parishes = $this->tableName();
        $deaneries = $this->database->prefix() . 'adct_pi_deaneries';
        $approvers = $this->database->prefix() . 'adct_pi_deanery_approvers';
        $query = "SELECT p.deanery_id, d.status AS deanery_status, "
            . 'a.id AS approver_id, a.wp_user_id, a.email, a.label, a.notify_mode, '
            . "a.reminders_enabled, a.active FROM {$parishes} p "
            . "LEFT JOIN {$deaneries} d ON d.id = p.deanery_id "
            . "LEFT JOIN {$approvers} a ON a.deanery_id = d.id "
            . 'WHERE p.id = %d ORDER BY a.id ASC';
        $rows = $this->fetchRows($this->database->prepare($query, $parishId));

        if ($rows === []) {
            return null;
        }

        $deaneryValue = $rows[0]['deanery_id'] ?? null;
        $deaneryId = $deaneryValue === null || $deaneryValue === ''
            ? null
            : (int) $deaneryValue;
        $deaneryActive = ($rows[0]['deanery_status'] ?? null) === 'active';
        $approverRows = [];

        foreach ($rows as $row) {
            $approverId = $row['approver_id'] ?? null;

            if ($approverId === null || $approverId === '') {
                continue;
            }

            $approverRows[] = new Approver(
                (int) $approverId,
                (int) ($row['wp_user_id'] ?? 0),
                (string) ($row['email'] ?? ''),
                (string) ($row['label'] ?? ''),
                (string) ($row['notify_mode'] ?? ''),
                (int) ($row['reminders_enabled'] ?? 0) === 1,
                (int) ($row['active'] ?? 0) === 1
            );
        }

        return new ApprovalRouteSnapshot($deaneryId, $deaneryActive, $approverRows);
    }
}
