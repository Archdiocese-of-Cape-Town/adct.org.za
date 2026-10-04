<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Pdf;

use ADCT\ParishIntake\Core\Pdf\ColumnAwareTextAssembler;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionLimits;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use ADCT\ParishIntake\Core\Pdf\PositionedTextFragment;
use ADCT\ParishIntake\Core\Ports\PdfTextExtractorInterface;
use ADCT\ParishIntake\Core\Ports\StopwatchInterface;
use ADCT\ParishIntake\Core\Support\SystemStopwatch;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\PdfParser;
use Throwable;

/**
 * Reads the text layer out of a PDF with prinsfrank/pdfparser.
 *
 * This is the only class that knows the library exists; everything else works
 * against {@see PdfTextExtractorInterface}.
 *
 * The limits are checked in the cheapest order possible, so an oversized file
 * is rejected on its size without ever being opened, and an over-long file is
 * rejected on its page count before a single page is read for text. Nothing
 * here throws: a PDF is untrusted input, and a bad file must not be able to
 * fail the message that carried it.
 */
final class PrinsFrankPdfTextExtractor implements PdfTextExtractorInterface
{
    public function __construct(
        private readonly ColumnAwareTextAssembler $assembler = new ColumnAwareTextAssembler(),
        private readonly ?StopwatchInterface $stopwatch = null,
    ) {
    }

    public function extract(string $absolutePath, PdfExtractionLimits $limits): PdfExtractionResult
    {
        $size = $this->sizeOf($absolutePath);

        if ($size === null) {
            return PdfExtractionResult::failed('The attachment could not be read from storage.');
        }

        if ($size > $limits->maxBytes) {
            return PdfExtractionResult::skippedSize($size, $limits->maxBytes);
        }

        $stopwatch = $this->stopwatch ?? new SystemStopwatch();

        try {
            // `false` streams the file from disk instead of loading it whole,
            // which keeps peak memory inside the host's 256M limit.
            $document = (new PdfParser())->parseFile($absolutePath, false);
        } catch (Throwable $throwable) {
            return $this->failure($throwable);
        }

        if ($stopwatch->elapsedSeconds() > $limits->timeBudgetSeconds) {
            return PdfExtractionResult::skippedTimeout(0, $limits->timeBudgetSeconds);
        }

        try {
            $pages = $document->getPages();
        } catch (Throwable $throwable) {
            return $this->failure($throwable);
        }

        $pageCount = count($pages);

        if ($pageCount > $limits->maxPages) {
            return PdfExtractionResult::skippedPageLimit($pageCount, $limits->maxPages);
        }

        if ($stopwatch->elapsedSeconds() > $limits->timeBudgetSeconds) {
            return PdfExtractionResult::skippedTimeout($pageCount, $limits->timeBudgetSeconds);
        }

        $texts = [];

        try {
            foreach ($pages as $page) {
                $text = $this->pageText($page);

                if (trim($text) !== '') {
                    $texts[] = $text;
                }

                if ($stopwatch->elapsedSeconds() > $limits->timeBudgetSeconds) {
                    return PdfExtractionResult::skippedTimeout($pageCount, $limits->timeBudgetSeconds);
                }
            }
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $pageCount);
        }

        $text = trim(implode("\n\n", $texts));

        return $text === ''
            ? PdfExtractionResult::noTextLayer($pageCount)
            : PdfExtractionResult::extracted($text, $pageCount);
    }

    /**
     * The library parses lazily, so the expensive work is spread across
     * `parseFile`, `getPages` and each page. The stopwatch is read between every
     * stage, which is what makes the budget meaningful.
     */
    private function sizeOf(string $absolutePath): ?int
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        $size = @filesize($absolutePath);

        return $size === false ? null : $size;
    }

    /**
     * @param \PrinsFrank\PdfParser\Document\Object\Decorator\Page $page
     */
    private function pageText(object $page): string
    {
        $fragments = [];

        foreach ($page->getPositionedTextElements() as $element) {
            $matrix = $element->absoluteMatrix;
            $fragments[] = new PositionedTextFragment(
                $element->getText($page),
                $matrix->offsetX,
                $matrix->offsetY,
                $element->getHeight(),
                $this->widthOf($element, $page),
                $this->angleOf($matrix),
            );
        }

        $assembled = $this->assembler->assemble($fragments);

        if ($assembled !== '') {
            return $assembled;
        }

        // The positioned pass can come up empty on text the library itself can
        // still read, for instance a single text run the column split discarded.
        // Falling back beats losing a page silently.
        return trim($page->getText());
    }

    /**
     * How far the run advances along the page, which is what tells the assembler
     * where a row of cells ends and the gutter after it begins.
     *
     * The library scales this by the x component of the text matrix only, so a
     * run turned through ninety degrees reports a width of about zero. That is
     * not a width worth reporting, and the assembler reads turned runs along
     * their own baseline instead, so zero is passed through and let stand for
     * "unknown".
     *
     * @param \PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement $element
     * @param \PrinsFrank\PdfParser\Document\Object\Decorator\Page                         $page
     */
    private function widthOf(object $element, object $page): float
    {
        try {
            $font = $element->getFont($page);

            // A font carrying no width array cannot report a real advance. The
            // library then charges a whole em per character, which makes a short
            // run look several times wider than it is and swallows the gutter
            // after it. Those runs are left for the assembler to measure from
            // their own text length, which errs far more gently.
            if ($font->getWidths() === null) {
                return 0.0;
            }

            $width = $element->getAdvanceWidth($font);
        } catch (Throwable) {
            // A font the document references but does not contain, or one the
            // library cannot map to a width. The assembler falls back to an
            // estimate from the text length.
            return 0.0;
        }

        return is_finite($width) ? max(0.0, $width) : 0.0;
    }

    /**
     * The angle of the run's baseline, in degrees counter-clockwise.
     *
     * The `Tm` operator maps the text-space x-axis onto `(a, b)`, the first two
     * values of the matrix, so that pair is the direction the run reads in.
     * `atan2` already reports (-180, 180], which is the range the assembler
     * expects, so no normalisation is needed.
     *
     * @param \PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix $matrix
     */
    private function angleOf(object $matrix): float
    {
        return rad2deg(atan2($matrix->shearX, $matrix->scaleX));
    }

    private function failure(Throwable $throwable, int $pageCount = 0): PdfExtractionResult
    {
        $reason = $throwable instanceof PdfParserException
            ? 'The file could not be parsed as a PDF.'
            : 'The file could not be read as a PDF.';

        return PdfExtractionResult::failed($reason, $pageCount);
    }
}
