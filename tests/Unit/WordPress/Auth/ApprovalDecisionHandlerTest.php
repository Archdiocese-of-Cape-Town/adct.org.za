<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';

    if (! function_exists('get_permalink')) {
            // WordPress takes a post object as well as an id. `PublicEventPage::jsonLd()`
            // passes one, and `phpunit.xml.dist` has no bootstrap, so whichever of the
            // two declarations loads first has to be the union.
            function get_permalink($post = 0, bool $leavename = false): string|false
            {
                $postId = $post instanceof WP_Post ? $post->ID : (int) $post;

                return 'https://adct.org.za/events/event-' . $postId . '/';
            }
        }

    if (! function_exists('esc_html')) {
        function esc_html(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('esc_url')) {
        function esc_url(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}

/**
 * No Approval stubs live here. tests/Support/WordPressStubs.php already declares
 * get_userdata(), user_can(), get_users() and get_user_meta() in
 * ADCT\ParishIntake\WordPress\Approval, and a second declaration in the same
 * namespace is a fatal, not a shadow. This harness drives that copy through the
 * same globals it reads, so there is one WordPress stand-in for the whole suite
 * and no test order dependency.
 */

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
    use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
    use ADCT\ParishIntake\Core\Approval\Approver;
    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
    use ADCT\ParishIntake\Core\Auth\ActionTokenService;
    use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Events\EventValidator;
    use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
    use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
    use ADCT\ParishIntake\Core\Mail\MailPriority;
    use ADCT\ParishIntake\Core\Mail\OutboundEmail;
    use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
    use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\MailerInterface;
    use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
    use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
    use ADCT\ParishIntake\Core\Publishing\Publication;
    use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
    use ADCT\ParishIntake\WordPress\Auth\ApprovalDecisionHandler;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use DateTimeImmutable;
    use DateTimeZone;
    use DomainException;
    use LogicException;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use WP_User;

    /**
     * #171: an approval link is addressed to a dean or an archdiocese reviewer,
     * but the endpoint cannot tell whether the person who opens it is still the
     * person who was written to, and the token carries no authority of its own.
     *
     * The guard that prevents an ex-approver from publishing an event is
     * `$this->role($row, $binding) === null`, and role() deliberately re-resolves
     * the recipient's entitlement *live* on every call through
     * ApprovalRecipients::roleFor(), which re-reads the WordPress account, the
     * account status, the capability and the deanery route. That live
     * re-resolution is the entire security property, and nothing in the codebase
     * asserts it: every existing test exercised the happy path with a recipient
     * who was still entitled, so memoising forParish() — or dropping the check
     * from any one entry point — would break the live site and pass every test
     * in the suite.
     *
     * These tests pin it from both directions. Each revocation test mints a
     * token while the recipient *is* entitled, removes the entitlement, and then
     * asserts three things about every guarded entry point as one group: the act
     * is refused, nothing was written, and the token is still VALID. That last
     * one is what makes a refusal safe — a mistyped click by someone who still
     * holds the parish must not burn the link that the rightful approver has.
     *
     * Every revocation test has a counterpart showing that authority which was
     * NOT revoked still works. Without the counterpart a refusal test passes
     * just as happily on a handler that refuses everything, which is the other
     * way this seam could break.
     */
    final class ApprovalDecisionHandlerTest extends TestCase
    {
        private const DEAN = 'dean@example.test';
        private const REVIEWER = 'reviewer@example.test';
        private const SUBMITTER = 'parish@example.test';

        private const DEAN_USER_ID = 101;
        private const REVIEWER_USER_ID = 202;

        /** The parish whose event this is. */
        private const PARISH_ID = 11;

        /** A second deanery, so "moved off the deanery" has something to take away. */
        private const OTHER_PARISH_ID = 12;

        private const CANDIDATE_ID = 7;
        private const MESSAGE_ID = 55;
        private const EVENT_ID = 34;

        /** Fixed so token lifetimes and the audit stamp are never read from "now". */
        private const NOW = '2026-09-25 08:00:00';

        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['adct_test_wp_users'] = [
                self::DEAN_USER_ID => new WP_User(self::DEAN_USER_ID, self::DEAN),
                self::REVIEWER_USER_ID => new WP_User(self::REVIEWER_USER_ID, self::REVIEWER),
            ];
            $GLOBALS['adct_test_wp_caps'] = [
                self::DEAN_USER_ID => [Capabilities::APPROVE_DEANERY],
                self::REVIEWER_USER_ID => [Capabilities::REVIEW],
            ];
            $GLOBALS['adct_test_wp_meta'] = [];
        }

        protected function tearDown(): void
        {
            foreach (['adct_test_wp_users', 'adct_test_wp_caps', 'adct_test_wp_meta'] as $key) {
                unset($GLOBALS[$key]);
            }

            parent::tearDown();
        }

        public function testTheHandlerOnlyAcceptsAnApprovalOrRejectionPurpose(): void
        {
            self::assertSame(
                ActionTokenPurpose::APPROVE_EVENT,
                $this->approve()->purpose()
            );
            self::assertSame(
                ActionTokenPurpose::REJECT_EVENT,
                $this->reject()->purpose()
            );

            // Every other purpose in the enum must be refused at construction,
            // so an endpoint can never wire this handler to a non-approval action.
            foreach (ActionTokenPurpose::cases() as $purpose) {
                if (in_array($purpose, [ActionTokenPurpose::APPROVE_EVENT, ActionTokenPurpose::REJECT_EVENT], true)) {
                    continue;
                }

                try {
                                    $this->handler($purpose);
                    self::fail('A decision handler must not accept ' . $purpose->name . '.');
                } catch (LogicException $refused) {
                    self::assertStringContainsString('approval or rejection', $refused->getMessage());
                }
            }
        }

        /**
         * The heart of #171. The notice went out while the dean covered this
         * parish; moving them to another deanery revokes the entitlement. All
         * three guarded entry points must answer the live question.
         */
        public function testAStillEntitledDeanMayApproveSoTheRevocationTestsBelowCannotPassVacuously(): void
        {
            $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $binding = $this->approvalBinding();
            $token = $tokens->issue($binding)->token();

            $database = $this->database();
            $handler = $this->approve(approvalDatabase: $database);
            $mailer = new RecordingDecisionMailer();
            $store = new RecordingDecisionPublicationStore($database);

            $outcome = $this->approve(
                approvalDatabase: $database,
                approvalMailer: $mailer,
                approvalStore: $store
            )->performAtomic($binding, $token, $tokens, '');

            self::assertNotNull($handler->preview($binding), 'An entitled dean must be offered the decision.');
            self::assertStringContainsString('approved and published', $outcome->message);
            self::assertSame(
                ActionTokenStatus::USED,
                $tokens->inspect($token)->status,
                'A decision that succeeds must burn its single-use token.'
            );
        }

        /**
         * Revocation #1: the dean is moved off this deanery. Every guarded entry
         * point is covered as one group, because a refactor that dropped the
         * check from a single one of them would leave the other two green.
         */
        public function testADeanMovedOffTheDeaneryIsRefusedOnEveryGuardedEntryPointAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: function (): void {
                    // The dean keeps their account and their capability but now
                    // covers only another deanery.
                },
                heldParishIds: [self::OTHER_PARISH_ID],
                reason: 'moved off the deanery'
            );
        }

        /**
         * Revocation #2: the WordPress account is deactivated. The route still
         * names them and the capability is untouched, so only a live read of
         * user_status catches this.
         */
        public function testADeanWithADeactivatedAccountIsRefusedOnEveryGuardedEntryPointAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: function (): void {
                    $GLOBALS['adct_test_wp_users'][self::DEAN_USER_ID]->user_status = 1;
                },
                heldParishIds: [self::PARISH_ID],
                reason: 'deactivated account'
            );
        }

        /**
         * Revocation #3: the capability is stripped while the route still names
         * them. Same route, same account, no authority.
         */
        public function testADeanWhoseCapabilityIsStrippedIsRefusedOnEveryGuardedEntryPointAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: function (): void {
                    $GLOBALS['adct_test_wp_caps'][self::DEAN_USER_ID] = [];
                },
                heldParishIds: [self::PARISH_ID],
                reason: 'stripped capability'
            );
        }

        /**
         * Reviewers hold authority archdiocese-wide, so losing a deanery is not
         * what would stop one. Their revocable entitlements are the account and
         * the REVIEW capability, and both must be read live.
         */
        public function testAReviewerWhoseReviewCapabilityIsStrippedIsRefusedOnEveryGuardedEntryPointAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: function (): void {
                    $GLOBALS['adct_test_wp_caps'][self::REVIEWER_USER_ID] = [];
                },
                heldParishIds: [self::PARISH_ID],
                recipient: self::REVIEWER,
                purpose: ActionTokenPurpose::APPROVE_EVENT,
                reason: 'stripped review capability'
            );
        }

        public function testAReviewerWithADeactivatedAccountIsRefusedOnEveryGuardedEntryPointAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: function (): void {
                    $GLOBALS['adct_test_wp_users'][self::REVIEWER_USER_ID]->user_status = 1;
                },
                heldParishIds: [self::PARISH_ID],
                recipient: self::REVIEWER,
                purpose: ActionTokenPurpose::APPROVE_EVENT,
                reason: 'deactivated reviewer account'
            );
        }

        /**
         * Revocation #1, on the recovery path alone: the dean is moved off the
         * deanery *after* deciding, and reopens the emailed link.
         */
        public function testADeanMovedOffTheDeaneryCannotRecoverASpentLink(): void
        {
            $this->assertRecoveryRefused(
                revocation: function (): void {
                    // Keeps the account and the capability; covers another deanery now.
                },
                heldParishIds: [self::OTHER_PARISH_ID],
                reason: 'moved off the deanery'
            );
        }

        /**
         * Revocation #2, on the recovery path alone: the account is deactivated
         * after deciding. The route still names them and APPROVE_DEANERY is
         * untouched, so only a live read of user_status catches this.
         */
        public function testADeanWithADeactivatedAccountCannotRecoverASpentLink(): void
        {
            $this->assertRecoveryRefused(
                revocation: function (): void {
                    $GLOBALS['adct_test_wp_users'][self::DEAN_USER_ID]->user_status = 1;
                },
                heldParishIds: [self::PARISH_ID],
                reason: 'deactivated account'
            );
        }

        /**
         * Revocation #3, on the recovery path alone: the capability is stripped
         * after deciding. Same route, same live account, no authority left.
         */
        public function testADeanWhoseCapabilityIsStrippedCannotRecoverASpentLink(): void
        {
            $this->assertRecoveryRefused(
                revocation: function (): void {
                    $GLOBALS['adct_test_wp_caps'][self::DEAN_USER_ID] = [];
                },
                heldParishIds: [self::PARISH_ID],
                reason: 'stripped capability'
            );
        }

        /**
         * The counterpart for the deanery case: a dean who still covers the
         * parish is untouched by a revocation that happened to their other
         * deanery, and a reviewer is untouched by any deanery change at all.
         */
        public function testAuthorityThatWasNotRevokedStillWorks(): void
        {
            $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $database = $this->database();

            $reviewerBinding = new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'event_candidate',
                self::CANDIDATE_ID,
                self::REVIEWER
            );
            self::assertNotNull(
                $this->approve(
                    approvalDatabase: $database,
                    approvalStore: new RecordingDecisionPublicationStore($database)
                )->preview($reviewerBinding),
                'A reviewer holds archdiocese-wide authority, so a deanery change is irrelevant to them.'
            );

            $rejectTokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $rejectToken = $rejectTokens->issue($this->rejectionBinding())->token();
            $outcome = $this->reject(
                rejectDatabase: $database
            )->performAtomic($this->rejectionBinding(), $rejectToken, $rejectTokens, 'Not this quarter.');

            self::assertStringContainsString('rejected', $outcome->message);
        }

        /**
         * A GET shows the page and must not decide. perform() throws by design, so
         * the bare emailed path can never commit a decision — pinned here so a
         * future change cannot quietly route one through it.
         */
        public function testTheBareEmailedPathCannotDecide(): void
        {
            foreach ([$this->approve(), $this->reject()] as $handler) {
                try {
                    $handler->perform($this->approvalBinding());
                    self::fail('A decision must never be reachable without a token transaction.');
                } catch (LogicException $refused) {
                    self::assertStringContainsString('atomic token transaction', $refused->getMessage());
                }
            }
        }

        /**
         * The endpoint's preflight. A token minted for another purpose, aimed at
         * another kind of row, or carrying a reason on an approval, is not ours.
         */
        public function testAPreflightRefusesTheWrongPurposeSubjectTypeOrAnUnexpectedReason(): void
        {
            $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $binding = $this->approvalBinding();
            $token = $tokens->issue($binding)->token();

            try {
                $this->approve()->performAtomic($binding, $token, $tokens, 'because');
                self::fail('An approval carrying a reason must be refused.');
            } catch (DomainException $refused) {
                self::assertStringContainsString('unavailable', $refused->getMessage());
            }

            $database = $this->database();
            foreach ([
                new ActionTokenBinding(
                    ActionTokenPurpose::REJECT_EVENT,
                    'event_candidate',
                    self::CANDIDATE_ID,
                    self::DEAN
                ),
                new ActionTokenBinding(
                    ActionTokenPurpose::APPROVE_EVENT,
                    'event_change',
                    self::CANDIDATE_ID,
                    self::DEAN
                ),
            ] as $wrong) {
                self::assertNull(
                    $this->approve(approvalDatabase: $database)->preview($wrong),
                    'A binding for another purpose or subject type must offer no action.'
                );
            }
        }

        /**
         * A deanery approver is only a legitimate recipient if their notice
         * actually reached the mail queue. A valid token for someone who was
         * never notified is not a decision they were asked to make.
         */
        public function testATokenForAnApproverWhoWasNeverNotifiedIsRefused(): void
        {
            $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $binding = $this->approvalBinding();
            $token = $tokens->issue($binding)->token();

            $database = $this->database();
            $database->mailStatus[self::DEAN] = 'suppressed';
            $handler = $this->approve(
                approvalDatabase: $database,
                approvalStore: new RecordingDecisionPublicationStore($database)
            );

            self::assertNull(
                $handler->preview($binding),
                'A GET must show no action for an approver who never received the notice.'
            );

            try {
                $handler->performAtomic($binding, $token, $tokens, '');
                self::fail('A decision must be refused when the notice was never deliverable.');
            } catch (DomainException $refused) {
                self::assertStringContainsString('no longer assigned', $refused->getMessage());
            }

            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
            self::assertSame([], $database->statements, 'A refusal must write nothing.');
        }

        /**
         * First to act wins. The second approver is told who got there first, and
         * the event is not published twice.
         */
        public function testTheSecondApproverIsRefusedAndToldWhoDecidedFirst(): void
        {
            $first = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $second = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $database = $this->database();

            $reviewerBinding = new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'event_candidate',
                self::CANDIDATE_ID,
                self::REVIEWER
            );
            $reviewerToken = $second->issue($reviewerBinding)->token();

            $store = new RecordingDecisionPublicationStore($database);
            $this->approve(
                approvalDatabase: $database,
                approvalStore: $store
            )->performAtomic($this->approvalBinding(), $first->issue($this->approvalBinding())->token(), $first, '');

            $database->applyUpdate();

            try {
                $this->approve(
                    approvalDatabase: $database,
                    approvalStore: $store
                )->performAtomic($reviewerBinding, $reviewerToken, $second, '');
                self::fail('A second decision on the same event must be refused.');
            } catch (DomainException $refused) {
                self::assertStringContainsString(
                    self::DEAN,
                    $refused->getMessage(),
                    'The loser must be told who decided, or the parish cannot tell what happened.'
                );
            }

            self::assertSame(
                ActionTokenStatus::VALID,
                $second->inspect($reviewerToken)->status,
                'A refused decision must leave the loser\'s link intact so they can still reject.'
            );
        }

        /**
         * Re-running a spent link must explain itself rather than look like a bad
         * link, and must not publish twice.
         */
        public function testRecoveringASpentLinkExplainsItAlreadyDecidedWithoutPublishingTwice(): void
        {
            $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $database = $this->database();
            $binding = $this->approvalBinding();
            $token = $tokens->issue($binding)->token();
            $store = new RecordingDecisionPublicationStore($database);

            $handler = $this->approve(approvalDatabase: $database, approvalStore: $store);
            $handler->performAtomic($binding, $token, $tokens, '');
            $database->applyUpdate();

            $outcome = $handler->recover($binding);

            self::assertStringContainsString('approved and published', $outcome->message);
            self::assertSame(
                1,
                $store->publishes,
                'Recovering a spent link must not publish the event a second time.'
            );
        }

        public function testRecoveringALinkThisRecipientNeverActedOnIsRefused(): void
        {
            $database = $this->database();
            $database->decide(self::DEAN, '2026-09-25 06:00:00');

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('did not decide');

            $this->approve(
                approvalDatabase: $database,
                approvalStore: new RecordingDecisionPublicationStore($database)
            )->recover($this->rejectionBinding());
        }

        /**
         * A rejected event tells the submitter why, escaped. The reason is typed
         * by an approver into a form, so it reaches the queue as text, not markup.
         */
        public function testARejectionRecordsTheReasonAndTellsTheSubmitter(): void
        {
            $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $database = $this->database();
            $mailer = new RecordingDecisionMailer();
            $binding = $this->rejectionBinding();
            $token = $tokens->issue($binding)->token();

            $this->reject(rejectDatabase: $database, rejectMailer: $mailer)
                ->performAtomic($binding, $token, $tokens, '<b>Wrong</b> parish.');

            self::assertSame(
                '<b>Wrong</b> parish.',
                $database->candidateRow()['decision_note'] ?? null,
                'The reason is stored verbatim for the audit trail.'
            );
            self::assertCount(1, $mailer->sent);
            $sent = $mailer->sent[0];
            self::assertSame(self::SUBMITTER, $sent->recipient);
            self::assertSame('Your event was not published', $sent->subject);
            self::assertStringNotContainsString(
                '<b>',
                            $sent->htmlBody,
                'An approver-supplied reason must be escaped before it reaches the submitter.'
            );
                        self::assertStringContainsString('&lt;b&gt;', $sent->htmlBody);
        }

        /**
         * Approving a matched or ambiguous candidate is not a decision the
         * approver can make: the parser could not tell which event this is, so a
         * human has to look. match_review_required and matched_candidate_id live
         * inside the fields JSON, not in columns, so this pins the JSON path.
         */
        public function testAMatchedOrAmbiguousCandidateIsRefusedForManualReview(): void
        {
            foreach ([
                'match_review_required' => true,
                'matched_candidate_id' => 91,
            ] as $flag => $value) {
                $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
                $database = $this->database();
                $row = $database->candidateRow();
                $fields = json_decode((string) $row['fields'], true, 512, JSON_THROW_ON_ERROR);
                $fields[$flag] = $value;
                $row['fields'] = (string) json_encode($fields, JSON_THROW_ON_ERROR);
                $database->replaceCandidate($row);
                $binding = $this->approvalBinding();
                $token = $tokens->issue($binding)->token();

                try {
                    $this->approve(
                        approvalDatabase: $database,
                        approvalStore: new RecordingDecisionPublicationStore($database)
                    )->performAtomic($binding, $token, $tokens, '');
                    self::fail('An ambiguous candidate must be routed to manual review, not approved.');
                } catch (DomainException $refused) {
                    self::assertStringContainsString('manual review', $refused->getMessage());
                }

                self::assertSame(
                    ActionTokenStatus::VALID,
                    $tokens->inspect($token)->status,
                    'A manual-review refusal must not burn the approver\'s link.'
                );
                self::assertSame([], $database->statements);
            }
        }

        /**
         * The audit trail records who decided and in what capacity. It is the one
         * place the live role is captured, so it is also the one place a memoised
         * role would leave a wrong answer behind.
         */
        public function testASuccessfulDecisionRecordsTheActorRoleAndAction(): void
        {
            $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $database = $this->database();
            $store = new RecordingDecisionPublicationStore($database);
            $binding = $this->approvalBinding();

            $this->approve(approvalDatabase: $database, approvalStore: $store)
                ->performAtomic($binding, $tokens->issue($binding)->token(), $tokens, '');

            self::assertCount(1, $database->auditRows);
            $audit = $database->auditRows[0];
            self::assertSame(self::DEAN, $audit['actor']);
            self::assertSame('approver_approved', $audit['action']);
            self::assertSame('event_candidate', $audit['subjectType']);
            self::assertSame(self::CANDIDATE_ID, $audit['subjectId']);
            self::assertSame(
                ['role' => 'dean', 'reason' => null],
                $audit['details'],
                'The audit row must record the live role, not a cached one.'
            );
        }

        /**
         * Asserts that `recover()` refuses once the entitlement is gone, in the
         * state where that is the *only* thing left to refuse it.
         *
         * `recover()` refuses with 'did not decide' for two different reasons: the
         * recipient never acted on this event, or they are no longer entitled to
         * act on it. `assertRefusedEverywhere()` exercises it in the first state,
         * where the `decided_by` check refuses first — so deleting the role check
         * from `recover()` changes nothing observable and the suite stays green.
         * That is exactly the "a future refactor breaks the site and still passes
         * every test" failure this issue exists to prevent.
         *
         * Here the event has already been decided *by this dean*, so every other
         * condition in `recover()` holds and the live role check is the only thing
         * between a revoked dean and a published event. `testAuthorityThatWasNotRevokedStillWorks()`
         * is the counterpart: without it this could pass on a `recover()` that
         * refuses everyone.
         *
         * @param callable(): void $revocation Applied after the dean decided.
         * @param int[] $heldParishIds The dean's deaneries *after* the revocation.
         */
        private function assertRecoveryRefused(
            callable $revocation,
            array $heldParishIds,
            string $reason
        ): void {
            $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());
            $database = $this->database();
            $binding = $this->approvalBinding();
            $store = new RecordingDecisionPublicationStore($database);

            // Minted and spent while the dean was still entitled, so this really is
            // the recovery path: the token is spent and the event is published.
            $entitled = $this->handler(
                ActionTokenPurpose::APPROVE_EVENT,
                $database,
                new RecordingDecisionMailer(),
                $store,
                [self::PARISH_ID]
            );
            $minted = $tokens->issue($binding)->token();
            $entitled->performAtomic($binding, $minted, $tokens, '');
            $database->applyUpdate();

            self::assertSame(
                ActionTokenStatus::USED,
                $tokens->inspect($minted)->status,
                'Pre-condition: the link is spent, which is what makes this the recovery path.'
            );
            self::assertSame(1, $store->publishes, 'Pre-condition: the event was published once.');

            // The entitlement goes away after the decision was taken.
            $revocation();

            $published = $store->publishes;
            $before = $database->candidateRow();
            $revoked = $this->handler(
                ActionTokenPurpose::APPROVE_EVENT,
                $database,
                new RecordingDecisionMailer(),
                $store,
                $heldParishIds
            );

            try {
                $revoked->recover($binding);
                self::fail('A recovery must be refused after the entitlement was revoked (' . $reason . ').');
            } catch (DomainException $refused) {
                self::assertStringContainsString('did not decide', $refused->getMessage());
            }

            self::assertSame(
                $published,
                $store->publishes,
                'A refused recovery must not publish again (' . $reason . ').'
            );
            self::assertSame(
                $before,
                $database->candidateRow(),
                'A refused recovery must not touch the candidate row (' . $reason . ').'
            );
        }

        /**
         * @param callable(): void $revocation Applied after the token was minted.
         */
        private function assertRefusedEverywhere(
            callable $revocation,
            array $heldParishIds,
            string $reason,
            string $recipient = self::DEAN,
            ActionTokenPurpose $purpose = ActionTokenPurpose::APPROVE_EVENT
        ): void {
            $binding = new ActionTokenBinding(
                $purpose,
                'event_candidate',
                self::CANDIDATE_ID,
                $recipient
            );

            $tokens = new ActionTokenService(new DecisionTokenStore(), new DecisionClock());

            // Minted while the recipient was entitled...
            $token = $tokens->issue($binding)->token();

            $database = $this->database();
            $original = $database->candidateRow();
            $mailer = new RecordingDecisionMailer();
            $store = new RecordingDecisionPublicationStore($database);

            $handler = $this->handler(
                $purpose,
                $database,
                $mailer,
                $store,
                $heldParishIds
            );

            // ...then the entitlement is removed, after the mail went out.
            $revocation();

            self::assertNull(
                $handler->preview($binding),
                'A GET must show no action once the entitlement is gone (' . $reason . ').'
            );

            try {
                $handler->performAtomic($binding, $token, $tokens, '');
                self::fail('A decision must be refused after the entitlement was revoked (' . $reason . ').');
            } catch (DomainException $refused) {
                self::assertStringContainsString('no longer assigned', $refused->getMessage());
            }

            try {
                $handler->recover($binding);
                self::fail('A spent-link recovery must be refused too (' . $reason . ').');
            } catch (DomainException $refused) {
                self::assertStringContainsString('did not decide', $refused->getMessage());
            }

            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A refusal must leave the token unspent, or a mistyped click burns the'
                    . ' recipient\'s only way in (' . $reason . ').'
            );
            self::assertContains('ROLLBACK', $database->transactions);
            self::assertNotContains('COMMIT', $database->transactions);
            self::assertSame([], $database->statements, 'A refusal must write nothing (' . $reason . ').');
            self::assertSame([], $database->auditRows, 'A refusal must leave no audit trail (' . $reason . ').');
            self::assertSame(
                $original,
                $database->candidateRow(),
                'A refusal must not touch the candidate row (' . $reason . ').'
            );
            self::assertSame(
                0,
                $store->publishes,
                'A refusal must not publish the event (' . $reason . ').'
            );
            self::assertSame([], $mailer->sent, 'A refusal must not send mail (' . $reason . ').');
        }

        private function approve(
            ?DecisionDatabase $approvalDatabase = null,
            ?MailerInterface $approvalMailer = null,
                    ?PublicationStoreInterface $approvalStore = null
                ): ApprovalDecisionHandler {
                    return $this->handler(
                        ActionTokenPurpose::APPROVE_EVENT,
                        $approvalDatabase,
                        $approvalMailer,
                        $approvalStore
                    );
                }

        private function reject(
            ?DecisionDatabase $rejectDatabase = null,
            ?MailerInterface $rejectMailer = null,
            ?PublicationStoreInterface $rejectStore = null
        ): ApprovalDecisionHandler {
            return $this->handler(
                ActionTokenPurpose::REJECT_EVENT,
                $rejectDatabase,
                $rejectMailer,
                $rejectStore
            );
        }

        /**
         * @param list<int> $heldParishIds
         */
        private function handler(
            ActionTokenPurpose $purpose,
            ?DecisionDatabase $database = null,
            ?MailerInterface $mailer = null,
            ?PublicationStoreInterface $store = null,
            array $heldParishIds = [self::PARISH_ID]
        ): ApprovalDecisionHandler {
            $database ??= $this->database();

            return new ApprovalDecisionHandler(
                $purpose,
                $database,
                new ApprovalRecipients(new ApprovalRouteResolver(new DecisionRouteRepository($heldParishIds))),
                new CandidatePublisher(
                    $store ?? new RecordingDecisionPublicationStore($database),
                    new EventValidator(new DateTimeZone('Africa/Johannesburg'))
                ),
                $mailer ?? new RecordingDecisionMailer(),
                new DecisionClock()
            );
        }

        private function approvalBinding(): ActionTokenBinding
        {
            return new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'event_candidate',
                self::CANDIDATE_ID,
                self::DEAN
            );
        }

        private function rejectionBinding(): ActionTokenBinding
        {
            return new ActionTokenBinding(
                ActionTokenPurpose::REJECT_EVENT,
                'event_candidate',
                self::CANDIDATE_ID,
                self::DEAN
            );
        }

        private function database(): DecisionDatabase
        {
            return new DecisionDatabase($this->candidateRow());
        }

        /**
         * @return array<string, mixed>
         */
        private function candidateRow(): array
        {
            return [
                'id' => (string) self::CANDIDATE_ID,
                'message_id' => (string) self::MESSAGE_ID,
                'block_index' => '0',
                'parish_id' => (string) self::PARISH_ID,
                'fields' => (string) json_encode([
                    'title' => 'Parish retreat day',
                    'description' => 'Anonymised details.',
                    'event_date' => '2026-10-12',
                    'event_time' => '09:00',
                    'venue' => 'St Mary\'s hall',
                    'event_type' => 'Retreat',
                ], JSON_THROW_ON_ERROR),
                'recurrence' => null,
                'confidence' => '0.9',
                'parser_version' => '1',
                'strategies' => '["rules"]',
                'notes' => '[]',
                'ai_used' => '0',
                'match_event_id' => null,
                'match_kind' => 'new',
                'status' => 'awaiting_approval',
                'confirmed_by' => self::SUBMITTER,
                'confirmed_at' => '2026-09-24 12:00:00',
                'approved_by' => null,
                'approved_at' => null,
                'approved_via' => null,
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
                'created_at' => '2026-09-24 12:00:00',
                'updated_at' => '2026-09-24 12:00:00',
            ];
        }
    }

    /**
     * The live relationship, and the whole point of the exercise: the same parish
     * answers differently once the dean's assignment changes, so a handler that
     * reads it at act time sees what is true now rather than what was true when
     * the notice was mailed.
     */
    final class DecisionRouteRepository implements ApprovalRouteRepositoryInterface
    {
        /**
         * @param list<int> $heldParishIds
         */
        public function __construct(private readonly array $heldParishIds)
        {
        }

        public function findForParish(int $parishId): ?ApprovalRouteSnapshot
        {
            $dean = new Approver(
                1,
                DecisionFixture::DEAN_USER_ID,
                DecisionFixture::DEAN_EMAIL,
                'Dean of the archdiocese',
                Approver::NOTIFY_EACH,
                true,
                true
            );

            // An unheld parish keeps its deanery but loses its approver, which is
            // exactly how a deanery approver assignment is withdrawn.
            return new ApprovalRouteSnapshot(
                7,
                true,
                in_array($parishId, $this->heldParishIds, true) ? [$dean] : []
            );
        }
    }

    final class DecisionFixture
    {
        public const DEAN_USER_ID = 101;
        public const DEAN_EMAIL = 'dean@example.test';
        public const PARISH_ID = 11;
        public const CANDIDATE_ID = 7;
                public const EVENT_ID = 34;
                public const NOW = '2026-09-25 08:00:00';
    }

    /**
     * A stand-in for the candidates, notices, mail queue and audit log, so the
     * handler's SQL is exercised without a database. It records what it is asked
     * to run, which is what lets a test assert that a refusal wrote nothing.
     */
    final class DecisionDatabase implements DatabaseConnectionInterface
    {
        /**
         * @var array<string, mixed>
         */
        private array $candidate;

        /**
         * @var array<string, mixed>|null
         */
        private array $committed;

        /**
                 * Columns changed by an UPDATE still inside the open transaction.
                 *
                 * @var array<string, string>
                 */
                private array $pending = [];

        /**
         * Statements run outside a transaction, so "a refusal wrote nothing" is checkable.
         *
         * @var list<string>
         */
        public array $statements = [];

        /**
         * @var list<string>
         */
        public array $transactions = [];

        /**
         * @var list<array<string, mixed>>
         */
        public array $auditRows = [];

        /**
         * @var list<array{recipient: string, status: string}>
         */
        private array $notices = [];

        /**
         * @var array<string, string>
         */
        public array $mailStatus = [];

        /**
         * @param array<string, mixed> $candidate
         */
        public function __construct(array $candidate)
        {
            $this->candidate = $candidate;
            $this->committed = $candidate;

            foreach ([DecisionFixture::DEAN_EMAIL, 'reviewer@example.test'] as $recipient) {
                $this->notices[] = ['recipient' => $recipient, 'status' => 'queued'];
                $this->mailStatus[$recipient] = 'queued';
            }
        }

        /**
         * @return array<string, mixed>
         */
        public function candidateRow(): array
        {
            return $this->candidate;
        }

        /**
         * @param array<string, mixed> $row
         */
        public function replaceCandidate(array $row): void
        {
            $this->candidate = $row;
            $this->committed = $row;
            $this->pending = [];
        }

                /**
                         * Marks the candidate as already approved by someone else, as another
                         * approver pressing their button would have.
                         */
                        public function decide(string $email, string $at): void
                        {
                            $this->candidate = array_merge($this->candidate, [
                                'status' => 'published',
                                'approved_by' => $email,
                                'approved_at' => $at,
                                'approved_via' => 'dean',
                                'decided_by' => $email,
                                'decided_at' => $at,
                            ]);
                            $this->committed = $this->candidate;
                        }

        /**
                         * Records the publication the store performed, so a second call takes the
                         * already-published path exactly as WordPressPublicationStore would.
         */
                        public function markPublished(int $eventId): void
                        {
                            $this->candidate = array_merge($this->candidate, [
                                'status' => 'published',
                                'match_event_id' => $eventId,
                            ]);
                            $this->committed = $this->candidate;
                        }

                        /**
                         * Mirrors MySQL: rows changed inside a transaction are only visible after
                         * COMMIT and are discarded on ROLLBACK.
                         */
                        public function applyUpdate(): void
                        {
                            $this->candidate = array_merge($this->candidate, $this->pending);
                            $this->pending = [];
                        }

        public function rollback(): void
        {
            $this->candidate = $this->committed ?? $this->candidate;
            $this->pending = [];
        }

        public function prefix(): string
        {
            return 'wp_';
        }

        /**
         * Arguments are appended so the handler still has to supply every one of
         * them, and nothing can be interpolated raw.
         */
        public function prepare(string $query, mixed ...$arguments): string
        {
            return $query . ' /* ' . (string) json_encode($arguments, JSON_THROW_ON_ERROR) . ' */';
        }

        public function query(string $query): int|false
        {
            $trimmed = trim($query);
            $upper = strtoupper($trimmed);

            if ($upper === 'START TRANSACTION' || $upper === 'COMMIT' || $upper === 'ROLLBACK') {
                $this->transactions[] = $upper;

                if ($upper === 'COMMIT') {
                    $this->applyUpdate();
                    $this->committed = $this->candidate;
                } elseif ($upper === 'ROLLBACK') {
                    $this->rollback();
                }

                return 0;
            }

            $this->statements[] = $trimmed;
            $values = self::argumentsOf($trimmed);

            if (str_contains($trimmed, 'UPDATE `wp_adct_pi_event_candidates`')) {
                // Column order differs between the approve and the reject UPDATE.
                $rejecting = str_contains($trimmed, 'decision_note');
                $this->pending = $rejecting
                    ? [
                        'status' => 'rejected',
                        'decided_by' => (string) $values[1],
                        'decided_at' => (string) $values[2],
                        'decision_note' => (string) $values[3],
                        'updated_at' => (string) $values[4],
                    ]
                    : [
                        'approved_by' => (string) $values[0],
                        'approved_at' => (string) $values[1],
                        'approved_via' => (string) $values[2],
                        'decided_by' => (string) $values[3],
                        'decided_at' => (string) $values[4],
                        'updated_at' => (string) $values[5],
                    ];

                return 1;
            }

            if (str_contains($trimmed, 'INSERT INTO `wp_adct_pi_audit_log`')) {
                $this->auditRows[] = [
                    'actor' => (string) ($values[0] ?? ''),
                    'action' => (string) ($values[1] ?? ''),
                    'subjectType' => (string) ($values[2] ?? ''),
                    'subjectId' => (int) ($values[3] ?? 0),
                    'details' => json_decode(
                        (string) ($values[4] ?? '{}'),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    ),
                ];

                return 1;
            }

            return 1;
        }

        public function getRow(string $query): ?array
        {
            if (str_contains($query, 'adct_pi_event_candidates')) {
                return $this->candidate;
            }

            if (str_contains($query, 'adct_pi_parishes')) {
                return ['name' => 'Parish of the Test'];
            }

            if (str_contains($query, 'adct_pi_inbound_messages')) {
                return ['sender_email' => 'parish@example.test'];
            }

            if (str_contains($query, 'adct_pi_approval_notices')) {
                $arguments = self::argumentsOf($query);
                $recipient = (string) ($arguments[1] ?? '');

                foreach ($this->notices as $notice) {
                    if ($notice['recipient'] === $recipient) {
                        return ['status' => $this->mailStatus[$recipient] ?? $notice['status']];
                    }
                }

                return null;
            }

            return null;
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function getResults(string $query): array
        {
            return [];
        }

        public function escapeLike(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function insertId(): int
        {
            return 1;
        }

        public function charsetCollate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function clearLastError(): void
        {
        }

        public function lastError(): string
        {
            return '';
        }

        /**
         * @return list<mixed>
         */
        private static function argumentsOf(string $prepared): array
        {
            $position = strrpos($prepared, '/*');

            if ($position === false) {
                return [];
            }

            $decoded = json_decode(substr($prepared, $position + 2, -2), true);

            return is_array($decoded) ? $decoded : [];
        }
    }

    final class DecisionClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable(
                DecisionFixture::NOW,
                new DateTimeZone('Africa/Johannesburg')
            );
        }
    }

    /**
     * The real store writes used_at with an UPDATE on the same connection the
     * handler's transaction is open on, so a ROLLBACK un-consumes the token too.
     * The fake mirrors that: without it, a rolled-back decision would look like it
     * had burned the link and a retry would be impossible.
     */
    final class DecisionTokenStore implements ActionTokenStoreInterface
    {
        /**
         * @var array<string, ActionTokenRecord>
         */
        private array $records = [];

        /**
         * @var array<string, ActionTokenRecord>
         */
        private array $committed = [];

        public function commit(): void
        {
            $this->committed = $this->records;
        }

        public function rollback(): void
        {
            $this->records = $this->committed;
        }

        public function create(ActionTokenRecord $record): void
        {
            $this->records[$record->tokenHash] = $record;
        }

        public function findByHash(string $tokenHash): ?ActionTokenRecord
        {
            return $this->records[$tokenHash] ?? null;
        }

        public function consume(string $tokenHash, ActionTokenBinding $binding, DateTimeImmutable $now): bool
        {
            $record = $this->records[$tokenHash] ?? null;

            if ($record === null || ! $record->binding->equals($binding)) {
                return false;
            }

            $this->records[$tokenHash] = new ActionTokenRecord(
                $record->tokenHash,
                $record->binding,
                $record->expiresAt,
                $now,
                $record->createdAt
            );

            return true;
        }
    }

    final class RecordingDecisionMailer implements MailerInterface
    {
        /**
         * @var list<OutboundEmail>
         */
        public array $sent = [];

        public function enqueue(OutboundEmail $email): MailQueueEnqueueResult
        {
            $this->sent[] = $email;

            return new MailQueueEnqueueResult(count($this->sent), MailQueueStatus::QUEUED, false);
        }
    }

    /**
     * Counts publications instead of performing them, so a test can assert that
     * a refusal published nothing and a recovery published only once.
     *
     * The row is read from the fake database at publish time rather than held as
     * a snapshot, because WordPressPublicationStore reads the candidate inside the
     * same call and a store holding a pre-decision snapshot would refuse every
     * publication for the wrong reason.
     *
     * Re-publishing an already published candidate is idempotent here, exactly as
     * WordPressPublicationStore::publish() is: it returns the existing event
     * instead of writing a second post. Recovery re-enters complete(), so a
     * non-idempotent fake would report a double publication that the real store
     * never performs.
     */
        final class RecordingDecisionPublicationStore implements PublicationStoreInterface
        {
            public int $publishes = 0;

            public function __construct(private readonly DecisionDatabase $database)
            {
            }

            public function publish(int $candidateId, callable $prepare): int
            {
                if ($this->database->candidateRow()['status'] === 'published') {
                    return (int) $this->database->candidateRow()['match_event_id'];
                }

                $this->publishes++;
                $publication = $prepare($this->database->candidateRow());
                $eventId = $publication->eventId ?? DecisionFixture::EVENT_ID;
                $this->database->markPublished($eventId);

                return $eventId;
            }
        }
}
