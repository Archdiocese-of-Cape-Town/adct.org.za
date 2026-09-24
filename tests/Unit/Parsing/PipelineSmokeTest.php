<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\PipelineFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit port of tests/parser_smoke_test.php (issue #17).
 *
 * The smoke script only checked that each case had a title and a known classification.
 * These tests keep those checks and also pin the fields the parser already gets right.
 * Venue extraction currently reads past the venue name, so only the start of the venue is asserted.
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

    /**
     * A fresh pipeline per message: notes and errors currently leak between parse() calls (#78).
     *
     * @return array<string, mixed>
     */
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
        // Venue extraction overshoots past the name (#78); tighten to assertSame once fixed.
        self::assertStringStartsWith('St Mary Parish Hall', $fields['venue']);
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
        // Venue extraction overshoots past the name (#78); tighten to assertSame once fixed.
        self::assertStringStartsWith('Holy Family Hall', $fields['venue']);

        self::assertSame([], $result['recurrence']);
        self::assertGreaterThanOrEqual(0.9, $result['confidence']);
    }
}
