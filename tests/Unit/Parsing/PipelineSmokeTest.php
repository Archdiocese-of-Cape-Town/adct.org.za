<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit port of tests/parser_smoke_test.php (issue #17).
 *
 * The smoke script only checked that each case had a title and a known classification.
 * These tests keep those checks and also pin the fields the parser already gets right.
 */
final class PipelineSmokeTest extends TestCase
{
    private static function healingMassMessage(): Message
    {
        return new Message(
            'email',
            'smoke-1',
            'secretary@stmarysparish.org.za',
            'St Mary Parish Office',
            'Healing Mass on First Friday',
            "Parish: St Mary Parish\nJoin us every first Friday at 18:00 at St Mary Parish Hall for a healing Mass. Contact office@stmarysparish.org.za"
        );
    }

    private static function youthFundraiserMessage(): Message
    {
        return new Message(
            'email',
            'smoke-2',
            'news@holyfamily.org.za',
            'Holy Family Parish',
            'Youth fundraiser 12 Oct 2026',
            'Our youth fundraiser takes place on 12 Oct 2026 at 19:00. Venue: Holy Family Hall. Contact 021 555 1111.'
        );
    }

    /**
     * @return array<string, array{Message}>
     */
    public static function smokeCases(): array
    {
        return [
            'recurring healing Mass' => [self::healingMassMessage()],
            'once-off youth fundraiser' => [self::youthFundraiserMessage()],
        ];
    }

    /** @return array<string, mixed> */
    private static function parse(Message $message): array
    {
        return (new PipelineFactory())->create()->parse($message)->toArray();
    }

    #[DataProvider('smokeCases')]
    public function testEveryCaseHasATitleAndAKnownClassification(Message $message): void
    {
        $result = self::parse($message);

        self::assertNotNull($result['fields']['title'] ?? null);
        self::assertNotSame('', $result['fields']['title']);
        self::assertArrayHasKey('classification', $result);
        self::assertNotSame('unknown', $result['classification']);
    }

    #[DataProvider('smokeCases')]
    public function testOfflineParsingDoesNotUseAiOrReportErrors(Message $message): void
    {
        $result = self::parse($message);

        self::assertFalse($result['ai_used']);
        self::assertNull($result['ai_provider']);
        self::assertSame([], $result['errors']);
        self::assertSame('0.1.0', $result['parser_version']);
        self::assertContains('rule_based_extraction', $result['strategies']);
    }

    public function testRecurringHealingMassIsParsed(): void
    {
        $result = self::parse(self::healingMassMessage());
        $fields = $result['fields'];

        self::assertSame('recurring_event', $result['classification']);
        self::assertSame('Healing Mass on First Friday', $fields['title']);
        self::assertSame('St Mary Parish', $fields['parish_name']);
        self::assertSame('18:00', $fields['event_time']);
        self::assertSame('office@stmarysparish.org.za', $fields['contact']);
        self::assertSame('St Mary Parish Hall', $fields['venue']);
        self::assertSame('secretary@stmarysparish.org.za', $fields['sender_email']);

        self::assertSame('monthly', $result['recurrence']['frequency']);
        self::assertSame(1, $result['recurrence']['interval']);
        self::assertSame('FR', $result['recurrence']['by_day']);
        self::assertSame(1, $result['recurrence']['by_set_position']);
        self::assertContains('recurrence_detection', $result['strategies']);

        self::assertGreaterThanOrEqual(0.9, $result['confidence']);
    }

    public function testOnceOffYouthFundraiserIsParsed(): void
    {
        $result = self::parse(self::youthFundraiserMessage());
        $fields = $result['fields'];

        self::assertSame('event', $result['classification']);
        self::assertSame('Youth fundraiser 12 Oct 2026', $fields['title']);
        self::assertSame('Holy Family Parish', $fields['parish_name']);
        self::assertSame('2026-10-12', $fields['event_date']);
        self::assertSame('19:00', $fields['event_time']);
        self::assertSame('021 555 1111', $fields['contact']);
        self::assertSame('Holy Family Hall', $fields['venue']);

        self::assertSame([], $result['recurrence']);
        self::assertGreaterThanOrEqual(0.9, $result['confidence']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function venueTerminationCases(): array
    {
        return [
            'event description after venue' => [
                'The event is at St Mary Parish Hall for a healing Mass.',
                'St Mary Parish Hall',
            ],
            'period in abbreviated venue name' => [
                'The meeting is at St. Example Parish Hall at 7pm.',
                'St. Example Parish Hall',
            ],
            'time after venue' => [
                'The meeting is at Example Parish Hall at 7pm.',
                'Example Parish Hall',
            ],
            'weekday after venue' => [
                'The meeting is at Fictional Community Centre on Saturday.',
                'Fictional Community Centre',
            ],
            'comma after venue' => [
                'Location: Sample Parish Hall, near the entrance.',
                'Sample Parish Hall',
            ],
            'newline after venue' => [
                "The gathering is at Invented Hall\nPlease arrive early.",
                'Invented Hall',
            ],
        ];
    }

    #[DataProvider('venueTerminationCases')]
    public function testVenueExtractionStopsAtVenueName(string $body, string $expectedVenue): void
    {
        $message = new Message(
            'email',
            'venue-test',
            'events@example.test',
            'Example Parish Office',
            'Example event',
            $body
        );

        $result = self::parse($message);

        self::assertSame($expectedVenue, $result['fields']['venue'] ?? null);
    }
}
