<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final readonly class ConfirmationEmailResult
{
    public function __construct(
        public ConfirmationEmailOutcome $outcome,
        public ?ConfirmationEmailReason $reason = null,
        public ?int $queueId = null
    ) {
        if ($queueId !== null && $queueId < 1) {
            throw new InvalidArgumentException('A confirmation preview queue ID must be positive.');
        }

        if (
            $outcome === ConfirmationEmailOutcome::SUPPRESSED
            && $reason === null
        ) {
            throw new InvalidArgumentException('A suppressed confirmation preview needs an operator-visible reason.');
        }
    }
}
