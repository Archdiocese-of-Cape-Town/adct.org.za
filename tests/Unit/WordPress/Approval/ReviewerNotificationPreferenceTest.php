<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval {

    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\WordPress\Approval\ReviewerNotificationPreference;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Throwable;
    use WP_User;

    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';

    /**
     * #171, third and last class: the per-user approval notification preference
     * on the WordPress profile screen.
     *
     * This one is deliberately *not* tested as an emailed-action handler. It has
     * no approver token, and so no live role re-resolution of the kind
     * ApprovalDecisionHandler and ApprovalEditHandler pin. The discipline is the
     * same in spirit — the guard has to be the thing that refuses — but the
     * guards here are a capability check, a nonce check and a value allow-list,
     * and each is exercised by attempting the save that bypasses it and then
     * reading the meta back to prove the refusal persisted nothing.
     *
     * Every refusal asserts two things: the response status the guard chose, and
     * that no meta was written. A test that only asserted "wp_die was reached"
     * would pass on a handler that wrote the preference and *then* died, which
     * on an admin surface is the failure that matters — the user sees a refusal
     * and their setting changed anyway.
     *
     * Stubs: tests/Support/WordPressStubs.php declares current_user_can(),
     * user_can(), wp_verify_nonce(), wp_die(), wp_nonce_field(), esc_html__(),
     * add_action(), update_user_meta() and friends for this namespace, and a
     * second declaration in one namespace is a fatal rather than a shadow. This
     * file therefore declares no functions of its own. wp_die() throws
     * \AdctTestWpDie carrying the response status, the same convention the
     * Admin-namespace stub already used.
     *
     * One limit is worth stating, because it is a limit of the harness rather
     * than of the handler. The stubbed current_user_can('edit_user', $id)
     * answers from the *acting* user's capabilities rather than from whether
     * the actor may edit that particular target, because resolving the
     * meta-capability properly needs the roles WordPress loaded for the current
     * user. So what is genuinely under test here is the user_can($target,
     * REVIEW) half of the guard; the edit_user half is pinned by user_can() in
     * the approval-namespace double and exercised end to end by
     * tests/Integration/ApprovalDecisionCheck.php.
     * testTheTargetIsGuardedSeparatelyFromTheActor() pins what can be verified
     * here.
     */
    final class ReviewerNotificationPreferenceTest extends TestCase
    {
        private const ACTOR_ID = 101;
        private const REVIEWER_ID = 202;
        private const OTHER_REVIEWER_ID = 303;

        private const MODE_META = 'adct_pi_approval_notify_mode';
        private const REMINDER_META = 'adct_pi_approval_reminders';

        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['adct_test_wp_users'] = [
                self::ACTOR_ID => new WP_User(self::ACTOR_ID, 'office@example.test'),
                self::REVIEWER_ID => new WP_User(self::REVIEWER_ID, 'reviewer@example.test'),
                self::OTHER_REVIEWER_ID => new WP_User(self::OTHER_REVIEWER_ID, 'colleague@example.test'),
            ];
            $GLOBALS['adct_test_current_user_id'] = self::ACTOR_ID;
            $GLOBALS['adct_test_wp_caps'] = [
                self::ACTOR_ID => ['edit_user'],
                self::REVIEWER_ID => [Capabilities::REVIEW],
                self::OTHER_REVIEWER_ID => [Capabilities::REVIEW],
            ];
            $GLOBALS['adct_test_wp_meta'] = [];
            $GLOBALS['adct_test_wp_meta_fails'] = [];
            $GLOBALS['adct_test_nonce_fields'] = [];
            $GLOBALS['adct_test_wp_actions'] = [];

            $_POST = [];
        }

        protected function tearDown(): void
        {
            foreach (
                [
                    'adct_test_wp_users',
                    'adct_test_current_user_id',
                    'adct_test_wp_caps',
                    'adct_test_wp_meta',
                    'adct_test_wp_meta_fails',
                    'adct_test_nonce_fields',
                    'adct_test_wp_actions',
                ] as $key
            ) {
                unset($GLOBALS[$key]);
            }

            $_POST = [];

            parent::tearDown();
        }

        /**
         * The non-vacuity counterpart every refusal below depends on: a caller
         * with the capability, holding the reviewer's own nonce, gets the
         * preference persisted. Without it, a handler that refused every save
         * would pass all of them.
         */
        public function testACallerWithTheRightCapabilityAndNonceCanSaveThePreference(): void
        {
            $this->postValid('digest', self::REVIEWER_ID, reminders: true);

            (new ReviewerNotificationPreference())->save(self::REVIEWER_ID);

            self::assertSame('digest', $this->meta(self::MODE_META, self::REVIEWER_ID));
            self::assertSame('1', $this->meta(self::REMINDER_META, self::REVIEWER_ID));
        }

        public function testTheTwoPreferencesAreSavedIndependently(): void
        {
            $this->postValid('each', self::REVIEWER_ID, reminders: false);

            (new ReviewerNotificationPreference())->save(self::REVIEWER_ID);

            self::assertSame('each', $this->meta(self::MODE_META, self::REVIEWER_ID));
            self::assertSame(
                '0',
                $this->meta(self::REMINDER_META, self::REVIEWER_ID),
                'An unticked checkbox never reaches $_POST, so absence is the off signal.'
            );
        }

        /**
         * A nonce is a CSRF token, not a permission. These are the cases a
         * "has some nonce" guard would wave through.
         */
        #[DataProvider('forgedNonces')]
        public function testAForgedOrWrongNonceDoesNotPersist(string $forged): void
        {
            $this->postValid('digest', self::REVIEWER_ID);
            $_POST['adct_pi_notify_nonce'] = $forged;

            self::assertSame(403, $this->saveRefusing(self::REVIEWER_ID), 'A bad nonce is a permission failure.');
            self::assertSame([], $GLOBALS['adct_test_wp_meta'], 'Nothing may be written.');
        }

        /**
         * @return array<string, list<string>>
         */
        public static function forgedNonces(): array
        {
            return [
                'a nonce minted for another reviewer' => ['nonce-for-adct_pi_notify_mode_303'],
                'a nonce for an unrelated action' => ['nonce-for-some-other-action'],
                'a token that is not a nonce at all' => ['not-a-nonce'],
                'an empty string' => [''],
            ];
        }

        /**
         * The user ID in the nonce action is the cross-user check, and it is the
         * only thing standing between a reviewer and a colleague's preference
         * form.
         */
        public function testANonceMintedForAnotherReviewerIsRefused(): void
        {
            $this->postValid('digest', self::REVIEWER_ID);
            $_POST['adct_pi_notify_nonce'] = 'nonce-for-adct_pi_notify_mode_' . self::OTHER_REVIEWER_ID;

            self::assertSame(403, $this->saveRefusing(self::REVIEWER_ID));
            self::assertSame([], $GLOBALS['adct_test_wp_meta'], 'A reviewer may not configure a colleague.');
        }

        public function testAFormWithNoNonceFieldAtAllDoesNotPersist(): void
        {
            $_POST['adct_pi_notify_mode'] = 'digest';

            self::assertSame(403, $this->saveRefusing(self::REVIEWER_ID));
            self::assertSame([], $GLOBALS['adct_test_wp_meta'], 'A stripped select must not be able to act alone.');
        }

        public function testANonStringNonceDoesNotPersist(): void
        {
            $this->postValid('digest', self::REVIEWER_ID);
            $_POST['adct_pi_notify_nonce'] = ['nonce-for-adct_pi_notify_mode_' . self::REVIEWER_ID];

            self::assertSame(403, $this->saveRefusing(self::REVIEWER_ID));
            self::assertSame([], $GLOBALS['adct_test_wp_meta'], 'An array nonce is not a verified nonce.');
        }

        /**
         * The target's own entitlement is checked separately from the caller's.
         * Removing the capability after the form was rendered is the
         * revoke-between-render-and-save shape: the administrator is still
         * allowed, but there is no longer anything to configure.
         */
        public function testTheTargetIsGuardedSeparatelyFromTheActor(): void
        {
            $GLOBALS['adct_test_wp_caps'][self::REVIEWER_ID] = [];
            $this->postValid('digest', self::REVIEWER_ID);

            self::assertSame(
                403,
                $this->saveRefusing(self::REVIEWER_ID),
                'There is no preference to configure for a user who is no longer a reviewer.'
            );
            self::assertSame([], $GLOBALS['adct_test_wp_meta']);
        }

        /**
         * The allow-list is strict on purpose. An unrecognised value is a 400
         * rather than a silently coerced default, so "somebody tampered with the
         * form" and "somebody chose a mode" cannot look the same downstream.
         */
        #[DataProvider('rejectedModes')]
        public function testOnlyEachAndDigestAreAccepted(string $mode): void
        {
            $this->postValid($mode, self::REVIEWER_ID);

            self::assertSame(
                400,
                $this->saveRefusing(self::REVIEWER_ID),
                'An unrecognised mode is a bad request, not a permission failure.'
            );
            self::assertSame([], $GLOBALS['adct_test_wp_meta']);
        }

        /**
         * @return array<string, list<string>>
         */
        public static function rejectedModes(): array
        {
            return [
                'empty' => [''],
                'whitespace' => ['  '],
                'the internal constant' => ['notify_each'],
                'a plausible neighbour' => ['dailydigest'],
                'an injected script tag' => ['digest<script>alert(1)</script>'],
                'an unknown future mode' => ['weekly'],
                'a case variant' => ['DIGEST'],
            ];
        }

        /**
         * A preference the page cannot render is indistinguishable from one that
         * was never set, so an unrecognised stored value must fall back to "as
         * events arrive" rather than marking neither option and leaving the
         * reviewer with a form that does not say what they chose.
         */
        public function testAnUnrecognisedStoredModeFallsBackToEachWhenRendering(): void
        {
            $GLOBALS['adct_test_wp_meta'][self::REVIEWER_ID][self::MODE_META] = 'weekly';

            $html = $this->render(self::REVIEWER_ID);

            self::assertMatchesRegularExpression(
                '/<option value="each"\s+selected=\'selected\'>/',
                $html,
                'An unknown mode must still mark exactly one option.'
            );
            self::assertSame(1, substr_count($html, 'selected='), 'A digest must not also be marked.');
        }

        public function testAStoredDigestIsRenderedAsSelected(): void
        {
            $GLOBALS['adct_test_wp_meta'][self::REVIEWER_ID][self::MODE_META] = 'digest';

            $html = $this->render(self::REVIEWER_ID);

            self::assertMatchesRegularExpression('/<option value="digest"\s+selected=\'selected\'>/', $html);
            self::assertSame(1, substr_count($html, 'selected='));
        }

        public function testAnUnsetModeFallsBackToEachWhenRendering(): void
        {
            $html = $this->render(self::REVIEWER_ID);

            self::assertSame('', $this->meta(self::MODE_META, self::REVIEWER_ID), 'Precondition: never chosen.');
            self::assertMatchesRegularExpression('/<option value="each"\s+selected=\'selected\'>/', $html);
        }

        /**
         * update_user_meta() can be accepted and then not stick. The read-back
         * is what turns that into a visible 503 instead of a preference the
         * reviewer believes they set and the reminder job never reads.
         */
        public function testAMetaWriteThatDoesNotStickIsRefusedRatherThanReportedAsSaved(): void
        {
            $this->postValid('digest', self::REVIEWER_ID);
            $GLOBALS['adct_test_wp_meta_fails'] = [self::MODE_META];

            self::assertSame(
                503,
                $this->saveRefusing(self::REVIEWER_ID),
                'A write that did not stick is a server fault, not a bad request.'
            );
        }

        /**
         * The reminder switch is a second, independent write with its own
         * read-back, so a failure there is reported too — and it is reported
         * after the mode has already been stored. That ordering is pinned
         * deliberately: what is asserted is that the fault is surfaced rather
         * than swallowed into a cheerful "settings saved".
         */
        public function testAReminderWriteThatDoesNotStickIsRefusedRatherThanReportedAsSaved(): void
        {
            $this->postValid('digest', self::REVIEWER_ID);
            $GLOBALS['adct_test_wp_meta_fails'] = [self::REMINDER_META];

            self::assertSame(503, $this->saveRefusing(self::REVIEWER_ID));
            self::assertSame(
                'digest',
                $this->meta(self::MODE_META, self::REVIEWER_ID),
                'The write that did succeed is left in place.'
            );
        }

        /**
         * render() is a surface a user without permission is shown, so silence is
         * the correct outcome — not a hidden field, and not the form without a
         * nonce field on it.
         */
        public function testRenderProducesNoOutputWhenTheTargetIsNotAReviewer(): void
        {
            $GLOBALS['adct_test_wp_caps'][self::REVIEWER_ID] = [];

            self::assertSame('', $this->render(self::REVIEWER_ID), 'There is nothing to configure for a non-reviewer.');
            self::assertSame([], $GLOBALS['adct_test_nonce_fields'], 'And so no nonce is minted for it.');
        }

        /**
         * The other half of the nonce guard: the form has to carry a nonce
         * field, minted for the action save() will verify. A field minted for
         * the wrong action renders perfectly and is refused on save.
         */
        public function testRenderMintsANonceFieldForTheActionSaveVerifies(): void
        {
            $this->render(self::REVIEWER_ID);

            self::assertContains(
                ['action' => 'adct_pi_notify_mode_' . self::REVIEWER_ID, 'name' => 'adct_pi_notify_nonce'],
                $GLOBALS['adct_test_nonce_fields'] ?? [],
                'The nonce must be minted for the action save() verifies.'
            );
        }

        public function testTheRenderedFormCarriesTheControlsSaveReads(): void
        {
                    // Pre-existing values, so "wrote nothing" is observed as "changed
                    // nothing" — an empty store would also be the result of a render
                    // that wrote an identical value, or of one that wrote nothing at all.
                    $GLOBALS['adct_test_wp_meta'][self::REVIEWER_ID][self::MODE_META] = 'each';
                    $GLOBALS['adct_test_wp_meta'][self::REVIEWER_ID][self::REMINDER_META] = '1';
                    $before = $GLOBALS['adct_test_wp_meta'];

                    $html = $this->render(self::REVIEWER_ID);

                    self::assertStringContainsString('<select id="adct_pi_notify_mode" name="adct_pi_notify_mode">', $html);
                    self::assertStringContainsString('<option value="each"', $html);
                    self::assertStringContainsString('<option value="digest"', $html);
                    self::assertStringContainsString('name="adct_pi_approval_reminders"', $html);
                    self::assertStringContainsString('name="adct_pi_notify_nonce"', $html);
                    self::assertSame($before, $GLOBALS['adct_test_wp_meta'], 'Rendering must not write anything.');
                }

        /**
         * The nonce action carries the user ID, so two reviewers' forms are not
         * interchangeable. Without that, a nonce lifted from one profile screen
         * would validate against the other.
         */
        public function testTheNonceFieldIsMintedPerUser(): void
        {
            $this->render(self::REVIEWER_ID);
            $this->render(self::OTHER_REVIEWER_ID);

            self::assertSame(
                [
                    ['action' => 'adct_pi_notify_mode_' . self::REVIEWER_ID, 'name' => 'adct_pi_notify_nonce'],
                    ['action' => 'adct_pi_notify_mode_' . self::OTHER_REVIEWER_ID, 'name' => 'adct_pi_notify_nonce'],
                ],
                $GLOBALS['adct_test_nonce_fields'] ?? []
            );
        }

        /**
         * An unticked checkbox never reaches $_POST, so absence is the "off"
         * signal. Asserting it is what stops a later refactor reading a missing
         * key as "leave it alone" and silently orphaning reviewers who had
         * deliberately switched reminders off.
         */
        public function testAnUntickedReminderCheckboxTurnsRemindersOff(): void
        {
            $GLOBALS['adct_test_wp_meta'][self::REVIEWER_ID][self::REMINDER_META] = '1';
            $this->postValid('each', self::REVIEWER_ID, reminders: false);

            (new ReviewerNotificationPreference())->save(self::REVIEWER_ID);

            self::assertSame('0', $this->meta(self::REMINDER_META, self::REVIEWER_ID));
        }

        /**
         * Reminders postdate some reviewers, so an unset key must not read as
         * switched off. Getting this backwards would silently mute every
         * reviewer who had never opened the form.
         */
        public function testTheReminderSwitchDefaultsToOnWhenNeverSet(): void
        {
            self::assertSame('', $this->meta(self::REMINDER_META, self::REVIEWER_ID), 'Precondition: never chosen.');

            $html = $this->render(self::REVIEWER_ID);

            self::assertMatchesRegularExpression(
                '/name="adct_pi_approval_reminders"[^>]*checked/',
                $html,
                'A reviewer who has never chosen should still get reminders.'
            );
        }

        /**
         * Every stored legacy value that means off must render unchecked. Reading
         * one of them as on nags a reviewer about a choice they already made.
         */
        #[DataProvider('remindersOffValues')]
        public function testEveryLegacyOffValueRendersUnchecked(string $stored): void
        {
            $GLOBALS['adct_test_wp_meta'][self::REVIEWER_ID][self::REMINDER_META] = $stored;

            $html = $this->render(self::REVIEWER_ID);

            self::assertStringNotContainsString('checked=', $html, 'A stored "' . $stored . '" means off.');
        }

        /**
         * @return array<string, list<string>>
         */
        public static function remindersOffValues(): array
        {
            return [
                'zero' => ['0'],
                'off' => ['off'],
                'false' => ['false'],
                'no' => ['no'],
            ];
        }

        #[DataProvider('remindersOnValues')]
        public function testAStoredOnValueRendersChecked(string $stored): void
        {
            $GLOBALS['adct_test_wp_meta'][self::REVIEWER_ID][self::REMINDER_META] = $stored;

            $html = $this->render(self::REVIEWER_ID);

            self::assertMatchesRegularExpression('/name="adct_pi_approval_reminders"[^>]*checked/', $html);
        }

        /**
         * @return array<string, list<string>>
         */
        public static function remindersOnValues(): array
        {
            return [
                'one' => ['1'],
                'true' => ['true'],
                'on' => ['on'],
            ];
        }

        /**
         * Neither guard fires when the form was not submitted at all. WordPress
         * fires edit_user_profile_update for every profile save, including ones
         * that never showed this form, so returning quietly is what keeps this
         * handler out of unrelated saves.
         */
        public function testAProfileSaveThatCarriesNoPreferenceFieldsIsIgnored(): void
        {
            $_POST['some_other_plugin_setting'] = 'x';

            (new ReviewerNotificationPreference())->save(self::REVIEWER_ID);

            self::assertSame([], $GLOBALS['adct_test_wp_meta'], 'Nothing may be written.');
        }

        /**
         * Both profile screens and both save hooks, so the preference works
         * whether a reviewer edits themselves or an administrator edits them.
         */
        public function testItRegistersOnBothProfileScreensAndBothSaveHooks(): void
        {
            self::assertSame([], $GLOBALS['adct_test_wp_actions'], 'No hook is registered until registerHooks() runs.');

            $preference = new ReviewerNotificationPreference();
            $preference->registerHooks();
            $actions = $GLOBALS['adct_test_wp_actions'];

            foreach (['show_user_profile', 'edit_user_profile'] as $hook) {
                self::assertContains(
                    [$preference, 'render'],
                    $actions[$hook] ?? [],
                    'A reviewer must find the form on the ' . $hook . ' screen.'
                );
            }

            foreach (['personal_options_update', 'edit_user_profile_update'] as $hook) {
                self::assertContains(
                    [$preference, 'save'],
                    $actions[$hook] ?? [],
                    'The preference must be saved from the ' . $hook . ' hook.'
                );
            }
        }

        /**
         * Labels are translated through esc_html__(); a translation carrying
         * markup must not reach the page as markup.
         */
        public function testRenderedLabelsAreEscaped(): void
        {
            $html = $this->render(self::REVIEWER_ID);

            self::assertStringNotContainsString('<script', $html);
            self::assertStringContainsString('<h2>', $html);
        }

        private function postValid(string $mode, int $userId, bool $reminders = false): void
        {
            $_POST['adct_pi_notify_nonce'] = 'nonce-for-adct_pi_notify_mode_' . $userId;
            $_POST['adct_pi_notify_mode'] = $mode;
            if ($reminders) {
                $_POST['adct_pi_approval_reminders'] = '1';
            }
        }

        /**
         * Runs save() expecting it to refuse, and returns the status the guard
         * chose. Every caller asserts on it, because wp_die() with no response
         * argument is a 500 — so a guard that stopped choosing one would still
         * throw and still look like it had refused.
         */
        private function saveRefusing(int $userId): int
        {
            try {
                (new ReviewerNotificationPreference())->save($userId);
            } catch (Throwable $died) {
                self::assertInstanceOf(
                    \AdctTestWpDie::class,
                    $died,
                    'The refusal must come from wp_die(), not from an unexpected error.'
                );

                /** @var \AdctTestWpDie $died */
                return $died->status;
            }

            self::fail('The save must be refused.');
        }

        /**
         * render() echoes, and the suite is strict about output during tests, so
         * the buffer is drained rather than printed.
         */
        private function render(int $userId): string
        {
            $user = $GLOBALS['adct_test_wp_users'][$userId] ?? new WP_User($userId, 'target@example.test');

            ob_start();
            try {
                (new ReviewerNotificationPreference())->render($user);
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        private function meta(string $key, int $userId): string
        {
            return (string) ($GLOBALS['adct_test_wp_meta'][$userId][$key] ?? '');
        }
    }
}