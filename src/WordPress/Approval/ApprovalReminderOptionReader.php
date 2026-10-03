<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval;

use ADCT\ParishIntake\Core\Approval\ApprovalReminderSettings;

/**
 * Reads the global approval reminder switch and period from WordPress options.
 *
 * The options are deliberately interpreted leniently: a corrupt or missing
 * value falls back to the safe default rather than throwing, because this is
 * read on every cron tick and a bad option must not take the monitoring job
 * down with it. Anything nonsensical disables reminders, which is the
 * privacy-preserving failure direction — a stale option cannot silently start
 * mailing parishes.
 */
final class ApprovalReminderOptionReader
{
    public const ENABLED_OPTION = 'adct_pi_approval_reminders_enabled';
    public const DAYS_OPTION = 'adct_pi_approval_reminders_days';

    public function read(): ApprovalReminderSettings
    {
        $days = filter_var(
            get_option(self::DAYS_OPTION, ApprovalReminderSettings::DEFAULT_DAYS),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => ApprovalReminderSettings::MAX_DAYS]]
        );

        if (! is_int($days)) {
            return new ApprovalReminderSettings(false, ApprovalReminderSettings::DEFAULT_DAYS);
        }

        return new ApprovalReminderSettings(
            get_option(self::ENABLED_OPTION, '1') === '1',
            $days
        );
    }
}