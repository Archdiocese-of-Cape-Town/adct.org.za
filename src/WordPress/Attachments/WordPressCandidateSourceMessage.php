<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Attachments;

use ADCT\ParishIntake\Core\Ports\CandidateSourceMessageInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;

/**
 * Resolves the inbound message behind an event candidate.
 *
 * `EventCandidateRepository::findById()` already returns the whole row, so this
 * only has to read `message_id` defensively: the column is nullable for manual
 * and portal entries, which have no poster image.
 */
final class WordPressCandidateSourceMessage implements CandidateSourceMessageInterface
{
    public function __construct(
        private readonly EventCandidateRepository $candidates
    ) {
    }

    public function sourceMessageIdForCandidate(int $candidateId): ?int
    {
        if ($candidateId < 1) {
            return null;
        }

        $row = $this->candidates->findById($candidateId);

        if ($row === null) {
            return null;
        }

        $messageId = (int) ($row['message_id'] ?? 0);

        return $messageId > 0 ? $messageId : null;
    }
}
