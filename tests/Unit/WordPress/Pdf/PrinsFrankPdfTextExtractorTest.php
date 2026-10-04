<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Pdf;

use ADCT\ParishIntake\Core\Pdf\PdfExtractionLimits;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use ADCT\ParishIntake\Core\Ports\StopwatchInterface;
use ADCT\ParishIntake\WordPress\Pdf\PrinsFrankPdfTextExtractor;
use PHPUnit\Framework\TestCase;

final class PrinsFrankPdfTextExtractorTest extends TestCase
{
    public function testExtractsTextFromASingleColumnPoster(): void
    {
        $result = $this->extractor()->extract(
            $this->fixture('single-column-poster.pdf'),
            PdfExtractionLimits::defaults()
        );

        self::assertSame(PdfExtractionResult::STATUS_EXTRACTED, $result->status);
        self::assertSame(PdfExtractionResult::METHOD_PDF_TEXT, $result->method);
        self::assertSame(1, $result->pageCount);
        self::assertFalse($result->needsManualAttention());
        self::assertStringContainsString('Parish Retreat Day', $result->text);
        self::assertStringContainsString('Saturday 17 October 2026, from 9am to 3pm', $result->text);
    }

    /**
     * The regression this whole layer exists for. The content stream emits the
     * columns interleaved, so a naive linear read pairs the left column's date
     * with the right column's time.
     */
    public function testReadsTwoColumnsInReadingOrderRatherThanInterleaved(): void
    {
        $text = $this->extractor()->extract(
            $this->fixture('two-column-bulletin.pdf'),
            PdfExtractionLimits::defaults()
        )->text;

        $left = strpos($text, 'Sunday 8 November 2026');
        $right = strpos($text, 'Choir Rehearsal');

        self::assertIsInt($left);
        self::assertIsInt($right);
        self::assertLessThan($right, $left, 'The left column must be read before the right column starts.');

        self::assertMatchesRegularExpression(
            '/Sunday 8 November 2026\s+at 10am/u',
            $text,
            'A date and a time from the same notice must stay together.'
        );

        self::assertMatchesRegularExpression('/Thursday 12 November 2026\s+at 6pm/u', $text);
    }

    public function testKeepsTheTwoColumnsAsSeparateBlocks(): void
    {
        $text = $this->extractor()->extract(
            $this->fixture('two-column-bulletin.pdf'),
            PdfExtractionLimits::defaults()
        )->text;

        self::assertStringContainsString("Please bring your books.\n\nParish Notice", $text);
    }

    public function testReportsAnImageOnlyPdfAsHavingNoTextLayer(): void
    {
        $result = $this->extractor()->extract(
            $this->fixture('image-only.pdf'),
            PdfExtractionLimits::defaults()
        );

        self::assertSame(PdfExtractionResult::STATUS_NO_TEXT_LAYER, $result->status);
        self::assertSame('', $result->text);
        self::assertTrue($result->needsManualAttention());
        self::assertSame('pdf_no_text_layer', $result->noticeKey());
    }

    public function testSkipsAPdfWithMorePagesThanTheLimit(): void
    {
        $result = $this->extractor()->extract(
            $this->fixture('over-page-limit.pdf'),
            PdfExtractionLimits::defaults()
        );

        self::assertSame(PdfExtractionResult::STATUS_SKIPPED_PAGE_LIMIT, $result->status);
        self::assertSame('', $result->text);
        self::assertSame(11, $result->pageCount);
        self::assertSame('pdf_skipped_page_limit', $result->noticeKey());
    }

    public function testExtractsTheSameFileOnceThePageLimitIsRaised(): void
    {
        $result = (new PrinsFrankPdfTextExtractor(stopwatch: new FixedStopwatch(0.0)))->extract(
            $this->fixture('over-page-limit.pdf'),
            new PdfExtractionLimits(maxPages: 50)
        );

        self::assertSame(PdfExtractionResult::STATUS_EXTRACTED, $result->status);
        self::assertSame(11, $result->pageCount);
    }

    public function testSkipsAFileAboveTheSizeLimitWithoutOpeningIt(): void
    {
        $result = (new PrinsFrankPdfTextExtractor())->extract(
            $this->fixture('single-column-poster.pdf'),
            new PdfExtractionLimits(maxBytes: 10)
        );

        self::assertSame(PdfExtractionResult::STATUS_SKIPPED_SIZE, $result->status);
        self::assertSame('pdf_skipped_size', $result->noticeKey());
        self::assertStringContainsString('906 bytes', (string) $result->reason);
    }

    public function testReportsAMissingFileAsFailed(): void
    {
        $result = (new PrinsFrankPdfTextExtractor())->extract(
            $this->fixture('does-not-exist.pdf'),
            PdfExtractionLimits::defaults()
        );

        self::assertSame(PdfExtractionResult::STATUS_FAILED, $result->status);
        self::assertSame('pdf_extraction_failed', $result->noticeKey());
    }

    public function testReportsATruncatedPdfAsFailedRatherThanThrowing(): void
    {
        $path = $this->temporaryFile("%PDF-1.4\ntruncated before any object");

        try {
            $result = (new PrinsFrankPdfTextExtractor())->extract($path, PdfExtractionLimits::defaults());

            self::assertSame(PdfExtractionResult::STATUS_FAILED, $result->status);
        } finally {
            @unlink($path);
        }
    }

    public function testReportsSomethingThatIsNotAPdfAtAllAsFailed(): void
    {
        $path = $this->temporaryFile("this is plain text pretending to be a pdf\n");

        try {
            $result = (new PrinsFrankPdfTextExtractor())->extract($path, PdfExtractionLimits::defaults());

            self::assertSame(PdfExtractionResult::STATUS_FAILED, $result->status);
        } finally {
            @unlink($path);
        }
    }

    public function testGivesUpWhenTheTimeBudgetRunsOut(): void
    {
        $result = (new PrinsFrankPdfTextExtractor(stopwatch: new FixedStopwatch(30.0)))->extract(
            $this->fixture('single-column-poster.pdf'),
            new PdfExtractionLimits(timeBudgetSeconds: 10)
        );

        self::assertSame(PdfExtractionResult::STATUS_SKIPPED_TIMEOUT, $result->status);
        self::assertSame('', $result->text);
        self::assertSame('pdf_skipped_timeout', $result->noticeKey());
    }

    public function testStaysWithinItsBudgetForAFixtureThatWouldOtherwiseTimeOut(): void
    {
        // The library parses lazily, so a budget checked only around the page
        // loop would let a slow `getPages` run to completion unchecked.
        $result = (new PrinsFrankPdfTextExtractor(stopwatch: new FixedStopwatch(2.0)))->extract(
            $this->fixture('two-column-bulletin.pdf'),
            new PdfExtractionLimits(timeBudgetSeconds: 10)
        );

        self::assertSame(PdfExtractionResult::STATUS_EXTRACTED, $result->status);
    }

    /**
         * A bulletin that starts full width and then splits into two columns. The
         * heading and the centred line above the columns belong to neither column,
         * and the two notices below have to read as two whole notices.
         */
        public function testDetectsTheColumnSwitchPartWayDownThePage(): void
        {
            $text = $this->extractor()->extract(
                $this->fixture('column-switch-bulletin.pdf'),
                PdfExtractionLimits::defaults()
            )->text;

            self::assertStringContainsString(
                "Parish Newsletter for November 2026\n"
                . 'The annual Mass intention list is on the table.',
                $text,
                'The full width lines above the columns must stay whole and stay first.'
            );

            self::assertMatchesRegularExpression(
                '/Confirmation Day\s+at 10am in the hall\./u',
                $text,
                'The left notice must read as one block.'
            );

            self::assertMatchesRegularExpression(
                '/Sunday 8 November 2026\s+at 6pm in the church\./u',
                $text,
                'The right notice must read as one block, not interleaved with the left.'
            );
        }

        /**
         * The Mass times table crosses the gutter between the two columns. Splitting
         * it at the gutter pairs each day with the wrong time, so each row has to be
         * read whole, left to right.
         */
        public function testReadsATableThatCrossesTheColumnGutterAsWholeRows(): void
        {
            $text = $this->extractor()->extract(
                $this->fixture('cross-column-table.pdf'),
                PdfExtractionLimits::defaults()
            )->text;

            self::assertStringContainsString(
                'Monday 5:45am Wednesday 9:00am',
                $text,
                'A row spanning both columns must not be split at the gutter.'
            );

            self::assertStringContainsString('Tuesday 6:30am Thursday 6:00pm', $text);
            self::assertMatchesRegularExpression('/5:45am.*9:00am/su', $text, 'Times must stay on their own row.');
        }

        /**
         * A vertical caption is one sentence, not one line per word. Its runs share a
         * single x position and step through y, so a naive x axis read both shreds
         * the caption and reverses it.
         */
        public function testReadsAVerticalCaptionAsOneLineInItsOwnOrder(): void
        {
            $text = $this->extractor()->extract(
                $this->fixture('rotated-caption.pdf'),
                PdfExtractionLimits::defaults()
            )->text;

            self::assertStringContainsString('Retreat programme', $text);
            self::assertStringNotContainsString('programme Retreat', $text);
            self::assertStringContainsString('Confirmation Day', $text);
        }

        /**
         * The sidebar's list is indented under its heading. That indent is a nested
         * list, not a column, so the sidebar must read as one block with the heading
         * at the top.
         */
        public function testReadsAnIndentedSidebarListAsOneBlockRatherThanAColumn(): void
        {
            $text = $this->extractor()->extract(
                $this->fixture('boxed-sidebar.pdf'),
                PdfExtractionLimits::defaults()
            )->text;

            self::assertStringContainsString(
                "Prayer group\n* Youth group\n* Marriage preparation",
                $text,
                'The indented list must stay under its own heading.'
            );
        }

        /**
             * The extractor checks its time budget against a stopwatch, so building it
             * without one makes the result depend on how loaded the machine is. These
             * fixtures are small and the assertions are about content and reading
             * order, never about timing, so pin the clock and keep the outcome stable.
             * The timeout path is covered separately, with an explicit budget.
             */
            private function extractor(): PrinsFrankPdfTextExtractor
            {
                return new PrinsFrankPdfTextExtractor(stopwatch: new FixedStopwatch(0.0));
            }

            private function fixture(string $name): string
            {
                return dirname(__DIR__, 3) . '/fixtures/pdfs/' . $name;
            }

        private function temporaryFile(string $contents): string
        {
            $path = tempnam(sys_get_temp_dir(), 'adct-pdf-');

            self::assertIsString($path);
            file_put_contents($path, $contents);

            return $path;
        }
    }

/**
 * Reports the same elapsed time on every reading, so a timeout can be tested
 * without actually waiting for one.
 */
final readonly class FixedStopwatch implements StopwatchInterface
{
    public function __construct(private float $seconds)
    {
    }

    public function elapsedSeconds(): float
    {
        return $this->seconds;
    }
}
