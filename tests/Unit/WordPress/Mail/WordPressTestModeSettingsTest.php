<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Mail {
    function get_option(string $option, mixed $default = false): mixed
    {
        return WordPressTestModeOptionsFixture::$options[$option] ?? $default;
    }

    final class WordPressTestModeOptionsFixture
    {
        /**
         * @var array<string, mixed>
         */
        public static array $options = [];
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Mail {
    use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeOptionsFixture;
    use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeRecipientPolicy;
    use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeSettings;
    use PHPUnit\Framework\TestCase;

    final class WordPressTestModeSettingsTest extends TestCase
    {
        protected function setUp(): void
        {
            WordPressTestModeOptionsFixture::$options = [];
        }

        public function testMissingOptionsUseTheProductionOffDefault(): void
        {
            $policy = new WordPressTestModeRecipientPolicy();

            self::assertTrue($policy->allows('anyone@example.test'));
        }

        public function testEnabledModeWithAnEmptyAllowlistBlocksEveryRecipientAndReportsAnError(): void
        {
            $settings = WordPressTestModeSettings::fromValues(
                WordPressTestModeSettings::MODE_ENABLED,
                []
            );

            self::assertTrue($settings->isEnabled());
            self::assertFalse($settings->allowsRecipient('allowed@example.test'));
            self::assertNotEmpty($settings->configurationError());
        }

        public function testInvalidModeAndAllowlistValuesFailClosed(): void
        {
            $invalidMode = WordPressTestModeSettings::fromValues(
                'enabled ',
                ['allowed@example.test']
            );
            $invalidList = WordPressTestModeSettings::fromValues(
                WordPressTestModeSettings::MODE_DISABLED,
                ['not-an-email']
            );

            self::assertFalse($invalidMode->allowsRecipient('allowed@example.test'));
            self::assertNotEmpty($invalidMode->configurationError());
            self::assertFalse($invalidList->allowsRecipient('anyone@example.test'));
            self::assertNotEmpty($invalidList->configurationError());
        }

        public function testValidTestModeUsesTheCurrentAddressAndDomainAllowlist(): void
        {
            $settings = WordPressTestModeSettings::fromValues(
                WordPressTestModeSettings::MODE_ENABLED,
                ['approved@example.test', '@qa.example.test']
            );

            self::assertNull($settings->configurationError());
            self::assertTrue($settings->allowsRecipient('approved@example.test'));
            self::assertTrue($settings->allowsRecipient('tester@qa.example.test'));
            self::assertFalse($settings->allowsRecipient('parish@example.test'));
        }

        public function testRecipientPolicyReloadsSettingsForEachEnqueueOrDispatchCheck(): void
        {
            $policy = new WordPressTestModeRecipientPolicy();

            self::assertTrue($policy->allows('queued@example.test'));

            WordPressTestModeOptionsFixture::$options = [
                WordPressTestModeSettings::TEST_MODE_OPTION => WordPressTestModeSettings::MODE_ENABLED,
                WordPressTestModeSettings::ALLOWLIST_OPTION => ['allowed@example.test'],
            ];

            self::assertFalse($policy->allows('queued@example.test'));
            self::assertTrue($policy->allows('allowed@example.test'));
        }
    }
}
