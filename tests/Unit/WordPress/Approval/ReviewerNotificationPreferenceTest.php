<?php

declare(strict_types=1);

namespace {

    require_once __DIR__ . '/../../../Support/WordPressStubs.php';

    // The global functions ReviewerNotificationPreference calls. Declared here
    // rather than in the shared stub file because these tests drive the values
    // and need to restore them between cases.
    if (! function_exists('wp_create_nonce')) {
        function wp_create_nonce(string $action): string
        {
            return 'nonce:' . $action;
        }
    }

    if (! function_exists('wp_verify_nonce')) {
        function wp_verify_nonce(string $nonce, string $action): string|false
        {
            return $nonce === 'nonce:' . $action ? '1' : false;
        }
    }

    if (! function_exists('wp_nonce_field')) {
        function wp_nonce_field(string $action, string $name): void
        {
            echo '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '" />';
        }
    }

    if (! function_exists('update_user_meta')) {
        function update_user_meta(int $userId, string $key, string $value): bool
        {
            $GLOBALS['adct_test_wp_meta'][$userId][$key] = $value;

            return true;
        }
    }

    if (! function_exists('wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return $value;
        }
    }

    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field(mixed $value): string
        {
            return is_string($value) ? trim(strip_tags($value)) : '';
        }
    }

    if (! function_exists('selected')) {
        function selected(mixed $selected, mixed $current = true, bool $display = true): string
        {
            return $display && (string) $selected === (string) $current ? " selected='selected'" : '';
        }
    }

    if (! function_exists('checked')) {
        function checked(mixed $checked, mixed $current = true, bool $display = true): string
        {
            return $display && (string) $checked === (string) $current ? " checked='checked'" : '';
        }
    }

    if (! function_exists('esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return in_array($capability, $GLOBALS['adct_test_wp_caps'][$GLOBALS['adct_pref_current']] ?? [], true);
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval {

    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
    use ADCT\ParishIntake\WordPress\Approval\ReviewerNotificationPreference;
    use PHPUnit\Framework\TestCase;

    final class ReviewerNotificationPreferenceTest extends TestCase
    {
        private const NONCE_ACTION_PREFIX = 'adct_pi_notify_mode_';
        private const USER_ID = 4211;

        /** @var array<string, mixed> */
        private array $globalsBackup = [];

        protected function setUp(): void
        {
            $this->globalsBackup = [
                'adct_pref_meta' => $GLOBALS['adct_test_wp_meta'] ?? [],
                'adct_test_wp_caps' => $GLOBALS['adct_test_wp_caps'] ?? [],
                'adct_pref_current' => $GLOBALS['adct_pref_current'] ?? 0,
                'POST' => $_POST,
            ];
            $GLOBALS['adct_test_wp_meta'] = [];
            $GLOBALS['adct_test_wp_caps'] = [self::USER_ID => ['edit_user', Capabilities::REVIEW]];
            $GLOBALS['adct_pref_current'] = self::USER_ID;
            $_POST = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['adct_test_wp_meta'] = $this->globalsBackup['adct_pref_meta'];
            $GLOBALS['adct_test_wp_caps'] = $this->globalsBackup['adct_test_wp_caps'];
            $GLOBALS['adct_pref_current'] = $this->globalsBackup['adct_pref_current'];
            $_POST = $this->globalsBackup['POST'];
        }

        /**
         * A rename of the nonce action silently breaks every saved preference,
         * because save() dies before writing anything. The integration harness
         * mints this nonce with the same literal, so pin it here where a rename
         * fails the fast local suite instead of only the wp-env job.
         */
        public function testTheNonceActionIsTheStringTheProfileFormMints(): void
        {
            $_POST['adct_pi_notify_nonce'] = wp_create_nonce(self::NONCE_ACTION_PREFIX . self::USER_ID);
            $_POST['adct_pi_notify_mode'] = 'digest';
            $_POST['adct_pi_approval_reminders'] = '1';

            (new ReviewerNotificationPreference())->save(self::USER_ID);

            self::assertSame(
                'digest',
                $GLOBALS['adct_test_wp_meta'][self::USER_ID]['adct_pi_approval_notify_mode'] ?? null,
                'A nonce minted from the documented action must verify.'
            );
        }

        /**
         * A tick and an untick are two different form submissions. The checkbox
         * is absent from $_POST when it is unticked, which is the only signal
         * that distinguishes them.
         */
        public function testATickedCheckboxKeepsRemindersOn(): void
        {
            $this->saveWithReminderField('1');

            self::assertSame(
                '1',
                $GLOBALS['adct_test_wp_meta'][self::USER_ID][ApprovalRecipients::REVIEWER_REMINDERS_META_KEY] ?? null,
                'A submitted checkbox is reminders on.'
            );
        }

        public function testAnAbsentCheckboxTurnsRemindersOff(): void
        {
            $this->saveWithReminderField('1');
            $this->saveWithReminderField(null);

            self::assertSame(
                '0',
                $GLOBALS['adct_test_wp_meta'][self::USER_ID][ApprovalRecipients::REVIEWER_REMINDERS_META_KEY] ?? null,
                'An unticked checkbox is absent from the submission and must record off.'
            );
        }

        /**
         * The rendered box reflects the stored value, so a reviewer who switched
         * reminders off is not shown a ticked box that would silently switch
         * them back on.
         */
        public function testTheRenderedCheckboxFollowsTheStoredValue(): void
        {
            $this->saveWithReminderField(null);

            $html = $this->render();

            self::assertStringNotContainsString('checked', $html);
        }

        public function testTheRenderedCheckboxIsTickedByDefaultForANewReviewer(): void
        {
            self::assertStringContainsString('checked', $this->render());
        }

        private function saveWithReminderField(?string $value): void
        {
            $_POST['adct_pi_notify_nonce'] = wp_create_nonce(self::NONCE_ACTION_PREFIX . self::USER_ID);
            $_POST['adct_pi_notify_mode'] = 'digest';
            if ($value === null) {
                unset($_POST['adct_pi_approval_reminders']);
            } else {
                $_POST['adct_pi_approval_reminders'] = $value;
            }

            (new ReviewerNotificationPreference())->save(self::USER_ID);
        }

        private function render(): string
        {
            $user = new \WP_User(self::USER_ID, 'reviewer@example.test');

            ob_start();
            (new ReviewerNotificationPreference())->render($user);

            return (string) ob_get_clean();
        }
    }
}
