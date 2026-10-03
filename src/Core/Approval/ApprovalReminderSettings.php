<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Approval;

use InvalidArgumentException;

/**
 * How often approval reminders go out.
 *
 * ADR 0008 point 9 requires one reminder per queue item after a configurable
 * number of idle days, switchable globally and per approver. The global switch
 * lives here; the per-approver switch lives with the approver record, because
 * it is a property of one person rather than of the installation.
 */
final readonly class ApprovalReminderSettings
{
    public const DEFAULT_DAYS = 3;
    public const MAX_DAYS = 365;

    public function __construct(
        public bool $enabled = true,
        public int $days = self::DEFAULT_DAYS
    ) {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException(
                'The approval reminder period must be between 1 and ' . self::MAX_DAYS . ' days.'
            );
        }
    }
}