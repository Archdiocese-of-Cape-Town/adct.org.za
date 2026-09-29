<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Review;

use DomainException;

final class ReviewQueuePolicy
{
    /** @param array<string, mixed> $candidate */
    public function canDecide(array $candidate): bool
    {
        return ($candidate['status'] ?? null) === 'awaiting_approval'
            && empty($candidate['approved_by'])
            && empty($candidate['decided_at']);
    }

    /** @param array<string, mixed> $candidate */
    public function canBulkApprove(array $candidate): bool
    {
        if (! $this->canDecide($candidate)) {
            return false;
        }

        $fields = $this->fields($candidate);
        if ($this->requiresMatchResolution($candidate)
            || (isset($fields['parish_id']) && (int) $fields['parish_id'] !== (int) ($candidate['parish_id'] ?? 0))) {
            return false;
        }

        $kind = $candidate['match_kind'] ?? null;
        $eventId = (int) ($candidate['match_event_id'] ?? 0);
        return $kind === 'new'
            ? $eventId === 0
            : in_array($kind, ['update', 'cancellation', 'postponement'], true) && $eventId > 0;
    }

    /** @param array<string, mixed> $candidate */
    public function requiresMatchResolution(array $candidate): bool
    {
        $fields = $this->fields($candidate);
        return ! empty($candidate['match_review_required'])
            || ! empty($candidate['matched_candidate_id'])
            || ! empty($fields['match_review_required'])
            || ! empty($fields['matched_candidate_id']);
    }

    /** @param array<string, mixed> $candidate
     *  @return array<string, mixed>
     */
    public function fields(array $candidate): array
    {
        $json = $candidate['fields'] ?? null;
        if (! is_string($json)) {
            throw new DomainException('Candidate event details are unavailable.');
        }
        $object = json_decode($json, false, 32);
        if (! $object instanceof \stdClass) {
            throw new DomainException('Candidate event details are invalid.');
        }
        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }
}
