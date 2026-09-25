<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ActionTokenServiceTest extends TestCase
{
    public function testIssueStoresOnlyTheHashAndBindsPurposeSubjectAndEmail(): void
    {
        $store = new InMemoryActionTokenStore();
        $clock = new ActionTokenTestClock();
        $service = new ActionTokenService($store, $clock);
        $binding = new ActionTokenBinding(
            ActionTokenPurpose::CONFIRM,
            'candidate',
            17,
            ' Events@Example.test '
        );

        $issued = $service->issue($binding);
        $record = $store->findByHash(hash('sha256', $issued->token()));

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $issued->token());
        self::assertSame(32, strlen((string) base64_decode(
            strtr($issued->token(), '-_', '+/') . '=',
            true
        )));
        self::assertNotNull($record);
        self::assertNotSame($issued->token(), $record->tokenHash);
        self::assertSame(hash('sha256', $issued->token()), $record->tokenHash);
        self::assertSame(ActionTokenPurpose::CONFIRM, $record->binding->purpose);
        self::assertSame('candidate', $record->binding->subjectType);
        self::assertSame(17, $record->binding->subjectId);
        self::assertSame('events@example.test', $record->binding->email);
        self::assertSame(
            '2026-10-09 00:00:00',
            $record->expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    public function testInspectingAValidTokenDoesNotChangeItsState(): void
    {
        $store = new InMemoryActionTokenStore();
        $service = new ActionTokenService($store, new ActionTokenTestClock());
        $binding = $this->binding();
        $issued = $service->issue($binding);

        $inspection = $service->inspect($issued->token());

        self::assertSame(ActionTokenStatus::VALID, $inspection->status);
        self::assertSame($binding, $inspection->binding);
        self::assertSame(0, $store->consumeCalls);
        self::assertNull($store->findByHash(hash('sha256', $issued->token()))?->usedAt);
    }

    public function testTokenCanBeConsumedOnce(): void
    {
        $store = new InMemoryActionTokenStore();
        $service = new ActionTokenService($store, new ActionTokenTestClock());
        $binding = $this->binding();
        $issued = $service->issue($binding);

        $first = $service->consume($issued->token(), $binding);
        $second = $service->consume($issued->token(), $binding);

        self::assertSame(ActionTokenStatus::CONSUMED, $first->status);
        self::assertSame(ActionTokenStatus::USED, $second->status);
        self::assertNotNull($store->findByHash(hash('sha256', $issued->token()))?->usedAt);
    }

    public function testExpiredTokenCannotBeInspectedOrConsumed(): void
    {
        $store = new InMemoryActionTokenStore();
        $clock = new ActionTokenTestClock();
        $service = new ActionTokenService($store, $clock);
        $binding = $this->binding();
        $issued = $service->issue($binding, $clock->now()->modify('+1 minute'));
        $clock->advance('+1 minute');

        self::assertSame(ActionTokenStatus::EXPIRED, $service->inspect($issued->token())->status);
        self::assertSame(
            ActionTokenStatus::EXPIRED,
            $service->consume($issued->token(), $binding)->status
        );
        self::assertNull($store->findByHash(hash('sha256', $issued->token()))?->usedAt);
    }

    public function testPurposeSubjectAndEmailMustAllMatchForConsumption(): void
    {
        $bindings = [
            new ActionTokenBinding(ActionTokenPurpose::DENY, 'candidate', 17, 'events@example.test'),
            new ActionTokenBinding(ActionTokenPurpose::CONFIRM, 'event', 17, 'events@example.test'),
            new ActionTokenBinding(ActionTokenPurpose::CONFIRM, 'candidate', 18, 'events@example.test'),
            new ActionTokenBinding(ActionTokenPurpose::CONFIRM, 'candidate', 17, 'other@example.test'),
        ];

        foreach ($bindings as $expectedBinding) {
            $store = new InMemoryActionTokenStore();
            $service = new ActionTokenService($store, new ActionTokenTestClock());
            $issued = $service->issue($this->binding());

            self::assertSame(
                ActionTokenStatus::INVALID,
                $service->consume($issued->token(), $expectedBinding)->status
            );
            self::assertNull($store->findByHash(hash('sha256', $issued->token()))?->usedAt);
        }
    }

    public function testMalformedAndUnknownTokensAreInvalid(): void
    {
        $store = new InMemoryActionTokenStore();
        $service = new ActionTokenService($store, new ActionTokenTestClock());

        self::assertSame(ActionTokenStatus::INVALID, $service->inspect('not-a-token')->status);
        self::assertSame(ActionTokenStatus::INVALID, $service->inspect(str_repeat('x', 500))->status);
        self::assertSame(0, $store->lookupCalls);
    }

    public function testConcurrentConsumptionLosingTheAtomicUpdateIsReportedAsUsed(): void
    {
        $store = new InMemoryActionTokenStore();
        $store->simulateCompetingConsumer = true;
        $service = new ActionTokenService($store, new ActionTokenTestClock());
        $binding = $this->binding();
        $issued = $service->issue($binding);

        $result = $service->consume($issued->token(), $binding);

        self::assertSame(ActionTokenStatus::USED, $result->status);
        self::assertNotNull($store->findByHash(hash('sha256', $issued->token()))?->usedAt);
    }

    public function testLoginTokensUseTheShorterDefaultLifetime(): void
    {
        $store = new InMemoryActionTokenStore();
        $service = new ActionTokenService($store, new ActionTokenTestClock());
        $issued = $service->issue(new ActionTokenBinding(
            ActionTokenPurpose::LOGIN,
            'user',
            7,
            'person@example.test'
        ));

        self::assertSame(
            '2026-09-25 00:30:00',
            $issued->expiresAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
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

final class InMemoryActionTokenStore implements ActionTokenStoreInterface
{
    /**
     * @var array<string, ActionTokenRecord>
     */
    private array $records = [];

    public int $lookupCalls = 0;
    public int $consumeCalls = 0;
    public bool $simulateCompetingConsumer = false;

    public function create(ActionTokenRecord $record): void
    {
        $this->records[$record->tokenHash] = $record;
    }

    public function findByHash(string $tokenHash): ?ActionTokenRecord
    {
        ++$this->lookupCalls;

        return $this->records[$tokenHash] ?? null;
    }

    public function consume(
        string $tokenHash,
        ActionTokenBinding $binding,
        DateTimeImmutable $now
    ): bool {
        ++$this->consumeCalls;
        $record = $this->records[$tokenHash] ?? null;

        if (
            $record === null
            || ! $record->binding->equals($binding)
            || $record->usedAt !== null
            || $record->expiresAt <= $now
        ) {
            return false;
        }

        if ($this->simulateCompetingConsumer) {
            $this->records[$tokenHash] = new ActionTokenRecord(
                $record->tokenHash,
                $record->binding,
                $record->expiresAt,
                $now,
                $record->createdAt
            );
            $this->simulateCompetingConsumer = false;

            return false;
        }

        $this->records[$tokenHash] = new ActionTokenRecord(
            $record->tokenHash,
            $record->binding,
            $record->expiresAt,
            $now,
            $record->createdAt
        );

        return true;
    }
}

final class ActionTokenTestClock implements ClockInterface
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
