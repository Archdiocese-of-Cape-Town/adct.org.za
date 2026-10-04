<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Review;

use ADCT\ParishIntake\Core\Matching\MatchReviewPolicy;
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

    /**
     * Issue #177: the detail screen re-renders a candidate whose `fields` are corrupt
     * when a save is rejected, and that re-render used to fatal, taking the whole page
     * down with it.
     *
     * So `requiresMatchResolution()` answers rather than throwing, unlike `fields()`
     * and unlike `canBulkApprove()` -- which still throws, and must: an approver has no
     * business proceeding on a row it cannot read, whereas a screen that is only
     * displaying the row has no licence to die on it.
     *
     * The two answers are therefore deliberately not interchangeable. A `false` here is
     * not a clean bill of health;
     * {@see testRequiresMatchResolutionStillReportsAFlaggedRowWithUnreadableDetails()}
     * pins the half of that which could plausibly go wrong.
     *
     * @param mixed $fields
     * @dataProvider corruptFieldRows
     */
    public function testRequiresMatchResolutionAnswersInsteadOfThrowingOnUnreadableDetails($fields): void
    {
        $policy = new ReviewQueuePolicy();

        self::assertFalse($policy->requiresMatchResolution([
            'status' => 'awaiting_approval', 'match_kind' => 'new', 'fields' => $fields,
        ]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function corruptFieldRows(): array
    {
        return [
            'not json at all' => ['{bad'],
            'json but not an object' => ['[1,2,3]'],
            'a bare scalar' => ['"a string"'],
            'absent' => [null],
            'not a string' => [42],
        ];
    }

    /**
     * The other half of "unreadable is not clean": the flag columns are ordinary
     * columns, so they still speak even when `fields` cannot be decoded.
     *
     * Refusing to answer here would be the worse lie -- it would tell a reviewer the
     * row is unambiguous when its own columns say otherwise. And the resolve route
     * cannot help this row anyway: resolving rewrites `fields`, which would destroy the
     * event we just failed to read. So the screen withholds the control and says why;
     * it does not pretend the ambiguity is not there.
     */
    public function testRequiresMatchResolutionStillReportsAFlaggedRowWithUnreadableDetails(): void
    {
        $policy = new ReviewQueuePolicy();

        self::assertTrue(
            $policy->requiresMatchResolution([
                'status' => 'awaiting_approval', 'match_kind' => 'new', 'fields' => '{bad',
                'match_review_required' => 1,
            ]),
            'A row whose column says it is flagged is still flagged, even when its details cannot be read.'
        );

        self::assertTrue(
            $policy->requiresMatchResolution([
                'status' => 'duplicate', 'match_kind' => 'new', 'fields' => '{bad',
                'matched_candidate_id' => 4,
            ]),
            'The same holds for a matched id recorded in the column.'
        );

        // And the fix must not disable the panel for genuinely readable rows.
        self::assertTrue(
            $policy->requiresMatchResolution([
                'status' => 'awaiting_approval', 'match_kind' => 'new',
                'fields' => '{"title":"Fictional event","match_review_required":true}',
            ]),
            'A valid but ambiguous candidate must still report that it needs resolving.'
        );
    }

    /**
     * Issue #177: resolving a match from the detail screen is only worth
     * anything if the candidate can be approved afterwards.
     *
     * "Resolved" here means exactly what the resolution write produces: the two
     * keys are gone — not set to false, because
     * {@see \ADCT\ParishIntake\Core\Matching\MatchReviewPolicy::requiresManualReview()}
     * decides on key *presence* — and the parish column agrees with
     * `fields['parish_id']`. A duplicate the reviewer resolved deliberately keeps
     * its `duplicate` status; it is no longer ambiguous, so nothing blocks it.
     *
     * And a deliberate "leave it unassigned" answer is a real resolution too: the
     * keys are gone, there is no `fields['parish_id']` to disagree with the NULL
     * column, and the candidate falls to the archdiocese to approve.
     */
    public function testACandidateResolvedOnTheDetailScreenBecomesApprovable(): void
    {
        $policy = new ReviewQueuePolicy();
        $resolved = [
            'status' => 'awaiting_approval', 'approved_by' => null, 'decided_at' => null,
            'match_kind' => 'new', 'match_event_id' => null, 'parish_id' => 3,
            'fields' => '{"title":"Fictional event","parish_id":3}',
        ];

        self::assertFalse(
            $policy->requiresMatchResolution($resolved),
            'A candidate with neither key is no longer waiting for a match decision.'
        );
        self::assertTrue($policy->canBulkApprove($resolved));

        $resolvedDuplicate = array_replace($resolved, [
            'status' => 'duplicate',
            'fields' => '{"title":"Fictional event","parish_id":3}',
        ]);
        self::assertFalse($policy->requiresMatchResolution($resolvedDuplicate));
        self::assertTrue(
            $policy->canBulkApprove($resolvedDuplicate),
            'A resolved duplicate is approvable; its status is not itself an ambiguity.'
        );

        $leftUnassigned = array_replace($resolved, [
            'parish_id' => null,
            'fields' => '{"title":"Fictional event"}',
        ]);
        self::assertFalse($policy->requiresMatchResolution($leftUnassigned));
        self::assertTrue(
            $policy->canBulkApprove($leftUnassigned),
            'Deliberately unassigned is a resolution, not a block: it matches no dean, so the archdiocese decides.'
        );

        // Falsifying the keys instead of removing them is the failure mode this pins
                // down, and the two policies disagree about it. MatchReviewPolicy treats a
                // literal `false` as a real answer — "no review needed" — but reads
                // `matched_candidate_id` on presence alone, so a falsified id still asks for
                // manual review on the next match. ReviewQueuePolicy reads truthiness, so the
                // queue would meanwhile see nothing wrong. Only removal satisfies both at
                // once, which is why the resolution unsets rather than writes false.
                self::assertFalse(
                    MatchReviewPolicy::requiresManualReview(['match_review_required' => false]),
                    'A literal false is a real answer to the matcher.'
                );
                foreach ([0, null] as $id) {
                    self::assertTrue(
                        MatchReviewPolicy::requiresManualReview(['matched_candidate_id' => $id]),
                        'A falsified matched_candidate_id still asks for manual review: '
                            . json_encode(['matched_candidate_id' => $id])
                    );
                }
                self::assertFalse(
                    MatchReviewPolicy::requiresManualReview([]),
                    'Removing the keys is what clears the block.'
                );

        // Resolution must also not leave the two statements of the parish
        // disagreeing, or the candidate is still not approvable.
        self::assertFalse(
            $policy->canBulkApprove(array_replace($resolved, [
                'fields' => '{"title":"Fictional event","parish_id":5}',
            ])),
            'Resolution has to keep the parish column and fields["parish_id"] in step.'
        );
    }
}
