<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Review\CandidateEditValidator;
use ADCT\ParishIntake\Core\Review\CandidateFieldSet;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Approval\FrontEndApprovalQueue;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Auth\LoginHandler;
use ADCT\ParishIntake\WordPress\Auth\WordPressActionTokenRenewalDelivery;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Plugin;

/**
 * Issue #72: "A dean logs in with an emailed link, sees only their own
 * deaneries' items, and can approve. A dean of another deanery can't see or
 * act on them (tested)."
 *
 * ReviewQueueCheck covers the same scoping for reviewers inside wp-admin. This
 * check covers the front-end half: the two deans here share a repository and a
 * policy with the wp-admin queue, so every claim about scoping is made twice,
 * once per surface. It runs against the installed plugin's tables, and drives
 * the three POST handlers rather than the renderer, because a rendered page is
 * not authority â€” the handler's own re-resolution is.
 */
final class FrontEndApprovalQueueCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $suffix = bin2hex(random_bytes(6));
        $stamp = gmdate('Y-m-d H:i:s');
        $date = (new DateTimeImmutable('tomorrow', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        // The stored candidate fields use the ISO form the parser writes, but the
        // editor form is day-first, and that is the only form the POST body accepts.
        // Submitting the ISO value is what a dean typing into a `DD/MM/YYYY` label
        // would never produce, so the two are kept apart deliberately.
        $editDate = (new DateTimeImmutable('tomorrow', new DateTimeZone('Africa/Johannesburg')))->format('d/m/Y');
        $prefix = $wpdb->prefix . 'adct_pi_';
        $database = new WordPressDatabaseConnection();
        $queue = new ReviewQueueRepository($database, new SystemClock(), new ReviewQueuePolicy(), 0.55);
        $page = new FrontEndApprovalQueue(
            $queue,
            Plugin::candidatePublisher(),
            new ReviewQueuePolicy(),
            new CandidateEditValidator()
        );
        $inserted = [];
        $users = [];
        $publishedPosts = [];
        $changes = [];
        $check = static function (bool $condition, string $message) use ($fail): void {
            if (! $condition) {
                $fail('Front-end approval queue: ' . $message);
            }
        };
        // The step label is reported by the standing wp_die() guard below and by
        // this error handler, so a PHP fatal anywhere in the check names the step
        // it died in instead of aborting the harness with a message nobody can place.
        $step = static function (string $label): void {
            $GLOBALS['adct_front_queue_step'] = $label;
        };
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new RuntimeException(
                sprintf(
                    'Front-end approval queue: PHP raised %d during step "%s": %s (%s:%d)',
                    $severity,
                    (string) ($GLOBALS['adct_front_queue_step'] ?? 'unknown'),
                    $message,
                    $file,
                    $line
                )
            );
        });
        $insert = static function (string $table, array $values) use ($wpdb, $prefix, $fail): int {
            $name = str_starts_with($table, $prefix) ? $table : $prefix . $table;
            if ($wpdb->insert($name, $values) !== 1 || (int) $wpdb->insert_id < 1) {
                $fail('Front-end queue fixture could not be inserted into ' . $name . ': ' . $wpdb->last_error);
            }
            return (int) $wpdb->insert_id;
        };
        $person = static function (string $role, string $label) use (&$users, $suffix, $fail): WP_User {
            $login = 'frontqueue-' . $label . '-' . $suffix;
            $id = wp_create_user($login, wp_generate_password(28), $login . '@example.test');
            if (is_wp_error($id)) {
                $fail('Front-end queue test user could not be created.');
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
        $originalReferer = wp_get_referer();
                // Standing guard for the whole check: a wp_die() that escapes every
                // per-call handler aborts the harness with a bare message, so this one
                // reports which step produced it and then stops the process exactly as
                // WordPress would have. It is removed by identity in the `finally`,
                // because removing every wp_die handler would also strip filters the
                // next check installed.
                //
                // It runs at an early priority on purpose. `wp_die_handler` filters
                // chain — each one receives the previous one's return value and the
                // last registration wins — so a fallback registered late would
                // answer for every refusal, and the per-call handlers that turn an
                // expected wp_die() into an inspectable Throwable would never be
                // reached.
                $escapedRefusal = static function () use ($fail): callable {
                    return static function ($message) use ($fail): void {
                        $fail('Front-end approval queue: unexpected wp_die() during step "'
                            . ($GLOBALS['adct_front_queue_step'] ?? 'unknown') . '": '
                            . wp_strip_all_tags((string) $message));
                    };
                };
                add_filter('wp_die_handler', $escapedRefusal, PHP_INT_MIN);
                try {
                    $step('fixtures');
                    // Two deaneries, one dean each. Nothing about the two users differs
            // except which deanery row points at them, which is what makes the
            // cross-deanery assertions below meaningful rather than a test of
            // two differently-permissioned accounts.
            $deaneryOne = $insert('deaneries', [
                'name' => 'Front Queue North ' . $suffix, 'slug' => 'front-queue-north-' . $suffix,
                'status' => 'active', 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $deaneryTwo = $insert('deaneries', [
                'name' => 'Front Queue South ' . $suffix, 'slug' => 'front-queue-south-' . $suffix,
                'status' => 'active', 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $parishOne = $insert('parishes', [
                'name' => 'Fictional Front Parish ' . $suffix, 'slug' => 'front-queue-parish-' . $suffix,
                'deanery_id' => $deaneryOne, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $parishTwo = $insert('parishes', [
                'name' => 'Fictional Front Other Parish ' . $suffix, 'slug' => 'front-queue-other-' . $suffix,
                'deanery_id' => $deaneryTwo, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $dean = $person('deanery_approver', 'dean');
            $otherDean = $person('deanery_approver', 'other-dean');
            $reviewer = $person('adct_pi_intake_reviewer', 'reviewer');
            $contactUser = $person('parish_contact', 'contact');
            $contact = 'front-queue-contact-' . $suffix . '@example.test';
            $insert('parish_contacts', [
                'parish_id' => $parishOne, 'email' => $contact, 'trust' => 'verified',
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $insert('parish_contacts', [
                'parish_id' => $parishTwo, 'email' => $contact, 'trust' => 'verified',
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            foreach ([
                [$deaneryOne, $dean, 'front-queue-assign-a-' . $suffix . '@example.test'],
                [$deaneryTwo, $otherDean, 'front-queue-assign-b-' . $suffix . '@example.test'],
            ] as [$deanery, $user, $assignmentEmail]) {
                $insert('deanery_approvers', [
                    'deanery_id' => $deanery, 'wp_user_id' => $user->ID, 'email' => $assignmentEmail,
                    'active' => 1, 'created_at' => $stamp, 'updated_at' => $stamp,
                ]);
            }

            $candidate = static function (
                string $label,
                ?int $parish,
                string $status = 'awaiting_approval',
                array $extras = []
            ) use ($suffix, $date, $stamp, $insert, &$inserted): int {
                $message = $insert('inbound_messages', [
                    'source_id' => 1, 'external_id' => 'front-queue-' . $label . '-' . $suffix,
                    'sender_email' => 'front-queue-contact-' . $suffix . '@example.test',
                    'received_at' => $stamp, 'status' => 'parsed',
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ]);
                $fields = array_merge([
                    'title' => self::EVENT_TITLE_PREFIX . $label . ' ' . $suffix,
                    'event_date' => $date, 'event_time' => '09:00', 'event_end_time' => '10:00',
                    'description' => 'Fictional description.',
                ], $extras['fields'] ?? []);
                if ($parish !== null) {
                    $fields['parish_id'] = $parish;
                }
                unset($extras['fields']);
                $id = $insert('event_candidates', array_merge([
                    'message_id' => $message, 'parish_id' => $parish, 'status' => $status,
                    'fields' => wp_json_encode($fields), 'recurrence' => '{}',
                    'notes' => '[]', 'match_kind' => 'new', 'confidence' => 0.9,
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ], $extras));
                $inserted[] = [$message, $id];
                return $id;
            };

            $mine = $candidate('mine', $parishOne);
            $theirs = $candidate('theirs', $parishTwo);
            $secondMine = $candidate('second-mine', $parishOne);

            $step('render-scope');
            $check(has_action('admin_post_' . FrontEndApprovalQueue::BULK_ACTION) !== false
                && has_action('admin_post_' . FrontEndApprovalQueue::SAVE_ACTION) !== false
                && has_action('admin_post_' . FrontEndApprovalQueue::REVERT_ACTION) !== false,
                'the front-end queue POST handlers must be registered on the installed plugin.');
            $check(Plugin::actionTokenHandlers()->forPurpose(
                            \ADCT\ParishIntake\Core\Auth\ActionTokenPurpose::LOGIN
                        ) instanceof \ADCT\ParishIntake\WordPress\Auth\LoginHandler,
                            '#72 requires the LOGIN purpose to be handled by LoginHandler on the installed plugin.');

            // --- The acceptance criterion, part one: a dean sees only their own
            // deanery's items, on the front end, with no admin involved. ---
            wp_set_current_user($dean->ID);
            $_GET = [];
            $_POST = [];
            $_REQUEST = [];
            $mineHtml = $page->render();
            $check(str_contains($mineHtml, 'Front Queue mine ' . $suffix)
                && str_contains($mineHtml, 'Front Queue second-mine ' . $suffix),
                'a dean must see the awaiting items of their own deanery on the front end.');
            $check(! str_contains($mineHtml, 'Front Queue theirs ' . $suffix),
                "a dean must not see another deanery's item rendered into their queue.");
            $check(str_contains($mineHtml, 'value="' . FrontEndApprovalQueue::BULK_ACTION . '"'),
                'the front-end approval form must post to its own action, not the wp-admin one.');
                        // The handler reads the nonce out of $_POST[BULK_NONCE], so the
            // rendered field name and the constant must stay the same string.
            // Without this the refusal below is unfalsifiable: a missing field
            // and a bad nonce produce the identical message.
            $check(str_contains($mineHtml, 'name="' . FrontEndApprovalQueue::BULK_NONCE . '"'),
                'the rendered nonce field name must be the constant the handler reads.');

            // --- The acceptance criterion, part two: the dean got in by
            // following an emailed link, with no password and no wp-admin. This
            // mints a real LOGIN token through the real service against the real
            // database and drives the real endpoint, so it exercises the whole
            // path rather than a stand-in for it. ---
            // Start signed out. The render step above left the dean current, and
            // against a signed-in baseline "the GET did not sign anybody in"
            // would be unfalsifiable: it would pass even if the GET signed in
            // again. Every assertion in this block is relative to a signed-out
            // visitor, which is the state the emailed link is actually clicked in.
            wp_set_current_user(0);
            // The only concession to the CLI harness: a real POST runs
            // wp_set_auth_cookie(), and setcookie() under wp-cli has no headers
            // left to write because the harness has already echoed output, so it
            // warns. That is an artefact of running a web handler in CLI, not
            // anything the handler did wrong, and a blanket @ would hide real
            // warnings from the rest of this block. The suppression is scoped to
            // the cookie write and reinstated immediately, so any other warning
            // during the login block still fails the harness.
            set_error_handler(static function (int $severity, string $message): bool {
                return str_contains($message, 'Cannot modify header information')
                    && str_contains($message, 'headers already sent');
            });
            $step('magic-link');
            $tokens = Plugin::actionTokenService();
            $endpoint = new ActionTokenEndpoint(
                $tokens,
                Plugin::actionTokenHandlers(),
                new ActionTokenRenewalService(
                    $tokens,
                    new ActionTokenRateLimiter(
                        new WordPressActionTokenRateLimitStore($database),
                        new SystemClock(),
                        wp_salt('auth')
                    ),
                    new WordPressActionTokenRenewalDelivery(Plugin::mailer())
                )
            );
            $binding = new ActionTokenBinding(ActionTokenPurpose::LOGIN, 'user', $dean->ID, $dean->user_email);
            $secret = $tokens->issue($binding)->token();

            // A GET shows a page and changes nothing: it must not report a
            // completed login, and it must not burn the token, or the POST below
            // could not work.
            $get = $endpoint->respond('GET', $secret, '', '', '', '203.0.113.90');
            preg_match('/name="' . ActionTokenEndpoint::NONCE_FIELD . '" value="([^"]+)"/', $get->body, $loginNonce);
            $check($get->statusCode === 200
                && str_contains($get->body, 'name="' . ActionTokenEndpoint::ACTION_FIELD . '"'),
                'the GET must show a confirmation page that posts the token back.');
            $check(! str_contains(wp_strip_all_tags($get->body), 'signed in'),
                'a GET must not report a completed login; only a POST acts.');
            $check($tokens->inspect($secret)->status === ActionTokenStatus::VALID,
                'the GET must leave the token usable, because the POST still needs it.');

            // The POST acts, and only once.
            //
            // The assertion is on the response and the token, not on
            // get_current_user_id(), because wp_set_auth_cookie() sends a
            // Set-Cookie header and there is no HTTP response to carry it under
            // wp-cli; it also does not set the current user. What is observable
            // here, and what the block below pins down, is that a POST for an
            // entitled account succeeds and one for a de-privileged account is
            // refused. Which user id the cookie is minted for is asserted
            // directly in tests/Unit/WordPress/Auth/LoginHandlerTest.php, where
            // wp_set_auth_cookie() is a double that records its argument.
            $post = $endpoint->respond('POST', '', $secret, 'perform',
                $loginNonce[1] ?? '', '203.0.113.90');
            $check($post->statusCode === 200,
                'the POST must log the dean in; a refusal would be 409, a bad nonce 403: '
                    . $post->statusCode . ' ' . wp_strip_all_tags($post->body));
            $check(str_contains(wp_strip_all_tags($post->body), 'signed in'),
                'the POST must report the signed-in state it just created.');
            $check($tokens->inspect($secret)->status === ActionTokenStatus::USED,
                'a login link must be single-use.');
            // Clear the cookie this check just set, so nothing downstream inherits
            // a session that belongs to a fixture user about to be deleted.
            wp_clear_auth_cookie();
            wp_set_current_user(0);

            // Replaying the spent secret must not log anybody in again.
            //
            // The sentinel is the message, not the status code: a spent token is
            // answered with 200 and a renewal form, because that is the shape
            // that lets a dean whose 30-minute window lapsed mid-review ask for
            // another link from the same page. So "not 200" would be the wrong
            // assertion, and would also have passed against a link that logged in
            // twice and merely forgot to change the code.
            $replay = $endpoint->respond('POST', '', $secret, 'perform', $loginNonce[1] ?? '', '203.0.113.90');
            $check(str_contains(wp_strip_all_tags($replay->body), 'already been used')
                && ! str_contains(wp_strip_all_tags($replay->body), 'signed in'),
                'a spent login link must not sign anybody in a second time: '
                    . wp_strip_all_tags($replay->body));

            // Entitlement is re-resolved at act time, not read off the token: a
            // dean deactivated between the emailed link and the click must not be
            // signed in.
            $step('magic-link-deactivated');
            $secondSecret = $tokens->issue($binding)->token();
            $secondGet = $endpoint->respond('GET', $secondSecret, '', '', '', '203.0.113.90');
            preg_match(
                '/name="' . ActionTokenEndpoint::NONCE_FIELD . '" value="([^"]+)"/',
                $secondGet->body,
                $secondNonce
            );
            $wpdb->update($wpdb->users, ['user_status' => 1], ['ID' => $dean->ID]);
            clean_user_cache($dean->ID);
            $deactivatedPost = $endpoint->respond('POST', '', $secondSecret, 'perform',
                $secondNonce[1] ?? '', '203.0.113.90');
            $check(! str_contains(wp_strip_all_tags($deactivatedPost->body), 'signed in'),
                'a login link must not sign in an account deactivated after the link was sent: '
                    . wp_strip_all_tags($deactivatedPost->body));
            $wpdb->update($wpdb->users, ['user_status' => 0], ['ID' => $dean->ID]);
            clean_user_cache($dean->ID);

            // And once the account is live again the same link still works,
            // because the token was refused rather than spent. This is the
            // property that makes a refusal safe: a mistimed click does not burn
            // a dean's only way in.
            $restored = $endpoint->respond('POST', '', $secondSecret, 'perform',
                $secondNonce[1] ?? '', '203.0.113.90');
            $check($restored->statusCode === 200
                && str_contains(wp_strip_all_tags($restored->body), 'signed in'),
                'a link refused for a suspended account must still work once the account is live again.');
            wp_clear_auth_cookie();
            wp_set_current_user(0);
            // The CLI-only cookie suppression ends here, before the scoped
            // render checks resume, so those still fail on any PHP warning.
            restore_error_handler();

            wp_set_current_user($otherDean->ID);
            $theirHtml = $page->render();
            $check(str_contains($theirHtml, 'Front Queue theirs ' . $suffix)
                && ! str_contains($theirHtml, 'Front Queue mine ' . $suffix),
                'the other dean sees their own item and not the first dean\'s.');

            // A logged-out visitor and a parish contact get the sign-in prompt
            // rather than an empty queue: rendering nothing is indistinguishable
            // from "you have no work", which would be misleading.
            wp_set_current_user(0);
            $anonHtml = $page->render();
            $check(str_contains($anonHtml, 'sign in with the link emailed to you')
                && ! str_contains($anonHtml, 'Front Queue mine ' . $suffix),
                'a signed-out visitor must get the magic-link prompt and no queue contents.');
            wp_set_current_user($contactUser->ID);
            $contactHtml = $page->render();
            $check(str_contains($contactHtml, 'sign in with the link emailed to you')
                && ! str_contains($contactHtml, 'Front Queue mine ' . $suffix),
                'a parish contact has no approval capability and must get the prompt, not the queue.');

            // A deactivated account holds its capabilities but not its standing.
            // render() degrades a suspended viewer to the unavailable notice
            // rather than a 403, because a refused render would take down the
            // page a dean is looking at; the handlers still refuse them.
            $wpdb->update($wpdb->users, ['user_status' => 1], ['ID' => $otherDean->ID]);
            clean_user_cache($otherDean->ID);
            wp_set_current_user($otherDean->ID);
            $suspendedHtml = $page->render();
            $check(! str_contains($suspendedHtml, 'Front Queue theirs ' . $suffix)
                && str_contains($suspendedHtml, 'unavailable right now'),
                'a suspended dean account must lose queue access immediately: ' . $suspendedHtml);
            $wpdb->update($wpdb->users, ['user_status' => 0], ['ID' => $otherDean->ID]);
            clean_user_cache($otherDean->ID);
            wp_set_current_user($otherDean->ID);

            // --- Scope at the repository, which is what the handlers rely on. ---
            $check($queue->findScoped($theirs, $dean->ID, $dean->user_email, false) === null
                && $queue->findScoped($mine, $otherDean->ID, $otherDean->user_email, false) === null,
                'findScoped() must not resolve another deanery\'s candidate.');
            $check($queue->findScoped($mine, $reviewer->ID, $reviewer->user_email, true) !== null
                && $queue->findScoped($theirs, $reviewer->ID, $reviewer->user_email, true) !== null,
                'an archdiocese reviewer keeps both deaneries in view, as in wp-admin.');

            // --- A dean can approve, and the approval publishes and audits. ---
            wp_set_current_user($dean->ID);
            $_POST = [
                'action' => FrontEndApprovalQueue::BULK_ACTION,
                'bulk_action' => 'approve',
                'candidate_ids' => [(string) $mine, (string) $secondMine],
                'adct_pi_front_queue_bulk' => wp_create_nonce(FrontEndApprovalQueue::BULK_ACTION),
            ];
            $_REQUEST = $_POST;
            $step('bulk-approve');
            $location = self::runExpectingRedirect($page, 'handleBulk');
            $check(str_contains($location, 'changed=2') && str_contains($location, 'skipped=0'),
                'a dean approving two of their own items must report both as changed: ' . $location);
            $approvedEvent = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d", $mine
            ));
                        // A bulk approval publishes one post per candidate, so every decided
            // candidate's post is tracked. Missing the second one would leave a
            // published adct_event pointing at a parish this check then deletes,
            // and the next run's occurrence expansion aborts on that orphan.
            $publishedPosts[] = $approvedEvent;
            $publishedPosts[] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d", $secondMine
            ));
            $check($approvedEvent > 0
                && get_post($approvedEvent) instanceof WP_Post
                && $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$prefix}event_candidates WHERE id = %d", $mine
                )) === 'published',
                "a dean's front-end approval must publish the event.");
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d", $secondMine
            )) === 'published',
                'a bulk approval must publish every item it decided.');
            $deanAudit = $wpdb->get_results($wpdb->prepare(
                "SELECT actor FROM {$prefix}audit_log WHERE subject_type = %s AND subject_id = %d "
                . 'AND action = %s',
                'event_candidate', $mine, 'approver_approved'
            ), ARRAY_A);
            $check(count($deanAudit) === 1 && $deanAudit[0]['actor'] === $dean->user_email,
                "the approval must be audited once, against the dean's own account.");

            // --- A dean of another deanery can neither see nor act on it. ---
            $step('cross-deanery');
            wp_set_current_user($otherDean->ID);
            $check(! str_contains($page->render(), 'Front Queue mine ' . $suffix),
                "the other dean must not see an already-published first-dean item.");
            $_POST = [
                'action' => FrontEndApprovalQueue::BULK_ACTION,
                'bulk_action' => 'approve',
                'candidate_ids' => [(string) $mine],
                'adct_pi_front_queue_bulk' => wp_create_nonce(FrontEndApprovalQueue::BULK_ACTION),
            ];
            $_REQUEST = $_POST;
            $rejected = self::expectFailure($page, 'handleBulk');
            $check($rejected !== null && str_contains(strtolower((string) $rejected), 'outside your review queue'),
                'a cross-deanery bulk approval must be refused by the scope re-check: ' . (string) $rejected);
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d", $mine
            )) === 'published',
                'a refused cross-deanery approval must leave the first dean\'s decision untouched.');

            // --- Moving a dean off a deanery revokes access on the old page. ---
            // The page they hold was rendered while they were still entitled;
            // only the act-time re-resolution can catch this, which is why it
            // is asserted here rather than left to the unit tests.
            $reassigned = $candidate('reassigned', $parishOne);
            wp_set_current_user($dean->ID);
            $staleHtml = $page->render();
            $check(str_contains($staleHtml, 'Front Queue reassigned ' . $suffix),
                'the dean must see the item before the move, or this test proves nothing.');
            $wpdb->update($prefix . 'deanery_approvers', ['active' => 0], [
                'deanery_id' => $deaneryOne, 'wp_user_id' => $dean->ID,
            ]);
            $_POST = [
                'action' => FrontEndApprovalQueue::BULK_ACTION,
                'bulk_action' => 'approve',
                'candidate_ids' => [(string) $reassigned],
                'adct_pi_front_queue_bulk' => wp_create_nonce(FrontEndApprovalQueue::BULK_ACTION),
            ];
            $_REQUEST = $_POST;
            $check(! str_contains($page->render(), 'Front Queue reassigned ' . $suffix),
                'a dean removed from their deanery must stop seeing its items immediately.');
            $step('dean-revoked');
            $revoked = self::expectFailure($page, 'handleBulk');
            $check($revoked !== null,
                'a dean removed from their deanery must not be able to act on a stale page.');
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d", $reassigned
            )) === 'awaiting_approval',
                'the revoked action must leave the candidate undecided.');
            $wpdb->update($prefix . 'deanery_approvers', ['active' => 1], [
                'deanery_id' => $deaneryOne, 'wp_user_id' => $dean->ID,
            ]);

            // --- Edits: save, approve-in-one-post, and the editor's own gate. ---
            $toEdit = $candidate('to-edit', $parishOne);
            // `theirs-to-edit` is the *dean's* item, not the other dean's: the check below
            // signs in as the other dean and expects a refusal, which only means
            // something if the candidate belongs to somebody else's deanery.
            $theirsToEdit = $candidate('theirs-to-edit', $parishOne);
            wp_set_current_user($dean->ID);
            $editPost = static function (int $id, string $mode, array $extra = []) use ($suffix, $editDate): array {
                return array_merge([
                    'action' => FrontEndApprovalQueue::SAVE_ACTION,
                    'candidate_id' => (string) $id,
                    'save_mode' => $mode,
                    'title' => 'Front Queue corrected ' . $suffix,
                                'event_date' => $editDate,
                    'event_time' => '11:00',
                    'description' => 'Fictional corrected description.',
                    'adct_pi_front_queue_save' => wp_create_nonce(FrontEndApprovalQueue::SAVE_ACTION),
                ], $extra);
            };
            // A dean opens the editor, changes nothing, and presses Save. The form labels
                        // the field `DD/MM/YYYY` and the validator only parses day-first, so a
                        // pre-fill in any other form would hand the dean a form that refuses
                        // its own values. This reads the pre-fill back out of the rendered
                        // field and posts exactly that, which is what the browser would send.
                        $step('editor-save');
                        $_GET = ['adct_pi_edit' => (string) $toEdit];
                        $editor = $page->render();
                        $check(preg_match(
                            '/name="event_date"[^>]*value="([^"]*)"/',
                            $editor,
                            $prefilled
                        ) === 1 && $prefilled[1] !== '',
                            'the editor must pre-fill the date so the dean can see what they are changing.');
                        $check(CandidateFieldSet::parseEditDate((string) ($prefilled[1] ?? '')) !== null,
                            'the pre-filled date must be day-first, the form the validator parses: '
                            . (string) ($prefilled[1] ?? ''));
                        $check(! str_contains($editor, 'notice-error'),
                            'the editor must open without a validation error.');

                        $_POST = $editPost($toEdit, 'save', ['event_date' => (string) $prefilled[1]]);
                        $_REQUEST = $_POST;
                        $check(self::runExpectingRedirect($page, 'handleSave') !== '',
                            'saving an unchanged candidate must succeed, not bounce back with an error.');

                        // A save-and-approve on a candidate whose text is already correct still
            // decides. `updateFields()` answers `unchanged` when the form matched
            // what was stored, which is the state a candidate is in after the
            // browser re-opens an editor the dean has already corrected; treating
            // that as "nothing to do" silently declined the approval and left the
            // item sitting in the queue with no way to tell anything had failed.
            $unchangedThenApprove = $candidate('unchanged-then-approve', $parishOne);
            wp_set_current_user($dean->ID);
            $_GET = ['adct_pi_edit' => (string) $unchangedThenApprove];
            $editor = $page->render();
                        // Replay the editor's own prefill, every field, exactly as the
                        // browser would submit it. Anything invented here would save as a
                        // change and quietly turn this into a second copy of the block above.
            $asPosted = static function (int $id, string $mode, string $html) use ($suffix, $unchangedThenApprove): array {
                $check = static function (bool $condition, string $message): void {
                    if (! $condition) {
                        throw new RuntimeException('Front-end approval queue: ' . $message);
                    }
                };
                $prefill = [];
                if (preg_match_all('/name="([a-z_]+)" value="([^"]*)"/', $html, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        $prefill[$match[1]] = html_entity_decode((string) $match[2], ENT_QUOTES);
                    }
                }
                if ($id === $unchangedThenApprove) {
                    $check(($prefill['title'] ?? '') === self::EVENT_TITLE_PREFIX
                        . 'unchanged-then-approve ' . $suffix,
                    'the editor must pre-fill the candidate\'s own title.');
                }
                $check(CandidateFieldSet::parseEditDate((string) ($prefill['event_date'] ?? '')) !== null,
                    'the pre-filled date must be day-first: ' . (string) ($prefill['event_date'] ?? ''));
                return array_merge($prefill, [
                    'action' => FrontEndApprovalQueue::SAVE_ACTION,
                    'candidate_id' => (string) $id,
                    'save_mode' => $mode,
                    'adct_pi_front_queue_save' => wp_create_nonce(FrontEndApprovalQueue::SAVE_ACTION),
                ]);
            };
            self::postPrefill($page, $unchangedThenApprove, 'save', $asPosted, $editor);
            $canonicalSave = self::runExpectingRedirect($page, 'handleSave');
            $check(str_contains($canonicalSave, 'saved=saved'),
                'the first save of a hand-built fixture must write the validator\'s canonical shape: '
                . $canonicalSave);
                        // The row now holds exactly what the validator produces, so the
                        // editor's next prefill replays back to the identical form.
            $editor = (static function () use ($page, $unchangedThenApprove): string {
                $_GET = ['adct_pi_edit' => (string) $unchangedThenApprove];
                return $page->render();
            })();
            self::postPrefill($page, $unchangedThenApprove, 'save', $asPosted, $editor);
            $unchangedSave = self::runExpectingRedirect($page, 'handleSave');
            $check(str_contains($unchangedSave, 'saved=unchanged'),
                'this step depends on the replayed prefill genuinely changing nothing: ' . $unchangedSave);
            self::postPrefill($page, $unchangedThenApprove, 'approve', $asPosted, $editor);
            $unchangedApprove = self::runExpectingRedirect($page, 'handleSave');
            $check(str_contains($unchangedApprove, 'decision=decided'),
                'approving already-corrected text must still decide the candidate: ' . $unchangedApprove);
            $unchangedStatus = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d", $unchangedThenApprove
            ));
            $check(in_array($unchangedStatus, ['approved', 'published'], true),
                'the decided candidate must be approved, not left awaiting approval: '
                . $unchangedStatus);
            $check((string) $wpdb->get_var($wpdb->prepare(
                "SELECT decided_by FROM {$prefix}event_candidates WHERE id = %d", $unchangedThenApprove
            )) === $dean->user_email,
                'the approval must record the dean who made it.');
            $publishedPosts[] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d",
                $unchangedThenApprove
            ));

            $_POST = $editPost($toEdit, 'approve');
                        $_REQUEST = $_POST;
                        $savedTo = self::runExpectingRedirect($page, 'handleSave');
            $check(str_contains($savedTo, 'saved=') && str_contains($savedTo, 'decision=decided'),
                'a save-and-approve POST must save, decide and publish: ' . $savedTo);
            $editedEvent = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d", $toEdit
            ));
            $publishedPosts[] = $editedEvent;
            $check($editedEvent > 0
                && get_post($editedEvent) instanceof WP_Post
                && get_post($editedEvent)->post_title === 'Front Queue corrected ' . $suffix,
                "the correction a dean types must be what the published event carries.");
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, '$.title')) FROM {$prefix}event_candidates WHERE id = %d",
                $toEdit
            )) === 'Front Queue corrected ' . $suffix,
                'the candidate must keep the corrected fields, not just the published post.');

            wp_set_current_user($otherDean->ID);
            $_POST = $editPost($theirsToEdit, 'save', ['title' => 'Cross-denied ' . $suffix]);
            $_REQUEST = $_POST;
            $crossEdit = self::expectFailure($page, 'handleSave');
            $check($crossEdit !== null && str_contains(strtolower((string) $crossEdit), 'not in your approval queue'),
                            'the editor must refuse to open another deanery\'s candidate: ' . var_export($crossEdit, true));
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, '$.title')) FROM {$prefix}event_candidates "
                . 'WHERE id = %d', $theirsToEdit
            )) === self::EVENT_TITLE_PREFIX . 'theirs-to-edit ' . $suffix,
                'the refused cross-deanery save must not have written a single field.');

            // A decided candidate is not editable, even by the dean entitled to it. This
            // one was saved and approved just above, so its editor holds exactly the
            // values the last save wrote rather than a fresh invented correction.
            wp_set_current_user($dean->ID);
            $_GET = ['adct_pi_edit' => (string) $toEdit];
            self::postPrefill($page, $toEdit, 'save', $asPosted, $page->render());
            $_REQUEST = $_POST;
            $step('decided-edit');
            $decidedEdit = self::expectFailure($page, 'handleSave');
            $check($decidedEdit !== null && str_contains(strtolower((string) $decidedEdit), 'already been decided'),
                'an already-decided candidate must not be editable again: ' . (string) $decidedEdit);

            // --- Nonces. A dean with a live session still may not post without one. ---
            wp_set_current_user($dean->ID);
            $_POST = [
                'action' => FrontEndApprovalQueue::BULK_ACTION,
                'bulk_action' => 'approve',
                'candidate_ids' => [(string) $reassigned],
                'adct_pi_front_queue_bulk' => 'not-a-real-nonce',
            ];
            $_REQUEST = $_POST;
            $step('nonce-negatives');
            $noNonce = self::expectFailure($page, 'handleBulk');
            $check($noNonce !== null && str_contains(strtolower((string) $noNonce), 'expired'),
                "a front-end bulk approval without a valid nonce must be refused: " . (string) $noNonce);
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d", $reassigned
            )) === 'awaiting_approval',
                'a nonce failure must not change the candidate.');
            $_POST = $editPost($reassigned, 'approve', ['adct_pi_front_queue_save' => 'not-a-real-nonce']);
            $_REQUEST = $_POST;
            $check(self::expectFailure($page, 'handleSave') !== null,
                'a front-end save without a valid nonce must be refused.');
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, '$.title')) FROM {$prefix}event_candidates "
                . 'WHERE id = %d', $reassigned
            )) === 'Front Queue reassigned ' . $suffix,
                'a nonce failure must not write the candidate.');

            // A valid nonce on a first, untouched approval still works, so the
            // check above is proving the nonce and not a permanently broken page.
            wp_set_current_user($dean->ID);
            $_POST = $editPost($reassigned, 'approve');
            $_REQUEST = $_POST;
            $finalSave = self::runExpectingRedirect($page, 'handleSave');
            $check(str_contains($finalSave, 'decision=decided'),
                'a correctly nonced save-and-approve must still succeed: ' . $finalSave);
            $publishedPosts[] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d", $reassigned
            ));

            // --- Recent changes, and the revert request that may only be made
            // for a change in the caller's own deanery. ---
            $changeOne = $insert('event_changes', [
                'event_id' => $approvedEvent, 'candidate_id' => $mine, 'actor' => $dean->user_email,
                'kind' => 'update', 'before_payload' => '{}', 'after_payload' => '{}',
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $changes[] = $changeOne;
            // A change with no candidate link: this is the path a post-publication
            // edit takes, and it is the one that would be invisible if the change
            // scope were collapsed into the candidate scope.
            $orphanChange = $insert('event_changes', [
                'event_id' => $editedEvent, 'candidate_id' => null, 'actor' => $dean->user_email,
                'kind' => 'update', 'before_payload' => '{}', 'after_payload' => '{}',
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $changes[] = $orphanChange;
            // A candidate in the *other* deanery, published so the change fixture below has
            // a foreign event to hang off. Publication needs a recorded dean, so the
            // approval columns are stamped here rather than left to a decision that
            // this check deliberately never performs.
            $otherPending = $candidate('other-pending', $parishTwo);
            $wpdb->update($prefix . 'event_candidates', [
                'status' => 'approved',
                'approved_by' => $otherDean->user_email,
                'approved_at' => $stamp,
                'approved_via' => 'dean',
            ], ['id' => $otherPending]);
            $otherEvent = Plugin::candidatePublisher()->publish($otherPending);
            $changeTwo = $insert('event_changes', [
                'event_id' => $otherEvent, 'candidate_id' => $otherPending, 'actor' => $otherDean->user_email,
                'kind' => 'update', 'before_payload' => '{}', 'after_payload' => '{}',
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $changes[] = $changeTwo;
            $publishedPosts[] = $otherEvent;
            wp_update_post([
                'ID' => $approvedEvent,
                'meta_input' => ['parish_id' => (string) $parishOne],
            ]);
            wp_update_post([
                'ID' => $otherEvent,
                'meta_input' => ['parish_id' => (string) $parishTwo],
            ]);

            wp_set_current_user($dean->ID);
            $step('changes-revert');
            $scopedChanges = $queue->recentChanges($dean->ID, $dean->user_email, false);
            $scopedIds = array_map('intval', array_column($scopedChanges, 'id'));
            sort($scopedIds);
            $expectedChanges = [$changeOne, $orphanChange];
            sort($expectedChanges);
            $check($scopedIds === $expectedChanges,
                'a dean must see both their candidate-linked and post-meta-linked changes, '
                    . 'and only those: ' . implode(',', $scopedIds));
            $check($queue->findScopedChange($changeTwo, $dean->ID, $dean->user_email, false) === null
                && $queue->findScopedChange($changeOne, $otherDean->ID, $otherDean->user_email, false) === null
                && $queue->findScopedChange($orphanChange, $otherDean->ID, $otherDean->user_email, false) === null,
                "a change from another deanery must resolve to nothing for this dean.");
            $check($queue->findScopedChange($changeOne, $reviewer->ID, $reviewer->user_email, true) !== null,
                'a reviewer keeps every deanery\'s changes in view.');
            // The editor replaces the queue when adct_pi_edit is present, so the
            // queue page has to be asked for as such before its table can be read.
            $_GET = [];
            $changesHtml = $page->render();
            $check(str_contains($changesHtml, 'event #' . $approvedEvent)
                && ! str_contains($changesHtml, 'event #' . $otherEvent),
                'the rendered changes table must be scoped to the caller\'s own deanery.');
            $check(str_contains($changesHtml, 'Ask to revert'),
                'an unreverted change must offer the emailed revert request (ADR 0008).');

            // Reverting an approved event notifies the parish, so this POST only
            // mints the request; it must never change the event by itself.
            $_POST = [
                'action' => FrontEndApprovalQueue::REVERT_ACTION,
                'change_id' => (string) $changeOne,
                'adct_pi_front_queue_bulk' => wp_create_nonce(FrontEndApprovalQueue::REVERT_ACTION),
            ];
            $_REQUEST = $_POST;
            $revertTo = self::runExpectingRedirect($page, 'handleRevertRequest');
            $check(str_contains($revertTo, 'revert_requested=' . $changeOne),
                'a scoped revert request must redirect with the change it was for: ' . $revertTo);
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT reverted_at FROM {$prefix}event_changes WHERE id = %d", $changeOne
            )) === null,
                'the request must not revert anything by itself.');
            $_POST['change_id'] = (string) $changeTwo;
            $_REQUEST = $_POST;
            $crossRevert = self::expectFailure($page, 'handleRevertRequest');
            $check($crossRevert !== null
                && str_contains(strtolower((string) $crossRevert), 'not in your approval queue'),
                            'a revert request for another deanery\'s change must be refused: ' . var_export($crossRevert, true));
            $_POST['change_id'] = (string) $changeOne;
            $_POST['adct_pi_front_queue_bulk'] = 'not-a-real-nonce';
            $_REQUEST = $_POST;
            $check(self::expectFailure($page, 'handleRevertRequest') !== null,
                'a revert request without a valid nonce must be refused.');
            $check($wpdb->get_var($wpdb->prepare(
                "SELECT reverted_at FROM {$prefix}event_changes WHERE id = %d", $changeOne
            )) === null,
                'no refused revert request may have reverted anything.');

            // A dean who is neither reviewer nor approver cannot act at all, so
            // the front-end page cannot become a second route into wp-admin.
            $step('actor-negatives');
            wp_set_current_user($contactUser->ID);
            $check(self::expectFailure($page, 'handleBulk') !== null,
                'a parish contact must not be able to drive the bulk handler.');
            $check(self::expectFailure($page, 'handleSave') !== null,
                'a parish contact must not be able to drive the save handler.');
            $check(self::expectFailure($page, 'handleRevertRequest') !== null,
                'a parish contact must not be able to drive the revert handler.');
            wp_set_current_user(0);
            $check(self::expectFailure($page, 'handleBulk') !== null,
                'a signed-out visitor must not be able to drive the bulk handler.');
        } finally {
            restore_error_handler();
            remove_filter('wp_die_handler', $escapedRefusal, PHP_INT_MIN);
            unset($GLOBALS['adct_front_queue_step']);
            wp_set_current_user($originalUser);
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_REQUEST = $originalRequest;
            foreach ($changes as $changeId) {
                $wpdb->delete($prefix . 'event_changes', ['id' => $changeId]);
            }
            foreach (array_unique(array_filter($publishedPosts)) as $postId) {
                wp_delete_post($postId, true);
            }
            foreach ($inserted as [$message, $candidateId]) {
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
            // A published event pointing at a parish this check has just deleted
            // is an orphan: the daily occurrence job walks every published event
            // and throws on the missing parish, so the leak would surface as an
            // unrelated failure ~2,800 lines later in the next run. Any post that
            // escaped the list above is deleted here, and then named, rather than
            // left to poison the harness.
            $leaked = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s "
                . 'AND post_title LIKE %s',
                'adct_event',
                'publish',
                self::EVENT_TITLE_PREFIX . '%' . $wpdb->esc_like($suffix) . '%'
            ));

            if ($leaked !== []) {
                foreach ($leaked as $postId) {
                    wp_delete_post((int) $postId, true);
                }

                self::reportLeakedPost($fail, $leaked);
            }
        }
    }

    /**
     * Every event this check publishes is titled with this prefix.
     *
     * The harness clears every event post titled "Fictional " before it seeds the
     * directory, so a run that is killed mid-check cannot leave a published post
     * pointing at a parish this check will delete again. Without this prefix the
     * leftovers survive, and the daily occurrence job then aborts on the missing
     * parish thousands of lines later, in a run that never reaches this check.
     */
    public const EVENT_TITLE_PREFIX = 'Front Queue ';

    /**
     * Says which posts this check failed to clean up.
     *
     * $fail() reports and the caller decides what to do; this only adds the ids,
     * because a bare "cleaned up" without them is not actionable.
     */
    private static function reportLeakedPost(callable $fail, array $postIds): void
    {
        $fail('Front-end approval queue: leaked published event post(s) '
            . implode(',', array_map('intval', $postIds))
            . '; each tracked publish must be added to $publishedPosts so the '
            . 'occurrence job does not abort on the deleted parish next run.');
    }

    /**
     * Runs a handler that must end in wp_safe_redirect(), and returns the URL.
     *
     * The redirect is intercepted by throwing, which is what stands in for the
     * `exit` that ends a real front-end POST. The filter is removed in a
     * `finally`, so an assertion throwing above cannot leak it into the next
     * check.
     *
     * @return string
     */
    /**
     * Stage one editor submission built from a rendered form and hand it to a handler.
     *
     * Returns the page rather than the redirect so the caller reads it directly;
     * the flow is the same either way, only the chain is shorter.
     *
     * @param callable(int, string, string): array<string, mixed> $build
     */
    private static function postPrefill(
        FrontEndApprovalQueue $page,
        int $id,
        string $mode,
        callable $build,
        string $html
    ): FrontEndApprovalQueue {
        $_POST = $build($id, $mode, $html);
        $_REQUEST = $_POST;

        return $page;
    }

    private static function runExpectingRedirect(FrontEndApprovalQueue $page, string $method): string
    {
        $redirect = static function ($location): bool {
            throw new FrontQueueRedirect((string) $location);
        };
        // A refusal on a path that must redirect is a failure of this check, not
        // of the harness, so it is reported here rather than escaping.
        $refusal = static function (): callable {
            return static function ($message): never {
                throw new FrontQueueUnexpectedRefusal(wp_strip_all_tags((string) $message));
            };
        };
        add_filter('wp_redirect', $redirect, 1);
        add_filter('wp_die_handler', $refusal);
        try {
            $page->{$method}();
        } catch (FrontQueueRedirect $caught) {
            return $caught->getMessage();
        } catch (FrontQueueUnexpectedRefusal $died) {
            throw new RuntimeException(
                sprintf(
                    '%s() refused instead of redirecting: %s',
                    $method,
                    $died->getMessage()
                )
            );
        } finally {
            // Both filters come off by identity. Leaving the wp_redirect filter in
            // place would make every later check that expects a redirect throw
            // this check's exception instead of capturing its own location.
            remove_filter('wp_redirect', $redirect, 1);
            remove_filter('wp_die_handler', $refusal);
        }

        return '';
    }

    /**
     * Runs a handler that must refuse, and returns the refusal message.
     *
     * `forbid()` ends in wp_die(), which the handler filter turns into a
     * Throwable so the refusal can be inspected. A handler that succeeds instead
     * of refusing returns null and is left to the caller's assertion.
     */
    private static function expectFailure(FrontEndApprovalQueue $page, string $method): ?string
    {
        $handler = static function (): callable {
            return static function ($message): never {
                throw new FrontQueueRefusal(wp_strip_all_tags((string) $message));
            };
        };
        // A handler that redirects when a refusal was expected has failed the
        // assertion it was called for. Left alone that redirect escapes as an
        // uncaught exception and takes the whole harness down, which reports the
        // symptom — a fatal — instead of the cause — this line. Swallowing it into
        // `null` is what the caller's check already means by "no refusal".
        $redirect = static function ($location): bool {
            throw new FrontQueueRedirect((string) $location);
        };
        add_filter('wp_die_handler', $handler);
        add_filter('wp_redirect', $redirect, 1);
        try {
            $page->{$method}();
        } catch (FrontQueueRefusal $refusal) {
            return $refusal->getMessage();
        } catch (FrontQueueRedirect) {
            return null;
        } finally {
            remove_filter('wp_redirect', $redirect, 1);
            remove_filter('wp_die_handler', $handler);
        }

        return null;
    }
}

/** Signals the intercepted wp_safe_redirect() so a handler can be checked. */
final class FrontQueueRedirect extends RuntimeException
{
}

/** Signals an intercepted wp_die() so a refusal can be inspected. */
final class FrontQueueRefusal extends RuntimeException
{
}

/** Signals a wp_die() inside a path that was expected to redirect instead. */
final class FrontQueueUnexpectedRefusal extends RuntimeException
{
}
