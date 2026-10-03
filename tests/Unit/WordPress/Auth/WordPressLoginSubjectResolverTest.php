<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';

    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\WordPress\Auth\WordPressLoginSubjectResolver;
    use PHPUnit\Framework\TestCase;

    /**
     * The mint half of the magic link: which live accounts may be sent one.
     *
     * Every rule here is re-applied by LoginHandler at act time. The two are
     * deliberately redundant, and these tests pin both sides of that contract.
     */
    final class WordPressLoginSubjectResolverTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['adct_test_users'] = [];
            $GLOBALS['adct_test_caps'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['adct_test_users'], $GLOBALS['adct_test_caps']);
        }

        public function testADeanApproverResolvesToALoginBinding(): void
        {
            $binding = $this->givenDean(42, 'dean@example.test');

            self::assertNotNull($binding);
            self::assertSame(ActionTokenPurpose::LOGIN, $binding->purpose);
            self::assertSame('user', $binding->subjectType);
            self::assertSame(42, $binding->subjectId);
            self::assertSame('dean@example.test', $binding->email);
        }

        public function testAnArchdioceseReviewerResolvesToo(): void
        {
            $this->givenUser(43, 'reviewer@example.test');
            $GLOBALS['adct_test_caps'][43] = [Capabilities::REVIEW];

            $binding = (new WordPressLoginSubjectResolver())->bindingFor('reviewer@example.test');

            self::assertNotNull($binding);
            self::assertSame(43, $binding->subjectId);
        }

        public function testAParishContactWithoutAnApprovalCapabilityResolvesToNothing(): void
        {
            $this->givenUser(44, 'contact@example.test');

            self::assertNull((new WordPressLoginSubjectResolver())->bindingFor('contact@example.test'));
        }

        public function testADeactivatedAccountResolvesToNothing(): void
        {
            $this->givenUser(45, 'gone@example.test', 1);
            $GLOBALS['adct_test_caps'][45] = [Capabilities::APPROVE_DEANERY];

            self::assertNull((new WordPressLoginSubjectResolver())->bindingFor('gone@example.test'));
        }

        public function testAnUnknownAddressResolvesToNothing(): void
        {
            self::assertNull((new WordPressLoginSubjectResolver())->bindingFor('nobody@example.test'));
        }

        public function testAUserIdOfZeroResolvesToNothing(): void
        {
            $this->givenUser(0, 'zero@example.test');
            $GLOBALS['adct_test_caps'][0] = [Capabilities::APPROVE_DEANERY];

            self::assertNull((new WordPressLoginSubjectResolver())->bindingFor('zero@example.test'));
        }

        /**
         * WordPress returns the account whose address matches case-insensitively;
         * if the stored address were something else, the link would be minted for a
         * mailbox the approver does not read.
         */
        public function testAnAddressThatDoesNotMatchTheStoredOneResolvesToNothing(): void
        {
            $this->givenUser(46, 'other@example.test');
            $GLOBALS['adct_test_caps'][46] = [Capabilities::APPROVE_DEANERY];

            self::assertNull((new WordPressLoginSubjectResolver())->bindingFor('dean@example.test'));
        }

        public function testTheLookupIsLiveRatherThanCached(): void
        {
            $resolver = new WordPressLoginSubjectResolver();
            $this->givenDean(47, 'moved@example.test');
            self::assertNotNull($resolver->bindingFor('moved@example.test'));

            $GLOBALS['adct_test_caps'][47] = [];

            self::assertNull(
                $resolver->bindingFor('moved@example.test'),
                'Losing the capability takes effect on the very next lookup.'
            );
        }

        private function givenDean(int $id, string $email): ?ActionTokenBinding
        {
            $this->givenUser($id, $email);
            $GLOBALS['adct_test_caps'][$id] = [Capabilities::APPROVE_DEANERY];

            return (new WordPressLoginSubjectResolver())->bindingFor($email);
        }

        private function givenUser(int $id, string $email, int $status = 0): void
        {
            $GLOBALS['adct_test_users'][strtolower($email)] = new \WP_User($id, $email, $status);
        }
    }
}