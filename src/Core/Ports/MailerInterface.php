<?php

namespace ADCT\ParishIntake\Core\Ports;

interface MailerInterface
{
    /**
     * Provisional: the signature will be refined by its first consumer, the mail-queue adapter in ADR 0011.
     */
    public function send(
        string $recipient,
        string $subject,
        string $htmlBody,
        string $textBody
    ): void;
}
