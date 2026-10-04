<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Pdf;

use ADCT\ParishIntake\Core\Pdf\PdfExtractionLimits;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use ADCT\ParishIntake\Core\Ports\StopwatchInterface;
use ADCT\ParishIntake\WordPress\Pdf\PrinsFrankPdfTextExtractor;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;

final class PrinsFrankPdfTextExtractorTest extends TestCase
{
private const PREFIXED_VENDOR_NAMESPACE = 'ADCT\ParishIntake\Dependencies\PrinsFrank';

    /**
     * A stand-in for the prefixing step: only classes under the release namespace
     * resolve, and only from the rewritten copy of the library.
     */
    private const PREFIXED_VENDOR_AUTOLOADER = <<<'PHP'
        <?php

        spl_autoload_register(static function (string $class): void {
            $prefix = 'ADCT\\ParishIntake\\Dependencies\\';

            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }

            $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_readable($path)) {
                require_once $path;
            }
        });
        PHP;

    /**
     * Loads the plugin source exactly as the site does and prints one line per field
     * so the calling test can assert on them.
     */
    private const RELEASE_SHAPED_PROBE = <<<'PHP'
        <?php

        declare(strict_types=1);

        require __DIR__ . '/vendor-prefixed/autoload.php';
        require __DIR__ . '/src/WordPress/Autoloader.php';

        \ADCT\ParishIntake\WordPress\Autoloader::register();

        $subject = $argv[1];

        // If the unprefixed library were still reachable, the case under test could
        // pass without the fix doing anything at all.
        if (class_exists('PrinsFrank\PdfParser\PdfParser')) {
            fwrite(STDERR, "The unprefixed parser is still reachable in this process.\n");
            exit(2);
        }

        if (! class_exists('ADCT\ParishIntake\Dependencies\PrinsFrank\PdfParser\PdfParser')) {
            fwrite(STDERR, "The prefixed parser is missing from the built package.\n");
            exit(3);
        }

        $result = (new \ADCT\ParishIntake\WordPress\Pdf\PrinsFrankPdfTextExtractor())->extract(
            $subject,
            \ADCT\ParishIntake\Core\Pdf\PdfExtractionLimits::defaults()
        );

        echo 'status=' . $result->status . "\n";
        echo 'reason=' . $result->reason . "\n";
        echo 'text=' . $result->text . "\n";
        PHP;

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
         * Issue #239. The release build rewrites vendor namespaces, but not the `use`
         * statements in our own source, so a hard import of a vendor class names a
         * class that no longer exists once the package is installed. The extractor
         * then failed on every PDF with a message about the file. This runs the
         * adapter in a process where the vendor library exists only under the
         * release prefix, which is the shape the installed site sees.
         */
        public function testExtractsTextWhenTheParserIsOnlyReachableUnderTheReleasePrefix(): void
        {
            $outcome = $this->runInAReleaseShapedProcess('single-column-poster.pdf');
    
            self::assertSame(0, $outcome['exit'], $outcome['stdout'] . $outcome['stderr']);
            self::assertSame(PdfExtractionResult::STATUS_EXTRACTED, $outcome['status'], $outcome['stdout'] . $outcome['stderr']);
            self::assertStringContainsString('Parish Retreat Day', $outcome['text']);
        }
    
        /**
         * The missing class also swallowed the difference between "this file is not
         * a PDF" and "the parser is not here". A genuinely broken file still has to
         * read as a parse failure, and that is only observable once the parser
         * resolves.
         */
        public function testReportsATruncatedPdfAsAParseFailureInAReleaseShapedPackage(): void
        {
            $outcome = $this->runInAReleaseShapedProcess(null, "%PDF-1.4\ntruncated before any object\n");
    
            self::assertSame(0, $outcome['exit'], $outcome['stdout'] . $outcome['stderr']);
            self::assertSame(PdfExtractionResult::STATUS_FAILED, $outcome['status'], $outcome['stdout'] . $outcome['stderr']);
            self::assertSame('The file could not be parsed as a PDF.', $outcome['reason']);
        }
    
        /**
         * Builds a throwaway package in which the only copy of the PDF library lives
         * under the release prefix, runs the extractor there in a clean process, and
         * reports what it saw.
         *
         * A subprocess is required: this one has already loaded the unprefixed library
         * through Composer, so the shape under test cannot be rebuilt inside it.
         *
         * @return array{exit: int, stdout: string, stderr: string, status: string, reason: string, text: string}
         */
        private function runInAReleaseShapedProcess(?string $fixture, string $literalPdf = ''): array
        {
            $package = tempnam(sys_get_temp_dir(), 'adct-release-pdf-');
    
            self::assertIsString($package);
            unlink($package);
            mkdir($package, 0777, true);
    
            try {
                $this->writePrefixOnlyParserLibrary($package);
                $this->copyTree(dirname(__DIR__, 4) . '/src', $package . '/src');
                $this->writeFile($package . '/probe.php', self::RELEASE_SHAPED_PROBE);
    
                if ($fixture !== null) {
                    $this->copyTree(dirname(__DIR__, 4) . '/tests/fixtures/pdfs', $package . '/fixtures');
                    $subject = $package . '/fixtures/' . $fixture;
                } else {
                    $subject = $package . '/subject.pdf';
                    $this->writeFile($subject, $literalPdf);
                }
    
                $process = proc_open(
                    [PHP_BINARY, $package . '/probe.php', $subject],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    $package
                );
    
                self::assertIsResource($process);
                $stdout = (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
    
                return $this->decodeProbeOutcome(proc_close($process), $stdout, $stderr);
            } finally {
                $this->removeTree($package);
            }
        }
    
        /**
         * @param int $exit
         * @return array{exit: int, stdout: string, stderr: string, status: string, reason: string, text: string}
         */
        private function decodeProbeOutcome(int $exit, string $stdout, string $stderr): array
        {
            $outcome = [
                'exit' => $exit,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'status' => '',
                'reason' => '',
                'text' => '',
            ];
    
            if (preg_match('/^status=(.*)$/m', $stdout, $status) === 1) {
                $outcome['status'] = $status[1];
            }
    
            if (preg_match('/^reason=(.*)$/m', $stdout, $reason) === 1) {
                $outcome['reason'] = $reason[1];
            }
    
            if (preg_match('/^text=(.*)$/ms', $stdout, $text) === 1) {
                $outcome['text'] = $text[1];
            }
    
            return $outcome;
        }
    
        /**
         * Copies the vendor PDF library into $package, rewriting every namespace the
         * way the release build does, so `PrinsFrank\PdfParser\PdfParser` exists
         * nowhere in the process. Rewriting the file bodies matters: prefixing only
         * the autoload map would hide the defect this test exists to catch.
         */
        private function writePrefixOnlyParserLibrary(string $package): void
        {
            $vendor = dirname(__DIR__, 4) . '/vendor/prinsfrank';
            $prefix = self::PREFIXED_VENDOR_NAMESPACE . '\\';
    
            // Each vendor package declares its own PSR-4 root, so each is copied under
            // the prefix with its own namespace segments intact.
            $libraries = [
                '/pdfparser/src' => '/PrinsFrank/PdfParser',
                '/glyph-lists/src' => '/PrinsFrank/GlyphLists',
                '/markdown-dom/src' => '/PrinsFrank/MarkDownDom',
            ];
            $moved = 0;
    
            foreach ($libraries as $suffix => $destination) {
                $library = $vendor . $suffix;
    
                self::assertDirectoryExists($library, 'The release prefixes every vendor package, not just the PDF parser.');
    
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($library, FilesystemIterator::SKIP_DOTS)
                );
    
                foreach ($files as $file) {
                    if (! $file->isFile() || $file->getExtension() !== 'php' || str_starts_with($file->getBasename(), '.')) {
                        continue;
                    }
    
                    $relative = substr($file->getPathname(), strlen($library));
                    $source = (string) file_get_contents($file->getPathname());
    
                    $this->writeFile(
                        $package . '/vendor-prefixed' . $destination . str_replace('\\', '/', $relative),
                        $this->prefixQualifiedNames($source, $prefix)
                    );
                    $moved++;
                }
            }
    
            self::assertGreaterThan(1, $moved, 'The vendor PDF library is missing, so the probe would prove nothing.');
    
            $this->writeFile($package . '/vendor-prefixed/autoload.php', self::PREFIXED_VENDOR_AUTOLOADER);
        }
    
        /**
         * Prefixes the namespace declarations and qualified name tokens of a source
         * file, leaving string literals and unqualified names alone for the same
         * reason the release build does.
         */
        private function prefixQualifiedNames(string $source, string $prefix): string
        {
            $rewritten = '';
            $tokens = token_get_all($source);
    
            foreach ($tokens as $token) {
                if (! is_array($token)) {
                    $rewritten .= $token;
                    continue;
                }
    
                if ($token[0] === T_NAME_QUALIFIED && str_starts_with($token[1], 'PrinsFrank\\')) {
                    $rewritten .= $prefix . substr($token[1], strlen('PrinsFrank\\'));
                    continue;
                }
    
                if ($token[0] === T_NAME_FULLY_QUALIFIED && $token[1] === '\\PrinsFrank\\') {
                    $rewritten .= '\\' . $prefix . substr($token[1], strlen('\\PrinsFrank\\'));
                    continue;
                }
    
                $rewritten .= $token[1];
            }
    
            return $rewritten;
        }
    
        private function copyTree(string $from, string $to): void
        {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)
            );
    
            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }
    
                $this->writeFile(
                    $to . str_replace('\\', '/', substr($file->getPathname(), strlen($from))),
                    (string) file_get_contents($file->getPathname())
                );
            }
        }
    
        private function writeFile(string $path, string $contents): void
        {
            $directory = dirname($path);
    
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
    
            file_put_contents($path, $contents);
        }
    
        private function removeTree(string $path): void
        {
            if (! is_dir($path)) {
                if (is_file($path)) {
                    unlink($path);
                }
    
                return;
            }
    
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
    
            foreach ($entries as $entry) {
                if ($entry->isDir()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
    
            rmdir($path);
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