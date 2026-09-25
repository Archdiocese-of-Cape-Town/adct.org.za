<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

final class MailQueueDispatchResult
{
    public function __construct(
        public readonly MailQueueDispatchStatus $status,
        public readonly ?int $messageId = null
    ) {
    }
}
