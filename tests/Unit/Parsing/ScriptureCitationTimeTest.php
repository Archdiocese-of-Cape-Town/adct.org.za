<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A chapter-and-verse citation looks exactly like a clock time. "Mark 16:15" is
 * indistinguishable from "16:15" to a bare `\d{1,2}:\d{2}` pattern, so a citation in
 * the prose of a bulletin was being published as the event's start time.
 */
final class ScriptureCitationTimeTest extends TestCase
{
    private static function parse(string $body): array
    {
        $message = new Message(
            'email',
            'scripture-test',
            'events@example.test',
            'Example Parish Office',
            '',
            $body,
            [],
            new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Johannesburg'))
        );

        return (new PipelineFactory())
            ->create()
            ->parse($message)
            ->toArray();
    }

    /**
     * Citations use invented book names so no real scripture text or parish data is
     * committed to this public repository.
     *
     * @return array<string, array{string}>
     */
    public static function scriptureCitationsWithoutAnEventTime(): array
    {
        return [
            'single verse' => ['The reading for Sunday is from Mark 16:15.'],
            'verse range with an en dash' => ['Please read Matthew 18:15–20 before Mass.'],
            'verse range with a hyphen' => ['Please read Romans 13:8-10 before Mass.'],
            'verse range across three verses' => ['The reading is from Luke 24:13–35.'],
            'verse range with a short chapter' => ['Read Psalm 95:5 and give thanks.'],
            'verse range in the second citation' => [
                'The first reading is from Acts 2:1-4. The second is from Ezekiel 33:7–9.',
            ],
            'citation with a trailing period' => ['The second reading is from Colossians 3:12.'],
            'citation in a bullet list' => ['Readings:`n- Galatians 5:13`n- Hebrews 11:1'],
        ];
    }

    #[DataProvider('scriptureCitationsWithoutAnEventTime')]
    public function testScriptureCitationIsNotExtractedAsAnEventTime(string $body): void
    {
        $result = self::parse($body);

        self::assertArrayNotHasKey('event_time', $result['fields']);
        self::assertArrayNotHasKey('event_end_time', $result['fields']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function citationsAlongsideAGenuineTime(): array
    {
        return [
            'time before the citation' => ['Mass at 18:15. The reading is from Mark 16:15.', '18:15'],
            'time after the citation' => ['The reading is from Mark 16:15. Mass is at 18:15.', '18:15'],
            'dotted time after a verse range' => ['Read Matthew 18:15–20. Mass is at 8.30 am.', '08:30'],
            'time given in words after a citation' => ['Read Psalm 95:5. Mass is at 6pm.', '18:00'],
            'twenty-four hour time after a citation' => ['Read Romans 13:8-10. Mass is at 17:00.', '17:00'],
        ];
    }

    #[DataProvider('citationsAlongsideAGenuineTime')]
    public function testGenuineTimeSurvivesAScriptureCitation(string $body, string $expected): void
    {
        $result = self::parse($body);

        self::assertSame($expected, $result['fields']['event_time'] ?? null);
    }

    /**
     * A colon-separated pair with no book name is still a time. Rejecting every
     * verse-shaped token would break ordinary notices.
     */
    public function testBareTimeWithNoCitationContextIsStillATime(): void
    {
        $result = self::parse('The meeting starts at 16:15 sharp.');

        self::assertSame('16:15', $result['fields']['event_time'] ?? null);
    }

    /**
     * The citation rule must key off the book name, not off the shape of the number
     * pair alone, or every ordinary clock time in every bulletin would be dropped.
     * "Meeting room 3" is a location, not a citation, and must stay extractable.
     */
    public function testNonCitationChapterAndVerseNumberingIsNotTreatedAsACitation(): void
    {
        $result = self::parse('Carols in Meeting room 3 at 18:15 on 5 October.');

        self::assertSame('18:15', $result['fields']['event_time'] ?? null);
    }
}
