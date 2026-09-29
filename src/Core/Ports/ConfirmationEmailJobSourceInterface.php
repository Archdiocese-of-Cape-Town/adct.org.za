<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Mail\ConfirmationEmailBatch;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResult;
use DateTimeImmutable;

interface ConfirmationEmailJobSourceInterface
{
    public function nextPending(): ?ConfirmationEmailBatch;

    public function recordResult(
        int $messageId,
        ConfirmationEmailResult $result,
        DateTimeImmutable $recordedAt
    ): void;
}
