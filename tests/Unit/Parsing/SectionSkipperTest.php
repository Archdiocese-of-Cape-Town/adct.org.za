<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Parsing\SectionSkipper;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SectionSkipperTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sectionCases(): array
    {
        return [
            'mass times' => [
                'mass_times',
                'MASS-TIMES',
                "Saturday 17:00 Mass\nSunday 09:00 Service",
            ],
            'mass intentions' => [
                'mass_intentions',
                'MASS intentions:',
                'For the intentions of Fictional Person Alpha.',
            ],
            'sick list' => [
                'sick_list',
                'Sick list',
                "Fictional Person Beta\nParish: Fictional Person Beta",
            ],
            'deceased' => [
                'deceased',
                'Recently deceased',
                'Please remember Fictional Person Gamma.',
            ],
            'anniversaries' => [
                'anniversaries',
                'Anniversaries',
                'Fictional Person Delta and Fictional Person Epsilon.',
            ],
            'raffle winners' => [
                'raffle_winners',
                'Raffle winners',
                'Fictional Person Zeta won.',
            ],
            'collections and finances' => [
                'collections_finances',
                'Collections & finances',
                'The monthly collection summary is available from the fictional office.',
            ],
            'banking details' => [
                'banking_details',
                'Banking details',
                'Contact the fictional office for payment instructions.',
            ],
            'readings' => [
                'readings',
                'Readings',
                'The fictional readings are listed in the bulletin.',
            ],
        ];
    }

    #[DataProvider('sectionCases')]
    public function testSkipsEachSectionCategoryBeforeCandidateCreation(
        string $category,
        string $heading,
        string $sectionText
    ): void {
        $message = self::message(
            "Parish: Example Parish\nOCTOBER 2026\n\n"
            . $heading . "\n" . $sectionText . "\n\n"
            . "UPCOMING EVENTS\nSaturday 10 October 2026 - Youth gathering at 16:00 at Fictional Parish Hall."
        );

        $outcome = (new PipelineFactory())->create()->parseAll($message);
        $candidates = $outcome->getCandidates();

        self::assertCount(1, $candidates);
        self::assertSame('Example Parish', $candidates[0]->getField('parish_name'));
        self::assertContains('skipped_sections: ' . $category . '=1', $outcome->getNotes());

        $skippedBlocks = array_values(array_filter(
            $outcome->getBlocks(),
            static fn (array $block): bool => ($block['reason'] ?? null) === $category
        ));
        self::assertCount(1, $skippedBlocks);
        self::assertSame('skipped', $skippedBlocks[0]['classification']);
        self::assertFalse($skippedBlocks[0]['candidate']);
        self::assertIsInt($skippedBlocks[0]['block_index']);

        $serializedOutcome = json_encode($outcome->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($heading, $serializedOutcome);
        self::assertStringNotContainsString($sectionText, $serializedOutcome);
    }

    public function testSkipsPunctuationInsensitiveLeadingPhrases(): void
    {
        $outcome = (new PipelineFactory())->create()->parseAll(self::message(
            "PLEASE, pray-for Fictional Person Alpha.\n"
            . "\nUPCOMING EVENTS\nSunday 11 October 2026 - Family picnic at 12:00."
        ));

        self::assertCount(1, $outcome->getCandidates());
        self::assertContains(
            'skipped_sections: sick_list=1',
            $outcome->getNotes()
        );
        self::assertContains(
            'sick_list',
            array_column($outcome->getBlocks(), 'reason')
        );
    }

    public function testReportsSkippedCountsForMultipleCategories(): void
    {
        $outcome = (new PipelineFactory())->create()->parseAll(self::message(<<<'TEXT'
SICK LIST
Fictional Person Alpha

MASS INTENTIONS
Fictional Person Beta

UPCOMING EVENTS
Saturday 10 October 2026 - Youth gathering at 16:00.
TEXT));

        self::assertContains(
            'skipped_sections: mass_intentions=1, sick_list=1',
            $outcome->getNotes()
        );
    }

    public function testSkipsWeeklyMassTimesTableAsOneSectionAndResumesAtNextHeading(): void
    {
        $outcome = (new PipelineFactory())->create()->parseAll(self::message(<<<'TEXT'
Parish: Example Parish

Day | Time | Mass/Service
Monday | 08:30 | Mass
Sunday | 09:00 | Service

UPCOMING EVENTS
Sunday 11 October 2026 - Family picnic at 12:00 at Fictional Community Gardens.
TEXT));

        self::assertCount(1, $outcome->getCandidates());
        self::assertSame('Family picnic', $outcome->getCandidates()[0]->getField('title'));
        self::assertContains('skipped_sections: mass_times=1', $outcome->getNotes());
        self::assertSame(
            1,
            count(array_filter(
                $outcome->getBlocks(),
                static fn (array $block): bool => ($block['reason'] ?? null) === 'mass_times'
            ))
        );
    }

    public function testCustomSanitizedKeywordListsReplaceDefaultsForTheirCategory(): void
    {
        $keywordLists = SectionSkipper::sanitizeKeywordLists([
            'sick_list' => " \n<strong>Care Circle</strong>\nCARE-CIRCLE\n ",
            'readings' => " \n ",
            'unknown_category' => 'This is ignored',
        ]);

        self::assertSame(['Care Circle'], $keywordLists['sick_list']);
        self::assertSame(
            SectionSkipper::defaultKeywordLists()['mass_times'],
            $keywordLists['mass_times']
        );
        self::assertSame(
            SectionSkipper::defaultKeywordLists()['readings'],
            $keywordLists['readings']
        );
        self::assertArrayNotHasKey('unknown_category', $keywordLists);

        $outcome = (new PipelineFactory())->create([
            'section_keywords' => $keywordLists,
        ])->parseAll(self::message(
            "CARE-CIRCLE\nFictional Person Alpha\n\n"
            . "UPCOMING EVENTS\nSaturday 10 October 2026 - Youth gathering at 16:00."
        ));

        self::assertCount(1, $outcome->getCandidates());
        self::assertContains(
            'skipped_sections: sick_list=1',
            $outcome->getNotes()
        );
    }

    public function testSkippedTextIsAbsentFromOutcomeAndAiProviderInput(): void
    {
        $sensitiveText = 'Fictional Person Alpha has a fictional health concern.';
        $provider = new CapturingAiProvider();
        $outcome = (new PipelineFactory())->create([
            'ai_enabled' => true,
            'ai_threshold' => 1.1,
            'ai_provider' => $provider,
        ])->parseAll(self::message(
            "SICK LIST\n" . $sensitiveText . "\n\n"
            . "UPCOMING EVENTS\nMonthly youth gathering at Fictional Parish Hall."
        ));

        self::assertCount(1, $outcome->getCandidates());
        self::assertNotSame('', $provider->capturedInput);
        self::assertStringNotContainsString($sensitiveText, $provider->capturedInput);
        self::assertStringNotContainsString(
            'Fictional Person Alpha',
            json_encode($outcome->toArray(), JSON_THROW_ON_ERROR)
        );
    }

    private static function message(string $body): Message
    {
        return new Message(
            'email',
            'section-skip-test',
            'events@example.test',
            'Example Parish Office',
            'October bulletin',
            $body,
            [],
            new DateTimeImmutable('2026-09-30T09:00:00+02:00')
        );
    }
}

final class CapturingAiProvider implements AiProviderInterface
{
    public string $capturedInput = '';

    public function name(): string
    {
        return 'test-capture';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function enrich(Message $message, ParseResult $result): array
    {
        $this->capturedInput = $message->fullText()
            . "\n\n"
            . json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        return [];
    }
}
