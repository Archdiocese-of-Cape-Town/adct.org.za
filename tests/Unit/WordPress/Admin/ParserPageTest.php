<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin {
    function is_admin(): bool
    {
        return true;
    }

    function current_user_can(string $capability): bool
    {
        return true;
    }

    function check_admin_referer(string $action, string $name): void
    {
    }

    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }

    function sanitize_text_field(mixed $value): string
    {
        return is_string($value) ? trim(strip_tags($value)) : '';
    }

    function esc_url_raw(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    function update_option(string $option, $value, $autoload = null): bool
    {
        $GLOBALS['parser_page_options'][$option] = $value;
        $GLOBALS['parser_page_updates'][] = ['option' => $option, 'value' => $value];

        return true;
    }

    function get_option(string $option, $default = false)
    {
        return $GLOBALS['parser_page_options'][$option] ?? $default;
    }

    function delete_option(string $option): bool
    {
        $present = array_key_exists($option, $GLOBALS['parser_page_options']);
        unset($GLOBALS['parser_page_options'][$option]);

        return $present;
    }

    function error_log(string $message): bool
    {
        $GLOBALS['parser_page_logs'][] = $message;

        return true;
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
    use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
    use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
    use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
    use ADCT\ParishIntake\WordPress\Admin\ParserPage;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
    use ADCT\ParishIntake\WordPress\Database\Schema;
    use ADCT\ParishIntake\WordPress\Export\StaticReportGenerator;
    use ADCT\ParishIntake\WordPress\Jobs\RetentionSettings;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

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
                'adct_parish_intake_section_keywords' => ['liturgy' => ['mass']],
                RetentionSettings::RAW_ENABLED_OPTION => '0',
                RetentionSettings::RAW_DAYS_OPTION => (string) RetentionSettings::DEFAULT_RAW_DAYS,
                RetentionSettings::PROCESSED_ENABLED_OPTION => '0',
                RetentionSettings::PROCESSED_DAYS_OPTION => (string) RetentionSettings::DEFAULT_PROCESSED_DAYS,
                RetentionSettings::ACTION_TOKENS_ENABLED_OPTION => '0',
                RetentionSettings::AUDIT_ENABLED_OPTION => '0',
            ];
            $GLOBALS['parser_page_updates'] = [];
            $GLOBALS['parser_page_logs'] = [];
            $_POST = [];
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
            self::assertSame(['liturgy' => ['mass']], $GLOBALS['parser_page_options']['adct_parish_intake_section_keywords']);
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

        private function page(?AttachmentRepository $attachments = null): ParserPage
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
                $attachments
            );
        }

        private function privateProperty(object $object, string $property): mixed
        {
            // No setAccessible() call: it has been a no-op since PHP 8.1 and is deprecated in 8.5.
            $reflection = new ReflectionProperty($object, $property);

            return $reflection->getValue($object);
        }
    }
}
