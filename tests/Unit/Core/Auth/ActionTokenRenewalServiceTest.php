<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalStatus;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Auth\IssuedActionToken;
use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenRenewalDeliveryInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ActionTokenRenewalServiceTest extends TestCase
{
    public function testRenewalIssuesAndDeliversANewTokenForExpiredContext(): void
    {
        $clock = new RenewalServiceTestClock();
        $store = new RenewalServiceTokenStore();
        $tokens = new ActionTokenService($store, $clock);
        $binding = $this->binding();
        $expired = $tokens->issue($binding, $clock->now()->modify('+1 minute'));
        $clock->advance('+1 minute');
        $delivery = new RecordingRenewalDelivery();
        $service = new ActionTokenRenewalService(
            $tokens,
            new ActionTokenRateLimiter(new RenewalServiceRateLimitStore(), $clock, str_repeat('k', 32)),
            $delivery
        );

        $result = $service->request($expired->token(), '203.0.113.5');

        self::assertSame(ActionTokenRenewalStatus::REQUESTED, $result);
        self::assertSame($binding, $delivery->binding);
        self::assertNotNull($delivery->issuedToken);
        self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($delivery->issuedToken->token())->status);
        self::assertNotSame($expired->token(), $delivery->issuedToken->token());
    }

    public function testUsedTokenCanRequestANewLinkWithinTheRateLimit(): void
    {
        $clock = new RenewalServiceTestClock();
        $store = new RenewalServiceTokenStore();
        $tokens = new ActionTokenService($store, $clock);
        $binding = $this->binding();
        $used = $tokens->issue($binding);
        $tokens->consume($used->token(), $binding);
        $delivery = new RecordingRenewalDelivery();
        $service = new ActionTokenRenewalService(
            $tokens,
            new ActionTokenRateLimiter(new RenewalServiceRateLimitStore(), $clock, str_repeat('k', 32)),
            $delivery
        );

        self::assertSame(
            ActionTokenRenewalStatus::REQUESTED,
            $service->request($used->token(), '203.0.113.5')
        );
        self::assertNotNull($delivery->issuedToken);
    }

    public function testValidAndInvalidTokensCannotTriggerRenewalDelivery(): void
    {
        $clock = new RenewalServiceTestClock();
        $store = new RenewalServiceTokenStore();
        $tokens = new ActionTokenService($store, $clock);
        $issued = $tokens->issue($this->binding());
        $delivery = new RecordingRenewalDelivery();
        $service = new ActionTokenRenewalService(
            $tokens,
            new ActionTokenRateLimiter(new RenewalServiceRateLimitStore(), $clock, str_repeat('k', 32)),
            $delivery
        );

        self::assertSame(
            ActionTokenRenewalStatus::NOT_ELIGIBLE,
            $service->request($issued->token(), '203.0.113.5')
        );
        self::assertSame(
            ActionTokenRenewalStatus::NOT_ELIGIBLE,
            $service->request('invalid', '203.0.113.5')
        );
        self::assertSame(1, count($store->records));
        self::assertNull($delivery->issuedToken);
    }

    public function testRateLimitedRenewalDoesNotIssueOrDeliverAToken(): void
    {
        $clock = new RenewalServiceTestClock();
        $store = new RenewalServiceTokenStore();
        $tokens = new ActionTokenService($store, $clock);
        $binding = $this->binding();
        $expired = $tokens->issue($binding, $clock->now()->modify('+1 minute'));
        $clock->advance('+1 minute');
        $delivery = new RecordingRenewalDelivery();
        $rateLimitStore = new RenewalServiceRateLimitStore(false);
        $service = new ActionTokenRenewalService(
            $tokens,
            new ActionTokenRateLimiter($rateLimitStore, $clock, str_repeat('k', 32)),
            $delivery
        );

        self::assertSame(
            ActionTokenRenewalStatus::RATE_LIMITED,
            $service->request($expired->token(), '203.0.113.5')
        );
        self::assertSame(1, count($store->records));
        self::assertNull($delivery->issuedToken);
    }

    private function binding(): ActionTokenBinding
    {
        return new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'candidate',
            17,
            'events@example.test'
        );
    }
}

final class RenewalServiceTokenStore implements ActionTokenStoreInterface
{
    /**
     * @var array<string, \ADCT\ParishIntake\Core\Auth\ActionTokenRecord>
     */
    public array $records = [];

    public function create(\ADCT\ParishIntake\Core\Auth\ActionTokenRecord $record): void
    {
        $this->records[$record->tokenHash] = $record;
    }

    public function findByHash(string $tokenHash): ?\ADCT\ParishIntake\Core\Auth\ActionTokenRecord
    {
        return $this->records[$tokenHash] ?? null;
    }

    public function consume(
        string $tokenHash,
        ActionTokenBinding $binding,
        DateTimeImmutable $now
    ): bool {
        $record = $this->records[$tokenHash] ?? null;

        if (
            $record === null
            || ! $record->binding->equals($binding)
            || $record->usedAt !== null
            || $record->expiresAt <= $now
        ) {
            return false;
        }

        $this->records[$tokenHash] = new \ADCT\ParishIntake\Core\Auth\ActionTokenRecord(
            $record->tokenHash,
            $record->binding,
            $record->expiresAt,
            $now,
            $record->createdAt
        );

        return true;
    }
}

final class RenewalServiceRateLimitStore implements ActionTokenRateLimitStoreInterface
{
    public function __construct(private bool $allowed = true)
    {
    }

    public function consume(string $scopeHash, DateTimeImmutable $windowStart, int $limit): bool
    {
        return $this->allowed;
    }
}

final class RecordingRenewalDelivery implements ActionTokenRenewalDeliveryInterface
{
    public ?ActionTokenBinding $binding = null;
    public ?IssuedActionToken $issuedToken = null;

    public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void
    {
        $this->binding = $binding;
        $this->issuedToken = $issuedToken;
    }
}

final class RenewalServiceTestClock implements ClockInterface
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
