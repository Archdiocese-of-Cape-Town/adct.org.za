<?php

declare(strict_types=1);

namespace {
    /**
     * The audit writer records the acting user's email address, matching the
     * actor column the review queue repository has always written.
     */
    function wp_get_current_user(): object
    {
        return (object) ['user_email' => $GLOBALS['parser_page_user_email'] ?? ''];
    }

    require_once __DIR__ . '/../../../Support/AdminWordPressStubs.php';
}

namespace ADCT\ParishIntake\WordPress\Admin {
    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\is_admin')) {
        function is_admin(): bool
        {
            return true;
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
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Audit\AuditAction;
    use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
    use ADCT\ParishIntake\Core\Audit\AuditWriter;
    use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
    use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
    use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
    use ADCT\ParishIntake\Core\Parsing\SectionSkipper;
    use ADCT\ParishIntake\Core\Security\SecretRegistry;
    use ADCT\ParishIntake\WordPress\Admin\ParserPage;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
    use ADCT\ParishIntake\WordPress\Database\Schema;
    use ADCT\ParishIntake\WordPress\Export\StaticReportGenerator;
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
            ];
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
            // The settings screen audits who changed a setting, and the audit
            // row records the acting user's email address, matching the actor
            // column the review queue repository has always written.
            $GLOBALS['parser_page_user_email'] = 'chaplain@example.test';
            $_POST = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['adct_test_wp_caps']);
            unset($GLOBALS['parser_page_user_email']);
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

        private function page(?AttachmentRepository $attachments = null, ?AuditWriter $audit = null): ParserPage
        {
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
                $audit
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
