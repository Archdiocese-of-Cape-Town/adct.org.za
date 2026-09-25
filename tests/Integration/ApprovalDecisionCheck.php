<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Approval\ApprovalNoticeJob;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Approval\ReviewerNotificationPreference;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Auth\ApprovalDecisionHandler;
use ADCT\ParishIntake\WordPress\Auth\ApprovalEditHandler;
use ADCT\ParishIntake\WordPress\Auth\WordPressActionTokenRenewalDelivery;
use ADCT\ParishIntake\WordPress\Database\Repository\ApprovalRouteRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenStore;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Database\WordPressMailQueueRepository;
use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeSettings;
use ADCT\ParishIntake\WordPress\Plugin;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;

final class ApprovalDecisionCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $suffix = bin2hex(random_bytes(5));
        $base = $wpdb->prefix . 'adct_pi_';
        $db = new WordPressDatabaseConnection();
        $clock = new SystemClock();
        $tokens = new ActionTokenService(new WordPressActionTokenStore($db), $clock);
        $recipients = new ApprovalRecipients(new ApprovalRouteResolver(new ApprovalRouteRepository($db)));
        $queue = new WordPressMailQueueRepository($db);
        $job = new ApprovalNoticeJob($db, $recipients, $tokens, Plugin::mailer(), $queue, $clock);
        $registry = new ActionTokenHandlerRegistry([
            new ApprovalDecisionHandler(ActionTokenPurpose::APPROVE_EVENT, $db, $recipients,
                Plugin::candidatePublisher(), Plugin::mailer(), $clock),
            new ApprovalDecisionHandler(ActionTokenPurpose::REJECT_EVENT, $db, $recipients,
                Plugin::candidatePublisher(), Plugin::mailer(), $clock),
            new ApprovalEditHandler($db, $recipients, $clock),
        ]);
        $endpoint = new ActionTokenEndpoint($tokens, $registry,
            new ActionTokenRenewalService($tokens,
                new ActionTokenRateLimiter(new WordPressActionTokenRateLimitStore($db), $clock, wp_salt('auth')),
                new WordPressActionTokenRenewalDelivery(Plugin::mailer())
            )
        );
        $check = static function (bool $valid, string $message) use ($fail): void {
            if (! $valid) {
                $fail('Approver decisions: ' . $message);
            }
        };
        $insert = static function (string $table, array $fields) use ($wpdb, $base, $fail): int {
            if ($wpdb->insert($base . $table, $fields) !== 1) {
                $fail('Could not create an invented approval fixture: ' . $wpdb->last_error);
            }
            return (int) $wpdb->insert_id;
        };
        $now = gmdate('Y-m-d H:i:s');
        $date = (new DateTimeImmutable('+3 days', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        $oldMode = get_option(WordPressTestModeSettings::TEST_MODE_OPTION);
        $oldAllowlist = get_option(WordPressTestModeSettings::ALLOWLIST_OPTION);
        update_option(WordPressTestModeSettings::TEST_MODE_OPTION, WordPressTestModeSettings::MODE_ENABLED);
        update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, ['@example.test']);
        $users = [];
        $posts = [];
        $candidates = [];
        $messages = [];
        $deanery = $insert('deaneries', ['name' => 'Example deanery', 'slug' => 'approval-' . $suffix,
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $parish = $insert('parishes', ['name' => 'Example parish', 'slug' => 'approval-parish-' . $suffix,
            'deanery_id' => $deanery, 'created_at' => $now, 'updated_at' => $now]);
        $noDeanery = $insert('parishes', ['name' => 'Independent group', 'slug' => 'approval-group-' . $suffix,
            'created_at' => $now, 'updated_at' => $now]);
        $makeUser = static function (string $label, string $role) use (&$users, $suffix, $fail): array {
            $email = $label . '-' . $suffix . '@example.test';
            $id = wp_create_user($label . '-' . $suffix, wp_generate_password(28), $email);
            if (is_wp_error($id)) {
                $fail('Could not create an invented approval user.');
            }
            (new WP_User($id))->set_role($role);
            $users[] = $id;
            return [$id, $email];
        };
        [$deanId, $deanEmail] = $makeUser('approval-dean', 'deanery_approver');
        [$reviewerId, $reviewerEmail] = $makeUser('approval-reviewer', 'adct_pi_intake_reviewer');
        $insert('deanery_approvers', [
            'deanery_id' => $deanery, 'wp_user_id' => $deanId, 'email' => $deanEmail,
            'active' => 1, 'notify_mode' => 'each', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $submitter = 'submitter-' . $suffix . '@example.test';
        $candidate = static function (string $label, int $parishId, array $notes = [], array $extraFields = []) use (
            $insert, $now, $date, $submitter, $suffix, &$candidates, &$messages
        ): int {
            $messageId = $insert('inbound_messages', [
                'source_id' => 1, 'external_id' => 'approval-' . $label . '-' . $suffix,
                'sender_email' => $submitter, 'received_at' => $now, 'status' => 'parsed',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $messages[] = $messageId;
            $id = $insert('event_candidates', [
                'message_id' => $messageId, 'block_index' => 0, 'parish_id' => $parishId,
                'fields' => wp_json_encode(array_merge([
                    'title' => 'Example ' . $label, 'event_date' => $date,
                    'event_time' => '09:00', 'description' => 'An invented public notice.',
                ], $extraFields)),
                'recurrence' => '{}', 'notes' => wp_json_encode($notes),
                'match_kind' => 'new', 'status' => 'awaiting_approval',
                'confirmed_by' => $submitter, 'confirmed_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $candidates[] = $id;
            return $id;
        };
        $tokensFor = static function (int $id, string $email) use ($wpdb, $base, $fail): array {
            $mail = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$base}mail_queue WHERE recipient = %s AND group_key IN"
                . " (SELECT group_key FROM {$base}approval_notices WHERE candidate_id = %d AND recipient = %s)"
                . ' LIMIT 1', $email, $id, $email
            ), ARRAY_A);
            if (! is_array($mail)) {
                $fail('No approver mail was queued for the invented candidate.');
            }
            preg_match_all('/adct_token=([A-Za-z0-9_-]{43})/', (string) $mail['body_text'], $matches);
            return [$mail, $matches[1]];
        };
        $act = static function (string $secret, string $reason = '', array $edits = []) use ($endpoint): array {
            $get = $endpoint->respond('GET', $secret, '', '', '', '203.0.113.90');
            preg_match('/name="adct_token_nonce" value="([^"]+)"/', $get->body, $nonce);
            $post = $endpoint->respond('POST', '', $secret, 'perform',
                $nonce[1] ?? '', '203.0.113.90', $reason, $edits
            );
            return [$get, $post];
        };
        try {
            $first = $candidate('first', $parish, ['unknown_sender']);
            $second = $candidate('second', $parish);
            $job->beginRun();
            $job->processNext((string) ($first - 1));
            [$deanMail, $deanLinks] = $tokensFor($first, $deanEmail);
            [$reviewMail, $reviewLinks] = $tokensFor($first, $reviewerEmail);
            $check($deanMail['priority'] == 2 && $reviewMail['priority'] == 2
                && count($deanLinks) === 6 && count($reviewLinks) === 6
                && str_contains($deanMail['body_text'], 'Unknown sender')
                && str_contains($deanMail['body_text'], 'Parish: Example parish')
                && str_contains($deanMail['body_text'], (new DateTimeImmutable($date))->format('j F Y'))
                && str_contains($deanMail['body_text'], $submitter),
                'one grouped, priority-2 email per active dean and reviewer must show trust and sender.');
            $check($endpoint->respond('GET', $deanLinks[0], '', '', '', '203.0.113.90')->statusCode === 200
                && $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$base}event_candidates WHERE id = %d", $first
                )) === 'awaiting_approval', 'GET must not approve.');
            $check($endpoint->respond('POST', '', $deanLinks[0], 'perform', 'wrong', '203.0.113.90')->statusCode === 403,
                'POST without a valid nonce must not approve.');
            $reviewerPreview = $endpoint->respond('GET', $reviewLinks[0], '', '', '', '203.0.113.90');
            preg_match('/name="adct_token_nonce" value="([^"]+)"/', $reviewerPreview->body, $reviewerNonce);
            [$get, $approved] = $act($deanLinks[0]);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT status, approved_via, approved_by, match_event_id FROM {$base}event_candidates WHERE id = %d",
                $first
            ), ARRAY_A);
            $posts[] = (int) $row['match_event_id'];
            $check($approved->statusCode === 200 && $row['status'] === 'published'
                && $row['approved_via'] === 'dean' && $row['approved_by'] === $deanEmail,
                'dean approval must publish once.');
            $laterGet = $endpoint->respond('GET', $reviewLinks[0], '', '', '', '203.0.113.90');
            $laterPost = $endpoint->respond('POST', '', $reviewLinks[0], 'perform',
                $reviewerNonce[1] ?? '', '203.0.113.90'
            );
            $check(str_contains($laterGet->body, 'Already approved')
                && str_contains($laterGet->body, $deanEmail) && $laterPost->statusCode === 409,
                'reviewer arriving second must see the winning approver and cannot reverse it.');
            preg_match('/name="adct_token_nonce" value="([^"]+)"/', $get->body, $deanNonce);
            $replayed = $endpoint->respond('POST', '', $deanLinks[0], 'perform',
                $deanNonce[1] ?? '', '203.0.113.90');
            $check($tokens->inspect($deanLinks[0])->status === ActionTokenStatus::USED
                && $replayed->statusCode === 200,
                'replaying the winning token must recover without another publication.');
            $check((int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}mail_queue WHERE recipient = %s AND group_key = %s",
                $submitter, 'approval-live:' . $first
            )) === 1, 'the submitter must receive one queued live link.');

            [$editGet, $editPost] = $act($reviewLinks[5], '', [
                'title' => 'Corrected second', 'event_date' => (new DateTimeImmutable($date))->format('d/m/Y'),
                'event_time' => '10:30', 'description' => 'Corrected invented text.',
            ]);
            $check($editGet->statusCode === 200 && $editPost->statusCode === 200
                && str_contains((string) $wpdb->get_var($wpdb->prepare(
                    "SELECT fields FROM {$base}event_candidates WHERE id = %d", $second
                )), 'Corrected second'),
                'Edit must save corrections without approving.');
            [, $rejected] = $act($reviewLinks[4], 'Incorrect date <script>alert(1)</script>');
            $check($rejected->statusCode === 200
                && $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$base}event_candidates WHERE id = %d", $second
                )) === 'rejected', 'reviewer rejection must win after an edit.');
            $reasonMail = $wpdb->get_row($wpdb->prepare(
                "SELECT body_text,body_html FROM {$base}mail_queue WHERE recipient = %s AND group_key = %s",
                $submitter, 'approval-rejected:' . $second
            ), ARRAY_A);
            $check(is_array($reasonMail) && str_contains($reasonMail['body_text'], 'Incorrect date')
                && ! str_contains($reasonMail['body_html'], '<script>'),
                'reason must be queued to the submitter and escaped in HTML.');
            $job->beginRun();
            $job->processNext((string) ($first - 1));
            $check((int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}mail_queue WHERE recipient = %s AND group_key = %s",
                $deanEmail, $deanMail['group_key']
            )) === 1, 'rerunning the job must not duplicate the grouped mail.');

            $orphan = $candidate('no-deanery', $noDeanery);
            $job->beginRun();
            $job->processNext((string) ($orphan - 1));
            $check((int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}approval_notices WHERE candidate_id = %d AND recipient = %s",
                $orphan, $deanEmail
            )) === 0, 'a parish without a deanery must not notify a dean.');
            [, $orphanLinks] = $tokensFor($orphan, $reviewerEmail);
            [, $reviewerApproval] = $act($orphanLinks[0]);
            $check($reviewerApproval->statusCode === 200, 'a reviewer must approve without a deanery.');
            $posts[] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT match_event_id FROM {$base}event_candidates WHERE id = %d", $orphan
            ));

            $previousUser = get_current_user_id();
            wp_set_current_user($reviewerId);
            $preference = new ReviewerNotificationPreference();
            ob_start();
            $preference->render(get_userdata($reviewerId));
            $profileHtml = (string) ob_get_clean();
            $check(str_contains($profileHtml, 'Daily digest'),
                'a reviewer must be able to find the daily digest preference on their profile.');
            $_POST['adct_pi_notify_nonce'] = wp_create_nonce('adct_pi_notify_mode_' . $reviewerId);
            $_POST['adct_pi_notify_mode'] = 'digest';
            $preference->save($reviewerId);
            unset($_POST['adct_pi_notify_nonce'], $_POST['adct_pi_notify_mode']);
            wp_set_current_user($previousUser);
            $check(get_user_meta($reviewerId, 'adct_pi_approval_notify_mode', true) === 'digest',
                'a reviewer preference change must persist with a valid nonce.');
            $wpdb->update($base . 'deanery_approvers', ['notify_mode' => 'digest'], ['wp_user_id' => $deanId]);
            $digest1 = $candidate('digest-one', $parish);
            $digest2 = $candidate('digest-two', $parish);
            $job->beginRun();
            $job->processNext((string) ($digest1 - 1));
            [$digestMail, $digestLinks] = $tokensFor($digest1, $deanEmail);
            $check($digestMail['priority'] == 3 && count($digestLinks) === 6
                && str_contains($digestMail['body_text'], 'Example digest-two')
                && (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$base}mail_queue WHERE recipient = %s AND group_key = %s",
                    $deanEmail, $digestMail['group_key']
                )) === 1, 'digest mode must group both pending items into one priority-3 email.');
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT group_key FROM {$base}approval_notices WHERE candidate_id = %d AND recipient = %s",
                $digest2, $deanEmail
            )) === $digestMail['group_key'], 'digest candidates must share the daily key.');
            $ambiguous = $candidate('ambiguous', $parish, [], ['match_review_required' => true]);
            $job->beginRun();
            $job->processNext((string) ($ambiguous - 1));
            $check((int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}approval_notices WHERE candidate_id = %d", $ambiguous
            )) === 0 && $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$base}event_candidates WHERE id = %d", $ambiguous
            )) === 'awaiting_approval', 'an ambiguous match must stay in manual review without actionable email.');

            $wpdb->update($base . 'deanery_approvers', ['notify_mode' => 'each'], ['wp_user_id' => $deanId]);
            $batch = [];
            for ($index = 0; $index < 21; ++$index) {
                $batch[] = $candidate('batch-' . $index, $parish);
            }
            $job->beginRun();
            $step = $job->processNext((string) ($batch[0] - 1));
            $job->processNext($step->checkpoint());
            $check((int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}approval_notices WHERE recipient = %s"
                . ' AND candidate_id BETWEEN %d AND %d',
                $deanEmail, $batch[0], $batch[20]
            )) === 20
                && (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$base}approval_notices WHERE candidate_id = %d AND recipient = %s",
                    $batch[20], $deanEmail
                )) === 0,
                'a bounded run must queue at most one twenty-event email per approver.');
            $job->beginRun();
            $job->processNext((string) ($batch[20] - 1));
            $check((int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}approval_notices WHERE candidate_id = %d AND recipient = %s",
                $batch[20], $deanEmail
            )) === 1, 'the next run must pick up the deferred event.');

            update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, []);
            $suppressed = $candidate('suppressed', $parish);
            $job->beginRun();
            $job->processNext((string) ($suppressed - 1));
            [$suppressedMail, $suppressedLinks] = $tokensFor($suppressed, $deanEmail);
            $check($suppressedMail['status'] === 'suppressed'
                && $endpoint->respond('GET', $suppressedLinks[0], '', '', '', '203.0.113.90')->statusCode === 200
                && ! str_contains(
                    $endpoint->respond('GET', $suppressedLinks[0], '', '', '', '203.0.113.90')->body,
                    'Approve and publish'
                ) && $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$base}event_candidates WHERE id = %d", $suppressed
                )) === 'awaiting_approval',
                'a suppressed queue item must never be actionable.');
        } finally {
            update_option(WordPressTestModeSettings::TEST_MODE_OPTION, $oldMode);
            update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, $oldAllowlist);
            foreach ($posts as $postId) {
                if ($postId > 0) {
                    wp_delete_post($postId, true);
                }
            }
            foreach ($users as $id) {
                wp_delete_user($id);
            }
            foreach ($candidates as $id) {
                $groups = $wpdb->get_col($wpdb->prepare(
                    "SELECT group_key FROM {$base}approval_notices WHERE candidate_id = %d", $id
                ));
                foreach ($groups as $group) {
                    $wpdb->delete($base . 'mail_queue', ['group_key' => $group]);
                }
                foreach (['approval-live:' . $id, 'approval-rejected:' . $id] as $group) {
                    $wpdb->delete($base . 'mail_queue', ['group_key' => $group]);
                }
                $wpdb->delete($base . 'approval_notices', ['candidate_id' => $id]);
                $wpdb->delete($base . 'action_tokens', ['subject_type' => 'event_candidate', 'subject_id' => $id]);
                $wpdb->delete($base . 'audit_log', ['subject_type' => 'event_candidate', 'subject_id' => $id]);
                $wpdb->delete($base . 'event_candidates', ['id' => $id]);
            }
            foreach ($messages as $id) {
                $wpdb->delete($base . 'inbound_messages', ['id' => $id]);
            }
            $wpdb->delete($base . 'deanery_approvers', ['deanery_id' => $deanery]);
            $wpdb->delete($base . 'parishes', ['id' => $parish]);
            $wpdb->delete($base . 'parishes', ['id' => $noDeanery]);
            $wpdb->delete($base . 'deaneries', ['id' => $deanery]);
        }
    }
}
