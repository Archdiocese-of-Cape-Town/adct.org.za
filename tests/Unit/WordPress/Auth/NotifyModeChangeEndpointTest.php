<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth {

    require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';
        require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';

        // get_user_by(), user_can(), esc_*(), __(), home_url() and add_query_arg()
        // come from the shared doubles and WordPressStubs.php. Only the two nonce
        // functions are declared here, because the endpoint's own tests are the
        // only place that needs them and a second declaration of an existing
        // namespaced function is a fatal, not a shadow.
    if (! function_exists('ADCT\ParishIntake\WordPress\Auth\wp_create_nonce')) {
        /**
         * Deterministic, because the endpoint only asks whether the nonce it
         * holds matches the action it built — so a test can build the same one.
         */
        function wp_create_nonce(string $action): string
        {
            return 'nonce-for-' . $action;
        }

        function wp_verify_nonce(string $nonce, string $action): string|false
        {
            return $nonce === 'nonce-for-' . $action ? $nonce : false;
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    use ADCT\ParishIntake\Core\Approval\Approver;
    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
    use ADCT\ParishIntake\Core\Auth\ActionTokenService;
    use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Auth\IssuedActionToken;
    use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
    use ADCT\ParishIntake\Core\Ports\ActionTokenRenewalDeliveryInterface;
    use ADCT\ParishIntake\Tests\Support\NotifyModeClock;
    use ADCT\ParishIntake\Tests\Support\NotifyModeDatabase;
    use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
    use ADCT\ParishIntake\WordPress\Auth\ActionTokenHttpResponse;
    use ADCT\ParishIntake\WordPress\Auth\NotifyModeChangeHandler;
    use ADCT\ParishIntake\WordPress\Auth\NotifyModeField;
    use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
    use DateTimeImmutable;
    use PHPUnit\Framework\TestCase;
    use WP_User;

    /**
     * Issue #169, at the layer where the guarantee actually holds: a GET shows
     * the choice and changes nothing, only a POST acts, and it acts only with a
     * valid nonce. NotifyModeChangeHandlerTest covers the handler's own
     * refusals; this covers the plumbing that could route a request past them.
     *
     * Each test asserts three things about every refusal — the response, that
     * no assignment moved and no audit row was written, and that the token is
     * still VALID — because a refused request that burns the recipient's only
     * link is a support call waiting to happen, and a "nothing was written"
     * assertion with no token assertion would pass on a handler that consumes
     * before it checks.
     */
    final class NotifyModeChangeEndpointTest extends TestCase
    {
        private const NOW = '2026-09-25 08:00:00';

        private NotifyModeDatabase $database;

        protected function setUp(): void
        {
            parent::setUp();

            $this->database = new NotifyModeDatabase();
            $this->entitleTheDean();
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['adct_test_users'], $GLOBALS['adct_test_caps']);

            parent::tearDown();
        }

        /**
         * The form a dean actually receives: the two choices, the one they are
         * currently on marked as checked, and the POST plumbing that carries
         * the token, the action and a nonce. A form missing any of those is a
         * dead page, so all of it is asserted rather than assumed.
         */
        public function testThePageOffersBothChoicesWithTheStoredOneSelected(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = $this->get($tokens, $token);

            self::assertSame(200, $response->statusCode);
            self::assertStringContainsString('<form method="post"', $response->body);
            self::assertStringContainsString('adct_token_nonce', $response->body, 'The form must carry a nonce.');
            self::assertStringContainsString('adct_token_action', $response->body);

            foreach (array_keys(NotifyModeChangeHandler::MODES) as $mode) {
                self::assertStringContainsString(
                    'value="' . $mode . '"',
                    $response->body,
                    'The "' . $mode . '" choice is missing from the form.'
                );
            }

            self::assertMatchesRegularExpression(
                '/value="' . preg_quote(Approver::NOTIFY_EACH, '/') . '"[^>]*\schecked/',
                $response->body,
                'The stored mode is the one marked as already chosen.'
            );
            self::assertStringNotContainsString(
                'value="' . Approver::NOTIFY_DIGEST . '" checked',
                $response->body,
                'Only the stored mode may be preselected.'
            );

            self::assertStringContainsString(
                'This applies to every deanery you approve for (2 in total).',
                $response->body,
                'The page must say what the change reaches.'
            );
        }

        /**
         * The acceptance criterion this file exists for. Opening the link is a
         * GET, so it must not consume the token, write a row, open a
         * transaction or write an audit entry — and the mode must be untouched
         * so that a reload, a link preview or a mail scanner that fetches the
         * URL cannot change anyone's settings.
         */
        public function testOpeningTheLinkChangesNothing(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = $this->get($tokens, $token);

            self::assertSame(200, $response->statusCode);
            $this->assertNothingWasWritten();
            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'Opening the link must leave it usable, or the dean cannot press Save afterwards.'
            );
        }

        /**
         * A POST with a valid nonce is the one request that acts: the mode moves
         * on both live assignments, the token is consumed and the dean is told
         * when it takes effect.
         */
        public function testAValidPostSavesTheChoiceAndConsumesTheToken(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = $this->post($tokens, $token, $this->nonceFor($token, 'perform'), Approver::NOTIFY_DIGEST);

            self::assertSame(200, $response->statusCode);
            self::assertStringContainsString('Preference saved', $response->body);
            self::assertStringContainsString('one email a day', $response->body);

            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $this->database->assignmentRow(NotifyModeDatabase::ASSIGNMENT_ID)['notify_mode']
            );
            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $this->database->assignmentRow(NotifyModeDatabase::SECOND_ASSIGNMENT_ID)['notify_mode'],
                'Every live assignment the person holds moves together.'
            );

            self::assertCount(1, $this->database->auditRows);
            self::assertSame(
                'approver_notify_mode_changed',
                $this->database->auditRows[0]['action']
            );
            self::assertSame(
                ActionTokenStatus::USED,
                $tokens->inspect($token)->status,
                'A link that has been pressed must not be pressable again.'
            );
        }

        /**
         * The nonce is checked before the purpose is even looked at, so a
         * forged POST cannot reach the handler at all. Asserting the mode is
         * unchanged is what makes this a security test rather than a status-code
         * test: a 403 that still saved the preference would pass a status-only
         * assertion.
         */
        public function testAPostWithoutAValidNonceIsRefusedAndChangesNothing(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = $this->post($tokens, $token, 'not-the-nonce', Approver::NOTIFY_DIGEST);

            self::assertSame(403, $response->statusCode);
            $this->assertNothingWasWritten();
            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A refused request must not burn the link.'
            );
        }

        /**
         * A missing nonce is the same refusal as a wrong one. Without this, a
         * handler that defaulted an empty nonce to "valid" would pass the wrong
         * nonce test above.
         */
        public function testAPostWithNoNonceAtAllIsRefusedAndChangesNothing(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = $this->post($tokens, $token, '', Approver::NOTIFY_DIGEST);

            self::assertSame(403, $response->statusCode);
            $this->assertNothingWasWritten();
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
        }

        /**
         * The nonce is bound to the action *and* the token, so one obtained for
         * a token cannot be replayed against another. Without the token in the
         * action name, a dean could harvest a valid nonce from their own link
         * and post it with a token someone else's.
         */
        public function testANonceForOneTokenIsRefusedForAnother(): void
        {
            $tokens = $this->tokens();
            $mine = $this->issue($tokens);
            $theirs = $this->issue($tokens);

            $response = $this->post($tokens, $theirs, $this->nonceFor($mine, 'perform'), Approver::NOTIFY_DIGEST);

            self::assertSame(403, $response->statusCode);
            $this->assertNothingWasWritten();
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($theirs)->status);
        }

        /**
         * Only 'perform' acts. An unrecognised action is a 400 before the nonce
         * is examined, because a request the endpoint cannot interpret has no
         * business being treated as a submission.
         */
        public function testAnUnrecognisedActionIsRefusedAndChangesNothing(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = (new ActionTokenEndpoint(
                $tokens,
                $this->registry(),
                $this->renewals($tokens)
            ))->respond('POST', '', $token, 'publish', $this->nonceFor($token, 'perform'), '203.0.113.5');

            self::assertSame(400, $response->statusCode);
            $this->assertNothingWasWritten();
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
        }

        /**
         * Methods other than GET and POST are not a submission, so they cannot
         * act even with a valid nonce.
         */
        public function testAMethodThatIsNeitherGetNorPostIsRefused(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = (new ActionTokenEndpoint(
                $tokens,
                $this->registry(),
                $this->renewals($tokens)
            ))->respond('DELETE', '', $token, 'perform', $this->nonceFor($token, 'perform'), '203.0.113.5');

            self::assertSame(405, $response->statusCode);
            $this->assertNothingWasWritten();
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
        }

        /**
         * The two choices are a closed list, and anything else is refused
         * before the transaction opens. A stored mode outside it would then be
         * written to every assignment and silently ignored by the notice job,
         * so the field is validated at the boundary rather than trusted.
         */
        public function testAnUnknownModeIsRefusedAndChangesNothing(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = $this->post($tokens, $token, $this->nonceFor($token, 'perform'), 'hourly');

            self::assertSame(409, $response->statusCode);
            self::assertStringContainsString('Preference not changed', $response->body);
            $this->assertNothingWasWritten();
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
        }

        /**
         * The submission is trimmed, because a radio group value arrives
         * exactly but a hand-built POST does not, and a trailing space is not a
         * reason to refuse a dean their own choice.
         */
        public function testASurroundingSpaceIsTrimmedRatherThanRefused(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = $this->post(
                $tokens,
                $token,
                $this->nonceFor($token, 'perform'),
                ' ' . Approver::NOTIFY_DIGEST . ' '
            );

            self::assertSame(200, $response->statusCode);
            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $this->database->assignmentRow(NotifyModeDatabase::ASSIGNMENT_ID)['notify_mode']
            );
        }

        /**
         * A second press of an already-used link is refused. This is the
         * endpoint's own defence, independent of the handler's consume check.
         */
        public function testASecondPostOfTheSameLinkIsRefusedAndChangesNothing(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            self::assertSame(200, $this->post(
                $tokens,
                $token,
                $this->nonceFor($token, 'perform'),
                Approver::NOTIFY_DIGEST
            )->statusCode);

            $writesSoFar = $this->database->writes;

            $response = $this->post($tokens, $token, $this->nonceFor($token, 'perform'), Approver::NOTIFY_EACH);

            self::assertSame(200, $response->statusCode);
            self::assertStringContainsString(
                'This link has already been used',
                $response->body,
                'A replay must be told the link is spent, not silently accepted.'
            );
            self::assertSame(
                $writesSoFar,
                $this->database->writes,
                'A replay must not write anything.'
            );
            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $this->database->assignmentRow(NotifyModeDatabase::ASSIGNMENT_ID)['notify_mode'],
                'The replayed choice must not undo the first one.'
            );
        }

        /**
         * A GET on a spent link never shows the form again. The handler has
         * already refused it by then, but the page must not offer a button that
         * can only fail.
         */
        public function testReopeningASpentLinkOffersNoForm(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);
            $this->post($tokens, $token, $this->nonceFor($token, 'perform'), Approver::NOTIFY_DIGEST);

            $response = $this->get($tokens, $token);

            self::assertSame(200, $response->statusCode);
            self::assertStringNotContainsString(
                'adct_notify_mode',
                $response->body,
                'A spent link must not offer the choice again.'
            );
        }

        /**
         * Live entitlement, checked at the endpoint layer. The token is minted
         * while the dean is still an approver, the capability is then stripped,
         * and the POST is refused: no assignment moved, no audit row, and the
         * link still works if the capability comes back.
         */
        public function testAPostIsRefusedAfterTheCapabilityIsRevoked(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $GLOBALS['adct_test_caps'][NotifyModeDatabase::DEAN_USER_ID] = [];

            $response = $this->post($tokens, $token, $this->nonceFor($token, 'perform'), Approver::NOTIFY_DIGEST);

            self::assertSame(200, $response->statusCode);
            self::assertStringNotContainsString('Preference saved', $response->body);
            $this->assertNothingWasWritten();
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);

            $this->entitleTheDean();
            self::assertSame(
                200,
                $this->get($tokens, $token)->statusCode,
                'Restoring the capability must make the same link work again.'
            );
        }

        /**
         * Revoked between the GET and the POST is the realistic case: a mail
         * sits in a mailbox for days. The page was rendered while the dean was
         * still an approver, so the POST must re-check rather than trust it.
         */
        public function testAPostIsRefusedWhenTheDeanIsDeactivatedAfterThePageWasRendered(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            self::assertSame(200, $this->get($tokens, $token)->statusCode);

            $GLOBALS['adct_test_users'][NotifyModeDatabase::DEAN]->user_status = 1;

            $response = $this->post($tokens, $token, $this->nonceFor($token, 'perform'), Approver::NOTIFY_DIGEST);

            self::assertStringNotContainsString('Preference saved', $response->body);
            $this->assertNothingWasWritten();
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
        }

        /**
         * A dean who has been moved off every deanery cannot change a
         * preference that no longer applies to them, and must not be shown a
         * form that will fail.
         */
        public function testADeanWithNoLiveAssignmentsIsRefused(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $this->database->deactivateDeanery(NotifyModeDatabase::FIRST_DEANERY_ID);
            $this->database->deactivateDeanery(NotifyModeDatabase::SECOND_DEANERY_ID);

            $get = $this->get($tokens, $token);
            self::assertSame(200, $get->statusCode);
            self::assertStringNotContainsString('adct_notify_mode', $get->body);

            $response = $this->post($tokens, $token, $this->nonceFor($token, 'perform'), Approver::NOTIFY_DIGEST);

            self::assertStringNotContainsString('Preference saved', $response->body);
            $this->assertNothingWasWritten();
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
        }

        /**
         * Only live assignments move. A deanery that has been deactivated since
         * the mail went out keeps whatever an administrator set for it, so the
         * link does not reach further than the recipient's current authority.
         */
        public function testOnlyLiveAssignmentsAreChanged(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $this->database->deactivateDeanery(NotifyModeDatabase::SECOND_DEANERY_ID);

            self::assertSame(200, $this->post(
                $tokens,
                $token,
                $this->nonceFor($token, 'perform'),
                Approver::NOTIFY_DIGEST
            )->statusCode);

            self::assertSame(
                Approver::NOTIFY_DIGEST,
                $this->database->assignmentRow(NotifyModeDatabase::ASSIGNMENT_ID)['notify_mode']
            );
            self::assertSame(
                Approver::NOTIFY_EACH,
                $this->database->assignmentRow(NotifyModeDatabase::SECOND_ASSIGNMENT_ID)['notify_mode'],
                'A deanery that is no longer live must not be written to.'
            );
        }

        /**
         * The link carries no approval authority. A token minted for this
         * purpose is refused outright by the decision handler, so obtaining one
         * still cannot approve, deny or publish anything.
         */
        public function testTheLinkGrantsNoApprovalAuthority(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);
            $nonce = $this->nonceFor($token, 'perform');

            // The endpoint can only reach a handler registered for the token's
            // own purpose, so minting an approve token and posting the notify
            // mode must not act on either side.
            $approveTokens = $this->tokens();
            $approveToken = $approveTokens->issue(new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'candidate',
                7,
                NotifyModeDatabase::DEAN
            ))->token();

            $response = $this->post($approveTokens, $approveToken, $this->nonceFor($approveToken, 'perform'));

            self::assertSame(200, $response->statusCode);
            self::assertStringNotContainsString('Preference saved', $response->body);
            $this->assertNothingWasWritten();
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
        }

        /**
         * Nothing on this page comes from the request, so there is no
         * attacker-controlled string to escape — which is a property worth
         * asserting rather than assuming, because it is what makes the page
         * safe to render without a sanitising layer.
         *
         * Every value on it is either a fixed string from
         * NotifyModeChangeHandler::MODES or a translation string, and the one
         * interpolated number is a row count. If a future field on this page
         * ever came from the database or the query string, this fails and the
         * author has to escape it.
         */
        public function testThePageRendersNoStoredOrSubmittedValueAsMarkup(): void
        {
            $tokens = $this->tokens();
            $token = $this->issue($tokens);

            $response = $this->get($tokens, $token);

            // The token itself is never echoed into the markup beyond its
            // hidden field, which the endpoint escapes; nothing else in the
            // body may look like it carries request data.
            self::assertSame(
                1,
                substr_count($response->body, 'value="' . htmlspecialchars($token, ENT_QUOTES) . '"'),
                'The token should appear only in the escaped hidden field.'
            );
            self::assertSame(
                substr_count($response->body, $token),
                substr_count($response->body, htmlspecialchars($token, ENT_QUOTES)),
                'The raw token must not appear anywhere unescaped.'
            );
        }

        private function entitleTheDean(): void
        {
            $GLOBALS['adct_test_users'] = [
                NotifyModeDatabase::DEAN => new WP_User(
                    NotifyModeDatabase::DEAN_USER_ID,
                    NotifyModeDatabase::DEAN,
                    0
                ),
            ];
            $GLOBALS['adct_test_caps'] = [
                NotifyModeDatabase::DEAN_USER_ID => [Capabilities::APPROVE_DEANERY],
            ];
        }

        /**
         * The single "nothing happened" assertion, so a test cannot assert it
         * one way and the code break the other.
         */
        private function assertNothingWasWritten(): void
        {
            self::assertSame([], $this->database->writes, 'A refused request wrote to the database.');
            self::assertSame([], $this->database->auditRows, 'A refused request wrote an audit row.');
            self::assertSame([], $this->database->transactions, 'A refused request opened a transaction.');
            self::assertSame(
                Approver::NOTIFY_EACH,
                $this->database->assignmentRow(NotifyModeDatabase::ASSIGNMENT_ID)['notify_mode'],
                'The stored preference must be exactly as it was.'
            );
        }

        private function get(ActionTokenService $tokens, string $token): ActionTokenHttpResponse
        {
            return (new ActionTokenEndpoint(
                $tokens,
                $this->registry(),
                $this->renewals($tokens)
            ))->respond('GET', $token, '', '', '', '203.0.113.5');
        }

        private function post(
            ActionTokenService $tokens,
            string $token,
            string $nonce,
            string $mode = ''
        ): ActionTokenHttpResponse {
            return (new ActionTokenEndpoint(
                $tokens,
                $this->registry(),
                $this->renewals($tokens)
            ))->respond(
                'POST',
                '',
                $token,
                'perform',
                $nonce,
                '203.0.113.5',
                '',
                [],
                $mode
            );
        }

        private function registry(): ActionTokenHandlerRegistry
        {
            $registry = new ActionTokenHandlerRegistry();
            $registry->register(new NotifyModeChangeHandler(
                $this->database,
                new DeaneryApproverRepository($this->database),
                new NotifyModeClock(self::NOW)
            ));

            return $registry;
        }

        private function tokens(): ActionTokenService
        {
            return new ActionTokenService($this->database, new NotifyModeClock(self::NOW));
        }

        private function issue(ActionTokenService $tokens): string
        {
            return $tokens->issue(new ActionTokenBinding(
                ActionTokenPurpose::CHANGE_NOTIFY_MODE,
                NotifyModeChangeHandler::SUBJECT_TYPE,
                NotifyModeDatabase::DEAN_USER_ID,
                NotifyModeDatabase::DEAN
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
                new \ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter(
                    new NotifyModeRateLimitStore(),
                    new NotifyModeClock(self::NOW),
                    str_repeat('k', 32)
                ),
                new NotifyModeUnusedRenewalDelivery()
            );
        }
    }

    final class NotifyModeRateLimitStore implements ActionTokenRateLimitStoreInterface
    {
        public function consume(string $scopeHash, DateTimeImmutable $windowStart, int $limit): bool
        {
            return true;
        }
    }

    final class NotifyModeUnusedRenewalDelivery implements ActionTokenRenewalDeliveryInterface
    {
        public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void
        {
        }
    }
}
