<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Admin;

/**
 * The canonical addresses of the guidance documents in docs/, in one place.
 *
 * The guide is the answer to "where do I read about this?", so its URL is
 * quoted in a dozen admin screens. Duplicating the string meant a renamed file
 * or a moved branch silently broke every screen that quoted it, and nothing
 * failed a test. Keeping it here gives one place to change, and gives the
 * local suite somewhere to assert the literal value, so a rename fails in
 * seconds instead of on somebody's screen.
 *
 * These are anchors into guides, not routes: a stale anchor lands the reader at
 * the top of the right document rather than on a 404, which is why every anchor
 * below is also unit-tested as a literal rather than left to the renderer.
 */
final class AdminGuideLinks
{
    /**
     * How to use Parish Intake day to day. The full reference: every screen,
     * every setting, and what to do when something looks wrong.
     */
    public const OPERATOR_GUIDE
        = 'https://github.com/Archdiocese-of-Cape-Town/adct.org.za/blob/main/docs/operator-guide.md';

    /**
     * One page for the people who send events in, so a parish secretary does
     * not have to find the whole reference to answer "do I just email it?".
     */
    public const PARISH_GUIDE
        = 'https://github.com/Archdiocese-of-Cape-Town/adct.org.za/blob/main/docs/parish-submission-guide.md';

    /**
     * One page for deans and archdiocese reviewers, who approve from a link in
     * an email rather than from the admin screens.
     */
    public const APPROVER_GUIDE
        = 'https://github.com/Archdiocese-of-Cape-Town/adct.org.za/blob/main/docs/approver-guide.md';

    private function __construct()
    {
    }

    /**
     * @return array<string, string> guide key to absolute URL, for the surfaces
     *                          that offer all three
     */
    public static function all(): array
    {
        return [
            'operator' => self::OPERATOR_GUIDE,
            'parish' => self::PARISH_GUIDE,
            'approver' => self::APPROVER_GUIDE,
        ];
    }

    public static function operator(string $anchor = ''): string
    {
        return self::withAnchor(self::OPERATOR_GUIDE, $anchor);
    }

    public static function approver(string $anchor = ''): string
    {
        return self::withAnchor(self::APPROVER_GUIDE, $anchor);
    }

    /**
     * GitHub derives a heading's anchor from its text, so the anchor has to be
     * written exactly as the heading is, or the link lands at the top of the
     * document. A heading with a link inside it is anchored from the words only,
     * which is why the guides keep links in the sentence after a heading rather
     * than inside it.
     */
    private static function withAnchor(string $url, string $anchor): string
    {
        if ($anchor === '') {
            return $url;
        }

        return $url . '#' . ltrim($anchor, '#');
    }
}