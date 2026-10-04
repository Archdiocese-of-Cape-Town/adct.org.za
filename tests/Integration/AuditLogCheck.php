<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditQuery;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Ingestion\MailboxMoveReceipt;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\MailboxSettingsStoreInterface;
use ADCT\ParishIntake\Core\Ports\ProcessedMailboxMessageStoreInterface;
use ADCT\ParishIntake\WordPress\Admin\AuditLogPage;
use ADCT\ParishIntake\WordPress\Admin\SubjectAuditPanel;
use ADCT\ParishIntake\WordPress\Audit\AuditLogRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Jobs\RetentionCleanupJob;
use ADCT\ParishIntake\WordPress\Jobs\RetentionSettings;
use ADCT\ParishIntake\WordPress\Plugin;

/**
 * The audit log screen, against the real table.
 *
 * The unit tests prove the screen renders and that the repository builds the
 * right SQL. Only this can prove the two fit together: that a row written the
 * way a write site writes one is visible to the screen, that the filters find
 * it, and that the window cannot be stretched past the retention period. The
 * last checks close the two things a unit test cannot: that a user without the
 * reports capability is refused, and that the page offers no way to change a
 * row.
 */
final class AuditLogCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;

        $audit = $wpdb->prefix . 'adct_pi_audit_log';
        $repository = Plugin::auditLog();
        $page = new AuditLogPage($repository, new AuditCheckClock(), new DateTimeZone('Africa/Johannesburg'));
        $suffix = strtolower((string) random_int(100000, 999999));
        $stamp = 'audit-check-' . $suffix;
        $subject = random_int(900000, 999999);
        $written = [];
        $users = [];
        $previousUser = get_current_user_id();
        $originalGet = $_GET;

        try {
            // Everything this check ever writes belongs to one invented actor,
            // so an interrupted earlier run cannot leave a row behind to be
            // counted here.
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$audit} WHERE actor = %s",
                'audit-check@example.test'
            ));

            // Written the way the screens write it, with details that came out of
            // an email body, so the screen is proved against hostile text.
            $written[] = $repository->write(
                'dean.audit-check@example.test',
                AuditAction::APPROVER_APPROVED,
                AuditSubjectType::EVENT_CANDIDATE,
                $subject,
                [
                    'marker' => $stamp,
                    'note' => '<script>alert("x")</script> & "quoted" notice',
                ]
            );

            $query = new AuditQuery(
                actor: 'audit-check@example.test',
                since: new DateTimeImmutable('-3 months'),
                limit: 50
            );
            $entries = $repository->entries($query);

            if ($repository->count($query) !== 1 || count($entries) !== 1) {
                $fail('The audit screen could not find a row by the actor who wrote it.');
            }

            $entry = $entries[0];

            if (
                $entry->action !== AuditAction::APPROVER_APPROVED->value
                || $entry->actor !== 'dean.audit-check@example.test'
                || $entry->subjectType !== AuditSubjectType::EVENT_CANDIDATE
                || $entry->subjectId !== $subject
                || ! str_contains((string) $entry->details, $stamp)
                || abs($entry->createdAt->getTimestamp() - time()) > 300
            ) {
                $fail('The audit screen did not read back a row with its actor, subject and time intact.');
            }

            $_GET = [];
            $scoped = new AuditQuery(
                subjectType: AuditSubjectType::EVENT_CANDIDATE,
                subjectId: $subject,
                since: new DateTimeImmutable('-3 months')
            );

            if (count($repository->entries($scoped)) !== 1) {
                $fail('The audit screen could not filter down to one candidate.');
            }

            $other = new AuditQuery(
                subjectType: AuditSubjectType::EVENT_CANDIDATE,
                subjectId: $subject + 1,
                since: new DateTimeImmutable('-3 months')
            );

            if (count($repository->entries($other)) !== 0) {
                $fail('The audit screen returned a row for a candidate that never existed.');
            }

            // Retention is why the window is capped. The bound is on the length
            // of the window rather than on its start, because a query carries no
            // clock, so this is what the repository has to enforce.
            $refused = false;

            try {
                new AuditQuery(
                    since: new DateTimeImmutable('-26 months'),
                    until: new DateTimeImmutable('now')
                );
            } catch (InvalidArgumentException $expected) {
                $refused = true;
            }

            if (! $refused) {
                $fail('A 26-month audit window was accepted, which is longer than the 24-month retention period.');
            }

            $makeUser = static function (string $label, string $role) use (&$users, $suffix, $fail): int {
                $id = wp_create_user(
                    'audit-' . $label . '-' . $suffix,
                    wp_generate_password(28),
                    $label . '-' . $suffix . '@example.test'
                );

                if (is_wp_error($id)) {
                    $fail('Could not create an invented audit screen user.');

                    return 0;
                }

                (new WP_User($id))->set_role($role);
                $users[] = $id;

                return (int) $id;
            };

            $readerId = $makeUser('reader', 'administrator');
            $outsiderId = $makeUser('outsider', 'subscriber');

            if ($readerId < 1 || $outsiderId < 1) {
                return;
            }

            $dieHandler = static function (): callable {
                return static function ($message): never {
                    throw new RuntimeException(wp_strip_all_tags((string) $message));
                };
            };

            wp_set_current_user($outsiderId);
            $denied = false;
            add_filter('wp_die_handler', $dieHandler);

            try {
                self::render($page);
            } catch (RuntimeException $expected) {
                $denied = str_contains($expected->getMessage(), 'do not have permission');
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }

            if (! $denied) {
                $fail('A subscriber without the reports capability could read the audit log.');
            }

            wp_set_current_user($readerId);
            $_GET = ['actor' => 'audit-check@example.test'];
            $html = self::render($page);

            if (
                ! str_contains($html, 'Audit log')
                || ! str_contains($html, 'dean.audit-check@example.test')
                || ! str_contains($html, 'Approver approved')
                || ! str_contains($html, $stamp)
            ) {
                $fail('The audit log screen did not show the entry the repository could read.');
            }

            if (str_contains($html, '<script>alert("x")</script>')) {
                $fail('The audit log screen rendered email-supplied text as markup.');
            }

            if (str_contains($html, 'method="post"') || str_contains($html, '_wpnonce')) {
                $fail('The audit log screen offered a way to change the trail.');
            }

            $_GET = ['actor' => 'nobody@example.test'];

            if (str_contains(self::render($page), 'dean.audit-check@example.test')) {
                $fail('The actor filter on the audit log screen did not narrow the results.');
            }

            $_GET = ['subject_type' => AuditSubjectType::EVENT_CANDIDATE, 'subject_id' => (string) $subject];
            $html = self::render($page);

            if (! str_contains($html, $stamp) || str_contains($html, 'No audit entries match this filter.')) {
                $fail('The audit log screen could not filter down to one candidate by subject.');
            }

            $_GET = ['window' => '120'];
            $html = self::render($page);

            if (! str_contains($html, 'not recognised')) {
                $fail('An audit window beyond the retention horizon was not reported to the visitor.');
            }

            // The panel mounted on the candidate, event and parish screens, and
            // the 24-month window as MySQL applies it. Both need a real table.
            self::checkMountedPanel($repository, $stamp, $subject, $fail);
            self::checkRetentionWindow($audit, $fail);

            // The menu is registered on admin_menu, which the harness never
            // fires, so the registration is replayed here.
            $page->registerMenu();

            if (! in_array('adct-parish-intake-audit-log', self::submenuSlugs(), true)) {
                $fail('The audit log screen was not registered under the Parish Intake menu.');
            }
        } finally {
            $_GET = $originalGet;
            wp_set_current_user($previousUser);

            foreach ($written as $id) {
                $wpdb->delete($audit, ['id' => $id], ['%d']);
            }

            foreach ($users as $id) {
                wp_delete_user($id);
            }
        }
    }

    /**
     * The shared panel as the three screens actually build it.
     *
     * The unit tests construct the panel directly. This constructs it the way
     * Plugin.php does and proves it reads back the row the write site wrote, for
     * the same subject, with the same escaping, and that it offers no way to
     * change what it shows. The row already carries text that came out of an
     * email body, so nothing asserted here can belong to a real parish.
     */
    private static function checkMountedPanel(
        AuditLogRepository $repository,
        string $stamp,
        int $subject,
        callable $fail
    ): void {
        $panel = new SubjectAuditPanel(
            $repository,
            new AuditCheckClock(),
            new DateTimeZone('Africa/Johannesburg')
        );

        // EVENT_CANDIDATE is what the candidate screen's panel asks for.
        ob_start();
        $panel->render(AuditSubjectType::EVENT_CANDIDATE, $subject);
        $candidate = (string) ob_get_clean();

        if (! str_contains($candidate, 'Audit trail') || ! str_contains($candidate, $stamp)) {
            $fail('The panel mounted on the candidate screen did not show the row written for it.');
        }

        if (str_contains($candidate, '<script>alert("x")</script>')) {
            $fail('The panel mounted on the candidate screen rendered email text as markup.');
        }

        if (str_contains($candidate, 'method="post"') || str_contains($candidate, '_wpnonce')) {
            $fail('The panel mounted on the candidate screen offered a way to change the trail.');
        }

        // A neighbouring candidate must not appear in the first one's panel.
        ob_start();
        $panel->render(AuditSubjectType::EVENT_CANDIDATE, $subject + 1);
        $neighbour = (string) ob_get_clean();

        if (str_contains($neighbour, $stamp)) {
            $fail('The panel showed one candidate\'s audit row on another candidate.');
        }

        // The parish tab is a trail of contacts rather than of one row, so it
        // takes the plural call.
        ob_start();
        $panel->renderMany(AuditSubjectType::PARISH_CONTACT, [$subject, $subject + 1]);
        $parish = (string) ob_get_clean();

        if (! str_contains($parish, 'Audit trail')) {
            $fail('The panel mounted on the parish screen rendered no heading.');
        }

        if (str_contains($parish, '<script>alert("x")</script>')) {
            $fail('The panel mounted on the parish screen rendered email text as markup.');
        }

        // The row written above is a candidate's, not a contact's, so the parish
        // panel must not show it. This is what stops a parish's screen from
        // becoming a second window onto every candidate's trail.
        if (str_contains($parish, $stamp)) {
            $fail('The parish panel showed a candidate\'s audit row: it is not scoped to parish contacts.');
        }
    }

    /**
     * The 24-month window, as the database applies it.
     *
     * RetentionCleanupJob keeps its 24 months in a private constant, so this
     * cannot import it and instead plants rows either side of a boundary
     * computed the same way the job computes its cutoff.
     *
     * One row sits exactly on the cutoff. Without it the one-second margin on the
     * other two proves only that the window is about 24 months wide: a mutation
     * probe that changed the comparison from < to <= deleted nothing extra and
     * this check still passed. The boundary row is what pins the comparison itself.
     *
     * The job is the real one, over the real connection, with only audit cleanup
     * enabled, so the raw, processed and token phases are skipped by
     * RetentionSettings rather than by anything this check does. The rows planted
     * at "now" belong to the other checks in this file, so their survival is also
     * evidence that this phase did not delete what it should keep.
     */
    private static function checkRetentionWindow(string $audit, callable $fail): void
    {
        global $wpdb;

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-24 months');
        $planted = [];

        try {
            foreach ([
                'older' => $cutoff->modify('-1 second'),
                'exact' => $cutoff,
                'newer' => $cutoff->modify('+1 second'),
                'yearOld' => $cutoff->modify('-1 year'),
            ] as $label => $moment) {
                $createdAt = $moment->format('Y-m-d H:i:s');

                if ($wpdb->insert($audit, [
                    'actor' => 'retention-check@example.test',
                    'action' => AuditAction::APPROVER_APPROVED->value,
                    'subject_type' => AuditSubjectType::EVENT_CANDIDATE,
                    'subject_id' => random_int(1, 999999),
                    'details' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]) !== 1) {
                    $fail('Could not plant an isolated audit row for the retention check.');

                    return;
                }

                $planted[$label] = [(int) $wpdb->insert_id, $createdAt];
            }

            $job = new RetentionCleanupJob(
                new WordPressDatabaseConnection($wpdb),
                new AuditCheckProcessedMessages(),
                new AuditCheckStorage(),
                new AuditCheckMailboxSettings(),
                static fn (): RetentionSettings => RetentionSettings::fromValues(
                    '0',
                    '30',
                    '0',
                    '30',
                    '0',
                    '1'
                ),
                static fn (MailboxSettings $mailbox): string => 'unused-' . $mailbox->id,
                static fn (MailboxSettings $mailbox, string $password): MailboxInterface
                    => throw new RuntimeException('The audit phase opens no mailbox.'),
                new AuditCheckFixedClock($now)
            );

            $step = $job->processNext(json_encode([
                'phase' => 'audit',
                'tokens_last_id' => 0,
                'audit_last_id' => 0,
                'raw_last_id' => 0,
                'processed' => [
                    'source_id' => 0,
                    'last_uid' => 0,
                ],
            ], JSON_THROW_ON_ERROR));

            if ($step === null) {
                $fail('Retention cleanup did not run the audit phase with audit cleanup enabled.');

                return;
            }

            foreach ($planted as $label => [$id, $createdAt]) {
                $survives = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$audit} WHERE id = %d",
                    $id
                ));

                $inside = in_array($label, ['exact', 'newer'], true);

                if (($survives === 1) !== $inside) {
                    $fail(sprintf(
                        'The audit row created at %s (%s) was %s, which is %s the 24-month window.',
                        $createdAt,
                        $label,
                        $survives === 1 ? 'kept' : 'deleted',
                        $inside ? 'inside' : 'outside'
                    ));
                }
            }
        } finally {
            foreach ($planted as [$id]) {
                $wpdb->delete($audit, ['id' => $id], ['%d']);
            }
        }
    }

    private static function render(AuditLogPage $page): string
    {
        ob_start();
        $page->renderPage();

        return (string) ob_get_clean();
    }

    /**
     * @return list<string>
     */
    private static function submenuSlugs(): array
    {
        global $submenu;

        foreach ($submenu ?? [] as $parent => $pages) {
            if ($parent === 'adct-parish-intake') {
                return array_values(array_map(
                    static fn (array $entry): string => (string) $entry[2],
                    $pages
                ));
            }
        }

        return [];
    }
}

/**
 * A clock an hour behind, so a row the harness just wrote falls inside the
 * screen's window without the check depending on the day the suite runs.
 */
final class AuditCheckClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now'))->modify('-1 hour');
    }
}

/**
 * A clock pinned to the instant the retention rows were planted around, so the
 * boundary the job computes is the boundary this check planted rows either side
 * of.
 */
final class AuditCheckFixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

/**
 * The collaborators the audit phase never reaches. Each fails loudly rather
 * than quietly returning something plausible, so a retention phase that starts
 * opening mailboxes or storing messages cannot pass unnoticed.
 */
final class AuditCheckProcessedMessages implements ProcessedMailboxMessageStoreInterface
{
    public function recordMoved(
        MailboxSettings $settings,
        MailboxMoveReceipt $receipt,
        DateTimeImmutable $internalDate,
        DateTimeImmutable $recordedAt
    ): void {
        throw new RuntimeException('The audit phase records no move.');
    }

    public function findExpired(
        MailboxSettings $settings,
        int $uidValidity,
        DateTimeImmutable $cutoff,
        int $limit
    ): array {
        throw new RuntimeException('The audit phase looks up no processed message.');
    }

    public function discardStale(MailboxSettings $settings, int $currentUidValidity): void
    {
        throw new RuntimeException('The audit phase discards nothing.');
    }

    public function deleteOwned(MailboxSettings $settings, int $uidValidity, int $uid): void
    {
        throw new RuntimeException('The audit phase deletes nothing.');
    }
}

final class AuditCheckStorage implements InboundMailStorageReaderInterface
{
    public function storeRawMessage(string $rawMessage): string
    {
        throw new RuntimeException('The audit phase stores no raw message.');
    }

    public function storeAttachment(string $content, string $extension): string
    {
        throw new RuntimeException('The audit phase stores no attachment.');
    }

    public function readRawMessage(string $relativePath): string
    {
        throw new RuntimeException('The audit phase reads no raw message.');
    }

    public function resolveAttachmentPath(string $relativePath): string
    {
        throw new RuntimeException('The audit phase resolves no path.');
    }

    public function delete(string $relativePath): void
    {
        throw new RuntimeException('The audit phase deletes no file.');
    }
}

final class AuditCheckMailboxSettings implements MailboxSettingsStoreInterface
{
    public function findActiveMailboxes(): array
    {
        return [];
    }
}
