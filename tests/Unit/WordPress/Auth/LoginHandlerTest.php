<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';

    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\WordPress\Auth\LoginHandler;
    use DomainException;
    use PHPUnit\Framework\TestCase;

    /**
     * ADR 0007 magic-link login for the front-end approval queue (#72).
     *
     * The rule these tests exist for: a login token names a recipient who may
     * stop being entitled to it — a dean moved off a deanery, or an account
     * deactivated. So the handler re-resolves the live account on the GET
     * preview and again on the POST, and refuses the link either time once the
     * account no longer qualifies. Nothing is cached between the two calls,
     * because a cached resolution is exactly the bug this guards against.
     */
    final class LoginHandlerTest extends TestCase
    {
        private const DEAN = 'dean@example.test';

        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['adct_test_cookies'] = [];
            $this->putUser(self::DEAN, 42);
            $this->grant(42, Capabilities::APPROVE_DEANERY);
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['adct_test_users'],
                $GLOBALS['adct_test_caps'],
                $GLOBALS['adct_test_cookies']
            );

            parent::tearDown();
        }

        public function testTheHandlerDeclaresTheLoginPurpose(): void
        {
            self::assertSame(ActionTokenPurpose::LOGIN, $this->handler()->purpose());
        }

        public function testAnActiveApproverSeesAnActionableLogInPage(): void
        {
            $preview = $this->handler()->preview($this->binding());

            self::assertNotNull($preview, 'An active deanery approver must be offered a log-in button.');
            self::assertTrue($preview->actionable);
            self::assertSame('Log in', $preview->submitLabel);
            self::assertStringContainsString('approval queue', $preview->title);
        }

        public function testAnArchdioceseReviewerCanAlsoUseTheLink(): void
        {
            $this->grant(42, Capabilities::REVIEW);

            self::assertNotNull($this->handler()->preview($this->binding()));
        }

        /**
         * A subscriber has no business in the approval queue, so the address is
         * not resolvable to an approver at all. This is the request-side twin of
         * the handler-side capability check below.
         */
        public function testAUserWithoutAnApprovalCapabilityGetsNoPage(): void
        {
            $GLOBALS['adct_test_caps'][42] = ['read'];

            self::assertNull($this->handler()->preview($this->binding()));
        }

        public function testADeactivatedAccountGetsNoPage(): void
        {
            $GLOBALS['adct_test_users'][self::DEAN]->user_status = 1;

            self::assertNull($this->handler()->preview($this->binding()));
        }

        public function testAnAddressWithNoAccountGetsNoPage(): void
        {
            $binding = new ActionTokenBinding(
                ActionTokenPurpose::LOGIN,
                'user',
                42,
                'nobody@example.test'
            );

            self::assertNull($this->handler()->preview($binding));
        }

        /**
         * The subject ID is part of the binding, so a token minted for one
         * account cannot be re-aimed at another by changing the address.
         */
        public function testATokenWhoseSubjectNoLongerMatchesTheAddressGetsNoPage(): void
        {
            $this->putUser(self::DEAN, 42);
            $this->putUser('other@example.test', 99);
            $this->grant(99, Capabilities::APPROVE_DEANERY);

            $binding = new ActionTokenBinding(ActionTokenPurpose::LOGIN, 'user', 99, self::DEAN);

            self::assertNull(
                $this->handler()->preview($binding),
                'The address resolves to user 42, so a token naming user 99 must not be honoured.'
            );
        }

        public function testPerformSignsTheUserInWithARememberedCookie(): void
        {
            $outcome = $this->handler()->perform($this->binding());

            self::assertStringContainsString('signed in', $outcome->message);
            self::assertSame(
                [['user_id' => 42, 'remember' => true]],
                $GLOBALS['adct_test_cookies'],
                'ADR 0007 logs the user in with a remembered cookie, so the session outlives the browser.'
            );
        }

        /**
         * The heart of the rule: the POST re-resolves. A dean who was previewed
         * as entitled, and whose capability was then stripped, must not be
         * signed in by the POST carrying their own previewed form.
         */
        public function testPerformRefusesWhenTheCapabilityWasStrippedAfterThePreview(): void
        {
            $handler = $this->handler();
            self::assertNotNull($handler->preview($this->binding()), 'The link must work before the account changes.');

            $GLOBALS['adct_test_caps'][42] = [];

            $this->expectException(DomainException::class);
            try {
                $handler->perform($this->binding());
            } finally {
                self::assertSame(
                    [],
                    $GLOBALS['adct_test_cookies'],
                    'A refused login must not set a session cookie.'
                );
            }
        }

        public function testPerformRefusesWhenTheAccountWasDeactivatedAfterThePreview(): void
        {
            $handler = $this->handler();
            self::assertNotNull($handler->preview($this->binding()));

            $GLOBALS['adct_test_users'][self::DEAN]->user_status = 1;

            $this->expectException(DomainException::class);
            $handler->perform($this->binding());
        }

        /**
         * The address is verified against the live account, so a link minted for
         * an address the user has since changed no longer resolves to them.
         */
        public function testPerformRefusesWhenTheAddressHasChangedOnTheAccount(): void
        {
            $binding = $this->binding();
            $GLOBALS['adct_test_users'][self::DEAN]->user_email = 'moved@example.test';

            self::assertNull($this->handler()->preview($binding));
            $this->expectException(DomainException::class);
            $this->handler()->perform($binding);
        }

        /**
         * The resolution is deliberately not memoised, so two calls against an
         * unchanged account behave identically rather than the second one
         * short-circuiting on the first one's answer.
         */
        public function testEachCallReResolvesRatherThanReusingTheFirstAnswer(): void
        {
            $handler = $this->handler();
            $binding = $this->binding();

            self::assertNotNull($handler->preview($binding));
            self::assertNotNull($handler->preview($binding));

            $GLOBALS['adct_test_caps'][42] = [];
            self::assertNull($handler->preview($binding), 'The third call must see the stripped capability.');
        }

        private function handler(): LoginHandler
        {
            return new LoginHandler(ActionTokenPurpose::LOGIN);
        }

        private function binding(): ActionTokenBinding
        {
            return new ActionTokenBinding(ActionTokenPurpose::LOGIN, 'user', 42, self::DEAN);
        }

        private function putUser(string $email, int $id): void
        {
            $GLOBALS['adct_test_users'][strtolower(trim($email))] = new \WP_User(
                $id,
                $email,
                0
            );
        }

        private function grant(int $userId, string $capability): void
        {
            $GLOBALS['adct_test_caps'][$userId][] = $capability;
        }
    }
}