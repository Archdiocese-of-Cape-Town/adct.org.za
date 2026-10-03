<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditQuery;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Admin\AuditLogPage;
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
