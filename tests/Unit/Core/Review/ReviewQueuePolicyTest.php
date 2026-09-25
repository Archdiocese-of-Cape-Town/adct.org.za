<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Review;

use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ReviewQueuePolicyTest extends TestCase
{
    public function testOnlyUndecidedUnambiguousCandidatesCanBeBulkApproved(): void
    {
        $policy = new ReviewQueuePolicy();
        $candidate = [
            'status' => 'awaiting_approval', 'approved_by' => null, 'decided_at' => null,
            'match_kind' => 'new', 'match_event_id' => null, 'parish_id' => 3,
            'fields' => '{"title":"Fictional event","parish_id":3}',
        ];
        self::assertTrue($policy->canBulkApprove($candidate));
        foreach ([
            ['status' => 'draft'],
            ['approved_by' => 'first@example.test'],
            ['decided_at' => '2026-09-25 10:00:00'],
            ['match_kind' => 'duplicate'],
            ['match_event_id' => 7],
            ['fields' => '{"match_review_required":true}'],
            ['fields' => '{"matched_candidate_id":4}'],
            ['fields' => '{"parish_id":5}'],
            ['match_review_required' => 1],
            ['matched_candidate_id' => 4],
        ] as $change) {
            self::assertFalse($policy->canBulkApprove(array_replace($candidate, $change)), json_encode($change));
        }
        self::assertTrue($policy->canBulkApprove(array_replace($candidate, [
            'match_kind' => 'update', 'match_event_id' => 8,
        ])));
        self::assertFalse($policy->canBulkApprove(array_replace($candidate, [
            'match_kind' => 'update', 'match_event_id' => null,
        ])));
        self::assertTrue($policy->requiresMatchResolution(array_replace($candidate, [
            'status' => 'duplicate', 'fields' => '{"matched_candidate_id":4}',
        ])));
    }

    public function testInvalidFieldsAreNotTreatedAsAnApproval(): void
    {
        $this->expectException(DomainException::class);
        (new ReviewQueuePolicy())->canBulkApprove([
            'status' => 'awaiting_approval', 'match_kind' => 'new', 'fields' => '{bad',
        ]);
    }
}
