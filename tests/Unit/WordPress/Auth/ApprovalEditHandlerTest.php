<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
    use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
    use ADCT\ParishIntake\Core\Approval\Approver;
    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
    use ADCT\ParishIntake\Core\Auth\ActionTokenService;
    use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
    use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
    use ADCT\ParishIntake\WordPress\Auth\ApprovalEditHandler;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use DateTimeImmutable;
    use DateTimeZone;
    use DomainException;
    use LogicException;
    use PHPUnit\Framework\TestCase;
    use WP_User;

    /**
     * #171, second half: the correction link behind "Save corrections".
     *
     * The guarded entry points here are preview() and save(). perform() is not
     * one of them: it throws unconditionally, before any row is read and before
     * any transaction is opened, so an edit can never be committed from the bare
     * emailed path. That is deliberate, and it is asserted below together with
     * the proof that it changed nothing — a test written as "preview and
     * perform" would exercise a method that always throws and never touch the
     * one that can mutate state, passing while covering nothing.
     *
     * The guard is the same live re-resolution as in ApprovalDecisionHandler:
     * role() calls ApprovalRecipients::roleFor() at act time, so a dean who is
     * deactivated, stripped of APPROVE_DEANERY, or moved off the deanery between
     * the notice being mailed and the correction being saved is refused. Each
     * revocation test therefore mints the token while the recipient *is*
     * entitled, revokes, and then asserts that preview() is null, that save()
     * throws, that the row is byte-identical to before, and — the part that
     * makes a refusal safe — that the token is still VALID, so a mistyped click
     * does not burn the recipient's only way in.
     *
     * No WordPress stubs are declared here. tests/Support/WordPressStubs.php
     * already declares get_userdata(), user_can(), get_users() and
     * get_user_meta() in ADCT\ParishIntake\WordPress\Approval, and a second
     * declaration in the same namespace is a fatal, not a shadow. This harness
     * drives that copy through the same globals it reads. Nothing in this file
     * needs a global-namespace function either: the edit handler calls no
     * esc_*(), get_permalink() or other WordPress global.
     */
    final class ApprovalEditHandlerTest extends TestCase
    {
        private const DEAN = 'dean@example.test';
        private const REVIEWER = 'reviewer@example.test';

        private const DEAN_USER_ID = 101;
        private const REVIEWER_USER_ID = 202;

        /** The parish whose event this is. */
        private const PARISH_ID = 11;

        /** A second deanery, so "moved off the deanery" has something to take away. */
        private const OTHER_PARISH_ID = 12;

        private const CANDIDATE_ID = 7;

        /** Fixed so token lifetimes and the audit stamp are never read from "now". */
        private const NOW = '2026-09-25 08:00:00';

        /** The day-first correction an entitled approver is expected to be able to save. */
        private const CORRECTIONS = [
            'title' => 'Parish retreat day (corrected)',
            'event_date' => '12/10/2026',
            'event_time' => '10:30',
            'description' => 'Anonymised details, corrected.',
        ];

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

        /**
         * The non-vacuity counterpart every revocation test needs: an approver who
         * is still entitled gets a working preview and a saved correction.
         * Without it, a handler that refused every edit outright would pass
         * every refusal test below.
         */
        public function testAStillEntitledDeanMayCorrectSoTheRevocationTestsBelowCannotPassVacuously(): void
        {
            $database = $this->database();
            $binding = $this->binding();
            $preview = $this->handler($database)->preview($binding);

            self::assertNotNull(
                $preview,
                'A dean who still covers the parish must get a preview of the event they may correct.'
            );
            self::assertTrue(
                $preview->actionable,
                'A live dean is offered the correction form, not the "already decided" notice.'
            );
            self::assertSame('12/10/2026', $preview->formFields['event_date'] ?? null);

            $outcome = $this->save($database, $binding);

            self::assertStringContainsString('Corrections saved', $outcome->message);
            self::assertSame('2026-10-12', $this->fields($database)['event_date']);
            self::assertSame('10:30', $this->fields($database)['event_time']);
            self::assertContains('COMMIT', $database->transactions);
        }

        /**
         * Revocation #1: the dean keeps their account and their capability but now
                 * covers only another deanery. The route genuinely held this parish when
                 * the link was mailed; the withdrawal is what changes.
                 */
                public function testADeanMovedOffTheDeaneryIsRefusedOnBothGuardedEntryPointsAndChangesNothing(): void
                {
                    $routes = new EditRouteRepository([self::PARISH_ID, self::OTHER_PARISH_ID]);

                    $this->assertRefusedEverywhere(
                        revocation: static function () use ($routes): void {
                            // The route no longer names them for this parish.
                            $routes->hold([self::OTHER_PARISH_ID]);
                        },
                        heldParishIds: [self::PARISH_ID],
                        reason: 'moved off the deanery',
                        routes: $routes
                    );
                }

        /**
         * Revocation #2: the WordPress account is deactivated. The route still
         * names them and the capability is untouched, so only a live read of
         * user_status catches this.
         */
        public function testADeanWithADeactivatedAccountIsRefusedOnBothGuardedEntryPointsAndChangesNothing(): void
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
        public function testADeanWhoseCapabilityIsStrippedIsRefusedOnBothGuardedEntryPointsAndChangesNothing(): void
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
        public function testAReviewerWhoseReviewCapabilityIsStrippedIsRefusedOnBothGuardedEntryPointsAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: function (): void {
                    $GLOBALS['adct_test_wp_caps'][self::REVIEWER_USER_ID] = [];
                },
                heldParishIds: [self::PARISH_ID],
                recipient: self::REVIEWER,
                reason: 'stripped review capability'
            );
        }

        public function testAReviewerWithADeactivatedAccountIsRefusedOnBothGuardedEntryPointsAndChangesNothing(): void
        {
            $this->assertRefusedEverywhere(
                revocation: function (): void {
                    $GLOBALS['adct_test_wp_users'][self::REVIEWER_USER_ID]->user_status = 1;
                },
                heldParishIds: [self::PARISH_ID],
                recipient: self::REVIEWER,
                reason: 'deactivated reviewer account'
            );
        }

        /**
         * The counterpart for the deanery case: a reviewer is untouched by a
         * deanery change, so a correction link addressed to one still works.
         */
        public function testAuthorityThatWasNotRevokedStillWorks(): void
        {
            $database = $this->database();
            $binding = $this->binding(self::REVIEWER);

            self::assertNotNull(
                $this->handler($database)->preview($binding),
                'A reviewer holds archdiocese-wide authority, so a deanery change is irrelevant to them.'
            );

            self::assertStringContainsString(
                'Corrections saved',
                $this->save($database, $binding)->message
            );
        }

        /**
         * perform() never reaches the role check because it throws before
         * anything is read. It is asserted here anyway, together with the proof
         * that it changed nothing, so nobody later "helpfully" routes an edit
         * through the plain emailed path.
         */
        public function testTheBareEmailedPathCannotCommitAnEdit(): void
        {
            $database = $this->database();
            $original = $database->candidateRow();

            try {
                $this->handler($database)->perform($this->binding());
                self::fail('An edit must never be committed without a nonce-protected transactional POST.');
            } catch (LogicException $refused) {
                self::assertStringContainsString('nonce-protected transactional POST', $refused->getMessage());
            }

            self::assertSame([], $database->transactions, 'perform() must not even open a transaction.');
            self::assertSame([], $database->statements);
            self::assertSame([], $database->auditRows);
            self::assertSame($original, $database->candidateRow());
        }

        /**
         * The other half of that guarantee: a saved correction burns its own
         * link, so a replayed POST cannot rewrite the event a second time.
         */
        public function testASaveConsumesItsTokenAndASecondAttemptIsRefusedWithoutWritingAgain(): void
        {
            $database = $this->database();
            $binding = $this->binding();
            $tokens = $this->tokens($database);
            $secret = $this->mint($tokens, $binding);

            $this->save($database, $binding, $secret);
            $after = $this->fields($database);

            try {
                $this->save($database, $binding, $secret);
                self::fail('A correction link must be single use.');
            } catch (DomainException $refused) {
                self::assertStringContainsString('already been used', $refused->getMessage());
            }

            self::assertSame(
                $after,
                $this->fields($database),
                'The replayed save must not apply the corrections a second time.'
            );
            self::assertCount(1, $database->auditRows, 'One correction, one audit row.');
        }

        /**
         * A correction is not a decision: saving must leave the candidate exactly
         * as undecided as it was, so the approver still has to approve it.
         */
        public function testACorrectionDoesNotApproveOrDecideTheEvent(): void
        {
            $database = $this->database();
            $this->save($database, $this->binding());

            $row = $database->candidateRow();
            self::assertSame('awaiting_approval', $row['status']);
            self::assertNull($row['approved_by']);
            self::assertNull($row['approved_at']);
            self::assertNull($row['approved_via']);
            self::assertNull($row['decided_by']);
            self::assertNull($row['decided_at']);

            self::assertSame(
                'approver_edited',
                $database->auditRows[0]['action'],
                'The audit row must say the event was corrected, not approved.'
            );
        }

        /**
         * The audit trail for a correction records only the four editable
         * fields. Venue, parish and the parser's own metadata are not
         * approver-editable, so they must not appear in the before/after pair.
         */
        public function testTheAuditTrailRecordsOnlyTheFourEditableFields(): void
        {
            $database = $this->database();
            $this->save($database, $this->binding());

            $details = $database->auditRows[0]['details'];

                        // Membership, not order: the snapshot follows the stored field
                        // order of the candidate, which is not this test's business.
                        $editable = array_keys($details['after']);
                        sort($editable);
                        self::assertSame(
                            ['description', 'event_date', 'event_time', 'title'],
                            $editable,
                            'Only the four editable fields may appear in the after snapshot.'
                        );
            self::assertSame(
                            self::withKeysSorted([
                    'title' => 'Parish retreat day',
                    'event_date' => '2026-10-12',
                    'event_time' => '09:00',
                    'description' => 'Anonymised details.',
                            ]),
                            self::withKeysSorted($details['before']),
                'The before snapshot is what the parish submitted, not what the approver typed.'
            );
            self::assertArrayNotHasKey('venue', $details['before']);
            self::assertArrayNotHasKey('event_type', $details['before']);
        }

        /**
         * Day-first, because that is what a South African parish writes. A
         * month-first reading of 12/10 would silently save the wrong day.
         */
        public function testADayFirstDateIsStoredAsAnIsoDate(): void
        {
            $database = $this->database();

            $this->save($database, $this->binding());

            self::assertSame('2026-10-12', $this->fields($database)['event_date']);
        }

        /**
         * Every validation failure is raised before the transaction opens, so a
         * mistyped correction never leaves a half-written row behind and never
         * burns the link.
         */
        public function testValidationFailuresAreRaisedBeforeAnyTransactionOpens(): void
        {
            $invalid = [
                'a blank title' => ['title' => '   '],
                            // A month-first reading of 10/12 is indistinguishable from a
                            // correct day-first one (10 December), so no validator can
                            // refuse it; a 13th month cannot occur on any calendar, so it
                            // is refused by the round trip.
                            'an impossible month' => ['event_date' => '12/13/2026'],
                            'an impossible day' => ['event_date' => '31/02/2026'],
                            'an unpadded date' => ['event_date' => '1/1/2026'],
                'a missing time' => ['event_time' => ''],
                'a 24-hour time' => ['event_time' => '24:00'],
                'a loose time' => ['event_time' => '9:30'],
                'an over-long title' => ['title' => str_repeat('a', 256)],
                'an over-long description' => ['description' => str_repeat('a', 2001)],
            ];

            foreach ($invalid as $label => $override) {
                $database = $this->database();
                $binding = $this->binding();
                $tokens = $this->tokens($database);
                $secret = $this->mint($tokens, $binding);
                $original = $database->candidateRow();

                try {
                    $this->save($database, $binding, $secret, $override);
                    self::fail('An edit with ' . $label . ' must be refused.');
                } catch (DomainException $refused) {
                    self::assertStringContainsString('Enter a title', $refused->getMessage());
                }

                self::assertSame(
                    [],
                    $database->transactions,
                    'Validation must precede the transaction (' . $label . ').'
                );
                self::assertSame([], $database->statements, 'Nothing may be written (' . $label . ').');
                self::assertSame($original, $database->candidateRow(), 'The row must be untouched (' . $label . ').');
                self::assertSame(
                    ActionTokenStatus::VALID,
                    $tokens->inspect($secret)->status,
                    'A rejected correction must leave the link usable (' . $label . ').'
                );
            }
        }

        /**
         * A correction cannot move a multi-day event or a time range, because
         * the parser also recorded an end that this form cannot show.
         */
        public function testMovingTheDatesOfARangeOrMultiDayEventIsRefused(): void
        {
            foreach (['event_end_date' => '2026-10-13', 'event_end_time' => '16:00'] as $extra => $value) {
                $database = $this->database();
                $database->replaceFields([$extra => $value]);
                $binding = $this->binding();
                $tokens = $this->tokens($database);
                $secret = $this->mint($tokens, $binding);
                $original = $database->candidateRow();

                try {
                    $this->save($database, $binding, $secret);
                    self::fail('Moving the dates of an event with an ' . $extra . ' must be refused.');
                } catch (DomainException $refused) {
                    self::assertStringContainsString('contact the intake office', $refused->getMessage());
                }

                self::assertContains('ROLLBACK', $database->transactions);
                self::assertSame($original, $database->candidateRow());
                self::assertSame([], $database->auditRows);
                self::assertSame(
                    ActionTokenStatus::VALID,
                    $tokens->inspect($secret)->status,
                    'A refused correction must leave the link usable (' . $extra . ').'
                );
            }
        }

        /**
         * Leaving the dates alone is still allowed on a range event: only moving
                 * them needs the intake office, so a typo in the title can still be
                 * fixed from the same link.
                 */
                public function testARangeEventMayStillHaveItsTitleCorrected(): void
                {
                    $database = $this->database();
                    $database->replaceFields(['event_end_date' => '2026-10-13', 'event_end_time' => '16:00']);

                    $this->save(
                        $database,
                        $this->binding(),
                        null,
                        ['event_date' => '12/10/2026', 'event_time' => '09:00']
                    );

                    $fields = $this->fields($database);
                    self::assertSame('Parish retreat day (corrected)', $fields['title']);
                    self::assertSame('2026-10-12', $fields['event_date']);
                    self::assertSame('09:00', $fields['event_time']);
                    self::assertSame('2026-10-13', $fields['event_end_date']);
                    self::assertSame('16:00', $fields['event_end_time']);
                }

        /**
         * An event somebody else has already decided is closed to corrections, so
         * a stale correction link cannot rewrite a published event's text.
         */
        public function testAnAlreadyDecidedEventIsClosedToCorrections(): void
        {
            $database = $this->database();
            $database->decide(self::DEAN, '2026-09-25 06:00:00');
            $original = $database->candidateRow();

            self::assertFalse(
                $this->handler($database)->preview($this->binding())->actionable,
                'A decided event offers no correction form.'
            );

            try {
                $this->save($database, $this->binding());
                self::fail('A decided event must not be corrected through a stale link.');
            } catch (DomainException $refused) {
                self::assertStringContainsString('already decided', $refused->getMessage());
            }

            self::assertSame($original, $database->candidateRow());
            self::assertSame([], $database->auditRows);
        }

        /**
         * An ambiguous match needs a human to look at it, so the correction form
         * is closed even for a live approver.
                 *
                 * The matcher has exactly one representation for "possible repeat, no
                 * event selected": kind 'new' with a note, which EventCandidateRepository
                 * turns into fields['match_review_required'] = true. A
                 * fields['matched_candidate_id'] is only ever written alongside a kind
                 * of duplicate/cancellation/postponement/update, which the
                 * $row['match_kind'] !== 'new' gate already rejects, so that is not a
                 * second reachable spelling of this state.
                 */
                public function testAnAmbiguousMatchIsClosedToCorrections(): void
                {
                    $database = $this->database();
                    $database->replaceFields(['match_review_required' => true]);

                    self::assertFalse(
                        $this->handler($database)->preview($this->binding())->actionable,
                        'An ambiguous match offers no correction form.'
                    );

                    try {
                        $this->save($database, $this->binding());
                        self::fail('An ambiguous match must not be corrected through the link.');
                    } catch (DomainException $refused) {
                        self::assertStringContainsString('manual review', $refused->getMessage());
                    }

                    self::assertContains('ROLLBACK', $database->transactions);
                    self::assertSame([], $database->auditRows);
                }

        /**
         * A link for a candidate nobody was ever notified about is not ours to
         * honour, even for a dean who still holds the parish.
         */
        public function testALinkToAnApproverWhoWasNeverNotifiedIsRefused(): void
        {
            $database = $this->database();
            $database->mailStatus[self::REVIEWER] = 'failed';
            $binding = $this->binding(self::REVIEWER);
            $tokens = $this->tokens($database);
            $secret = $this->mint($tokens, $binding);
            $original = $database->candidateRow();

            self::assertNull(
                $this->handler($database)->preview($binding),
                'A reviewer whose notice was never deliverable has no editable link.'
            );

            try {
                $this->save($database, $binding, $secret);
                self::fail('A correction link for an undelivered notice must be refused.');
            } catch (DomainException $refused) {
                self::assertStringContainsString('no longer an approver', $refused->getMessage());
            }

            self::assertSame($original, $database->candidateRow());
            self::assertSame([], $database->auditRows);
            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($secret)->status,
                'A refusal must leave the link usable.'
            );
        }

        /**
         * Only an event_candidate subject may be corrected, whatever the
         * purpose, so an edit token cannot be pointed at another row type.
         */
        public function testATokenForAnotherPurposeOrSubjectTypeCannotReachTheCandidate(): void
        {
            foreach (
                [
                    new ActionTokenBinding(ActionTokenPurpose::APPROVE_EVENT, 'event_candidate', self::CANDIDATE_ID, self::DEAN),
                    new ActionTokenBinding(ActionTokenPurpose::EDIT, 'event', self::CANDIDATE_ID, self::DEAN),
                ] as $binding
            ) {
                $database = $this->database();
                $original = $database->candidateRow();

                self::assertNull(
                    $this->handler($database)->preview($binding),
                    'A binding this handler does not own must produce no preview.'
                );

                try {
                    $this->save($database, $binding);
                    self::fail('A binding this handler does not own must not save.');
                } catch (DomainException $refused) {
                    self::assertStringContainsString('no longer an approver', $refused->getMessage());
                }

                self::assertSame($original, $database->candidateRow());
                self::assertSame([], $database->auditRows);
            }
        }

        /**
                 * The kinds of revocation, checked as one group over both guarded entry
                 * points: refused, nothing written, and the link still good.
         *
         * @param callable(): void $revocation
         * @param list<int>        $heldParishIds
         */
        private function assertRefusedEverywhere(
            callable $revocation,
            array $heldParishIds,
            string $reason,
                    string $recipient = self::DEAN,
                    ?EditRouteRepository $routes = null
                 ): void {
                    // Mint while entitled: the point is that a link which was valid when
                    // it was mailed stops working, not that a stranger is turned away.
                    $database = $this->database();
                    $binding = $this->binding($recipient);
                    $routes ??= new EditRouteRepository($heldParishIds);

                    // The entitled handler is built before the token exists, because a
                    // live read of the route can only happen at act time: the preview
                    // below is what proves the link was good a moment ago.
                    $entitled = $this->handlerOver($database, $routes);

                    $tokens = $this->tokens($database);
                    $secret = $this->mint($tokens, $binding);
                    $original = $database->candidateRow();

                    $this->assertNotNull(
                        $entitled->preview($binding),
                        'The link must work before the revocation, or the test proves nothing (' . $reason . ').'
                    );

                    $revocation();
                    $handler = $this->handlerOver($database, $routes);

                    self::assertNull(
                        $handler->preview($binding),
                        'preview() must refuse a recipient whose authority is gone (' . $reason . ').'
                    );

                    try {
                        $this->saveOver($database, $binding, $secret, [], $routes);
                        self::fail('save() must refuse a recipient whose authority is gone (' . $reason . ').');
                    } catch (DomainException $refused) {
                        self::assertStringContainsString('no longer an approver', $refused->getMessage());
                    }

            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($secret)->status,
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
        }

        /**
         * The fake database is also the token store, so the service handed to
         * save() has to be the one bound to it.
         */
        private function tokens(EditDatabase $database): ActionTokenService
        {
            return new ActionTokenService($database, new EditClock());
        }

        private function mint(ActionTokenService $tokens, ActionTokenBinding $binding): string
        {
            return $tokens->issue($binding)->token();
        }

        /**
         * @param array<string, mixed> $edits
         * @param list<int>             $heldParishIds
         */
        private function save(
            EditDatabase $database,
            ActionTokenBinding $binding,
            ?string $secret = null,
            array $edits = [],
            array $heldParishIds = [self::PARISH_ID]
        ): ActionTokenOutcome {
            $tokens = $this->tokens($database);
            $secret ??= $this->mint($tokens, $binding);

                    return $this->handler($database, $heldParishIds)->save(
                $binding,
                $secret,
                $tokens,
                $edits + self::CORRECTIONS
            );
        }

        /**
         * @param list<int> $heldParishIds
         */
                private function handler(
                    EditDatabase $database,
                    array $heldParishIds = [self::PARISH_ID]
                ): ApprovalEditHandler {
                    return $this->handlerOver($database, new EditRouteRepository($heldParishIds));
                }

                /**
                 * The same handler, over a route repository the test can change between
                 * minting a link and acting on it.
                 */
                private function handlerOver(EditDatabase $database, EditRouteRepository $routes): ApprovalEditHandler
                {
                    return new ApprovalEditHandler(
                        $database,
                        new ApprovalRecipients(new ApprovalRouteResolver($routes)),
                        new EditClock()
                    );
                }

                /**
                 * @param array<string, mixed> $edits
                 */
                private function saveOver(
                    EditDatabase $database,
                    ActionTokenBinding $binding,
                    string $secret,
                    array $edits,
                    EditRouteRepository $routes
                ): ActionTokenOutcome {
                    return $this->handlerOver($database, $routes)->save(
                        $binding,
                        $secret,
                        $this->tokens($database),
                        $edits + self::CORRECTIONS
                    );
                }

        private function binding(string $recipient = self::DEAN): ActionTokenBinding
        {
            return new ActionTokenBinding(
                ActionTokenPurpose::EDIT,
                'event_candidate',
                self::CANDIDATE_ID,
                $recipient
            );
        }

        private function database(): EditDatabase
        {
            return new EditDatabase($this->candidateRow());
        }

        /**
         * @return array<string, mixed>
         */
        private function fields(EditDatabase $database): array
        {
            $fields = json_decode((string) $database->candidateRow()['fields'], true);

            return is_array($fields) ? $fields : [];
        }

                /**
                 * Compares values, not key order: the JSON object order comes from the
                 * stored candidate and is not what these assertions are about.
                 *
                 * @param array<string, mixed> $values
                 *
                 * @return array<string, mixed>
                 */
                private static function withKeysSorted(array $values): array
                {
                    ksort($values);

                    return $values;
                }

        /**
         * @return array<string, mixed>
         */
        private function candidateRow(): array
        {
            return [
                'id' => (string) self::CANDIDATE_ID,
                'message_id' => '55',
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
                'confirmed_by' => 'parish@example.test',
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
    final class EditRouteRepository implements ApprovalRouteRepositoryInterface
    {
        /**
         * @param list<int> $heldParishIds
         */
            public function __construct(private array $heldParishIds)
        {
        }

            /**
             * @param list<int> $heldParishIds
             */
            public function hold(array $heldParishIds): void
            {
                $this->heldParishIds = $heldParishIds;
            }

            public function findForParish(int $parishId): ?ApprovalRouteSnapshot
        {
            $dean = new Approver(
                1,
                EditFixture::DEAN_USER_ID,
                EditFixture::DEAN_EMAIL,
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

    final class EditFixture
    {
        public const DEAN_USER_ID = 101;
        public const DEAN_EMAIL = 'dean@example.test';
        public const PARISH_ID = 11;
        public const CANDIDATE_ID = 7;
        public const NOW = '2026-09-25 08:00:00';
    }

    /**
     * A stand-in for the candidates, notices, mail queue and audit log, which is
     * deliberately also the token store.
     *
     * Combining them is what makes the single most important assertion in this
     * file possible: that a rollback un-consumes the token, so a refused
     * correction does not burn the link. The real plugin gets that for free
     * because both go through the same $wpdb transaction; a fake that kept them
     * apart would let a genuine bug — consuming the token on a refused save —
     * pass unnoticed.
     */
    final class EditDatabase implements DatabaseConnectionInterface, ActionTokenStoreInterface
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
         * @var array<string, ActionTokenRecord>
         */
        private array $records = [];

        /**
                 * The token table as it stood when the open transaction began, which is
                 * what ROLLBACK puts back.
                 *
                 * @var array<string, ActionTokenRecord>
                 */
                private array $snapshot = [];

                /**
                 * The audit log as it stood when the open transaction began. Rows from
                 * an earlier save are already durable, so a rollback restores them
                 * rather than erasing them.
                 *
                 * @var list<array<string, mixed>>
                 */
                private array $auditSnapshot = [];

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
         * Audit rows visible to the test: staged ones inside a transaction are
         * only promoted on COMMIT, and dropped on ROLLBACK.
         *
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

            foreach ([EditFixture::DEAN_EMAIL, 'reviewer@example.test'] as $recipient) {
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
         * @param array<string, mixed> $overrides
         */
        public function replaceFields(array $overrides): void
        {
            $fields = json_decode((string) $this->candidate['fields'], true);
            $this->replaceCandidate(array_merge($this->candidate, [
                'fields' => (string) json_encode(
                    array_merge(is_array($fields) ? $fields : [], $overrides),
                    JSON_THROW_ON_ERROR
                ),
            ]));
        }

        /**
         * Marks the candidate as already decided, as another approver pressing
         * their button would have.
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
                     * Arguments are appended so the handler still has to supply every one of
                     * them, and nothing can be interpolated raw.
                     */
                    public function prefix(): string
        {
                        return 'wp_';
        }

                    public function prepare(string $query, mixed ...$arguments): string
                    {
                        return $query . ' /* ' . (string) json_encode($arguments, JSON_THROW_ON_ERROR) . ' */';
                    }

        public function query(string $query): int|false
        {
            $trimmed = trim($query);
            $upper = strtoupper($trimmed);

            if ($upper === 'START TRANSACTION') {
                $this->transactions[] = $upper;
                        $this->snapshot = $this->records;
                        $this->auditSnapshot = $this->auditRows;

                        return 0;
                    }

                    if ($upper === 'COMMIT') {
                        $this->transactions[] = $upper;
                        $this->candidate = array_merge($this->candidate, $this->pending);
                        $this->committed = $this->candidate;
                        $this->pending = [];
                        $this->commit();

                        return 0;
                    }

                    if ($upper === 'ROLLBACK') {
                        $this->transactions[] = $upper;
                        $this->candidate = $this->committed;
                        $this->pending = [];
                        $this->rollback();

                        return 0;
                    }

            $this->statements[] = $trimmed;
            $values = self::argumentsOf($trimmed);

            if (str_contains($trimmed, 'UPDATE `wp_adct_pi_event_candidates`')) {
                $this->pending = [
                    'fields' => (string) ($values[0] ?? '{}'),
                    'updated_at' => (string) ($values[1] ?? ''),
                ];

                return 1;
            }

            if (str_contains($trimmed, 'INSERT INTO `wp_adct_pi_audit_log`')) {
                            $this->pendingAudit[] = [
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

        /**
         * @var list<array<string, mixed>>
         */
        private array $pendingAudit = [];

        public function getRow(string $query): ?array
        {
            if (str_contains($query, 'adct_pi_event_candidates')) {
                return $this->candidate;
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
         * @return list<mixed>
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

        public function commit(): void
        {
                    $this->auditRows = array_merge($this->auditRows, $this->pendingAudit);
                    $this->pendingAudit = [];
                    $this->snapshot = $this->records;
                    $this->auditSnapshot = $this->auditRows;
                }

                /**
                 * Discards everything staged since START TRANSACTION, including the
                 * token row: in the real plugin both go through the same $wpdb
                 * transaction, so a save that refuses after consuming the token must
                 * put it back rather than burn the recipient's only way in.
                 */
                public function rollback(): void
                {
                    // Restores the transaction's starting point rather than emptying
                    // the log: rows committed by an earlier save are already durable,
                    // so a later refusal cannot erase them.
                    $this->auditRows = $this->auditSnapshot;
                    $this->pendingAudit = [];
                    $this->records = $this->snapshot;
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

    final class EditClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable(EditFixture::NOW, new DateTimeZone('Africa/Johannesburg'));
        }
    }
}