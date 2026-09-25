<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

final readonly class ConfirmationEmailContent
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text
    ) {
    }
}
