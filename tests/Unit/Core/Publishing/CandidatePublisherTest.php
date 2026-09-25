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
