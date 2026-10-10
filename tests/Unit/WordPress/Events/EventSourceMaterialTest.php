<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Events;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Ports\IntakeAttachmentReaderInterface;
use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use ADCT\ParishIntake\WordPress\Events\EventEditor;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The event editor's source material control (issue #172).
 *
 * The review queue is the first of the two places an event's source material can
 * be offered; this is the second, and the one that works *after* the fact. A
 * publisher who promoted the wrong poster at review time, or who was asked to
 * add a bulletin after the event was already published, comes here.
 *
 * These tests are mostly about the guard rather than the button. The button is
 * small; the guard is the thing that decides whether a stranger with a browser
 * can decide which of a parish's files becomes publicly readable, and who gets
 * blamed for it in the audit trail.
  */
 #[CoversClass(EventEditor::class)]
 final class EventSourceMaterialTest extends TestCase
{
    private const EVENT = 314;

    private const MEDIA = 900;

    private const ATTACHMENT = 11;

        /** The candidate this event was published from. */
        private const CANDIDATE = 47;

        /** The intake message that candidate was extracted from. */
        private const MESSAGE = 6;

        protected function setUp(): void
        {
            parent::setUp();

        require_once __DIR__ . '/../../../Support/WordPressStubs.php';
        require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';
        require_once __DIR__ . '/../../../Support/AdminWordPressStubs.php';
        require_once __DIR__ . '/../../../Support/WordPressMediaStubs.php';
        require_once __DIR__ . '/../../../Support/WordPressEventEditorStubs.php';

        $GLOBALS['adct_test_current_user'] = new \WP_User(9, 'publisher@example.test');
        $GLOBALS['adct_test_nonce_checks'] = [];
        $GLOBALS['adct_test_nonce_fields'] = [];
        $GLOBALS['adct_test_meta_boxes'] = [];
        $GLOBALS['adct_test_redirect'] = null;
        $GLOBALS['adct_test_post_caps'] = true;
                $GLOBALS['adct_test_post_types'] = [];

                // Every per-test global is seeded here, not merely unset in tearDown().
                // A leaked one does not announce itself: it turns a later test's guard
                // into a different guard, and the suite's verdict then depends on the
                // order the runner happened to pick.
                $GLOBALS['adct_test_candidate_messages'] = [];
                $GLOBALS['adct_test_promotable_attachments'] = [];
                $GLOBALS['adct_test_all_stored_attachments'] = [];
                $GLOBALS['adct_test_nonce_expected_action'] = null;
                unset($GLOBALS['adct_test_nonce_should_fail']);

        $GLOBALS['adct_publishing_meta'] = [
            self::EVENT => [
                'source_attachment_ids' => json_encode([
                    [
                        'media_id' => self::MEDIA,
                        'role' => SourceAttachment::ROLE_POSTER,
                        'original_filename' => 'parish-poster.png',
                        'attachment_id' => self::ATTACHMENT,
                    ],
                ]),
            ],
        ];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        unset(
            $GLOBALS['adct_test_meta_boxes'],
            $GLOBALS['adct_test_post_caps'],
            $GLOBALS['adct_test_candidate_messages'],
            $GLOBALS['adct_test_promotable_attachments'],
            $GLOBALS['adct_test_all_stored_attachments']
        );
    }

    /**
     * A box is registered only when a store was wired in, because a box that
     * cannot do anything is a control that looks like it works.
     */
    public function testTheControlIsOfferedOnlyWhenAStoreWasWiredIn(): void
    {
        $this->editor()->registerMetaBox();

        self::assertArrayHasKey('adct_event_source_material', $GLOBALS['adct_test_meta_boxes']);

        unset($GLOBALS['adct_test_meta_boxes']);
        $this->editor(store: null)->registerMetaBox();

        self::assertArrayNotHasKey('adct_event_source_material', $GLOBALS['adct_test_meta_boxes']);
    }

    /**
         * A box that has nothing published still has to say so, and -- when there is
         * a provenance chain to offer from -- still carry the control that changes it.
         */
        public function testAnEventWithNoSourceMaterialSaysSoAndStillOffersTheControl(): void
        {
            $this->givenProvenance(messageId: self::MESSAGE, files: [
                ['id' => self::ATTACHMENT, 'filename' => 'parish-poster.png', 'mime_type' => 'image/png'],
            ]);
            $GLOBALS['adct_publishing_meta'][self::EVENT]['source_attachment_ids'] = json_encode([]);

            $markup = $this->renderBox($this->editor());

            self::assertStringContainsString('Nothing has been published with this event yet.', $markup);
            self::assertStringContainsString(EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION, $markup);
        }

            // --------------------------------------------------------- the add control

            /**
             * The add control offers the files that arrived with *this* event's email,
             * and only those.
             *
             * This is the answer to the issue's named failure mode -- offering one
             * parish's files on another parish's event -- and it is answered structurally
             * rather than by a filter: the walk starts at the event, follows the
             * `source_candidate_id` the publication store wrote, resolves that to the one
             * message the parish's notice arrived on, and lists only that message's files.
             * There is no code path from this screen to any other parish's attachments.
             */
        public function testTheAddControlOffersOnlyThisEventsOwnIntakeFiles(): void
            {
                        $this->withNothingPromoted();
                        $this->givenProvenance(messageId: self::MESSAGE, files: [
                            ['id' => self::ATTACHMENT, 'filename' => 'parish-poster.png', 'mime_type' => 'image/png'],
                            ['id' => self::ATTACHMENT + 1, 'filename' => 'september-bulletin.pdf', 'mime_type' => 'application/pdf'],
                        ]);

                $markup = $this->renderBox($this->editor());

                self::assertStringContainsString('name="attachment_id"', $markup);
                self::assertStringContainsString('value="' . self::ATTACHMENT . '"', $markup);
                self::assertStringContainsString('parish-poster.png', $markup);
                self::assertStringContainsString('value="' . (self::ATTACHMENT + 1) . '"', $markup);
                self::assertStringContainsString('september-bulletin.pdf', $markup);
            }

        /**
         * A file from another parish must never appear as an option, even though it
         * is perfectly promotable in its own right. The offer is scoped by message,
         * so "somebody else's bulletin" is not filtered out of the list -- it was
         * never queried.
         */
        public function testAnotherParishesFileIsNotEvenOffered(): void
        {
                    $this->withNothingPromoted();
                    $this->givenProvenance(messageId: self::MESSAGE, files: [
                ['id' => self::ATTACHMENT + 1, 'filename' => 'parish-poster.png', 'mime_type' => 'image/png'],
            ]);
            // Present in the store, belonging to a different message entirely.
            $GLOBALS['adct_test_all_stored_attachments'] = [
                ['id' => 5555, 'filename' => 'other-parish-bulletin.pdf', 'message_id' => 999],
            ];

            $markup = $this->renderBox($this->editor());

            self::assertStringNotContainsString('other-parish-bulletin.pdf', $markup);
            self::assertStringNotContainsString('value="5555"', $markup);
        }

        /**
         * An event with no `source_candidate_id` has no email behind it, so there is
         * nothing to offer and the control says so rather than offering everything.
         *
         * This is the case a manual entry (#63) would create, and it is why the
         * control cannot simply list every stored attachment.
         */
        public function testAnEventWithNoSourceEmailOffersNothingRatherThanEverything(): void
        {
            unset($GLOBALS['adct_publishing_meta'][self::EVENT]['source_candidate_id']);
            $GLOBALS['adct_test_all_stored_attachments'] = [
                ['id' => 5555, 'filename' => 'other-parish-bulletin.pdf', 'message_id' => 999],
            ];

            $markup = $this->renderBox($this->editor());

            self::assertStringNotContainsString('name="attachment_id"', $markup);
            self::assertStringNotContainsString('other-parish-bulletin.pdf', $markup);
            self::assertStringContainsString('source material', strtolower($markup));
        }

        /**
         * HEIC and HEIF are storable so an operator can open them, but no browser
         * renders them. Promoting one would publish a link that is broken for every
         * visitor who clicks it, so they are shown as unavailable and are not
         * options.
         */
        public function testAnUnrenderableImageTypeIsShownAsUnavailableAndIsNotAnOption(): void
        {
                    $this->withNothingPromoted();
                    $this->givenProvenance(messageId: self::MESSAGE, files: [
                ['id' => 77, 'filename' => 'parish-poster.heic', 'mime_type' => 'image/heic'],
                ['id' => 78, 'filename' => 'parish-poster.heif', 'mime_type' => 'image/heif'],
            ]);

            $markup = $this->renderBox($this->editor());

            self::assertStringContainsString('parish-poster.heic', $markup, 'the file is still accounted for');
            self::assertStringNotContainsString('value="77"', $markup, 'but it is not an option');
            self::assertStringNotContainsString('value="78"', $markup);
            self::assertStringContainsString('cannot be published', $markup);
        }

        /**
         * A file already promoted is not offered again, so a publisher does not
         * promote the same bulletin under two different roles.
         */
        public function testAFileAlreadyPromotedIsNotOfferedAgain(): void
        {
            $this->givenProvenance(messageId: self::MESSAGE, files: [
                ['id' => self::ATTACHMENT, 'filename' => 'parish-poster.png', 'mime_type' => 'image/png'],
                ['id' => self::ATTACHMENT + 1, 'filename' => 'september-bulletin.pdf', 'mime_type' => 'application/pdf'],
            ]);

            $markup = $this->renderBox($this->editor());

            // setUp seeds MEDIA / ATTACHMENT as already promoted.
            self::assertStringNotContainsString('value="' . self::ATTACHMENT . '"', $markup);
            self::assertStringContainsString('value="' . (self::ATTACHMENT + 1) . '"', $markup);
        }

        /**
         * The handler re-derives the offer and refuses anything outside it.
         *
         * Rendering the list is not authorisation. A request can name any
         * `attachment_id` at all, so the handler asks the same provenance question
         * the render did and refuses an id that is not in the answer. Without this a
         * crafted POST would attach another parish's bulletin and write an audit row
         * naming the publisher who was tricked into clicking it.
         */
        public function testTheAddHandlerRefusesAnAttachmentThatIsNotOnOffer(): void
        {
            $this->givenProvenance(messageId: self::MESSAGE, files: [
                ['id' => self::ATTACHMENT + 1, 'filename' => 'september-bulletin.pdf', 'mime_type' => 'application/pdf'],
            ]);

            $store = new RecordingEditorStore();

            $died = $this->refusedAs(
                $this->editor($store),
                'handlePromoteSourceMaterial',
                404,
                [
                    'event_id' => self::EVENT,
                    'attachment_id' => 4242,
                    'role' => SourceAttachment::ROLE_POSTER,
                ],
                nonce: EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
                expectedAction: EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION
            );

            self::assertNotEmpty($died, 'An attachment from another message is not this event\'s.');
            self::assertSame([], $store->promotions, 'The store is never reached.');
            self::assertSame([], $store->audit, 'And no audit row claims a publisher did this.');
        }

        /**
         * The refused message must not tell the requester whether an attachment id
         * exists somewhere in the system.
         */
        public function testTheRefusalForAnUnknownAttachmentSaysNothingAboutTheRestOfTheSite(): void
        {
            $this->givenProvenance(messageId: self::MESSAGE, files: []);

            $store = new RecordingEditorStore();

            $died = $this->refusedAs(
                $this->editor($store),
                'handlePromoteSourceMaterial',
                404,
                [
                    'event_id' => self::EVENT,
                    'attachment_id' => 4242,
                    'role' => SourceAttachment::ROLE_POSTER,
                ],
                nonce: EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
                expectedAction: EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION
            );

            self::assertStringNotContainsString('parish', strtolower($died['message']));
            self::assertStringNotContainsString('exists', strtolower($died['message']));
        }

    /**
     * The promoted list is rendered from the ordered post meta, in order, with
     * the role spelled out. This is the only read of an event's source material
     * on this screen, and it is the same read the front end makes.
     */
    public function testThePromotedMaterialIsListedInOrderWithItsRole(): void
    {
        $GLOBALS['adct_publishing_meta'][self::EVENT]['source_attachment_ids'] = json_encode([
            [
                'media_id' => self::MEDIA,
                'role' => SourceAttachment::ROLE_POSTER,
                'original_filename' => 'parish-poster.png',
                'attachment_id' => self::ATTACHMENT,
            ],
            [
                'media_id' => self::MEDIA + 1,
                'role' => SourceAttachment::ROLE_BULLETIN,
                'original_filename' => 'september-bulletin.pdf',
                'attachment_id' => self::ATTACHMENT + 1,
            ],
        ]);

        $markup = $this->renderBox($this->editor());

        $poster = strpos($markup, 'parish-poster.png');
        $bulletin = strpos($markup, 'september-bulletin.pdf');

        self::assertIsInt($poster);
        self::assertIsInt($bulletin);
        self::assertLessThan($bulletin, $poster, 'The order in the meta is the order on screen.');

        // The role is shown, not inferred: parentage alone does not say which file
        // is the poster, so the poster role has to be legible to a human.
        self::assertStringContainsString('Poster', $markup);
        self::assertStringContainsString('Bulletin', $markup);
    }

    /**
     * The parish's own filename is untrusted text that came out of an email, and
     * it is rendered into an admin page. It must be escaped on every render, not
     * trusted because "it only comes from our own parishes".
     */
    public function testAParishFilenameIsEscapedRatherThanRendered(): void
    {
        $GLOBALS['adct_publishing_meta'][self::EVENT]['source_attachment_ids'] = json_encode([
            [
                'media_id' => self::MEDIA,
                'role' => SourceAttachment::ROLE_POSTER,
                'original_filename' => '<script>alert(1)</script>.png',
                'attachment_id' => self::ATTACHMENT,
            ],
        ]);

        $markup = $this->renderBox($this->editor());

        self::assertStringNotContainsString('<script>alert(1)</script>', $markup);
        self::assertStringContainsString('&lt;script&gt;', $markup);
    }

    /**
     * Add and remove are two different actions with two different nonces.
     *
     * A single nonce covering both would mean a link harvested from one control
     * authorises the other, and the remove button is the one a stranger would
     * want. Each gets its own action name and its own field.
     */
    public function testAddAndRemoveCarrySeparateActionsAndNonces(): void
    {
            // Both controls are on screen at once only when there is something already
            // published to remove *and* a file still on offer to add; this seeds both.
            $this->withNothingPromoted();
            $this->givenProvenance(messageId: self::MESSAGE, files: [
                ['id' => self::ATTACHMENT + 1, 'filename' => 'september-bulletin.pdf', 'mime_type' => 'application/pdf'],
            ]);
            $GLOBALS['adct_publishing_meta'][self::EVENT]['source_attachment_ids'] = json_encode([
                [
                    'media_id' => self::MEDIA,
                    'role' => SourceAttachment::ROLE_POSTER,
                    'original_filename' => 'parish-poster.png',
                    'attachment_id' => self::ATTACHMENT,
                ],
            ]);

            $markup = $this->renderBox($this->editor());

        self::assertStringContainsString(EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION, $markup);
        self::assertStringContainsString(EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE, $markup);
        self::assertStringContainsString(EventEditor::REMOVE_SOURCE_MATERIAL_ACTION, $markup);
        self::assertStringContainsString(EventEditor::REMOVE_SOURCE_MATERIAL_NONCE, $markup);
        self::assertNotSame(
            EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION,
            EventEditor::REMOVE_SOURCE_MATERIAL_ACTION,
            'A shared action name would let one control authorise the other.'
        );
        self::assertNotSame(
            EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
            EventEditor::REMOVE_SOURCE_MATERIAL_NONCE,
            'A shared nonce field would let one control authorise the other.'
        );
    }

    // ---------------------------------------------------------------- guards

    /**
     * The add control refuses a request that carries no nonce, before it reads
     * a single field of it and before it reaches the store.
     *
     * This is the mutation the issue asks for by name: delete the nonce check
     * and this goes red.
     */
    public function testAddingSourceMaterialWithoutTheNonceIsRefusedAndTouchesNothing(): void
    {
        $store = new RecordingEditorStore();
        unset($GLOBALS['adct_test_nonce_fields'][EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE]);

        $died = $this->refusedAs(
            $this->editor($store),
            'handlePromoteSourceMaterial',
            403,
            [
                'event_id' => self::EVENT,
                'attachment_id' => self::ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ]
        );

        self::assertSame([], $store->promotions, 'A refused request must not reach the store.');
    }

    /**
     * A nonce minted for a different action is not this one's nonce. A valid
     * nonce for the *remove* control must not authorise an add, which is the
     * whole reason there are two of them.
     */
    public function testTheRemoveNonceDoesNotAuthoriseAnAdd(): void
    {
        $store = new RecordingEditorStore();

        $died = $this->refusedAs(
            $this->editor($store),
            'handlePromoteSourceMaterial',
            403,
            [
                'event_id' => self::EVENT,
                'attachment_id' => self::ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ],
            nonce: EventEditor::REMOVE_SOURCE_MATERIAL_NONCE,
            expectedAction: EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION
        );

        self::assertSame([], $store->promotions);
    }

    /**
     * Capability before nonce is not the order that matters; nonce before
     * capability is. Either way, a user who cannot edit the post gets nothing.
     */
    public function testAUserWhoCannotEditThePostCannotPromoteOrRemove(): void
    {
        $GLOBALS['adct_test_post_caps'] = false;

        foreach (
            [
                ['handlePromoteSourceMaterial', EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
                    EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION],
                ['handleRemoveSourceMaterial', EventEditor::REMOVE_SOURCE_MATERIAL_NONCE,
                    EventEditor::REMOVE_SOURCE_MATERIAL_ACTION],
            ] as [$method, $nonce, $action]
        ) {
            $store = new RecordingEditorStore();

            $died = $this->refusedAs(
                $this->editor($store),
                $method,
                403,
                [
                    'event_id' => self::EVENT,
                    'media_id' => self::MEDIA,
                    'attachment_id' => self::ATTACHMENT,
                    'role' => SourceAttachment::ROLE_POSTER,
                ],
                nonce: $nonce,
                expectedAction: $action,
                capabilities: []
            );

            self::assertNotEmpty($died, $method . ' must refuse a user with no capability.');
            self::assertSame([], $store->promotions, $method . ' must not reach the store.');
            self::assertSame([], $store->removals, $method . ' must not reach the store.');
            self::assertSame([], $store->audit, $method . ' must write no audit row.');
        }
    }

    /**
     * The event comes from the request but only after the post type and the
     * capability have been checked against *that* post. Posting somebody else's
     * event id is not enough; the acting user has to be allowed to edit it.
     */
    public function testTheEventIdFromTheRequestIsCheckedAgainstThePostsOwnType(): void
    {
        $GLOBALS['adct_test_post_types'][self::EVENT] = 'post';
        $store = new RecordingEditorStore();

        $died = $this->refusedAs(
            $this->editor($store),
            'handlePromoteSourceMaterial',
            403,
            [
                'event_id' => self::EVENT,
                'attachment_id' => self::ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ],
            nonce: EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
            expectedAction: EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION
        );

        self::assertNotEmpty($died, 'A post that is not an adct_event is not an event.');
        self::assertSame([], $store->promotions);
        self::assertSame([], $store->audit);
    }

    /**
     * REST cannot promote.
     *
     * The event editor accepts a filtered set of meta through the REST API, so
     * without an explicit refusal `source_attachment_ids` would become a second
     * promotion path with no human decision, no separate nonce and no separate
     * audit row. Promotion is only ever the two screens.
     */
    public function testTheRestApiRefusesToSetSourceAttachmentIds(): void
    {
        $request = new \WP_REST_Request('POST', '/adct_event/314');
        $request->set_param('meta', ['source_attachment_ids' => '[{"media_id":1,"role":"poster"}]']);

        $result = $this->editor()->validateRestRequest(new \WP_Post(self::EVENT), $request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('adct_event_source_material_manual', $result->get_error_code());
        self::assertSame(400, $result->get_error_data()['status']);
    }

    // ------------------------------------------------------------ the actions

    /**
     * The happy path: a good nonce, a capable user, a real attachment and a real
     * role reach the store exactly once, and the editor returns to the post.
     */
    public function testAddingSourceMaterialReachesTheStoreAndReturnsToThePost(): void
    {
            // An id that is on offer and not already published. setUp() promotes
            // self::ATTACHMENT, so the file being added here is the second one.
            $this->givenProvenance(messageId: self::MESSAGE, files: [
                ['id' => self::ATTACHMENT + 1, 'filename' => 'september-bulletin.pdf', 'mime_type' => 'application/pdf'],
            ]);

            $store = new RecordingEditorStore();

            $redirect = $this->acting(
                $this->editor($store),
                'handlePromoteSourceMaterial',
                [
                    'event_id' => self::EVENT,
                    'attachment_id' => self::ATTACHMENT + 1,
                    'role' => SourceAttachment::ROLE_POSTER,
                ],
                nonce: EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
                expectedAction: EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION
            );

        self::assertNotNull($redirect, 'A successful action redirects rather than rendering.');
        self::assertCount(1, $store->promotions);
        self::assertSame(
                    [self::EVENT, self::ATTACHMENT + 1, SourceAttachment::ROLE_POSTER],
            $store->promotions[0]
        );
        self::assertCount(1, $store->promotedAuditRows(), 'One promotion is one audit row.');
    }

    /**
     * Removing is a visibility change and the store is asked for it, not the
     * reverse: the route never deletes an attachment itself.
     */
    public function testRemovingSourceMaterialReachesTheStore(): void
    {
        $store = new RecordingEditorStore();

        $redirect = $this->acting(
            $this->editor($store),
            'handleRemoveSourceMaterial',
            ['event_id' => self::EVENT, 'media_id' => self::MEDIA],
            nonce: EventEditor::REMOVE_SOURCE_MATERIAL_NONCE,
            expectedAction: EventEditor::REMOVE_SOURCE_MATERIAL_ACTION
        );

        self::assertNotNull($redirect);
        self::assertSame([[self::EVENT, self::MEDIA]], $store->removals);
        self::assertCount(1, $store->removedAuditRows());
    }

    /**
     * A role outside the closed set is refused rather than coerced. Defaulting
     * here would turn a crafted value into a real one, and a poster role decides
     * what the front page shows.
     */
    public function testAnUnknownRoleIsRefused(): void
    {
        $store = new RecordingEditorStore();

        $died = $this->refusedAs(
            $this->editor($store),
            'handlePromoteSourceMaterial',
            400,
            ['event_id' => self::EVENT, 'attachment_id' => self::ATTACHMENT, 'role' => 'superuser'],
            nonce: EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
            expectedAction: EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION
        );

        self::assertSame([], $store->promotions);
    }

    /**
     * A copy that fails leaves the event alone.
     *
     * The promotion runs outside the publication transaction precisely so that a
     * broken filesystem cannot unpublish an event. The screen therefore reports
     * the failure and changes nothing else.
     */
    public function testAFailedPromotionIsReportedAndChangesNothing(): void
    {
            // An id on offer and not yet published, so the request gets past every
            // guard and the failure it reports is the copy's and not a refusal.
            $this->givenProvenance(messageId: self::MESSAGE, files: [
                ['id' => self::ATTACHMENT + 1, 'filename' => 'september-bulletin.pdf', 'mime_type' => 'application/pdf'],
            ]);

            $store = new RecordingEditorStore();
            $store->promoteFails = true;

            $died = $this->refusedAs(
                $this->editor($store),
                'handlePromoteSourceMaterial',
                500,
                [
                    'event_id' => self::EVENT,
                    'attachment_id' => self::ATTACHMENT + 1,
                    'role' => SourceAttachment::ROLE_POSTER,
                ],
                nonce: EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
                expectedAction: EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION
            );

            self::assertCount(1, $store->promotions, 'The attempt happened.');
            self::assertSame([], $store->audit, 'A failure writes no audit row claiming a promotion.');

            // The publisher is told to retry; they are not told where the file lives.
            self::assertStringNotContainsString('/var/www', $died['message']);
            self::assertStringNotContainsString('copy failed', $died['message']);
        }

        /**
         * A refusal the publisher can act on keeps its own words, so "this file is
         * already published under another role" is distinguishable from "something
         * broke". Collapsing both into one generic 500 would leave a publisher
         * retrying forever against a file that will never promote.
         */
        public function testARefusablePromotionKeepsItsOwnMessage(): void
        {
            $this->givenProvenance(messageId: self::MESSAGE, files: [
                ['id' => self::ATTACHMENT + 1, 'filename' => 'september-bulletin.pdf', 'mime_type' => 'application/pdf'],
            ]);

            $store = new RecordingEditorStore();
            $store->promoteFails = true;
            $store->failureType = 'domain';

            $died = $this->refusedAs(
                $this->editor($store),
                'handlePromoteSourceMaterial',
                409,
                [
                    'event_id' => self::EVENT,
                    'attachment_id' => self::ATTACHMENT + 1,
                    'role' => SourceAttachment::ROLE_POSTER,
                ],
                nonce: EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
                expectedAction: EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION
            );

            self::assertStringContainsString('already published', $died['message']);
        }

    /**
     * An id that is not a positive number never reaches the store. `absint()` of
         * "3 OR 1=1" is 3 and of "abc" is 0, so the check is on the normalised
     * result rather than on the shape of the input.
         *
         * `'-4'` is the interesting case and it is deliberately *not* a 400: it
         * normalises to 4, which is a well-formed id, so it gets the ordinary
         * "not this event's file" refusal instead. What matters for security is the
         * same either way -- it never reaches the store and never writes an audit
         * row -- so the test asserts the status each case actually earns.
         *
         * @param array<string, string> $post
         */
        #[DataProvider('unusableAttachmentIds')]
        public function testAnUnusableIdNeverReachesTheStore(string $bad, int $expectedStatus): void
        {
                $this->givenProvenance(messageId: self::MESSAGE, files: [
                    ['id' => self::ATTACHMENT, 'filename' => 'parish-poster.png', 'mime_type' => 'image/png'],
                ]);

                $store = new RecordingEditorStore();

            $this->refusedAs(
                $this->editor($store),
                'handlePromoteSourceMaterial',
                $expectedStatus,
                [
                    'event_id' => self::EVENT,
                    'attachment_id' => $bad,
                    'role' => SourceAttachment::ROLE_POSTER,
                ],
                nonce: EventEditor::PROMOTE_SOURCE_MATERIAL_NONCE,
                expectedAction: EventEditor::PROMOTE_SOURCE_MATERIAL_ACTION
            );

            self::assertSame([], $store->promotions);
            self::assertSame([], $store->audit, 'A refused id leaves no trace.');
        }

        /**
         * @return array<string, array{0: string, 1: int}>
         */
        public static function unusableAttachmentIds(): array
        {
            return [
                'letters normalise to zero' => ['abc', 400],
                'empty is zero' => ['', 400],
                'zero is not an id' => ['0', 400],
                'a negative sign normalises to a real id' => ['-4', 404],
            ];
        }

    // --------------------------------------------------------------- helpers

    /**
     * Build an editor.
     *
     * `$store` distinguishes "no store" from "a store that records nothing", so a
     * test can assert that the control is absent when the plugin was built
     * without one. `func_num_args()` is what tells those two apart, because a
     * plain `?? new ...` would hand back a recording store for an explicit null
     * and make the "only when wired in" test pass for the wrong reason.
     */
    private function editor(?SourceMaterialStoreInterface $store = null): EventEditor
    {
        $resolved = func_num_args() > 0 ? $store : new RecordingEditorStore();

        return new EventEditor(
            new \ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository(
                new NullDatabase()
            ),
            new \ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository(
                new NullDatabase()
            ),
            new EventValidator(new DateTimeZone('Africa/Johannesburg')),
            new RRulePresetMapper(),
            new DateTimeZone('Africa/Johannesburg'),
            new FrozenClock(new DateTimeZone('Africa/Johannesburg')),
            null,
                $resolved,
                new FakeIntakeAttachmentReader(),
                new FakeCandidateMessageLookup()
            );
        }

    /**
     * Forget the seeded promotion, so a test about the *offer* sees every file
     * the event could still take rather than only the ones not yet published.
     *
     * setUp() seeds one promoted file so the "published with this event" table
     * has something in it. That is right for the table tests and wrong for the
     * offer tests, which are asking a different question.
     */
private function withNothingPromoted(): void
{
    $GLOBALS['adct_publishing_meta'][self::EVENT]['source_attachment_ids'] = json_encode([]);
}

private function renderBox(EventEditor $editor): string
    {
        $editor->registerMetaBox();
        $box = $GLOBALS['adct_test_meta_boxes']['adct_event_source_material'] ?? null;
        self::assertIsArray($box, 'The source material box must be registered to be rendered.');

        ob_start();

        try {
            $editor->renderSourceMaterialMetaBox(new \WP_Post(self::EVENT));
        } finally {
            $markup = (string) ob_get_clean();
        }

        return $markup;
    }

    /**
     * Run an action that must end in a refusal, and hand back the refusal so the
     * test can read the status code out of it.
     *
     * A refusal that is merely "it did not redirect" would pass for the wrong
     * reason, so the status is asserted rather than inferred.
     *
     * @param array<string, mixed> $post
     * @param list<string>|null $capabilities
     * @return array{message: string, status: int}
     */
    private function refusedAs(
        EventEditor $editor,
        string $method,
        int $expectedStatus,
        array $post,
        ?string $nonce = null,
        ?string $expectedAction = null,
        ?array $capabilities = null
    ): array {
        try {
            $this->acting($editor, $method, $post, $nonce, $expectedAction, $capabilities);
            self::fail($method . ' must refuse this request with ' . $expectedStatus . '.');
        } catch (\AdctTestWpDie $died) {
            self::assertSame($expectedStatus, $died->status, $died->getMessage());

            return ['message' => $died->getMessage(), 'status' => $died->status];
        } catch (\AdctTestNonceRefused $refused) {
            // WordPress rejects a bad nonce before any handler runs, so there is
            // no status of its own; 403 is what the browser gets.
            self::assertSame(403, $expectedStatus, 'A refused nonce is a 403.');

            return ['message' => $refused->getMessage(), 'status' => 403];
        }
    }

    /**
         * Give the event a provenance chain and the intake reader an answer.
         *
         * The editor asks two questions to build the offer: which message was this
         * event published from, and which files arrived on that message. Both are
         * answered from globals here so the test states the scenario plainly rather
         * than standing up two repositories.
         *
         * @param list<array{id: int, filename: string, mime_type: string}> $files
         */
        private function givenProvenance(int $messageId, array $files): void
        {
            $GLOBALS['adct_publishing_meta'][self::EVENT]['source_candidate_id'] = (string) self::CANDIDATE;
            $GLOBALS['adct_test_candidate_messages'] = [self::CANDIDATE => $messageId];
            $GLOBALS['adct_test_promotable_attachments'] = [$messageId => $files];
        }

        /**
         * Run an action with a prepared request.
     *
     * A `$nonce` of null means "send no nonce field at all", which is the
     * request the issue asks to be rejected. A string means "send a nonce under
     * that field name, holding whatever WordPress would mint for
     * `$expectedAction`" -- the stub compares the action, which is how a nonce
     * minted for the *other* control is refused.
     *
     * @param array<string, mixed> $post
     * @param list<string>|null $capabilities
     */
    private function acting(
        EventEditor $editor,
        string $method,
        array $post,
        ?string $nonce = null,
        ?string $expectedAction = null,
        ?array $capabilities = null
    ): ?string {
            // A real request carries strings in $_POST; PHP never delivers an int.
            // Coercing here keeps every call site able to write `self::EVENT` and
            // stops a passing integer from quietly testing a stricter rule than
            // production sees.
            $_POST = array_map(static fn ($value) => is_string($value) ? $value : (string) $value, $post);

                    if ($nonce !== null) {
                        $_POST[$nonce] = $expectedAction ?? 'nonce';
                    }

                    unset($GLOBALS['adct_test_nonce_should_fail']);

        // Drive the stub's idea of which action a nonce is good for. The stub
        // compares this with the action the handler asks about, so a nonce
        // minted for the remove control cannot authorise an add.
        $GLOBALS['adct_test_nonce_expected_action'] = $expectedAction;

        if ($capabilities !== null) {
            $GLOBALS['adct_test_wp_caps'] = $capabilities;
            $GLOBALS['adct_test_post_caps'] = $capabilities !== [];
        }

        try {
                    $editor->{$method}();
                } catch (\AdctTestRedirect $redirected) {
                    return $redirected->getMessage();
                }

                return null;
            }
                }

                /**
                 * An intake attachment reader that answers from globals.
 *
 * The editor asks it exactly one question -- which files arrived on the message
 * this event was published from -- and this keeps that answer in the test where
 * the scenario can be stated in a line. `findStoredById()` answers "nothing",
 * because the editor's add path has no business asking for a stored row by id:
 * that is the promotion's own business, inside the store.
 */
final class FakeIntakeAttachmentReader implements IntakeAttachmentReaderInterface
{
    public function findStoredById(int $attachmentId): ?array
    {
        foreach ($GLOBALS['adct_test_all_stored_attachments'] ?? [] as $row) {
            if ((int) ($row['id'] ?? 0) === $attachmentId) {
                return [
                    'id' => $attachmentId,
                    'filename' => (string) ($row['filename'] ?? ''),
                    'mime_type' => (string) ($row['mime_type'] ?? ''),
                    'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                    'storage_path' => (string) ($row['storage_path'] ?? ''),
                ];
            }
        }

        return null;
    }

    public function findPromotableForMessage(int $messageId): array
    {
        $rows = [];

        foreach ($GLOBALS['adct_test_promotable_attachments'][$messageId] ?? [] as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'filename' => (string) $row['filename'],
                'mime_type' => (string) $row['mime_type'],
                'size_bytes' => 4096,
                'storage_path' => 'inbox/2026/03/message-' . $messageId . '/' . (string) $row['filename'],
            ];
        }

        return $rows;
    }
}

/**
 * The candidate-to-message join, answered from globals.
 *
 * The editor depends on `CandidateSourceMessageInterface`, not on the concrete
 * repository, so this fake implements exactly one method and no more. That is the
 * point of the port: the screen cannot widen its own reach, because the only thing
 * it is handed is a question about one candidate and nothing else.
 *
 * @implements \ADCT\ParishIntake\Core\Ports\CandidateSourceMessageInterface
 */
final class FakeCandidateMessageLookup implements \ADCT\ParishIntake\Core\Ports\CandidateSourceMessageInterface
{
    public function sourceMessageIdForCandidate(int $candidateId): ?int
    {
        $messages = $GLOBALS['adct_test_candidate_messages'] ?? [];

        return isset($messages[$candidateId]) ? (int) $messages[$candidateId] : null;
    }
}

/**
 * A store that records what it was asked to do instead of copying anything.
 *
 * The audit assertions are made here rather than in a fake audit writer because
 * "every promotion and every removal writes exactly one row" is a property of
 * the port's implementation, and a fake at this seam would be asserting its own
 * behaviour back at itself. These tests are about the screen, so the screen's
 * fake records the rows the real store would have written.
 */
final class RecordingEditorStore implements SourceMaterialStoreInterface
{
    private const MEDIA = 900;

    /** @var list<array{int, int, string}> */
    public array $promotions = [];

    /** @var list<array{int, int}> */
    public array $removals = [];

    public bool $promoteFails = false;

    /**
     * What kind of failure the fake raises.
     *
     * The handler treats the two differently on purpose. A `DomainException` is
     * a refusal the publisher can act on -- this file is not promotable, this one
     * is already promoted under another role -- and is reported as 409 with its
     * own message. Anything else is the filesystem or the database falling over
     * underneath us, which is reported as a plain 500 with a message that tells
     * the publisher to retry and tells nobody else what happened.
     */
    public string $failureType = 'infrastructure';

    /** @var list<array{action: string, user_id: int, subject_type: string, subject_id: int}> */
    public array $audit = [];

    public function promote(int $eventId, int $attachmentId, string $role): SourceAttachment
    {
        $this->promotions[] = [$eventId, $attachmentId, $role];

        if ($this->promoteFails) {
                    throw $this->failureType === 'domain'
                        ? new DomainException('That file is already published with this event.')
                        : new \RuntimeException('copy failed: /var/www/uploads/adct-inbox/x.pdf');
                }

        $this->audit[] = [
            'action' => AuditAction::SOURCE_MATERIAL_PROMOTED,
            'user_id' => (int) ($GLOBALS['adct_test_current_user']->ID ?? 0),
            'subject_type' => AuditSubjectType::EVENT,
            'subject_id' => $eventId,
        ];

        return new SourceAttachment($attachmentId, self::MEDIA, $role, 'parish-poster.png');
    }

    public function remove(int $eventId, int $mediaId): void
    {
        $this->removals[] = [$eventId, $mediaId];

        $this->audit[] = [
            'action' => AuditAction::SOURCE_MATERIAL_REMOVED,
            'user_id' => (int) ($GLOBALS['adct_test_current_user']->ID ?? 0),
            'subject_type' => AuditSubjectType::EVENT,
            'subject_id' => $eventId,
        ];
    }

    /**
     * Read the ordered post meta rather than returning a fixed list, so that a
     * test which seeds two promoted items sees two, and one which seeds none
     * sees none. A fake with a hard-coded answer would let the empty-state test
     * pass for the wrong reason.
     */
    public function forEvent(int $eventId): array
    {
        $raw = $GLOBALS['adct_publishing_meta'][$eventId]['source_attachment_ids'] ?? '';
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($decoded)) {
            return [];
        }

        $items = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry) || ! isset($entry['media_id'], $entry['role'], $entry['attachment_id'])) {
                continue;
            }

            $items[] = new SourceAttachment(
                (int) $entry['attachment_id'],
                (int) $entry['media_id'],
                (string) $entry['role'],
                (string) ($entry['original_filename'] ?? '')
            );
        }

        return $items;
    }

    public function promotedAuditRows(): array
    {
        return array_values(array_filter(
            $this->audit,
            static fn (array $row): bool => $row['action'] === AuditAction::SOURCE_MATERIAL_PROMOTED
        ));
    }

    public function removedAuditRows(): array
    {
        return array_values(array_filter(
            $this->audit,
            static fn (array $row): bool => $row['action'] === AuditAction::SOURCE_MATERIAL_REMOVED
        ));
    }
}

    /**
     * A clock that does not move.
     *
     * The rule about dates in this project is to inject one rather than read "now",
     * and that is most easily enforced by a clock that cannot be right: if a value
     * came from the wall clock it would differ from this, and the test would fail
     * rather than pass by coincidence.
     */
    final class FrozenClock implements \ADCT\ParishIntake\Core\Ports\ClockInterface
    {
        private DateTimeImmutable $now;

        public function __construct(DateTimeZone $timezone)
        {
            $this->now = new DateTimeImmutable('2026-03-01 08:00:00', $timezone);
        }

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }

        public function timezone(): DateTimeZone
        {
            return $this->now->getTimezone();
        }
    }

    /**
     * A database that answers nothing.
     *
     * The source material control does not read the directory tables, so the
     * repositories are only here to satisfy the editor's constructor. getResults()
     * returning no rows is a real answer for an empty table rather than a null that
     * a caller would have to defend against.
     */
    final class NullDatabase implements \ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface
    {
        public function prefix(): string
        {
            return 'wp_adct_pi_';
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            return $query;
        }

        public function query(string $query): int|false
        {
            return 0;
        }

        public function getRow(string $query): ?array
        {
            return null;
        }

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
            return 0;
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
    }