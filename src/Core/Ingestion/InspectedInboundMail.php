<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use DateTimeImmutable;

final readonly class InspectedInboundMail
{
    /**
     * @param list<RawEmailAttachment> $attachments
     */
    public function __construct(
        public ?string $messageId,
        public ?string $senderEmail,
        public ?string $senderName,
        public string $subject,
        public string $body,
        public DateTimeImmutable $receivedAt,
        public array $attachments
    ) {
    }
}
