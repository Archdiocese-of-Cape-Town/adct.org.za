<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Publishing;

use ADCT\ParishIntake\Core\Directory\SenderLookupResult;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use ADCT\ParishIntake\Core\Ports\PublicationAuthorityInterface;
use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\Core\Publishing\Publication;
use ADCT\ParishIntake\Core\Publishing\ReviewRequiredPublicationAuthority;
use ADCT\ParishIntake\Core\Publishing\VerifiedContactPublicationAuthority;
use DateTimeZone;
use DomainException;
use LogicException;
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

        try {
            $this->publisher($store)->publish(7);
            self::fail('A candidate with no dean, reviewer or self approval must not publish.');
        } catch (DomainException $failure) {
            // Assert the message, not only the type. A DomainException from a
            // different gate would satisfy a type-only assertion, which is how the
            // contact label below can quietly become authority instead of staying
            // a label.
            self::assertStringContainsString(
                'recorded dean, reviewer or self approval',
                $failure->getMessage()
            );
        }

        self::assertNull($store->publication);
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

    /**
    * What production constructs with: no authority and no directory at all. A
    * verified contact's change is refused on that construction, which is the
    * answer #200 is waiting on, and which cannot damage a published event if the
    * owner later decides the other way.
    */
    public function testAVerifiedContactStillWaitsForReviewUnderTheDefaultConstruction(): void
    {
        $store = new RecordingPublicationStore($this->contactChange());
        $contacts = new FakeContactStore([
            'contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::VERIFIED]],
        ]);

        try {
            $this->publisher($store, null, $contacts)->publish(7);
            self::fail('The default construction must not let a contact change publish itself.');
        } catch (DomainException $failure) {
            self::assertStringContainsString(
        'recorded dean, reviewer or self approval',
        $failure->getMessage()
            );
        }

        self::assertNull($store->publication);
    }

    /**
    * The policy the plugin ships, named as such rather than inferred from the
    * absence of an argument: the conservative policy refuses on its own merits
    * and without consulting the directory, so it stays refused however the
    * directory happens to look at the time.
    */
    public function testTheShippedPolicyRefusesEveryContactChangeWithoutAskingTheDirectory(): void
    {
        $authority = new ReviewRequiredPublicationAuthority();
        $sender = new SenderLookupResult('contact@example.test', SenderTrust::VERIFIED, [3]);

        self::assertFalse($authority->allowsContactChange($this->contactChange(), $sender));
        self::assertFalse($authority->allowsContactChange(
            array_merge($this->contactChange(), ['approved_via' => 'dean', 'approved_at' => '2026-09-25 09:00:00']),
            $sender
        ));
        self::assertFalse($authority->allowsContactChange([], $sender));
    }

    /**
    * A permissive authority with no directory behind it can allow nothing, so the
    * contacts argument is not optional decoration: hand it over.
    */
    public function testAPermissiveAuthorityWithoutADirectoryCanAllowNothing(): void
    {
        $store = new RecordingPublicationStore($this->contactChange());

        try {
            $this->publisher($store, new VerifiedContactPublicationAuthority())->publish(7);
            self::fail('An authority with no directory to check against must refuse.');
        } catch (DomainException $failure) {
            self::assertStringContainsString(
        'recorded dean, reviewer or self approval',
        $failure->getMessage()
            );
        }

        self::assertNull($store->publication);
    }

    /**
        * The decision is an injected argument, not a constant. With the permissive
        * authority the same verified contact's change publishes, is aimed at the
        * matched event, and records the contact as the actor — so the change trail
        * and the change notice can say who moved it.
        */
        public function testTheInjectedAuthorityDecidesWhetherAVerifiedContactMayPublishAChange(): void
        {
            $contacts = new FakeContactStore([
                'contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::VERIFIED]],
            ]);
            $store = new RecordingPublicationStore($this->contactChange());

            $eventId = $this->publisher($store, new VerifiedContactPublicationAuthority(), $contacts)
                ->publish(7);

            self::assertSame(34, $eventId);
            self::assertSame('contact@example.test', $store->publication->actor);
            self::assertSame('cancellation', $store->publication->kind);
            self::assertSame('cancelled', $store->publication->details->statusFlag);
        }

        /**
        * Each case withholds or breaks exactly one piece of the evidence a contact
        * change needs, so deleting any single clause of the guard leaves exactly one
        * data set failing. A clause whose deletion leaves every case passing is a
        * clause the test was not reading.
        *
        * @param array<string, array<int, array<string, mixed>>> $directory
        * @param array<string, mixed> $rowChanges
        */
        #[DataProvider('unprovenContactChanges')]
        public function testAContactChangeWithNoLiveProofOfVerificationCannotPublish(
            array $directory,
            array $rowChanges
        ): void {
            $store = new RecordingPublicationStore(array_replace($this->contactChange(), $rowChanges));

            try {
                $this->publisher(
                    $store,
                    new VerifiedContactPublicationAuthority(),
                    new FakeContactStore($directory)
                )->publish(7);
                self::fail('A contact change with no live verified trust must not publish itself.');
            } catch (DomainException $failure) {
                self::assertStringContainsString(
                    'recorded dean, reviewer or self approval',
                    $failure->getMessage()
                );
            }

            self::assertNull($store->publication);
        }

        /**
        * @return array<string, array{0: array<string, array<int, array<string, mixed>>>, 1: array<string, mixed>}>
        */
        public static function unprovenContactChanges(): iterable
        {
            $verified = ['contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::VERIFIED]]];

            // Nobody signed it. A caller who writes contact_change without saying who
            // sent it has asserted the label and nothing else.
            yield 'no sender named' => [[], []];

            // A blank address is not an address.
            yield 'blank sender' => [[], ['approved_by' => '   ']];

            // Verified once, since withdrawn.
            yield 'verification withdrawn' => [
                ['contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::PENDING]]], [],
            ];

            // A monitored source: in the directory, explicitly blocked.
            yield 'blocked sender' => [
                ['contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::BLOCKED]]], [],
            ];

            // Never linked to a parish at all.
            yield 'unknown sender' => [
                ['contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::UNKNOWN]]], [],
            ];

            // The directory has no such address: a deleted link looks like trust the
            // row still claims.
            yield 'sender no longer in the directory' => [
                ['someone.else@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::VERIFIED]]], [],
            ];

            // Verified, but for another parish. Trust is per parish, so a
            // verification for parish 9 authorises nothing for parish 3.
            yield 'verified for another parish' => [
                ['contact@example.test' => [['parish_id' => 9, 'trust' => SenderTrust::VERIFIED]]], [],
            ];

            // Verified for one parish, linked to two, only one of which is this
            // candidate's. The other parish must not lend its trust.
            yield 'verified for this and another parish' => [
                ['contact@example.test' => [
                    ['parish_id' => 3, 'trust' => SenderTrust::VERIFIED],
                    ['parish_id' => 9, 'trust' => SenderTrust::VERIFIED],
                ]],
                ['parish_id' => '4'],
            ];

            // The candidate is for a parish the sender is not linked to.
            yield 'verified but not for this candidate parish' => [$verified, ['parish_id' => '4']];

            // A parishless change has nothing to check a parish-scoped verification
            // against, so it cannot take the contact-change route.
            yield 'change with no parish' => [$verified, ['parish_id' => null]];

            // Unparseable approved_by cannot be resolved to an address to check.
            yield 'unusable sender address' => [$verified, ['approved_by' => 'not-an-address']];

            // A directory row whose trust no longer parses must not be read as
            // "close enough to verified".
            yield 'unreadable trust in the directory' => [
                ['contact@example.test' => [['parish_id' => 3, 'trust' => 'perhaps']]], [],
            ];

            // The same address trusted differently across two parish links is a
            // contradiction, not a permission.
            yield 'inconsistent trust across parish links' => [
                ['contact@example.test' => [
                    ['parish_id' => 3, 'trust' => SenderTrust::VERIFIED],
                    ['parish_id' => 9, 'trust' => SenderTrust::PENDING],
                ]],
                [],
            ];
        }

        /**
        * A contact change records the sender and the moment the change was made; the
        * approver's decision lives in a different column and is not the contact's to
        * fill in. Nothing on the contact route reads approved_at, so a row carrying
        * a stale or invented decision time publishes exactly as one carrying none —
        * which is asserted here so the column cannot quietly become a requirement.
        */
        #[DataProvider('irrelevantApprovalTimes')]
        public function testTheApprovalTimeIsNotPartOfTheContactDecision(
            mixed $approvedAt,
            bool $publishes
        ): void {
            $row = $this->contactChange();
            $row['approved_at'] = $approvedAt;
            $store = new RecordingPublicationStore($row);

            if (! $publishes) {
                $this->expectException(DomainException::class);
            }

            self::assertSame(34, $this->publisher(
                $store,
                new VerifiedContactPublicationAuthority(),
                new FakeContactStore([
                    'contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::VERIFIED]],
                ])
            )->publish(7));
        }

        /**
        * @return array<string, array{0: mixed, 1: bool}>
        */
        public static function irrelevantApprovalTimes(): iterable
        {
            yield 'recorded' => ['2026-09-25 09:00:00', true];
            yield 'absent' => [null, true];
            yield 'blank' => ['   ', true];
            yield 'not a timestamp at all' => ['whenever the secretary felt like it', true];
        }

        /**
        * The lookup is by sender and by parish, and the answer comes from the
        * directory rather than from anything on the candidate. The first half
        * publishes with the address verified for parish 3; the second half uses the
        * same row after the directory has forgotten that address, which is what a
        * withdrawn verification looks like to a row written weeks earlier.
        */
        public function testTrustIsReReadFromTheDirectoryAtPublicationTime(): void
        {
            $contacts = new FakeContactStore([
                'contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::VERIFIED]],
            ]);

            $first = new RecordingPublicationStore($this->contactChange());
            self::assertSame(34, $this->publisher(
                $first,
                new VerifiedContactPublicationAuthority(),
                $contacts
            )->publish(7));
            self::assertSame('contact@example.test', $first->publication->actor);

            $contacts->forget('contact@example.test');

            $second = new RecordingPublicationStore($this->contactChange());
            try {
                $this->publisher($second, new VerifiedContactPublicationAuthority(), $contacts)->publish(7);
                self::fail('A withdrawn directory entry must stop the change publishing itself.');
            } catch (DomainException $failure) {
                self::assertStringContainsString(
                    'recorded dean, reviewer or self approval',
                    $failure->getMessage()
                );
            }

            self::assertNull($second->publication);
        }

        /**
        * The permissive authority still requires a decision from a dean, reviewer
        * or submitter to be absent — it is an addition to the recorded route, never
        * a replacement for it. A candidate that already carries a dean decision
        * publishes identically whichever authority is injected.
        */
        public function testARecordedDeanDecisionIsUnchangedByTheInjectedAuthority(): void
        {
            $row = $this->candidate();
            $row['match_kind'] = 'cancellation';
            $row['match_event_id'] = '34';
            $store = new RecordingPublicationStore($row);

            self::assertSame(34, $this->publisher($store, new VerifiedContactPublicationAuthority())
                ->publish(7));
            self::assertSame('reviewer@example.test', $store->publication->actor);
        }

        /**
        * A contact change that is not actually a change — a brand new event — still
        * needs a first read, even from a contact the permissive authority would
        * otherwise let through. The route is for altering an event that is already
        * public, not for putting a new one there unreviewed.
        *
        * Asserted as a refusal without caring which clause refuses it, so this test
        * keeps its meaning whether the change is caught by the contact route or by
        * the match rules below it.
        */
        public function testAContactCannotPublishABrandNewEventWithoutARead(): void
        {
            $row = $this->contactChange();
            $row['match_kind'] = 'new';
            $row['match_event_id'] = null;
            $store = new RecordingPublicationStore($row);

            $this->expectException(DomainException::class);
            $this->publisher(
                $store,
                new VerifiedContactPublicationAuthority(),
                new FakeContactStore([
                    'contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::VERIFIED]],
                ])
            )->publish(7);

            self::assertNull($store->publication, 'A brand new event from a contact must not reach the site.');
        }

        /**
        * A candidate is only ever publishable by the authority once it is a real
        * change to a real event. A 'new' candidate with an event already attached,
        * or a change with no event, is refused — and the contact route does not get
        * a way round the match rules.
        */
        #[DataProvider('impossibleContactChangeTargets')]
        public function testTheContactRouteDoesNotBypassTheMatchRules(array $rowChanges): void
        {
            $store = new RecordingPublicationStore(array_replace($this->contactChange(), $rowChanges));

            $this->expectException(DomainException::class);
            $this->publisher(
                $store,
                new VerifiedContactPublicationAuthority(),
                new FakeContactStore([
                    'contact@example.test' => [['parish_id' => 3, 'trust' => SenderTrust::VERIFIED]],
                ])
            )->publish(7);

            self::assertNull($store->publication, 'The contact route must not reach past the match rules.');
        }

    /**
    * @return array<string, array{0: array<string, mixed>}>
    */
    public static function impossibleContactChangeTargets(): iterable
    {
        yield 'new candidate that already carries an event' => [
            ['match_kind' => 'new', 'match_event_id' => '34'],
        ];
        yield 'change with no event to change' => [['match_event_id' => null]];
        yield 'change whose event is zero' => [['match_event_id' => '0']];
        yield 'unknown match kind' => [['match_kind' => 'amendment']];
    }

    /**
    * @return array<string, mixed>
    */
    private function contactChange(): array
    {
        $row = $this->candidate();
        $row['status'] = 'awaiting_approval';
        $row['approved_via'] = 'contact_change';
        $row['approved_by'] = 'contact@example.test';
        $row['approved_at'] = '2026-09-25 09:00:00';
        $row['parish_id'] = '3';
        $row['match_kind'] = 'cancellation';
        $row['match_event_id'] = '34';

        return $row;
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

    /**
    * The two-argument construction is what the rest of this file uses: no
    * authority, so a contact change never publishes itself. The extra arguments
    * appear only where a test is about the injected policy rather than about
    * publishing.
    */
    private function publisher(
            RecordingPublicationStore $store,
            ?PublicationAuthorityInterface $authority = null,
            ?ParishContactStoreInterface $contacts = null
        ): CandidatePublisher {
            return new CandidatePublisher(
                $store,
                new EventValidator(new DateTimeZone('Africa/Johannesburg')),
                $authority,
                $contacts
            );
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

/**
 * The parish directory as the publication path sees it: an address mapped to the
 * parish links it has. forget() is the point of the class — a verification being
 * withdrawn mid-flight is a row disappearing, not a column changing.
 *
 * @implements ParishContactStoreInterface
 */
final class FakeContactStore implements ParishContactStoreInterface
{
    /**
     * @param array<string, array<int, array<string, mixed>>> $directory
     */
    public function __construct(private array $directory = [])
    {
    }

    public function forget(string $email): void
    {
        unset($this->directory[$email]);
    }

    public function findByEmail(string $email): array
    {
        return $this->directory[strtolower(trim($email))] ?? [];
    }

    public function savePendingSender(string $email, ?int $suggestedParishId, ?string $source, string $timestamp): void
    {
        throw new LogicException('The publication path must never write to the directory.');
    }

        public function saveLink(
                int $parishId,
                string $email,
                string $displayName,
                string $roleLabel,
                bool $receivesReminders,
                string $trust,
                ?string $verifiedAt,
                string $timestamp
            ): void {
                throw new LogicException('The publication path must never write to the directory.');
            }

            public function savePendingLink(
                int $parishId,
                string $email,
                string $displayName,
                string $roleLabel,
                bool $receivesReminders,
                string $timestamp
            ): int {
                throw new LogicException('The publication path must never write to the directory.');
            }

            public function updateLink(
                int $contactId,
                int $parishId,
                string $email,
                string $displayName,
                string $roleLabel,
                bool $receivesReminders,
                string $trust,
                ?string $verifiedAt,
                string $timestamp
            ): int {
                throw new LogicException('The publication path must never write to the directory.');
            }

            public function deleteLink(int $contactId, int $parishId): int
            {
                throw new LogicException('The publication path must never write to the directory.');
            }

            public function setTrustForEmail(string $email, string $trust, ?string $verifiedAt, string $timestamp): int
            {
                throw new LogicException('The publication path must never write to the directory.');
            }

            /**
            * @return array<int, array<string, mixed>>
            */
            public function findForParish(int $parishId): array
            {
                $rows = [];
                foreach ($this->directory as $links) {
                    foreach ($links as $link) {
                        if ((int) ($link['parish_id'] ?? 0) === $parishId) {
                            $rows[] = $link;
                        }
                    }
                }

                return $rows;
            }

            /**
            * @return array<string, mixed>|null
            */
            public function findLink(int $contactId, int $parishId): ?array
            {
                foreach ($this->directory as $links) {
                    foreach ($links as $link) {
                        if ((int) ($link['parish_id'] ?? 0) === $parishId && (int) ($link['id'] ?? 0) === $contactId) {
                            return $link;
                        }
                    }
                }

                return null;
            }
        }
