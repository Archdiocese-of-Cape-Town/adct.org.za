<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

/**
 * Resolves the inbound message that produced an event candidate.
 *
 * Used by the client-side OCR feature of ADR 0017 to find the poster image
 * behind an emailed action-token page, without exposing any other message.
 */
interface CandidateSourceMessageInterface
{
    /**
     * The id of the message this candidate was parsed from, or null when the
     * candidate has no source message (a manual or portal entry).
     */
    public function sourceMessageIdForCandidate(int $candidateId): ?int;
}
