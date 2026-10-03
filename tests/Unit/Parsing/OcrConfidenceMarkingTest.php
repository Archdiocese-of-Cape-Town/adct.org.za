<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Ocr\OcrTextEnrichmentService;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Parsing\Stages\ConfidenceScoringStage;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Issue #75: OCR-filled fields are marked lower confidence.
 *
 * A value read out of a photograph is real evidence -- it is what the parish actually wrote --
 * but it is a weaker reading of it. A misread digit or a broken letter is invisible to every
 * other check in the model, because the characters were never compared against anything the
 * parish typed. Left uncharged, a date lifted from a blurry photo of a poster would score
 * exactly as well as the same date typed into the email, and would reach the events page
 * unattended.
 *
 * The charge is applied as a flag on the evidence rather than as a weaker origin, so a plainly
 * printed date is still `explicit` and simply scores lower. It is also inert until OCR sets it:
 * a flag is only ever charged when something sets one, so no existing candidate moves.
 */
final class OcrConfidenceMarkingTest extends TestCase
{
    /**
     * An event as a poster would carry it: a heading, a line with a date and a time, and a
     * venue. Used twice in the same message so the two copies differ only in provenance.
     */
    private const EVENT_LINES = "Parish Retreat Day\n"
        . "Saturday 14 November 2026 at 09:00\n"
        . "St John the Baptist Church, Observatory";

    /** The same event, with the OCR section appended exactly as the stage appends it. */
    private const OCR_BODY = self::EVENT_LINES . "\n\n"
        . OcrTextEnrichmentService::SECTION_HEADING . "\n\n"
        . "parish-poster.png\n"
        . self::EVENT_LINES;

    public function testTheOcrSectionProducesAMarkedCandidate(): void
    {
        // Guards the premise of every other test here. If the heading did not reach the
        // splitter intact, the marking would silently never apply and the rest would pass
        // for the wrong reason.
        self::assertNotEmpty(
            self::markedCandidates(self::parseAll(self::OCR_BODY)),
            'No candidate was marked as OCR-derived, so nothing would be scored down.'
        );
    }

    public function testFieldsReadFromAPosterAreMarkedAsOcr(): void
    {
        $candidate = self::markedCandidateWithDate(self::parseAll(self::OCR_BODY));
        $fields = $candidate->fieldConfidence()['fields'] ?? [];

        self::assertNotEmpty($fields, 'The OCR candidate published no per-field confidence.');

        $marked = array_filter(
            $fields,
            static fn (array $data): bool => in_array('ocr', self::flags($data), true)
        );

        self::assertNotEmpty($marked, 'No field on an OCR-derived candidate carried the ocr flag.');

        // A value the parser invented was never read off the poster, so it must not claim to
        // have been. It scores zero either way, but the stored record has to stay truthful.
        $contact = $fields['contact'] ?? null;

        self::assertIsArray($contact, 'No contact field was published for the OCR candidate.');
        self::assertSame(
            'unsupported',
            (string) ($contact['origin'] ?? ''),
            'The contact on an OCR candidate is not a fabricated sender fallback.'
        );
        self::assertNotContains(
            'ocr',
            self::flags($contact),
            'A fabricated contact was marked as having been read out of the poster.'
        );
    }

    public function testOcrKeepsTheOriginAndOnlyAddsTheFlag(): void
    {
        $ocr = self::eventDate(self::markedCandidateWithDate(self::parseAll(self::OCR_BODY)));
        $typed = self::eventDate(self::typedCandidateWithDate(self::parseAll(self::EVENT_LINES)));

        // The point of the flag: how the value was read is unchanged, only how much it is
        // trusted. Demoting the origin instead would lose that distinction for no benefit,
        // and would make an OCR date indistinguishable from a value the parser invented.
        self::assertSame(
            $typed['origin'],
            $ocr['origin'],
            'OCR changed how the date was read, rather than only how much it is trusted.'
        );

        self::assertNotContains('ocr', self::flags($typed));
        self::assertContains('ocr', self::flags($ocr));
    }

    public function testAnOcrDateScoresLowerThanTheSameDateTyped(): void
    {
        $ocr = self::eventDate(self::markedCandidateWithDate(self::parseAll(self::OCR_BODY)));
        $typed = self::eventDate(self::typedCandidateWithDate(self::parseAll(self::EVENT_LINES)));

        self::assertGreaterThan(
            $ocr['score'],
            $typed['score'],
            sprintf(
                'A date read out of a photograph scored %.2f, the same date typed into the email %.2f.',
                $ocr['score'],
                $typed['score']
            )
        );

        // The margin must be decisive, not a rounding artefact: wide enough that an OCR-only
        // event cannot clear the unattended-publish threshold however good the scan was.
        self::assertLessThanOrEqual(
            ConfidenceScoringStage::DEFAULT_FIELD_THRESHOLD,
            $ocr['score'],
            'A date read out of a photograph is still trusted enough to publish without a human.'
        );

        // It must not collapse to nothing, though. An imperfectly read poster still has to
        // produce a usable candidate for a human to correct, never a blank one.
        self::assertGreaterThan(
            0.2,
            $ocr['score'],
            'An OCR date was scored as worthless, discarding text a human needed in order to check it.'
        );
    }

    public function testAnOcrOnlyEventIsHeldForReview(): void
    {
        $marked = self::markedCandidates(self::parseAll(self::OCR_BODY));

        self::assertNotEmpty($marked);

        foreach ($marked as $candidate) {
            self::assertLessThan(
                ConfidenceScoringStage::DEFAULT_REVIEW_THRESHOLD,
                $candidate->getConfidence(),
                sprintf(
                    'An event read out of a poster scored %.2f, confident enough to publish unattended.',
                    $candidate->getConfidence()
                )
            );

            self::assertTrue(
                $candidate->needsReprocess(),
                'An event read out of a poster was not queued for review.'
            );
        }
    }

    public function testTheOperatorIsToldTheEventCameFromAPoster(): void
    {
        $candidate = self::markedCandidateWithDate(self::parseAll(self::OCR_BODY));
        $notices = implode("\n", $candidate->getNotes());

        self::assertStringContainsString(
            'OCR',
            $notices,
            'Nothing told the operator that this candidate was assembled from a photograph.'
        );
    }

    public function testTheTypedPartOfTheSameMessageIsUnaffected(): void
    {
        // The zero-regression guarantee. Both sets of candidates come out of one message, so
        // this proves the charge is scoped to the poster rather than applied message-wide.
        $candidates = self::parseAll(self::OCR_BODY)->getCandidates();

        self::assertGreaterThan(
            count(self::markedCandidates(self::parseAll(self::OCR_BODY))),
            count($candidates),
            'Every candidate was marked, so there is nothing un-marked to compare against.'
        );

        foreach ($candidates as $candidate) {
            if (in_array('ocr', self::candidateFlags($candidate), true)) {
                continue;
            }

            foreach ($candidate->fieldConfidence()['fields'] ?? [] as $name => $data) {
                self::assertNotContains(
                    'ocr',
                    self::flags($data),
                    sprintf(
                        'Field "%s" from the typed part of the message was marked as OCR.',
                        (string) $name
                    )
                );
            }
        }
    }

    public function testAMessageWithNoOcrSectionScoresExactlyAsBefore(): void
    {
        // The flag is inert until OCR sets it, so a message with no poster must score exactly
        // as it did before any of this existed.
        $typed = self::parseAll(self::EVENT_LINES)->getCandidates();

        self::assertNotEmpty($typed);
        self::assertNotContains(
            'ocr',
            self::candidateFlags($typed[0]),
            'A message with no poster had fields marked as OCR.'
        );

        $unmarked = array_values(array_filter(
            self::parseAll(self::OCR_BODY)->getCandidates(),
            static fn (ParseResult $candidate): bool => ! in_array('ocr', self::candidateFlags($candidate), true)
        ));

        self::assertNotEmpty($unmarked, 'No un-marked candidate survived in the message with a poster.');
        self::assertSame(
            round($typed[0]->getConfidence(), 3),
            round($unmarked[0]->getConfidence(), 3),
            'Appending an OCR section changed the score of the candidate that came from the email.'
        );
    }

    /**
     * Candidates carrying the ocr flag on at least one field.
     *
     * @return ParseResult[]
     */
    private static function markedCandidates(ParseOutcome $outcome): array
    {
        return array_values(array_filter(
            $outcome->getCandidates(),
            static fn (ParseResult $candidate): bool => in_array('ocr', self::candidateFlags($candidate), true)
        ));
    }

    /**
     * @return list<string>
     */
    private static function candidateFlags(ParseResult $candidate): array
    {
        $flags = [];

        foreach ($candidate->fieldConfidence()['fields'] ?? [] as $data) {
            $flags = [...$flags, ...self::flags(is_array($data) ? $data : [])];
        }

        return array_values(array_unique($flags));
    }

    /**
     * The OCR candidate that carries a date.
     *
     * The poster text splits into more than one block, and only the block holding the
     * date is the one that shows the charge. Picking it by content rather than by position
     * keeps the test honest if the splitter's boundaries shift.
     */
    private static function markedCandidateWithDate(ParseOutcome $outcome): ParseResult
    {
        return self::candidateWithDate(self::markedCandidates($outcome));
    }

    /**
     * The candidate carrying a date that did not come from a poster.
     */
    private static function typedCandidateWithDate(ParseOutcome $outcome): ParseResult
    {
        return self::candidateWithDate(array_values(array_filter(
            $outcome->getCandidates(),
            static fn (ParseResult $candidate): bool => ! in_array(
                'ocr',
                self::candidateFlags($candidate),
                true
            )
        )));
    }

    /**
     * @param ParseResult[] $candidates
     */
    private static function candidateWithDate(array $candidates): ParseResult
    {
        foreach ($candidates as $candidate) {
            if (isset($candidate->fieldConfidence()['fields']['event_date'])) {
                return $candidate;
            }
        }

        self::fail(sprintf(
            'No candidate carried an event_date. Candidates had: %s',
            implode(', ', array_map(
                static fn (ParseResult $candidate): string => implode('+', array_keys(
                    $candidate->fieldConfidence()['fields'] ?? []
                )),
                $candidates
            ))
        ));
    }

    /**
     * @return array{score: float, origin: string, flags: list<string>}
     */
    private static function eventDate(ParseResult $candidate): array
    {
        $data = $candidate->fieldConfidence()['fields']['event_date'] ?? null;

        self::assertIsArray($data, 'The selected candidate carries no event_date.');

        return [
            'score' => (float) ($data['score'] ?? 0.0),
            'origin' => (string) ($data['origin'] ?? ''),
            'flags' => self::flags($data),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function flags(array $data): array
    {
        return array_values(array_map('strval', (array) ($data['flags'] ?? [])));
    }

    private static function parseAll(string $body): ParseOutcome
    {
        $referenceDate = new DateTimeImmutable(
            '2026-09-25 09:00:00',
            new DateTimeZone('Africa/Johannesburg')
        );

        $message = new Message(
            'email',
            'ocr-confidence-test',
            'bulletin.office@example.org.za',
            'Archdiocesan Communications Office',
            'Parish Retreat Day',
            $body,
            [],
            $referenceDate
        );

        return (new PipelineFactory(new OcrConfidenceTestClock($referenceDate)))
            ->create()
            ->parseAll($message);
    }
}

final class OcrConfidenceTestClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}