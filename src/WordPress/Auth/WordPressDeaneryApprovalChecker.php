<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Auth\DeaneryApprovalPolicy;
use RuntimeException;

final class WordPressDeaneryApprovalChecker
{
    private DeaneryApprovalPolicy $policy;

    public function __construct(?DeaneryApprovalPolicy $policy = null)
    {
        $this->policy = $policy ?? new DeaneryApprovalPolicy();
    }

    /**
     * @param string[] $userCaps
     */
    public function canApprove(
        int $userId,
        ?int $parishDeaneryId,
        array $userCaps
    ): bool {
        if ($this->policy->canApprove([], $parishDeaneryId, $userCaps)) {
            return true;
        }

        if (
            $parishDeaneryId === null
            || ! in_array(Capabilities::APPROVE_DEANERY, $userCaps, true)
        ) {
            return false;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'adct_pi_deanery_approvers';
        $query = $wpdb->prepare(
            "SELECT deanery_id FROM {$table} WHERE wp_user_id = %d AND active = %d",
            $userId,
            1
        );

        if (! is_string($query) || $query === '') {
            throw new RuntimeException('The deanery approver assignments query could not be prepared.');
        }

        $deaneryIds = $wpdb->get_col($query);

        if (! is_array($deaneryIds) || (isset($wpdb->last_error) && $wpdb->last_error !== '')) {
            throw new RuntimeException('The deanery approver assignments could not be read.');
        }

        $deaneryIds = array_map('intval', $deaneryIds);

        return $this->policy->canApprove($deaneryIds, $parishDeaneryId, $userCaps);
    }
}
