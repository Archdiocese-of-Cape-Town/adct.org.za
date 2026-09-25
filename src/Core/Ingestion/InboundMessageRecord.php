<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class InboundMessageRecord
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param list<InboundAttachmentRecord> $attachments
     */
    public function __construct(
        public int $sourceId,
        public string $externalId,
        public ?string $contentHash,
        public ?string $senderEmail,
        public ?string $senderName,
        public string $subject,
        public DateTimeImmutable $receivedAt,
        public ?string $rawPath,
        public array $attachments = [],
        public string $status = self::STATUS_RECEIVED,
        public ?string $error = null,
        public bool $isAutoReply = false,
        public ?AuthenticationResults $authResults = null
    ) {
        if ($sourceId < 1 || $externalId === '' || strlen($externalId) > 191) {
            throw new InvalidArgumentException('An inbound message needs a valid source and external ID.');
        }

        if (
            $contentHash !== null
            && preg_match('/\A[a-f0-9]{64}\z/i', $contentHash) !== 1
        ) {
            throw new InvalidArgumentException('An inbound message content hash must be a SHA-256 value.');
        }

        if (! in_array($status, [self::STATUS_RECEIVED, self::STATUS_SKIPPED], true)) {
            throw new InvalidArgumentException('An inbound message has an unsupported initial status.');
        }

        foreach ($attachments as $attachment) {
            if (! $attachment instanceof InboundAttachmentRecord) {
                throw new InvalidArgumentException('Every inbound attachment must have a valid record.');
            }
        }
    }
}
