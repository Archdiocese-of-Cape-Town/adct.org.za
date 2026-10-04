<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Approval\ApprovalNoticeJob;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Approval\ReviewerNotificationPreference;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Auth\ApprovalDecisionHandler;
use ADCT\ParishIntake\WordPress\Auth\ApprovalEditHandler;
use ADCT\ParishIntake\WordPress\Auth\WordPressActionTokenRenewalDelivery;
use ADCT\ParishIntake\WordPress\Database\Repository\ApprovalRouteRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenStore;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Database\WordPressMailQueueRepository;
use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeSettings;
use ADCT\ParishIntake\WordPress\Plugin;

final class ApprovalDecisionCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $suffix = bin2hex(random_bytes(5));
        $base = $wpdb->prefix . 'adct_pi_';
        $db = new WordPressDatabaseConnection();
        $clock = new ApprovalDecisionCheckClock();
        $tokens = new ActionTokenService(new WordPressActionTokenStore($db), $clock);
        $recipients = new ApprovalRecipients(new ApprovalRouteResolver(new ApprovalRouteRepository($db)));
        $queue = new WordPressMailQueueRepository($db);
        $job = new ApprovalNoticeJob($db, $recipients, $tokens, Plugin::mailer(), $queue, $clock,
            new DeaneryApproverRepository($db));
        $approveHandler = new ApprovalDecisionHandler(ActionTokenPurpose::APPROVE_EVENT, $db, $recipients,
            Plugin::candidatePublisher(), Plugin::mailer(), $clock);
        $registry = new ActionTokenHandlerRegistry([
            $approveHandler,
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
        // Position in the body is not an identity: the notice mail also carries the digest-choice
        // link, and any future link shifts every index after it. Each token is resolved through the
        // token store and matched on the purpose the click under test is supposed to have, so a
        // link that is added, removed or reordered cannot silently point a decision at the wrong
        // button. A purpose that is absent fails loudly here rather than being borrowed.
        $linkFor = static function (array $links, ActionTokenPurpose $want, string $label) use (
            $tokens, $fail
        ): string {
            foreach ($links as $link) {
                $binding = $tokens->inspect((string) $link)->binding;
                if ($binding !== null && $binding->purpose === $want) {
                    return (string) $link;
                }
            }
            $fail('Approver decisions: no ' . $label . ' link (' . $want->value
                . ') was in the mail; found purposes: '
                . implode(', ', array_map(static function (string $link) use ($tokens): string {
                    return $tokens->inspect($link)->binding?->purpose->value ?? 'unresolvable';
                }, $links)));
            return '';
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
            $deanApprove = $linkFor($deanLinks, ActionTokenPurpose::APPROVE_EVENT, "the dean's approval");
            $reviewApprove = $linkFor($reviewLinks, ActionTokenPurpose::APPROVE_EVENT, "the reviewer's approval");
            $reviewEdit = $linkFor($reviewLinks, ActionTokenPurpose::EDIT, "the reviewer's edit");
            $reviewReject = $linkFor($reviewLinks, ActionTokenPurpose::REJECT_EVENT, "the reviewer's rejection");
            // The dean holds a live deanery_approvers row, so the notice also carries the
            // digest-choice link (issue #169). The reviewer holds no assignment row, so the same
            // mail to them is unchanged and they set the choice on their own profile page instead.
            // Both halves are asserted: which token the dean's extra link is, and that the reviewer
            // is sent no such link rather than one that would resolve to nobody on click.
            $deansPurpose = $linkFor($deanLinks, ActionTokenPurpose::CHANGE_NOTIFY_MODE, "the dean's digest-choice");
            $reviewerPurposes = array_map(static function (string $link) use ($tokens): string {
                return $tokens->inspect($link)->binding?->purpose->value ?? 'unresolvable';
            }, $reviewLinks);
            $check($deansPurpose !== $deanApprove
                && ! in_array(ActionTokenPurpose::CHANGE_NOTIFY_MODE->value, $reviewerPurposes, true),
                'the digest-choice link must reach only approvers with a live deanery assignment.');
            $check($deanMail['priority'] == 2 && $reviewMail['priority'] == 2
                && count($deanLinks) === 7 && count($reviewLinks) === 6
                && str_contains($deanMail['body_text'], 'Unknown sender')
                && str_contains($deanMail['body_text'], 'Parish: Example parish')
                && str_contains($deanMail['body_text'], (new DateTimeImmutable($date))->format('j F Y'))
                && str_contains($deanMail['body_text'], $submitter),
                'one grouped, priority-2 email per active dean and reviewer must show trust and sender.');
            $check($endpoint->respond('GET', $deanApprove, '', '', '', '203.0.113.90')->statusCode === 200
                && $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$base}event_candidates WHERE id = %d", $first
                )) === 'awaiting_approval', 'GET must not approve.');
            $check($endpoint->respond('POST', '', $deanApprove, 'perform', 'wrong', '203.0.113.90')->statusCode === 403,
                'POST without a valid nonce must not approve.');
            $reviewerPreview = $endpoint->respond('GET', $reviewApprove, '', '', '', '203.0.113.90');
            preg_match('/name="adct_token_nonce" value="([^"]+)"/', $reviewerPreview->body, $reviewerNonce);
            [$get, $approved] = $act($deanApprove);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT status, approved_via, approved_by, match_event_id FROM {$base}event_candidates WHERE id = %d",
                $first
            ), ARRAY_A);
            $posts[] = (int) $row['match_event_id'];
            $check($approved->statusCode === 200 && $row['status'] === 'published'
                && $row['approved_via'] === 'dean' && $row['approved_by'] === $deanEmail,
                'dean approval must publish once.');
            $laterGet = $endpoint->respond('GET', $reviewApprove, '', '', '', '203.0.113.90');
            $laterPost = $endpoint->respond('POST', '', $reviewApprove, 'perform',
                $reviewerNonce[1] ?? '', '203.0.113.90'
            );
            $check(str_contains($laterGet->body, 'Already approved')
                && str_contains($laterGet->body, $deanEmail) && $laterPost->statusCode === 409,
                'reviewer arriving second must see the winning approver and cannot reverse it.');
            preg_match('/name="adct_token_nonce" value="([^"]+)"/', $get->body, $deanNonce);
            $replayed = $endpoint->respond('POST', '', $deanApprove, 'perform',
                $deanNonce[1] ?? '', '203.0.113.90');
            $check($tokens->inspect($deanApprove)->status === ActionTokenStatus::USED
                && $replayed->statusCode === 200,
                'replaying the winning token must recover without another publication.');

                        // A GET that is refused must not burn the link. The unit tests pin this
                        // per recipient; here it is pinned end to end through the endpoint, where
                        // a mistyped or stale click is what actually happens to a dean.
                        $stale = $candidate('stale', $parish);
                        $job->beginRun();
                        $job->processNext((string) ($stale - 1));
                        [, $staleLinks] = $tokensFor($stale, $deanEmail);
                        $staleApprove = $linkFor($staleLinks, ActionTokenPurpose::APPROVE_EVENT, 'the stale dean approval');
                        $wpdb->query($wpdb->prepare(
                            "UPDATE {$base}deanery_approvers SET active = 0 WHERE wp_user_id = %d", $deanId
                        ));
                        $refusedGet = $endpoint->respond('GET', $staleApprove, '', '', '', '203.0.113.90');
                        $check($tokens->inspect($staleApprove)->status === ActionTokenStatus::VALID,
                            'a GET refused because the dean lost the deanery must leave the link usable.');
                        $wpdb->query($wpdb->prepare(
                            "UPDATE {$base}deanery_approvers SET active = 1 WHERE wp_user_id = %d", $deanId
                        ));
                        [, $staleApproved] = $act($staleApprove);
                        $check($staleApproved->statusCode === 200
                            && $wpdb->get_var($wpdb->prepare(
                                "SELECT status FROM {$base}event_candidates WHERE id = %d", $stale
                            )) === 'published',
                            'the same link must still approve once the deanery is restored.');
                        $posts[] = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT match_event_id FROM {$base}event_candidates WHERE id = %d", $stale
                        ));
            $check((int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}mail_queue WHERE recipient = %s AND group_key = %s",
                $submitter, 'approval-live:' . $first
            )) === 1, 'the submitter must receive one queued live link.');

            [$editGet, $editPost] = $act($reviewEdit, '', [
                'title' => 'Corrected second', 'event_date' => (new DateTimeImmutable($date))->format('d/m/Y'),
                'event_time' => '10:30', 'description' => 'Corrected invented text.',
            ]);
            $check($editGet->statusCode === 200 && $editPost->statusCode === 200
                && str_contains((string) $wpdb->get_var($wpdb->prepare(
                    "SELECT fields FROM {$base}event_candidates WHERE id = %d", $second
                )), 'Corrected second'),
                'Edit must save corrections without approving.');
            [, $rejected] = $act($reviewReject, 'Incorrect date <script>alert(1)</script>');
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
            $orphanApprove = $linkFor($orphanLinks, ActionTokenPurpose::APPROVE_EVENT, 'the orphan reviewer approval');
            [, $reviewerApproval] = $act($orphanApprove);
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
            $check(str_contains($profileHtml, 'adct_pi_approval_reminders'),
                'a reviewer must be able to find the reminder switch on their profile.');
            $_POST['adct_pi_notify_nonce'] = wp_create_nonce('adct_pi_notify_mode_' . $reviewerId);
            $_POST['adct_pi_notify_mode'] = 'digest';
            // A ticked checkbox is present in $_POST; absence is how 'off' arrives.
            $_POST['adct_pi_approval_reminders'] = '1';
            $preference->save($reviewerId);
            unset($_POST['adct_pi_notify_nonce'], $_POST['adct_pi_notify_mode'], $_POST['adct_pi_approval_reminders']);
            wp_set_current_user($previousUser);
            $check(get_user_meta($reviewerId, 'adct_pi_approval_notify_mode', true) === 'digest',
                'a reviewer preference change must persist with a valid nonce.');
            $check(get_user_meta($reviewerId, 'adct_pi_approval_reminders', true) === '1',
                'a reviewer who leaves the reminder box ticked keeps reminders on.');
            wp_set_current_user($reviewerId);
            $_POST['adct_pi_notify_nonce'] = wp_create_nonce('adct_pi_notify_mode_' . $reviewerId);
            $_POST['adct_pi_notify_mode'] = 'digest';
            // An unticked box is absent from $_POST, which is how the 'off' signal arrives.
            $preference->save($reviewerId);
            unset($_POST['adct_pi_notify_nonce'], $_POST['adct_pi_notify_mode']);
            wp_set_current_user($previousUser);
            $check(get_user_meta($reviewerId, 'adct_pi_approval_reminders', true) === '0',
                'a reviewer who unticks the reminder box turns their own reminders off.');
            $wpdb->update($base . 'deanery_approvers', ['notify_mode' => 'digest'], ['wp_user_id' => $deanId]);
            $digest1 = $candidate('digest-one', $parish);
            $digest2 = $candidate('digest-two', $parish);
            // Before the digest hour the job must hold the digest, leaving the run complete
            // and the candidate untouched, so the hold cannot be mistaken for a partial run.
            $clock->setInstant(ApprovalDecisionCheckClock::BEFORE_DIGEST_HOUR);
            $job->beginRun();
            $step = $job->processNext((string) ($digest1 - 1));
            // Scope every count to one digest approver: the job also notifies every
                        // site-wide REVIEW-capability user (administrators, editors, managers) in
                        // per-item mode, and those notices are correct behaviour, not a broken hold.
                        $heldDigestNotices = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$base}approval_notices WHERE recipient = %s"
                            . ' AND candidate_id IN (%d,%d)',
                            $deanEmail, $digest1, $digest2
            ));
                        $heldDigestMail = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$base}mail_queue WHERE recipient = %s AND group_key LIKE %s",
                            $deanEmail, 'approval-digest:%'
            ));
                        $heldDigestTokens = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$base}action_tokens WHERE subject_type = %s AND email = %s"
                            . ' AND subject_id IN (%d,%d)',
                            'event_candidate', $deanEmail, $digest1, $digest2
                        ));
                        // Assert each condition separately so a failure names the behaviour that broke
                        // rather than collapsing into one indistinguishable boolean.
                        $check($step->checkpoint() === null,
                            'holding a digest before the digest hour must end the run with no checkpoint.');
                        $check($heldDigestNotices === 0,
                            'a digest approver must receive no approval notice before the digest hour.');
                        $check($heldDigestMail === 0,
                            'a digest approver must receive no digest email before the digest hour.');
                        $check($heldDigestTokens === 0,
                            'a digest approver must receive no actionable token before the digest hour.');
                        $check($wpdb->get_var($wpdb->prepare(
                            "SELECT status FROM {$base}event_candidates WHERE id = %d", $digest1
                        )) === 'awaiting_approval',
                            'holding a digest must leave the candidate awaiting approval.');
                        // The reviewer switched to digest mode above, so the same hold applies to them.
                        $check((int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$base}approval_notices WHERE recipient = %s"
                            . ' AND candidate_id IN (%d,%d)',
                            $reviewerEmail, $digest1, $digest2
                        )) === 0,
                            'a digest-mode reviewer must also be held before the digest hour.');
            $clock->setInstant(ApprovalDecisionCheckClock::AFTER_DIGEST_HOUR);
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
            $pendingMatch = $candidate('pending-match-fields', $parish, [], ['matched_candidate_id' => 987]);
            $job->beginRun();
            $job->processNext((string) ($pendingMatch - 1));
            $pendingMatchNotices = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}approval_notices WHERE candidate_id = %d",
                $pendingMatch
            ));
            $pendingMatchMail = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}mail_queue WHERE group_key LIKE %s",
                'approval:' . $pendingMatch . ':%'
            ));
            $pendingMatchTokens = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$base}action_tokens WHERE subject_type = %s AND subject_id = %d",
                'event_candidate',
                $pendingMatch
            ));
            $check($pendingMatchNotices === 0 && $pendingMatchMail === 0 && $pendingMatchTokens === 0
                && $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$base}event_candidates WHERE id = %d",
                    $pendingMatch
                )) === 'awaiting_approval',
                'a pending candidate ID in fields must not queue approval mail or action tokens.');

            $guardedApproval = $candidate('pending-match-action', $parish, [], ['matched_candidate_id' => 988]);
            $guardedGroup = 'manual-review-action:' . $guardedApproval;
            $guardedNoticeId = $insert('approval_notices', [
                'candidate_id' => $guardedApproval, 'recipient' => $reviewerEmail,
                'group_key' => $guardedGroup, 'notify_mode' => 'each', 'queued_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $guardedMailId = $insert('mail_queue', [
                'recipient' => $reviewerEmail, 'subject' => 'Synthetic approval notice',
                'body_html' => '<p>Synthetic event.</p>', 'body_text' => 'Synthetic event.',
                'priority' => 2, 'group_key' => $guardedGroup, 'status' => 'sent',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $approvalBinding = new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'event_candidate',
                $guardedApproval,
                $reviewerEmail
            );
            $approvalToken = $tokens->issue($approvalBinding)->token();
            [$guardedPreview, $blockedApproval] = $act($approvalToken);
            $guardedCandidate = $wpdb->get_row($wpdb->prepare(
                "SELECT status, approved_by, approved_at, approved_via, decided_by, decided_at"
                . " FROM {$base}event_candidates WHERE id = %d",
                $guardedApproval
            ), ARRAY_A);
            $check($guardedNoticeId > 0 && $guardedMailId > 0
                && $guardedPreview->statusCode === 200 && $blockedApproval->statusCode === 409
                && $tokens->inspect($approvalToken, $approvalBinding)->status === ActionTokenStatus::VALID
                && is_array($guardedCandidate) && $guardedCandidate['status'] === 'awaiting_approval'
                && $guardedCandidate['approved_by'] === null && $guardedCandidate['approved_at'] === null
                && $guardedCandidate['approved_via'] === null && $guardedCandidate['decided_by'] === null
                && $guardedCandidate['decided_at'] === null,
                'an approval link for a pending matched-candidate field must not consume or record approval.');

            $rejectionBinding = new ActionTokenBinding(
                ActionTokenPurpose::REJECT_EVENT,
                'event_candidate',
                $guardedApproval,
                $reviewerEmail
            );
            $rejectionToken = $tokens->issue($rejectionBinding)->token();
            [, $rejectionResponse] = $act($rejectionToken, 'Duplicate match confirmed.');
            $rejectedCandidate = $wpdb->get_row($wpdb->prepare(
                "SELECT status, approved_by, decision_note FROM {$base}event_candidates WHERE id = %d",
                $guardedApproval
            ), ARRAY_A);
            $check($rejectionResponse->statusCode === 200
                && $tokens->inspect($rejectionToken, $rejectionBinding)->status === ActionTokenStatus::USED
                && is_array($rejectedCandidate) && $rejectedCandidate['status'] === 'rejected'
                && $rejectedCandidate['approved_by'] === null
                && $rejectedCandidate['decision_note'] === 'Duplicate match confirmed.',
                'a matched candidate must remain rejectable without authorizing publication.');

            $ambiguousGroup = 'manual-review-recovery:' . $ambiguous;
            $noticeId = $insert('approval_notices', [
                'candidate_id' => $ambiguous, 'recipient' => $reviewerEmail,
                'group_key' => $ambiguousGroup, 'notify_mode' => 'each', 'queued_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $mailId = $insert('mail_queue', [
                'recipient' => $reviewerEmail, 'subject' => 'Synthetic approval notice',
                'body_html' => '<p>Synthetic event.</p>', 'body_text' => 'Synthetic event.',
                'priority' => 2, 'group_key' => $ambiguousGroup, 'status' => 'sent',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $recordedApproval = $wpdb->update($base . 'event_candidates', [
                'approved_by' => $reviewerEmail, 'approved_at' => $now, 'approved_via' => 'reviewer',
                'decided_by' => $reviewerEmail, 'decided_at' => $now,
            ], ['id' => $ambiguous]);
            $recoveryRejected = false;
            try {
                $approveHandler->recover(new ActionTokenBinding(
                    ActionTokenPurpose::APPROVE_EVENT,
                    'event_candidate',
                    $ambiguous,
                    $reviewerEmail
                ));
            } catch (RuntimeException $failure) {
                $previous = $failure->getPrevious();
                $recoveryRejected = str_contains(
                    $failure->getMessage(),
                    'publication needs operator attention'
                ) && $previous instanceof DomainException
                    && str_contains($previous->getMessage(), 'manual review');
            }
            $recoveredCandidate = $wpdb->get_row($wpdb->prepare(
                "SELECT status, approved_by, match_event_id FROM {$base}event_candidates WHERE id = %d",
                $ambiguous
            ), ARRAY_A);
            $unexpectedEventId = (int) ($recoveredCandidate['match_event_id'] ?? 0);
            if ($unexpectedEventId > 0) {
                $posts[] = $unexpectedEventId;
            }
            $publishedSourceCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                'source_candidate_id',
                (string) $ambiguous
            ));
            $check($noticeId > 0 && $mailId > 0 && $recordedApproval === 1 && $recoveryRejected
                && is_array($recoveredCandidate) && $recoveredCandidate['status'] === 'awaiting_approval'
                && $recoveredCandidate['approved_by'] === $reviewerEmail
                && $unexpectedEventId === 0 && $publishedSourceCount === 0,
                'publisher recovery must fail closed for an ambiguous candidate after reviewer approval is recorded.');

            $wpdb->update($base . 'deanery_approvers', ['notify_mode' => 'each'], ['wp_user_id' => $deanId]);
            // Still before the digest hour: a per-item approver must never be held.
            $clock->setInstant(ApprovalDecisionCheckClock::BEFORE_DIGEST_HOUR);
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
            $suppressedLink = $linkFor($suppressedLinks, ActionTokenPurpose::APPROVE_EVENT, 'the suppressed approval');
            $check($suppressedMail['status'] === 'suppressed'
                && $endpoint->respond('GET', $suppressedLink, '', '', '', '203.0.113.90')->statusCode === 200
                && ! str_contains(
                    $endpoint->respond('GET', $suppressedLink, '', '', '', '203.0.113.90')->body,
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

/**
 * A movable fixed clock. The digest hour gate depends on the wall-clock time in
 * Africa/Johannesburg, so this harness sets the instant explicitly instead of
 * letting a real SystemClock decide whether the digest email should go out.
 */
final class ApprovalDecisionCheckClock implements ClockInterface
{
    /** 06:30 SAST: before the default 07:00 digest hour. */
    public const BEFORE_DIGEST_HOUR = '2026-09-25 06:30:00';

    /** 08:15 SAST: after the default digest hour, still the same SAST day. */
    public const AFTER_DIGEST_HOUR = '2026-09-25 08:15:00';

    private DateTimeImmutable $instant;

    public function __construct()
    {
        $this->setInstant(self::AFTER_DIGEST_HOUR);
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function setInstant(string $localTime): void
    {
        $this->instant = new DateTimeImmutable(
            $localTime,
            new DateTimeZone('Africa/Johannesburg')
        );
    }
}
