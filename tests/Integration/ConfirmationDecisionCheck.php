<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Auth\ConfirmationDecisionHandler;
use ADCT\ParishIntake\WordPress\Auth\WordPressActionTokenRenewalDelivery;
use ADCT\ParishIntake\WordPress\Database\Repository\ApprovalRouteRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenStore;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Plugin;

final class ConfirmationDecisionCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $suffix = bin2hex(random_bytes(6));
        $now = gmdate('Y-m-d H:i:s');
        $date = (new DateTimeImmutable('tomorrow', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        $prefix = $wpdb->prefix . 'adct_pi_';
        $db = new WordPressDatabaseConnection();
        $clock = new SystemClock();
        $tokens = new ActionTokenService(new WordPressActionTokenStore($db), $clock);
        $route = new ApprovalRouteResolver(new ApprovalRouteRepository($db));
        $registry = new ActionTokenHandlerRegistry([
            new ConfirmationDecisionHandler(ActionTokenPurpose::CONFIRM, $db, $route, Plugin::candidatePublisher(), $clock),
            new ConfirmationDecisionHandler(ActionTokenPurpose::DENY, $db, $route, Plugin::candidatePublisher(), $clock),
        ]);
        $endpoint = new ActionTokenEndpoint(
            $tokens,
            $registry,
            new ActionTokenRenewalService(
                $tokens,
                new ActionTokenRateLimiter(new WordPressActionTokenRateLimitStore($db), $clock, wp_salt('auth')),
                new WordPressActionTokenRenewalDelivery(Plugin::mailer())
            )
        );
        $ids = ['messages' => [], 'candidates' => [], 'posts' => [], 'users' => [], 'parishes' => [], 'deaneries' => []];
        $check = static function (bool $condition, string $description) use ($fail): void {
            if (! $condition) {
                $fail('Confirmation decision: ' . $description);
            }
        };
        $insert = static function (string $table, array $values) use ($wpdb, $fail): int {
            if ($wpdb->insert($table, $values) !== 1 || (int) $wpdb->insert_id < 1) {
                $fail('Could not insert a synthetic confirmation test record: ' . $wpdb->last_error);
            }
            return (int) $wpdb->insert_id;
        };
        $person = static function (string $label, string $role) use (&$ids, $suffix, $fail): string {
            $email = $label . '-' . $suffix . '@example.test';
            $id = wp_create_user($label . '-' . $suffix, wp_generate_password(32), $email);
            if (is_wp_error($id)) {
                $fail('Could not create a synthetic approver user.');
            }
            $ids['users'][] = $id;
            (new WP_User($id))->set_role($role);
            return $email;
        };
        $case = static function (
            string $label,
            string $sender,
            string $recipient,
            ?int $parish,
            int $count = 1,
            string $kind = 'new',
            string $queueStatus = 'sent',
            ?string $authResults = null
        ) use (&$ids, $prefix, $suffix, $now, $date, $insert): array {
            $message = $insert($prefix . 'inbound_messages', [
                'source_id' => 1, 'external_id' => $label . '-' . $suffix,
                'sender_email' => $sender, 'received_at' => $now, 'status' => 'parsed',
                'confirmation_status' => $queueStatus === 'suppressed' ? 'suppressed' : 'sent',
                'auth_results' => $authResults, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $ids['messages'][] = $message;
            $insert($prefix . 'mail_queue', [
                'recipient' => $recipient, 'subject' => 'Synthetic event',
                'group_key' => 'confirmation:' . $message, 'status' => $queueStatus,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $candidates = [];
            for ($index = 0; $index < $count; $index++) {
                $candidates[] = $insert($prefix . 'event_candidates', [
                    'message_id' => $message, 'block_index' => $index, 'parish_id' => $parish,
                    'fields' => wp_json_encode([
                        'title' => 'Synthetic ' . $label . ' ' . $index,
                        'event_date' => $date, 'event_time' => '09:00',
                        'event_end_time' => '10:00', 'description' => 'Invented notice.',
                    ]),
                    'recurrence' => '{}', 'notes' => '[]', 'status' => 'draft',
                    'match_kind' => $kind, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $ids['candidates'][] = $candidates[$index];
            }
            return [$message, $candidates];
        };
        $act = static function (
            ActionTokenPurpose $purpose,
            string $type,
            int $id,
            string $email,
            string $reason = ''
        ) use ($tokens, $endpoint): array {
            $issued = $tokens->issue(new ActionTokenBinding($purpose, $type, $id, $email));
            $secret = $issued->token();
            $get = $endpoint->respond('GET', $secret, '', '', '', '203.0.113.19');
            preg_match('/name="adct_token_nonce" value="([^"]+)"/', $get->body, $match);
            $nonce = $match[1] ?? '';
            $post = static fn (string $value = '') => $endpoint->respond(
                'POST', '', $secret, 'perform', $nonce, '203.0.113.19', $value
            );
            return [$secret, $get, $post, $nonce];
        };

        try {
            $deanery = $insert($prefix . 'deaneries', [
                'name' => 'Fictional deanery', 'slug' => 'decision-' . $suffix,
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $ids['deaneries'][] = $deanery;
            $parish = $insert($prefix . 'parishes', [
                'name' => 'Fictional parish', 'slug' => 'decision-parish-' . $suffix,
                'deanery_id' => $deanery, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $ids['parishes'][] = $parish;
            $otherParish = $insert($prefix . 'parishes', [
                'name' => 'Other fictional parish', 'slug' => 'other-decision-' . $suffix,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $ids['parishes'][] = $otherParish;
            $deanEmail = $person('decision-dean', 'deanery_approver');
            $reviewerEmail = $person('decision-reviewer', 'adct_pi_intake_reviewer');
            $insert($prefix . 'deanery_approvers', [
                'deanery_id' => $deanery, 'wp_user_id' => $ids['users'][0], 'email' => $deanEmail,
                'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);

            [$message, $candidate] = $case('normal', 'unknown-' . $suffix . '@example.test', 'unknown-' . $suffix . '@example.test', $parish);
            [$secret, $get, $post, $nonce] = $act(ActionTokenPurpose::CONFIRM, 'event_candidate', $candidate[0], 'unknown-' . $suffix . '@example.test');
            $check($get->statusCode === 200 && str_contains($get->body, 'Synthetic normal')
                && $wpdb->get_var($wpdb->prepare("SELECT status FROM {$prefix}event_candidates WHERE id = %d", $candidate[0])) === 'draft',
                'GET must be read-only.');
            $check($endpoint->respond('POST', '', $secret, 'perform', 'wrong-nonce', '203.0.113.19')->statusCode === 403
                && $wpdb->get_var($wpdb->prepare("SELECT status FROM {$prefix}event_candidates WHERE id = %d", $candidate[0])) === 'draft',
                'CSRF attempt must not consume a token or change a candidate.');
            $competing = $act(ActionTokenPurpose::DENY, 'event_candidate', $candidate[0], 'unknown-' . $suffix . '@example.test');
            $check($post()->statusCode === 200 && str_contains($post()->body, 'already been used'),
                'confirmation must consume a token once.');
            $check($competing[2]()->statusCode === 409, 'a competing denial must not reverse a confirmed candidate.');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}event_candidates WHERE id = %d", $candidate[0]), ARRAY_A);
            $check($row['status'] === 'awaiting_approval' && $row['approved_by'] === null
                && $row['confirmed_by'] === 'unknown-' . $suffix . '@example.test',
                'unknown sender must enter approval queue, not publish.');
            $check(in_array('unknown_sender', json_decode((string) $row['notes'], true), true),
                'unknown sender must be flagged on the approver candidate.');
            $audit = $wpdb->get_row($wpdb->prepare(
                "SELECT details FROM {$prefix}audit_log WHERE subject_type = %s AND subject_id = %d",
                'event_candidate', $candidate[0]
            ), ARRAY_A);
            $check(($audit !== null) && (json_decode($audit['details'], true)['unknown_sender'] ?? null) === true,
                'unknown sender flag must be audited.');

            [$message, $candidate] = $case('deny', 'deny-' . $suffix . '@example.test', 'deny-' . $suffix . '@example.test', $parish);
            [, $get, $post] = $act(ActionTokenPurpose::DENY, 'event_candidate', $candidate[0], 'deny-' . $suffix . '@example.test');
            $check(str_contains($get->body, 'adct_denial_reason') && $post('Incorrect time')->statusCode === 200,
                'denial reason must be offered and accepted.');
            $row = $wpdb->get_row($wpdb->prepare("SELECT status, decision_note FROM {$prefix}event_candidates WHERE id = %d", $candidate[0]), ARRAY_A);
            $check($row['status'] === 'rejected' && $row['decision_note'] === 'Incorrect time', 'denial must reject with reason.');
            [, , $expiredPost] = $act(ActionTokenPurpose::CONFIRM, 'event_candidate', $candidate[0], 'deny-' . $suffix . '@example.test');
            $check($expiredPost()->statusCode === 409, 'a different confirmation cannot reverse a denial.');

            [$message, $candidate] = $case('expired', 'expired-' . $suffix . '@example.test', 'expired-' . $suffix . '@example.test', $parish);
            $binding = new ActionTokenBinding(ActionTokenPurpose::CONFIRM, 'event_candidate', $candidate[0], 'expired-' . $suffix . '@example.test');
            $expired = $tokens->issue($binding, $clock->now()->modify('+1 second'));
            $wpdb->update($prefix . 'action_tokens', ['expires_at' => '2001-01-01 00:00:00'],
                ['token_hash' => hash('sha256', $expired->token())]);
            $check($endpoint->respond('GET', $expired->token(), '', '', '', '203.0.113.19')->statusCode === 200
                && $endpoint->respond(
                    'POST', '', $expired->token(), 'perform',
                    wp_create_nonce('adct_pi_action_token_perform_' . hash('sha256', $expired->token())),
                    '203.0.113.19'
                )->statusCode === 200
                && $wpdb->get_var($wpdb->prepare("SELECT status FROM {$prefix}event_candidates WHERE id = %d", $candidate[0])) === 'draft',
                'expired link must not change an event.');

            [$message, $candidate] = $case('all', 'all-' . $suffix . '@example.test', 'all-' . $suffix . '@example.test', $parish, 2);
            [, $get, $post] = $act(ActionTokenPurpose::CONFIRM, 'inbound_message', $message, 'all-' . $suffix . '@example.test');
            $check(str_contains($get->body, 'Synthetic all 0') && str_contains($get->body, 'Synthetic all 1')
                && $post()->statusCode === 200, 'confirm-all must show and act on both candidates.');
            foreach ($candidate as $id) {
                $check($wpdb->get_var($wpdb->prepare("SELECT status FROM {$prefix}event_candidates WHERE id = %d", $id))
                    === 'awaiting_approval', 'confirm-all candidate must await approval.');
            }

            [$message, $candidate] = $case('self-dean', $deanEmail, $deanEmail, $parish);
            [, , $post] = $act(ActionTokenPurpose::CONFIRM, 'event_candidate', $candidate[0], $deanEmail);
            $response = $post();
            $row = $wpdb->get_row($wpdb->prepare("SELECT status, approved_via, match_event_id FROM {$prefix}event_candidates WHERE id = %d", $candidate[0]), ARRAY_A);
            $ids['posts'][] = (int) $row['match_event_id'];
            $check($response->statusCode === 200 && str_contains($response->body, 'View published event')
                && $row['status'] === 'published' && $row['approved_via'] === 'self'
                && get_post((int) $row['match_event_id'])?->post_status === 'publish',
                'active dean must publish a new event with a link.');

            [$message, $candidate] = $case('self-reviewer', $reviewerEmail, $reviewerEmail, $otherParish);
            [, , $post] = $act(ActionTokenPurpose::CONFIRM, 'event_candidate', $candidate[0], $reviewerEmail);
            $check($post()->statusCode === 200, 'active archdiocese reviewer must self-approve.');
            $row = $wpdb->get_row($wpdb->prepare("SELECT status, match_event_id FROM {$prefix}event_candidates WHERE id = %d", $candidate[0]), ARRAY_A);
            $ids['posts'][] = (int) $row['match_event_id'];
            $check($row['status'] === 'published', 'reviewer self-approval must publish.');

            foreach ([
                ['wrong-deanery', $deanEmail, $deanEmail, $otherParish, 'sent'],
                ['spoofed-reply', $deanEmail, 'attacker-' . $suffix . '@example.test', $parish, 'sent'],
                ['suppressed', $deanEmail, $deanEmail, $parish, 'suppressed'],
            ] as [$label, $sender, $recipient, $scope, $queueStatus]) {
                [, $candidate] = $case($label, $sender, $recipient, $scope, 1, 'new', $queueStatus);
                [, , $post] = $act(ActionTokenPurpose::CONFIRM, 'event_candidate', $candidate[0], $recipient);
                $response = $post();
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT status, approved_via FROM {$prefix}event_candidates WHERE id = %d", $candidate[0]
                ), ARRAY_A);
                $check($row['status'] !== 'published' && $row['approved_via'] === null,
                    $label . ' must never self-publish.');
                $check($queueStatus === 'suppressed' ? $response->statusCode === 409 : $response->statusCode === 200,
                    $label . ' action response must match its eligibility.');
            }
            [, $candidate] = $case('match-review', $deanEmail, $deanEmail, $parish);
            $wpdb->update(
                $prefix . 'event_candidates',
                ['fields' => wp_json_encode([
                    'title' => 'A changed pending event',
                    'event_date' => $date,
                    'match_review_required' => true,
                ])],
                ['id' => $candidate[0]]
            );
            [, , $post] = $act(ActionTokenPurpose::CONFIRM, 'event_candidate', $candidate[0], $deanEmail);
            $check($post()->statusCode === 200 && $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d", $candidate[0]
            )) === 'awaiting_approval', 'an ambiguous pending match must never self-publish.');
        } finally {
            foreach ($ids['posts'] as $id) {
                if ($id > 0) {
                    wp_delete_post($id, true);
                    $wpdb->delete($prefix . 'occurrences', ['event_id' => $id]);
                }
            }
            foreach ($ids['candidates'] as $id) {
                $wpdb->delete($prefix . 'audit_log', ['subject_type' => 'event_candidate', 'subject_id' => $id]);
                $wpdb->delete($prefix . 'event_candidates', ['id' => $id]);
            }
            foreach ($ids['messages'] as $id) {
                $wpdb->delete($prefix . 'mail_queue', ['group_key' => 'confirmation:' . $id]);
                $wpdb->delete($prefix . 'action_tokens', ['subject_type' => 'inbound_message', 'subject_id' => $id]);
                $wpdb->delete($prefix . 'inbound_messages', ['id' => $id]);
            }
            foreach ($ids['candidates'] as $id) {
                $wpdb->delete($prefix . 'action_tokens', ['subject_type' => 'event_candidate', 'subject_id' => $id]);
            }
            $wpdb->delete($prefix . 'deanery_approvers', ['deanery_id' => $ids['deaneries'][0] ?? 0]);
            foreach ($ids['parishes'] as $id) {
                $wpdb->delete($prefix . 'parishes', ['id' => $id]);
            }
            foreach ($ids['deaneries'] as $id) {
                $wpdb->delete($prefix . 'deaneries', ['id' => $id]);
            }
            foreach ($ids['users'] as $id) {
                wp_delete_user($id);
            }
        }
    }
}
