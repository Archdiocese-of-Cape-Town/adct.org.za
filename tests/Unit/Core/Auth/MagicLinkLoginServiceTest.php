<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Auth\IssuedActionToken;
use ADCT\ParishIntake\Core\Auth\MagicLinkLoginService;
use ADCT\ParishIntake\Core\Auth\MagicLinkLoginStatus;
use ADCT\ParishIntake\Core\Ports\ActionTokenLoginDeliveryInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenLoginSubjectResolverInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * The request half of ADR 0007: a dean asks for a link, and the answer must be
 * the same whether or not the address belongs to an account.
 */
final class MagicLinkLoginServiceTest extends TestCase
{
    public function testAKnownApproverIsSentALoginToken(): void
    {
        [$service, $delivery, $tokens] = $this->service(['dean@example.test' => 42]);

        self::assertSame(MagicLinkLoginStatus::SENT, $service->request('dean@example.test', '203.0.113.5'));

        self::assertNotNull($delivery->issuedToken);
        $binding = $delivery->binding;
        self::assertNotNull($binding);
        self::assertSame(ActionTokenPurpose::LOGIN, $binding->purpose);
        self::assertSame(42, $binding->subjectId);

        $issued = $tokens->inspect($delivery->issuedToken->token());
        self::assertSame(ActionTokenStatus::VALID, $issued->status);
    }

    /**
     * The whole point of ADR 0007: an unknown address and a known one must be
     * indistinguishable from the outside, so both answer SENT.
     */
    public function testAnUnknownAddressIsIndistinguishableFromAKnownOne(): void
    {
        [$known, $knownDelivery] = $this->service(['dean@example.test' => 42]);
        [$unknown, $unknownDelivery] = $this->service([]);

        self::assertSame(
            $known->request('dean@example.test', '203.0.113.5'),
            $unknown->request('stranger@example.test', '203.0.113.5')
        );
        self::assertSame(MagicLinkLoginStatus::SENT, $unknown->request('stranger@example.test', '203.0.113.5'));

        self::assertNotNull($knownDelivery->issuedToken);
        self::assertNull($unknownDelivery->issuedToken);
        self::assertNull($unknownDelivery->binding);
    }

    /**
     * The limiter has to run before the resolver, otherwise the rate-limit
     * counters themselves become an oracle for which addresses exist.
     */
    public function testTheRateLimiterIsAskedBeforeTheAddressIsResolved(): void
    {
            $log = new LoginServiceCallLog();
            $limiterStore = new RecordingLoginRateLimitStore(true, $log);
            $resolver = new RecordingLoginSubjectResolver(['dean@example.test' => 42], $log);

            $service = new MagicLinkLoginService(
                new ActionTokenService(new LoginServiceTokenStore(), new LoginServiceTestClock()),
                new ActionTokenRateLimiter($limiterStore, new LoginServiceTestClock(), str_repeat('k', 32)),
                $resolver,
                new RecordingLoginDelivery()
            );

            $service->request('dean@example.test', '203.0.113.5');

            self::assertSame(
                ['limit', 'limit', 'resolve'],
                $log->calls,
                'Both rate-limit buckets are consulted before the address is looked up.'
            );
        }

    public function testARateLimitedRequestDeliversNothingAndResolvesNothing(): void
    {
            $log = new LoginServiceCallLog();
            $limiterStore = new RecordingLoginRateLimitStore(false, $log);
            $resolver = new RecordingLoginSubjectResolver(['dean@example.test' => 42], $log);
            $delivery = new RecordingLoginDelivery();

            $service = new MagicLinkLoginService(
                new ActionTokenService(new LoginServiceTokenStore(), new LoginServiceTestClock()),
                new ActionTokenRateLimiter($limiterStore, new LoginServiceTestClock(), str_repeat('k', 32)),
                $resolver,
                $delivery
            );

            self::assertSame(MagicLinkLoginStatus::RATE_LIMITED, $service->request('dean@example.test', '203.0.113.5'));

            self::assertNull($delivery->issuedToken);
            self::assertSame(['limit', 'limit'], $log->calls, 'A refused request stops before the address is looked up.');
            self::assertSame([], $resolver->asked);
        }

    /**
     * A delivery failure must not be swallowed: the service would otherwise
     * report SENT to the dean while no mail was queued.
     */
    public function testADeliveryFailurePropagates(): void
    {
        [$service] = $this->service(
            ['dean@example.test' => 42],
            null,
            new class implements ActionTokenLoginDeliveryInterface {
                public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void
                {
                    throw new DomainException('The sign-in link could not be queued.');
                }
            }
        );

        $this->expectException(DomainException::class);

        $service->request('dean@example.test', '203.0.113.5');
    }

    public function testTheAddressIsNormalisedBeforeItReachesTheResolver(): void
    {
        [$service, , , $resolver] = $this->service(['dean@example.test' => 42]);

        $service->request('  Dean@Example.Test  ', '203.0.113.5');

        self::assertSame(['dean@example.test'], $resolver->asked);
    }

    /**
     * @param array<string, int> $accounts   lowercase address => user ID
     * @return array{MagicLinkLoginService, RecordingLoginDelivery, ActionTokenService, RecordingLoginSubjectResolver, RecordingLoginRateLimitStore}
     */
    private function service(
        array $accounts,
        ?RecordingLoginRateLimitStore $limiterStore = null,
        ?ActionTokenLoginDeliveryInterface $delivery = null
    ): array {
        $clock = new LoginServiceTestClock();
        $tokens = new ActionTokenService(new LoginServiceTokenStore(), $clock);
        $store = $limiterStore ?? new RecordingLoginRateLimitStore(true);
        $resolver = new RecordingLoginSubjectResolver($accounts);
        $mailer = $delivery ?? new RecordingLoginDelivery();

        return [
            new MagicLinkLoginService(
                $tokens,
                new ActionTokenRateLimiter($store, $clock, str_repeat('k', 32)),
                $resolver,
                $mailer
            ),
            $mailer instanceof RecordingLoginDelivery ? $mailer : new RecordingLoginDelivery(),
            $tokens,
            $resolver,
            $store,
        ];
    }
}

final class RecordingLoginSubjectResolver implements ActionTokenLoginSubjectResolverInterface
{
    /** @var list<string> */
    public array $asked = [];

        /**
         * @param array<string, int> $accounts
         */
        public function __construct(
            private array $accounts,
            private ?LoginServiceCallLog $log = null
        )
        {
        }

        public function bindingFor(string $email): ?ActionTokenBinding
        {
            $this->asked[] = strtolower(trim($email));
            $this->log?->add('resolve');
            $id = $this->accounts[strtolower(trim($email))] ?? null;

            return $id === null
                ? null
                : new ActionTokenBinding(ActionTokenPurpose::LOGIN, 'user', $id, $email);
        }
    }

final class RecordingLoginDelivery implements ActionTokenLoginDeliveryInterface
{
    public ?ActionTokenBinding $binding = null;
    public ?IssuedActionToken $issuedToken = null;

    public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void
    {
        $this->binding = $binding;
        $this->issuedToken = $issuedToken;
    }
}

final class RecordingLoginRateLimitStore implements ActionTokenRateLimitStoreInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $consumedKeys = [];

    public function __construct(
        private bool $allowed = true,
        private ?LoginServiceCallLog $log = null
    )
    {
    }

    public function consume(string $scopeHash, DateTimeImmutable $windowStart, int $limit): bool
    {
        $this->calls++;
        $this->consumedKeys[] = $scopeHash;
        $this->log?->add('limit');

        return $this->allowed;
    }
}

/** Records the order the collaborators were asked in. */
final class LoginServiceCallLog
{
    /** @var list<string> */
    public array $calls = [];

    public function add(string $entry): void
    {
        $this->calls[] = $entry;
    }
}

final class LoginServiceTokenStore implements ActionTokenStoreInterface
{
    /** @var array<string, ActionTokenRecord> */
    private array $records = [];

    public function create(ActionTokenRecord $record): void
    {
        $this->records[$record->tokenHash] = $record;
    }

    public function findByHash(string $tokenHash): ?ActionTokenRecord
    {
        return $this->records[$tokenHash] ?? null;
    }

    public function consume(string $tokenHash, ActionTokenBinding $binding, DateTimeImmutable $now): bool
    {
        $record = $this->records[$tokenHash] ?? null;

        if (
            $record === null
            || ! $record->binding->equals($binding)
            || $record->usedAt !== null
            || $record->expiresAt <= $now
        ) {
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

final class LoginServiceTestClock implements ClockInterface
{
    private DateTimeImmutable $instant;

    public function __construct()
    {
        $this->instant = new DateTimeImmutable('2026-09-25 02:00:00', new DateTimeZone('Africa/Johannesburg'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}