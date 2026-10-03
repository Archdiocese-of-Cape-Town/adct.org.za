<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth {

    /**
     * Pulled in first so these tests' doubles are the ones that survive:
     * PHPUnit randomises the order files load in, and the shared auth doubles
     * (esc_url, esc_html, the escapers) must not lose to another file's.
     */
    require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';

    if (! function_exists(__NAMESPACE__ . '\\wp_verify_nonce')) {
        /**
         * The page only asks whether the nonce it holds matches the action it
         * built, so a deterministic stand-in drives both branches exactly.
         */
        function wp_verify_nonce(string $nonce, string $action): bool
        {
            return $nonce === ('nonce-for-' . $action);
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\wp_nonce_field')) {
        function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true): string
        {
            return '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '" />';
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\add_shortcode')) {
            function add_shortcode(string $tag, callable $callback): void
            {
                $GLOBALS['adct_test_shortcodes'][$tag] = $callback;
            }
        }

        if (! function_exists(__NAMESPACE__ . '\\wp_die')) {
        /**
         * Recorded rather than fatal, so a test can assert the status code and
         * message a visitor would have been shown.
         */
        function wp_die(string $message = '', string $title = '', array $args = []): void
        {
            $GLOBALS['adct_test_died'] = [
                'message' => $message,
                'title' => $title,
                'status' => (int) ($args['response'] ?? 500),
            ];

            throw new WpDieException();
        }
    }

    if (! class_exists(__NAMESPACE__ . '\\WpDieException', false)) {
        /**
         * WordPress's wp_die() ends the request; this stands in for that so the
         * test can carry on and inspect what was rendered.
         */
        final class WpDieException extends \RuntimeException
        {
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
    use ADCT\ParishIntake\Core\Auth\ActionTokenService;
    use ADCT\ParishIntake\Core\Auth\MagicLinkLoginService;
    use ADCT\ParishIntake\Core\Auth\MagicLinkLoginStatus;
    use ADCT\ParishIntake\WordPress\Auth\MagicLinkLoginRequestPage;
    use ADCT\ParishIntake\WordPress\Auth\WpDieException;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;

    /**
     * The public face of ADR 0007: asking for a sign-in link must give the same
     * answer whether or not the address belongs to an approver.
     */
    final class MagicLinkLoginRequestPageTest extends TestCase
    {
        protected function setUp(): void
        {
            $_POST = [];
            $_GET = [];
            $GLOBALS['adct_test_died'] = null;
            $GLOBALS['adct_test_shortcodes'] = [];
            $GLOBALS['adct_test_blocks'] = [];
        }

        protected function tearDown(): void
        {
            $_POST = [];
            $_GET = [];
                        unset($_SERVER['REMOTE_ADDR']);
                        unset(
                            $GLOBALS['adct_test_died'],
                            $GLOBALS['adct_test_shortcodes'],
                            $GLOBALS['adct_test_blocks']
                        );
        }

        /**
         * The headline property: a dean and a stranger get byte-identical pages.
         */
        public function testAKnownApproverAndAnUnknownAddressGetTheSameAnswer(): void
        {
            $known = $this->postedFor('dean@example.test', ['dean@example.test' => 42]);
            $unknown = $this->postedFor('stranger@example.test', []);

            self::assertSame($unknown['message'], $known['message']);
            self::assertSame($unknown['title'], $known['title']);
            self::assertSame($unknown['status'], $known['status']);
        }

        /**
         * The wording must not leak the answer either: a visitor who reads
         * "no account" learns what the plugin would otherwise hide.
                 *
                 * The byte-for-byte equality above is the load-bearing check — this one
                 * only guards against phrasing that would give the game away even when
                 * it happened to match. "dean" is deliberately *not* on the list: the
                 * page names the roles a link can be sent to for everyone, because a
                 * message that mentioned a role only for known addresses would be the
                 * leak.
                 */
                public function testTheAnswerNeverSaysWhetherTheAccountExists(): void
                {
                    foreach ([
                        $this->postedFor('dean@example.test', ['dean@example.test' => 42]),
                        $this->postedFor('stranger@example.test', []),
                    ] as $died) {
                        self::assertStringNotContainsStringIgnoringCase('not found', $died['message']);
                        self::assertStringNotContainsStringIgnoringCase('no account', $died['message']);
                        self::assertStringNotContainsStringIgnoringCase('unknown address', $died['message']);
                    }
                }

        /**
         * A GET must never act: it shows the form and nothing else. This is the
         * same rule the emailed action links follow.
         */
        public function testAGetNeverRequestsALink(): void
        {
            $_GET['adct_pi_email'] = 'dean@example.test';
            $resolver = new PageRecordingResolver(['dean@example.test' => 42]);

            $this->pageWith($resolver)->handleRequest();

            self::assertSame([], $resolver->asked, 'A GET must not mint a link.');
            self::assertNull($GLOBALS['adct_test_died']);
        }

        public function testAGetRendersTheFormWithANonceAndTheActionMarker(): void
        {
            $html = $this->pageWith(new PageRecordingResolver())->render();

            self::assertStringContainsString('method="post"', $html);
            self::assertStringContainsString('name="_wpnonce"', $html);
            self::assertStringContainsString('value="' . MagicLinkLoginRequestPage::ACTION . '"', $html);
            self::assertStringContainsString('name="adct_pi_email"', $html);
        }

        /**
         * The form posts back to the page it is on rather than to a guessed URL,
         * so an administrator can place the shortcode anywhere.
         */
        public function testTheFormPostsToThePageItIsOn(): void
        {
            self::assertStringContainsString('action=""', $this->pageWith(new PageRecordingResolver())->render());
        }

        public function testAPostWithoutTheActionMarkerIsIgnored(): void
        {
            $resolver = new PageRecordingResolver(['dean@example.test' => 42]);
            $_POST = $this->validPost('dean@example.test');
            $_POST['adct_pi_action'] = 'something_else';

            $this->pageWith($resolver)->handleRequest();

            self::assertSame([], $resolver->asked);
            self::assertNull($GLOBALS['adct_test_died']);
        }

        /**
         * Without a valid nonce nothing is sent and the visitor is told the
         * request expired, rather than the form being processed anyway.
         */
        public function testAPostWithABadNonceIsRefusedWithoutRequestingAnything(): void
                {
                    $resolver = new PageRecordingResolver(['dean@example.test' => 42]);

                    $died = $this->submitWith($resolver, ['_wpnonce' => 'wrong']);

                    self::assertSame(403, $died['status']);
                    self::assertSame([], $resolver->asked, 'No link may be requested without a valid nonce.');
                }

                public function testAPostWithNoNonceAtAllIsRefused(): void
                {
                    $resolver = new PageRecordingResolver(['dean@example.test' => 42]);

                    self::assertSame(403, $this->submitWith($resolver, ['_wpnonce' => null])['status']);
                    self::assertSame([], $resolver->asked);
                }

        /**
         * The one answer that does differ is the rate limit, and it has to be
         * visible: otherwise a dean who has genuinely hit the cap would keep
         * being told to check an inbox that will stay empty.
         */
        public function testTheRateLimitIsTheOnlyDistinguishableOutcome(): void
        {
            $resolver = new PageRecordingResolver(['dean@example.test' => 42]);
            $_POST = $this->validPost('dean@example.test');

            $died = $this->submit($resolver, allowRateLimit: false);

            self::assertSame(429, $died['status']);
            self::assertSame([], $resolver->asked, 'A rate-limited request never looks the address up.');
        }

        /**
         * A malformed address is answered exactly like a well-formed one, so the
         * form cannot be used to probe which addresses are real by shape.
         */
        public function testAMalformedAddressIsAnsweredLikeAnyOtherAddressAndNeverLookedUp(): void
        {
            $resolver = new PageRecordingResolver();

            $died = $this->submit($resolver, 'not-an-address');

            self::assertSame(200, $died['status']);
            self::assertSame([], $resolver->asked);
        }

        public function testAnEmptyAddressIsAnsweredLikeAnyOtherAddressAndNeverLookedUp(): void
        {
            $resolver = new PageRecordingResolver();

            $died = $this->submit($resolver, '');

            self::assertSame(200, $died['status']);
            self::assertSame([], $resolver->asked);
        }

        /**
         * The remote address is forwarded for the limiter, so a dean behind a
                 * shared parish NAT is not cut off by someone else's requests, and so
                 * the IP counter is not silently bypassed.
                 */
                public function testTheRemoteAddressIsForwardedToTheLimiter(): void
                {
                    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
                    $resolver = new PageRecordingResolver(['dean@example.test' => 42]);

                    $this->submit($resolver, 'dean@example.test');

                    // Two consumes per request: the address scope and the IP scope.
                    self::assertSame(['203.0.113.9', '203.0.113.9'], $resolver->remoteAddresses);
                }

                /**
                 * A request with no REMOTE_ADDR at all still reaches the limiter, with an
                 * empty scope, rather than skipping it.
                 */
                public function testAMissingRemoteAddressStillGoesThroughTheLimiter(): void
                {
                    unset($_SERVER['REMOTE_ADDR']);
                    $resolver = new PageRecordingResolver(['dean@example.test' => 42]);

                    $this->submit($resolver, 'dean@example.test');

                    self::assertSame(['', ''], $resolver->remoteAddresses);
                }

        /**
         * An administrator places the form with a shortcode or a block; both are
         * registered so the queue page is not something only this plugin's own
         * code can render.
         */
        public function testBothTheShortcodeAndTheBlockAreRegistered(): void
        {
            $this->pageWith(new PageRecordingResolver())->register();

            self::assertArrayHasKey(MagicLinkLoginRequestPage::SHORTCODE, $GLOBALS['adct_test_shortcodes']);
            self::assertArrayHasKey(MagicLinkLoginRequestPage::BLOCK, $GLOBALS['adct_test_blocks']);
        }

        public function testTheShortcodeRendersTheSameFormAsTheBlock(): void
        {
            $page = $this->pageWith(new PageRecordingResolver());
            $page->register();

            $fromShortcode = ($GLOBALS['adct_test_shortcodes'][MagicLinkLoginRequestPage::SHORTCODE])();
            $fromBlock = ($GLOBALS['adct_test_blocks'][MagicLinkLoginRequestPage::BLOCK]['render_callback'])();

            self::assertSame($fromShortcode, $fromBlock);
        }

        /**
         * The expired and already-used notices explain what to do next, because
         * the dean arrived from a dead link with no other route in.
         */
        public function testAnExpiredLinkExplainsHowToGetANewOne(): void
        {
            $_GET['adct_pi_login_error'] = 'expired';

            $html = $this->pageWith(new PageRecordingResolver())->render();

            self::assertStringContainsString('has expired', $html);
            self::assertStringContainsString('request a new one', $html);
        }

        public function testAnAlreadyUsedLinkExplainsHowToGetANewOne(): void
        {
            $_GET['adct_pi_login_error'] = 'used';

            $html = $this->pageWith(new PageRecordingResolver())->render();

            self::assertStringContainsString('already been used', $html);
        }

        /**
         * An unknown error code is ignored rather than echoed, so a crafted query
         * string cannot put arbitrary text into the notice.
         */
        public function testAnUnknownErrorCodeIsIgnored(): void
        {
            $_GET['adct_pi_login_error'] = '"><script>alert(1)</script>';

            $html = $this->pageWith(new PageRecordingResolver())->render();

            self::assertStringNotContainsString('<script>', $html);
            self::assertStringNotContainsString('adct-notice-error', $html);
        }

        /**
         * @param array<string, int> $accounts lowercase address => user ID
         *
         * @return array{message: string, title: string, status: int}
         */
        private function postedFor(string $email, array $accounts): array
        {
            $resolver = new PageRecordingResolver($accounts);
            $_POST = $this->validPost($email);

            return $this->submit($resolver, $email);
        }

        /**
                 * Post the form the way a visitor's browser would, then hand back what
                 * wp_die() was asked to render.
                 *
                 * @param array<string, string|null> $override merged over the valid POST;
                 *                                               a null value removes the field
                 *
                 * @return array{message: string, title: string, status: int}
                 */
                private function submitWith(
                    PageRecordingResolver $resolver,
                    array $override,
                    bool $allowRateLimit = true
                ): array {
                    $page = new MagicLinkLoginRequestPage($this->service($resolver, $allowRateLimit));
                    $_POST = $this->validPost('dean@example.test');
                    foreach ($override as $key => $value) {
                        if ($value === null) {
                            unset($_POST[$key]);
                            continue;
                        }
                        $_POST[$key] = $value;
                    }

                    return $this->submitRequest($page);
                }

                /**
                 * @return array{message: string, title: string, status: int}
                 */
                private function submit(
                    PageRecordingResolver $resolver,
                    string $email = 'dean@example.test',
                    bool $allowRateLimit = true
                ): array {
                    $page = new MagicLinkLoginRequestPage($this->service($resolver, $allowRateLimit));
                    $_POST = $this->validPost($email);

                    return $this->submitRequest($page);
                }

                /**
                 * Hand the POST to the page and report what wp_die() was asked to render.
                 *
                 * @return array{message: string, title: string, status: int}
                 */
                private function submitRequest(MagicLinkLoginRequestPage $page): array
                {
                    try {
                        $page->handleRequest();
                    } catch (WpDieException) {
                        // expected: wp_die() ends the request
                    }

                    $died = $GLOBALS['adct_test_died'];
                    self::assertIsArray($died, 'The page must always end with a wp_die() response.');

                    return $died;
                }

        private function pageWith(PageRecordingResolver $resolver): MagicLinkLoginRequestPage
        {
            return new MagicLinkLoginRequestPage($this->service($resolver, true));
        }

        private function service(PageRecordingResolver $resolver, bool $allowRateLimit): MagicLinkLoginService
        {
            $clock = new PageTestClock();
            $resolver->remoteAddresses = [];
            $store = new PageRateLimitStore($allowRateLimit, $resolver->remoteAddresses);

            return new MagicLinkLoginService(
                new ActionTokenService(new PageTokenStore(), $clock),
                new ActionTokenRateLimiter($store, $clock, str_repeat('k', 32)),
                $resolver,
                new PageNullDelivery()
            );
        }

        /**
         * @return array<string, string>
         */
        private function validPost(string $email): array
        {
            return [
                'adct_pi_action' => MagicLinkLoginRequestPage::ACTION,
                'adct_pi_email' => $email,
                '_wpnonce' => 'nonce-for-' . MagicLinkLoginRequestPage::NONCE,
            ];
        }
    }

    /**
     * Records which addresses were actually looked up, so a test can prove the
     * page refused before the resolver was ever consulted.
     */
    final class PageRecordingResolver implements \ADCT\ParishIntake\Core\Ports\ActionTokenLoginSubjectResolverInterface
    {
        /** @var list<string> */
        public array $asked = [];

        /**
         * The remote address each request arrived with, collected by the
         * limiter store so a test can see the forwarding end to end.
         *
         * @var list<string>
         */
        public array $remoteAddresses = [];

        /** @param array<string, int> $accounts */
        public function __construct(private array $accounts = [])
        {
        }

        public function bindingFor(string $email): ?\ADCT\ParishIntake\Core\Auth\ActionTokenBinding
        {
            $this->asked[] = strtolower(trim($email));
            $id = $this->accounts[strtolower(trim($email))] ?? null;

            return $id === null
                ? null
                : new \ADCT\ParishIntake\Core\Auth\ActionTokenBinding(
                    \ADCT\ParishIntake\Core\Auth\ActionTokenPurpose::LOGIN,
                    'user',
                    $id,
                    $email
                );
        }
    }

    final class PageNullDelivery implements
        \ADCT\ParishIntake\Core\Ports\ActionTokenLoginDeliveryInterface
    {
        public function deliver(
            \ADCT\ParishIntake\Core\Auth\ActionTokenBinding $binding,
            \ADCT\ParishIntake\Core\Auth\IssuedActionToken $issuedToken
        ): void {
            // The page tests care about the response, not the mail body.
        }
    }

    /**
             * The limiter consumes twice per request — once for the address, once for
             * the IP — so this records the REMOTE_ADDR both times and the test can
             * see that the page forwarded whatever the server gave it.
             *
             * @param list<string> $seen filled with the remote address of each consume() call
             */
            final class PageRateLimitStore implements \ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface
        {
            public function __construct(
                private bool $allowed,
                private array &$seen
            ) {
            }

            public function consume(string $scopeHash, \DateTimeImmutable $windowStart, int $limit): bool
            {
                $this->seen[] = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

                return $this->allowed;
            }
        }

    final class PageTestClock implements \ADCT\ParishIntake\Core\Ports\ClockInterface
    {
        public function now(): \DateTimeImmutable
        {
            return new \DateTimeImmutable('2026-10-12 09:00:00', new DateTimeZone('Africa/Johannesburg'));
        }
    }

    final class PageTokenStore implements \ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface
    {
        /** @var array<string, \ADCT\ParishIntake\Core\Auth\ActionTokenRecord> */
        private array $records = [];

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
            \ADCT\ParishIntake\Core\Auth\ActionTokenBinding $binding,
            \DateTimeImmutable $now
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

}