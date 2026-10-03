<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval;

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Auth\Capabilities;

final class ApprovalRecipients
{
    /**
     * Deanery approvers opt out of reminders on their own row in
     * `deanery_approvers`. Reviewers are not on that table, so their switch is
     * user meta beside the notify-mode preference.
     */
    public const REVIEWER_REMINDERS_META_KEY = 'adct_pi_approval_reminders';

    public function __construct(private readonly ApprovalRouteResolver $routes)
    {
    }

    /**
     * @return array<string, array{role: string, mode: string, reminders: bool}>
     */
    public function forParish(?int $parishId): array
    {
        $recipients = [];
        if ($parishId !== null && $parishId > 0) {
            foreach ($this->routes->forParish($parishId)->approvers as $approver) {
                $user = get_userdata($approver->wpUserId);
                if ($user instanceof \WP_User && (int) $user->user_status === 0
                    && user_can($user, Capabilities::APPROVE_DEANERY)) {
                    $recipients[$approver->email] = [
                        'role' => 'dean',
                        'mode' => $approver->notifyMode,
                        'reminders' => $approver->remindersEnabled,
                    ];
                }
            }
        }

        foreach (get_users(['capability' => Capabilities::REVIEW]) as $user) {
            if (! $user instanceof \WP_User || (int) $user->user_status !== 0
                || ! user_can($user, Capabilities::REVIEW)
                || filter_var($user->user_email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $mode = get_user_meta((int) $user->ID, 'adct_pi_approval_notify_mode', true);
            $reminders = get_user_meta((int) $user->ID, self::REVIEWER_REMINDERS_META_KEY, true);
            $recipients[strtolower($user->user_email)] = [
                'role' => 'reviewer',
                'mode' => $mode === Approver::NOTIFY_DIGEST ? Approver::NOTIFY_DIGEST : Approver::NOTIFY_EACH,
                'reminders' => ! in_array($reminders, ['0', 'off', 'false', 'no'], true),
            ];
        }

        return $recipients;
    }

    public function roleFor(?int $parishId, string $email): ?string
    {
        return $this->forParish($parishId)[strtolower($email)]['role'] ?? null;
    }
}
