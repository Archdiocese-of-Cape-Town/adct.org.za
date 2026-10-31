<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../../Support/WordPressOptionsStubs.php';
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Jobs {
    use ADCT\ParishIntake\WordPress\Jobs\OcrSettings;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class OcrSettingsTest extends TestCase
    {
        protected function setUp(): void
        {
            new_options_database();
        }

        public function testOcrIsUnavailableEvenWhenTheSettingIsOff(): void
        {
            $settings = OcrSettings::fromValues('0', '20');

            self::assertFalse($settings->isEnabled());
            self::assertFalse($settings->isConfiguredEnabled());
        }

        #[DataProvider('acceptedTruthyValues')]
        public function testEveryPreviouslyAcceptedOptInValueRemainsRecognizedButUnavailable(mixed $submitted): void
        {
            $settings = OcrSettings::fromValues($submitted, '20');

            self::assertFalse($settings->isEnabled());
            self::assertTrue($settings->isConfiguredEnabled());
        }

        /**
         * @return iterable<string, array{mixed}>
         */
        public static function acceptedTruthyValues(): iterable
        {
            yield 'string one' => ['1'];
            yield 'int one' => [1];
            yield 'boolean true' => [true];
            yield 'checkbox on' => ['on'];
            yield 'word yes' => ['yes'];
        }

        #[DataProvider('rejectedTruthyValues')]
        public function testAnythingElseLeavesOcrOff(mixed $submitted): void
        {
            $settings = OcrSettings::fromValues($submitted, '20');

            self::assertFalse($settings->isEnabled());
            self::assertFalse($settings->isConfiguredEnabled());
        }

        /**
         * @return iterable<string, array{mixed}>
         */
        public static function rejectedTruthyValues(): iterable
        {
            yield 'absent checkbox' => [null];
            yield 'empty string' => [''];
            yield 'string zero' => ['0'];
            yield 'the word off' => ['off'];
            yield 'arbitrary text' => ['maybe'];
            yield 'array' => [['1']];
        }

        public function testTheDefaultDailyCapIsUsedWhenNothingIsStored(): void
        {
            self::assertSame(
                OcrSettings::DEFAULT_DAILY_CALL_LIMIT,
                OcrSettings::fromValues('0', OcrSettings::DEFAULT_DAILY_CALL_LIMIT)->dailyCallLimit()
            );
        }

        public function testALegacyStoredCapIsReadWithoutEnablingOcr(): void
        {
            $settings = OcrSettings::fromValues('1', '7');

            self::assertSame(7, $settings->dailyCallLimit());
            self::assertFalse($settings->isEnabled());
            self::assertTrue($settings->isConfiguredEnabled());
        }

        #[DataProvider('unusableCaps')]
        public function testAnUnusableCapFallsBackToTheDefaultWithoutFailing(mixed $submitted): void
        {
            $settings = OcrSettings::fromValues('0', $submitted);

            self::assertSame(OcrSettings::DEFAULT_DAILY_CALL_LIMIT, $settings->dailyCallLimit());
            self::assertNull($settings->configurationError());
        }

        /**
         * @return iterable<string, array{mixed}>
         */
        public static function unusableCaps(): iterable
        {
            yield 'empty' => [''];
            yield 'not a number' => ['lots'];
            yield 'zero' => ['0'];
            yield 'negative' => ['-5'];
            yield 'decimal' => ['2.5'];
            yield 'over the ceiling' => ['1001'];
            yield 'array' => [['20']];
        }

        #[DataProvider('unusableCaps')]
        public function testLegacyUnusableCapRemainsDiagnosableButCannotEnableOcr(mixed $submitted): void
        {
            $settings = OcrSettings::fromValues('1', $submitted);

            self::assertFalse($settings->isEnabled());
            self::assertTrue($settings->isConfiguredEnabled());
            self::assertNotNull($settings->configurationError());
            self::assertStringContainsString('daily call limit', (string) $settings->configurationError());
        }

        public function testLegacyCapBoundariesRemainReadableWithoutEnablingOcr(): void
        {
            foreach ([OcrSettings::MIN_DAILY_CALL_LIMIT, OcrSettings::MAX_DAILY_CALL_LIMIT] as $limit) {
                $settings = OcrSettings::fromValues('1', (string) $limit);

                self::assertSame($limit, $settings->dailyCallLimit());
                self::assertFalse($settings->isEnabled());
                self::assertNull($settings->configurationError());
            }
        }

        public function testCurrentReadsTheStoredOptions(): void
        {
            $GLOBALS['wpdb']->rows = [
                OcrSettings::ENABLED_OPTION => '1',
                OcrSettings::DAILY_CALL_LIMIT_OPTION => '5',
            ];

            $settings = OcrSettings::current();

            self::assertFalse($settings->isEnabled());
            self::assertTrue($settings->isConfiguredEnabled());
            self::assertSame(5, $settings->dailyCallLimit());
        }

        public function testCurrentOnAFreshInstallIsOffWithTheDefaultCap(): void
        {
            $settings = OcrSettings::current();

            self::assertFalse($settings->isEnabled());
            self::assertSame(OcrSettings::DEFAULT_DAILY_CALL_LIMIT, $settings->dailyCallLimit());
        }
    }
}