<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InboundMessageProcessingRecord
{
    public function __construct(
        public int $id,
        public int $sourceId,
        public string $externalId,
        public ?string $senderEmail,
        public ?string $senderName,
        public string $subject,
        public ?DateTimeImmutable $receivedAt,
        public ?string $rawPath,
        public bool $isAutoReply
    ) {
        if (
            $id < 1
            || $sourceId < 1
            || $externalId === ''
            || strlen($externalId) > 191
        ) {
            throw new InvalidArgumentException('An inbound message processing record has invalid identity data.');
        }
    }
}
