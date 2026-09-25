<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitKeyProviderInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActionTokenRateLimiterTest extends TestCase
{
    private const SECRET = 'test-rate-limit-hmac-key-with-at-least-32-bytes';

    public function testEmailLimitIsSharedAcrossRequestIpAddresses(): void
    {
        $store = new InMemoryActionTokenRateLimitStore();
        $limiter = new ActionTokenRateLimiter($store, new RateLimitTestClock(), self::SECRET);

        self::assertTrue($limiter->allowRequest('events@example.test', '203.0.113.1'));
        self::assertTrue($limiter->allowRequest('events@example.test', '203.0.113.2'));
        self::assertTrue($limiter->allowRequest('events@example.test', '203.0.113.3'));
        self::assertFalse($limiter->allowRequest('events@example.test', '203.0.113.4'));
    }

    public function testIpLimitIsSharedAcrossRequestedEmailAddresses(): void
    {
        $store = new InMemoryActionTokenRateLimitStore();
        $limiter = new ActionTokenRateLimiter($store, new RateLimitTestClock(), self::SECRET);

        for ($index = 0; $index < ActionTokenRateLimiter::IP_REQUEST_LIMIT; ++$index) {
            self::assertTrue($limiter->allowRequest(
                'contact' . $index . '@example.test',
                '203.0.113.5'
            ));
        }

        self::assertFalse($limiter->allowRequest('last@example.test', '203.0.113.5'));
    }

    public function testRateLimitKeysAreHashedAndBucketsResetAfterOneHour(): void
    {
        $store = new InMemoryActionTokenRateLimitStore();
        $clock = new RateLimitTestClock();
        $limiter = new ActionTokenRateLimiter($store, $clock, self::SECRET);

        self::assertTrue($limiter->allowRequest('events@example.test', '203.0.113.5'));

        foreach (array_keys($store->buckets) as $key) {
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $key);
            self::assertStringNotContainsString('events@example.test', $key);
            self::assertStringNotContainsString('203.0.113.5', $key);
        }

        $clock->advance('+1 hour');

        self::assertTrue($limiter->allowRequest('events@example.test', '203.0.113.5'));
        self::assertCount(2, $store->buckets);
        self::assertSame(1, $store->buckets[array_key_first($store->buckets)]['count']);
    }

    public function testHmacKeyProviderIsResolvedOnlyWhenARequestIsLimited(): void
    {
        $store = new InMemoryActionTokenRateLimitStore();
        $keyProvider = new RateLimitTestKeyProvider();
        $limiter = new ActionTokenRateLimiter($store, new RateLimitTestClock(), $keyProvider);

        self::assertSame(0, $keyProvider->calls);
        self::assertTrue($limiter->allowRequest('events@example.test', '203.0.113.5'));
        self::assertSame(1, $keyProvider->calls);
    }

    public function testInvalidEmailAndRateLimitConfigurationAreRejected(): void
    {
        $store = new InMemoryActionTokenRateLimitStore();
        $limiter = new ActionTokenRateLimiter($store, new RateLimitTestClock(), self::SECRET);

        try {
            $limiter->allowRequest('not-an-email', '203.0.113.5');
            self::fail('The rate limiter must reject invalid email addresses.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $store->buckets);
        }

        $this->expectException(InvalidArgumentException::class);
        new ActionTokenRateLimiter($store, new RateLimitTestClock(), self::SECRET, 0);
    }
}

final class RateLimitTestKeyProvider implements ActionTokenRateLimitKeyProviderInterface
{
    public int $calls = 0;

    public function getKey(): string
    {
        ++$this->calls;

        return 'test-rate-limit-hmac-key-with-at-least-32-bytes';
    }
}

final class InMemoryActionTokenRateLimitStore implements ActionTokenRateLimitStoreInterface
{
    /**
     * @var array<string, array{window: string, count: int}>
     */
    public array $buckets = [];

    public function consume(
        string $scopeHash,
        DateTimeImmutable $windowStart,
        int $limit
    ): bool {
        $window = $windowStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $bucket = $this->buckets[$scopeHash] ?? ['window' => $window, 'count' => 0];

        if ($bucket['window'] !== $window) {
            $bucket = ['window' => $window, 'count' => 0];
        }

        if ($bucket['count'] >= $limit) {
            $this->buckets[$scopeHash] = $bucket;

            return false;
        }

        ++$bucket['count'];
        $this->buckets[$scopeHash] = $bucket;

        return true;
    }
}

final class RateLimitTestClock implements ClockInterface
{
    private DateTimeImmutable $instant;

    public function __construct()
    {
        $this->instant = new DateTimeImmutable(
            '2026-09-25 02:00:00',
            new DateTimeZone('Africa/Johannesburg')
        );
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function advance(string $interval): void
    {
        $this->instant = $this->instant->modify($interval);
    }
}
