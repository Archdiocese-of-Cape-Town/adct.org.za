<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Issue #130: "Confidence scoring rewards fabricated fields".
 *
 * The scoring stage used to add a fixed bonus for every populated field regardless of where
 * the value came from. A block whose title came from a newsletter masthead, whose "parish"
 * was a mid-sentence fragment, and whose time was a scripture reference therefore out-scored
 * a block that carried a real title and a real date.
 */
final class ConfidenceScoringStageTest extends TestCase
{
    /**
     * A newsletter-shaped block in which nothing is evidence for an event. The subject is the
     * masthead, "12:45" is a scripture citation, and the only "… Parish" text sits inside a
     * sentence. Nothing here supports an event.
     */
    private const FABRICATED_BODY = "The season of Ordinary Time opens this year. I remind all Parish Pastoral\n"
        . "Councils that the annual meeting will be held in the Cathedral. Scripture for today is 12:45.";

    private const REAL_BODY = 'The Parish Retreat Day takes place on 14 Nov 2026. Please join us.';

    public function testFabricatedBlockScoresMateriallyLowerThanRealBlock(): void
    {
        $fabricated = self::parse(self::FABRICATED_BODY, 'Diocese of Sample - Weekly Bulletin October 2026');
        $real = self::parse(self::REAL_BODY, 'Parish Retreat Day');

        self::assertLessThan(
            $real['confidence'],
            $fabricated['confidence'],
            sprintf(
                'A block with no supporting evidence scored %.2f, above a block with a real title and date at %.2f.',
                $fabricated['confidence'],
                $real['confidence']
            )
        );

        // The ordering must be decisive, not a rounding artefact.
        self::assertLessThan(
            $real['confidence'] - 0.2,
            $fabricated['confidence'],
            'The margin between a fabricated block and a real one is too small to route the fabricated one to review.'
        );
    }

    public function testFabricatedFieldsAreFlaggedAsUnsupported(): void
    {
        $result = self::parse(self::FABRICATED_BODY, 'Diocese of Sample - Weekly Bulletin October 2026');
        $origins = self::origins($result);

        // The title is a body sentence the parser cut off at a line break, not a heading anyone
        // wrote. It is a fabrication, and it is the heaviest-weighted field in the model.
        self::assertArrayHasKey('title', $origins, 'A title was extracted from the bulletin prose.');
        self::assertSame('unsupported', $origins['title']);

        // "Parish Pastoral Councils" is a fragment of a sentence, not a parish name.
        self::assertArrayHasKey('parish_name', $origins, 'A parish name was read out of running prose.');
        self::assertSame('unsupported', $origins['parish_name']);

        // The sender address is always present, so the contact fallback must never look reliable.
        self::assertArrayHasKey('contact', $origins);
        self::assertSame('unsupported', $origins['contact']);
    }

    public function testAddingASenderFallbackContactDoesNotIncreaseConfidence(): void
    {
            // The sender address is always present, so a candidate always has a contact field. Its
            // value is never reliable, so it must contribute no confidence rather than a bonus.
            $bare = self::parse('Some notes from the parish office this week.');
            $withSenderFallback = self::parse('Some notes from the parish office this week.');

            $contact = self::scoreFor($bare, 'contact');

            self::assertNotNull($contact);
            self::assertSame('unsupported', $contact['origin']);
            self::assertLessThanOrEqual(
                $bare['confidence'],
                $withSenderFallback['confidence'],
                'A sender-fallback contact raised the overall confidence.'
            );
        }

    public function testAddingAGenuineBodyContactDoesNotLowerConfidence(): void
    {
        $before = self::parse('The Parish Retreat Day takes place on 14 Nov 2026. Please join us.');
        $after = self::parse(
           'The Parish Retreat Day takes place on 14 Nov 2026. Please join us. Contact 021 555 0000.'
        );

        $contact = self::scoreFor($after, 'contact');

        self::assertNotNull($contact, 'A phone number in the body should have been extracted.');
        self::assertSame(
           'explicit',
           $contact['origin'],
           'A contact read from the body is real evidence, not a fabrication.'
        );
        self::assertGreaterThan(
           0.8,
           $contact['score'],
           'A contact read from the body is strong evidence.'
        );
        self::assertGreaterThanOrEqual(
           $before['confidence'],
           $after['confidence'],
           'Adding a genuine field should never lower the overall confidence.'
        );
    }

    public function testFabricatedFieldScoresZeroAndIsCountedAgainstTheCandidate(): void
    {
        $fabricated = self::parse(self::FABRICATED_BODY, 'Diocese of Sample - Weekly Bulletin October 2026');

        $date = self::scoreFor($fabricated, 'event_date');

        // Nothing in the block states a date, so there is no date evidence at all.
        self::assertNull($date, 'A date was fabricated out of a bulletin masthead month.');

        $title = self::scoreFor($fabricated, 'title');

        self::assertNotNull($title, 'A title was cut out of the bulletin prose.');
        self::assertSame(
            0.0,
            $title['score'],
            'A fabricated field must score zero, not merely score low.'
        );
        self::assertSame('unsupported', $title['origin']);
        self::assertContains('sentence_fragment', $title['flags']);

        // An absent field costs nothing; a fabricated one must cost something.
        $unsupported = array_keys(
            array_filter(
                $fabricated['fields']['field_confidence']['fields'] ?? [],
                static fn (array $data): bool => ($data['origin'] ?? '') === 'unsupported'
            )
        );

        self::assertNotEmpty($unsupported, 'The fabricated block should contain unsupported fields.');
        self::assertLessThan(0.2, $fabricated['confidence']);
    }

    public function testMissingDateIsNeutralButUnsupportedFieldCarriesAPenalty(): void
    {
        $bare = self::parse('Some notes from the parish office this week.');
        $fabricated = self::parse(
            'Some notes from the parish office this week. '
            . 'I remind all Parish Pastoral Councils to attend.'
        );

        // The bare block invents no title from prose; the fabricated one does.
        self::assertSame(
            'unsupported',
            self::origins($fabricated)['parish_name'] ?? null,
            'A mid-sentence "Parish" fragment should be unsupported evidence, not a parish name.'
        );
        self::assertArrayNotHasKey(
            'parish_name',
            self::origins($bare),
            'A block with no parish text should have no parish evidence at all.'
        );

        // The fabricated block invents more, so it must not score higher than the bare one.
        self::assertLessThan(
            $bare['confidence'] + 0.0001,
            $fabricated['confidence'],
            'Inventing fields raised the overall confidence.'
        );
    }

    public function testSenderAddressContactFallbackScoresZero(): void
    {
        $result = self::parse('Some notes from the parish office this week.');
        $contact = self::scoreFor($result, 'contact');

        self::assertNotNull($contact);
        self::assertSame('unsupported', $contact['origin']);
        self::assertSame(0.0, $contact['score']);
    }

    public function testExtractedFieldsCarryAnOriginAndPerFieldConfidenceIsPublished(): void
    {
        $result = self::parse(self::REAL_BODY, 'Parish Retreat Day');
        $confidence = $result['fields']['field_confidence'] ?? null;

        self::assertIsArray($confidence, 'Per-field confidence is not published in the fields payload.');
        self::assertArrayHasKey('fields', $confidence);
        self::assertArrayHasKey('title', $confidence['fields']);
        self::assertArrayHasKey('score', $confidence['fields']['title']);
        self::assertArrayHasKey('origin', $confidence['fields']['title']);
        self::assertArrayHasKey('coverage', $confidence);
        self::assertGreaterThan(0.0, $confidence['fields']['title']['score']);
    }

    public function testLowConfidenceCandidateIsFlaggedForReprocessing(): void
    {
        $fabricated = self::parse(self::FABRICATED_BODY, 'Diocese of Sample - Weekly Bulletin October 2026');

        self::assertTrue(
            $fabricated['reprocess_needed'],
            'A wholly fabricated block was not queued for reprocessing or review.'
        );
    }

    public function testBareScriptureCitationIsNotReadAsAStartTime(): void
        {
            $result = self::parse('Scripture for today is 12:45.');

            self::assertNull(
                $result['fields']['event_time'] ?? null,
                'A scripture citation should not be published as an event start time.'
            );
        }

        /**
         * @param array<string, mixed> $result
         *
         * @return array<string, string>
         */
        private static function origins(array $result): array
    {
        $out = [];

        foreach ($result['fields']['field_confidence']['fields'] ?? [] as $name => $data) {
            $out[(string) $name] = (string) ($data['origin'] ?? '');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array{score: float, origin: string, flags: array<int, string>}|null
     */
    private static function scoreFor(array $result, string $field): ?array
    {
        $data = $result['fields']['field_confidence']['fields'][$field] ?? null;

        if (! is_array($data)) {
            return null;
        }

        return [
            'score' => (float) ($data['score'] ?? 0.0),
            'origin' => (string) ($data['origin'] ?? ''),
            'flags' => array_values((array) ($data['flags'] ?? [])),
        ];
    }

    private static function parse(string $body, string $subject = 'Example event'): array
    {
        $referenceDate = new DateTimeImmutable(
            '2026-09-25 09:00:00',
            new DateTimeZone('Africa/Johannesburg')
        );

        $message = new Message(
            'email',
            'confidence-test',
            'bulletin.office@example.org.za',
            'Archdiocesan Communications Office',
            $subject,
            $body,
            [],
            $referenceDate
        );

        return (new PipelineFactory(new ConfidenceTestClock($referenceDate)))
            ->create()
            ->parse($message)
            ->toArray();
    }
}

final class ConfidenceTestClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}