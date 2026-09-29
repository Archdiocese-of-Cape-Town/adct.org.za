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

    /**
     * @return array<string, array{string, string}>
     */
    public static function eventBearingKeywordCases(): array
    {
        return [
            'collections and finances' => [
                'collections_finances',
                'Collection drive for the food bank on Saturday 10 October 2026 at 09:00 at Example Parish Hall.',
            ],
            'readings' => [
                'readings',
                'Scripture readings evening on Friday 9 October 2026 at 19:00 in the parish hall.',
            ],
            'sick list' => [
                'sick_list',
                'Please pray for vocations at the Holy Hour on Thursday 8 October 2026 at 19:00.',
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unaffectedEventCases(): array
    {
        return [
            'readers and ministers meeting' => [
                'Readers and ministers meeting on Saturday 10 October 2026 at 09:00 at Example Parish Hall.',
            ],
            'healing Mass and anointing of the sick' => [
                'Healing Mass with anointing of the sick on Saturday 10 October 2026 at 09:00 at Example Parish Hall.',
            ],
            'marriage anniversaries Mass' => [
                'Marriage anniversaries Mass on Sunday 11 October 2026 at 09:00 at Example Parish Hall.',
            ],
            'date-led Youth Mass' => [
                'Sat 10 Oct 2026 - Youth Mass at 18:00.',
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

    public function testRunningKeywordWithoutEventSignalSkipsOnlyItsBlock(): void
    {
        $outcome = (new PipelineFactory())->create()->parseAll(self::message(
            "Please pray for Fictional Person Alpha.\n"
            . "Parish: Fictional Person Alpha\n\n"
            . "Saturday 10 October 2026 - Family picnic at 12:00."
        ));

        self::assertCount(1, $outcome->getCandidates());
        self::assertSame('Family picnic', $outcome->getCandidates()[0]->getField('title'));
        self::assertContains('skipped_sections: sick_list=1', $outcome->getNotes());
        self::assertStringNotContainsString(
            'Fictional Person Alpha',
            json_encode($outcome->toArray(), JSON_THROW_ON_ERROR)
        );
    }

    #[DataProvider('eventBearingKeywordCases')]
    public function testKeepsEventBearingRunningKeywordAndLowersConfidence(
        string $category,
        string $eventText
    ): void {
        $message = self::message($eventText);
        $outcome = (new PipelineFactory())->create()->parseAll($message);

        self::assertCount(1, $outcome->getCandidates());
        $candidate = $outcome->getCandidates()[0];
        $overrideNote = 'section_keyword_overridden: ' . $category;

        self::assertSame('event', $candidate->getClassification());
        self::assertContains($overrideNote, $outcome->getNotes());
        self::assertContains($overrideNote, $candidate->getNotes());
        self::assertNotContains(
            'skipped_sections: ' . $category . '=1',
            $outcome->getNotes()
        );

        $keywordLists = SectionSkipper::defaultKeywordLists();
        $keywordLists[$category] = ['Keyword override disabled for this test'];
        $baseline = (new PipelineFactory())->create([
            'section_keywords' => $keywordLists,
        ])->parseAll($message);

        self::assertCount(1, $baseline->getCandidates());
        self::assertEqualsWithDelta(
            $baseline->getCandidates()[0]->getConfidence() - 0.1,
            $candidate->getConfidence(),
            0.000001
        );
    }

    #[DataProvider('unaffectedEventCases')]
    public function testPreviouslyAcceptedEventPhrasesRemainUnchanged(string $eventText): void
    {
        $outcome = (new PipelineFactory())->create()->parseAll(self::message($eventText));

        self::assertCount(1, $outcome->getCandidates());
        self::assertSame('event', $outcome->getCandidates()[0]->getClassification());
        self::assertSame([], array_values(array_filter(
            array_merge(
                $outcome->getNotes(),
                $outcome->getCandidates()[0]->getNotes()
            ),
            static fn (string $note): bool => strpos($note, 'section_keyword_overridden: ') === 0
        )));
    }

    public function testDateAndEventNounOverrideDoesNotRequireATime(): void
    {
        $message = self::message(
            'Collection drive for the food bank on Saturday 10 October 2026.'
        );
        $outcome = (new PipelineFactory())->create()->parseAll($message);

        self::assertCount(1, $outcome->getCandidates());
        self::assertContains(
            'section_keyword_overridden: collections_finances',
            $outcome->getNotes()
        );
        self::assertNull($outcome->getCandidates()[0]->getField('event_time'));

        $keywordLists = SectionSkipper::defaultKeywordLists();
        $keywordLists['collections_finances'] = ['Keyword override disabled for this test'];
        $baseline = (new PipelineFactory())->create([
            'section_keywords' => $keywordLists,
        ])->parseAll($message);

        self::assertCount(1, $baseline->getCandidates());
        self::assertEqualsWithDelta(
            $baseline->getCandidates()[0]->getConfidence() - 0.1,
            $outcome->getCandidates()[0]->getConfidence(),
            0.000001
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

    public function testHeadingMatchSkipsDatedContentAndKeepsTextOutOfAiInput(): void
    {
        $sensitiveText = 'Fictional Person Alpha';
        $provider = new CapturingAiProvider();
        $outcome = (new PipelineFactory())->create([
            'ai_enabled' => true,
            'ai_threshold' => 1.1,
            'ai_provider' => $provider,
        ])->parseAll(self::message(
            "PLEASE PRAY FOR:\n"
            . $sensitiveText . "\n"
            . "Saturday 10 October 2026 at 09:00 - prayer intentions for " . $sensitiveText . ".\n\n"
            . "UPCOMING EVENTS\nSunday 11 October 2026 - Youth picnic at 12:00."
        ));

        self::assertCount(1, $outcome->getCandidates());
        self::assertContains('skipped_sections: sick_list=1', $outcome->getNotes());
        self::assertNotSame('', $provider->capturedInput);
        self::assertStringNotContainsString(
            $sensitiveText,
            json_encode($outcome->toArray(), JSON_THROW_ON_ERROR)
        );
        self::assertStringNotContainsString($sensitiveText, $provider->capturedInput);
    }

    public function testFlagsPossibleUnheadedEventAfterSkippedSectionWithoutExposingText(): void
    {
        $provider = new CapturingAiProvider();
        $outcome = (new PipelineFactory())->create([
            'ai_enabled' => true,
            'ai_threshold' => 1.1,
            'ai_provider' => $provider,
        ])->parseAll(self::message(
            "MASS INTENTIONS\nFictional Person Alpha\n\n"
            . "Parish braai on Sunday 11 October 2026 at 12:00 at Fictional Hall."
        ));

        self::assertCount(0, $outcome->getCandidates());
        self::assertContains(
            'possible_missed_event_after_skipped_section: 1',
            $outcome->getNotes()
        );
        self::assertContains(
            'possible_missed_event_after_skipped_section: 1',
            $outcome->getPrimaryResult()->getNotes()
        );
        self::assertSame('mass_intentions', $outcome->getBlocks()[0]['reason']);
        self::assertStringNotContainsString(
            'Fictional Person Alpha',
            json_encode($outcome->toArray(), JSON_THROW_ON_ERROR)
        );
        self::assertStringNotContainsString(
            'Parish braai',
            json_encode($outcome->toArray(), JSON_THROW_ON_ERROR)
        );
        self::assertStringNotContainsString('Parish braai', $provider->capturedInput);
    }

    public function testDoesNotFlagPersonalIntentionAfterBlankLine(): void
    {
        $outcome = (new PipelineFactory())->create()->parseAll(self::message(
            "MASS INTENTIONS\nFictional Person Alpha\n\n"
            . "For the intentions of Fictional Person Beta at Mass on Sunday 11 October 2026 at 12:00."
        ));

        self::assertCount(0, $outcome->getCandidates());
        self::assertSame([], array_values(array_filter(
            $outcome->getNotes(),
            static fn (string $note): bool => str_starts_with(
                $note,
                'possible_missed_event_after_skipped_section: '
            )
        )));
        self::assertStringNotContainsString(
            'Fictional Person Beta',
            json_encode($outcome->toArray(), JSON_THROW_ON_ERROR)
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
