<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParishNameExtractionTest extends TestCase
{
    /**
     * The default sender deliberately contains neither "parish" nor "church" so the
     * sender-name fallback cannot mask what the text itself produced.
     */
    private static function parse(string $body, string $senderName = 'Fictional Office'): array
    {
        $message = new Message(
            'email',
            'parish-name-test',
            'events@example.test',
            $senderName,
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
     * @return array<string, array{string}>
     */
    public static function sentenceFragmentsThatAreNotParishNames(): array
    {
        return [
            'pastoral councils' => ['I remind all Parish Pastoral Councils to send their responses.'],
            'leading definite article' => ['The Parish office is closed on Friday.'],
            'quantified parish' => ['Every Parish should return the form to the office.'],
            'pastoral role mid sentence' => ['Thank you to the Parish Financial Council for their help.'],
        ];
    }

    #[DataProvider('sentenceFragmentsThatAreNotParishNames')]
    public function testSentenceFragmentIsNotExtractedAsAParishName(string $body): void
    {
        $result = self::parse($body);

        self::assertArrayNotHasKey('parish_name', $result['fields']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function genuineParishNames(): array
    {
        return [
            'labelled line' => ["Parish: St Mary\nAll are welcome.", 'St Mary'],
            'labelled line with full name' => ["Parish: St Mary Parish\nAll are welcome.", 'St Mary Parish'],
            'name at the start of a line' => ["St Mary's Parish\nRetreat on 5 October.", "St Mary's Parish"],
            'our lady of at line start' => ["Our Lady of Grace Parish\nRetreat on 5 October.", 'Our Lady of Grace Parish'],
            'our lady of the cape' => ["Our Lady of the Cape Parish\nRetreat on 5 October.", 'Our Lady of the Cape Parish'],
            'saint name of a place' => ["St Francis of Assisi Parish\nRetreat on 5 October.", 'St Francis of Assisi Parish'],
            'our lady of behind a parish label' => ["Parish: Our Lady of Grace\nAll are welcome.", 'Our Lady of Grace'],
        ];
    }

    #[DataProvider('genuineParishNames')]
    public function testGenuineParishNameIsStillExtracted(string $body, string $expected): void
    {
        $result = self::parse($body);

        self::assertSame($expected, $result['fields']['parish_name'] ?? null);
    }

    public function testCaptureStopsAtSentencePunctuation(): void
    {
        $result = self::parse("St Mary Parish. Mass is at 09:00 on 5 October.");

        self::assertSame('St Mary Parish', $result['fields']['parish_name'] ?? null);
    }

    public function testSenderNameIsStillUsedWhenNoParishNameIsInTheText(): void
    {
        $result = self::parse(
            'Healing Mass on 5 October at 18:00.',
            'St Fictional Parish Office'
        );

        self::assertSame('St Fictional Parish Office', $result['fields']['parish_name'] ?? null);
    }

    public function testRejectedFragmentFallsThroughToTheSenderName(): void
    {
        $result = self::parse(
            'I remind all Parish Pastoral Councils to send their responses.',
            'St Fictional Parish Office'
        );

        self::assertSame('St Fictional Parish Office', $result['fields']['parish_name'] ?? null);
    }

    /**
     * A line naming a church is a venue, and the block splitter lifts it out of the
     * text before parish extraction runs. Recording it as the venue is correct; what
     * matters here is that it never also becomes a parish name.
     */
    public function testChurchVenueLineIsCapturedAsAVenueNotAParishName(): void
    {
        $result = self::parse("Our Lady of Peace Church\nRetreat on 5 October.");

        self::assertSame('Our Lady of Peace Church', $result['fields']['venue'] ?? null);
        self::assertArrayNotHasKey('parish_name', $result['fields']);
    }
}
