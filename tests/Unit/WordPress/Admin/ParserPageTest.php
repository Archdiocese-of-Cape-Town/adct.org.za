<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../../Support/AdminWordPressStubs.php';
    require_once __DIR__ . '/../../../Support/WordPressOptionsStubs.php';
}

namespace ADCT\ParishIntake\WordPress\Admin {
    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\is_admin')) {
        /**
         * Driven through a global so the shared stub in
         * tests/Support/WordPressStubs.php can decide, whichever file PHPUnit
         * includes first. This namespace has two consumers now — ParserPage
                 * and ReviewQueuePage — so one of them had to stop declaring its own
         * unconditional copy. ParserPage only renders inside wp-admin, so the
         * default stays true; ReviewQueuePageTest sets the global itself.
         */
        function is_admin(): bool
        {
            return (bool) ($GLOBALS['adct_test_is_admin'] ?? true);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\check_admin_referer')) {
        function check_admin_referer(string $action, string $name): void
        {
            $GLOBALS['adct_test_nonce_checks'][] = ['action' => $action, 'name' => $name];
            if (($GLOBALS['adct_test_nonce_should_fail'] ?? false) === true) {
                throw new \AdctTestNonceRefused($action);
            }
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\update_option')) {
        function update_option(string $option, $value, $autoload = null): bool
        {
            $GLOBALS['parser_page_options'][$option] = $value;
            $GLOBALS['parser_page_updates'][] = ['option' => $option, 'value' => $value];

            return true;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\get_option')) {
        function get_option(string $option, $default = false)
        {
            return $GLOBALS['parser_page_options'][$option] ?? $default;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\delete_option')) {
        function delete_option(string $option): bool
        {
            if (($GLOBALS['parser_page_delete_fails'] ?? false) === true) {
                return false;
            }
            $present = array_key_exists($option, $GLOBALS['parser_page_options']);
            unset($GLOBALS['parser_page_options'][$option]);

            return $present;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\absint')) {
        function absint(mixed $value): int
        {
            return abs((int) $value);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_html')) {
        function esc_html(mixed $text): string
        {
            return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_attr')) {
        function esc_attr(mixed $text): string
        {
            return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_url')) {
        function esc_url(mixed $url): string
        {
            return htmlspecialchars(is_string($url) ? $url : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_url_raw')) {
        function esc_url_raw(mixed $url): string
        {
            return is_string($url) ? trim($url) : '';
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\selected')) {
        function selected(mixed $current, mixed $value, bool $echo = true): string
        {
            $markup = (string) $current === (string) $value ? " selected='selected'" : '';

            if ($echo) {
                echo $markup;
            }

            return $markup;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\add_query_arg')) {
        function add_query_arg(array $arguments, string $url): string
        {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($arguments);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\sanitize_text_field')) {
        function sanitize_text_field(mixed $value): string
        {
            return is_string($value) ? trim(strip_tags($value)) : '';
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return $value;
        }
    }

        if (! function_exists('ADCT\ParishIntake\WordPress\Admin\checked')) {
            function checked(mixed $checked, mixed $current = true, bool $echo = true): string
            {
                return __checked_selected_helper($checked, $current, $echo, 'checked');
            }
        }

        if (! function_exists('ADCT\ParishIntake\WordPress\Admin\selected')) {
            function selected(mixed $selected, mixed $current = true, bool $echo = true): string
            {
                return __checked_selected_helper($selected, $current, $echo, 'selected');
            }
        }

        /**
         * Mirrors WordPress: the attribute is omitted entirely when it does not
         * apply, so a test asserting on the raw markup sees "checked" as a word,
         * never as `checked="0"`.
         */
        function __checked_selected_helper(mixed $helper, mixed $current, bool $echo, string $type): string
        {
            $actual = __checked_selected_helper_value($helper);
            $expected = __checked_selected_helper_value($current);

            if ((string) $actual === (string) $expected) {
                $result = " {$type}='{$type}'";
            } else {
                $result = '';
            }

            if ($echo) {
                echo $result;
            }

            return $result;
        }

        function __checked_selected_helper_value(mixed $value): string
        {
            if (is_bool($value)) {
                return $value ? '1' : '';
            }

            return is_scalar($value) ? (string) $value : '';
        }

        if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_textarea')) {
            function esc_textarea(mixed $text): string
            {
                return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
            }
        }

        if (! function_exists('ADCT\ParishIntake\WordPress\Admin\wp_nonce_field')) {
            function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
            {
                $field = '<input type="hidden" name="' . $name . '" value="nonce" />';

                if ($echo) {
                    echo $field;
                }

                return $field;
            }
        }

        if (! function_exists('ADCT\ParishIntake\WordPress\Admin\submit_button')) {
            function submit_button(string $text = 'Save Changes', string $type = 'primary', string $name = 'submit', bool $echo = true): string
            {
                $button = '<button type="submit" class="button button-primary">' . esc_html($text) . '</button>';

                if ($echo) {
                    echo $button;
                }

                return $button;
            }
        }
    }

// The Jobs-namespace option functions OcrSettings and RetentionSettings call
// are NOT declared here. They live in tests/Support/WordPressOptionsStubs.php,
// which every test that reaches a Jobs-namespace option store requires, and
// setUp() below seeds their shared store with this test's baseline so both
// namespaced settings objects read the values a settings screen would see.

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {

    use ADCT\ParishIntake\Core\Security\SecretLookupInterface;
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';

    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Audit\AuditAction;
    use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
    use ADCT\ParishIntake\Core\Audit\AuditWriter;
    use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
    use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
    use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
    use ADCT\ParishIntake\Core\Parsing\SectionSkipper;
        use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
        use ADCT\ParishIntake\Core\Security\SecretRegistry;
    use ADCT\ParishIntake\WordPress\Admin\ParserPage;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
    use ADCT\ParishIntake\WordPress\Database\Schema;
    use ADCT\ParishIntake\WordPress\Export\StaticReportGenerator;
    use ADCT\ParishIntake\WordPress\Jobs\OcrSettings;
    use ADCT\ParishIntake\WordPress\Jobs\RetentionSettings;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use RuntimeException;

    final class ParserPageTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['parser_page_options'] = [
                'adct_parish_intake_ai_enabled' => '0',
                'adct_parish_intake_ai_provider' => 'none',
                'adct_parish_intake_openrouter_model' => 'gpt-4o-mini',
                'adct_parish_intake_ai_base_url' => 'https://api.example.test/v1',
                'adct_parish_intake_ai_threshold' => '0.55',
                'adct_parish_intake_section_keywords' => SectionSkipper::defaultKeywordLists(),
                RetentionSettings::RAW_ENABLED_OPTION => '0',
                RetentionSettings::RAW_DAYS_OPTION => (string) RetentionSettings::DEFAULT_RAW_DAYS,
                RetentionSettings::PROCESSED_ENABLED_OPTION => '0',
                RetentionSettings::PROCESSED_DAYS_OPTION => (string) RetentionSettings::DEFAULT_PROCESSED_DAYS,
                RetentionSettings::ACTION_TOKENS_ENABLED_OPTION => '0',
                RetentionSettings::AUDIT_ENABLED_OPTION => '0',
                OcrSettings::ENABLED_OPTION => '0',
                OcrSettings::DAILY_CALL_LIMIT_OPTION => (string) OcrSettings::DEFAULT_DAILY_CALL_LIMIT,
            ];
                        // OcrSettings and RetentionSettings sit in the Jobs namespace, so
                        // their unqualified reads reach the shared option store rather than
                        // this one. Seeding it with the same baseline keeps the settings
                        // screen and the value objects looking at one set of options; it is
                        // reset per test, so the Jobs tests that share the stub are
                        // unaffected.
                        \new_options_database($GLOBALS['parser_page_options']);
                        $GLOBALS['parser_page_updates'] = [];
            $GLOBALS['parser_page_logs'] = [];
            $GLOBALS['adct_test_nonce_checks'] = [];
            unset($GLOBALS['adct_test_nonce_should_fail']);
            unset($GLOBALS['parser_page_delete_fails']);
            // These tests are about what happens once a user reaches the page,
            // so the shared capability stub grants everything they need. The
            // refusal path is covered where the gate itself is.
            $GLOBALS['adct_test_wp_caps'] = [
                Capabilities::REVIEW,
                Capabilities::VIEW_REPORTS,
                Capabilities::MANAGE_SETTINGS,
            ];
            // ParserPage only ever renders inside wp-admin, and the shared stub
                        // defaults this to false so that a screen test which has not said
            // otherwise is not silently treated as an admin request. Every test in
            // this file is about what happens once a user reaches the page, so the
            // request is stated here rather than left to the default. Setting it per
            // test also makes the outcome independent of which copy of is_admin()
            // PHPUnit happened to include first.
            $GLOBALS['adct_test_is_admin'] = true;
            // The settings screen audits who changed a setting, and the audit
            // row records the acting user's email address, matching the actor
            // column the review queue repository has always written. The stub
            // is the shared one in tests/Support/WordPressStubs.php, driven
            // through its global rather than declared locally: a second
            // declaration of the same global function is a fatal error.
            $GLOBALS['adct_test_current_user'] = new \WP_User(4, 'chaplain@example.test');
            $_POST = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['adct_test_wp_caps'], $GLOBALS['adct_test_is_admin'], $GLOBALS['adct_test_current_user']);
            unset($GLOBALS['adct_test_nonce_should_fail'], $GLOBALS['adct_test_nonce_checks']);
            unset($GLOBALS['parser_page_delete_fails']);
        }

        public function testASettingsSaveWritesOneAuditRowNamingWhatChanged(): void
        {
            $_POST = $this->settingsPost();
            $_POST['ai_provider'] = 'openrouter';
            $_POST['openrouter_model'] = 'gpt-5.5';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertCount(1, $audit->rows);
            self::assertSame('chaplain@example.test', $audit->rows[0]['actor']);
            self::assertSame(AuditAction::SETTINGS_UPDATED, $audit->rows[0]['action']);
            self::assertSame(AuditSubjectType::SETTINGS, $audit->rows[0]['subjectType']);
            self::assertSame(0, $audit->rows[0]['subjectId']);

            $options = array_column($audit->rows[0]['details']['changed'], 'option', 'option');
            self::assertArrayHasKey('adct_parish_intake_ai_provider', $options);
            self::assertArrayHasKey('adct_parish_intake_openrouter_model', $options);
        }

        public function testASettingsSaveThatChangesNothingWritesNoAuditRow(): void
        {
            $_POST = $this->settingsPost();

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertNotSame([], $GLOBALS['parser_page_updates']);
            self::assertSame([], $audit->rows);
        }

        public function testTheAuditRowNeverCarriesAnApiKey(): void
        {
            $_POST = $this->settingsPost();
            $_POST['openrouter_api_key'] = 'sk-live-do-not-log-me';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertCount(1, $audit->rows);
            self::assertStringNotContainsString(
                'sk-live-do-not-log-me',
                json_encode($audit->rows[0]['details'], JSON_THROW_ON_ERROR)
            );

            $secret = SecretRegistry::optionName(SecretRegistry::AI_API_KEY);
            $written = array_column($GLOBALS['parser_page_updates'], 'value', 'option');
            self::assertArrayHasKey($secret, $written);
            self::assertSame('sk-live-do-not-log-me', $written[$secret]);
        }

        public function testRemovingAnApiKeyIsRecordedAsRemovalRatherThanSilently(): void
        {
            $_POST = $this->settingsPost();
            $_POST['remove_openrouter_api_key'] = '1';
            $GLOBALS['parser_page_options'][SecretRegistry::optionName(SecretRegistry::AI_API_KEY)] = 'sk-stored';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            $options = array_column($audit->rows[0]['details']['changed'] ?? [], 'option', 'option');
            self::assertArrayHasKey(SecretRegistry::optionName(SecretRegistry::AI_API_KEY), $options);
        }

        public function testASettingsAuditFailureIsLoggedAndDoesNotUndoTheSave(): void
        {
            $_POST = $this->settingsPost();
            $_POST['ai_provider'] = 'openrouter';

            $audit = new RecordingAuditWriter();
            $audit->failure = new RuntimeException('the audit table is missing');

            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertSame('openrouter', $GLOBALS['parser_page_options']['adct_parish_intake_ai_provider']);
            self::assertSame(
                ['[ADCT Parish Intake] The settings audit row could not be written (the audit table is missing).'],
                $GLOBALS['parser_page_logs']
            );
        }

        public function testResettingTheSectionKeywordsIsAuditedAsAChange(): void
        {
            // Customised keywords first: a reset that restored what was already
            // stored would be a no-op, and a no-op must not reach the log.
            $GLOBALS['parser_page_options']['adct_parish_intake_section_keywords'] = [
                'mass_times' => ['Parish sung Mass'],
                'readings' => ['Word of God'],
            ];
            $_POST = $this->settingsPost();
            $_POST['section_keywords'] = ['mass_times' => ['Parish sung Mass'], 'readings' => ['Word of God']];
            $_POST['reset_section_keywords'] = '1';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertCount(1, $audit->rows);
            self::assertSame(AuditAction::SETTINGS_UPDATED, $audit->rows[0]['action']);
            self::assertSame(
                SectionSkipper::defaultKeywordLists(),
                $GLOBALS['parser_page_options']['adct_parish_intake_section_keywords']
            );
        }

        public function testASettingsRequestCannotEnableOcrOrChangeItsSavedConfiguration(): void
        {
            $ocrKeyOption = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $GLOBALS['parser_page_options'][$ocrKeyOption] = 'K811-retained';
            $GLOBALS['wpdb']->rows = $GLOBALS['parser_page_options'];

            $_POST = $this->settingsPost();
            $_POST['ocr_enabled'] = '1';
            $_POST['ocr_daily_call_limit'] = '3';
            $_POST['ocr_api_key'] = 'K811-forged';
            $_POST['remove_ocr_api_key'] = '1';
            $_POST['ai_provider'] = 'openrouter';

            $audit = new RecordingAuditWriter();
            $page = $this->page(audit: $audit);
            $page->maybeHandleSettings();

            self::assertSame([], $GLOBALS['parser_page_updates']);
            self::assertSame([], $audit->rows);
            self::assertSame('none', $GLOBALS['parser_page_options']['adct_parish_intake_ai_provider']);
            self::assertSame('0', $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION]);
            self::assertSame(
                (string) OcrSettings::DEFAULT_DAILY_CALL_LIMIT,
                $GLOBALS['parser_page_options'][OcrSettings::DAILY_CALL_LIMIT_OPTION]
            );
            self::assertSame('K811-retained', $GLOBALS['parser_page_options'][$ocrKeyOption]);
            self::assertStringContainsString(
                'Automatic server-side poster OCR is unavailable',
                (string) $this->privateProperty($page, 'settingsSaveError')
            );

            ob_start();
            try {
                $page->renderSettingsPage();
            } finally {
                $html = (string) ob_get_clean();
            }

            self::assertStringContainsString(
                'Automatic server-side poster OCR is unavailable',
                $html,
                'the rejected activation must be explained on the settings page'
            );
        }

        public function testSavingOtherSettingsPreservesPreviouslySavedOcrOptionsAndKey(): void
        {
            $ocrKeyOption = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION] = '1';
            $GLOBALS['parser_page_options'][OcrSettings::DAILY_CALL_LIMIT_OPTION] = '7';
            $GLOBALS['parser_page_options'][$ocrKeyOption] = 'K811-retained';
            $GLOBALS['wpdb']->rows = $GLOBALS['parser_page_options'];
            $_POST = $this->settingsPost();
            $_POST['ai_provider'] = 'openrouter';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertSame('1', $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION]);
            self::assertSame('7', $GLOBALS['parser_page_options'][OcrSettings::DAILY_CALL_LIMIT_OPTION]);
            self::assertSame('K811-retained', $GLOBALS['parser_page_options'][$ocrKeyOption]);
            self::assertSame('openrouter', $GLOBALS['parser_page_options']['adct_parish_intake_ai_provider']);
            self::assertCount(1, $audit->rows);
            self::assertStringNotContainsString(
                'K811-retained',
                json_encode($audit->rows[0]['details'], JSON_THROW_ON_ERROR)
            );
            self::assertStringNotContainsString(
                $ocrKeyOption,
                json_encode($audit->rows[0]['details'], JSON_THROW_ON_ERROR)
            );
        }

        public function testCraftedOcrKeyRemovalAndCapFieldsAreIgnoredWithoutAnEnableFlag(): void
        {
            $ocrKeyOption = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION] = '1';
            $GLOBALS['parser_page_options'][OcrSettings::DAILY_CALL_LIMIT_OPTION] = '7';
            $GLOBALS['parser_page_options'][$ocrKeyOption] = 'K811-retained';
            $GLOBALS['wpdb']->rows = $GLOBALS['parser_page_options'];

            $_POST = $this->settingsPost();
            unset($_POST['ocr_enabled']);
            $_POST['ocr_daily_call_limit'] = '999';
            $_POST['ocr_api_key'] = 'K811-forged';
            $_POST['remove_ocr_api_key'] = '1';
            $_POST['ai_provider'] = 'openrouter';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertSame('1', $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION]);
            self::assertSame('7', $GLOBALS['parser_page_options'][OcrSettings::DAILY_CALL_LIMIT_OPTION]);
            self::assertSame('K811-retained', $GLOBALS['parser_page_options'][$ocrKeyOption]);
            self::assertSame('openrouter', $GLOBALS['parser_page_options']['adct_parish_intake_ai_provider']);
            self::assertCount(1, $audit->rows);
            $auditDetails = json_encode($audit->rows[0]['details'], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('K811-retained', $auditDetails);
            self::assertStringNotContainsString('K811-forged', $auditDetails);
            self::assertStringNotContainsString($ocrKeyOption, $auditDetails);
        }

        public function testExplicitOcrKeyRemovalDeletesOnlyTheSavedKeyAndAuditsWithoutSecrets(): void
        {
            $option = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $GLOBALS['parser_page_options'][$option] = 'K811-obsolete-never-log';
            $before = $GLOBALS['parser_page_options'];
            $_POST = [
                'adct_parish_intake_remove_saved_ocr_key' => '1',
                'adct_parish_intake_remove_ocr_key_nonce' => 'nonce',
                'ocr_api_key' => 'K811-forged-never-log',
            ];
            $audit = new RecordingAuditWriter();
            $page = $this->page(audit: $audit);
            $page->maybeHandleSettings();

            unset($before[$option]);
            self::assertSame($before, $GLOBALS['parser_page_options']);
            self::assertSame([], $GLOBALS['parser_page_updates']);
            self::assertSame([
                ['action' => 'adct_parish_intake_remove_saved_ocr_key', 'name' => 'adct_parish_intake_remove_ocr_key_nonce'],
            ], $GLOBALS['adct_test_nonce_checks']);
            self::assertCount(1, $audit->rows);
            self::assertSame(AuditAction::SETTINGS_UPDATED, $audit->rows[0]['action']);
            self::assertSame([
                'changed' => [['option' => $option, 'before' => 'set', 'after' => 'removed']],
            ], $audit->rows[0]['details']);
            self::assertStringNotContainsString('K811-', json_encode($audit->rows, JSON_THROW_ON_ERROR));
            self::assertSame([], $GLOBALS['parser_page_logs']);
        }

        public function testOcrKeyRemovalRequiresSettingsCapabilityAndItsOwnNonce(): void
        {
            $option = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $GLOBALS['parser_page_options'][$option] = 'K811-obsolete-never-log';
            $_POST = [
                'adct_parish_intake_remove_saved_ocr_key' => '1',
                'adct_parish_intake_remove_ocr_key_nonce' => 'bad-nonce',
            ];
            $audit = new RecordingAuditWriter();
            $page = $this->page(audit: $audit);
            $GLOBALS['adct_test_wp_caps'] = [Capabilities::REVIEW];
            $page->maybeHandleSettings();
            self::assertSame('K811-obsolete-never-log', $GLOBALS['parser_page_options'][$option]);
            self::assertSame([], $audit->rows);
            self::assertSame([], $GLOBALS['adct_test_nonce_checks']);

            $GLOBALS['adct_test_wp_caps'] = [Capabilities::MANAGE_SETTINGS];
            $GLOBALS['adct_test_nonce_should_fail'] = true;
            try {
                $page->maybeHandleSettings();
                self::fail('An invalid removal nonce must be refused.');
            } catch (\AdctTestNonceRefused) {
                self::assertSame('K811-obsolete-never-log', $GLOBALS['parser_page_options'][$option]);
                self::assertSame([], $audit->rows);
            }
        }

        public function testOcrActivationAttemptCannotAlsoRemoveTheSavedKey(): void
        {
            $option = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $GLOBALS['parser_page_options'][$option] = 'K811-retained';
            $_POST = [
                'adct_parish_intake_remove_saved_ocr_key' => '1',
                'adct_parish_intake_remove_ocr_key_nonce' => 'nonce',
                'ocr_enabled' => '1',
            ];
            $audit = new RecordingAuditWriter();
            $page = $this->page(audit: $audit);
            $page->maybeHandleSettings();

            self::assertSame('K811-retained', $GLOBALS['parser_page_options'][$option]);
            self::assertSame([], $audit->rows);
            self::assertStringContainsString('unavailable', (string) $this->privateProperty($page, 'settingsSaveError'));
        }

        public function testSavedOcrKeyHasASeparateRemovalFormEvenWithAConfiguredConstant(): void
        {
            $option = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $GLOBALS['parser_page_options'][$option] = 'K811-never-render';
            $GLOBALS['wpdb']->rows[$option] = 'K811-never-render';
            self::assertSame('K811-never-render', \ADCT\ParishIntake\WordPress\Admin\get_option($option));
            self::assertSame('K811-never-render', $GLOBALS['wpdb']->rows[$option]);
            foreach ([[], [SecretRegistry::OCR_API_KEY]] as $constants) {
                $html = $this->renderSettings([SecretRegistry::OCR_API_KEY => 'K811-never-render'], $constants);
                self::assertStringContainsString('name="adct_parish_intake_remove_saved_ocr_key"', $html);
                self::assertStringContainsString('name="adct_parish_intake_remove_ocr_key_nonce"', $html);
                self::assertStringNotContainsString('K811-never-render', $html);
                self::assertStringNotContainsString('name="ocr_api_key"', $html);
            }
            self::assertStringNotContainsString(
                'name="adct_parish_intake_remove_saved_ocr_key"',
                $this->renderSettings([], [SecretRegistry::OCR_API_KEY])
            );
        }

        public function testFailedOcrKeyDeletionPreservesTheKeyAndReportsAnErrorWithoutAuditingSuccess(): void
        {
            $option = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $GLOBALS['parser_page_options'][$option] = 'K811-never-log';
            $GLOBALS['parser_page_delete_fails'] = true;
            $_POST = [
                'adct_parish_intake_remove_saved_ocr_key' => '1',
                'adct_parish_intake_remove_ocr_key_nonce' => 'nonce',
            ];
            $audit = new RecordingAuditWriter();
            $page = $this->page(audit: $audit);
            $page->maybeHandleSettings();

            self::assertSame('K811-never-log', $GLOBALS['parser_page_options'][$option]);
            self::assertSame([], $audit->rows);
            self::assertSame('The saved OCR API key could not be removed. Please try again.', $this->privateProperty($page, 'settingsSaveError'));
            self::assertSame(['[ADCT Parish Intake] The saved OCR API key could not be removed.'], $GLOBALS['parser_page_logs']);
        }

        public function testRemovingAnAbsentOcrKeyDoesNotCreateAnAuditEntry(): void
        {
            $_POST = [
                'adct_parish_intake_remove_saved_ocr_key' => '1',
                'adct_parish_intake_remove_ocr_key_nonce' => 'nonce',
            ];
            $audit = new RecordingAuditWriter();
            $page = $this->page(audit: $audit);
            $page->maybeHandleSettings();

            self::assertSame([], $audit->rows);
            self::assertSame('No saved OCR API key was found.', $this->privateProperty($page, 'settingsSaveError'));
        }

        public function testAnUncheckedRemovalActionCannotDeleteTheSavedOcrKey(): void
        {
            $option = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $GLOBALS['parser_page_options'][$option] = 'K811-retained';
            $_POST = [
                'adct_parish_intake_remove_saved_ocr_key' => '0',
                'adct_parish_intake_remove_ocr_key_nonce' => 'nonce',
            ];
            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertSame('K811-retained', $GLOBALS['parser_page_options'][$option]);
            self::assertSame([], $audit->rows);
            self::assertSame([], $GLOBALS['adct_test_nonce_checks']);
        }

        public function testAnUnreadablePosterLookupFailureIsLoggedWithoutExposingDatabaseDetails(): void
        {
            $database = $this->createMock(DatabaseConnectionInterface::class);
            $database->method('prefix')->willReturn('wp_');
            $database->method('prepare')->willReturnCallback(
                static fn (string $query, mixed ...$arguments): string => $query
            );
            $database->method('getResults')->willThrowException(
                new RuntimeException('sensitive SQL error')
            );
            $database->method('lastError')->willReturn('sensitive SQL error');

            $page = $this->page(new AttachmentRepository($database));
            $method = new \ReflectionMethod(ParserPage::class, 'unreadablePosters');

            self::assertSame([], $method->invoke($page));
            self::assertSame(
                ['[ADCT Parish Intake] Could not load unreadable poster attachment warnings (RuntimeException).'],
                $GLOBALS['parser_page_logs']
            );
            self::assertStringNotContainsString('sensitive SQL error', $GLOBALS['parser_page_logs'][0]);
        }

        public function testEveryPosterStatusIsExplainedInTheOperatorsWords(): void
        {
            $page = $this->page();
            $method = new \ReflectionMethod(ParserPage::class, 'posterStatusLabel');

            // An unrecognised status must still render as something, never as a
            // raw enum value or an empty label in the notice.
            foreach ([
                OcrExtractionResult::STATUS_NO_TEXT,
                OcrExtractionResult::STATUS_SKIPPED_SIZE,
                OcrExtractionResult::STATUS_SKIPPED_TYPE,
                OcrExtractionResult::STATUS_SKIPPED_TIMEOUT,
                OcrExtractionResult::STATUS_SKIPPED_RATE_LIMIT,
                OcrExtractionResult::STATUS_NOT_CONFIGURED,
                OcrExtractionResult::STATUS_FAILED,
                'something_new',
                '',
            ] as $status) {
                $label = $method->invoke($page, $status);

                self::assertIsString($label);
                self::assertNotSame('', $label);
                self::assertStringNotContainsString('_', $label);
            }
        }

        public function testTheSettingsPageExplainsThatAutomaticPosterOcrIsUnavailable(): void
        {
            $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION] = '1';

            $ocrKeyOption = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            $savedOcrKey = 'K811-never-render';
            $GLOBALS['parser_page_options'][$ocrKeyOption] = $savedOcrKey;
            $GLOBALS['wpdb']->rows[$ocrKeyOption] = $savedOcrKey;
            self::assertSame($savedOcrKey, $GLOBALS['parser_page_options'][$ocrKeyOption]);
            self::assertSame($savedOcrKey, $GLOBALS['wpdb']->rows[$ocrKeyOption]);

            $html = $this->renderSettings([SecretRegistry::OCR_API_KEY => $savedOcrKey]);

            self::assertStringContainsString('Automatic server-side poster OCR is unavailable', $html);
            self::assertStringContainsString('Any previously saved OCR setting or API key is retained but not used', $html);
            self::assertStringContainsString('on-demand browser OCR', $html);
            self::assertStringContainsString('enter the details manually', $html);
            self::assertStringNotContainsString('name="ocr_enabled"', $html);
            self::assertStringNotContainsString('name="ocr_daily_call_limit"', $html);
            self::assertStringNotContainsString('name="ocr_api_key"', $html);
            self::assertStringNotContainsString('name="remove_ocr_api_key"', $html);
            self::assertStringNotContainsString($savedOcrKey, $html);
            self::assertStringNotContainsString('api.ocr.space', $html);
            self::assertStringNotContainsString('Turn it on', $html);
        }

        /**
         * Renders the Settings screen and returns its HTML. Asserting on the
         * markup, rather than on the array behind it, is the only way to prove a
         * secret is not rendered: a value can be absent from the settings array
         * and still be printed by the template.
         *
         * The secret state is injected as a lookup rather than staged into an
         * options table. `WordPressSecretResolver` reads the *global* WordPress
         * `get_option`, and this suite deliberately declares several different
         * stand-ins for that function, so whichever one PHPUnit happened to
         * include first used to decide what the screen rendered. Injecting the
         * port makes the saved-key branch reachable no matter the load order.
         *
         * @param array<string, string> $storedKeys Secret ids that have a non-empty
         *                                           value in the options table.
         * @param list<string>           $constants  Secret ids that wp-config.php defines.
         */
        private function renderSettings(array $storedKeys = [], array $constants = []): string
        {
            $page = $this->page(secretLookup: new class ($storedKeys, $constants) implements SecretLookupInterface {
                /** @param array<string, string> $stored */
                public function __construct(
                    private array $stored,
                    private array $constants
                ) {
                }

                public function isConstantConfigured(string $secretId, ?string $scope = null): bool
                {
                    return in_array($secretId, $this->constants, true);
                }

                public function hasStoredOption(string $secretId, ?string $scope = null): bool
                {
                    return ($this->stored[$secretId] ?? '') !== '';
                }
            });

            ob_start();
            try {
                $page->renderSettingsPage();
            } finally {
                return (string) ob_get_clean();
            }
        }

        private function settingsPost(): array
        {
            return [
                'adct_parish_intake_settings_nonce' => 'nonce',
                'adct_parish_intake_save_settings' => '1',
                'ai_provider' => 'none',
                'openrouter_model' => 'gpt-4o-mini',
                'ai_base_url' => 'https://api.example.test/v1',
                'ai_threshold' => '0.55',
                'section_keywords' => SectionSkipper::defaultKeywordLists(),
                'retention_raw_days' => (string) RetentionSettings::DEFAULT_RAW_DAYS,
                'retention_processed_days' => (string) RetentionSettings::DEFAULT_PROCESSED_DAYS,
                            ];

            // The four retention checkboxes are absent on purpose. An unchecked
            // box is not submitted at all and the handler reads them with
            // isset(), so posting them as '0' would look checked and switch all
            // four on. The stored value is '0', which is what an absent box
            // means, so this really is the nothing-changed baseline.
        }

        public function testInvalidRetentionLeavesEverySettingUnchanged(): void
        {
            $_POST = [
                'adct_parish_intake_settings_nonce' => 'nonce',
                'adct_parish_intake_save_settings' => '1',
                'ai_enabled' => '1',
                'ai_provider' => 'openrouter',
                'openrouter_model' => 'gpt-5.5',
                'ai_base_url' => 'https://api.other.test/v1',
                'openrouter_api_key' => 'secret-key',
                'ai_threshold' => '0.8',
                'section_keywords' => [
                    'liturgy' => "mass\nchoir",
                ],
                'retention_raw_enabled' => '1',
                'retention_raw_days' => '0',
                'retention_processed_enabled' => '1',
                'retention_processed_days' => '30',
                'retention_action_tokens_enabled' => '1',
                'retention_audit_enabled' => '1',
            ];

            $page = $this->page();
            $page->maybeHandleSettings();

            self::assertSame([], $GLOBALS['parser_page_updates']);
            self::assertSame('0', $GLOBALS['parser_page_options']['adct_parish_intake_ai_enabled']);
            self::assertSame('none', $GLOBALS['parser_page_options']['adct_parish_intake_ai_provider']);
            self::assertSame('gpt-4o-mini', $GLOBALS['parser_page_options']['adct_parish_intake_openrouter_model']);
            self::assertSame('https://api.example.test/v1', $GLOBALS['parser_page_options']['adct_parish_intake_ai_base_url']);
            self::assertSame('0.55', $GLOBALS['parser_page_options']['adct_parish_intake_ai_threshold']);
            self::assertSame(
                SectionSkipper::defaultKeywordLists(),
                $GLOBALS['parser_page_options']['adct_parish_intake_section_keywords']
            );
            self::assertSame('0', $GLOBALS['parser_page_options'][RetentionSettings::RAW_ENABLED_OPTION]);
            self::assertSame((string) RetentionSettings::DEFAULT_RAW_DAYS, $GLOBALS['parser_page_options'][RetentionSettings::RAW_DAYS_OPTION]);
            self::assertSame('0', $GLOBALS['parser_page_options'][RetentionSettings::PROCESSED_ENABLED_OPTION]);
            self::assertSame((string) RetentionSettings::DEFAULT_PROCESSED_DAYS, $GLOBALS['parser_page_options'][RetentionSettings::PROCESSED_DAYS_OPTION]);
            self::assertSame('0', $GLOBALS['parser_page_options'][RetentionSettings::ACTION_TOKENS_ENABLED_OPTION]);
            self::assertSame('0', $GLOBALS['parser_page_options'][RetentionSettings::AUDIT_ENABLED_OPTION]);
            self::assertSame(
                'Raw-data retention is enabled, but the retention period must be at least 1 day.',
                $this->privateProperty($page, 'settingsSaveError')
            );
            self::assertFalse($this->privateProperty($page, 'settingsSaveSucceeded'));
        }

        public function testUnreadablePdfLookupFailureIsLoggedWithoutExposingDatabaseDetails(): void
        {
            $database = $this->createMock(DatabaseConnectionInterface::class);
            $database->method('prefix')->willReturn('wp_');
            $database->method('prepare')->willReturnCallback(
                static fn (string $query, mixed ...$arguments): string => $query
            );
            $database->method('getResults')->willReturn([]);
            $database->method('lastError')->willReturn('sensitive SQL error');

            $page = $this->page(new AttachmentRepository($database));
            $method = new \ReflectionMethod(ParserPage::class, 'unreadablePdfs');

            self::assertSame([], $method->invoke($page));
            self::assertSame(
                ['[ADCT Parish Intake] Could not load unreadable PDF attachment warnings (RuntimeException).'],
                $GLOBALS['parser_page_logs']
            );
            self::assertStringNotContainsString('sensitive SQL error', $GLOBALS['parser_page_logs'][0]);
        }

        private function page(
            ?AttachmentRepository $attachments = null,
            ?AuditWriter $audit = null,
            ?SecretLookupInterface $secretLookup = null
        ): ParserPage {
            return new ParserPage(
                new Schema(),
                new PipelineFactory(),
                new StaticReportGenerator(new Schema()),
                new class implements HttpClientInterface {
                    public function isAvailable(): bool
                    {
                        return false;
                    }

                    public function post(string $url, array $headers, string $body, int $timeout): \ADCT\ParishIntake\Core\Ports\HttpResponse
                    {
                        throw new \RuntimeException('Not used in settings tests.');
                    }
                },
                new class implements AiCallGateInterface {
                    public function reserve(): bool
                    {
                        return false;
                    }

                    public function backOff(int $seconds): void
                    {
                    }
                },
                $attachments,
                '',
                null,
                $audit,
                null,
                $secretLookup
            );
        }

        private function privateProperty(object $object, string $property): mixed
        {
            // No setAccessible() call: it has been a no-op since PHP 8.1 and is deprecated in 8.5.
            $reflection = new ReflectionProperty($object, $property);

            return $reflection->getValue($object);
        }
    }

    /**
     * Captures the audit rows a settings save writes, so a test can read what
     * the log would have received without a database.
     */
    final class RecordingAuditWriter implements AuditWriter
    {
        /** @var list<array<string, mixed>> */
        public array $rows = [];

        public ?RuntimeException $failure = null;

        public function write(
            string $actor,
            AuditAction $action,
            string $subjectType,
            int $subjectId,
            array $details
        ): int {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            $this->rows[] = [
                'actor' => $actor,
                'action' => $action,
                'subjectType' => $subjectType,
                'subjectId' => $subjectId,
                'details' => $details,
            ];

            return 1;
        }
    }
}
