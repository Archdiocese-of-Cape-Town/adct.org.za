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
        use ADCT\ParishIntake\WordPress\Ocr\OcrSpaceProvider;
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

        /**
         * A settings form posts every field on every save, so this is the
         * "nothing changed" baseline the audit tests compare against. The
         * section keywords are the sanitised defaults, because a submitted
         * list is merged over the defaults rather than replacing them, and a
         * baseline that differs from what would be stored would look like a
         * change on every save.
         *
         * @return array<string, string|array<string, string>>
         */
        public function testTheOcrOptInIsOffUntilAnAdministratorTurnsItOn(): void
        {
            self::assertSame('0', $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION]);

            $_POST = $this->settingsPost();
            $_POST['ocr_enabled'] = '1';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertSame('1', $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION]);
            self::assertCount(1, $audit->rows);
            $options = array_column($audit->rows[0]['details']['changed'], 'option', 'option');
            self::assertArrayHasKey(OcrSettings::ENABLED_OPTION, $options);
        }

        public function testUncheckingTheOcrOptInWritesItOffRatherThanLeavingItOn(): void
        {
            // An unchecked checkbox is not submitted at all, so the handler has
            // to read absence as "off" or a revoke would silently do nothing.
            $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION] = '1';

            $_POST = $this->settingsPost();

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertSame('0', $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION]);
            self::assertCount(1, $audit->rows);
        }

        public function testTheOcrDailyCallLimitIsStoredAndAudited(): void
        {
            $_POST = $this->settingsPost();
            $_POST['ocr_daily_call_limit'] = '3';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertSame('3', $GLOBALS['parser_page_options'][OcrSettings::DAILY_CALL_LIMIT_OPTION]);
            self::assertArrayHasKey(
                OcrSettings::DAILY_CALL_LIMIT_OPTION,
                array_column($audit->rows[0]['details']['changed'], 'option', 'option')
            );
        }

        public function testAnUnusableOcrLimitLeavesEverySettingUnchanged(): void
        {
            $_POST = $this->settingsPost();
            $_POST['ocr_enabled'] = '1';
            $_POST['ocr_daily_call_limit'] = '0';
            $_POST['ai_provider'] = 'openrouter';

            $page = $this->page();
            $page->maybeHandleSettings();

            self::assertSame([], $GLOBALS['parser_page_updates']);
            self::assertSame('none', $GLOBALS['parser_page_options']['adct_parish_intake_ai_provider']);
            self::assertSame('0', $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION]);
            self::assertSame(
                'Poster OCR is enabled, but the daily call limit must be a whole number between '
                . OcrSettings::MIN_DAILY_CALL_LIMIT . ' and ' . OcrSettings::MAX_DAILY_CALL_LIMIT . '.',
                $this->privateProperty($page, 'settingsSaveError')
            );
        }

        public function testAnOcrLimitThatIsMerelyUnusableIsIgnoredWhenOcrStaysOff(): void
        {
            // Switching OCR off must never be blocked by a bad cap field: the
            // operator's way out of the feature has to keep working.
            $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION] = '1';
            $_POST = $this->settingsPost();
            $_POST['ocr_daily_call_limit'] = 'not a number';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertSame('0', $GLOBALS['parser_page_options'][OcrSettings::ENABLED_OPTION]);
            self::assertSame(
                (string) OcrSettings::DEFAULT_DAILY_CALL_LIMIT,
                $GLOBALS['parser_page_options'][OcrSettings::DAILY_CALL_LIMIT_OPTION]
            );
            self::assertCount(1, $audit->rows);
        }

        public function testASavedOcrApiKeyIsNeverEchoedIntoTheAuditRow(): void
        {
            $_POST = $this->settingsPost();
            $_POST['ocr_enabled'] = '1';
            $_POST['ocr_api_key'] = 'K811-do-not-log-ocr';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            self::assertCount(1, $audit->rows);
            self::assertStringNotContainsString(
                'K811-do-not-log-ocr',
                json_encode($audit->rows[0]['details'], JSON_THROW_ON_ERROR)
            );
            self::assertSame(
                'K811-do-not-log-ocr',
                $GLOBALS['parser_page_options'][SecretRegistry::optionName(SecretRegistry::OCR_API_KEY)]
            );
        }

        public function testRemovingTheOcrApiKeyIsRecordedAsRemovalRatherThanSilently(): void
        {
            $GLOBALS['parser_page_options'][SecretRegistry::optionName(SecretRegistry::OCR_API_KEY)] = 'K811-old';

            $_POST = $this->settingsPost();
            $_POST['remove_ocr_api_key'] = '1';

            $audit = new RecordingAuditWriter();
            $this->page(audit: $audit)->maybeHandleSettings();

            $option = SecretRegistry::optionName(SecretRegistry::OCR_API_KEY);
            self::assertArrayNotHasKey($option, $GLOBALS['parser_page_options']);
            self::assertCount(1, $audit->rows);
            self::assertStringNotContainsString(
                'K811-old',
                json_encode($audit->rows[0]['details'], JSON_THROW_ON_ERROR)
            );
        }

        public function testAnOcrKeyIsNotStoredWhileOneIsDefinedInWpConfig(): void
        {
                    // A wp-config.php constant cannot be defined and then undefined
                    // inside one PHP process, so the constant path is exercised in its
                    // own process by tests/Integration/verify-plugin.php. What matters
                    // here is the writable half: a submitted key is stored, and nothing
                    // about it reaches the audit row.
                    $_POST = $this->settingsPost();
                    $_POST['ocr_enabled'] = '1';
                    $_POST['ocr_api_key'] = 'K811-writable';

                    $audit = new RecordingAuditWriter();
                    $this->page(audit: $audit)->maybeHandleSettings();

                    self::assertSame(
                        'K811-writable',
                        $GLOBALS['parser_page_options'][SecretRegistry::optionName(SecretRegistry::OCR_API_KEY)]
                    );
                    self::assertStringNotContainsString(
                        'K811-writable',
                        json_encode($audit->rows[0]['details'], JSON_THROW_ON_ERROR)
                    );
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

        public function testTheSettingsPageExplainsWhatPosterOcrSendsOffTheSite(): void
        {
            ob_start();
            try {
                $this->page()->renderSettingsPage();
            } finally {
                $html = (string) ob_get_clean();
            }

            self::assertStringContainsString('name="ocr_enabled"', $html);
            self::assertStringContainsString('name="ocr_daily_call_limit"', $html);
            self::assertStringContainsString('name="ocr_api_key" value=""', $html);
            self::assertStringContainsString(OcrSpaceProvider::PROVIDER_NAME, $html);
            // The egress note has to say what is NOT sent, or an operator
            // cannot make the POPIA decision the opt-in exists to force.
            self::assertStringContainsString('the image attachment itself and nothing else', $html);
            self::assertStringContainsString('never sent with it', $html);
            self::assertStringContainsString('never shown again', $html);
        }

        public function testTheSettingsPageSaysPosterOcrIsOffByDefault(): void
        {
            ob_start();
            try {
                $this->page()->renderSettingsPage();
            } finally {
                $html = (string) ob_get_clean();
            }

            self::assertStringContainsString('off by default', $html);
            self::assertStringNotContainsString('name="ocr_enabled" value="1" checked', $html);
        }

        public function testTheSettingsPageNeverEchoesASavedOcrKey(): void
        {
            $html = $this->renderSettings(storedKeys: [SecretRegistry::OCR_API_KEY => 'K811-never-shown']);

            // The field stays empty and the key appears nowhere, so the secret never
            // reaches the page HTML or the admin DOM.
            self::assertStringNotContainsString('K811-never-shown', $html);
            self::assertStringContainsString('name="ocr_api_key" value=""', $html);
            // A saved key is never re-pasted over by the paste prompt.
            self::assertStringNotContainsString($this->ocrKeyPrompt(), $html);
        }

        public function testTheSettingsPageOffersToRemoveASavedOcrKey(): void
        {
            $html = $this->renderSettings(storedKeys: [SecretRegistry::OCR_API_KEY => 'K811-saved']);

            // Without this control a saved key can only be removed by editing the
            // database, which is exactly what the no-WP-CLI rule rules out.
            self::assertStringContainsString('A key is saved. Leave blank to keep it.', $html);
            self::assertStringContainsString('name="remove_ocr_api_key"', $html);
        }

        public function testTheSettingsPagePromptsForAOcrKeyWhenNoneIsSaved(): void
        {
            $html = $this->renderSettings();

            self::assertStringContainsString($this->ocrKeyPrompt(), $html);
            self::assertStringNotContainsString('name="remove_ocr_api_key"', $html);
        }

        /**
         * The OCR row's "paste a key" prompt.
         *
         * Distinct from the AI key prompt directly above it, which reads "Paste a
         * valid API key" and does not name the provider. Asserting on the bare
         * phrase "Paste a valid" therefore passes for the wrong row.
         */
        private function ocrKeyPrompt(): string
        {
            return 'Paste a valid ' . \ADCT\ParishIntake\WordPress\Ocr\OcrSpaceProvider::PROVIDER_NAME
                . ' key only when you need to add or replace it.';
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
                                'ocr_daily_call_limit' => (string) OcrSettings::DEFAULT_DAILY_CALL_LIMIT,
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
