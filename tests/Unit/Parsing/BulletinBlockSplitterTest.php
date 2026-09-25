<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\BulletinBlockSplitter;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BulletinBlockSplitterTest extends TestCase
{
    public function testSplitsBlankLineGroupsUnderAnAllCapsHeading(): void
    {
        $blocks = $this->split(<<<'TEXT'
UPCOMING EVENTS

Youth gathering on Saturday 10 October 2026 at 16:00.

Family picnic on Sunday 11 October 2026 at 12:00.
TEXT);

        self::assertCount(2, $blocks);
        self::assertSame('event', $blocks[0]->getClassification());
        self::assertSame('event', $blocks[1]->getClassification());
    }

    public function testRecognizesColonAndMarkdownUnderlinedHeadings(): void
    {
        $blocks = $this->split(<<<'TEXT'
Upcoming events:
Youth gathering on Saturday 10 October 2026 at 16:00.

Family activities
-----------------
Family picnic on Sunday 11 October 2026 at 12:00.
TEXT);

        self::assertCount(2, $blocks);
        self::assertStringContainsString('Youth gathering', $blocks[0]->getSourceText());
        self::assertStringContainsString('Family picnic', $blocks[1]->getSourceText());
    }

    public function testSplitsBulletAndNumberedListItems(): void
    {
        $blocks = $this->split(<<<'TEXT'
EVENTS
- Youth gathering on Saturday 10 October 2026 at 16:00.
2. Family picnic on Sunday 11 October 2026 at 12:00.
TEXT);

        self::assertCount(2, $blocks);
        self::assertStringContainsString('Youth gathering', $blocks[0]->getSourceText());
        self::assertStringContainsString('Family picnic', $blocks[1]->getSourceText());
    }

    public function testSplitsDateLedLines(): void
    {
        $blocks = $this->split(<<<'TEXT'
Sat 10 Oct - Youth gathering at 16:00.
11 October 2026: Family picnic at 12:00.
TEXT);

        self::assertCount(2, $blocks);
        self::assertStringContainsString('Youth gathering', $blocks[0]->getSourceText());
        self::assertStringContainsString('Family picnic', $blocks[1]->getSourceText());
    }

    public function testSplitsPipeDelimitedTableRowsAndExtractsTheirTitle(): void
    {
        $blocks = $this->split(<<<'TEXT'
Date | Event | Time | Venue
10 October 2026 | Youth gathering | 16:00 | Example Parish Hall
11 October 2026 | Family picnic | 12:00 | Fictional Gardens
TEXT);

        self::assertCount(2, $blocks);
        self::assertSame('Youth gathering', $blocks[0]->getTitle());
        self::assertSame('Family picnic', $blocks[1]->getTitle());
        self::assertStringContainsString('Youth gathering', $blocks[0]->getParseText());
    }

    public function testPropagatesParishMonthAndVenueContextToEveryCandidate(): void
    {
        $message = self::message(<<<'TEXT'
Parish: Fictional Parish
OCTOBER 2026
Venue: Fictional Parish Hall

EVENTS
Sat 5 - Youth gathering at 16:00.
Sat 12 - Family picnic at 12:00.
TEXT);

        $outcome = (new PipelineFactory())->create()->parseAll($message);
        $candidates = $outcome->getCandidates();

        self::assertCount(2, $candidates);
        self::assertSame('Fictional Parish', $candidates[0]->getField('parish_name'));
        self::assertSame('Fictional Parish', $candidates[1]->getField('parish_name'));
        self::assertSame('Fictional Parish Hall', $candidates[0]->getField('venue'));
        self::assertSame('Fictional Parish Hall', $candidates[1]->getField('venue'));
        self::assertSame('2026-10-05', $candidates[0]->getField('event_date'));
        self::assertSame('2026-10-12', $candidates[1]->getField('event_date'));
    }

    public function testSingleEventWithGreetingAndSignoffRemainsOneCandidate(): void
    {
        $message = self::message(<<<'TEXT'
Dear friends,

Join us for a youth gathering on Saturday 10 October 2026 at 16:00 at Example Parish Hall.

Kind regards,
Fictional Parish Office
TEXT);

        $outcome = (new PipelineFactory())->create()->parseAll($message);

        self::assertCount(1, $outcome->getCandidates());
        self::assertSame(
            'Youth gathering',
            $outcome->getCandidates()[0]->getField('title')
        );
    }

    public function testSkipsMassTimesAndGreetingsWhenAnEventIsPresent(): void
    {
        $message = self::message(<<<'TEXT'
Hello everyone,

MASS TIMES
Saturday 17:00
Sunday 09:00

EVENTS
Sunday 11 October 2026 - Family picnic at 12:00.

Blessings,
Fictional Parish Office
TEXT);

        $outcome = (new PipelineFactory())->create()->parseAll($message);

        self::assertCount(1, $outcome->getCandidates());
        self::assertStringNotContainsString(
            'MASS TIMES',
            $outcome->getCandidates()[0]->getSourceSnippet()
        );
        self::assertStringNotContainsString(
            'Saturday 17:00',
            (string) $outcome->getCandidates()[0]->getField('description')
        );
        self::assertNotEmpty($outcome->getNotes());
        self::assertContains(
            'skipped',
            array_column($outcome->getBlocks(), 'classification')
        );
    }

    public function testDoesNotMergeUnrelatedNonEventTextIntoASingleEvent(): void
    {
        $message = self::message(<<<'TEXT'
PARISH NOTICE
The office closes early.

EVENTS
Family picnic on Sunday 11 October 2026 at 12:00.
TEXT);

        $outcome = (new PipelineFactory())->create()->parseAll($message);

        self::assertCount(1, $outcome->getCandidates());
        self::assertStringNotContainsString(
            'office closes early',
            (string) $outcome->getCandidates()[0]->getField('description')
        );
        self::assertContains(
            'notice',
            array_column($outcome->getBlocks(), 'classification')
        );
    }

    public function testLegacyParseReturnsTheFirstCandidate(): void
    {
        $message = self::message(<<<'TEXT'
EVENTS
- Youth gathering on Saturday 10 October 2026 at 16:00.
- Family picnic on Sunday 11 October 2026 at 12:00.
TEXT);
        $pipeline = (new PipelineFactory())->create();
        $outcome = $pipeline->parseAll($message);
        $legacyResult = $pipeline->parse($message);

        self::assertCount(2, $outcome->getCandidates());
        self::assertSame(
            $outcome->getCandidates()[0]->toArray(),
            $legacyResult->toArray()
        );
    }

    public function testCandidateSourceSnippetIsTrimmedAndCappedAtTwoThousandCharacters(): void
    {
        $description = str_repeat('A fictional event detail. ', 100);
        $message = self::message(
            'Youth gathering on Saturday 10 October 2026 at 16:00. ' . $description
        );
        $candidate = (new PipelineFactory())->create()->parseAll($message)->getCandidates()[0];

        self::assertSame(0, $candidate->getBlockIndex());
        self::assertLessThanOrEqual(2000, strlen($candidate->getSourceSnippet()));
        self::assertSame(
            trim($candidate->getSourceSnippet()),
            $candidate->getSourceSnippet()
        );
    }

    /**
     * @return array<int, \ADCT\ParishIntake\Core\Parsing\EventBlock>
     */
    private function split(string $body): array
    {
        return (new BulletinBlockSplitter())
            ->split(self::message($body))
            ->getCandidateBlocks();
    }

    private static function message(string $body): Message
    {
        return new Message(
            'email',
            'bulletin-test',
            'events@example.test',
            'Fictional Parish Office',
            'October bulletin',
            $body,
            [],
            new DateTimeImmutable('2026-09-30T09:00:00+02:00')
        );
    }
}
