<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use InvalidArgumentException;

final readonly class MailboxPollingSummary
{
    public function __construct(
        public int $total,
        public int $successful,
        public int $failed,
        public int $backoff,
        public int $interval,
        public int $pending
    ) {
        if (min($total, $successful, $failed, $backoff, $interval, $pending) < 0
            || $total !== $successful + $failed + $backoff + $interval + $pending) {
            throw new InvalidArgumentException('Mailbox outcome counts must account for every active mailbox.');
        }
    }

    public function toQueryValue(): string
    {
        return implode(',', [$this->total, $this->successful, $this->failed, $this->backoff, $this->interval, $this->pending]);
    }

    public static function fromQueryValue(mixed $value): ?self
    {
        if (! is_string($value) || preg_match('/\A[0-9]{1,9}(?:,[0-9]{1,9}){5}\z/D', $value) !== 1) {
            return null;
        }
        try {
            return new self(...array_map('intval', explode(',', $value)));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function message(): string
    {
        if ($this->total === 0) {
            return 'No active email mailboxes are configured for polling. Disabled/manual sources and inactive mailboxes are not polled.';
        }

        return sprintf(
            'Mailboxes: %d checked successfully; %d failed; %d waiting for retry backoff; %d waiting for source interval; %d unfinished or not reached in this batch. Disabled/manual sources and inactive mailboxes are not polled. See Sources for failure details.',
            $this->successful, $this->failed, $this->backoff, $this->interval, $this->pending
        );
    }
}
