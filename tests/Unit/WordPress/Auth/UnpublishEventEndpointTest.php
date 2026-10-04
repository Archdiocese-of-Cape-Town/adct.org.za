<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth {

    require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';
    require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';

    // Guarded, because RevertChangeEndpointTest declares the same namespaced
    // functions and a second declaration of an existing namespaced function is a
    // fatal, not a shadow.
    if (! function_exists('ADCT\ParishIntake\WordPress\Auth\wp_create_nonce')) {
        /**
         * Deterministic, because the endpoint only asks whether the nonce it holds
         * matches the action it built -- so a test can build the same one.
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

namespace {

    // The same global doubles UnpublishEventHandlerTest drives, for the same
    // reason: one stand-in for the whole suite, because a second declaration of
    // get_post() or wp_insert_post() in the global namespace is a fatal.
    require_once __DIR__ . '/UnpublishEventHandlerTest.php';
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
    use ADCT\ParishIntake\Core\Auth\ActionTokenService;
    use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Auth\IssuedActionToken;
    use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
    use ADCT\ParishIntake\Core\Ports\ActionTokenRenewalDeliveryInterface;
    use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
    use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
    use ADCT\ParishIntake\WordPress\Auth\ActionTokenHttpResponse;
    use ADCT\ParishIntake\WordPress\Auth\UnpublishEventHandler;
    use ADCT\ParishIntake\WordPress\Events\EventListingGeneration;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;
    use WP_Post;
    use WP_User;

    /**
     * #71's unpublish link, driven the way a dean drives it: the POST the emailed
     * form actually submits, through the endpoint, with the real handler in a real
     * registry.
     *
     * UnpublishEventHandlerTest exercises the handler directly, which leaves the
     * seam the parish actually touches unpinned -- the endpoint's own checks, the
     * mapping of the handler's refusals onto status codes, and above all what an
     * approver is shown.
     *
     * The failure mode this file exists to catch is specific to unpublish. The
     * preview page is the last thing an approver sees before an action that
     * removes a public event and its calendar feed with no undo in the plugin.
     * If the endpoint renders that page without saying the event will stop
     * appearing in subscribers' calendars, the handler is correct and the
     * product is still dangerous. So the positive-path test pins the wording, not
     * just the status code.
     *
     * RevertChangeEndpointTest is the sibling of this file and the two are meant
     * to stay comparable: same harness, same fake event, same registry shape.
     */
    final class UnpublishEventEndpointTest extends TestCase
    {
        private const DEAN = 'dean@example.test';
        private const DEAN_USER_ID = 101;
        private const PARISH_ID = 11;
        private const EVENT_ID = 501;
        private const CHANGE_ID = 9001;
        private const NOW = '2026-09-25 08:00:00';

        /**
         * The service the endpoint is built with, so a token the test issues is
         * the same token the endpoint reads.
         */
        private ?ActionTokenService $issuedTokens = null;

        /**
         * UnpublishEventHandlerTest's own setUp() seeds these; its tearDown()
         * removes them, and under executionOrder="random" PHPUnit is free to run
         * this file's tests first. Repeating the seeding keeps the two files
         * order-independent.
         */
        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['revert_posts'] = [self::EVENT_ID => new WP_Post(self::EVENT_ID)];
            $post = $GLOBALS['revert_posts'][self::EVENT_ID];
            $post->post_title = 'Retreat day (renamed)';
            $post->post_content = 'The amended description.';
            $post->post_excerpt = 'Amended excerpt.';
            $post->post_status = 'publish';
            $post->post_type = 'adct_event';

            $GLOBALS['revert_meta'] = [self::EVENT_ID => $this->amendedMeta()];
            $GLOBALS['revert_terms'] = [self::EVENT_ID => [43]];
            $GLOBALS['revert_inserts'] = [];
            $GLOBALS['revert_unexpected_inserts'] = [];
            $GLOBALS['revert_cache_cleared'] = [];
            $GLOBALS['revert_options'] = [EventListingGeneration::OPTION => '0'];
            $GLOBALS['revert_option_writes'] = [];

            $GLOBALS['adct_test_wp_users'] = [
                self::DEAN_USER_ID => new WP_User(self::DEAN_USER_ID, self::DEAN),
            ];
            $GLOBALS['adct_test_wp_caps'] = [
                self::DEAN_USER_ID => [Capabilities::APPROVE_DEANERY],
            ];
            $GLOBALS['adct_test_wp_meta'] = [];
        }

        protected function tearDown(): void
        {
            foreach ([
                'revert_posts',
                'revert_meta',
                'revert_terms',
                'revert_inserts',
                'revert_unexpected_inserts',
                'revert_cache_cleared',
                'revert_options',
                'revert_option_writes',
                'adct_test_wp_users',
                'adct_test_wp_caps',
                'adct_test_wp_meta',
            ] as $key) {
                unset($GLOBALS[$key]);
            }

            \ADCT\ParishIntake\WordPress\Events\EventOccurrenceHooks::setPublishingCandidate(false);

            parent::tearDown();
        }

        /**
         * ADR 0008 requires a GET to show the page and only a POST to act. An
         * unpublish reachable by GET would be a one-click removal with no
         * confirmation step and no page in front of the recipient.
         */
        public function testAGetOnTheLinkShowsTheConfirmationPageAndActsOnNothing(): void
        {
            $tokens = $this->tokens();
            $token = $tokens->issue($this->binding())->token();

            $response = $this->endpoint($this->database())->respond('GET', $token, '', '', '', '203.0.113.5');

            self::assertSame(200, $response->statusCode);
            self::assertStringContainsString('Take this event off the events page', $response->body);
            self::assertStringContainsString('<form method="post"', $response->body);
            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A GET must leave the link usable.'
            );
            self::assertSame(
                'publish',
                $GLOBALS['revert_posts'][self::EVENT_ID]->post_status,
                'A GET must not take the event off the page.'
            );
        }

        /**
         * The page is the last warning an approver gets. Removing an event also
         * removes it from every subscriber's calendar feed, and nothing in the
         * plugin puts it back, so the wording has to say so rather than leaving
         * the approver to infer it from the word "remove".
         */
        public function testTheConfirmationPageWarnsThatSubscribersWillStopReceivingTheEvent(): void
        {
            $tokens = $this->tokens();
            $token = $tokens->issue($this->binding())->token();

            $response = $this->endpoint($this->database())->respond('GET', $token, '', '', '', '203.0.113.5');

            self::assertStringContainsString('calendar feed', $response->body);
            self::assertStringContainsString('not deleted', $response->body);
        }

        /**
         * The positive path, through the real POST.
         */
        public function testTheEmailedFormPostTakesTheEventOffThePage(): void
        {
            $tokens = $this->tokens();
            $token = $tokens->issue($this->binding())->token();
            $database = $this->database();

            $response = $this->post($database, $token);

            self::assertSame(200, $response->statusCode);
            self::assertSame(
                UnpublishEventHandler::UNPUBLISHED_STATUS,
                $GLOBALS['revert_posts'][self::EVENT_ID]->post_status,
                'The POST must leave the published set.'
            );
            self::assertStringContainsString(
                'event_unpublished',
                implode("\n", $database->statements),
                'An unpublish must be audited.'
            );
            self::assertSame(ActionTokenStatus::USED, $tokens->inspect($token)->status);
        }

        /**
         * The claim #71 needs proven end to end. A later change lands on the event;
         * the emailed link is then followed; the endpoint must refuse and must
         * leave both the event and the link alone.
         */
        public function testAPostOnALinkWhoseChangeHasBeenSupersededIsRefusedAndChangesNothing(): void
        {
            $database = $this->database();
            $database->seedSupersedingChange(self::EVENT_ID);

            $tokens = $this->tokens();
            $token = $tokens->issue($this->binding())->token();

            $response = $this->post($database, $token);

            self::assertSame(409, $response->statusCode);
            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'The refusal must not burn the link: the approver is still entitled to act'
                    . ' once the newer change has been dealt with.'
            );
            self::assertSame([], $database->insertedChanges);
            self::assertSame(
                'publish',
                $GLOBALS['revert_posts'][self::EVENT_ID]->post_status,
                'A superseded change must not be unpublished.'
            );
        }

        /**
         * The refusal deliberately does not carry the handler's own wording: that
         * message names a change row id and is written for somebody reading the
         * event's history, not safe in a page served to whoever followed a link.
         */
        public function testTheRefusalRendersAPageRatherThanAnEmptyResponse(): void
        {
            $database = $this->database();
            $database->seedSupersedingChange(self::EVENT_ID);

            $tokens = $this->tokens();
            $token = $tokens->issue($this->binding())->token();

            $response = $this->post($database, $token);

            self::assertSame(409, $response->statusCode);
            self::assertStringContainsString('<h1>', $response->body);
            self::assertStringContainsString('cannot be changed using this link', $response->body);
            self::assertStringNotContainsString(
                'change 9002',
                $response->body,
                'A change row id is internal and must not be echoed into a page served to'
                    . ' whoever followed the link.'
            );
        }

        /**
         * A recipient who has lost the parish is refused by the endpoint itself,
         * before the handler is ever asked: a VALID token whose preview is null
         * cannot be acted on. That ordering means the trail is never read for
         * somebody who is no longer an approver, so the refusal cannot leak the
         * state of the event to a former dean.
         */
        public function testARecipientWhoHasLostTheParishIsRefusedBeforeAnythingIsRead(): void
        {
            $database = $this->database();
            $database->seedSupersedingChange(self::EVENT_ID);

            $tokens = $this->tokens();
            $token = $tokens->issue($this->binding())->token();

            // The dean covers no parish at all now.
            $response = $this->post($database, $token, []);

            self::assertSame(200, $response->statusCode);
            self::assertStringContainsString('This link is not valid', $response->body);
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
            self::assertSame([], $database->statements);
            self::assertSame([], $database->insertedChanges);
            self::assertSame('publish', $GLOBALS['revert_posts'][self::EVENT_ID]->post_status);
        }

        /**
         * A nonce is checked before the purpose is looked at, so a cross-site POST
         * cannot reach the unpublish at all. Without this, the supersession guard
         * would be the only thing between a stranger and a published event.
         */
        public function testAPostWithoutTheNonceIsRefusedBeforeAnythingIsRead(): void
        {
            $database = $this->database();
            $tokens = $this->tokens();
            $token = $tokens->issue($this->binding())->token();

            $response = $this->endpoint($database)->respond(
                'POST',
                '',
                $token,
                'perform',
                'not-the-nonce',
                '203.0.113.5'
            );

            self::assertSame(403, $response->statusCode);
            self::assertSame(ActionTokenStatus::VALID, $tokens->inspect($token)->status);
            self::assertSame([], $database->statements);
        }

        /**
         * Replaying a spent link is a no-op, not a second removal.
         */
        public function testReplayingASpentLinkDoesNotUnpublishTwice(): void
        {
            $database = $this->database();
            $tokens = $this->tokens();
            $token = $tokens->issue($this->binding())->token();

            self::assertSame(200, $this->post($database, $token)->statusCode);

            $again = $this->post($database, $token);

            self::assertSame(200, $again->statusCode);
            self::assertStringContainsString('already been taken off', $again->body);
            self::assertCount(
                1,
                $database->insertedChanges,
                'A replay must not append a second removal row.'
            );
        }

        private function post(
            RevertDatabase $database,
            string $token,
            ?array $heldParishIds = null
        ): ActionTokenHttpResponse {
            return $this->endpoint($database, $heldParishIds)->respond(
                'POST',
                '',
                $token,
                'perform',
                'nonce-for-' . $this->nonceAction('perform', $token),
                '203.0.113.5'
            );
        }

        private function nonceAction(string $action, string $token): string
        {
            return 'adct_pi_action_token_' . $action . '_' . hash('sha256', $token);
        }

        /**
         * The registry the plugin builds, not an empty one: the empty registry in
         * ActionTokenEndpointUnhandledPurposeTest is a drift alarm, and a test that
         * reached an unpublish through it would prove nothing.
         */
        private function endpoint(RevertDatabase $database, ?array $heldParishIds = null): ActionTokenEndpoint
        {
            $handler = new UnpublishEventHandler(
                $database,
                new ApprovalRecipients(new ApprovalRouteResolver(
                    new RevertRouteRepository($heldParishIds ?? [self::PARISH_ID])
                )),
                new RecordingMailer(),
                new RevertClock(),
                new RecordingOccurrenceMaintenance(),
                new EventListingGeneration(),
                new DateTimeZone('Africa/Johannesburg')
            );

            $registry = new ActionTokenHandlerRegistry();
            $registry->register($handler);

            $tokens = $this->tokens();

            return new ActionTokenEndpoint(
                $tokens,
                $registry,
                new ActionTokenRenewalService(
                    $tokens,
                    new ActionTokenRateLimiter(
                        new AlwaysAllowRateLimitStoreForUnpublish(),
                        new RevertClock(),
                        str_repeat('k', 32)
                    ),
                    new UnusedRenewalDeliveryForUnpublish()
                )
            );
        }

        /**
         * One service, one store, for the whole test. A fresh ActionTokenService
         * per call would give the endpoint a store that has never seen the token
         * the test just issued, and every link would read as unknown.
         */
        private function tokens(): ActionTokenService
        {
            if ($this->issuedTokens === null) {
                $this->issuedTokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            }

            return $this->issuedTokens;
        }

        private function binding(): ActionTokenBinding
        {
            return new ActionTokenBinding(
                ActionTokenPurpose::UNPUBLISH_EVENT,
                UnpublishEventHandler::SUBJECT_TYPE,
                self::CHANGE_ID,
                self::DEAN
            );
        }

        /**
         * @return array<string, mixed>
         */
        private function amendedMeta(): array
        {
            return [
                '_thumbnail_id' => 0,
                'parish_id' => self::PARISH_ID,
                'venue_id' => 8,
                'start_local' => '2026-10-12 09:00:00',
                'end_local' => '2026-10-12 12:00:00',
                'all_day' => '',
                'rrule' => '',
                'exdates' => '',
                'rdates' => '',
                'featured' => '',
                'status_flag' => '',
                'source_candidate_id' => 300,
                'contact' => 'contact@example.test',
            ];
        }

        private function database(): RevertDatabase
        {
            $before = [
                'title' => 'Parish retreat day',
                'content' => 'The original description.',
                'excerpt' => 'Original excerpt.',
                'status' => 'publish',
                'event_type_term_ids' => [42],
                'featured_image_id' => 0,
                'meta' => array_merge($this->amendedMeta(), [
                    'venue_id' => 7,
                    'status_flag' => '',
                    'source_candidate_id' => 299,
                ]),
            ];
            $after = [
                'title' => 'Retreat day (renamed)',
                'content' => 'The amended description.',
                'excerpt' => 'Amended excerpt.',
                'status' => 'publish',
                'event_type_term_ids' => [43],
                'featured_image_id' => 0,
                'meta' => $this->amendedMeta(),
            ];

            $database = new RevertDatabase();
            $database->seedChange(
                self::CHANGE_ID,
                self::EVENT_ID,
                300,
                self::DEAN,
                'update',
                $before,
                $after
            );

            return $database;
        }
    }

    final class AlwaysAllowRateLimitStoreForUnpublish implements ActionTokenRateLimitStoreInterface
    {
        public function consume(string $scopeHash, DateTimeImmutable $windowStart, int $limit): bool
        {
            return true;
        }
    }

    final class UnusedRenewalDeliveryForUnpublish implements ActionTokenRenewalDeliveryInterface
    {
        public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void
        {
        }
    }
}
