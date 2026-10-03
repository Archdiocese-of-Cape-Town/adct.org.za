<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use InvalidArgumentException;

/**
 * The help attached to each Parish Intake admin screen, with no WordPress in it.
 *
 * `docs/operator-guide.md` is the right answer to "what does this screen do?"
 * for somebody who has been sent to the repository to read it, which is nobody
 * who works here. So the same answers are held here, keyed by each screen's own
 * WordPress screen id, and WordPressHelp turns them into the Help tab on that
 * screen.
 *
 * Writing them here rather than in each page class is deliberate. Help written
 * inline on a page ends up one file away from the screen, where nobody looks
 * when they need it, and every page grows its own rendering conventions. One
 * registry makes the whole help surface a single list, and a screen with no
 * entry shows up in {@see screenIds()} as a gap rather than as an omission
 * somebody has to notice.
 *
 * The text is short on purpose. A Help tab that needs scrolling is a Help tab
 * nobody reads: each entry is the sentence or two that let somebody decide what
 * to click, plus a link to the section of the guide that has the detail.
 */
final class AdminHelpRegistry
{
    /**
     * The admin screens the plugin registers, each with its own help.
     *
     * Keys are WordPress screen ids, not the page slugs the screens are
     * registered with. WordPress names a screen after the hook suffix, which
     * core builds as `{page_type}_page_{slug}` in get_plugin_page_hookname() —
     * so a submenu under the Parish Intake top-level menu is
     * `adct-parish-intake_page_adct-parish-intake-health`, and the top-level
     * screen itself is `toplevel_page_adct-parish-intake`. A registry keyed by
     * slug silently matches nothing, which is why these are spelled out as
     * literals and asserted against the real hooknames in
     * AdminHelpRegistryTest.
     *
     * `capability` is the capability the screen's own menu is registered with.
     * It is repeated here rather than read from the page class so the gate can
     * be checked without loading a WordPress page class; AdminHelpRegistryTest
     * asserts it still matches the registration, so the two cannot drift.
     *
     * `guides` names the extra documents worth offering on that screen: someone
     * approving an event from an email needs the approver guide, not the
     * operator guide, and the review queue is where they land first.
     *
     * @var array<string, array{capability: string, title: string, body: string, anchor: string, guides?: list<string>}>
     */
    private const SCREENS = [
        'adct-parish-intake_page_adct-parish-intake-review' => [
            'capability' => Capabilities::REVIEW,
            'title' => 'Reviewing events',
            'body' => 'Every event a parish emailed is a draft here until somebody decides about it.'
                . ' Open a row to check the date, time and place, correct anything the parser got'
                . ' wrong, then approve it. Approving puts it on the events page and in the calendar feed.',
            'anchor' => 'review-approve-and-correct',
            'guides' => ['approver', 'parish'],
        ],
        'adct-parish-intake_page_adct-parish-intake-parishes' => [
            'capability' => Capabilities::MANAGE_DIRECTORY,
            'title' => 'Parishes, venues and contacts',
            'body' => 'Each row is a parish. Add one by hand, or import the whole directory from a CSV.'
                . ' The Contacts tab on a parish lists the people allowed to send events in for it.',
            'anchor' => 'add-or-change-a-parish',
        ],
        'adct-parish-intake_page_adct-parish-intake-deaneries' => [
            'capability' => Capabilities::MANAGE_DIRECTORY,
            'title' => 'Deaneries and their approvers',
            'body' => 'A deanery needs at least one active approver. A deanery without one has nobody to'
                . ' approve its events, so archdiocese reviewers cover it until you assign somebody.',
            'anchor' => 'manage-deaneries-and-approvers',
        ],
        'adct-parish-intake_page_adct-parish-intake-senders' => [
            'capability' => Capabilities::MANAGE_DIRECTORY,
            'title' => 'Senders',
            'body' => 'Every email address that has sent an event in, and the parishes it is linked to.'
                . ' A new address is unverified until somebody checks it with the parish.',
            'anchor' => 'manage-parish-contacts-and-senders',
        ],
        'adct-parish-intake_page_adct-parish-intake-sources' => [
            'capability' => Capabilities::MANAGE_DIRECTORY,
            'title' => 'Sources',
            'body' => 'Where events come from: an email mailbox, an ICS file, a web page or a Facebook'
                . ' page. The health columns say when a source was last read and what went wrong.',
            'anchor' => 'manage-sources',
        ],
        'adct-parish-intake_page_adct-parish-intake-inbox' => [
            'capability' => Capabilities::REVIEW,
            'title' => 'Inbox',
            'body' => 'Every email received, and how far it got. A failed message can be reprocessed'
                . ' once you have fixed the reason shown.',
            'anchor' => 'review-the-inbox-and-reprocess-failures',
        ],
        'adct-parish-intake_page_adct-parish-intake-mailboxes' => [
            'capability' => Capabilities::MANAGE_SETTINGS,
            'title' => 'Mailboxes',
            'body' => 'The email accounts the plugin reads. Use Test connection after any change: it'
                . ' signs in, counts unread messages and checks the Processed folder exists, without'
                . ' downloading anything.',
            'anchor' => 'connect-the-events-mailbox-on-xneelo',
        ],
        'adct-parish-intake_page_adct-parish-intake-outbound-mail' => [
            'capability' => Capabilities::MANAGE_SETTINGS,
            'title' => 'Outbound email',
            'body' => 'Turn on Test mode before letting a test site send real email. Only the addresses'
                . ' and domains you list will receive anything; everything else is held back and listed'
                . ' here as suppressed.',
            'anchor' => 'outbound-email-and-hourly-cap',
        ],
        'adct-parish-intake_page_adct-parish-intake-jobs' => [
            'capability' => Capabilities::VIEW_REPORTS,
            'title' => 'Scheduled jobs',
            'body' => 'The background work: reading mailboxes, reading saved messages and sending queued'
                . ' email. Run now starts one immediately, with the same time limits and checkpoints cron'
                . ' uses.',
            'anchor' => 'keep-scheduled-jobs-running',
        ],
        'adct-parish-intake_page_adct-parish-intake-health' => [
            'capability' => Capabilities::VIEW_REPORTS,
            'title' => 'Health',
            'body' => 'Start here when something looks stuck. This page says when the background jobs'
                . ' last ran, what is waiting, and what needs attention.',
            'anchor' => 'check-intake-health',
        ],
        'adct-parish-intake_page_adct-parish-intake-retention' => [
            'capability' => Capabilities::MANAGE_SETTINGS,
            'title' => 'Data retention',
            'body' => 'How long the original emails, attachments and mailbox copies are kept. Everything'
                . ' is off by default, and switching cleanup on cannot be undone.',
            'anchor' => 'configure-retention-cleanup',
        ],
        'adct-parish-intake_page_adct-parish-intake-settings' => [
            'capability' => Capabilities::MANAGE_SETTINGS,
            'title' => 'Settings',
            'body' => 'Site-wide settings: the AI provider (off by default), how confident a parse has to'
                . ' be, which bulletin sections to skip, and data retention.',
            'anchor' => 'configure-parser-safeguards',
        ],
        'adct-parish-intake_page_adct-parish-intake-manual-parser' => [
            'capability' => Capabilities::REVIEW,
            'title' => 'Manual parser',
            'body' => 'Paste a notice to see how it would be read. This is a test only: it creates no'
                . ' event, sends no confirmation email and changes nothing.',
            'anchor' => 'configure-parser-safeguards',
        ],
        'toplevel_page_adct-parish-intake' => [
            'capability' => Capabilities::REVIEW,
            'title' => 'Parish Intake',
            'body' => 'Events emailed to the parish mailbox arrive as drafts. Somebody approves each one'
                . ' before it appears on the public events page.',
            'anchor' => 'your-daily-routine',
        ],
        // The same screen, registered as a top-level page: ReviewQueuePage adds
        // it there instead of under the menu for users who hold APPROVE_DEANERY
        // but not REVIEW, which gives the screen a different id. Both get the
        // help, so a dean who only ever sees the top-level entry is not left
        // without it.
        'toplevel_page_adct-parish-intake-review' => [
            'capability' => Capabilities::APPROVE_DEANERY,
            'title' => 'Reviewing events',
            'body' => 'Every event a parish emailed is a draft here until somebody decides about it.'
                . ' Open a row to check the date, time and place, correct anything the parser got'
                . ' wrong, then approve it. Approving puts it on the events page and in the calendar feed.',
            'anchor' => 'review-approve-and-correct',
            'guides' => ['approver', 'parish'],
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Every screen id the plugin registers, so a test can assert each one is
     * covered rather than leaving an uncovered screen to be discovered by
     * somebody who needed the help.
     *
     * @return list<string>
     */
    public static function screenIds(): array
    {
        return array_keys(self::SCREENS);
    }

    public static function has(string $screenId): bool
    {
        return isset(self::SCREENS[$screenId]);
    }

    /**
     * @return array{capability: string, title: string, body: string, anchor: string, guides: list<string>}
     */
    public static function forScreen(string $screenId): array
    {
        if (! isset(self::SCREENS[$screenId])) {
            throw new InvalidArgumentException('No help is registered for screen: ' . $screenId);
        }

        $screen = self::SCREENS[$screenId];

        return [
            'capability' => $screen['capability'],
            'title' => $screen['title'],
            'body' => $screen['body'],
            'anchor' => $screen['anchor'],
            'guides' => $screen['guides'] ?? [],
        ];
    }

    /**
     * Every document worth offering on a screen, as guide key to URL.
     *
     * The operator guide is this screen's own section, so it leads with that
     * screen's anchor already applied; the extra guides follow whole. Keeping
     * the pairing here means the renderer cannot attach a label to the wrong
     * guide, which is what happens if a renderer zips the key list against a
     * deduplicated URL list and the order changes.
     *
     * A guide key that is not in {@see AdminGuideLinks::all()} is a typo in a
     * screen, and is refused here rather than rendering a link to nowhere on
     * somebody's screen.
     *
     * @return array<string, string> guide key to absolute URL, in display order
     */
    public static function guideLinks(string $screenId): array
    {
        return self::guideLinksFor($screenId, self::forScreen($screenId)['guides']);
    }

    /**
     * {@see guideLinks()} with the extra guides supplied directly.
     *
     * Split out so the unknown-key guard is reachable from a test without
     * putting a bad key into the registry itself. Nothing else calls this.
     *
     * @param list<string> $extraGuideKeys
     * @return array<string, string>
     */
    public static function guideLinksFor(string $screenId, array $extraGuideKeys): array
    {
        $known = AdminGuideLinks::all();
        $links = ['operator' => AdminGuideLinks::operator(self::forScreen($screenId)['anchor'])];

        foreach ($extraGuideKeys as $key) {
            if (! isset($known[$key])) {
                throw new InvalidArgumentException('Unknown guide key: ' . $key);
            }
            $links[$key] = $known[$key];
        }

        return $links;
    }

    /**
     * The URL of the operator guide section this screen's help points at.
     */
    public static function guideUrl(string $screenId): string
    {
        return AdminGuideLinks::operator(self::forScreen($screenId)['anchor']);
    }

    /**
     * The label to show for a guide key, so the help names the document rather
     * than printing a bare URL at somebody who is trying to choose one.
     *
     * Plain English, not translated: src/Core calls no WordPress function, so
     * the adapter runs these through the translation and escaping helpers when
     * it renders them.
     *
     * @return array<string, string>
     */
    public static function guideLabels(): array
    {
        return [
            'operator' => 'Operator guide (this screen in detail)',
            'approver' => 'Approver guide (approving from an email)',
            'parish' => 'Parish guide (how to send events in)',
        ];
    }
}