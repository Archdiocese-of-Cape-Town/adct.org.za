<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Matching;

final class MatchReviewPolicy
{
    /**
     * @param array<string, mixed> $fields
     */
    public static function requiresManualReview(array $fields): bool
    {
        if (array_key_exists('matched_candidate_id', $fields)) {
            return true;
        }

        if (! array_key_exists('match_review_required', $fields)) {
            return false;
        }

        return ! is_bool($fields['match_review_required']) || $fields['match_review_required'];
    }
}
