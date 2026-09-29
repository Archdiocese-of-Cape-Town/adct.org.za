<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use ADCT\ParishIntake\Core\Directory\SenderTrust;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ConfirmationEmailBatch
{
    /**
     * @param list<ConfirmationEmailCandidate> $candidates
     */
    public function __construct(
        public int $messageId,
        public int $sourceId,
        public ?string $senderEmail,
        public ?string $senderName,
        public string $subject,
        public DateTimeImmutable $receivedAt,
        public ?string $replyToEmail,
        public string $senderTrust,
        public string $replyToTrust,
        public bool $automatedOrList,
        public ?string $originalMessageId,
        public array $candidates,
        public ?ConfirmationEmailReason $emptyReason = null
    ) {
        if ($messageId < 1 || $sourceId < 1) {
            throw new InvalidArgumentException('A confirmation preview needs valid inbound message and source IDs.');
        }

        if (! SenderTrust::isValid($senderTrust) || ! SenderTrust::isValid($replyToTrust)) {
            throw new InvalidArgumentException('A confirmation preview has an invalid sender trust state.');
        }

        if (! array_is_list($candidates)) {
            throw new InvalidArgumentException('Confirmation preview candidates must be a list.');
        }
        if ($candidates !== [] && $emptyReason !== null) {
            throw new InvalidArgumentException('An empty-batch suppression reason requires no candidates.');
        }

        $candidateIds = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof ConfirmationEmailCandidate || isset($candidateIds[$candidate->id])) {
                throw new InvalidArgumentException('Confirmation preview candidates must have unique valid records.');
            }

            $candidateIds[$candidate->id] = true;
        }
    }
}
