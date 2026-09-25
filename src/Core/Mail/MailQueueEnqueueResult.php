<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final class MailQueueEnqueueResult
{
    public function __construct(
        public readonly int $id,
        public readonly MailQueueStatus $status,
        public readonly bool $duplicate
    ) {
        if ($id < 1) {
            throw new InvalidArgumentException('A queued email must have a positive ID.');
        }
    }
}
