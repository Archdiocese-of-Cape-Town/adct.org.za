<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
    require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';
    require_once __DIR__ . '/../../../Support/NotifyModeFakes.php';
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    use ADCT\ParishIntake\Core\Approval\Approver;
    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
    use ADCT\ParishIntake\Core\Auth\ActionTokenService;
    use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Tests\Support\NotifyModeClock;
    use ADCT\ParishIntake\Tests\Support\NotifyModeDatabase;
    use ADCT\ParishIntake\WordPress\Auth\NotifyModeChangeHandler;
    use ADCT\ParishIntake\WordPress\Auth\NotifyModeField;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
    use DateTimeImmutable;
    use DateTimeZone;
    use DomainException;
    use LogicException;
    use PHPUnit\Framework\TestCase;
    use WP_User;

    /**
     * Issue #169: the link a deanery approver follows to change between per-item
     * notices and a daily digest without ever opening wp-admin.
     *
     * The guarded entry points here are preview() and save(). perform() is not
     * one of them: it throws before anything is read and before a transaction is
     * opened, which is what makes "a GET never acts" true at the handler rather
     * than only at the endpoint. It is asserted below together with the proof
     * that it changed nothing, because a test written as "preview and perform"
     * would exercise a method that always throws and never touch the one that
     * mutates state.
     *
     * The guard is live re-resolution, as in ApprovalDecisionHandler and
     * ApprovalEditHandler: the binding's WordPress user is read again at act
     * time, uncached, so a dean who is deactivated, stripped of
     * APPROVE_DEANERY, or moved off every deanery between the notice being
     * mailed and the button being pressed is refused. Each revocation test
     * therefore mints while the recipient *is* entitled, revokes, and then
     * asserts preview() is null, save() throws, no assignment moved, no audit
     * row was written, and the token is still VALID — so a mistyped click does
     * not burn the recipient's only way in.
     *
     * The token grants no approval authority. That is asserted directly rather
     * than inferred: this handler is registered against CHANGE_NOTIFY_MODE and
     * refuses a binding minted for any other purpose, so a dean who obtains one
     * still cannot approve, deny or publish anything with it.
     *
     * No WordPress stubs are declared in this file. WordPressStubs.php and
     * WordPressAuthDoubles.php already declare get_user_by() and user_can() in
     * ADCT\ParishIntake\WordPress\Auth, and a second declaration in the same
     * namespace is a fatal, not a shadow. This harness drives those copies
     * through the globals they read — adct_test_users and adct_test_caps for the
     * account directory, which is what get_user_by() and user_can() in this
     * namespace consult.
     */
    final class NotifyModeChangeHandlerTest extends TestCase
    {
        private const DEAN = NotifyModeDatabase::DEAN;

        /** A second approver, so the tests are not all about one person. */
        private const OTHER_DEAN = 'other@example.test';

        private const DEAN_USER_ID = NotifyModeDatabase::DEAN_USER_ID;
        private const OTHER_DEAN_USER_ID = 202;

        private const FIRST_DEANERY_ID = NotifyModeDatabase::FIRST_DEANERY_ID;
        private const SECOND_DEANERY_ID = NotifyModeDatabase::SECOND_DEANERY_ID;

        private const ASSIGNMENT_ID = NotifyModeDatabase::ASSIGNMENT_ID;
        private const SECOND_ASSIGNMENT_ID = NotifyModeDatabase::SECOND_ASSIGNMENT_ID;

        /** Fixed so the audit stamp and the seven-day lifetime are never read from "now". */
        private const NOW = '2026-09-25 08:00:00';

        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['adct_test_users'] = [
                self::DEAN => new WP_User(self::DEAN_USER_ID, self::DEAN),
                self::OTHER_DEAN => new WP_User(self::OTHER_DEAN_USER_ID, self::OTHER_DEAN),
            ];
            $GLOBALS['adct_test_caps'] = [
                self::DEAN_USER_ID => [Capabilities::APPROVE_DEANERY],
                self::OTHER_DEAN_USER_ID => [Capabilities::APPROVE_DEANERY],
            ];
            $GLOBALS['adct_test_cookies'] = [];
        }

        protected function tearDown(): void
        {
            foreach (['adct_test_users', 'adct_test_caps', 'adct_test_cookies'] as $key) {
                unset($GLOBALS[$key]);
            }

            parent::tearDown();
        }

        /**
         * The non-vacuity counterpart every refusal test needs. Without it a
         * handler that refused every change would pass all of them.
     */
        public function testADeanMayMoveToTheDailyDigestWithoutOpeningWpAdmin(): void
        {
            $database = $this->database();
            $binding = $this->binding();
            $preview = $this->handler($database)->preview($binding);

            self::assertNotNull(
                $preview,
                'A live deanery approver must be offered the choice.'
            );
            self::assertTrue($preview->actionable, 'The confirmation page must offer a form.');
            self::assertSame(
                Approver::NOTIFY_EACH,
                $preview->formFields[NotifyModeField::FORM_FIELD] ?? null,
                'The form must preselect what is stored today, not default to something else.'
            );

            $outcome = $this->save($database, $binding, Approver::NOTIFY_DIGEST);

            self::assertStringContainsString('one email a day', $outcome->message);
            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $database->assignmentRow(self::ASSIGNMENT_ID)['notify_mode']
            );
            self::assertContains('COMMIT', $database->transactions);
        }

        /**
         * A digest choice made from the mail takes effect for the next candidate,
         * which is what ApprovalRecipients reads. The mode is stored on the
         * assignment and read back through the same value object the notice job
         * already used, so this is the seam rather than a parallel one.
     */
        public function testTheStoredModeIsReadBackThroughTheSameShapeTheNoticeJobReads(): void
        {
            $database = $this->database();

            $this->save($database, $this->binding(), Approver::NOTIFY_DIGEST);

            $approver = new Approver(
                1,
                self::DEAN_USER_ID,
                self::DEAN,
                'Dean',
                $database->assignmentRow(self::ASSIGNMENT_ID)['notify_mode'],
                true,
                true
            );

            self::assertSame(Approver::NOTIFY_DIGEST, $approver->notifyMode);
            self::assertTrue(Approver::isValidNotifyMode($approver->notifyMode));
        }

        /**
         * Moving back the other way is equally available; the link is not a
         * one-shot opt-in.
     */
        public function testADeanOnTheDigestCanMoveBackToPerItemNotices(): void
        {
            $database = $this->database();
            $database->setNotifyMode(self::ASSIGNMENT_ID, Approver::NOTIFY_DIGEST);
            $binding = $this->binding();

            $preview = $this->handler($database)->preview($binding);

            self::assertNotNull($preview);
            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $preview->formFields[NotifyModeField::FORM_FIELD] ?? null,
                'The form must show the digest as the current setting.'
            );

            $outcome = $this->save($database, $binding, Approver::NOTIFY_EACH);

            self::assertStringContainsString('each time', $outcome->message);
            self::assertSame(
                Approver::NOTIFY_EACH,
                $database->assignmentRow(self::ASSIGNMENT_ID)['notify_mode']
            );
        }

        /**
         * Documented multi-deanery behaviour (#169 asks for a defined answer):
         * the choice applies to every deanery the person is live in. The issue
         * allowed either that or an explicit per-deanery choice; one control
         * that means one thing is the better fit for a non-technical recipient,
         * and an administrator can still set them differently on the Deaneries
         * screen.
     */
        public function testADeanOfTwoDeanerysHasBothAssignmentsChanged(): void
        {
            $database = $this->database();

            $this->save($database, $this->binding(), Approver::NOTIFY_DIGEST);

            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $database->assignmentRow(self::ASSIGNMENT_ID)['notify_mode']
            );
            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $database->assignmentRow(self::SECOND_ASSIGNMENT_ID)['notify_mode']
            );
            self::assertSame(
                [self::FIRST_DEANERY_ID, self::SECOND_DEANERY_ID],
                $database->auditRows[0]['details']['deanery_ids'] ?? null,
                'The audit row must name every deanery the choice reached.'
            );
        }

        /**
         * A deanery that has been deactivated is not somewhere this person is
         * being notified about, so it is not somewhere they can be choosing a
         * mode for. It must also not appear in the audit trail, or the record
         * would claim a change that was never made.
     */
        public function testADeactivatedDeaneryIsNotChangedAndIsNotNamedInTheAuditTrail(): void
        {
            $database = $this->database();
            $database->deactivateDeanery(self::SECOND_DEANERY_ID);

            $this->save($database, $this->binding(), Approver::NOTIFY_DIGEST);

            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $database->assignmentRow(self::ASSIGNMENT_ID)['notify_mode']
            );
            self::assertSame(
                Approver::NOTIFY_EACH,
                $database->assignmentRow(self::SECOND_ASSIGNMENT_ID)['notify_mode'],
                'A deanery that is no longer live must keep the setting it had.'
            );
            self::assertSame(
                [self::FIRST_DEANERY_ID],
                $database->auditRows[0]['details']['deanery_ids'] ?? null
            );
        }

        /**
         * #171's rule, applied here: the set of deaneries that changes is read
         * at act time, not taken from what was true when the mail went out. A
         * dean moved from two deaneries to one between the notice and the click
         * must change only the one they still hold.
     */
        public function testTheSetChangedIsTheSetHeldNowNotTheSetHeldWhenTheLinkWasMailed(): void
        {
            $database = $this->database();
            $tokens = $this->tokens($database);
            $binding = $this->binding();
            $secret = $tokens->issue($binding)->token();

            self::assertNotNull(
                $this->handler($database)->preview($binding),
                'The link is good while both deaneries are held.'
            );

            // The second assignment is withdrawn after the mail was sent.
            $database->deactivateDeanery(self::SECOND_DEANERY_ID);

            $this->save($database, $binding, Approver::NOTIFY_DIGEST, $secret);

            self::assertSame(
                Approver::NOTIFY_EACH,
                $database->assignmentRow(self::SECOND_ASSIGNMENT_ID)['notify_mode'],
                'An assignment withdrawn between the mail and the click must not change.'
            );
            self::assertSame(
                [self::FIRST_DEANERY_ID],
                $database->auditRows[0]['details']['deanery_ids'] ?? null
            );
        }

        /**
         * The revocable entitlements, checked as one group over both guarded
         * entry points: refused, nothing written, and the link still good.
         *
         * @param callable(NotifyModeDatabase): void $revocation
     */
        private function assertRefusedEverywhere(callable $revocation, string $reason): void
        {
            $database = $this->database();
            $binding = $this->binding();
            $tokens = $this->tokens($database);
            $secret = $tokens->issue($binding)->token();

            self::assertNotNull(
                $this->handler($database)->preview($binding),
                'The link must work before the revocation, or the test proves nothing ('
                    . $reason . ').'
            );

            $revocation($database);

            // Snapshot after the revocation, so a refusal is compared against the
            // state it was refused in rather than against the state before it.
            $original = $database->assignmentRows();
            $handler = $this->handler($database);

            self::assertNull(
                $handler->preview($binding),
                'preview() must refuse a recipient whose entitlement is gone (' . $reason . ').'
            );

            try {
                $handler->save($binding, $secret, $tokens, Approver::NOTIFY_DIGEST);
                self::fail('save() must refuse a recipient whose entitlement is gone (' . $reason . ').');
            } catch (DomainException $refused) {
                self::assertStringContainsString('no longer an approver', $refused->getMessage());
            }

            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($secret)->status,
                'A refusal must leave the link usable (' . $reason . ').'
            );
            self::assertContains('ROLLBACK', $database->transactions);
            self::assertNotContains('COMMIT', $database->transactions);
            self::assertSame(
                $original,
                $database->assignmentRows(),
                'A refusal must not touch any assignment row (' . $reason . ').'
            );
            self::assertSame([], $database->auditRows, 'A refusal must leave no audit trail (' . $reason . ').');
            self::assertSame([], $database->writes, 'A refusal must write nothing (' . $reason . ').');
        }

        public function testADeanMovedOffEveryDeaneryIsRefusedOnBothGuardedEntryPointsAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: static function (NotifyModeDatabase $database): void {
                    $database->deactivateAssignment(NotifyModeDatabase::ASSIGNMENT_ID);
                    $database->deactivateAssignment(NotifyModeDatabase::SECOND_ASSIGNMENT_ID);
                },
                reason: 'moved off every deanery'
            );
        }

        public function testADeanWhoseAssignmentsAreAllDeactivatedIsRefusedAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: static function (NotifyModeDatabase $database): void {
                    $database->deactivateDeanery(self::FIRST_DEANERY_ID);
                    $database->deactivateDeanery(self::SECOND_DEANERY_ID);
                },
                reason: 'every deanery deactivated'
            );
        }

        public function testADeanWithADeactivatedAccountIsRefusedOnBothGuardedEntryPointsAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: function (): void {
                    $GLOBALS['adct_test_users'][self::DEAN]->user_status = 1;
                },
                reason: 'deactivated account'
            );
        }

        public function testADeanWhoseCapabilityIsStrippedIsRefusedOnBothGuardedEntryPointsAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: function (): void {
                    $GLOBALS['adct_test_caps'][self::DEAN_USER_ID] = [];
                },
                reason: 'stripped capability'
            );
        }

        /**
         * The account's own address changed. The mail went to the old one, so
         * the holder of the new one must not inherit the link.
     */
        public function testADeanWhoseAccountEmailChangedIsRefusedAndChangesNothing(): void
        {
            $database = $this->database();
            $binding = $this->binding();
            $tokens = $this->tokens($database);
            $secret = $tokens->issue($binding)->token();
            $original = $database->assignmentRows();

            $GLOBALS['adct_test_users'][self::DEAN]->user_email = 'moved@example.test';

            self::assertNull($this->handler($database)->preview($binding));

            try {
                $this->handler($database)->save($binding, $secret, $tokens, Approver::NOTIFY_DIGEST);
                self::fail('A link for a reassigned address must be refused.');
            } catch (DomainException $refused) {
                self::assertStringContainsString('no longer an approver', $refused->getMessage());
            }

            self::assertSame($original, $database->assignmentRows());
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($secret)->status);
        }

        /**
         * A stranger's address is refused for the same reason: the binding names
         * a WordPress user ID, and the ID must belong to the address the token
         * was mailed to.
     */
        public function testALinkWhoseBoundUserIsSomebodyElseIsRefusedAndChangesNothing(): void
        {
            $database = $this->database();
            $binding = new ActionTokenBinding(
                ActionTokenPurpose::CHANGE_NOTIFY_MODE,
                NotifyModeChangeHandler::SUBJECT_TYPE,
                self::OTHER_DEAN_USER_ID,
                self::DEAN
            );
            $tokens = $this->tokens($database);
            $secret = $tokens->issue($binding)->token();

            // Non-vacuity: the only difference is the subject id. While it is
            // the ID of the user behind this address, the very same link works,
            // so a test that cannot tell the two apart is testing nothing.
            $entitled = new ActionTokenBinding(
                ActionTokenPurpose::CHANGE_NOTIFY_MODE,
                NotifyModeChangeHandler::SUBJECT_TYPE,
                self::DEAN_USER_ID,
                self::DEAN
            );

            self::assertNotNull(
                $this->handler($database)->preview($entitled),
                'The link must work while the subject id is the bound user, or the refusal proves nothing.'
            );

            $original = $database->assignmentRows();

            self::assertNull(
                $this->handler($database)->preview($binding),
                'The bound user ID must be the user behind the bound address.'
            );

            try {
                $this->handler($database)->save($binding, $secret, $tokens, Approver::NOTIFY_DIGEST);
                self::fail('A mismatched user ID must be refused.');
            } catch (DomainException $refused) {
                self::assertStringContainsString('no longer an approver', $refused->getMessage());
            }

            self::assertContains('ROLLBACK', $database->transactions);
            self::assertNotContains('COMMIT', $database->transactions);
            self::assertSame(
                $original,
                $database->assignmentRows(),
                'A mismatched subject id must not touch an assignment row.'
            );
            self::assertSame([], $database->writes);
            self::assertSame([], $database->auditRows);
            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($secret)->status,
                'A refusal must leave the link usable.'
            );
        }

        /**
         * One person may not change another's setting by being handed their
         * link, and may not change their own through a binding that does not
         * belong to this handler.
     */
        public function testATokenForAnotherPurposeOrSubjectTypeCannotChangeAPreference(): void
        {
            // Non-vacuity: this exact binding, in the one combination this
            // handler owns, does change the preference. Nothing below is proved
            // unless that first half is on the record.
            $control = $this->database();
            $controlBinding = new ActionTokenBinding(
                ActionTokenPurpose::CHANGE_NOTIFY_MODE,
                NotifyModeChangeHandler::SUBJECT_TYPE,
                self::DEAN_USER_ID,
                self::DEAN
            );

            self::assertNotNull(
                $this->handler($control)->preview($controlBinding),
                'The combination this handler owns must produce a preview, or the refusals below prove nothing.'
            );
            $this->save($control, $controlBinding, Approver::NOTIFY_DIGEST);
            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $control->assignmentRow(self::ASSIGNMENT_ID)['notify_mode'],
                'The combination this handler owns must change the preference.'
            );

            foreach (
                [
                    // An approval link, in the shape it is really minted in.
                    new ActionTokenBinding(
                        ActionTokenPurpose::APPROVE_EVENT,
                        'event_candidate',
                        self::ASSIGNMENT_ID,
                        self::DEAN
                    ),
                    // Both of the next two name the dean's own account correctly,
                    // so resolve() alone would wave them through and the save
                    // would commit. Only isForeign() stands between them and a
                    // preference changed under a purpose or subject type that
                    // never granted that power.
                    new ActionTokenBinding(
                        ActionTokenPurpose::CHANGE_NOTIFY_MODE,
                        'event_candidate',
                        self::DEAN_USER_ID,
                        self::DEAN
                    ),
                    new ActionTokenBinding(
                        ActionTokenPurpose::APPROVE_EVENT,
                        NotifyModeChangeHandler::SUBJECT_TYPE,
                        self::DEAN_USER_ID,
                        self::DEAN
                    ),
                ] as $binding
            ) {
                $database = $this->database();
                $original = $database->assignmentRows();

                self::assertNull(
                    $this->handler($database)->preview($binding),
                    'A binding this handler does not own must produce no preview.'
                );

                try {
                    $this->save($database, $binding, Approver::NOTIFY_DIGEST);
                    self::fail('A binding this handler does not own must not change anything.');
                } catch (DomainException $refused) {
                    self::assertStringContainsString('no longer an approver', $refused->getMessage());
                }

                self::assertContains('ROLLBACK', $database->transactions);
                self::assertNotContains('COMMIT', $database->transactions);
                self::assertSame($original, $database->assignmentRows());
                self::assertSame([], $database->writes);
                self::assertSame([], $database->auditRows);
            }
        }

        /**
         * The token changes how often a dean is emailed. It confers no approval
         * authority, and the handler cannot become a route to any event: it is
         * registered for one purpose only and refuses every other binding above.
         * Asserted so the property is on the record rather than assumed.
     */
        public function testThePreferenceTokenGrantsNoApprovalAuthority(): void
        {
            $database = $this->database();
            $handler = $this->handler($database);

            self::assertSame(
                ActionTokenPurpose::CHANGE_NOTIFY_MODE,
                $handler->purpose(),
                'The handler may only be reachable through the notify-mode purpose.'
            );

            $approvalLink = new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'event_candidate',
                7,
                self::DEAN
            );

            self::assertNull(
                $handler->preview($approvalLink),
                'An approval binding must not render through the preference handler.'
            );

            $this->save($database, $this->binding(), Approver::NOTIFY_DIGEST);

            self::assertSame(
                ['approver_notify_mode_changed'],
                array_column($database->auditRows, 'action'),
                'The only trace of this flow is the preference change itself.'
            );
        }

        /**
         * perform() never reaches the checks because it throws before anything is
         * read. Asserted anyway, with the proof that it changed nothing, so
         * nobody later routes a preference change through a bare emailed path.
     */
        public function testTheBareEmailedPathCannotChangeThePreference(): void
        {
            $database = $this->database();
            $original = $database->assignmentRows();

            try {
                $this->handler($database)->perform($this->binding());
                self::fail('A preference change must never commit without a nonce-protected POST.');
            } catch (LogicException $refused) {
                self::assertStringContainsString('nonce-protected transactional POST', $refused->getMessage());
            }

            self::assertSame([], $database->transactions, 'perform() must not even open a transaction.');
            self::assertSame([], $database->writes);
            self::assertSame([], $database->auditRows);
            self::assertSame($original, $database->assignmentRows());
        }

        /**
         * A saved change burns its own link, so a replayed POST cannot rewrite
         * somebody's notification frequency over their head.
     */
        public function testASaveConsumesItsTokenAndASecondAttemptIsRefusedWithoutWritingAgain(): void
        {
            $database = $this->database();
            $binding = $this->binding();
            $tokens = $this->tokens($database);
            $secret = $tokens->issue($binding)->token();

            $this->save($database, $binding, Approver::NOTIFY_DIGEST, $secret);

            try {
                $this->save($database, $binding, Approver::NOTIFY_DIGEST, $secret);
                self::fail('A preference link must be single use.');
            } catch (DomainException $refused) {
                self::assertStringContainsString('already been used', $refused->getMessage());
            }

            self::assertCount(1, $database->auditRows, 'One change, one audit row.');
            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $database->assignmentRow(self::ASSIGNMENT_ID)['notify_mode']
            );
        }

        /**
         * The expiry, checked against an injected clock rather than "now". The
         * lifetime is seven days, chosen because a dean's attention to one of
         * these mails is measured in days, not minutes — but long enough that
         * the token store cannot be treated as a permanent capability.
     */
        public function testALinkExpiresAfterItsLifetime(): void
        {
            $database = $this->database();
            $binding = $this->binding();
            $clock = new NotifyModeClock('2026-09-25 08:00:00');
            $tokens = new ActionTokenService($database, $clock);
            $secret = $tokens->issue($binding)->token();
            $original = $database->assignmentRows();

            $later = new NotifyModeClock('2026-10-02 08:00:01');

            self::assertSame(
                ActionTokenStatus::EXPIRED,
                (new ActionTokenService($database, $later))->inspect($secret)->status,
                'The link must stop working once its lifetime has passed.'
            );

            try {
                $this->handlerOver($database, $later)
                    ->save($binding, $secret, new ActionTokenService($database, $later), Approver::NOTIFY_DIGEST);
                self::fail('An expired link must not change a preference.');
            } catch (DomainException $refused) {
                self::assertStringContainsString('already been used', $refused->getMessage());
            }

            self::assertSame($original, $database->assignmentRows());
            self::assertSame([], $database->auditRows);
        }

        public function testTheLifetimeIsSevenDays(): void
        {
            self::assertSame(
                7 * 24 * 60 * 60,
                ActionTokenPurpose::CHANGE_NOTIFY_MODE->defaultLifetimeSeconds()
            );
        }

        /**
         * Only the two stored values are acceptable, so a crafted POST cannot
         * put anything else in the column. Rejected before the transaction
         * opens, and without spending the link, so a bad radio group is
         * recoverable by pressing the button again.
     */
        public function testAModeOutsideTheStoredSetIsRefusedBeforeAnyTransactionOpens(): void
        {
            foreach (['', '   ', 'EACH', 'digest; DROP TABLE x', 'none', '0'] as $mode) {
                $database = $this->database();
                $binding = $this->binding();
                $tokens = $this->tokens($database);
                $secret = $tokens->issue($binding)->token();
                $original = $database->assignmentRows();

                try {
                    $this->handler($database)->save($binding, $secret, $tokens, $mode);
                    self::fail(sprintf('The mode %s must be refused.', var_export($mode, true)));
                } catch (DomainException $refused) {
                    self::assertStringContainsString('either an email for each event', $refused->getMessage());
                }

                self::assertSame(
                    [],
                    $database->transactions,
                    'Validation must precede the transaction ('
                        . var_export($mode, true) . ').'
                );
                self::assertSame([], $database->writes);
                self::assertSame($original, $database->assignmentRows());
                self::assertSame(
                    ActionTokenStatus::VALID,
                    $tokens->inspect($secret)->status,
                    'A rejected submission must leave the link usable.'
                );
            }
        }

        /**
         * A dean whose deaneries are currently set differently is told so, so a
         * single choice that makes them all the same is not a surprise.
     */
        public function testAMixedConfigurationIsCalledOutOnTheConfirmationPage(): void
        {
            $database = $this->database();
            $database->setNotifyMode(self::SECOND_ASSIGNMENT_ID, Approver::NOTIFY_DIGEST);

            $preview = $this->handler($database)->preview($this->binding());

            self::assertNotNull($preview);
            self::assertStringContainsString(
                'This applies to every deanery you approve for (2 in total)',
                implode(' ', $preview->details)
            );
            self::assertStringContainsString(
                'currently set differently',
                implode(' ', $preview->details)
            );
        }

        /**
         * The audit row names what changed and for whom, which is what makes the
         * two-place preference split auditable: a dean's mode lives on the
         * assignment, and this row is the trail of who pressed the button.
     */
        public function testTheAuditRowNamesTheActorTheChangeAndEveryDeaneryItReached(): void
        {
            $database = $this->database();

            $this->save($database, $this->binding(), Approver::NOTIFY_DIGEST);

            self::assertSame(
                [
                    'actor' => self::DEAN,
                    'action' => 'approver_notify_mode_changed',
                    'subjectType' => 'approval_preference',
                    'subjectId' => self::DEAN_USER_ID,
                ],
                array_intersect_key(
                    $database->auditRows[0],
                    array_flip(['actor', 'action', 'subjectType', 'subjectId'])
                )
            );

            $details = $database->auditRows[0]['details'];

            self::assertSame(Approver::NOTIFY_EACH, $details['from'] ?? null);
            self::assertSame(Approver::NOTIFY_DIGEST, $details['to'] ?? null);
            self::assertSame(2, $details['changed'] ?? null, 'Both deaneries moved from each to digest.');
            self::assertSame([self::FIRST_DEANERY_ID, self::SECOND_DEANERY_ID], $details['deanery_ids'] ?? null);
        }

        /**
         * The stamp comes from the injected clock, in UTC as every other audit
         * row is, so the row sorts and compares consistently regardless of the
         * server's timezone.
     */
        public function testTheAuditStampComesFromTheInjectedClockInUtc(): void
        {
            $database = $this->database();

            $this->save($database, $this->binding(), Approver::NOTIFY_DIGEST);

            self::assertSame('2026-09-25 06:00:00', $database->auditRows[0]['createdAt'] ?? null);
            self::assertSame(
                '2026-09-25 06:00:00',
                $database->assignmentRow(self::ASSIGNMENT_ID)['updated_at'],
                'The assignment row is stamped the same way.'
            );
        }

        private function tokens(NotifyModeDatabase $database): ActionTokenService
        {
            return new ActionTokenService($database, new NotifyModeClock(self::NOW));
        }

        private function mint(ActionTokenService $tokens, ActionTokenBinding $binding): string
        {
            return $tokens->issue($binding)->token();
        }

        private function save(
            NotifyModeDatabase $database,
            ActionTokenBinding $binding,
            string $mode,
            ?string $secret = null
        ): ActionTokenOutcome {
            return $this->handler($database)->save(
                $binding,
                $secret ?? $this->mint($this->tokens($database), $binding),
                $this->tokens($database),
                $mode
            );
        }

        private function handler(NotifyModeDatabase $database): NotifyModeChangeHandler
        {
            return $this->handlerOver($database, new NotifyModeClock(self::NOW));
        }

        private function handlerOver(
            NotifyModeDatabase $database,
            ClockInterface $clock
        ): NotifyModeChangeHandler {
            return new NotifyModeChangeHandler(
                $database,
                new DeaneryApproverRepository($database),
                $clock
            );
        }

        private function binding(string $recipient = self::DEAN): ActionTokenBinding
        {
            return new ActionTokenBinding(
                ActionTokenPurpose::CHANGE_NOTIFY_MODE,
                NotifyModeChangeHandler::SUBJECT_TYPE,
                self::DEAN_USER_ID,
                $recipient
            );
        }

        private function database(): NotifyModeDatabase
        {
            return new NotifyModeDatabase();
        }
    }
}
