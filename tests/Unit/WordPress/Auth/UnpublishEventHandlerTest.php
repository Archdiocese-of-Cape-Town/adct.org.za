<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    // One stand-in for the whole suite, not a second one: this file drives the
    // same fake event, the same postmeta globals and the same WordPress doubles
    // that RevertChangeHandlerTest declares, because the unpublish handler reads
    // the same snapshot shape and writes through the same seam. A second
    // declaration of any of them would be a fatal, not a shadow.
    require_once __DIR__ . '/RevertChangeHandlerTest.php';

    use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\ActionTokenService;
    use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Mail\MailPriority;
    use ADCT\ParishIntake\Core\Ports\OccurrenceMaintenanceInterface;
    use ADCT\ParishIntake\Core\Ports\MailerInterface;
    use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
    use ADCT\ParishIntake\WordPress\Auth\UnpublishEventHandler;
    use ADCT\ParishIntake\WordPress\Events\EventListingGeneration;
    use ADCT\ParishIntake\WordPress\Events\EventOccurrenceHooks;
    use DateTimeZone;
    use DomainException;
    use LogicException;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use WP_Post;
    use WP_User;

    /**
    * #71's unpublish link, exercised at the handler seam.
    *
    * The revert half has its own test file and this one deliberately mirrors it,
    * because the two handlers are peers: same recipient, same live-authority
    * check, same supersession guard, same transaction shape. Anything that made
    * unpublish a *second* implementation rather than the other half of the same
    * decision would show up as a difference between the two files.
    *
    * What is genuinely different, and what these tests exist for:
    *
    *  - unpublishing is terminal. A revert restores the old state, so a double
    *    revert is merely redundant; a double unpublish would write a second
    *    trail row claiming a removal that had already happened, so the guard has
    *    to be the post's status rather than a column on the change row.
    *  - it takes the event off the calendar feed, so the occurrence rebuild is
    *    part of the action rather than a cache refresh.
    *  - it writes its own audit verb, because `change_reverted` promises the old
    *    state comes back and nothing here does that.
    *
    * The endpoint-level proofs live in UnpublishEventEndpointTest, which drives
    * the actual POSTs.
    */
    final class UnpublishEventHandlerTest extends TestCase
    {
        private const DEAN = 'dean@example.test';

        /**
         * The parish contact who wrote the change being unpublished. A third
         * address, distinct from both the approvers and the event's own contact
         * meta, so a test cannot pass by mailing the wrong one of the three.
         */
        private const CONTACT = 'office@example.test';
        private const REVIEWER = 'reviewer@example.test';

        private const DEAN_USER_ID = 101;
        private const REVIEWER_USER_ID = 202;

        /** The parish the published change belongs to. */
        private const PARISH_ID = 11;

        /** A second deanery the same dean used to cover, so revocation has something to take away. */
        private const OTHER_PARISH_ID = 12;

        private const EVENT_ID = 501;
        private const CHANGE_ID = 9001;

        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['revert_posts'] = [self::EVENT_ID => new WP_Post(self::EVENT_ID)];
            $post = $GLOBALS['revert_posts'][self::EVENT_ID];
            $post->post_title = 'Retreat day (renamed)';
            $post->post_content = 'The amended description.';
            $post->post_excerpt = 'Amended excerpt.';
            $post->post_status = 'publish';
            $post->post_type = 'adct_event';

            $GLOBALS['revert_meta'] = [self::EVENT_ID => $this->amendedMeta()];
            $GLOBALS['revert_terms'] = [self::EVENT_ID => [43]];
            $GLOBALS['revert_inserts'] = [];
            $GLOBALS['revert_unexpected_inserts'] = [];
            $GLOBALS['revert_cache_cleared'] = [];
            $GLOBALS['revert_options'] = [EventListingGeneration::OPTION => '0'];
            $GLOBALS['revert_option_writes'] = [];

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
            foreach ([
                'revert_posts',
                'revert_meta',
                'revert_terms',
                'revert_inserts',
                'revert_unexpected_inserts',
                'revert_cache_cleared',
                'revert_options',
                'revert_option_writes',
                'adct_test_wp_users',
                'adct_test_wp_caps',
                'adct_test_wp_meta',
            ] as $key) {
                unset($GLOBALS[$key]);
            }

            EventOccurrenceHooks::setPublishingCandidate(false);

            parent::tearDown();
        }

        public function testTheHandlerClaimsItsOwnReservedPurpose(): void
        {
            self::assertSame(ActionTokenPurpose::UNPUBLISH_EVENT, $this->handler()->purpose());
        }

        /**
        * The purpose has to be distinct from revert. If they shared one, the
        * token in either link would be a credential for both, and an approver
        * who read a notice as offering one would get the other.
        */
        public function testTheUnpublishPurposeIsNotTheRevertPurpose(): void
        {
            self::assertNotSame(
                ActionTokenPurpose::REVERT_CHANGE,
                ActionTokenPurpose::UNPUBLISH_EVENT,
                'One token must not be able to mean both "put it back" and "take it down".'
            );
            self::assertSame('unpublish_event', ActionTokenPurpose::UNPUBLISH_EVENT->value);
        }

        /**
        * #71's permissioning requirement: the link goes to whoever held
        * authority over the parish when the change was published, which is a
        * fact about the past. A dean can move between deaneries before the link
        * is followed, so the live relationship is re-resolved, and a refusal has
        * to change nothing.
        */
        public function testAnUnpublishTokenMintedWhileTheApproverHeldTwoDeaneriesIsRefusedAfterRevocation(): void
        {
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();

            // Mailed while both deaneries were held...
            $token = $tokens->issue($binding)->token();

            // ...then revoked down to one.
            $database = $this->database();
            $mailer = new RecordingMailer();
            $handler = $this->handler([self::OTHER_PARISH_ID], $database, null, $mailer);

            self::assertNull(
                $handler->preview($binding),
                'A GET must show no action once the dean no longer covers the parish.'
            );

            try {
                $handler->performAtomic($binding, $token, $tokens, '');
                self::fail('An unpublish must be refused after the dean lost the parish.');
            } catch (DomainException $refusal) {
                self::assertStringContainsString('no longer', $refusal->getMessage());
            }

            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A refused unpublish must leave the link usable.'
            );
            self::assertSame([], $database->statements, 'A refused unpublish must write nothing.');
            self::assertSame(
                'publish',
                $GLOBALS['revert_posts'][self::EVENT_ID]->post_status,
                'A refused unpublish must not take the event down.'
            );
            self::assertSame([], $mailer->sent, 'A refused unpublish must not send mail.');
        }

        /**
        * The counterpart. Without this, the refusal above would also pass on a
        * handler that always says no, which is the failure mode the live check
        * exists to catch.
        */
        public function testTheDeaneryTheApproverStillHoldsIsUnaffectedByARevocationElsewhere(): void
        {
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $outcome = $this->handler([self::PARISH_ID])->performAtomic($binding, $token, $tokens, '');

            self::assertStringContainsString('taken off', $outcome->message);
        }

        public function testAnArchdioceseReviewerCanUnpublishAnEventTheyHoldNoDeaneryOver(): void
        {
            self::assertNotNull(
                $this->handler([self::OTHER_PARISH_ID])->preview(
                    $this->bindingFor(self::REVIEWER)
                ),
                'A reviewer must still be able to unpublish an event they hold no deanery over.'
            );
        }

        public function testADeanWhoseAccountIsDeactivatedIsRefusedEvenWhileTheRouteNamesThem(): void
        {
            $GLOBALS['adct_test_wp_users'][self::DEAN_USER_ID]->user_status = 1;

            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $handler = $this->handler([self::PARISH_ID]);

            self::assertNull($handler->preview($binding));

            $this->expectException(DomainException::class);

            $handler->performAtomic($binding, $token, $tokens, '');
        }

        /**
        * A token minted for the other purpose, or pointed at another kind of
        * row, must not be actionable here even if it reaches this handler.
        */
        public function testAPreflightRefusesABindingForAnotherPurposeOrSubjectType(): void
        {
            $handler = $this->handler();

            self::assertNull($handler->preview(new ActionTokenBinding(
                ActionTokenPurpose::REVERT_CHANGE,
                'event_change',
                self::CHANGE_ID,
                self::DEAN
            )));
            self::assertNull($handler->preview(new ActionTokenBinding(
                ActionTokenPurpose::UNPUBLISH_EVENT,
                'event_candidate',
                self::CHANGE_ID,
                self::DEAN
            )));
        }

        public function testAPreflightForAChangeThatDoesNotExistOffersNothing(): void
        {
            self::assertNull(
                $this->handler()->preview(new ActionTokenBinding(
                    ActionTokenPurpose::UNPUBLISH_EVENT,
                    'event_change',
                    4242,
                    self::DEAN
                )),
                'A token for a change row that is gone must not offer an action.'
            );
        }

        /**
        * The happy path, and the reason this handler exists: the event stops
        * being published and the ICS feed stops carrying it, which is why the
        * occurrence rebuild is part of the action and not a cache refresh.
        */
        public function testASuccessfulUnpublishTakesTheEventOffTheEventsPageAndTheFeed(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $occurrences = new RecordingOccurrenceMaintenance();
            $outcome = $this->handler([self::PARISH_ID], $database, $occurrences)
                ->performAtomic($binding, $token, $tokens, '');

            self::assertStringContainsString('taken off', $outcome->message);
            self::assertSame(
                UnpublishEventHandler::UNPUBLISHED_STATUS,
                $GLOBALS['revert_posts'][self::EVENT_ID]->post_status,
                'The event must leave the published set.'
            );
            self::assertSame(
                [],
                $GLOBALS['revert_unexpected_inserts'],
                'An unpublish must move the existing event, never create a new one.'
            );
            self::assertSame(
                [self::EVENT_ID],
                $occurrences->rebuilt,
                'The occurrences must be rebuilt, or the feed keeps serving an event that is off the page.'
            );
            self::assertSame(ActionTokenStatus::USED, $tokens->inspect($token)->status);
        }

        /**
        * The event is not deleted, so the trail is the only record of what was
        * on the page before. Without the `unpublish` row the history would claim
        * the event was always unpublished and no approver could find out what a
        * contact had got it published as.
        */
        public function testAnUnpublishAppendsATrailRowHoldingTheStateThatWasLive(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $this->handler([self::PARISH_ID], $database)->performAtomic($binding, $token, $tokens, '');

            self::assertCount(1, $database->insertedChanges);
            $row = $database->insertedChanges[0];
            self::assertSame('unpublish', $row['kind']);
            self::assertSame(self::DEAN, $row['actor']);
            self::assertSame(self::EVENT_ID, $row['event_id']);

            $removed = json_decode((string) $row['before_payload'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(
                'Retreat day (renamed)',
                $removed['title'],
                "The unpublish row's before_payload must hold the state it removed."
            );
            self::assertSame('publish', $removed['status']);
            self::assertSame(8, $removed['meta']['venue_id']);

            $after = json_decode((string) $row['after_payload'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(UnpublishEventHandler::UNPUBLISHED_STATUS, $after['status']);

            self::assertStringContainsString(
                'event_unpublished',
                implode("\n", $database->statements),
                'An unpublish must be audited.'
            );
            self::assertStringNotContainsString(
                'change_reverted',
                implode("\n", $database->statements),
                'It must not borrow the revert verb, whose name promises the old state returns.'
            );
        }

        /**
                * Unpublishing is the harshest thing the plugin can do to a parish's
                * event: it vanishes from the page and the feed. The contact who asked
                * for it is the person who most needs to hear that it happened.
                *
                * ChangeNoticeJob will not tell them. It skips `revert` rows on purpose --
                * a notice carrying a one-click revert link is a live credential for an
                * undo, and these rows record undos already performed -- and `unpublish`
                * rows are silent for the same reason. So the handler is the only place
                * the outcome and the contact's address are both in hand.
                */
                public function testTheContactWhoseEventWasUnpublishedIsToldToo(): void
                {
                    $database = $this->database();
                    $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
                    $binding = $this->binding();
                    $token = $tokens->issue($binding)->token();

                    $mailer = new RecordingMailer();

                    $this->handler([self::PARISH_ID], $database, null, $mailer)
                        ->performAtomic($binding, $token, $tokens, '');

                    $toContact = null;
                    foreach ($mailer->sent as $email) {
                        if ($email->recipient === self::CONTACT) {
                            $toContact = $email;
                        }
                    }

                    self::assertNotNull(
                        $toContact,
                        'The contact who wrote the change must learn their event is off the page.'
                    );
                    self::assertStringContainsString(
                        'taken off',
                        $toContact->textBody,
                        'The mail must state the outcome plainly, not just that something happened.'
                    );
                    self::assertStringContainsString(
                        'not deleted',
                        $toContact->textBody,
                        'A parish whose event vanished needs to know it can be restored.'
                    );
                    self::assertSame(MailPriority::APPROVER_OR_CHANGE, $toContact->priority);
                }

                /**
                * One person, one message. When the contact is also the approver who
                * pressed the button the first mail already said it, and the account is
                * capped at 500 emails an hour for the whole site (ADR 0011).
                */
                public function testAContactWhoAlsoUnpublishedItIsToldOnceNotTwice(): void
                {
                    $database = $this->database();
                    $database->setActor(self::CHANGE_ID, self::DEAN);
                    $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
                    $binding = $this->binding();
                    $token = $tokens->issue($binding)->token();

                    $mailer = new RecordingMailer();

                    $this->handler([self::PARISH_ID], $database, null, $mailer)
                        ->performAtomic($binding, $token, $tokens, '');

                    self::assertCount(
                        1,
                        $mailer->sent,
                        'The presser and the contact are the same address here.'
                    );
                    self::assertSame(self::DEAN, $mailer->sent[0]->recipient);
                }

                /**
                * Terminal, so the guard cannot be the change row's own reverted_at
                * column: a revert can follow an unpublish and an unpublish can follow a
                * revert, and in both directions reverted_at is the wrong thing to test.
                * The post's status is the authority, and only the locked row knows it.
                */
        public function testAnEventThatIsAlreadyUnpublishedIsRefusedAndAppendsNothing(): void
        {
            $database = $this->database();
            $GLOBALS['revert_posts'][self::EVENT_ID]->post_status =
                UnpublishEventHandler::UNPUBLISHED_STATUS;

            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $handler = $this->handler([self::PARISH_ID], $database);

            $preview = $handler->preview($binding);
            self::assertInstanceOf(ActionTokenPreview::class, $preview);
            self::assertFalse(
                $preview->actionable,
                'An already unpublished event must offer no button.'
            );
            self::assertStringContainsString('already off the events page', $preview->summary);

            try {
                $handler->performAtomic($binding, $token, $tokens, '');
                self::fail('A second unpublish must be refused.');
            } catch (DomainException $refusal) {
                self::assertStringContainsString('already off', $refusal->getMessage());
            }

            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A refused second unpublish must not burn the link.'
            );
            self::assertSame(
                [],
                $database->insertedChanges,
                'A refused second unpublish must not append a second removal row.'
            );
        }

        /**
        * The riskier hazard than a superseded revert. A revert that discarded a
        * later change could be recovered from the trail; an unpublish that fired
        * against a stale notice would take down an event an approver has since
        * looked at, and the sender who asked for the change would get a
        * withdrawal they never sent.
        *
        * Like the revert guard, the refusal is about the state of the event
        * rather than about the link, so the link stays usable.
        */
        public function testAChangeSupersededByALaterOneCannotBeUnpublished(): void
        {
            $database = $this->database();
            $database->seedSupersedingChange(self::EVENT_ID);

            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $occurrences = new RecordingOccurrenceMaintenance();
            $mailer = new RecordingMailer();
            $handler = $this->handler([self::PARISH_ID], $database, $occurrences, $mailer);

            try {
                $handler->performAtomic($binding, $token, $tokens, '');
                self::fail('Unpublishing a superseded change must be refused.');
            } catch (DomainException $refusal) {
                self::assertStringContainsString('newer', $refusal->getMessage());
            }

            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A superseded change must not burn the link.'
            );
            self::assertSame('publish', $GLOBALS['revert_posts'][self::EVENT_ID]->post_status);
            self::assertSame(
                [],
                $database->insertedChanges,
                'A refused unpublish must not append a removal row.'
            );
            self::assertSame([], $occurrences->rebuilt);
            self::assertSame([], $mailer->sent);
        }

        /**
        * The counterpart, so the guard is not merely "refuse everything".
        */
        public function testTheCurrentHeadOfTheTrailStillUnpublishes(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $outcome = $this->handler([self::PARISH_ID], $database)->performAtomic($binding, $token, $tokens, '');

            self::assertStringContainsString('taken off', $outcome->message);
        }

        /**
        * A change to a different event cannot say anything about this one, so it
        * must not be read as superseding it.
        */
        public function testAChangeToADifferentEventDoesNotBlockThisUnpublish(): void
        {
            $database = $this->database();
            $database->seedSupersedingChange(9999);

            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $outcome = $this->handler([self::PARISH_ID], $database)->performAtomic($binding, $token, $tokens, '');

            self::assertStringContainsString('taken off', $outcome->message);
        }

        public function testAnUnpublishRefreshesTheListingCacheExactlyOnce(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $this->handler([self::PARISH_ID], $database)
                ->performAtomic($binding, $token, $tokens, '');

            self::assertSame(
                [EventListingGeneration::OPTION],
                $GLOBALS['revert_option_writes'],
                'The public listing must be invalidated or the event stays on the page.'
            );
            self::assertContains(self::EVENT_ID, $GLOBALS['revert_cache_cleared']);
        }

        /**
        * Unpublishing writes a post, occurrences and rows, so it cannot take the
        * plain non-transactional perform path.
        */
        public function testAPlainPerformIsRefusedBecauseUnpublishingMustBeTransactional(): void
        {
            $this->expectException(LogicException::class);

            $this->handler()->perform($this->binding());
        }

        /**
        * A POST carrying a reason is not one of ours: an unpublish has no reason
        * field, so its presence means the request was shaped by something else.
        */
        public function testAnUnpublishWithAReasonIsRefused(): void
        {
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('unavailable');

            $this->handler([self::PARISH_ID])->performAtomic($binding, $token, $tokens, 'because');
        }

        /**
        * A half-applied unpublish would leave an event that is neither published
        * nor recorded as removed, which is the one state nothing can recover
        * from. So it is one transaction with the token consumed inside it.
        */
        public function testAFailureMidUnpublishRollsBackEverything(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService($store = new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();
            $store->commit();

            $occurrences = new RecordingOccurrenceMaintenance();
            $occurrences->failOnRebuild = true;

            try {
                $this->handler([self::PARISH_ID], $database, $occurrences)
                    ->performAtomic($binding, $token, $tokens, '');
                self::fail('A failed occurrence rebuild must abort the unpublish.');
            } catch (RuntimeException) {
                // Expected: the rebuild blew up.
            }

            self::assertContains('ROLLBACK', $database->transactions);
            self::assertNotContains('COMMIT', $database->transactions);

            // The post was already moved inside the transaction. WordPress has
            // no transaction here, so what makes this recoverable is that the
            // failure means nothing is recorded as having happened — the event
            // history, which is the authority, still shows it published.
            self::assertSame([], $database->insertedChanges);

            $store->rollback();
            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A rolled-back unpublish must not burn the token, so a retry stays possible.'
            );
        }

        /**
        * Re-running a spent link must explain itself rather than look like a bad
        * link, and must not unpublish twice.
        */
        public function testRecoveringASpentLinkExplainsThatItAlreadyUnpublished(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $handler = $this->handler([self::PARISH_ID], $database);
            $handler->performAtomic($binding, $token, $tokens, '');

            $outcome = $handler->recover($binding);

            self::assertStringContainsString('already been taken off', $outcome->message);
        }

        /**
         * The recovered outcome is only ever shown to the recipient of a token
         * this handler itself spent, and the endpoint only calls recover() for a
         * USED token bound to this handler's purpose. So unlike a revert, which
         * can test `reverted_by` because the change row records who spent it, an
         * unpublish has no per-actor column to test -- the guard is the post's
         * status, which does not say who took it down. What it must still refuse
         * is the recipient who is no longer an approver, because a token cannot
         * prove its bearer was one when the post was taken down.
         */
        public function testRecoveringASpentLinkIsRefusedOnceTheRecipientIsNoLongerAnApprover(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $handler = $this->handler([self::PARISH_ID], $database);
            $handler->performAtomic($binding, $token, $tokens, '');
            self::assertSame(ActionTokenStatus::USED, $tokens->inspect($token)->status);

            // The dean lost the parish after spending the link.
            $this->expectException(DomainException::class);

            $this->handler([self::OTHER_PARISH_ID], $database)->recover($binding);
        }

        /**
         * And the counterpart: a token spent while the dean still held the parish
         * recovers cleanly afterwards, so the refusal above is about authority
         * rather than about the event having already gone.
         */
        public function testRecoveringASpentLinkStillWorksWhileTheApproverHoldsTheParish(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $this->handler([self::PARISH_ID], $database)->performAtomic($binding, $token, $tokens, '');

            self::assertStringContainsString(
                'already been taken off',
                $this->handler([self::PARISH_ID], $database)->recover($binding)->message
            );
        }

        /**
        * @param list<int> $heldParishIds Deaneries this dean currently covers.
        */
        private function handler(
            array $heldParishIds = [self::PARISH_ID],
            ?RevertDatabase $database = null,
            ?OccurrenceMaintenanceInterface $occurrences = null,
            ?MailerInterface $mailer = null
        ): UnpublishEventHandler {
            return new UnpublishEventHandler(
                $database ?? $this->database(),
                new ApprovalRecipients(new ApprovalRouteResolver(new RevertRouteRepository($heldParishIds))),
                $mailer ?? new RecordingMailer(),
                new RevertClock(),
                $occurrences ?? new RecordingOccurrenceMaintenance(),
                new EventListingGeneration(),
                new DateTimeZone('Africa/Johannesburg')
            );
        }

        private function binding(): ActionTokenBinding
        {
            return $this->bindingFor(self::DEAN);
        }

        private function bindingFor(string $email): ActionTokenBinding
        {
            return new ActionTokenBinding(
                ActionTokenPurpose::UNPUBLISH_EVENT,
                'event_change',
                self::CHANGE_ID,
                $email
            );
        }

        /**
        * The state the amendment left the event in.
        *
        * @return array<string, mixed>
        */
        private function amendedMeta(): array
        {
            return [
                '_thumbnail_id' => 0,
                'parish_id' => self::PARISH_ID,
                'venue_id' => 8,
                'start_local' => '2026-10-12 09:00:00',
                'end_local' => '2026-10-12 12:00:00',
                'all_day' => '',
                'rrule' => '',
                'exdates' => '',
                'rdates' => '',
                'featured' => '',
                'status_flag' => '',
                'source_candidate_id' => 300,
                'contact' => 'contact@example.test',
            ];
        }

        /**
        * A change row whose before_payload is the same shape
        * WordPressPublicationStore::snapshot() writes, so the unpublish row's
        * before_payload is a real snapshot rather than a guess about the shape.
        */
        private function database(): RevertDatabase
        {
            $before = [
                'title' => 'Parish retreat day',
                'content' => 'The original description.',
                'excerpt' => 'Original excerpt.',
                'status' => 'publish',
                'event_type_term_ids' => [42],
                'featured_image_id' => 0,
                'meta' => array_merge($this->amendedMeta(), [
                    'venue_id' => 7,
                    'status_flag' => '',
                    'source_candidate_id' => 299,
                ]),
            ];
            $after = [
                'title' => 'Retreat day (renamed)',
                'content' => 'The amended description.',
                'excerpt' => 'Amended excerpt.',
                'status' => 'publish',
                'event_type_term_ids' => [43],
                'featured_image_id' => 0,
                'meta' => $this->amendedMeta(),
            ];

            $database = new RevertDatabase();
            $database->seedChange(
                self::CHANGE_ID,
                self::EVENT_ID,
                300,
                            self::CONTACT,
                'update',
                $before,
                $after
            );

            return $database;
        }
    }
}
