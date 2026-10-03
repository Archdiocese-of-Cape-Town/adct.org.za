<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Publishing;

use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\Core\Publishing\Publication;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CandidatePublisherTest extends TestCase
{
    public function testApprovedCreateNormalizesParserDateAndPreservesSource(): void
    {
        $store = new RecordingPublicationStore($this->candidate());
        $id = $this->publisher($store)->publish(7);

        self::assertSame(34, $id);
        self::assertSame('2026-10-12T09:00', $store->publication->details->startLocal);
        self::assertSame('2026-10-12T10:00', $store->publication->details->endLocal);
        self::assertSame(7, $store->publication->details->sourceCandidateId);
        self::assertSame('FREQ=WEEKLY;BYDAY=MO', $store->publication->details->rrule);
        self::assertSame('scheduled', $store->publication->details->statusFlag);
        self::assertNull($store->publication->eventType);
    }

    #[DataProvider('changedKinds')]
    public function testApprovedChangesTargetTheMatchedEvent(string $kind, string $status): void
    {
        $row = $this->candidate();
        $row['match_kind'] = $kind;
        $row['match_event_id'] = '34';
        $store = new RecordingPublicationStore($row);

        self::assertSame(34, $this->publisher($store)->publish(7));
        self::assertSame(34, $store->publication->eventId);
        self::assertSame($status, $store->publication->details->statusFlag);
    }

    public static function changedKinds(): iterable
    {
        yield 'update' => ['update', 'scheduled'];
        yield 'cancel' => ['cancellation', 'cancelled'];
        yield 'postpone' => ['postponement', 'postponed'];
    }

    #[DataProvider('unauthorizedCandidates')]
    public function testSenderConfirmationOrUnverifiedChangeCannotPublish(array $changes): void
    {
        $store = new RecordingPublicationStore(array_replace($this->candidate(), $changes));

        $this->expectException(DomainException::class);
        $this->publisher($store)->publish(7);
    }

    public static function unauthorizedCandidates(): iterable
    {
        yield 'mere confirmation' => [['approved_via' => null, 'status' => 'awaiting_approval']];
        yield 'contact label alone' => [[
            'approved_via' => 'contact_change',
            'match_kind' => 'update',
            'match_event_id' => '34',
        ]];
        yield 'missing actor' => [['approved_by' => null]];
        yield 'draft' => [['status' => 'draft']];
        yield 'rejected' => [['status' => 'rejected']];
    }

    #[DataProvider('manualReviewMatches')]
    public function testCandidatesRequiringManualMatchReviewCannotBePublished(array $reviewFields): void
    {
        $row = $this->candidate();
        $fields = json_decode($row['fields'], true, 512, JSON_THROW_ON_ERROR);
        $row['fields'] = json_encode(array_merge($fields, $reviewFields), JSON_THROW_ON_ERROR);
        $store = new RecordingPublicationStore($row);

        try {
            $this->publisher($store)->publish(7);
            self::fail('A candidate requiring manual match review must not be published.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('manual review', $failure->getMessage());
        }

        self::assertNull($store->publication);
    }

    public static function manualReviewMatches(): iterable
    {
        yield 'ambiguous match' => [['match_review_required' => true]];
        yield 'pending candidate match' => [['matched_candidate_id' => 42]];
        yield 'invalid review flag' => [['match_review_required' => 'false']];
    }

    /**
         * Exactly the row ReviewQueueRepository::createManualCandidate() writes,
         * then as it stands once a person has filled the edit form in. The only
         * difference from a parsed candidate is where the text came from, so manual
         * entry must not be a way round the approval allow-list.
         *
         * Each case withholds or corrupts one of the approval fields. They are
         * separate data sets rather than one assertion so that dropping any single
         * clause of the guard is caught here: a case that still passes after a
         * clause is deleted is a clause the test was not actually reading, which is
         * how an allow-list quietly widens.
         *
         * @param array{via: ?string, by: ?string, at: ?string} $approval
         */
        #[DataProvider('manualApprovalGaps')]
        public function testAHandTypedEventCannotPublishWithoutADeanOrReviewerDecision(array $approval): void
        {
            $store = new RecordingPublicationStore([
                'id' => '7',
                'status' => 'awaiting_approval',
                'approved_via' => $approval['via'],
                'approved_by' => $approval['by'],
                'approved_at' => $approval['at'],
                'match_kind' => 'new',
                'match_event_id' => null,
                'parish_id' => '3',
                'fields' => json_encode([
                    'title' => 'Parish evening service',
                    'event_date' => '2026-10-12',
                    'event_time' => '18:00',
                ], JSON_THROW_ON_ERROR),
                'recurrence' => null,
                'notes' => '["manual_entry"]',
            ]);

            try {
                $this->publisher($store)->publish(7);
                self::fail('A hand-typed event must not reach publication without a recorded approval.');
            } catch (DomainException $failure) {
                self::assertStringContainsString(
                    'recorded dean, reviewer or self approval',
                    $failure->getMessage()
                );
            }

            self::assertNull($store->publication);
        }

        /**
         * @return array<string, array{0: array{via: ?string, by: ?string, at: ?string}}>
         */
        public static function manualApprovalGaps(): iterable
        {
            // As createManualCandidate() leaves it: nothing decided, nothing stamped.
            yield 'no decision recorded' => [['via' => null, 'by' => null, 'at' => null]];

            // Every other case names someone and a time but leaves approved_via off
            // the list. This is the case that pins the allow-list itself: if the list
            // were widened to accept it, these would publish with nothing behind them.
            yield 'claimed as manual, not a dean or reviewer' => [
                ['via' => 'by_hand', 'by' => 'chaplain@example.test', 'at' => '2026-09-25 09:00:00'],
            ];
            yield 'blank approver' => [
                ['via' => 'reviewer', 'by' => '   ', 'at' => '2026-09-25 09:00:00'],
            ];
            yield 'no approver named' => [
                ['via' => 'reviewer', 'by' => null, 'at' => '2026-09-25 09:00:00'],
            ];
            yield 'no approval time' => [
                ['via' => 'reviewer', 'by' => 'reviewer@example.test', 'at' => null],
            ];
        }

    public function testAHandTypedEventStillPublishesOnceADecisionIsRecorded(): void
    {
        // The counterpart: manual entry is not a slower or a different route.
        // Once the reviewer records the ordinary decision, the same row
        // publishes exactly like a parsed one.
        $store = new RecordingPublicationStore([
            'id' => '7',
            'status' => 'approved',
            'approved_via' => 'dean',
            'approved_by' => 'dean@example.test',
            'approved_at' => '2026-09-25 09:00:00',
            'match_kind' => 'new',
            'match_event_id' => null,
            'parish_id' => '3',
            'fields' => json_encode([
                'title' => 'Parish evening service',
                'event_date' => '2026-10-12',
                'event_time' => '18:00',
            ], JSON_THROW_ON_ERROR),
            'recurrence' => null,
            'notes' => '["manual_entry"]',
        ]);

        self::assertSame(34, $this->publisher($store)->publish(7));
        self::assertSame('Parish evening service', $store->publication->title);
    }

    public function testRetryUsesPublishedCandidateLink(): void
    {
        $row = $this->candidate();
        $row['status'] = 'published';
        $row['match_event_id'] = '34';
        $store = new RecordingPublicationStore($row);

        self::assertSame(34, $this->publisher($store)->publish(7));
    }

    public function testEmptyRecurrenceObjectMeansNoRule(): void
    {
        $row = $this->candidate();
        $row['recurrence'] = '{}';
        $store = new RecordingPublicationStore($row);
        $this->publisher($store)->publish(7);
        self::assertNull($store->publication->details->rrule);
    }

    public function testMalformedDateFailsBeforePersistence(): void
    {
        $row = $this->candidate();
        $row['fields'] = json_encode([
            'title' => 'Sample event',
            'event_date' => '12/10/2026',
            'event_time' => '09:00',
        ], JSON_THROW_ON_ERROR);
        $store = new RecordingPublicationStore($row);
        $this->expectException(DomainException::class);
        $this->publisher($store)->publish(7);
    }

    private function publisher(RecordingPublicationStore $store): CandidatePublisher
    {
        return new CandidatePublisher($store, new EventValidator(new DateTimeZone('Africa/Johannesburg')));
    }

    private function candidate(): array
    {
        return [
            'id' => '7',
            'status' => 'awaiting_approval',
            'approved_via' => 'reviewer',
            'approved_by' => 'reviewer@example.test',
            'approved_at' => '2026-09-25 09:00:00',
            'match_kind' => 'new',
            'match_event_id' => null,
            'parish_id' => null,
            'fields' => json_encode([
                'title' => 'Sample event',
                'description' => 'Anonymised details.',
                'event_date' => '2026-10-12',
                'event_time' => '09:00',
                'event_end_time' => '10:00',
            ], JSON_THROW_ON_ERROR),
            'recurrence' => '{"rrule":"FREQ=WEEKLY;BYDAY=MO"}',
        ];
    }
}

final class RecordingPublicationStore implements PublicationStoreInterface
{
    public ?Publication $publication = null;

    public function __construct(private array $row)
    {
    }

    public function publish(int $candidateId, callable $prepare): int
    {
        $this->publication = $prepare($this->row);
        return $this->publication->eventId ?? 34;
    }
}
