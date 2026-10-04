<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Review;

use DomainException;

final class ReviewQueuePolicy
{
    /**
     * The default below which a candidate needs a human before it is published.
     *
     * This matches the historic value that was read from the AI threshold option, so a site that
     * has never set the new option behaves exactly as before.
     */
    public const DEFAULT_CONFIDENCE_THRESHOLD = 0.55;

    /** @param array<string, mixed> $candidate */
    public function canDecide(array $candidate): bool
    {
        return in_array($candidate['status'] ?? null, ['awaiting_approval', 'duplicate'], true)
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

    /**
     * Whether this candidate is blocked until somebody resolves its match.
     *
     * Answers without throwing, which is the whole point of it being a separate
     * question from {@see fields()}. `fields()` throws on JSON that is not an object,
     * and it should: a policy *decision* must not be made from a guessed parse. But a
     * screen that only displays a row is not deciding anything, and must not die on a
     * candidate it cannot read (issue #177). Two callers depend on this: the detail
     * screen, which re-renders after a rejected save, and the retry check, which needs
     * a yes/no for a corrupt row.
     *
     * The columns `match_review_required` and `matched_candidate_id` are still
     * readable, because they are ordinary columns and not part of `fields`. So an
     * unreadable row whose columns say it is ambiguous is still reported as ambiguous:
     * refusing to answer here would be the worse lie, because it would tell a reviewer
     * the row is clear when its own columns say otherwise.
     *
     * That leaves the two failures distinguishable, which is what makes the answer safe
     * to act on:
     *
     * - Columns say ambiguous -> `true`. The detail screen still offers no resolve
     *   control, because a correct resolve *rewrites* `fields` and would destroy the
     *   event we just failed to parse.
     * - Columns say nothing and `fields` is unreadable -> `false`, and
     *   {@see canBulkApprove()} has already refused the row on its own `fields()`.
     *   Note it throws where this returns `false`, so a renderer's `false` is not the
     *   same answer as the approver's throw.
     *
     * @param array<string, mixed> $candidate
     */
    public function requiresMatchResolution(array $candidate): bool
    {
        // Deliberately before the decode: these are columns, and they are the only part
        // of the answer that survives unreadable JSON.
        if (! empty($candidate['match_review_required']) || ! empty($candidate['matched_candidate_id'])) {
            return true;
        }

        try {
            $fields = $this->fields($candidate);
        } catch (DomainException) {
            // The row's details are unreadable, so its `fields` keys cannot vouch for
            // anything. Not "clear" -- the approver refuses it separately, and the
            // detail screen says so in as many words.
            return false;
        }

        return ! empty($fields['match_review_required']) || ! empty($fields['matched_candidate_id']);
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
