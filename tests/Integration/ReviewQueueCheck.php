<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Plugin;

final class ReviewQueueCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $suffix = bin2hex(random_bytes(6));
        $stamp = gmdate('Y-m-d H:i:s');
        $date = (new DateTimeImmutable('tomorrow', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        $prefix = $wpdb->prefix . 'adct_pi_';
        $db = new WordPressDatabaseConnection();
        $queue = new ReviewQueueRepository($db, new SystemClock(), new ReviewQueuePolicy(), 0.55);
        $page = new ReviewQueuePage($queue, Plugin::candidatePublisher());
        $inserted = [];
        $users = [];
        $check = static function (bool $condition, string $message) use ($fail): void {
            if (! $condition) {
                $fail('Review queue: ' . $message);
            }
        };
        $insert = static function (string $table, array $values) use ($wpdb, $fail): int {
            if ($wpdb->insert($table, $values) !== 1 || (int) $wpdb->insert_id < 1) {
                $fail('Review queue synthetic fixture could not be inserted: ' . $wpdb->last_error);
            }
            return (int) $wpdb->insert_id;
        };
        $person = static function (string $role, string $label) use (&$users, $suffix, $fail): WP_User {
            $id = wp_create_user('queue-' . $label . '-' . $suffix, wp_generate_password(28),
                'queue-' . $label . '-' . $suffix . '@example.test');
            if (is_wp_error($id)) {
                $fail('Review queue test user could not be created.');
            }
            $users[] = $id;
            $user = new WP_User($id);
            $user->set_role($role);
            return $user;
        };

        $originalUser = get_current_user_id();
        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalRequest = $_REQUEST;
        try {
            $deaneryOne = $insert($prefix . 'deaneries', [
                'name' => 'Queue North ' . $suffix, 'slug' => 'queue-north-' . $suffix,
                'status' => 'active', 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $deaneryTwo = $insert($prefix . 'deaneries', [
                'name' => 'Queue South ' . $suffix, 'slug' => 'queue-south-' . $suffix,
                'status' => 'active', 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $parishOne = $insert($prefix . 'parishes', [
                'name' => 'Fictional Queue Parish ' . $suffix, 'slug' => 'queue-parish-' . $suffix,
                'deanery_id' => $deaneryOne, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $parishTwo = $insert($prefix . 'parishes', [
                'name' => 'Fictional Other Parish ' . $suffix, 'slug' => 'queue-other-' . $suffix,
                'deanery_id' => $deaneryTwo, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $reviewer = $person('adct_pi_intake_reviewer', 'reviewer');
            $dean = $person('deanery_approver', 'dean');
            $otherDean = $person('deanery_approver', 'other-dean');
            $contactUser = $person('parish_contact', 'contact');
            $contact = 'queue-contact-' . $suffix . '@example.test';
            $unknown = 'queue-unknown-' . $suffix . '@example.test';
            $insert($prefix . 'parish_contacts', [
                'parish_id' => $parishOne, 'email' => $contact, 'trust' => 'verified',
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $insert($prefix . 'parish_contacts', [
                'parish_id' => $parishTwo, 'email' => $contact, 'trust' => 'verified',
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            foreach ([[$deaneryOne, $dean], [$deaneryTwo, $otherDean]] as [$deanery, $user]) {
                $insert($prefix . 'deanery_approvers', [
                    'deanery_id' => $deanery, 'wp_user_id' => $user->ID,
                    'email' => 'queue-assignment-' . $user->ID . '-' . $suffix . '@example.test',
                    'active' => 1,
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ]);
            }
            $candidate = static function (
                string $label,
                string $status,
                ?int $parish,
                string $sender,
                float $confidence = 0.9,
                array $extras = []
            ) use ($suffix, $date, $stamp, $insert, $prefix, &$inserted): int {
                $message = $insert($prefix . 'inbound_messages', [
                    'source_id' => 1, 'external_id' => 'queue-' . $label . '-' . $suffix,
                    'sender_email' => $sender, 'received_at' => $stamp, 'status' => 'parsed',
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ]);
                $fields = array_merge([
                    'title' => 'Queue ' . $label . ' ' . $suffix,
                    'event_date' => $date, 'event_time' => '09:00', 'event_end_time' => '10:00',
                    'description' => 'Fictional description.',
                ], $extras['fields'] ?? []);
                if ($parish !== null) {
                    $fields['parish_id'] = $parish;
                }
                unset($extras['fields']);
                $id = $insert($prefix . 'event_candidates', array_merge([
                    'message_id' => $message, 'parish_id' => $parish, 'status' => $status,
                    'fields' => wp_json_encode($fields), 'recurrence' => '{}',
                    'notes' => '[]', 'match_kind' => 'new', 'confidence' => $confidence,
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ], $extras));
                $inserted[] = [$message, $id];
                return $id;
            };

            $normal = $candidate('normal', 'awaiting_approval', $parishOne, $contact);
            $low = $candidate('low', 'awaiting_approval', $parishOne, $contact, 0.20, [
                'notes' => wp_json_encode([
                    'skipped_sections: fictional-private-snippet',
                    'possible_missed_event_after_skipped_section: 2',
                ]),
            ]);
            $unknownId = $candidate('unknown', 'awaiting_approval', $parishOne, $unknown, 0.20);
            $unassigned = $candidate('unassigned', 'awaiting_approval', null, $unknown);
            $other = $candidate('other', 'awaiting_approval', $parishTwo, $contact);
            $pending = $candidate('pending-publication', 'awaiting_approval', $parishOne, $contact, 0.9, [
                'approved_by' => $reviewer->user_email, 'approved_at' => $stamp,
                'decided_by' => $reviewer->user_email, 'decided_at' => $stamp,
            ]);
            $draft = $candidate('draft', 'draft', $parishOne, $contact);
            $submitter = $candidate('submitter', 'awaiting_submitter', $parishOne, $contact);
            $failed = $candidate('failed', 'failed', $parishOne, $contact);
            $expired = $candidate('expired', 'expired', $parishOne, $contact);
            $ambiguous = $candidate('ambiguous', 'duplicate', $parishOne, $contact, 0.9, [
                'fields' => ['matched_candidate_id' => 58, 'match_review_required' => true],
            ]);
            $published = $candidate('published', 'published', $parishOne, $contact);
            $rejected = $candidate('rejected', 'rejected', $parishOne, $contact, 0.9, [
                'decided_by' => $reviewer->user_email, 'decided_at' => $stamp,
            ]);
            $superseded = $candidate('superseded', 'superseded', $parishOne, $contact);

            $count = $queue->counts($reviewer->ID, $reviewer->user_email, true, $suffix);
            $check($count === [
                'awaiting_approval' => 6, 'unknown_senders' => 2, 'low_confidence' => 1,
                'failed' => 4, 'awaiting_submitter' => 2, 'recently_published' => 1,
                'recently_decided' => 1, 'recent_changes' => 0, 'primary_approval' => 2,
            ], 'the scoped tab counts must account for each non-final state exactly once.');
            $primary = [];
            foreach (['unknown_senders', 'low_confidence', 'failed', 'awaiting_submitter'] as $tab) {
                foreach ($queue->find($tab, $reviewer->ID, $reviewer->user_email, true, $suffix, 50, 0) as $row) {
                    $primary[] = (int) $row['id'];
                }
            }
            $approval = array_map('intval', array_column(
                $queue->find('awaiting_approval', $reviewer->ID, $reviewer->user_email, true, $suffix, 50, 0), 'id'
            ));
            $check(count($approval) === 6
                && count(array_intersect($approval, [$normal, $low, $unknownId, $unassigned, $other, $pending])) === 6,
                'all six awaiting items must remain visible in the overlapping approval view.');
            $primary = array_merge($primary, [$normal, $other]);
            sort($primary);
            $expected = [$normal, $low, $unknownId, $unassigned, $other, $pending,
                $draft, $submitter, $failed, $expired, $ambiguous];
            sort($expected);
            $check($primary === $expected, 'all non-final candidates must have one disjoint primary category.');
            $deanCount = $queue->counts($dean->ID, $dean->user_email, false, $suffix);
            $check($deanCount['awaiting_approval'] === 4
                && $queue->findScoped($other, $dean->ID, $dean->user_email, false) === null
                && $queue->findScoped($unassigned, $dean->ID, $dean->user_email, false) === null
                && $queue->findScoped($superseded, $reviewer->ID, $reviewer->user_email, true) === null
                && $queue->findScoped($normal, $otherDean->ID, $otherDean->user_email, false) === null,
                'detail previews must be limited to visible candidates in the assigned active deanery.');
            $wpdb->update($prefix . 'deanery_approvers', ['active' => 0], [
                'deanery_id' => $deaneryOne, 'wp_user_id' => $dean->ID,
            ]);
            $check($queue->counts($dean->ID, $dean->user_email, false, $suffix)['awaiting_approval'] === 0,
                'a deactivated deanery assignment must immediately remove review access.');
            $wpdb->update($prefix . 'deanery_approvers', ['active' => 1], [
                'deanery_id' => $deaneryOne, 'wp_user_id' => $dean->ID,
            ]);
            $check($queue->counts($reviewer->ID, $reviewer->user_email, true, 'Queue normal ' . $suffix)['awaiting_approval'] === 1
                && $queue->counts($reviewer->ID, $reviewer->user_email, true, $unknown)['unknown_senders'] === 2
                && $queue->counts($reviewer->ID, $reviewer->user_email, true, 'Fictional Queue Parish ' . $suffix)['awaiting_approval'] === 4,
                'title, sender and parish search must each filter results and tab counts.');

            wp_set_current_user($reviewer->ID);
            if (function_exists('add_submenu_page')) {
                global $submenu;
                $page->registerMenu();
                $labels = array_column($submenu['adct-parish-intake'] ?? [], 0, 2);
                $badge = $labels[ReviewQueuePage::PAGE_SLUG] ?? '';
                $totalForBadge = $queue->counts($reviewer->ID, $reviewer->user_email, true)['awaiting_approval'];
                $check(str_contains((string) $badge, '<span class="pending-count">' . $totalForBadge . '</span>'),
                    'menu badge must include every actionable scoped approval item.');
            }
            $_GET = ['tab' => 'unknown_senders', 'search' => $suffix];
            ob_start();
            $page->renderPage();
            $html = (string) ob_get_clean();
            $check(str_contains($html, 'Unknown senders (2)') && str_contains($html, 'Awaiting approval (6)')
                && str_contains($html, 'Recent changes (0)')
                && str_contains($html, 'Queue unknown ' . $suffix),
                'installed queue HTML must show exact counts and scoped candidates.');
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d", $unknownId
            )) === 'awaiting_approval', 'GET rendering must not decide a candidate.');
            $_GET = ['tab' => 'awaiting_submitter', 'search' => $suffix];
            ob_start();
            $page->renderPage();
            $submitterHtml = (string) ob_get_clean();
            $check(! str_contains($submitterHtml, 'name="candidate_ids[]"')
                && ! str_contains($submitterHtml, 'Approve selected'),
                'awaiting submitter must be read-only.');
            $_GET = ['tab' => 'low_confidence', 'search' => $suffix];
            ob_start();
            $page->renderPage();
            $lowHtml = (string) ob_get_clean();
            $check(str_contains($lowHtml, 'Possible missed event after 2 skipped sections.')
                && ! str_contains($lowHtml, 'fictional-private-snippet'),
                'future parser skip warnings must show generic counts, never skipped text.');
            $_GET = ['tab' => 'recent_changes', 'search' => $suffix];
            ob_start();
            $page->renderPage();
            $changesHtml = (string) ob_get_clean();
            $check(str_contains($changesHtml, '#71') && ! str_contains($changesHtml, 'Revert</button>'),
                'recent changes must not invent the future instant-change workflow.');
            $check(has_action('admin_post_adct_pi_review_bulk') !== false,
                'bulk action must be registered on the installed plugin.');

            $dieHandler = static function (): callable {
                return static function ($message): never {
                    throw new RuntimeException(wp_strip_all_tags((string) $message));
                };
            };
            add_filter('wp_die_handler', $dieHandler);
            try {
                $_POST = [
                    'bulk_action' => 'approve', 'candidate_ids' => [(string) $normal],
                    'tab' => 'awaiting_approval', 'review_nonce' => 'invalid',
                ];
                $_REQUEST = $_POST;
                try {
                    $page->handleBulk();
                    $fail('Review queue: invalid nonce was accepted.');
                } catch (RuntimeException) {
                    // The nonce must fail before a candidate changes.
                }
                $check($wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$prefix}event_candidates WHERE id = %d", $normal
                )) === 'awaiting_approval', 'invalid nonce must not approve.');
                wp_set_current_user($contactUser->ID);
                try {
                    $page->renderPage();
                    $fail('Review queue: parish contact was shown the queue.');
                } catch (RuntimeException $error) {
                    $check(str_contains($error->getMessage(), 'cannot view'),
                        'an unprivileged contact must not see review information.');
                }
                wp_set_current_user($dean->ID);
                $_POST['bulk_action'] = 'assign';
                $_POST['review_nonce'] = wp_create_nonce('adct_pi_review_bulk');
                $_REQUEST = $_POST;
                try {
                    $page->handleBulk();
                    $fail('Review queue: dean was allowed to assign a parish.');
                } catch (RuntimeException $error) {
                    $check(str_contains($error->getMessage(), 'cannot perform'),
                        'dean must be denied parish reassignment: ' . $error->getMessage());
                }
                wp_set_current_user($otherDean->ID);
                $_POST['bulk_action'] = 'approve';
                try {
                    $queue->decide($normal, 'approve', $otherDean->ID, $otherDean->user_email, false);
                    $fail('Review queue: an approver of another deanery won a decision.');
                } catch (DomainException) {
                    // The database scope is rechecked under the candidate lock.
                }
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }

            $check($queue->decide($ambiguous, 'approve', $reviewer->ID, $reviewer->user_email, true)
                === 'manual_review', 'pending matches must never bulk-publish.');
            $check($queue->decide($normal, 'approve', $dean->ID, $dean->user_email, false)
                === 'decided', 'the assigned dean must be able to approve.');
            $check($queue->decide($normal, 'reject', $reviewer->ID, $reviewer->user_email, true)
                === 'already_decided', 'the competing reviewer cannot reject after a dean approves.');
            $check($queue->decide($normal, 'approve', $dean->ID, $dean->user_email, false)
                === 'retry', 'only the original approver may retry publication after a decision.');
            wp_set_current_user($dean->ID);
            Plugin::candidatePublisher()->publish($normal);
            $check($queue->decide($normal, 'approve', $dean->ID, $dean->user_email, false)
                === 'already_decided', 'published candidates cannot receive another decision.');
            $audit = $wpdb->get_results($wpdb->prepare(
                "SELECT action, actor, details FROM {$prefix}audit_log WHERE subject_type = %s AND subject_id = %d "
                . 'AND action = %s',
                'event_candidate', $normal, 'approver_approved'
            ), ARRAY_A);
            $check(count($audit) === 1 && $audit[0]['actor'] === $dean->user_email
                && (json_decode($audit[0]['details'], true)['role'] ?? '') === 'dean',
                'first-wins approval and retry must leave exactly one actor-linked audit record.');
            wp_set_current_user($reviewer->ID);
            $check($queue->decide($low, 'reject', $reviewer->ID, $reviewer->user_email, true, 'Incorrect date')
                === 'decided'
                && $queue->decide($low, 'approve', $dean->ID, $dean->user_email, false) === 'already_decided',
                'first-wins rejection cannot be reversed by another approver.');
            $rejectedAudit = $wpdb->get_row($wpdb->prepare(
                "SELECT actor, details FROM {$prefix}audit_log WHERE subject_type = %s AND subject_id = %d "
                . 'AND action = %s',
                'event_candidate', $low, 'approver_rejected'
            ), ARRAY_A);
            $check($rejectedAudit !== null && $rejectedAudit['actor'] === $reviewer->user_email
                && (json_decode($rejectedAudit['details'], true)['reason'] ?? '') === 'Incorrect date',
                'bulk rejection must record the reviewer, reason and one audit entry.');
            $check($queue->assignParish($unassigned, $parishOne, $reviewer->ID, $reviewer->user_email)
                && $queue->findScoped($unassigned, $dean->ID, $dean->user_email, false) !== null,
                'parish assignment must update deanery routing.');
            $assigned = $wpdb->get_row($wpdb->prepare(
                "SELECT parish_id, fields FROM {$prefix}event_candidates WHERE id = %d", $unassigned
            ), ARRAY_A);
            $trust = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}parish_contacts WHERE email = %s", $unknown
            ));
            $assignmentAudit = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}audit_log WHERE subject_id = %d AND action = %s",
                $unassigned, 'candidate_parish_assigned'
            ));
            $check((int) $assigned['parish_id'] === $parishOne
                && (int) json_decode($assigned['fields'], true)['parish_id'] === $parishOne
                && (int) $trust === 0 && (int) $assignmentAudit === 1,
                'assignment must align JSON and parish ID, audit once and never verify an unknown sender.');
        } finally {
            wp_set_current_user($originalUser);
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_REQUEST = $originalRequest;
            foreach ($inserted as [$message, $candidateId]) {
                $publishedId = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d", $candidateId
                ));
                if ($publishedId > 0) {
                    wp_delete_post($publishedId, true);
                }
                $wpdb->delete($prefix . 'audit_log', ['subject_type' => 'event_candidate', 'subject_id' => $candidateId]);
                $wpdb->delete($prefix . 'event_candidates', ['id' => $candidateId]);
                $wpdb->delete($prefix . 'inbound_messages', ['id' => $message]);
            }
            foreach ($users as $id) {
                wp_delete_user($id);
            }
            $wpdb->delete($prefix . 'parish_contacts', ['email' => $contact ?? '']);
            foreach ([$deaneryOne ?? 0, $deaneryTwo ?? 0] as $id) {
                $wpdb->delete($prefix . 'deanery_approvers', ['deanery_id' => $id]);
                $wpdb->delete($prefix . 'deaneries', ['id' => $id]);
            }
            foreach ([$parishOne ?? 0, $parishTwo ?? 0] as $id) {
                $wpdb->delete($prefix . 'parishes', ['id' => $id]);
            }
        }
    }
}
