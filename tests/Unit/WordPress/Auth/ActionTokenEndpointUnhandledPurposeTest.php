<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth {

    require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';

        // __(), esc_html(), esc_attr() and esc_url() come from the shared doubles;
        // only what is specific to the endpoint is declared here.
        /**
         * A deterministic stand-in: the endpoint only asks whether the nonce it
         * holds matches the action it built, so a test can build the same one.
         */
    function wp_create_nonce(string $action): string
    {
        return 'nonce-for-' . $action;
    }

    function wp_verify_nonce(string $nonce, string $action): string|false
    {
        return $nonce === 'nonce-for-' . $action ? $nonce : false;
    }

    /**
     * Captured rather than written, so a test can assert on it and so the run
     * stays free of output. The other error_log() calls in the code paths
     * below are not reached, so anything recorded here came from the
     * unhandled-purpose branch.
     */
    function error_log(string $message): bool
    {
        $GLOBALS['adct_endpoint_error_log'][] = $message;

        return true;
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
        use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
        use ADCT\ParishIntake\Core\Auth\ActionTokenService;
        use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
        use ADCT\ParishIntake\Core\Auth\IssuedActionToken;
        use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
        use ADCT\ParishIntake\Core\Ports\ActionTokenRenewalDeliveryInterface;
    use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
    use ADCT\ParishIntake\WordPress\Auth\ActionTokenHttpResponse;
    use DateTimeImmutable;
    use DateTimeZone;
        use PHPUnit\Framework\Attributes\DataProvider;
        use PHPUnit\Framework\TestCase;

    /**
     * The endpoint must refuse a purpose it has no handler for, rather than
          * guessing. As of #72 every case on the enum is handled in Plugin, so this
          * registry is deliberately empty: reaching the branch means a registration
          * was dropped, which is a drift alarm, not a normal state. Either way a
          * silent no-op reads like a broken link, so the response says so and names
          * the purpose.
     */
    final class ActionTokenEndpointUnhandledPurposeTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['adct_endpoint_error_log'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['adct_endpoint_error_log']);

            parent::tearDown();
        }

        public function testOpeningALinkForAnUnhandledPurposeLogsThePurposeAndActionsNothing(): void
        {
            $purpose = ActionTokenPurpose::LOGIN;
            $response = $this->getResponseFor($purpose);

            self::assertSame(200, $response->statusCode);
            self::assertStringContainsString('This link is not available', $response->body);
            self::assertStringNotContainsString('<form', $response->body, 'An unusable purpose must offer no action.');

            self::assertCount(1, $GLOBALS['adct_endpoint_error_log'], 'The missing handler was not logged once.');
            $logged = $GLOBALS['adct_endpoint_error_log'][0];
            self::assertStringContainsString(
                '"' . $purpose->value . '"',
                $logged,
                'The log line must name the purpose, or it is impossible to tell which one is unimplemented.'
            );
            self::assertStringContainsString('no registered handler', $logged);
        }

        /**
         * Every purpose reaches the branch the same way, and each one has to name
                  * itself rather than log a generic line. Covering all the cases, not only
                  * the ones that were once reserved, keeps a purpose added later from
                  * logging something unreadable if its registration is ever dropped.
                  */
        #[DataProvider('allPurposes')]
        public function testEveryPurposeIsNamedInItsOwnLogLine(ActionTokenPurpose $purpose): void
        {
            $response = $this->getResponseFor($purpose);

            self::assertSame(200, $response->statusCode);
            self::assertStringContainsString(
                '"' . $purpose->value . '"',
                $GLOBALS['adct_endpoint_error_log'][0] ?? ''
            );
        }

        /**
         * @return array<string, array{0: ActionTokenPurpose}>
         */
        public static function allPurposes(): array
        {
            $purposes = [];
                    foreach (ActionTokenPurpose::cases() as $purpose) {
                $purposes[$purpose->value] = [$purpose];
            }

            return $purposes;
        }

        /**
         * The POST path reaches the same branch, so a form rendered before a
         * handler was removed, or a replayed request, is not a silent dead end
         * either. The token is left usable: an unusable purpose must not burn
         * the token of an event that is still decidable.
         */
        public function testSubmittingAnUnhandledPurposeLogsAndLeavesTheTokenUsable(): void
        {
            $tokens = $this->service();
            $token = $this->issue($tokens, ActionTokenPurpose::REVERT_CHANGE);

            $response = $this->post($tokens, $token, $this->nonceFor($token, 'perform'));

            self::assertSame(200, $response->statusCode);
            self::assertStringContainsString('This link is not available', $response->body);
            self::assertCount(1, $GLOBALS['adct_endpoint_error_log']);
            self::assertStringContainsString(
                '"' . ActionTokenPurpose::REVERT_CHANGE->value . '"',
                $GLOBALS['adct_endpoint_error_log'][0]
            );
            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A purpose with no handler must not consume the token.'
            );
        }

        /**
         * A POST without a valid nonce is refused before the purpose is looked
         * at, so it must not log about a purpose it never reached.
         */
        public function testAPostWithoutAValidNonceIsRefusedWithoutLoggingAboutThePurpose(): void
        {
            $tokens = $this->service();
            $token = $this->issue($tokens, ActionTokenPurpose::REVERT_CHANGE);

            $response = $this->post($tokens, $token, 'wrong-nonce');

            self::assertSame(403, $response->statusCode);
            self::assertStringNotContainsString('This link is not available', $response->body);
            self::assertSame([], $GLOBALS['adct_endpoint_error_log']);
        }

        private function getResponseFor(ActionTokenPurpose $purpose): ActionTokenHttpResponse
        {
            $tokens = $this->service();

            return (new ActionTokenEndpoint(
                $tokens,
                new ActionTokenHandlerRegistry(),
                $this->renewals($tokens)
            ))->respond('GET', $this->issue($tokens, $purpose), '', '', '', '203.0.113.5');
        }

        private function post(ActionTokenService $tokens, string $token, string $nonce): ActionTokenHttpResponse
        {
            return (new ActionTokenEndpoint(
                $tokens,
                new ActionTokenHandlerRegistry(),
                $this->renewals($tokens)
            ))->respond('POST', '', $token, 'perform', $nonce, '203.0.113.5');
        }

        private function service(): ActionTokenService
        {
            return new ActionTokenService(
                new UnhandledPurposeTokenStore(),
                new UnhandledPurposeClock()
            );
        }

        private function issue(ActionTokenService $tokens, ActionTokenPurpose $purpose): string
        {
            return $tokens->issue(new ActionTokenBinding(
                $purpose,
                'user',
                7,
                'person@example.test'
            ))->token();
        }

        private function nonceFor(string $token, string $action): string
        {
            return 'nonce-for-' . 'adct_pi_action_token_' . $action . '_' . hash('sha256', $token);
        }

        private function renewals(ActionTokenService $tokens): ActionTokenRenewalService
        {
            return new ActionTokenRenewalService(
                $tokens,
                new ActionTokenRateLimiter(
                    new AlwaysAllowRateLimitStore(),
                    new UnhandledPurposeClock(),
                    str_repeat('k', 32)
                ),
                new UnusedRenewalDelivery()
            );
        }
    }

    final class UnhandledPurposeTokenStore implements ActionTokenStoreInterface
    {
        /**
         * @var array<string, ActionTokenRecord>
         */
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

            if ($record === null || ! $record->binding->equals($binding)) {
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

    final class AlwaysAllowRateLimitStore implements ActionTokenRateLimitStoreInterface
    {
        public function consume(string $scopeHash, DateTimeImmutable $windowStart, int $limit): bool
        {
            return true;
        }
    }

    final class UnusedRenewalDelivery implements ActionTokenRenewalDeliveryInterface
    {
        public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void
        {
        }
    }

    final class UnhandledPurposeClock implements ClockInterface
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
    }
}