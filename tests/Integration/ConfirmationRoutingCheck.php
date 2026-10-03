<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Approval\ApprovalRoute;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResult;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResults;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Approval\ApprovalNoticeJob;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenHttpResponse;
use ADCT\ParishIntake\WordPress\Auth\ApprovalDecisionHandler;
use ADCT\ParishIntake\WordPress\Auth\ApprovalEditHandler;
use ADCT\ParishIntake\WordPress\Auth\ConfirmationDecisionHandler;
use ADCT\ParishIntake\WordPress\Auth\WordPressActionTokenRenewalDelivery;
use ADCT\ParishIntake\WordPress\Database\Repository\ApprovalRouteRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenStore;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Database\WordPressMailQueueRepository;
use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeSettings;
use ADCT\ParishIntake\WordPress\Plugin;

/**
* End-to-end confirmation routing for issue #50.
*
* `ConfirmationDecisionCheck` proves that a submitter's own decision is recorded,
* and `ApprovalDecisionCheck` proves the approver's side once a candidate is
* already sitting in the queue. Neither of them walks a submitter's confirmation
* *into* the approval queue, so the routing table in ADR 0008 — who is notified,
* who may self-approve, and what happens to the candidate in every deanery
* configuration — has only ever been checked by hand, in a browser.
*
* Each path below drives the real chain:
*
*     ConfirmationDecisionHandler (the mailed preview link, via ActionTokenEndpoint)
*       -> ApprovalNoticeJob::processNext() queues the approver email
*       -> ApprovalDecisionHandler (the emailed link) publishes or rejects
*
* Assertions are made against the resulting rows and queue state, and every
* message names its path, so a red run points at one route rather than at one
* opaque boolean.
*/
final class ConfirmationRoutingCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $suffix = bin2hex(random_bytes(5));
        $base = $wpdb->prefix . 'adct_pi_';
        $now = gmdate('Y-m-d H:i:s');
        $date = (new DateTimeImmutable('+5 days', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        $db = new WordPressDatabaseConnection();
        $clock = new SystemClock();
        $tokens = new ActionTokenService(new WordPressActionTokenStore($db), $clock);
        $resolver = new ApprovalRouteResolver(new ApprovalRouteRepository($db));
        $recipients = new ApprovalRecipients($resolver);
        $job = new ApprovalNoticeJob(
            $db,
            $recipients,
            $tokens,
            Plugin::mailer(),
            new WordPressMailQueueRepository($db),
            $clock
        );
        $approvalHandler = static fn (ActionTokenPurpose $purpose): ApprovalDecisionHandler
            => new ApprovalDecisionHandler(
            $purpose,
            $db,
            $recipients,
            Plugin::candidatePublisher(),
            Plugin::mailer(),
            $clock
        );
        $confirmationHandler = static fn (ActionTokenPurpose $purpose): ConfirmationDecisionHandler
            => new ConfirmationDecisionHandler(
            $purpose,
            $db,
            $resolver,
            Plugin::candidatePublisher(),
            $clock
        );
        $confirmHandler = $confirmationHandler(ActionTokenPurpose::CONFIRM);
        $denyHandler = $confirmationHandler(ActionTokenPurpose::DENY);
        $approveHandler = $approvalHandler(ActionTokenPurpose::APPROVE_EVENT);
        $registry = new ActionTokenHandlerRegistry([
                $confirmHandler,
                $denyHandler,
                $approveHandler,
                $approvalHandler(ActionTokenPurpose::REJECT_EVENT),
                new ApprovalEditHandler($db, $recipients, $clock),
        ]);
        $endpoint = new ActionTokenEndpoint(
            $tokens,
            $registry,
            new ActionTokenRenewalService(
                $tokens,
                new ActionTokenRateLimiter(
                    new WordPressActionTokenRateLimitStore($db),
                    $clock,
                    wp_salt('auth')
                ),
                new WordPressActionTokenRenewalDelivery(Plugin::mailer())
            )
        );

        // Collect every failure instead of aborting on the first one. The harness $fail is
        // WP_CLI::error(), which exits the whole script and would skip the finally block below,
        // leaking fixtures that break the checks that follow. Reporting at the end keeps the
        // cleanup intact and shows every broken routing path in a single run.
        $failures = [];
        $check = static function (bool $valid, string $message) use (&$failures): void {
            if (! $valid) {
                $failures[] = 'Confirmation routing: ' . $message;
            }
        };
        // Every clause of an assertion is named, so a red run reports which clause broke
        // instead of one opaque true/false for a twelve-clause check. Pass the observed value
        // for a clause that can fail and the message will quote it, so the report is enough to
        // diagnose the run without re-instrumenting the check.
        $render = static function (mixed $value): string {
            if ($value === null || is_scalar($value)) {
                return var_export($value, true);
            }
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: gettype($value);
        };
        $clauses = static function (string $message, array $conditions, array $observed = []) use ($check, $render): void {
            $broken = array_keys(array_filter($conditions, static fn (bool $ok): bool => ! $ok));

            if ($broken === []) {
                $check(true, $message);

                return;
            }

            $detail = array_map(
                static fn (string $name): string => array_key_exists($name, $observed)
                ? $name . ' = ' . $render($observed[$name])
                : $name,
                $broken
            );
            $check(false, $message . ' Failing: ' . implode(', ', $detail) . '.');
        };

        // A fixture that cannot be created leaves every later path meaningless, so stop there.
        // This throws rather than calling $fail on purpose: the finally block still runs, and the
        // exception carries the assertion failures collected so far.
        $insert = static function (string $table, array $fields) use ($wpdb, $base, &$failures): int {
            if ($wpdb->insert($base . $table, $fields) !== 1) {
                $failures[] = 'Confirmation routing: could not create an invented routing fixture:'
                    . $wpdb->last_error;
                throw new RuntimeException(implode("\n", $failures));
            }
            return (int) $wpdb->insert_id;
        };

        // Both handlers refuse to write while outbound Test mode is on, and Test mode also decides which
        // approvers may be notified. Force production mode with an empty allow-list so the routing under
        // test is not filtered, and put back whatever was there afterwards so a later check still sees
        // the state it expects.
        $oldMode = get_option(WordPressTestModeSettings::TEST_MODE_OPTION, WordPressTestModeSettings::MODE_DISABLED);
        $oldAllowlist = get_option(WordPressTestModeSettings::ALLOWLIST_OPTION, []);
        update_option(WordPressTestModeSettings::TEST_MODE_OPTION, WordPressTestModeSettings::MODE_DISABLED, false);
        update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, [], false);

        $users = [];
        $posts = [];
        $candidates = [];
        $messages = [];
        $contacts = [];
        $parishes = [];
        $deaneries = [];

        $makeUser = static function (string $label, string $role) use (&$users, $suffix, &$failures): array {
            $email = $label . '-' . $suffix . '@example.test';
            $id = wp_create_user($label . '-' . $suffix, wp_generate_password(28), $email);
            if (is_wp_error($id)) {
                $failures[] = 'Confirmation routing: could not create an invented routing user.';
                throw new RuntimeException(implode("\n", $failures));
            }
            (new WP_User($id))->set_role($role);
            $users[] = $id;
            return [(int) $id, $email];
        };

        /** One inbound notice, its deliverable confirmation queue row and one draft candidate. */
        $submit = static function (
            string $label,
            string $sender,
            ?int $parishId,
            string $matchKind = 'new',
            ?string $authResults = null
        ) use ($insert, &$candidates, &$messages, $suffix, $now, $date): int {
            $messageId = $insert('inbound_messages', [
                    'source_id' => 1,
                    'external_id' => 'routing-' . $label . '-' . $suffix,
                    'sender_email' => $sender,
                    'received_at' => $now,
                    'status' => 'parsed',
                    'confirmation_status' => 'sent',
                    'auth_results' => $authResults,
                    'created_at' => $now,
                    'updated_at' => $now,
            ]);
            $messages[] = $messageId;
            $insert('mail_queue', [
                    'recipient' => $sender,
                    'subject' => 'Please confirm the events in your notice',
                    'group_key' => 'confirmation:' . $messageId,
                    'status' => 'sent',
                    'priority' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
            ]);
            $id = $insert('event_candidates', [
                    'message_id' => $messageId,
                    'block_index' => 0,
                    'parish_id' => $parishId,
                    'fields' => wp_json_encode([
                            'title' => 'Example ' . $label,
                            'event_date' => $date,
                            'event_time' => '09:00',
                            'event_end_time' => '10:00',
                            'venue' => 'Example hall',
                            'description' => 'An invented public notice.',
                    ]),
                    'recurrence' => '{}',
                    'notes' => '[]',
                    'match_kind' => $matchKind,
                    'status' => 'draft',
                    'created_at' => $now,
                    'updated_at' => $now,
            ]);
            $candidates[] = $id;
            return $id;
        };

        $row = static function (int $id) use ($wpdb, $base): array {
            $found = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$base}event_candidates WHERE id = %d",
                    $id
                ), ARRAY_A);
            return is_array($found) ? $found : [];
        };
        $noticesFor = static function (int $id) use ($wpdb, $base): array {
            return array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
                        "SELECT recipient FROM {$base}approval_notices WHERE candidate_id = %d ORDER BY recipient ASC",
                        $id
            )));
        };
        $auditCount = static function (int $id, string $action) use ($wpdb, $base): int {
            return (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$base}audit_log WHERE subject_type = %s AND subject_id = %d"
                        . ' AND action = %s',
                    'event_candidate',
                    $id,
                    $action
            ));
        };
        /** The approver mail queued for a candidate, plus the action tokens it links to. */
        $approverMail = static function (int $id, string $email) use ($wpdb, $base, $tokens, &$failures): array {
            $mail = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$base}mail_queue WHERE recipient = %s AND group_key IN"
                        . " (SELECT group_key FROM {$base}approval_notices WHERE candidate_id = %d AND recipient = %s)"
                            . ' LIMIT 1',
                    $email,
                    $id,
                    $email
                ), ARRAY_A);
            if (! is_array($mail)) {
                $failures[] = 'Confirmation routing: no approver mail was queued for the invented routing'
                    . ' candidate.';
                throw new RuntimeException(implode("\n", $failures));
            }
            // The plain-text alternative is the one Outlook and the queue both render; the HTML
            // alternative is searched as well so neither body can hide a link.
            preg_match_all('/adct_token=([A-Za-z0-9_-]{43})/', (string) ($mail['body_text'] ?? ''), $plain);
            preg_match_all('/adct_token=([A-Za-z0-9_-]{43})/', (string) ($mail['body_html'] ?? ''), $html);
            // The mail also carries the event's public links, so every candidate is resolved
            // through the token store and only the approval ones are kept: the click being
            // tested has to be this approver's own approval button and nothing else.
            $links = [];
            foreach (array_unique(array_merge($plain[1], $html[1])) as $secret) {
                $inspection = $tokens->inspect((string) $secret);
                if ($inspection->binding !== null
                    && $inspection->binding->purpose === ActionTokenPurpose::APPROVE_EVENT
                    && $inspection->binding->subjectId === $id
                    && strcasecmp($inspection->binding->email, $email) === 0) {
                    $links[] = (string) $secret;
                }
            }
            return [$mail, $links];
        };
        $nonceFor = static function (object $get): string {
            preg_match('/name="adct_token_nonce" value="([^"]+)"/', $get->body, $nonce);
            return $nonce[1] ?? '';
        };
        // A refusal deliberately renders no form, so the nonce cannot always be scraped from the
        // refused page, and a spent link renders the renewal form whose nonce is for the 'renew'
        // action. The perform nonce is derived from the token rather than from the page, so the
        // scraped nonce is used only when it genuinely verifies for 'perform' and is otherwise
        // reproduced here. That way every refusal proved below is the handler's own answer and
        // never a nonce rejection.
        $press = static function (string $secret, string $reason = '') use ($endpoint, $nonceFor): array {
            $get = $endpoint->respond('GET', $secret, '', '', '', '203.0.113.51');
            $action = 'adct_pi_action_token_perform_' . hash('sha256', $secret);
            $scraped = $nonceFor($get);
            $nonce = $scraped !== '' && wp_verify_nonce($scraped, $action) !== false
            ? $scraped
            : wp_create_nonce($action);
            $post = $endpoint->respond(
                'POST',
                '',
                $secret,
                'perform',
                $nonce,
                '203.0.113.51',
                $reason
            );
            return [$get, $post];
        };
        /** Follows the mailed link exactly as the submitter's mail client would. */
        $confirm = static function (int $id, string $email) use ($tokens, $press): array {
            $secret = $tokens->issue(new ActionTokenBinding(
                    ActionTokenPurpose::CONFIRM,
                    'event_candidate',
                    $id,
                    $email
            ))->token();
            return $press($secret);
        };
        /** Clicks the mailed approval link end to end, as the approver's mail client would. */
        $approveFromMail = static function (int $id, string $email) use ($approverMail, $press): array {
            [, $links] = $approverMail($id, $email);
            return $press($links[0] ?? '');
        };
        /** How many published events point back at this candidate. */
        $post = static function (int $id) use ($wpdb): int {
            return (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                    'source_candidate_id',
                    (string) $id
            ));
        };
        /** How many events pointing back at this candidate are actually live, not trashed. */
        $livePost = static function (int $id) use ($wpdb): int {
            return (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id"
                        . ' WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status = %s',
                    'source_candidate_id',
                    (string) $id,
                    'publish'
            ));
        };

        // Test mode was already forced to production above, and the surrounding harness leaves it
        // that way for every check that follows, so nothing is re-enabled here.
        try {
            [$deanId, $deanEmail] = $makeUser('routing-dean', 'deanery_approver');
            [$secondDeanId, $secondDeanEmail] = $makeUser('routing-second-dean', 'deanery_approver');
            [$suspendedDeanId, $suspendedDeanEmail] = $makeUser('routing-suspended-dean', 'deanery_approver');
            // Suspension is applied with direct SQL: a user_status change made through wp_update_user
            // is undone by the harness's own later session switch, which would make the suspension
            // gates below pass or fail for reasons that have nothing to do with the routing code.
            $suspend = static function (int $userId, int $status) use ($wpdb): void {
                $wpdb->update($wpdb->users, ['user_status' => $status], ['ID' => $userId]);
                clean_user_cache($userId);
            };
            $suspend($suspendedDeanId, 1);
            [, $reviewerEmail] = $makeUser('routing-reviewer', 'adct_pi_intake_reviewer');
            [$foreignDeanId, $foreignDeanEmail] = $makeUser('routing-foreign-dean', 'deanery_approver');

            $makeDeanery = static function (string $label, string $status) use ($insert, &$deaneries, $suffix, $now): int {
                $id = $insert('deaneries', [
                        'name' => 'Example ' . $label,
                        'slug' => 'routing-' . $label . '-' . $suffix,
                        'status' => $status,
                        'created_at' => $now,
                        'updated_at' => $now,
                ]);
                $deaneries[] = $id;
                return $id;
            };
            $makeParish = static function (string $label, ?int $deaneryId) use (
                $insert,
                &$parishes,
                $suffix,
                $now
            ): int {
                $id = $insert('parishes', [
                        'name' => 'Example ' . $label,
                        'slug' => 'routing-' . $label . '-' . $suffix,
                        'deanery_id' => $deaneryId,
                        'created_at' => $now,
                        'updated_at' => $now,
                ]);
                $parishes[] = $id;
                return $id;
            };
            $deanery = $makeDeanery('routing deanery', 'active');
            $unassigned = $makeDeanery('unassigned deanery', 'active');
            $inactive = $makeDeanery('retired deanery', 'inactive');
            $unrelated = $makeDeanery('unrelated deanery', 'active');
            $parish = $makeParish('routing parish', $deanery);
            $independent = $makeParish('routing group', null);
            $unassignedParish = $makeParish('parish awaiting a dean', $unassigned);
            $inactiveParish = $makeParish('parish in a retired deanery', $inactive);

            $appoint = static function (int $deaneryId, int $userId, string $email)
            use ($insert, $now): void {
                $insert('deanery_approvers', [
                        'deanery_id' => $deaneryId,
                        'wp_user_id' => $userId,
                        'email' => $email,
                        'active' => 1,
                        'notify_mode' => 'each',
                        'created_at' => $now,
                        'updated_at' => $now,
                ]);
            };
            $appoint($deanery, $deanId, $deanEmail);
            $appoint($deanery, $secondDeanId, $secondDeanEmail);
            $appoint($deanery, $suspendedDeanId, $suspendedDeanEmail);
            $appoint($unrelated, $foreignDeanId, $foreignDeanEmail);
            $setActive = static function (int $userId, bool $active) use ($wpdb, $base): void {
                $wpdb->update(
                    $base . 'deanery_approvers',
                    ['active' => $active ? 1 : 0],
                    ['wp_user_id' => $userId]
                );
            };

            // The route resolution that every later path depends on.
            $deansRoute = $resolver->forParish($parish);
            $check($deansRoute->reason === ApprovalRoute::REASON_OK
                && $deansRoute->reviewersOnly === false
                && count($deansRoute->approvers) === 3,
            'an active deanery routes to every active appointment, suspended accounts included.');
            $independentRoute = $resolver->forParish($independent);
            $check($independentRoute->reason === ApprovalRoute::REASON_NO_DEANERY
                && $independentRoute->reviewersOnly === true
                && $independentRoute->approvers === [],
            'a parish with no deanery resolves to reviewers only.');
            $unassignedRoute = $resolver->forParish($unassignedParish);
            $check($unassignedRoute->reason === ApprovalRoute::REASON_NO_ACTIVE_APPROVER
                && $unassignedRoute->reviewersOnly === true
                && $unassignedRoute->approvers === [],
            'a deanery with no active approver resolves to reviewers only.');
            $inactiveRoute = $resolver->forParish($inactiveParish);
            $check($inactiveRoute->reason === ApprovalRoute::REASON_DEANERY_INACTIVE
                && $inactiveRoute->reviewersOnly === true
                && $inactiveRoute->approvers === [],
            'a deanery that is not active resolves to reviewers only.');
            $setActive($suspendedDeanId, false);
            $narrowed = $resolver->forParish($parish);
            $check($narrowed->reason === ApprovalRoute::REASON_OK
                && count($narrowed->approvers) === 2
                && ! in_array($suspendedDeanId, array_map(
                        static fn ($approver): int => $approver->wpUserId,
                        $narrowed->approvers
                    ), true),
            'a deactivated appointment drops out of the route but leaves the deanery routable.');
            $setActive($secondDeanId, false);
            $soleRoute = $resolver->forParish($parish);
            $check($soleRoute->reason === ApprovalRoute::REASON_OK
                && count($soleRoute->approvers) === 1
                && $soleRoute->approvers[0]->wpUserId === $deanId,
            'withdrawing every other appointment leaves the remaining dean as the only route.');
            $setActive($deanId, false);
            $orphanRoute = $resolver->forParish($parish);
            $check($orphanRoute->reason === ApprovalRoute::REASON_NO_ACTIVE_APPROVER
                && $orphanRoute->reviewersOnly === true
                && $orphanRoute->approvers === [],
            'a deanery whose only appointment is withdrawn falls back to reviewers only.');
            $setActive($deanId, true);
            $setActive($secondDeanId, true);
            $setActive($suspendedDeanId, true);
            $check(count($resolver->forParish($parish)->approvers) === 3,
            're-activating an appointment restores it to the route.');

            $submitter = 'routing-submitter-' . $suffix . '@example.test';

            // Path 1: a plain submitter confirms, so the parish dean and the archdiocese
            // reviewer are notified and the dean approves from the mailed link.
            // One of the three appointed deans is suspended, so the notices must carry only the
            // two active accounts; the suspension is lifted again once the notice has gone out.
            $suspend($suspendedDeanId, 1);
            $deanRouted = $submit('dean routed event', $submitter, $parish);
            [$confirmGet, $confirmPost] = $confirm($deanRouted, $submitter);
            $confirmed = $row($deanRouted);
            $check($confirmGet->statusCode === 200
                && $confirmPost->statusCode === 200
                && str_contains($confirmPost->body, 'your dean or the archdiocese will approve it shortly')
                && $confirmed['status'] === 'awaiting_approval'
                && $confirmed['confirmed_by'] === $submitter
                && $confirmed['approved_by'] === null
                && $confirmed['approved_via'] === null
                && $auditCount($deanRouted, 'submitter_confirmed') === 1,
            'Path 1: confirming a submitted event queues it for approval with no approver set.');
            $job->beginRun();
            $job->processNext((string) ($deanRouted - 1));
            $suspend($suspendedDeanId, 0);
            [$deanMail, $deanLinks] = $approverMail($deanRouted, $deanEmail);
            [$reviewerMail, $reviewerLinks] = $approverMail($deanRouted, $reviewerEmail);
            // Administering the site creates its own review-capable accounts, and every active
            // one is a notified reviewer, so the notices are asserted as a set that must contain
            // the routed approvers and exclude the out-of-scope ones, rather than as an exact list.
            $notifiedRecipients = $noticesFor($deanRouted);
            $clauses('Path 1: each active dean and the archdiocese reviewer get one approval link each.', [
                    'priority' => (int) $deanMail['priority'] === 2,
                    'dean group key' => $deanMail['group_key'] === 'approval:' . $deanRouted . ':'
                        . substr(hash('sha256', $deanEmail), 0, 24),
                    'reviewer group key' => $reviewerMail['group_key'] === 'approval:' . $deanRouted . ':'
                        . substr(hash('sha256', $reviewerEmail), 0, 24),
                    'parish named' => str_contains((string) $deanMail['body_text'], 'Parish: Example routing parish'),
                    'submitter named' => str_contains((string) $deanMail['body_text'], 'Submitted by: ' . $submitter),
                    'title named' => str_contains((string) $deanMail['body_text'], 'Example dean routed event'),
                    'one dean approval link' => count($deanLinks) === 1,
                    'one reviewer approval link' => count($reviewerLinks) === 1,
                    'no duplicate recipients' => count($notifiedRecipients) === count(array_unique($notifiedRecipients)),
                    'dean notified' => in_array($deanEmail, $notifiedRecipients, true),
                    'second dean notified' => in_array($secondDeanEmail, $notifiedRecipients, true),
                    'reviewer notified' => in_array($reviewerEmail, $notifiedRecipients, true),
                    'suspended dean not notified' => ! in_array($suspendedDeanEmail, $notifiedRecipients, true),
                    'foreign dean not notified' => ! in_array($foreignDeanEmail, $notifiedRecipients, true),
                ], $notifiedRecipients);
            [$deanGet, $deanApproval] = $press($deanLinks[0]);
            $deanDecided = $row($deanRouted);
            $posts[] = (int) ($deanDecided['match_event_id'] ?? 0);
            $clauses('Path 1: the dean\'s mailed approval publishes the event as approved_via = dean.', [
                    'preview opens' => $deanGet->statusCode === 200,
                    'preview asks for a decision' => str_contains($deanGet->body, 'Review event'),
                    'approval accepted' => $deanApproval->statusCode === 200,
                    'candidate published' => $deanDecided['status'] === 'published',
                    'approved via dean' => $deanDecided['approved_via'] === 'dean',
                    'approved by the dean' => $deanDecided['approved_by'] === $deanEmail,
                    'decided by the dean' => $deanDecided['decided_by'] === $deanEmail,
                    'event post is published' => get_post((int) $deanDecided['match_event_id'])?->post_status === 'publish',
                    'approval audited once' => $auditCount($deanRouted, 'approver_approved') === 1,
                    'published exactly once' => $post($deanRouted) === 1,
                    'submitter told it is live' => (bool) $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$base}mail_queue WHERE group_key = %s",
                            'approval-live:' . $deanRouted
                    )),
            ]);

            // Path 2: the reviewer's copy of the same event now shows the dean's decision.
            [$lateGet, $latePost] = $press($reviewerLinks[0]);
            $lateRow = $row($deanRouted);
            $clauses('Path 2: the reviewer\'s copy shows the dean\'s approval and cannot reverse it.', [
                    'preview opens' => $lateGet->statusCode === 200,
                    'titled as decided' => str_contains($lateGet->body, 'Event already decided'),
                    'names the deciding dean' => str_contains($lateGet->body, 'Already approved by ' . $deanEmail),
                    'press is refused' => $latePost->statusCode === 409,
                    'press body says why' => str_contains($latePost->body, 'Already approved by ' . $deanEmail),
                    'still published' => $lateRow['status'] === 'published',
                    'still approved via dean' => $lateRow['approved_via'] === 'dean',
                    'still approved by the dean' => $lateRow['approved_by'] === $deanEmail,
                    'only one approval recorded' => $auditCount($deanRouted, 'approver_approved') === 1,
                ], [
                    'press is refused' => $latePost->statusCode,
                    'press body says why' => $latePost->body,
            ]);

            // Path 3: a reviewer approves from their own mailed link.
            $reviewerRouted = $submit('reviewer routed event', $submitter, $parish);
            [, $confirmPost] = $confirm($reviewerRouted, $submitter);
            $job->beginRun();
            $job->processNext((string) ($reviewerRouted - 1));
            [, $reviewerApproval] = $approveFromMail($reviewerRouted, $reviewerEmail);
            $reviewerDecided = $row($reviewerRouted);
            $posts[] = (int) ($reviewerDecided['match_event_id'] ?? 0);
            $check($confirmPost->statusCode === 200
                && $reviewerApproval->statusCode === 200
                && $reviewerDecided['status'] === 'published'
                && $reviewerDecided['approved_via'] === 'reviewer'
                && $reviewerDecided['approved_by'] === $reviewerEmail
                && $reviewerDecided['decided_by'] === $reviewerEmail,
            'Path 3: an archdiocese reviewer approves from the mailed link as approved_via = reviewer.');

            // Path 4: a denial never reaches an approver, and the denial link is single-use.
            $denier = 'routing-denier-' . $suffix . '@example.test';
            $denied = $submit('denied event', $denier, $parish);
            $denySecret = $tokens->issue(new ActionTokenBinding(
                    ActionTokenPurpose::DENY,
                    'event_candidate',
                    $denied,
                    $denier
            ))->token();
            [$denyGet, $denyPost] = $press($denySecret, 'The date is wrong');
            [$denyReplayGet, $denyReplayPost] = $press($denySecret);
            $deniedRow = $row($denied);
            $clauses('Path 4: a denial is recorded once, cannot be replayed, and publishes nothing.', [
                    'preview opens' => $denyGet->statusCode === 200,
                    'titled as a denial' => str_contains($denyGet->body, 'Deny event'),
                    'denial accepted' => $denyPost->statusCode === 200,
                    'denial acknowledged' => str_contains($denyPost->body, 'Your decision has been recorded. Thank you.'),
                    'replay preview opens' => $denyReplayGet->statusCode === 200,
                    'replay is refused' => $denyReplayPost->statusCode === 409,
                    'replay press refuses the change' => str_contains(
                        $denyReplayPost->body,
                        'cannot be changed using this link'
                    ),
                    'status rejected' => $deniedRow['status'] === 'rejected',
                    'decider recorded' => $deniedRow['decided_by'] === $denier,
                    'reason recorded' => $deniedRow['decision_note'] === 'The date is wrong',
                    'never approved' => $deniedRow['approved_via'] === null,
                    'denial audited once' => $auditCount($denied, 'submitter_denied') === 1,
                    'nothing published' => $post($denied) === 0,
                ], [
                    'replay is refused' => $denyReplayPost->statusCode,
                    'replay press reports the decision' => $denyReplayPost->body,
            ]);
            $job->beginRun();
            $job->processNext((string) ($denied - 1));
            // A rejected candidate is not `awaiting_approval`, so the notice job never selects it.
            $check($noticesFor($denied) === [],
            'Path 4: a rejected candidate is never routed for approval.');

            // Path 5: an unknown sender is flagged for the approver and still routed.
            $stranger = 'routing-stranger-' . $suffix . '@example.test';
            $unknown = $submit('unknown sender event', $stranger, $parish);
            [, $confirmPost] = $confirm($unknown, $stranger);
            $unknownRow = $row($unknown);
            $unknownNotes = (array) json_decode((string) $unknownRow['notes'], true);
            $check($confirmPost->statusCode === 200
                && $unknownRow['status'] === 'awaiting_approval'
                && in_array('unknown_sender', $unknownNotes, true),
            'Path 5: an unrecognised sender is flagged on the candidate.');
            $job->beginRun();
            $job->processNext((string) ($unknown - 1));
            [$deanMail] = $approverMail($unknown, $deanEmail);
            $check(str_contains((string) $deanMail['body_text'],
                'WARNING: Unknown sender. Verify the parish before approving.'),
            'Path 5: the approver email carries the unknown-sender warning.');
            [, $deanApproval] = $approveFromMail($unknown, $deanEmail);
            $posts[] = (int) ($row($unknown)['match_event_id'] ?? 0);
            $check($deanApproval->statusCode === 200 && $row($unknown)['status'] === 'published',
            'Path 5: a flagged event is still approvable by the dean.');

            // Path 6: a reported DMARC failure is flagged and still routed.
            $spoofed = 'routing-spoofed-' . $suffix . '@example.test';
            $dmarc = $submit(
                'dmarc failed event',
                $spoofed,
                $parish,
                'new',
                (new AuthenticationResults([
                            new AuthenticationResult('dmarc', 'fail', 'mx.example.test', true),
                ]))->toJson()
            );
            [, $confirmPost] = $confirm($dmarc, $spoofed);
            $dmarcRow = $row($dmarc);
            $dmarcNotes = (array) json_decode((string) $dmarcRow['notes'], true);
            $check($confirmPost->statusCode === 200
                && $dmarcRow['status'] === 'awaiting_approval'
                && in_array('dmarc_fail', $dmarcNotes, true),
            'Path 6: a reported DMARC failure is flagged on the candidate.');
            $job->beginRun();
            $job->processNext((string) ($dmarc - 1));
            [$deanMail] = $approverMail($dmarc, $deanEmail);
            $check(str_contains((string) $deanMail['body_text'], 'WARNING: Reported DMARC failure.'),
            'Path 6: the approver email carries the DMARC-failure warning.');
            [, $deanApproval] = $approveFromMail($dmarc, $deanEmail);
            $posts[] = (int) ($row($dmarc)['match_event_id'] ?? 0);
            $check($deanApproval->statusCode === 200 && $row($dmarc)['status'] === 'published',
            'Path 6: a DMARC-flagged event is still approvable by the dean.');

            // Path 7: an address linked as blocked never self-approves, whichever parish it
            // belongs to, but remains a routed approver of its own deanery.
            $blockedId = $insert('parish_contacts', [
                    'parish_id' => $independent,
                    'email' => $deanEmail,
                    'display_name' => 'Example blocked contact',
                    'trust' => 'blocked',
                    'created_at' => $now,
                    'updated_at' => $now,
            ]);
            $contacts[] = $blockedId;
            $blocked = $submit('blocked contact event', $deanEmail, $parish);
            [, $confirmPost] = $confirm($blocked, $deanEmail);
            $blockedRow = $row($blocked);
            $check($confirmPost->statusCode === 200
                && str_contains($confirmPost->body, 'your dean or the archdiocese will approve it shortly')
                && $blockedRow['status'] === 'awaiting_approval'
                && $blockedRow['approved_via'] === null
                && $post($blocked) === 0,
            'Path 7: a blocked address cannot self-approve even while appointed dean of the parish.');
            $job->beginRun();
            $job->processNext((string) ($blocked - 1));
            [, $blockedApproval] = $approveFromMail($blocked, $deanEmail);
            $blockedDecided = $row($blocked);
            $posts[] = (int) ($blockedDecided['match_event_id'] ?? 0);
            $check($blockedApproval->statusCode === 200
                && $blockedDecided['status'] === 'published'
                && $blockedDecided['approved_via'] === 'dean',
            'Path 7: a blocked dean still receives their own approval link and publishes through it.');
            // The block applies to the address, not to the candidate, so it is lifted again before
            // the paths that must prove the same dean self-approves and self-publishes.
            $wpdb->delete($base . 'parish_contacts', ['id' => $blockedId]);

            // Path 8: the appointed dean is suspended rather than withdrawn. The appointment stays
            // active, so the resolver still lists him, which isolates the user_status gate:
            // ApprovalRecipients and isActiveApprover must both drop him.
            $suspend($suspendedDeanId, 1);
            $suspended = $submit('suspended dean event', $suspendedDeanEmail, $parish);
            [, $confirmPost] = $confirm($suspended, $suspendedDeanEmail);
            $suspendedRow = $row($suspended);
            $job->beginRun();
            $job->processNext((string) ($suspended - 1));
            $suspendedNotices = $noticesFor($suspended);
            $suspend($suspendedDeanId, 0);
            $clauses('Path 8: a suspended dean cannot self-approve and is skipped by the notice job.', [
                    'confirmation accepted' => $confirmPost->statusCode === 200,
                    'confirmation body says routed' => str_contains(
                        $confirmPost->body,
                        'your dean or the archdiocese will approve it shortly'
                    ),
                    'routed instead of published' => $suspendedRow['status'] === 'awaiting_approval',
                    'never self-approved' => $suspendedRow['approved_via'] === null,
                    'never self-approved by' => $suspendedRow['approved_by'] === null,
                    'nothing published' => $post($suspended) === 0,
                    'suspended dean not notified' => ! in_array($suspendedDeanEmail, $suspendedNotices, true),
                    'active dean notified' => in_array($deanEmail, $suspendedNotices, true),
                ], [
                    'routed instead of published' => $suspendedRow['status'],
                    'never self-approved' => $suspendedRow['approved_via'],
                    'never self-approved by' => $suspendedRow['approved_by'],
                    'confirmation body says routed' => $confirmPost->body,
                    'suspended dean not notified' => $suspendedNotices,
            ]);

            // Path 9: a deanery with no active approver is still approved, by a reviewer.
            $awaitingDean = 'routing-awaiting-' . $suffix . '@example.test';
            $noApprover = $submit('unassigned deanery event', $awaitingDean, $unassignedParish);
            [, $confirmPost] = $confirm($noApprover, $awaitingDean);
            $job->beginRun();
            $job->processNext((string) ($noApprover - 1));
            $noApproverNotices = $noticesFor($noApprover);
            $check($confirmPost->statusCode === 200
                && in_array($reviewerEmail, $noApproverNotices, true)
                && ! in_array($deanEmail, $noApproverNotices, true),
            'Path 9: a deanery with no active approver notifies the archdiocese reviewer only.');
            [, $reviewerApproval] = $approveFromMail($noApprover, $reviewerEmail);
            $noApproverRow = $row($noApprover);
            $posts[] = (int) ($noApproverRow['match_event_id'] ?? 0);
            $check($reviewerApproval->statusCode === 200
                && $noApproverRow['status'] === 'published'
                && $noApproverRow['approved_via'] === 'reviewer',
            'Path 9: an archdiocese reviewer approves a parish whose deanery has no dean.');

            // Path 10: a parish with no deanery reaches reviewers only, and even a valid-looking
            // approval link handed to a dean of another deanery cannot decide the event.
            $deanlessSender = 'routing-deanless-' . $suffix . '@example.test';
            $deanless = $submit('deanless parish event', $deanlessSender, $independent);
            [, $confirmPost] = $confirm($deanless, $deanlessSender);
            $job->beginRun();
            $job->processNext((string) ($deanless - 1));
            $deanlessNotices = $noticesFor($deanless);
            $check($confirmPost->statusCode === 200
                && in_array($reviewerEmail, $deanlessNotices, true)
                && ! in_array($deanEmail, $deanlessNotices, true),
            'Path 10: a parish without a deanery notifies the archdiocese reviewer only.');
            $foreignBinding = new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'event_candidate',
                $deanless,
                $foreignDeanEmail
            );
            $foreignSecret = $tokens->issue($foreignBinding)->token();
            // No notice was ever mailed for this token, so ApprovalDecisionHandler::deliverable()
            // has nothing to prove it against and the handler refuses it before any routing check.
            // That is exactly the guarantee a dean of another deanery gets from a hand-issued
            // link: the click cannot decide the event and the token is not spent, so the rightful
            // approver's own notice is unaffected. The endpoint never reaches the handler, because
            // it answers an un-previewable link with the invalid-link page before pressing anything.
            [$foreignOpen, $foreignApproval] = $press($foreignSecret);
            $foreignDirect = null;
            try {
                $approveHandler->performAtomic(
                    $foreignBinding,
                    $foreignSecret,
                    $tokens,
                    ''
                );
            } catch (DomainException $refusal) {
                $foreignDirect = $refusal->getMessage();
            }
            $foreignRow = $row($deanless);
            $clauses('Path 10: a dean of another deanery cannot approve, and his unused link stays valid.', [
                    'link never opens' => $foreignOpen->statusCode === 200
                    && str_contains($foreignOpen->body, 'This link is not valid'),
                    'click refused' => $foreignApproval->statusCode === 200
                    && str_contains((string) $foreignApproval->body, 'This link is not valid'),
                    'the handler refuses it too' => $foreignDirect === 'This approver is no longer assigned.',
                    'token still valid' => $tokens->inspect($foreignSecret, $foreignBinding)->status
                    === ActionTokenStatus::VALID,
                    'still awaiting approval' => $foreignRow['status'] === 'awaiting_approval',
                    'nobody approved it' => $foreignRow['approved_by'] === null,
                    'no approval audited' => $auditCount($deanless, 'approver_approved') === 0,
                ], [
                    'link never opens' => $foreignOpen->statusCode,
                    'click refused' => $foreignApproval->statusCode,
                    'the handler refuses it too' => $foreignDirect,
            ]);
            [, $reviewerApproval] = $approveFromMail($deanless, $reviewerEmail);
            $posts[] = (int) ($row($deanless)['match_event_id'] ?? 0);
            $check($reviewerApproval->statusCode === 200 && $row($deanless)['status'] === 'published',
            'Path 10: the archdiocese reviewer still approves the deanless parish.');

            // Path 11: a deanery that is not active falls back to reviewers only.
            $retiredSender = 'routing-retired-' . $suffix . '@example.test';
            $retired = $submit('inactive deanery event', $retiredSender, $inactiveParish);
            [, $confirmPost] = $confirm($retired, $retiredSender);
            $job->beginRun();
            $job->processNext((string) ($retired - 1));
            $retiredNotices = $noticesFor($retired);
            $check($confirmPost->statusCode === 200
                && in_array($reviewerEmail, $retiredNotices, true)
                && ! in_array($deanEmail, $retiredNotices, true),
            'Path 11: a parish in a deanery that is not active notifies reviewers only.');
            [, $reviewerApproval] = $approveFromMail($retired, $reviewerEmail);
            $retiredRow = $row($retired);
            $posts[] = (int) ($retiredRow['match_event_id'] ?? 0);
            $check($reviewerApproval->statusCode === 200
                && $retiredRow['status'] === 'published'
                && $retiredRow['approved_via'] === 'reviewer',
            'Path 11: a reviewer approves a candidate whose deanery is not active.');

            // Path 12: a candidate that may update an already published event never self-publishes.
            // ApprovalNoticeJob only mails match_kind = 'new' (see its processNext query), and
            // ApprovalDecisionHandler refuses to approve anything that is not match_kind = 'new',
            // so a matched update deliberately stops at awaiting_approval for a human to handle
            // through the review queue. The match is recorded the way the matcher records it:
            // match_kind 'update' with a resolved match_event_id.
            $existing = $submit('existing published event', $submitter, $parish);
            $confirm($existing, $submitter);
            $job->beginRun();
            $job->processNext((string) ($existing - 1));
            [, $existingApproval] = $approveFromMail($existing, $deanEmail);
            $existingRow = $row($existing);
            $existingEvent = (int) ($existingRow['match_event_id'] ?? 0);
            $posts[] = $existingEvent;
            $updater = 'routing-updater-' . $suffix . '@example.test';
            $update = $submit('parish update event', $updater, $parish, 'update');
            $wpdb->update(
                $base . 'event_candidates',
                ['match_event_id' => $existingEvent],
                ['id' => $update]
            );
            [, $confirmPost] = $confirm($update, $updater);
            $updateRow = $row($update);
            $job->beginRun();
            $job->processNext((string) ($update - 1));
            $updateNotices = $noticesFor($update);
            $clauses('Path 12: a matched update is held for human review and never self-published.', [
                    'existing event approved' => $existingApproval->statusCode === 200
                    && $existingRow['status'] === 'published',
                    'confirmation accepted' => $confirmPost->statusCode === 200,
                    'routed for approval' => $updateRow['status'] === 'awaiting_approval',
                    'never self-approved' => $updateRow['approved_via'] === null,
                    'never self-approved by' => $updateRow['approved_by'] === null,
                    'nothing published' => $post($update) === 0,
                    'target event untouched' => get_post_status($existingEvent) === 'publish',
                    'no approval mail queued' => $updateNotices === [],
                ], [
                    'routed for approval' => [$updateRow['match_kind'], $updateRow['match_event_id']],
                    'no approval mail queued' => $updateNotices,
                    'target event untouched' => get_post_status($existingEvent),
            ]);

            // Path 13: the appointed dean self-approves and nobody is notified.
            $deanOwn = $submit('dean own event', $deanEmail, $parish);
            [, $confirmPost] = $confirm($deanOwn, $deanEmail);
            $deanOwnRow = $row($deanOwn);
            $posts[] = (int) ($deanOwnRow['match_event_id'] ?? 0);
            $clauses('Path 13: the appointed dean\'s own submission publishes once with approved_via = self.', [
                    'confirmation accepted' => $confirmPost->statusCode === 200,
                    'links to the event' => str_contains($confirmPost->body, 'View published event'),
                    'self-approval acknowledged' => str_contains(
                        $confirmPost->body,
                        'Thank you. Your event has been approved and published.'
                    ),
                    'published' => $deanOwnRow['status'] === 'published',
                    'approved via self' => $deanOwnRow['approved_via'] === 'self',
                    'approved by the submitting dean' => $deanOwnRow['approved_by'] === $deanEmail,
                    'confirmed by the submitting dean' => $deanOwnRow['confirmed_by'] === $deanEmail,
                    'not decided by an approver' => $deanOwnRow['decided_by'] === null,
                    'published exactly once' => $post($deanOwn) === 1,
                ], [
                    'links to the event' => $confirmPost->body,
                    'self-approval acknowledged' => $deanOwnRow['status'],
                    'published' => $deanOwnRow['approved_via'],
                    'approved via self' => $deanOwnRow['approved_by'],
                    'approved by the submitting dean' => $deanOwnRow['confirmed_by'],
                    'published exactly once' => $post($deanOwn),
            ]);
            $job->beginRun();
            $job->processNext((string) ($deanOwn - 1));
            // It is never published, so it is never selected for notification either.
            $check($noticesFor($deanOwn) === [],
            'Path 13: a self-approved submission is never queued for another approver.');

            // Path 14: the interrupted self-approval that recover() exists to finish, then the
            // recovery itself, then the refusal once there is nothing left to recover.
            $recoverable = $submit('recoverable dean event', $deanEmail, $parish);
            $recoverBinding = new ActionTokenBinding(
                ActionTokenPurpose::CONFIRM,
                'event_candidate',
                $recoverable,
                $deanEmail
            );
            $recoverSecret = $tokens->issue($recoverBinding)->token();
            [, $interrupted] = $press($recoverSecret);
            $interruptedRow = $row($recoverable);
            $interruptedEvent = (int) ($interruptedRow['match_event_id'] ?? 0);
            $posts[] = $interruptedEvent;
            // recover() only republishes a candidate still sitting at awaiting_approval
            // with approved_via = 'self', so the decision is interrupted after it has
            // committed by returning the candidate to that state and deleting the event it
            // had published. That is the half-finished state recover() exists to finish,
            // and the spent token is what a later press would present. The endpoint itself
            // never reaches it, because a spent confirmation token is no longer bound to a
            // candidate, so recover() is exercised directly here.
            $wpdb->update(
                $base . 'event_candidates',
                ['status' => 'awaiting_approval', 'match_event_id' => null],
                ['id' => $recoverable]
            );
            clean_post_cache($interruptedEvent);
            $wpdb->update(
                $wpdb->posts,
                ['post_status' => 'trash'],
                ['ID' => $interruptedEvent]
            );
            clean_post_cache($interruptedEvent);
            $clauses('Path 14: an approval interrupted after it committed is left awaiting_approval.', [
                    'first press published' => $interrupted->statusCode === 200,
                    'token is spent' => $tokens->inspect($recoverSecret)->status === ActionTokenStatus::USED,
                    'left awaiting approval' => $row($recoverable)['status'] === 'awaiting_approval',
                    'event really is missing' => get_post_status($interruptedEvent) === 'trash',
                    'no live event points back at it' => $livePost($recoverable) === 0,
                ], [
                    'first press published' => $interrupted->statusCode,
                    'left awaiting approval' => $row($recoverable)['status'],
                    'event really is missing' => get_post_status($interruptedEvent),
                    'no live event points back at it' => $livePost($recoverable),
            ]);
            $recovered = null;
            $recoveryFailure = null;
            try {
                $recovered = $confirmHandler->recover($recoverBinding);
            } catch (DomainException | RuntimeException $failure) {
                $recoveryFailure = $failure->getMessage();
            }
            $recoveredRow = $row($recoverable);
            $recoveredEvent = (int) ($recoveredRow['match_event_id'] ?? 0);
            $posts[] = $recoveredEvent;
            $clauses('Path 14: recovery republishes the interrupted self-approval exactly once.', [
                    'recovery succeeds' => $recovered !== null && $recoveryFailure === null,
                    'recovery reports the outcome' => $recovered?->message
                    === 'Thank you. Your event has been approved and published.',
                    'links to the recovered event' => count($recovered?->eventUrls ?? []) === 1
                    && ($recovered?->eventUrls[0] ?? '') === get_permalink($recoveredEvent),
                    'still approved via self' => $recoveredRow['approved_via'] === 'self',
                    'approved by the same dean' => $recoveredRow['approved_by'] === $deanEmail,
                    'event is published again' => get_post_status($recoveredEvent) === 'publish',
                    'one live event points back at it' => $livePost($recoverable) === 1,
                ], [
                    'recovery succeeds' => $recoveryFailure ?? 'ok',
                    'recovery reports the outcome' => $recovered?->message ?? '(refused)',
                    'links to the recovered event' => $recovered?->eventUrls ?? [],
                    'event is published again' => get_post_status($recoveredEvent),
                    'still approved via self' => $recoveredRow['approved_via'],
                    'one live event points back at it' => $livePost($recoverable),
            ]);
            $recoveredTwice = null;
            try {
                $confirmHandler->recover($recoverBinding);
            } catch (DomainException $refusal) {
                $recoveredTwice = $refusal->getMessage();
            }
            $clauses('Path 14: a second recovery is refused rather than publishing again.', [
                    'second recovery refused' => $recoveredTwice
                    === 'This confirmation has already been completed.',
                    'still only one live event' => $livePost($recoverable) === 1,
                ], [
                    'second recovery refused' => $recoveredTwice,
                    'still only one live event' => $livePost($recoverable),
            ]);

            // Path 15: a second appointed dean clicking their own mailed link records the decision.
            $contestedSender = 'routing-contested-' . $suffix . '@example.test';
            $contested = $submit('contested event', $contestedSender, $parish);
            [, $confirmPost] = $confirm($contested, $contestedSender);
            $job->beginRun();
            $job->processNext((string) ($contested - 1));
            [, $secondDeanLinks] = $approverMail($contested, $secondDeanEmail);
            [, $firstApproval] = $press($secondDeanLinks[0]);
            $contestedRow = $row($contested);
            $posts[] = (int) ($contestedRow['match_event_id'] ?? 0);
            $clauses('Path 15: the second appointed dean can approve the event through their own link.', [
                    'confirmation accepted' => $confirmPost->statusCode === 200,
                    'approval accepted' => $firstApproval->statusCode === 200,
                    'candidate published' => $contestedRow['status'] === 'published',
                    'approved via dean' => $contestedRow['approved_via'] === 'dean',
                    'approved by the second dean' => $contestedRow['approved_by'] === $secondDeanEmail,
                    'decided by the second dean' => $contestedRow['decided_by'] === $secondDeanEmail,
                    'published exactly once' => $post($contested) === 1,
            ]);
            // Replaying the same spent link is safe: the endpoint lets the deciding dean
            // press again so a lost reply still republishes idempotently, and the handler
            // refuses anyone else. Neither can record a second approval.
            [$secondGet, $secondPost] = $press($secondDeanLinks[0]);
            $secondRival = null;
            try {
                $approveHandler->performAtomic(
                    new ActionTokenBinding(
                        ActionTokenPurpose::APPROVE_EVENT,
                        'event_candidate',
                        $contested,
                        $reviewerEmail
                    ),
                    $secondDeanLinks[0],
                    $tokens,
                    ''
                );
            } catch (DomainException $refusal) {
                $secondRival = $refusal->getMessage();
            }
            $clauses('Path 15: replaying that link shows the recorded approval and records nothing further.', [
                    'replay preview opens' => $secondGet->statusCode === 200,
                    'replay names the deciding dean' => str_contains(
                        $secondGet->body,
                        'Already approved by ' . $secondDeanEmail
                    ),
                    'replay press is accepted' => $secondPost->statusCode === 200,
                    'another approver is refused' => $secondRival !== null
                    && str_starts_with((string) $secondRival, 'Already approved by '),
                    'still approved by that dean' => $row($contested)['approved_by'] === $secondDeanEmail,
                    'approval still audited once' => $auditCount($contested, 'approver_approved') === 1,
                    'still published exactly once' => $post($contested) === 1,
                ], [
                    'replay preview opens' => $secondGet->statusCode,
                    'replay names the deciding dean' => str_contains(
                        $secondGet->body,
                        'Already approved by ' . $secondDeanEmail
                    ),
                    'replay press is accepted' => $secondPost->statusCode,
                    'another approver is refused' => $secondRival,
            ]);

            // Path 16: an approver withdrawn between notice and click can no longer decide.
            $removedSender = 'routing-removed-' . $suffix . '@example.test';
            $removed = $submit('removed approver event', $removedSender, $parish);
            [, $confirmPost] = $confirm($removed, $removedSender);
            // The notice is queued, and the link is opened to prove it was live, all
            // while the dean is still appointed. The appointment is withdrawn only then,
            // so the click that follows is exactly the stale one that must be refused.
            $job->beginRun();
            $job->processNext((string) ($removed - 1));
            [$removedMail, $removedLinks] = $approverMail($removed, $deanEmail);
            $removedSecret = $removedLinks[0] ?? '';
            $removedOpen = $endpoint->respond('GET', $removedSecret, '', '', '', '203.0.113.51');
            $setActive($deanId, false);
            [$staleOpen, $stalePost] = $press($removedSecret);
            $withdrawnDirectly = null;
            try {
                $approveHandler->performAtomic(
                    new ActionTokenBinding(
                        ActionTokenPurpose::APPROVE_EVENT,
                        'event_candidate',
                        $removed,
                        $deanEmail
                    ),
                    $removedSecret,
                    $tokens,
                    ''
                );
            } catch (DomainException $refusal) {
                $withdrawnDirectly = $refusal->getMessage();
            }
            $setActive($deanId, true);
            $removedRow = $row($removed);
            $clauses('Path 16: a dean withdrawn between the notice and the click cannot decide the event.', [
                    'confirmation accepted' => $confirmPost->statusCode === 200,
                    'notice queued while appointed' => (int) $removedMail['priority'] === 2,
                    'an approval link was mailed' => $removedSecret !== '',
                    'link opened while appointed' => $removedOpen->statusCode === 200,
                    'link is dead after the withdrawal' => $staleOpen->statusCode === 200
                    && str_contains((string) $staleOpen->body, 'This link is not valid'),
                    'press after the withdrawal is dead too' => $stalePost->statusCode === 200
                    && str_contains((string) $stalePost->body, 'This link is not valid'),
                    'the handler refuses the withdrawal' => $withdrawnDirectly
                    === 'This approver is no longer assigned.',
                    'token not spent' => $tokens->inspect($removedSecret)->status === ActionTokenStatus::VALID,
                    'still awaiting approval' => $removedRow['status'] === 'awaiting_approval',
                    'nobody approved it' => $removedRow['approved_by'] === null,
                    'no approval audited' => $auditCount($removed, 'approver_approved') === 0,
                    'nothing published' => $post($removed) === 0,
                ], [
                    'link opened while appointed' => $removedOpen->statusCode,
                    'link is dead after the withdrawal' => $staleOpen->statusCode,
                    'press after the withdrawal is dead too' => $stalePost->statusCode,
                    'the handler refuses the withdrawal' => $withdrawnDirectly,
            ]);

            // Path 17: a denial link can never recover a self-approval.
            $denyRecoverMessage = null;
            try {
                $denyHandler->recover(new ActionTokenBinding(
                        ActionTokenPurpose::DENY,
                        'event_candidate',
                        $removed,
                        $removedSender
                ));
            } catch (DomainException $refusal) {
                $denyRecoverMessage = $refusal->getMessage();
            }
            $check($denyRecoverMessage === 'There is no self-approval to complete.',
            'Path 17: a denial link refuses to recover anything.');

            // Path 18: a confirmation that never self-approved reports that it is complete.
            $completedMessage = null;
            try {
                $confirmHandler->recover(new ActionTokenBinding(
                        ActionTokenPurpose::CONFIRM,
                        'event_candidate',
                        $removed,
                        $removedSender
                ));
            } catch (DomainException $refusal) {
                $completedMessage = $refusal->getMessage();
            }
            $check($completedMessage === 'This confirmation has already been completed.',
            'Path 18: recovering a confirmation that routed to a dean reports it as already complete.');
        } finally {
            update_option(WordPressTestModeSettings::TEST_MODE_OPTION, $oldMode);
            update_option(WordPressTestModeSettings::ALLOWLIST_OPTION, $oldAllowlist);
            foreach ($posts as $id) {
                if ($id > 0) {
                    wp_delete_post($id, true);
                    $wpdb->delete($base . 'occurrences', ['event_id' => $id]);
                }
            }
            foreach ($contacts as $id) {
                $wpdb->delete($base . 'parish_contacts', ['id' => $id]);
            }
            foreach ($candidates as $id) {
                $groups = (array) $wpdb->get_col($wpdb->prepare(
                        "SELECT group_key FROM {$base}approval_notices WHERE candidate_id = %d",
                        $id
                ));
                $groups[] = 'approval-live:' . $id;
                $groups[] = 'approval-rejected:' . $id;
                foreach (array_unique($groups) as $group) {
                    $wpdb->delete($base . 'mail_queue', ['group_key' => $group]);
                }
                $wpdb->delete($base . 'approval_notices', ['candidate_id' => $id]);
                $wpdb->delete($base . 'action_tokens', [
                        'subject_type' => 'event_candidate',
                        'subject_id' => $id,
                ]);
                $wpdb->delete($base . 'audit_log', ['subject_type' => 'event_candidate', 'subject_id' => $id]);
                $wpdb->delete($base . 'event_candidates', ['id' => $id]);
            }
            foreach ($messages as $id) {
                $wpdb->delete($base . 'mail_queue', ['group_key' => 'confirmation:' . $id]);
                $wpdb->delete($base . 'action_tokens', [
                        'subject_type' => 'inbound_message',
                        'subject_id' => $id,
                ]);
                $wpdb->delete($base . 'inbound_messages', ['id' => $id]);
            }
            foreach ($deaneries as $id) {
                $wpdb->delete($base . 'deanery_approvers', ['deanery_id' => $id]);
            }
            foreach ($parishes as $id) {
                $wpdb->delete($base . 'parishes', ['id' => $id]);
            }
            foreach ($deaneries as $id) {
                $wpdb->delete($base . 'deaneries', ['id' => $id]);
            }
            foreach ($users as $id) {
                wp_delete_user($id);
            }
        }

        // Reported once the fixtures have been removed, so a red run still tidies up and
        // lists every broken route instead of only the first.
        if ($failures !== []) {
            $fail(implode("\n", $failures));
        }
    }
}
