<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Ocr {
    function get_option(string $option, mixed $default = false): mixed
    {
        return OcrCallGateOptionsFixture::$options[$option] ?? $default;
    }

    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        OcrCallGateOptionsFixture::$options[$option] = $value;

        return true;
    }

    final class OcrCallGateOptionsFixture
    {
        /**
         * @var array<string, mixed>
         */
        public static array $options = [];
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Ocr {
    use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
        use ADCT\ParishIntake\Core\Ports\ClockInterface;
        use ADCT\ParishIntake\WordPress\Ocr\OcrCallGateOptionsFixture;
    use ADCT\ParishIntake\WordPress\Ocr\WordPressOcrCallGate;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class WordPressOcrCallGateTest extends TestCase
    {
        protected function setUp(): void
        {
            OcrCallGateOptionsFixture::$options = [];
        }

        public function testACallWithinTheCapIsAllowed(): void
        {
            $limits = new RecordingRateLimitStore();

            self::assertTrue((new WordPressOcrCallGate($limits, new FixedClock()))->reserve());
        }

        public function testTheCapIsCountedInItsOwnBucketSoItCannotDrainTheAiBudget(): void
        {
            $limits = new RecordingRateLimitStore();

            (new WordPressOcrCallGate($limits, new FixedClock(), 25))->reserve();

            self::assertSame(
                [hash('sha256', 'adct_pi_ocr_daily_calls'), 25],
                [$limits->scopeHashes[0] ?? null, $limits->limits[0] ?? null]
            );
            self::assertNotSame(hash('sha256', 'adct_pi_ai_daily_calls'), $limits->scopeHashes[0] ?? null);
        }

        public function testTheDailyWindowIsTheUtcDaySoTheCapResetsOnItsOwn(): void
        {
            $limits = new RecordingRateLimitStore();

            (new WordPressOcrCallGate($limits, new FixedClock()))->reserve();

            self::assertSame('2026-10-03 00:00:00', $limits->windowStarts[0] ?? null);
        }

        public function testASpentCapStopsTheCallWithoutTouchingTheService(): void
        {
            $limits = new RecordingRateLimitStore();
            $limits->allowed = false;

            self::assertFalse((new WordPressOcrCallGate($limits, new FixedClock()))->reserve());
        }

        public function testARateLimitFromTheServiceHoldsEveryPosterBackForTheGivenTime(): void
        {
            OcrCallGateOptionsFixture::$options = [];

            $gate = new WordPressOcrCallGate(new RecordingRateLimitStore(), new FixedClock());

            $gate->backOff(600);

            self::assertFalse($gate->reserve(), 'the next run must not walk into the same rate limit');
        }

        public function testBackOffNeverShortensAnExistingCooldown(): void
        {
            $gate = new WordPressOcrCallGate(new RecordingRateLimitStore(), new FixedClock());

            $gate->backOff(600);
            $gate->backOff(60);

            self::assertGreaterThanOrEqual(
                self::now()->getTimestamp() + 600,
                (int) OcrCallGateOptionsFixture::$options['adct_parish_intake_ocr_backoff_until']
            );
        }

        public function testTheCooldownExpiresOnItsOwn(): void
        {
            OcrCallGateOptionsFixture::$options = [
                'adct_parish_intake_ocr_backoff_until' => self::now()->getTimestamp() - 1,
            ];

            self::assertTrue(
                (new WordPressOcrCallGate(new RecordingRateLimitStore(), new FixedClock()))->reserve()
            );
        }

        #[DataProvider('unusableCaps')]
        public function testAnUnusableCapIsRejectedAtConstruction(int $cap): void
        {
            $this->expectException(\InvalidArgumentException::class);

            new WordPressOcrCallGate(new RecordingRateLimitStore(), new FixedClock(), $cap);
        }

        public static function unusableCaps(): iterable
        {
            yield 'zero' => [0];
            yield 'negative' => [-1];
            yield 'above the ceiling' => [1001];
        }

        public function testTheDefaultCapIsInsideTheFreeTiersDailyAllowance(): void
        {
            $limits = new RecordingRateLimitStore();

            new WordPressOcrCallGate($limits, new FixedClock());
            (new WordPressOcrCallGate($limits, new FixedClock()))->reserve();

            self::assertSame(25, $limits->limits[0] ?? null);
        }

        private static function now(): DateTimeImmutable
        {
            return (new DateTimeImmutable('2026-10-03T22:05:00', new DateTimeZone('Africa/Johannesburg')))
                ->setTimezone(new DateTimeZone('UTC'));
        }
    }

    final class FixedClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-03T22:05:00', new DateTimeZone('Africa/Johannesburg'));
        }
    }

    final class RecordingRateLimitStore implements ActionTokenRateLimitStoreInterface
    {
        public bool $allowed = true;

        /**
         * @var list<string>
         */
        public array $scopeHashes = [];

        /**
         * @var list<int>
         */
        public array $limits = [];

        /**
         * @var list<string>
         */
        public array $windowStarts = [];

        public function consume(string $scopeHash, DateTimeImmutable $windowStart, int $limit): bool
        {
            $this->scopeHashes[] = $scopeHash;
            $this->limits[] = $limit;
            $this->windowStarts[] = $windowStart->format('Y-m-d H:i:s');

            return $this->allowed;
        }
    }

    }