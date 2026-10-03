<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Admin;

use ADCT\ParishIntake\Core\Admin\AdminGuideLinks;
use PHPUnit\Framework\TestCase;

/**
 * The guide URLs, as literals, and the file each one names.
 *
 * The admin screens and the help tabs quote these strings, and nothing else
 * would notice a change: a renamed guide file leaves a 404, and a stale anchor
 * leaves a link that quietly lands at the top of the right document, which reads
 * like the link is working. Both are only visible to whoever is standing in
 * front of the screen, so they are checked here instead.
 */
final class AdminGuideLinksTest extends TestCase
{
    public function testGuideUrlsAreTheLiteralAddressesOfTheDocumentsInDocs(): void
    {
        // Asserted as literals on purpose: this is the cross-file contract
        // between the help tab and the file it sends somebody to.
        self::assertSame(
            'https://github.com/Archdiocese-of-Cape-Town/adct.org.za/blob/main/docs/operator-guide.md',
            AdminGuideLinks::OPERATOR_GUIDE
        );
        self::assertSame(
            'https://github.com/Archdiocese-of-Cape-Town/adct.org.za/blob/main/docs/parish-submission-guide.md',
            AdminGuideLinks::PARISH_GUIDE
        );
        self::assertSame(
            'https://github.com/Archdiocese-of-Cape-Town/adct.org.za/blob/main/docs/approver-guide.md',
            AdminGuideLinks::APPROVER_GUIDE
        );
    }

    public function testEveryGuideUrlNamesADocumentThatExistsInThisRepository(): void
    {
        foreach (AdminGuideLinks::all() as $key => $url) {
            $relative = self::relativePathFor($url);
            self::assertFileExists(
                dirname(__DIR__, 4) . '/' . $relative,
                $key . ' links to ' . $relative . ', which does not exist.'
            );
        }
    }

    public function testAllPairsEveryGuideKeyWithItsUrl(): void
    {
        self::assertSame(
            [
                'operator' => AdminGuideLinks::OPERATOR_GUIDE,
                'parish' => AdminGuideLinks::PARISH_GUIDE,
                'approver' => AdminGuideLinks::APPROVER_GUIDE,
            ],
            AdminGuideLinks::all()
        );
    }

    public function testAnAnchorIsAppendedToTheGuide(): void
    {
        self::assertSame(
            AdminGuideLinks::OPERATOR_GUIDE . '#your-daily-routine',
            AdminGuideLinks::operator('your-daily-routine')
        );
        self::assertSame(
            AdminGuideLinks::APPROVER_GUIDE . '#deciding-on-an-event',
            AdminGuideLinks::approver('deciding-on-an-event')
        );
    }

    /**
     * A leading # is accepted as well as omitted, because an anchor read out of
     * a Markdown link already carries one and callers should not have to strip
     * it.
     */
    public function testALeadingHashIsNotDoubled(): void
    {
        self::assertSame(
            AdminGuideLinks::OPERATOR_GUIDE . '#check-intake-health',
            AdminGuideLinks::operator('#check-intake-health')
        );
    }

    public function testNoAnchorReturnsTheGuideItself(): void
    {
        self::assertSame(AdminGuideLinks::OPERATOR_GUIDE, AdminGuideLinks::operator());
        self::assertSame(AdminGuideLinks::APPROVER_GUIDE, AdminGuideLinks::approver(''));
    }

    /**
     * The reason these are centralised: the second copy is the one that goes
     * stale, because nothing makes the two copies agree.
     */
    public function testNoOtherSourceFileQuotesAGuideUrl(): void
    {
        $offenders = [];

        foreach (self::phpFilesIn('src') as $file) {
            if (str_ends_with($file, 'Core/Admin/AdminGuideLinks.php')) {
                continue;
            }

            $contents = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
            if (is_string($contents) && str_contains($contents, 'blob/main/docs/')) {
                $offenders[] = $file;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Quote AdminGuideLinks instead of repeating a guide URL: ' . implode(', ', $offenders)
        );
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $relativeDirectory): array
    {
        $base = dirname(__DIR__, 4);
        $found = [];

        foreach (glob($base . '/' . $relativeDirectory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                $found[] = substr($file, strlen($base) + 1);
            }
        }

        return $found;
    }

    private static function relativePathFor(string $url): string
    {
        $marker = '/blob/main/';
        $position = strpos($url, $marker);

        self::assertNotFalse($position, $url . ' is not a blob URL into this repository.');

        return substr($url, $position + strlen($marker));
    }
}