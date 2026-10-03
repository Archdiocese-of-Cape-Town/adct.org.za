<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval {
    function get_option(string $option, mixed $default = false): mixed
    {
        return ApprovalReminderOptionOptionsFixture::$options[$option] ?? $default;
    }

    final class ApprovalReminderOptionOptionsFixture
    {
        /**
         * @var array<string, mixed>
         */
        public static array $options = [];
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval {
    use ADCT\ParishIntake\Core\Approval\ApprovalReminderSettings;
    use ADCT\ParishIntake\WordPress\Approval\ApprovalReminderOptionOptionsFixture;
    use ADCT\ParishIntake\WordPress\Approval\ApprovalReminderOptionReader;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class ApprovalReminderOptionReaderTest extends TestCase
    {
        protected function setUp(): void
        {
            ApprovalReminderOptionOptionsFixture::$options = [];
        }

        public function testUnsetOptionsEnableRemindersAfterTheDefaultPeriod(): void
        {
            $settings = (new ApprovalReminderOptionReader())->read();

            self::assertTrue($settings->enabled);
            self::assertSame(ApprovalReminderSettings::DEFAULT_DAYS, $settings->days);
        }

        public function testStoredSwitchAndPeriodAreBothHonoured(): void
        {
            ApprovalReminderOptionOptionsFixture::$options = [
                ApprovalReminderOptionReader::ENABLED_OPTION => '1',
                ApprovalReminderOptionReader::DAYS_OPTION => '7',
            ];

            $settings = (new ApprovalReminderOptionReader())->read();

            self::assertTrue($settings->enabled);
            self::assertSame(7, $settings->days);
        }

        public function testTheGlobalSwitchTurnsRemindersOff(): void
        {
            ApprovalReminderOptionOptionsFixture::$options = [
                ApprovalReminderOptionReader::ENABLED_OPTION => '0',
                ApprovalReminderOptionReader::DAYS_OPTION => '7',
            ];

            $settings = (new ApprovalReminderOptionReader())->read();

            self::assertFalse($settings->enabled);
            self::assertSame(7, $settings->days);
        }

        #[DataProvider('unusablePeriodCases')]
        public function testAnUnusableStoredPeriodDisablesRemindersRatherThanThrowing(string $stored): void
        {
            ApprovalReminderOptionOptionsFixture::$options = [
                ApprovalReminderOptionReader::ENABLED_OPTION => '1',
                ApprovalReminderOptionReader::DAYS_OPTION => $stored,
            ];

            $settings = (new ApprovalReminderOptionReader())->read();

            self::assertFalse($settings->enabled);
            self::assertSame(ApprovalReminderSettings::DEFAULT_DAYS, $settings->days);
        }

        public static function unusablePeriodCases(): iterable
        {
            yield 'not a number' => ['later'];
            yield 'zero' => ['0'];
            yield 'negative' => ['-3'];
            yield 'above the maximum' => ['366'];
            yield 'an empty string' => [''];
        }
    }
}
