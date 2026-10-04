<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';

    if (! class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_title = '';
            public string $post_content = '';
            public string $post_excerpt = '';
            public string $post_status = 'publish';
            public string $post_type = 'adct_event';

            public function __construct(int $id = 0)
            {
                $this->ID = $id;
            }
        }
    }

    if (! class_exists('WP_Term', false)) {
        class WP_Term
        {
            public int $term_id = 0;
        }
    }

    if (! function_exists('get_post_thumbnail_id')) {
        function get_post_thumbnail_id(int $postId): int
        {
            return (int) ($GLOBALS['revert_meta'][(int) $postId]['_thumbnail_id'] ?? 0);
        }
    }

    if (! function_exists('set_post_thumbnail')) {
        function set_post_thumbnail(int $postId, int $thumbnailId): bool
        {
            $GLOBALS['revert_meta'][(int) $postId]['_thumbnail_id'] = $thumbnailId;

            return true;
        }
    }

    if (! function_exists('delete_post_thumbnail')) {
        function delete_post_thumbnail(int $postId): bool
        {
            unset($GLOBALS['revert_meta'][(int) $postId]['_thumbnail_id']);

            return true;
        }
    }

    if (! function_exists('wp_get_object_terms')) {
        function wp_get_object_terms(int $postId, string $taxonomy, array $args = []): mixed
        {
            return array_map('intval', (array) ($GLOBALS['revert_terms'][(int) $postId] ?? []));
        }
    }

    if (! function_exists('wp_set_object_terms')) {
        function wp_set_object_terms(int $postId, array $termIds, string $taxonomy, bool $append = false): mixed
        {
            $GLOBALS['revert_terms'][(int) $postId] = array_map('intval', $termIds);

            return $GLOBALS['revert_terms'][(int) $postId];
        }
    }

    if (! function_exists('get_permalink')) {
        function get_permalink(int $postId): string
        {
            return 'https://adct.org.za/events/event-' . $postId . '/';
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('esc_url')) {
        function esc_url(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    /**
     * EventListingGeneration is final and has no port, so the real class is
     * used and these two functions are its seam. update_option() reports
     * false exactly when the stored value is unchanged, which is what makes
     * "bumped exactly once" countable.
     */
    if (! function_exists('get_option')) {
        function get_option(string $option, mixed $default = false): mixed
        {
            return $GLOBALS['revert_options'][$option] ?? $default;
        }
    }

    if (! function_exists('update_option')) {
        function update_option(string $option, mixed $value, mixed $autoload = null): bool
        {
            if (array_key_exists($option, $GLOBALS['revert_options'] ?? [])
                && $GLOBALS['revert_options'][$option] === $value) {
                return false;
            }

            $GLOBALS['revert_options'][$option] = $value;
            $GLOBALS['revert_option_writes'][] = $option;

            return true;
        }
    }
}

/**
 * No Approval stubs live here. tests/Support/WordPressStubs.php already declares
 * get_userdata(), user_can(), get_users() and get_user_meta() in
 * ADCT\ParishIntake\WordPress\Approval, and a second declaration in the same
 * namespace is a fatal, not a shadow. This harness drives that copy through the
 * same globals it reads, so there is one WordPress stand-in for the whole suite
 * and no test order dependency: nothing here reads a global before setUp() sets
 * it, and nothing here writes one the reminder and notice jobs rely on.
 */

namespace ADCT\ParishIntake\WordPress\Auth {

    function get_post(mixed $post): mixed
    {
        return $GLOBALS['revert_posts'][(int) $post] ?? null;
    }

    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        return $GLOBALS['revert_meta'][(int) $postId][$key] ?? '';
    }

    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        $GLOBALS['revert_meta'][(int) $postId][$key] = $value;

        return true;
    }

    function delete_post_meta(int $postId, string $key, bool $deleteAll = false): bool
    {
        unset($GLOBALS['revert_meta'][(int) $postId][$key]);

        return true;
    }

    function clean_post_cache(int $postId): void
    {
        $GLOBALS['revert_cache_cleared'][] = $postId;
    }

    /**
     * A revert restores an event that already exists, so the handler has to
     * pass the ID back. Answering a create with a fresh ID would paper over a
     * revert that posted a duplicate instead of restoring the original, so an
     * insert without a known ID is recorded as a failure.
     */
    function wp_insert_post(array $data, bool $wpError = false): int
    {
        $GLOBALS['revert_inserts'][] = $data;
        $id = (int) ($data['ID'] ?? 0);

        if ($id < 1 || ! isset($GLOBALS['revert_posts'][$id])) {
            $GLOBALS['revert_unexpected_inserts'][] = $data;

            return 0;
        }

        $post = $GLOBALS['revert_posts'][$id];
        $post->post_title = (string) ($data['post_title'] ?? $post->post_title);
        $post->post_content = (string) ($data['post_content'] ?? $post->post_content);
        $post->post_excerpt = (string) ($data['post_excerpt'] ?? $post->post_excerpt);
        $post->post_status = (string) ($data['post_status'] ?? $post->post_status);

        return $id;
    }

    function is_wp_error(mixed $thing): bool
    {
        return false;
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Auth {

    use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
    use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
    use ADCT\ParishIntake\Core\Approval\Approver;
    use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
    use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
    use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
    use ADCT\ParishIntake\Core\Auth\ActionTokenService;
    use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
    use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
    use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
    use ADCT\ParishIntake\Core\Mail\MailPriority;
    use ADCT\ParishIntake\Core\Mail\OutboundEmail;
    use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
    use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\MailerInterface;
    use ADCT\ParishIntake\Core\Ports\OccurrenceMaintenanceInterface;
    use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
    use ADCT\ParishIntake\WordPress\Auth\RevertChangeHandler;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Events\EventListingGeneration;
    use ADCT\ParishIntake\WordPress\Events\EventOccurrenceHooks;
    use ADCT\ParishIntake\WordPress\Events\EventPostType;
    use DateTimeImmutable;
    use DateTimeZone;
    use DomainException;
    use LogicException;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use Throwable;
    use WP_Post;
    use WP_User;

    /**
     * #71: a revert link in a change notice is addressed to whoever held
     * authority over the parish when the change was published. That is a fact
     * about the past, and the recipient can stop being entitled to it before
     * the link is followed: a dean moved between deaneries, a reviewer de-roled,
     * an approver deactivated.
     *
     * The endpoint cannot detect any of that, and neither can the token, which
     * carries no authority of its own. So the handler has to re-resolve the live
     * relationship at act time and refuse when it no longer holds. These tests
     * pin that seam from both directions: revoked authority refuses and changes
     * nothing, and authority that was *not* revoked still works. Either test
     * alone would pass on a handler that always refuses, so both are load-bearing.
     */
    final class RevertChangeHandlerTest extends TestCase
    {
        private const DEAN = 'dean@example.test';
        private const REVIEWER = 'reviewer@example.test';

                /**
                 * The parish contact who made the change being reverted. Deliberately a
                 * third address, distinct from both the approvers and the published
                 * event's own contact meta, so a test cannot pass by notifying the wrong
                 * one of the three.
                 */
                private const CONTACT = 'office@example.test';

        private const DEAN_USER_ID = 101;
        private const REVIEWER_USER_ID = 202;

        /** The parish the published change belongs to. */
        private const PARISH_ID = 11;

        /** A second deanery the same dean used to cover, so revocation has something to take away. */
        private const OTHER_PARISH_ID = 12;

        private const EVENT_ID = 501;
        private const CHANGE_ID = 9001;

        /** Fixed so the token lifetime is never read from "now". */
        private const NOW = '2026-09-25 08:00:00';

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
                self::REVIEWER_USER_ID => new WP_User(self::REVIEWER_USER_ID, self::REVIEWER),
            ];
            $GLOBALS['adct_test_wp_caps'] = [
                self::DEAN_USER_ID => [Capabilities::APPROVE_DEANERY],
                self::REVIEWER_USER_ID => [Capabilities::REVIEW],
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

            EventOccurrenceHooks::setPublishingCandidate(false);

            parent::tearDown();
        }

        public function testTheHandlerClaimsTheReservedRevertPurpose(): void
        {
            self::assertSame(ActionTokenPurpose::REVERT_CHANGE, $this->handler()->purpose());
        }

        /**
         * The heart of #71. The notice went out while the dean covered both
         * deaneries; moving them to one revokes the token for the parish they no
         * longer cover. The handler must act on the live answer, not the one that
         * was true when the mail was written.
         */
        public function testARevertTokenMintedWhileTheApproverHeldTwoDeaneriesIsRefusedAfterRevocation(): void
        {
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();

            // Mailed while both deaneries were held...
            $token = $tokens->issue($binding)->token();

            // ...then revoked down to one.
            $database = $this->database();
            $handler = $this->handler([self::OTHER_PARISH_ID], $database);

            self::assertNull(
                $handler->preview($binding),
                'A GET must show no action once the dean no longer covers the parish.'
            );

            $mailer = new RecordingMailer();

            try {
                $handler->performAtomic($binding, $token, $tokens, '');
                self::fail('A revert must be refused after the dean lost the parish.');
            } catch (DomainException $refusal) {
                self::assertStringContainsString('no longer', $refusal->getMessage());
            }

            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A refused revert must leave the token usable, so a recipient who still holds'
                    . ' the parish can act on the same notice.'
            );
            self::assertSame([], $database->statements, 'A refused revert must write nothing.');
            self::assertNull(
                $database->change(self::CHANGE_ID)['reverted_by'] ?? null,
                'A refused revert must not mark the change as reverted.'
            );
            self::assertSame(
                'Retreat day (renamed)',
                $GLOBALS['revert_posts'][self::EVENT_ID]->post_title,
                'A refused revert must not touch the published event.'
            );
            self::assertSame(
                8,
                $GLOBALS['revert_meta'][self::EVENT_ID]['venue_id'],
                'A refused revert must not touch the event metadata.'
            );
            self::assertSame([], $mailer->sent, 'A refused revert must not send mail.');
        }

        /**
         * The counterpart. Revoking one deanery must not take the other with it.
         * Without this, the refusal above would also pass on a handler that always
         * says no, which is the failure mode this seam exists to catch.
         */
        public function testTheDeaneryTheApproverStillHoldsIsUnaffectedByARevocationElsewhere(): void
        {
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $database = $this->database();
            $handler = $this->handler([self::PARISH_ID], $database);

            self::assertNotNull(
                $handler->preview($binding),
                'A dean who still covers the parish must be offered the revert.'
            );

            $outcome = $handler->performAtomic($binding, $token, $tokens, '');

            self::assertStringContainsString('reverted', $outcome->message);
        }

        /**
         * A reviewer holds authority archdiocese-wide, so losing a deanery is not
         * what would stop them. If that ever changed, the reviewer path would be
         * the one to break silently.
         */
        public function testAnArchdioceseReviewerIsUnaffectedByADeaneryRevocation(): void
        {
            self::assertNotNull(
                $this->handler([self::OTHER_PARISH_ID])->preview($this->bindingFor(self::REVIEWER)),
                'A reviewer must still be able to revert an event they hold no deanery over.'
            );
        }

        /**
         * A revoked WordPress account differs from a revoked assignment: the
         * deanery route still names them, but the account can no longer act. Both
         * must refuse, for the same underlying reason.
         */
        public function testADeanWhoseAccountIsDeactivatedIsRefusedEvenWhileTheRouteNamesThem(): void
        {
            $GLOBALS['adct_test_wp_users'][self::DEAN_USER_ID]->user_status = 1;

            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $handler = $this->handler([self::PARISH_ID]);

            self::assertNull($handler->preview($binding));

            $this->expectException(DomainException::class);

            $handler->performAtomic($binding, $token, $tokens, '');
        }

        /**
         * The purpose is fixed, so a token minted for a different purpose, or
         * pointed at a different kind of row, must not be actionable here even if
         * it somehow reaches this handler.
         */
        public function testAPreflightRefusesABindingForAnotherPurposeOrSubjectType(): void
        {
            $handler = $this->handler();

            self::assertNull($handler->preview(new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'event_change',
                self::CHANGE_ID,
                self::DEAN
            )));
            self::assertNull($handler->preview(new ActionTokenBinding(
                ActionTokenPurpose::REVERT_CHANGE,
                'event_candidate',
                self::CHANGE_ID,
                self::DEAN
            )));
        }

        public function testAPreflightForAChangeThatDoesNotExistOffersNothing(): void
        {
            self::assertNull(
                $this->handler()->preview(new ActionTokenBinding(
                    ActionTokenPurpose::REVERT_CHANGE,
                    'event_change',
                    4242,
                    self::DEAN
                )),
                'A token for a change row that is gone must not offer an action.'
            );
        }

        /**
         * Reverting is a second reversal of the same row. The notice is still worth
         * reading, so the preview explains itself and disables the button rather
         * than pretending the link is invalid.
         */
        public function testAChangeThatIsAlreadyRevertedExplainsItselfAndIsRefused(): void
        {
            $database = $this->database();
            $database->markReverted(self::CHANGE_ID, self::REVIEWER, '2026-09-20 10:00:00');
            $binding = $this->binding();

            $preview = $this->handler([self::PARISH_ID], $database)->preview($binding);

            self::assertInstanceOf(ActionTokenPreview::class, $preview);
            self::assertFalse($preview->actionable, 'An already reverted change must offer no button.');
            self::assertStringContainsString('already been reverted', $preview->summary);

            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $token = $tokens->issue($binding)->token();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('already been reverted');

            $this->handler([self::PARISH_ID], $database)->performAtomic($binding, $token, $tokens, '');
        }

        /**
         * Reverting writes posts, meta, terms and rows, so it cannot take the
         * plain non-transactional perform path.
         */
        public function testAPlainPerformIsRefusedBecauseRevertingMustBeTransactional(): void
        {
            $this->expectException(LogicException::class);

            $this->handler()->perform($this->binding());
        }

        /**
         * A POST carrying a reason is not one of ours: a revert has no reason to
         * record, so its presence means the request was shaped by something else.
         */
        public function testARevertWithAReasonIsRefused(): void
        {
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('unavailable');

            $this->handler([self::PARISH_ID])->performAtomic($binding, $token, $tokens, 'because');
        }

        /**
         * The published trail. Reverting appends a revert change row carrying the
         * snapshots, so the history reads as a sequence instead of losing the
         * amended state.
         */
        public function testASuccessfulRevertRestoresTheBeforeSnapshotAndRecordsTheReversal(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $outcome = $this->handler([self::PARISH_ID], $database)->performAtomic($binding, $token, $tokens, '');

            self::assertStringContainsString('reverted', $outcome->message);
            self::assertSame(
                [],
                $GLOBALS['revert_unexpected_inserts'],
                'A revert must restore the existing event, never create a new one.'
            );

            // The amended state is gone, field by field.
            $post = $GLOBALS['revert_posts'][self::EVENT_ID];
            self::assertSame('Parish retreat day', $post->post_title);
            self::assertSame('The original description.', $post->post_content);
            self::assertSame('Original excerpt.', $post->post_excerpt);
            self::assertSame([42], $GLOBALS['revert_terms'][self::EVENT_ID]);
            self::assertSame(7, $GLOBALS['revert_meta'][self::EVENT_ID]['venue_id']);
            self::assertSame('', $GLOBALS['revert_meta'][self::EVENT_ID]['status_flag']);
            self::assertSame(299, $GLOBALS['revert_meta'][self::EVENT_ID]['source_candidate_id']);

            // The change row knows who reverted it and that it is done.
            $change = $database->change(self::CHANGE_ID);
            self::assertSame(self::DEAN, $change['reverted_by']);
            self::assertNotNull($change['reverted_at']);

            // And the reversal itself is in the trail, carrying the amended state
            // as its "before" and the restored state as its "after", so the
            // history reads forwards instead of losing what was replaced.
            self::assertNotEmpty($database->insertedChanges);
            self::assertSame('revert', $database->insertedChanges[0]['kind']);
            self::assertSame(self::DEAN, $database->insertedChanges[0]['actor']);
            self::assertSame(self::EVENT_ID, $database->insertedChanges[0]['event_id']);
            $reversal = json_decode(
                (string) $database->insertedChanges[0]['before_payload'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertSame(
                'Retreat day (renamed)',
                $reversal['title'],
                "The reversal's before_payload must hold the state it replaced."
            );
            $restored = json_decode(
                (string) $database->insertedChanges[0]['after_payload'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertSame('Parish retreat day', $restored['title']);
            self::assertSame(7, $restored['meta']['venue_id']);

            self::assertSame(
                ActionTokenStatus::USED,
                $tokens->inspect($token)->status,
                'A successful revert must consume its single-use token.'
            );
        }

        /**
         * The risky part of #71, and the reason a change notice offers one-click
         * revert at all.
         *
         * A revert restores a snapshot of the past. If a *later* change to the same
         * event has since been published, that snapshot is no longer the state this
         * event was in immediately before the change being reverted: it is the state
         * before something else as well. Applying it silently discards the later
         * change, and the approver who made that later change never learns their
         * edit was thrown away by a link they did not press.
         *
         * WordPressPublicationStore::publish() already refuses the equivalent case
         * with "A newer candidate has already updated this event." A revert is the
         * same hazard from the other direction, and without a guard it has none.
         *
         * The refusal must also leave the link usable: the token was minted for a
         * genuine change by a genuine approver, and nothing about the supersession
         * makes it a forgery. That matches every other refusal in this class.
         */
        public function testAChangeSupersededByALaterOneCannotBeReverted(): void
        {
            $database = $this->database();
            // A second change lands on the event after this change row was written:
            // the live event is now somewhere this change never saw.
            $database->seedSupersedingChange(self::EVENT_ID);

            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $occurrences = new RecordingOccurrenceMaintenance();
            $mailer = new RecordingMailer();
            $handler = $this->handler([self::PARISH_ID], $database, $occurrences, $mailer);


            try {
                $handler->performAtomic($binding, $token, $tokens, '');
                self::fail('Reverting a superseded change must be refused.');
            } catch (DomainException $refusal) {
                self::assertStringContainsString(
                    'newer',
                    $refusal->getMessage(),
                    'The refusal must say a newer change exists, or an approver cannot tell'
                        . ' this apart from a broken link.'
                );
            }

            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A superseded change must not burn the link: the refusal is about the state'
                    . ' of the event, not about the link.'
            );
            self::assertNull(
                $database->change(self::CHANGE_ID)['reverted_by'] ?? null,
                'A refused revert must not mark the change as reverted.'
            );
            self::assertSame(
                [],
                $database->insertedChanges,
                'A refused revert must not append a reversal row.'
            );
            self::assertSame(
                'Retreat day (renamed)',
                $GLOBALS['revert_posts'][self::EVENT_ID]->post_title,
                'A refused revert must not touch the published event.'
            );
            self::assertSame(
                [],
                $occurrences->rebuilt,
                'A refused revert must not rebuild occurrences for a state that was never restored.'
            );
            self::assertSame([], $mailer->sent, 'A refused revert must not send mail.');
        }

        /**
         * The counterpart, and the reason the guard above is not just "refuse
         * everything". A change whose after_payload is still the live state is the
         * current head of the trail and must revert normally.
         */
        public function testTheCurrentHeadOfTheTrailStillReverts(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $outcome = $this->handler([self::PARISH_ID], $database)->performAtomic($binding, $token, $tokens, '');

            self::assertStringContainsString('reverted', $outcome->message);
            self::assertSame(
                'Parish retreat day',
                $GLOBALS['revert_posts'][self::EVENT_ID]->post_title
            );
        }

        /**
         * A change to a different event cannot say anything about this one, so it
         * must not be read as superseding it. Without this, the guard above would
         * also pass on a "refuse whenever any other change exists" implementation.
         */
        public function testAChangeToADifferentEventDoesNotBlockThisRevert(): void
        {
            $database = $this->database();
            $database->seedSupersedingChange(9999);

            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $outcome = $this->handler([self::PARISH_ID], $database)->performAtomic($binding, $token, $tokens, '');

            self::assertStringContainsString('reverted', $outcome->message);
        }

        public function testARevertRefreshesOccurrencesAndTheListingCache(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $occurrences = new RecordingOccurrenceMaintenance();
            $mailer = new RecordingMailer();

            $this->handler([self::PARISH_ID], $database, $occurrences, $mailer)
                ->performAtomic($binding, $token, $tokens, '');

            self::assertCount(
                1,
                $occurrences->rebuilt,
                'A revert must refresh the occurrence rows once, or the feed keeps the amended dates.'
            );
            self::assertSame(self::EVENT_ID, $occurrences->rebuilt[0]);

            self::assertSame(
                [EventListingGeneration::OPTION],
                $GLOBALS['revert_option_writes'],
                'A revert must invalidate the public listing cache exactly once.'
            );
            self::assertContains(self::EVENT_ID, $GLOBALS['revert_cache_cleared']);

            $recipients = array_map(static fn ($email) => $email->recipient, $mailer->sent);

                        self::assertCount(
                            2,
                            $mailer->sent,
                            'A successful revert confirms to the presser and tells the contact.'
                        );
                        self::assertSame(
                            [self::DEAN, self::CONTACT],
                            $recipients,
                            'Both messages are confirmation: nothing here re-mints an undo link.'
                        );
                        self::assertSame(self::DEAN, $mailer->sent[0]->recipient);
                        self::assertSame(MailPriority::APPROVER_OR_CHANGE, $mailer->sent[0]->priority);
                    }

        /**
         * The contact whose change was undone is the person who needs to know.
         *
         * They made the edit in good faith and the event is no longer what they
         * wrote. ChangeNoticeJob deliberately does not mail `revert` rows — the
         * approvers who press the button already hold that authority, so a link
         * mailed back to them would be a second live credential for an undo that
         * already happened. That leaves `afterCommit()` as the only place the
         * pressed button is known, and therefore the only place the contact can
         * be reached. If this mail is dropped, a parish silently loses its change
         * and never finds out.
         */
        public function testTheContactWhoseChangeWasUndoneIsToldToo(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $mailer = new RecordingMailer();

            $this->handler([self::PARISH_ID], $database, null, $mailer)
                ->performAtomic($binding, $token, $tokens, '');

            $recipients = array_map(static fn ($email) => $email->recipient, $mailer->sent);

            self::assertContains(
                self::CONTACT,
                $recipients,
                'The contact who made the reverted change must learn that it was undone.'
            );

            $toContact = null;
            foreach ($mailer->sent as $email) {
                if ($email->recipient === self::CONTACT) {
                    $toContact = $email;
                }
            }

            self::assertNotNull($toContact);
            self::assertStringContainsString(
                'undone',
                $toContact->textBody,
                'The mail must say the outcome in words, not just that something happened.'
            );
            self::assertSame(
                MailPriority::APPROVER_OR_CHANGE,
                $toContact->priority,
                'A change notice shares the approver/change queue, not the reminder one.'
            );
        }

        /**
         * A contact who is also the approver who pressed the button gets one
         * message, not two. The two mails would say the same thing twice and
         * cost two of the account's 500 emails an hour (ADR 0011).
         */
        public function testAContactWhoAlsoRevertedItIsToldOnceNotTwice(): void
        {
            $database = $this->database();
                        // The change is recorded as made by the same address that reverts it.
                        $database->setActor(self::CHANGE_ID, self::DEAN);
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $mailer = new RecordingMailer();

            $this->handler([self::PARISH_ID], $database, null, $mailer)
                ->performAtomic($binding, $token, $tokens, '');

            self::assertCount(
                1,
                $mailer->sent,
                'One person, one message: the presser and the contact are the same address here.'
            );
            self::assertSame(self::DEAN, $mailer->sent[0]->recipient);
        }

        /**
         * A restore that half-applies would leave a published event in a state that
         * never existed, so it has to be one transaction with the token consumed
         * inside it.
         *
         * A failure mid-revert rolls back everything, so nothing may be mailed
         * either: a message about a revert that did not happen is worse than
         * silence, because the contact would re-send a change that is still live.
         */
        public function testAFailureMidRevertRollsBackEverything(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService($store = new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();
            $store->commit();

            $occurrences = new RecordingOccurrenceMaintenance();
            $occurrences->failOnRebuild = true;
                        $mailer = new RecordingMailer();

                        try {
                            $this->handler([self::PARISH_ID], $database, $occurrences, $mailer)
                                ->performAtomic($binding, $token, $tokens, '');
                            self::fail('A failed occurrence rebuild must abort the revert.');
                        } catch (RuntimeException) {
                            // Expected: the rebuild blew up.
                        }

                        self::assertSame(
                            [],
                            $mailer->sent,
                            'A revert that rolled back must mail nobody: the change is still live.'
                        );
                        self::assertContains('ROLLBACK', $database->transactions);
            self::assertNotContains('COMMIT', $database->transactions);
            // A rolled-back revert must not burn the token, so a retry stays
            // possible. commit() runs even though the revert failed: the
            // transaction that established the token as VALID already ended.
            $store->rollback();
            self::assertSame(
                ActionTokenStatus::VALID,
                $tokens->inspect($token)->status,
                'A rolled-back revert must not burn the token, so a retry stays possible.'
            );
            self::assertSame(
                null,
                $database->change(self::CHANGE_ID)['reverted_by'] ?? null,
                'A rolled-back revert must not leave the change marked as reverted.'
            );
        }

        /**
         * Re-running a spent link must explain itself rather than look like a bad
         * link, and must not revert twice.
         */
        public function testRecoveringASpentLinkExplainsThatItAlreadyReverted(): void
        {
            $database = $this->database();
            $tokens = new ActionTokenService(new RevertTokenStore(), new RevertClock());
            $binding = $this->binding();
            $token = $tokens->issue($binding)->token();

            $handler = $this->handler([self::PARISH_ID], $database);
            $handler->performAtomic($binding, $token, $tokens, '');

            $outcome = $handler->recover($binding);

            self::assertStringContainsString('already been reverted', $outcome->message);
        }

        public function testRecoveringALinkThisRecipientNeverActedOnIsRefused(): void
        {
            $database = $this->database();
            $database->markReverted(self::CHANGE_ID, self::REVIEWER, '2026-09-20 10:00:00');

            $this->expectException(DomainException::class);

            $this->handler([self::PARISH_ID], $database)->recover($this->bindingFor(self::DEAN));
        }

        /**
         * @param list<int> $heldParishIds Deaneries this dean currently covers.
         */
        private function handler(
            array $heldParishIds = [self::PARISH_ID],
            ?RevertDatabase $database = null,
            ?OccurrenceMaintenanceInterface $occurrences = null,
            ?MailerInterface $mailer = null
        ): RevertChangeHandler {
            return new RevertChangeHandler(
                $database ?? $this->database(),
                new ApprovalRecipients(new ApprovalRouteResolver(new RevertRouteRepository($heldParishIds))),
                $mailer ?? new RecordingMailer(),
                new RevertClock(),
                $occurrences ?? new RecordingOccurrenceMaintenance(),
                new EventListingGeneration(),
                new DateTimeZone('Africa/Johannesburg')
            );
        }

        private function binding(): ActionTokenBinding
        {
            return $this->bindingFor(self::DEAN);
        }

        private function bindingFor(string $email): ActionTokenBinding
        {
            return new ActionTokenBinding(
                ActionTokenPurpose::REVERT_CHANGE,
                'event_change',
                self::CHANGE_ID,
                $email
            );
        }

        /**
         * The state the amendment left the event in: a different title, a different
         * venue, a cancellation flag that was not there before, and a newer candidate
         * as the source.
         *
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
                'status_flag' => 'cancelled',
                'source_candidate_id' => 300,
                'contact' => 'contact@example.test',
            ];
        }

        /**
         * A change row whose before_payload is the same shape
         * WordPressPublicationStore::snapshot() writes, so a restore is a real
         * restore rather than a guess about the shape.
         */
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
                            self::CONTACT,
                'update',
                $before,
                $after
            );

            return $database;
        }
    }

    /**
     * The live relationship, and the whole point of the exercise: the same parish
     * answers differently once the dean's assignment changes, so a handler that
     * reads it at act time sees what is true now rather than what was true when
     * the notice was mailed.
     */
    final class RevertRouteRepository implements ApprovalRouteRepositoryInterface
    {
        /**
         * @param list<int> $heldParishIds
         */
        public function __construct(private readonly array $heldParishIds)
        {
        }

        public function findForParish(int $parishId): ?ApprovalRouteSnapshot
        {
            $dean = new Approver(
                1,
                RevertChangeHandlerFixture::DEAN_USER_ID,
                RevertChangeHandlerFixture::DEAN_EMAIL,
                'Dean of the archdiocese',
                Approver::NOTIFY_EACH,
                true,
                true
            );

                // An unheld parish keeps its deanery but loses its approver, which is
                // exactly how a deanery approver assignment is withdrawn.
                return new ApprovalRouteSnapshot(
                    7,
                    true,
                    in_array($parishId, $this->heldParishIds, true) ? [$dean] : []
                );
            }
        }

    /**
     * Fixture constants shared between the test and its collaborators, so the
     * repository does not have to reach into the test class.
     */
    final class RevertChangeHandlerFixture
    {
        public const DEAN_USER_ID = 101;
        public const REVIEWER_USER_ID = 202;
        public const DEAN_EMAIL = 'dean@example.test';
        public const REVIEWER_EMAIL = 'reviewer@example.test';
        public const PARISH_ID = 11;
        public const NOW = '2026-09-25 08:00:00';
    }

    /**
     * A stand-in for the event_changes table, so the handler's SQL is exercised
     * without a database. It records what it is asked to run, which is what lets
     * a test assert that a refusal wrote nothing at all.
     */
    final class RevertDatabase implements DatabaseConnectionInterface
    {
        /**
         * @var array<int, array<string, mixed>>
         */
        private array $changes = [];

        /**
         * @var array<int, array<string, mixed>>
         */
        private array $committed = [];

        /**
         * Statements run outside a transaction, so "a refusal wrote nothing" is checkable.
         *
         * @var list<string>
         */
        public array $statements = [];

        /**
         * @var list<string>
         */
        public array $transactions = [];

        /**
         * @var list<array<string, mixed>>
         */
        public array $insertedChanges = [];

        /**
         * @param array<string, mixed> $before
         * @param array<string, mixed> $after
         */
        public function seedChange(
            int $id,
            int $eventId,
            int $candidateId,
            string $actor,
            string $kind,
            array $before,
            array $after
        ): void {
            $this->changes[$id] = [
                'id' => $id,
                'event_id' => $eventId,
                'candidate_id' => $candidateId,
                'actor' => $actor,
                'kind' => $kind,
                'before_payload' => self::encode($before),
                'after_payload' => self::encode($after),
                'notified_at' => null,
                'reverted_by' => null,
                'reverted_at' => null,
                'created_at' => '2026-09-24 12:00:00',
                'updated_at' => '2026-09-24 12:00:00',
            ];
        }

        public function markReverted(int $id, string $email, string $at): void
        {
            if (isset($this->changes[$id])) {
                $this->changes[$id]['reverted_by'] = $email;
                $this->changes[$id]['reverted_at'] = $at;
            }
        }

                /**
                 * Re-attributes the seeded change, so a test can make the contact and the
                 * presser the same address without restating the snapshots.
                 */
                public function setActor(int $id, string $actor): void
                {
                    if (isset($this->changes[$id])) {
                        $this->changes[$id]['actor'] = $actor;
                    }
                }

        /**
         * A change published after the one under revert, so the event's live state
         * is no longer the state that change left behind.
         *
         * It also moves the live event itself, because that is what a later
         * publication does. The guard has to notice from the trail alone — the
         * handler locks the change row and the post, and reading the post is the
         * only way to see that somebody edited it after the fact.
         */
        public function seedSupersedingChange(int $eventId = 501): void
        {
            $this->seedChange(
                9002,
                $eventId,
                301,
                'reviewer@example.test',
                'update',
                [],
                []
            );
        }

        /**
         * @return array<string, mixed>|null
         */
        public function change(int $id): ?array
        {
            return $this->changes[$id] ?? null;
        }

        /**
         * Mirrors WordPressPublicationStore: once ROLLBACK has run, the rows this
         * fake was updating are back to how they started.
         */
        public function rollback(): void
        {
            foreach ($this->changes as $id => $change) {
                $this->changes[$id] = $this->committed[$id] ?? $change;
            }
        }

        public function prefix(): string
        {
            return 'wp_';
        }

        /**
         * Arguments are appended so the handler still has to supply every one of them,
         * and nothing can be interpolated raw.
         */
        public function prepare(string $query, mixed ...$arguments): string
        {
            return $query . ' /* ' . (string) json_encode($arguments, JSON_THROW_ON_ERROR) . ' */';
        }

        public function query(string $query): int|false
        {
            $trimmed = trim($query);
            $upper = strtoupper($trimmed);

            if ($upper === 'START TRANSACTION' || $upper === 'COMMIT' || $upper === 'ROLLBACK') {
                $this->transactions[] = $upper;

                if ($upper === 'COMMIT') {
                    $this->committed = $this->changes;
                } elseif ($upper === 'ROLLBACK') {
                    $this->rollback();
                }

                return 0;
            }

            $this->statements[] = $trimmed;

            if (str_contains($trimmed, 'INSERT INTO `wp_adct_pi_event_changes`')) {
                $values = self::argumentsOf($trimmed);
                // The column order is event_id,candidate_id,actor,kind,...
                $this->insertedChanges[] = [
                    'event_id' => (int) ($values[0] ?? 0),
                    'candidate_id' => $values[1] ?? null,
                    'actor' => (string) ($values[2] ?? ''),
                    'kind' => (string) ($values[3] ?? ''),
                    'before_payload' => (string) ($values[4] ?? ''),
                    'after_payload' => (string) ($values[5] ?? ''),
                ];

                return 1;
            }
            if (str_contains($trimmed, 'UPDATE `wp_adct_pi_event_changes`')) {
                $values = self::argumentsOf($trimmed);
                $id = (int) ($values[3] ?? 0);
                if (! isset($this->changes[$id]) || $this->changes[$id]['reverted_at'] !== null) {
                    // The guarded UPDATE must report 0 when the row is gone or
                    // already reversed, or the handler's concurrency check is a
                    // no-op it would never notice.
                    return 0;
                }
                $this->changes[$id]['reverted_by'] = (string) ($values[0] ?? '');
                $this->changes[$id]['reverted_at'] = (string) ($values[1] ?? '');
                $this->changes[$id]['updated_at'] = (string) ($values[2] ?? '');

                return 1;
            }

            return 1;
        }

        public function getRow(string $query): ?array
        {
            // The handler joins postmeta for parish_id, so matching on the bare
            // "FROM ... WHERE id" shape would silently return nothing and read
            // as a missing change rather than a seam mismatch.
            if (preg_match('#WHERE c\.id = %d#', $query) === 1
                && str_contains($query, 'adct_pi_event_changes')) {
                $arguments = self::argumentsOf($query);
                $row = $this->changes[(int) ($arguments[0] ?? 0)] ?? null;

                if ($row === null) {
                    return null;
                }

                $row['parish_id'] = RevertChangeHandlerFixture::PARISH_ID;

                return $row;
            }

            if (preg_match(
                '#WHERE event_id = %d AND id > %d#',
                $query
            ) === 1 && str_contains($query, 'adct_pi_event_changes')) {
                $arguments = self::argumentsOf($query);
                $eventId = (int) ($arguments[0] ?? 0);
                $afterId = (int) ($arguments[1] ?? 0);

                $matches = array_filter(
                    $this->changes,
                    static fn (array $change): bool => (int) $change['event_id'] === $eventId
                        && (int) $change['id'] > $afterId
                );
                if ($matches === []) {
                    return null;
                }
                ksort($matches);

                return ['id' => (int) array_key_first($matches)];
            }

            if (preg_match('#SELECT ID FROM `wp_posts` WHERE ID = %d#', $query) === 1) {
                return isset($GLOBALS['revert_posts'][(int) (self::argumentsOf($query)[0] ?? 0)])
                    ? ['ID' => (int) (self::argumentsOf($query)[0] ?? 0)]
                    : null;
            }

            return null;
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function getResults(string $query): array
        {
            return [];
        }

        public function escapeLike(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function insertId(): int
        {
            return 1;
        }

        public function charsetCollate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function clearLastError(): void
        {
        }

        public function lastError(): string
        {
            return '';
        }

        /**
         * @return list<mixed>
         */
        private static function argumentsOf(string $prepared): array
        {
            $position = strrpos($prepared, '/*');

            if ($position === false) {
                return [];
            }

            $decoded = json_decode(substr($prepared, $position + 2, -2), true);

            return is_array($decoded) ? $decoded : [];
        }

        /**
         * @param array<string, mixed> $payload
         */
        private static function encode(array $payload): string
        {
            return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
    }

    final class RevertClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable(
                RevertChangeHandlerFixture::NOW,
                new DateTimeZone('Africa/Johannesburg')
            );
        }
    }

    /**
     * The real store writes used_at with an UPDATE on the same connection the
     * handler's transaction is open on, so a ROLLBACK un-consumes the token too.
     * The fake mirrors that: without it, a rolled-back revert would look like it
     * had burned the link and a retry would be impossible.
     */
    final class RevertTokenStore implements ActionTokenStoreInterface
    {
        /**
         * @var array<string, ActionTokenRecord>
         */
        private array $records = [];

        /**
         * @var array<string, ActionTokenRecord>
         */
        private array $committed = [];

        public function commit(): void
        {
            $this->committed = $this->records;
        }

        public function rollback(): void
        {
            $this->records = $this->committed;
        }

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

    final class RecordingMailer implements MailerInterface
    {
        /**
         * @var list<OutboundEmail>
         */
        public array $sent = [];

        public function enqueue(OutboundEmail $email): MailQueueEnqueueResult
        {
            $this->sent[] = $email;

            return new MailQueueEnqueueResult(count($this->sent), MailQueueStatus::QUEUED, false);
        }
    }

    /**
     * Records the rebuild instead of performing it, so a test can assert the revert
     * refreshed occurrences without dragging in the expander.
     */
    final class RecordingOccurrenceMaintenance implements OccurrenceMaintenanceInterface
    {
        /**
         * @var list<int>
         */
        public array $rebuilt = [];

        public bool $failOnRebuild = false;

        public function nextEventIdAfter(int $eventId): ?int
        {
            return null;
        }

        public function rebuildEvent(int $eventId, OccurrenceWindow $window): void
        {
            if ($this->failOnRebuild) {
                throw new RuntimeException('The occurrence rebuild failed.');
            }

            $this->rebuilt[] = $eventId;
        }

        public function deleteEventOccurrences(int $eventId): void
        {
        }
    }
}
