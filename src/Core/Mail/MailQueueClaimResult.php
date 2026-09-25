<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final class MailQueueClaimResult
{
    public function __construct(
        public readonly MailQueueClaimStatus $status,
        public readonly ?MailQueueRecord $record = null
    ) {
        if (($status === MailQueueClaimStatus::CLAIMED) !== ($record !== null)) {
            throw new InvalidArgumentException('A claimed queue result must include its record.');
        }
    }
}
