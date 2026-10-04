<?php
declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Events {
    require_once dirname(__DIR__, 4) . '/tests/Support/WordPressStubs.php';
    // The capability gate calls current_user_can() and the audit trail resolves
    // an actor from \WP_User. Both live in the capability stubs, and this file
    // says so outright rather than depending on PHPUnit's load order.
    require_once dirname(__DIR__, 4) . '/tests/Support/WordPressCapabilityStubs.php';

    use ADCT\ParishIntake\Core\Attachments\SourceMaterialPromotion;
    use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
    use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
    use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
    use ADCT\ParishIntake\Core\Audit\AuditWriter;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\SourceMaterialCopierInterface;
    use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
    use ADCT\ParishIntake\WordPress\Attachments\SourceMaterialAuditTrail;
    use ADCT\ParishIntake\WordPress\Audit\ActorResolver;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
    use ADCT\ParishIntake\WordPress\Events\EventSourceMaterialEditor;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * Issue #172: adding and removing an event's source material from the event
     * editor.
     *
     * This is the second surface that turns a parish's private file into a file
     * the whole internet can fetch, and the one with the least obvious
     * constraints, because the person pressing the button is usually editing an
     * event rather than reviewing a candidate. Most of what is pinned here is
     * about refusals:
     *
     * - **The capability gate runs before the nonce**, so somebody who cannot
     *   edit the event never reaches the copy, and no audit row is written
     *   attributing a promotion to them.
     * - **The nonce is the route's own**, checked before any read.
     * - **The event must really be an event.** A crafted `event_id` naming a
     *   page is refused rather than having its attachments rewritten.
     * - **The candidate is derived, never posted.** The handler reads no
     *   candidate id out of the request, so no form can name another parish's
     *   notice and publish from it.
     * - **Nothing is published unless a person ticks a box**, and an empty
     *   submit says so rather than failing.
     * - **A refused selection is refused whole.**
     * - **Removal never deletes a file.** It takes the reference off the event
     *   and releases the featured image; the media-library row stays.
     *
     * Every stub the class under test calls unqualified is declared below in
     * the `ADCT\ParishIntake\WordPress\Events` namespace, because that is where
     * PHP looks first. tests/Support/WordPressEventEditorStubs.php declares
     * add_meta_box(), remove_meta_box() and current_user_can() in that namespace,
     * so those three are declared there instead and are not repeated below.
     */
    final class EventSourceMaterialEditorTest extends TestCase
    {
        private array $savedPost;

        private array $savedGet;

        protected function setUp(): void
        {
            parent::setUp();

            $this->savedPost = $_POST;
            $this->savedGet = $_GET;

            $GLOBALS['adct_test_current_user_id'] = 9;
            $GLOBALS['adct_test_current_user'] = new \WP_User(9, 'dean@example.test');
            $GLOBALS['adct_test_wp_caps'] = [9 => [Capabilities::EDIT_PUBLISHED_EVENTS]];
            // WordPressEventEditorStubs.php answers current_user_can() from
            // adct_test_post_caps alone, so this is what grants 'edit_post'.
            $GLOBALS['adct_test_post_caps'] = true;
            $GLOBALS['adct_test_nonce_checks'] = [];
            $GLOBALS['adct_test_nonce_fields'] = [];
            $GLOBALS['adct_test_redirect'] = null;
            $GLOBALS['adct_test_is_admin'] = true;
            $GLOBALS['adct_test_meta_boxes'] = [];
            $GLOBALS['adct_test_meta_boxes_removed'] = [];
            $GLOBALS['adct_test_post_types'] = [EditorTestIds::EVENT => 'adct_event'];
            $GLOBALS['adct_test_edit_links'] = [
                EditorTestIds::EVENT => 'https://adct.example.test/wp-admin/post.php?post=4312&action=edit',
            ];
            $GLOBALS['adct_test_redirects_allowed'] = [];
            $GLOBALS['adct_test_transients'] = [];
            $GLOBALS['adct_test_posts'] = [];
            $GLOBALS['event_editor_logs'] = [];

            unset($GLOBALS['adct_test_nonce_should_fail']);
        }

        protected function tearDown(): void
        {
            $_POST = $this->savedPost;
            $_GET = $this->savedGet;

            unset(
            $GLOBALS['adct_test_wp_caps'],
            $GLOBALS['adct_test_post_caps'],
            $GLOBALS['adct_test_current_user'],
            $GLOBALS['adct_test_current_user_id'],
            $GLOBALS['adct_test_nonce_checks'],
            $GLOBALS['adct_test_nonce_fields'],
            $GLOBALS['adct_test_redirect'],
            $GLOBALS['adct_test_is_admin'],
            $GLOBALS['adct_test_meta_boxes'],
            $GLOBALS['adct_test_meta_boxes_removed'],
            $GLOBALS['adct_test_post_types'],
            $GLOBALS['adct_test_edit_links'],
            $GLOBALS['adct_test_redirects_allowed'],
            $GLOBALS['adct_test_transients'],
            $GLOBALS['adct_test_posts'],
            $GLOBALS['event_editor_logs'],
            $GLOBALS['adct_test_nonce_should_fail']
            );

            parent::tearDown();
        }

        /**
                     * The action and nonce names are a cross-file contract: `Plugin.php`
                     * builds the `admin_post_` hook from the constants, the forms post to
                     * them, and nothing would notice the two drifting apart except a button
                     * that 404s.
                     */
        public function testTheAddAndRemoveRoutesAreNamedDistinctly(): void
        {
            self::assertSame('adct_pi_event_add_source_material', EventSourceMaterialEditor::ADD_ACTION);
            self::assertSame('adct_pi_event_add_source_nonce', EventSourceMaterialEditor::ADD_NONCE);
            self::assertSame('adct_pi_event_remove_source_material', EventSourceMaterialEditor::REMOVE_ACTION);
            self::assertSame('adct_pi_event_remove_source_nonce', EventSourceMaterialEditor::REMOVE_NONCE);

            self::assertNotSame(
            EventSourceMaterialEditor::ADD_ACTION,
            EventSourceMaterialEditor::REMOVE_ACTION,
            'Add and remove do opposite things and one takes a file off the public site. '
            . 'One action with a mode would mean pressing Enter in a filename field could remove.'
            );

            self::assertNotSame(EventSourceMaterialEditor::ADD_NONCE, EventSourceMaterialEditor::REMOVE_NONCE);
        }

        /**
                     * The box is registered on the event screen and nowhere else, so it
                     * cannot appear on a screen that has no event behind it.
                     */
        public function testTheBoxIsRegisteredOnTheEventScreenOnly(): void
        {
            $this->editor(new EditorQueueDatabase())->registerMetaBox();

            self::assertCount(1, $GLOBALS['adct_test_meta_boxes']);

            // WordPressEventEditorStubs.php keys the recording by box id, so
            // that the audit box and this one can be told apart.
            self::assertArrayHasKey(
                EventSourceMaterialEditor::META_BOX_ID,
                $GLOBALS['adct_test_meta_boxes']
            );

            $box = $GLOBALS['adct_test_meta_boxes'][EventSourceMaterialEditor::META_BOX_ID];

            self::assertSame('adct_event', $box['screen']);
            self::assertSame('normal', $box['context']);
            self::assertSame('Poster and bulletin', $box['title']);
        }

        /**
                     * Somebody who cannot edit the event must not reach the copy, the
                     * database or the nonce, and must leave no audit row behind naming them.
                     */
        public function testSomeoneWhoCannotEditTheEventCannotPublishSourceMaterial(): void
        {
            $GLOBALS['adct_test_wp_caps'] = [9 => [Capabilities::MANAGE_SETTINGS]];
            $GLOBALS['adct_test_post_caps'] = false;
            $database = new EditorQueueDatabase();
            $copier = new EditorRecordingCopier();
            $this->postAnAdd();

            try {
                $this->editor($database, $copier)->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(403, $refused->status);
                self::assertSame([], $database->queries, 'A refused request must not read or write anything.');
                self::assertSame([], $copier->calls, 'And it must not copy a file.');
                self::assertSame([], $database->auditRows, 'And it must leave no audit row.');

                return;
            }

            self::fail('Editing the event is required to publish source material beside it.');
        }

        /**
                     * The capability gate is checked before the nonce, so an unauthorised
                     * caller never even makes WordPress demand a word — and, more to the
                     * point, never reaches the copy that a valid nonce would have permitted.
                     */
        public function testTheCapabilityIsCheckedBeforeTheNonce(): void
        {
            $GLOBALS['adct_test_wp_caps'] = [9 => [Capabilities::MANAGE_SETTINGS]];
            $GLOBALS['adct_test_post_caps'] = false;
            $copier = new EditorRecordingCopier();
            $this->postAnAdd();

            try {
                $this->editor(new EditorQueueDatabase(), $copier)->handleAdd();
            } catch
            (\AdctTestWpDie) {
                self::assertSame(
                [],
                $GLOBALS['adct_test_nonce_checks'],
                'The nonce is checked second: somebody who cannot edit the event has no word to present.'
                );

                return;
            }

            self::fail('Editing the event is required.');
        }

        /**
                     * A forged or missing nonce stops the handler before it reads anything.
                     */
        public function testAddingDemandsItsOwnNonceBeforeReadingAnything(): void
        {
            $GLOBALS['adct_test_nonce_should_fail'] = true;
            $database = new EditorQueueDatabase();
            $copier = new EditorRecordingCopier();
            $this->postAnAdd();

            try {
                $this->editor($database, $copier)->handleAdd();
            } catch
            (\AdctTestNonceRefused) {
                self::assertSame([], $database->queries);
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('The add route must require its own nonce.');
        }

        /**
                     * The exact action and nonce name the route demands. A handler that
                     * checked some other form's pair would pass every other assertion here.
                     */
        public function testAddingAsksForItsOwnActionAndNonceName(): void
        {
            $this->postAnAdd();

            try {
                $this->editor()->handleAdd();
            } catch
            (\AdctTestRedirect) {
                self::assertSame(
                [['action' => EventSourceMaterialEditor::ADD_ACTION, 'name' => EventSourceMaterialEditor::ADD_NONCE]],
                $GLOBALS['adct_test_nonce_checks']
                );

                return;
            }

            self::fail('The add route must end in a redirect.');
        }

        /**
                     * The whole path: the ticked file is copied, recorded against the event,
                     * given its role, and written to the audit trail.
                     */
        public function testTheTickedFileIsCopiedRecordedAndGivenItsRole(): void
        {
            $database = new EditorQueueDatabase();
            $copier = new EditorRecordingCopier();
            $store = new EditorRecordingStore();
            $this->postAnAdd();

            $this->redirectsTo(function () use ($database, $copier, $store): void {
                $this->editor($database, $copier, $store)->handleAdd();
            }
            );

            self::assertSame(
            [[
            'event_id' => EditorTestIds::EVENT,
            'storage_name' => EditorTestIds::STORAGE_NAME,
            'original_name' => 'poster.jpg',
            'role' => SourceMaterialRole::POSTER,
            ]],
            $copier->calls,
            'The stored file is copied into the media library under the role the reviewer chose.'
            );

            self::assertSame([EditorTestIds::EVENT], $store->writtenEvents, 'The event that was written.');

            $references = $store->forEvent(EditorTestIds::EVENT);

            self::assertCount(1, $references);
            self::assertSame(EditorTestIds::MEDIA_ATTACHMENT, $references[0]->attachmentId);
            self::assertSame(SourceMaterialRole::POSTER, $references[0]->role);
            self::assertSame('poster.jpg', $references[0]->originalName);

            self::assertCount(1, $database->auditRows, 'Publishing writes one audit row.');

            $row = $database->auditRows[0];

            self::assertSame(AuditAction::SOURCE_MATERIAL_PROMOTED->value, $row['action']);
            // The subject type is the audit vocabulary's "event", not the post-type
            // slug: the trail is read by subject, not by table.
            self::assertSame(AuditSubjectType::EVENT, $row['subject_type']);
            self::assertSame(EditorTestIds::EVENT, $row['subject_id']);
            self::assertSame('dean@example.test', $row['actor'], 'The actor is the signed-in person.');
            self::assertSame(EditorTestIds::ATTACHMENT, $row['details']['intake_attachment_id'] ?? null);
        }

        /**
                     * A poster also becomes the event's featured image, which is what makes
                     * it show up on the listing card rather than only on the event page.
                     */
        public function testAPublishedPosterAlsoBecomesTheEventFeaturedImage(): void
        {
            $copier = new EditorRecordingCopier();
            $this->postAnAdd();

            $this->redirectsTo(function () use ($copier): void {
                $this->editor(copier: $copier)->handleAdd();
            }
            );

            self::assertSame(
            [EditorTestIds::MEDIA_ATTACHMENT],
            $copier->featuredImageCalls,
            'A poster is the event picture; a bulletin is not.'
            );
        }

        /**
                     * The reviewer is sent back to the event and told what happened, rather
                     * than left on an admin-post.php response.
                     */
        public function testTheReviewerIsRedirectedBackToTheEventAndToldWhatHappened(): void
        {
            $this->postAnAdd();

            $this->redirectsTo(function (): void {
                $this->editor()->handleAdd();
            }
            );

            self::assertSame(
            'https://adct.example.test/wp-admin/post.php?post=' . EditorTestIds::EVENT . '&action=edit',
            $GLOBALS['adct_test_redirect']
            );

            $notice = $this->noticeFor(EditorTestIds::EVENT);

            self::assertNotNull($notice);
            self::assertTrue($notice['success']);
            self::assertStringContainsString('Published 1 file', $notice['message']);
            self::assertStringContainsString(
            'Anyone can fetch it by its address',
            $notice['message'],
            'A reviewer publishing a parish file for the first time deserves to be told it is public.'
            );
        }

        /**
                     * An `redirect_to` the form asked for is honoured, but only after
                     * `wp_validate_redirect()` has agreed it is one of this site's own
                     * addresses. An admin POST that redirects anywhere at all is an open
                     * redirect.
                     */
        public function testAnOffSiteReturnUrlIsNotFollowed(): void
        {
            // This site's own host is allowed; the attacker's is not on the list.
            $GLOBALS['adct_test_redirects_allowed'] = ['adct.example.test'];

            $this->postAnAdd();

            // Also after postAnAdd(), for the same reason.
            $_POST['redirect_to'] = 'https://attacker.example.test/harvest';
            $this->redirectsTo(function (): void {
                $this->editor()->handleAdd();
            }
            );

            self::assertSame(
            'https://adct.example.test/wp-admin/post.php?post=' . EditorTestIds::EVENT . '&action=edit',
            $GLOBALS['adct_test_redirect'],
            'An off-site return address falls back to the event screen rather than being followed.'
            );
        }

        /**
                     * A return address that is one of this site's own is followed, so a
                     * reviewer who arrived from somewhere else goes back there.
                     */
        public function testAnOnSiteReturnUrlIsFollowed(): void
        {
            // Hosts, not URLs: wp_validate_redirect() checks the parsed host
            // against this list, so a whole URL here could never match.
            $GLOBALS['adct_test_redirects_allowed'] = ['adct.example.test'];

            $this->postAnAdd();

            // After postAnAdd(), which replaces $_POST wholesale: set beforehand it
            // was silently dropped and the test passed for the wrong reason.
            $_POST['redirect_to'] = 'https://adct.example.test/wp-admin/edit.php?post_type=adct_event';
            $this->redirectsTo(function (): void {
                $this->editor()->handleAdd();
            }
            );

            self::assertSame(
            'https://adct.example.test/wp-admin/edit.php?post_type=adct_event',
            $GLOBALS['adct_test_redirect']
            );
        }

        /**
                     * A post id that is not an event is refused before anything is read. A
                     * crafted form naming a page must not have that page's attachments
                     * rewritten.
                     */
        public function testAFormNamingSomethingThatIsNotAnEventIsRefused(): void
        {
            $GLOBALS['adct_test_post_types'] = [EditorTestIds::FOREIGN_EVENT => 'page'];
            $database = new EditorQueueDatabase();
            $copier = new EditorRecordingCopier();
            $_POST = [
            'event_id' => (string) EditorTestIds::FOREIGN_EVENT,
            'selected' => [(string) EditorTestIds::ATTACHMENT],
            'roles' => [(string) EditorTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            try {
                $this->editor($database, $copier)->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $database->queries);
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('Only an event may carry source material.');
        }

        /**
                     * @param mixed $eventId
                     */
        #[DataProvider('unusableEventIds')]
        public function testAFormWithoutAUsableEventIdIsRefused(mixed $eventId): void
        {
            $database = new EditorQueueDatabase();
            $copier = new EditorRecordingCopier();
            $_POST = [
            'event_id' => $eventId,
            'selected' => [(string) EditorTestIds::ATTACHMENT],
            'roles' => [(string) EditorTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            try {
                $this->editor($database, $copier)->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('An event id that is not one cannot be acted on.');
        }

        /**
                     * @return array<string, array{mixed}>
                     */
        public static function unusableEventIds(): array
        {
            return [
            'missing' => [null],
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-5'],
            'words' => ['abc'],
            'array' => [['4312']],
            ];
        }

        /**
                     * An event with no intake origin has nothing to publish, and saying so
                     * with 409 is more honest than an empty form that silently does nothing.
                     */
        public function testAnEventThatWasNotPublishedFromANoticeHasNothingToPublish(): void
        {
            $database = new EditorQueueDatabase();
            $database->sourceCandidate = null;
            $copier = new EditorRecordingCopier();
            $this->postAnAdd();

            try {
                $this->editor($database, $copier)->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(409, $refused->status);
                self::assertStringContainsString('not published from a notice', $refused->getMessage());
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('An event with no intake origin has no files to publish.');
        }

        /**
                     * Nothing ticked publishes nothing. This is the default state of the
                     * form, so an accidental submit must not copy a file.
                     */
        public function testSubmittingTheFormWithNothingTickedPublishesNothing(): void
        {
            $copier = new EditorRecordingCopier();
            $store = new EditorRecordingStore();
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'selected' => [],
            'roles' => [],
            ];

            $this->redirectsTo(function () use ($copier, $store): void {
                $this->editor(copier: $copier, store: $store)->handleAdd();
            }
            );

            self::assertSame([], $copier->calls, 'Nothing is copied.');
            self::assertSame([], $store->writes, 'Nothing is written to the event.');

            $notice = $this->noticeFor(EditorTestIds::EVENT);

            self::assertNotNull($notice);
            self::assertStringContainsString('Nothing was selected', $notice['message']);
        }

        /**
                     * No `selected` key at all is the same request as an empty one, and is
                     * refused the same way. `selected` as a string rather than an array is
                     * what a hand-edited form sends.
                     */
        public function testASelectionThatIsNotAListOfIdsPublishesNothing(): void
        {
            $copier = new EditorRecordingCopier();
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'selected' => 'poster.jpg',
            ];

            $this->redirectsTo(function () use ($copier): void {
                $this->editor(copier: $copier)->handleAdd();
            }
            );

            self::assertSame([], $copier->calls, 'A string is not a file id, so nothing is published.');
        }

        /**
                     * @param mixed $role
                     */
        #[DataProvider('unusableRoles')]
        public function testARoleTheFilesTypeCannotFillIsRefused(mixed $role, int $attachmentId): void
        {
            $database = new EditorQueueDatabase();
            $copier = new EditorRecordingCopier();
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'selected' => [(string) $attachmentId],
            'roles' => [(string) $attachmentId => $role],
            ];

            try {
                $this->editor($database, $copier)->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls, 'A refused role copies nothing.');
                self::assertSame([], $database->auditRows, 'And records nothing.');

                return;
            }

            self::fail('A role that cannot apply to the file must be refused, not guessed at.');
        }

        /**
                     * @return array<string, array{mixed}>
                     */
                public static function unusableRoles(): array
        {
            // The provider names the attachment as well as the role, because
            // "a pdf as a poster" only refuses when the pdf is the file chosen:
            // pointing that row at the jpeg made it succeed instead of fail.
            return [
                'absent' => [null, EditorTestIds::ATTACHMENT],
                'empty' => ['', EditorTestIds::ATTACHMENT],
                'not a role' => ['album-art', EditorTestIds::ATTACHMENT],
                'array' => [['poster'], EditorTestIds::ATTACHMENT],
                'a pdf as a poster' => [SourceMaterialRole::POSTER, EditorTestIds::SECOND_ATTACHMENT],
                'an image as a bulletin' => [SourceMaterialRole::BULLETIN, EditorTestIds::ATTACHMENT],
                ];
        }

        /**
                     * A pdf cannot be a poster. The data provider above proves the refusal;
                     * this proves the pair the refusal depends on — `image/jpeg` may be a
                     * poster, `application/pdf` may not — so the refusal cannot pass because
                     * the role list itself emptied out.
                     */
        public function testTheRoleRefusalRestsOnWhatTheTypeCanActuallyFill(): void
        {
            self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::POSTER, 'image/jpeg'));
            self::assertFalse(SourceMaterialRole::allows(SourceMaterialRole::POSTER, 'application/pdf'));
            self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::DOCUMENT, 'application/pdf'));
        }

        /**
                     * An attachment that is not on this notice is refused. The id comes from
                     * a form, so this is the guard that stops one parish publishing another
                     * parish's file.
                     */
        public function testAFileThatIsNotOnTheNoticeIsRefused(): void
        {
            $copier = new EditorRecordingCopier();
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'selected' => [(string) EditorTestIds::FOREIGN_ATTACHMENT],
            'roles' => [(string) EditorTestIds::FOREIGN_ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            try {
                $this->editor(copier: $copier)->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame(
                    'One of the files you chose is not on this notice.',
                    $refused->getMessage(),
                    'Refused by the on-this-notice guard specifically. Without that guard the missing row falls through to the role check and is refused as a role mismatch instead, which is true but says the wrong thing.'
                );
                self::assertSame([], $copier->calls, 'And nothing was copied.');

                return;
            }

            self::fail('A file that did not arrive on this notice must not be published beside it.');
        }

        /**
                     * One refused selection stops the batch before any of it is copied, so a
                     * single press cannot half-promote an event.
                     */
        public function testOneRefusedSelectionStopsTheWholeBatchBeforeAnyCopy(): void
        {
            $copier = new EditorRecordingCopier();
            $database = new EditorQueueDatabase();
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'selected' => [
            (string) EditorTestIds::ATTACHMENT,
            (string) EditorTestIds::FOREIGN_ATTACHMENT,
            ],
            'roles' => [
            (string) EditorTestIds::ATTACHMENT => SourceMaterialRole::POSTER,
            (string) EditorTestIds::FOREIGN_ATTACHMENT => SourceMaterialRole::POSTER,
            ],
            ];

            try {
                $this->editor($database, $copier)->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls, 'The good file in the batch is not copied either.');
                self::assertSame([], $database->auditRows);

                return;
            }

            self::fail('A refused selection refuses the batch.');
        }

    /**
     * A copy that fails leaves no audit row claiming a file was published.
     *
     * The notice is written to a transient and the redirect carries it back to
     * the event screen, so the wording of the notice is the only place the
     * failure can be seen. If the transient said the copy worked, the redirect
     * would tell the operator the file is published when it is not.
     */
    public function testAFailedCopyIsReportedRatherThanClaimed(): void
    {
        $database = new EditorQueueDatabase();
        $copier = new EditorRecordingCopier();
        $copier->failOn = EditorTestIds::STORAGE_NAME;
        $store = new EditorRecordingStore();
        $this->postAnAdd();

        try {
            $this->editor($database, $copier, $store)->handleAdd();
            self::fail('The route must end by returning to the event screen.');
        } catch (\AdctTestWpDie $failure) {
            self::fail('A failed copy with an edit screen to return to is a notice, not a fatal: it died with ' . $failure->getMessage());
        } catch (\AdctTestRedirect $redirect) {
            self::assertStringContainsString(
                'post=' . EditorTestIds::EVENT,
                $redirect->getMessage(),
                'The operator goes back to the event they were editing.'
            );
        }

        self::assertSame([], $database->auditRows, 'Nothing was published, so nothing is recorded.');
        self::assertSame([], $store->forEvent(EditorTestIds::EVENT), 'And the event keeps the list it had.');

        $notice = $this->noticeFor(EditorTestIds::EVENT);

        self::assertNotNull($notice, 'The operator is told the copy failed.');
        self::assertFalse($notice['success'], 'And that it failed, not that it worked.');
        self::assertStringContainsString(
            'could not be published',
            $notice['message'],
            'The wording says the file is not there.'
        );
        self::assertNotSame([], $GLOBALS['event_editor_logs'], 'The failure is logged for the operator.');
    }

        /**
                     * Adding copies; the intake row and the stored file both stay. This is
                     * the same property the review queue's promote route has, asserted here
                     * because it is the one thing an editor could get wrong independently.
                     */
        public function testAddingCopiesRatherThanMovesTheOriginal(): void
        {
            $copier = new EditorRecordingCopier();
            $this->postAnAdd();

            $this->redirectsTo(function () use ($copier): void {
                $this->editor(copier: $copier)->handleAdd();
            }
            );

            self::assertSame(
            [EditorTestIds::STORAGE_NAME],
            array_column($copier->calls, 'storage_name'),
            'The stored file is named, not consumed: nothing here removes it.'
            );
        }

        /**
                     * The removal route demands its own nonce before touching anything.
                     */
        public function testRemovingDemandsItsOwnNonceBeforeTouchingAnything(): void
        {
            $GLOBALS['adct_test_nonce_should_fail'] = true;
            $store = new EditorRecordingStore();
            $store->seed(EditorTestIds::EVENT, [EditorTestIds::publishedPoster()]);
            $this->postARemove();

            try {
                $this->editor(store: $store)->handleRemove();
            } catch
            (\AdctTestNonceRefused) {
                self::assertSame([], $store->writes, 'The event is untouched.');

                return;
            }

            self::fail('The remove route must require its own nonce.');
        }

        /**
                     * The exact action and nonce name removal demands, so a forged add form
                     * cannot be replayed as a remove.
                     */
        public function testRemovingAsksForItsOwnActionAndNonceName(): void
        {
            $store = new EditorRecordingStore();
            $store->seed(EditorTestIds::EVENT, [EditorTestIds::publishedPoster()]);
            $this->postARemove();

            $this->redirectsTo(function () use ($store): void {
                $this->editor(store: $store)->handleRemove();
            }
            );

            self::assertSame(
            [[
            'action' => EventSourceMaterialEditor::REMOVE_ACTION,
            'name' => EventSourceMaterialEditor::REMOVE_NONCE,
            ]],
            $GLOBALS['adct_test_nonce_checks']
            );
        }

        /**
                     * Somebody who cannot edit the event cannot take a file off it.
                     */
        public function testSomeoneWhoCannotEditTheEventCannotRemoveSourceMaterial(): void
        {
            $GLOBALS['adct_test_wp_caps'] = [9 => [Capabilities::MANAGE_SETTINGS]];
            $GLOBALS['adct_test_post_caps'] = false;
            $store = new EditorRecordingStore();
            $store->seed(EditorTestIds::EVENT, [EditorTestIds::publishedPoster()]);
            $copier = new EditorRecordingCopier();
            $this->postARemove();

            try {
                $this->editor(copier: $copier, store: $store)->handleRemove();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(403, $refused->status);
                self::assertSame([], $copier->calls, 'Nothing is detached.');
                self::assertSame([], $store->writes, 'And the event is not rewritten.');

                return;
            }

            self::fail('Editing the event is required to take a file off it.');
        }

        /**
                     * The whole removal path: the reference comes off the event, the
                     * featured image is released, an audit row is written, and — the point
                     * of the whole method — the file is still there afterwards.
                     */
        public function testRemovingTakesTheFileOffTheEventAndKeepsTheFile(): void
        {
            $database = new EditorQueueDatabase();
            $copier = new EditorRecordingCopier();
            $store = new EditorRecordingStore();
            $store->seed(EditorTestIds::EVENT, [EditorTestIds::publishedPoster()]);
            $this->postARemove();

            $this->redirectsTo(function () use ($database, $copier, $store): void {
                $this->editor($database, $copier, $store)->handleRemove();
            }
            );

            self::assertSame([], $store->forEvent(EditorTestIds::EVENT), 'The reference is off the event.');
            self::assertCount(1, $store->writes, 'The event is rewritten once.');
            self::assertSame(
            [EditorTestIds::MEDIA_ATTACHMENT],
            array_column($copier->detachCalls, 'attachment_id'),
            'The media-library row is detached from the event.'
            );
            self::assertSame(
            [EditorTestIds::MEDIA_ATTACHMENT],
            $copier->releasedFeaturedImages,
            'A poster that was the event picture stops being it.'
            );

            self::assertCount(1, $database->auditRows, 'Removal writes one audit row.');
            self::assertSame(AuditAction::SOURCE_MATERIAL_REMOVED->value, $database->auditRows[0]['action']);
            self::assertSame('dean@example.test', $database->auditRows[0]['actor']);

            $removed = array_column($copier->calls, 'role');

            self::assertNotContains(
            'delete',
            $removed,
            'Removal must never delete the file: it is taken off the event, not destroyed.'
            );

            $notice = $this->noticeFor(EditorTestIds::EVENT);

            self::assertNotNull($notice);
            self::assertTrue($notice['success']);
            self::assertStringContainsString('The file itself was kept', $notice['message']);
        }

        /**
                     * An id that is not published beside this event is refused with 404 and
                     * nothing is detached, so a crafted id cannot strip an unrelated
                     * attachment from the event's media list.
                     */
        public function testRemovingAFileThatIsNotPublishedBesideTheEventIsRefused(): void
        {
            $store = new EditorRecordingStore();
            $store->seed(EditorTestIds::EVENT, [EditorTestIds::publishedPoster()]);
            $copier = new EditorRecordingCopier();
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'attachment_id' => (string) EditorTestIds::FOREIGN_ATTACHMENT,
            ];

            try {
                $this->editor(copier: $copier, store: $store)->handleRemove();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(404, $refused->status);
                self::assertSame([], $copier->detachCalls, 'Nothing is detached.');
                self::assertSame([], $store->writes, 'And the event is not rewritten.');

                return;
            }

            self::fail('A file that is not published beside the event cannot be taken off it.');
        }

        /**
                     * Two ways to name something that cannot be removed, and they are
                     * refused for different reasons.
                     *
                     * A value that is not an id at all (`absint()` makes it 0) is a
                     * malformed request and gets 400. A negative number is a perfectly
                     * well-formed id - `absint('-3')` is 3 - so it fails the "is this
                     * published beside the event" check instead and gets 404. Asserting
                     * one status for both would hide whichever guard was dropped.
                     *
                     * @param mixed $attachmentId
                     */
        #[DataProvider('unusableAttachmentIds')]
        public function testRemovingSomethingThatIsNotAnAttachmentIdIsRefused(
        mixed $attachmentId,
        int $status
        ): void {
            $store = new EditorRecordingStore();
            $store->seed(EditorTestIds::EVENT, [EditorTestIds::publishedPoster()]);
            $copier = new EditorRecordingCopier();
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'attachment_id' => $attachmentId,
            ];

            try {
                $this->editor(copier: $copier, store: $store)->handleRemove();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame($status, $refused->status);
                self::assertSame([], $copier->detachCalls);
                self::assertSame([], $store->writes, 'And the event keeps its list.');

                return;
            }

            self::fail('An attachment id that is not one cannot be acted on.');
        }

        /**
                     * @return array<string, array{mixed, int}>
                     */
        public static function unusableAttachmentIds(): array
        {
            return [
            'missing' => [null, 400],
            'empty' => ['', 400],
            'zero' => ['0', 400],
            // absint() makes this 3, which is a well-formed id of something that
            // is not published here, so it is refused as unknown rather than bad.
            'negative' => ['-3', 404],
            'words' => ['poster', 400],
            'array' => [[11], 400],
            // The id of a file that is published, but not beside this event.
            "another event's poster" => [EditorTestIds::FOREIGN_ATTACHMENT, 404],
            ];
        }

        /**
                     * The removal route refuses a post that is not an event, on the same
                     * terms as the add route.
                     */
        public function testRemovingFromSomethingThatIsNotAnEventIsRefused(): void
        {
            $GLOBALS['adct_test_post_types'] = [EditorTestIds::FOREIGN_EVENT => 'page'];
            $store = new EditorRecordingStore();
            $copier = new EditorRecordingCopier();
            $_POST = [
            'event_id' => (string) EditorTestIds::FOREIGN_EVENT,
            'attachment_id' => (string) EditorTestIds::MEDIA_ATTACHMENT,
            ];

            try {
                $this->editor(copier: $copier, store: $store)->handleRemove();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->detachCalls);

                return;
            }

            self::fail('Only an event carries source material.');
        }

        /**
                     * The box renders nothing at all to somebody who cannot edit the event,
                     * even though WordPress has already put it on their screen.
                     */
        public function testTheBoxRendersNothingToSomebodyWhoCannotEditTheEvent(): void
        {
            $GLOBALS['adct_test_wp_caps'] = [9 => [Capabilities::MANAGE_SETTINGS]];
            $GLOBALS['adct_test_post_caps'] = false;

            ob_start();
            $this->editor()->renderMetaBox((object) ['ID' => EditorTestIds::EVENT]);
            $markup = (string) ob_get_clean();

            self::assertSame('', trim($markup), 'No list, no form, no file name is disclosed.');
        }

        /**
                     * The box renders nothing for a post with no id, rather than treating it
                     * as post zero and listing the files of an event that does not exist.
                     */
        public function testTheBoxRendersNothingForAPostWithNoId(): void
        {
            ob_start();
            $this->editor()->renderMetaBox((object) ['ID' => 0]);
            $markup = (string) ob_get_clean();

            self::assertSame('', trim($markup));
        }

        /**
                     * The rendered box offers exactly the files that arrived with the notice
                     * and are not published yet, each with its own add form and the route's
                     * nonce.
                     */
        public function testTheBoxOffersTheNoticesOwnUnpublishedFiles(): void
        {
            $markup = $this->render();

            self::assertStringContainsString('Files from the notice that are not published yet', $markup);
            self::assertStringContainsString('bulletin.pdf', $markup, 'The pdf is offered as a document.');
            self::assertStringContainsString('poster.jpg', $markup);
            self::assertStringContainsString('value="' . EventSourceMaterialEditor::ADD_ACTION . '"', $markup);
            self::assertStringContainsString(EventSourceMaterialEditor::ADD_NONCE, $markup);
            self::assertStringContainsString('name="selected[]"', $markup);
            self::assertStringNotContainsString(
            'checked',
            $markup,
            'Nothing is ticked for the reviewer: publishing has to be a decision, not a default.'
            );
        }

    /**
     * A file already published is not offered again, whatever role it was
     * published under. Publishing the same bulletin twice would make two
     * media-library rows for one parish file.
     *
     * The offer table itself is still rendered, because the notice's *other*
     * file has not been published. An earlier version of this asserted the
     * whole table was gone, which would have passed on an empty box.
     */
    public function testAFileAlreadyPublishedIsNotOfferedAgain(): void
    {
        $store = new EditorRecordingStore();
        $store->seed(
            EditorTestIds::EVENT,
            [new SourceMaterialReference(EditorTestIds::MEDIA_ATTACHMENT, SourceMaterialRole::POSTER, 'poster.jpg')]
        );

        $markup = $this->render(store: $store);

        self::assertStringContainsString('poster.jpg', $markup, 'It is listed as published.');
        self::assertStringContainsString(
            'Files from the notice that are not published yet',
            $markup,
            'The other file has not been published, so it is still offered.'
        );
        self::assertStringNotContainsString(
            'id="adct-pi-publish-11"',
            $markup,
            'The published file has no checkbox: publishing it twice would make two media rows for one parish file.'
        );
        self::assertStringContainsString(
            'id="adct-pi-publish-12"',
            $markup,
            'And the file that has not been published is still offered.'
        );
        self::assertStringContainsString('bulletin.pdf', $markup, 'Which is the pdf that has not been published.');
    }

        /**
                     * An event with no intake origin says so, and offers no form at all
                     * rather than a form that would submit nothing.
                     */
        public function testTheBoxSaysWhenAnEventHasNoIntakeOrigin(): void
        {
            $database = new EditorQueueDatabase();
            $database->sourceCandidate = null;

            $markup = $this->render(database: $database);

            self::assertStringNotContainsString(EventSourceMaterialEditor::ADD_ACTION, $markup);
            self::assertStringNotContainsString('Not published from a notice', $markup);
            self::assertStringContainsString(
            'This event was not published from a notice',
            $markup,
            'The reviewer is told why there is nothing to tick.'
            );
        }

        /**
                     * Each published file gets its own remove form carrying its own
                     * attachment id, and the box says removal keeps the file.
                     */
        public function testEachPublishedFileHasItsOwnRemoveForm(): void
        {
            $store = new EditorRecordingStore();
            $store->seed(
            EditorTestIds::EVENT,
            [
            new SourceMaterialReference(EditorTestIds::MEDIA_ATTACHMENT, SourceMaterialRole::POSTER, 'poster.jpg'),
            new SourceMaterialReference(5151, SourceMaterialRole::DOCUMENT, 'bulletin.pdf'),
            ]
            );

            $markup = $this->render(store: $store);

            self::assertStringContainsString('value="' . EventSourceMaterialEditor::REMOVE_ACTION . '"', $markup);
            self::assertStringContainsString(EventSourceMaterialEditor::REMOVE_NONCE, $markup);
            self::assertStringContainsString('name="attachment_id"', $markup);
            self::assertStringContainsString('value="' . EditorTestIds::MEDIA_ATTACHMENT . '"', $markup);
            self::assertStringContainsString('value="5151"', $markup);
            // SourceMaterialRole::label() knows exactly three words. An earlier
            // version of this asserted 'Event picture', which the class can never
            // render, so the row-label rendering was not actually being checked.
            self::assertStringContainsString('>Poster<', $markup, 'The first file reads as a poster.');
            self::assertStringContainsString('>Document<', $markup, 'The second as a document.');
            self::assertStringNotContainsString('Event picture', $markup, 'Which is not a role that exists.');
            self::assertStringContainsString(
            'does not delete the file',
            $markup,
            'A reviewer pressing "take off the event" needs to know the file survives.'
            );
        }

        /**
                     * When every file has been published the box says so rather than
                     * rendering a heading with an empty table under it.
                     */
        public function testTheBoxSaysWhenThereIsNothingLeftToPublish(): void
        {
            $store = new EditorRecordingStore();
            $store->seed(
            EditorTestIds::EVENT,
            [
            new SourceMaterialReference(EditorTestIds::MEDIA_ATTACHMENT, SourceMaterialRole::POSTER, 'poster.jpg'),
            new SourceMaterialReference(5151, SourceMaterialRole::DOCUMENT, 'bulletin.pdf'),
            ]
            );

            $markup = $this->render(store: $store);

            self::assertStringNotContainsString('Files from the notice that are not published yet', $markup);
            self::assertStringNotContainsString(EventSourceMaterialEditor::ADD_ACTION, $markup);
        }

        /**
                     * The notice a promotion left is shown once and then gone, so it cannot
                     * reappear on a later edit of the same event and claim a decision that
                     * has since been undone.
                     */
        public function testTheNoticeIsShownOnceAndThenGone(): void
        {
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'selected' => [],
            'roles' => [],
            ];

            $this->redirectsTo(function (): void {
                $this->editor()->handleAdd();
            }
            );

            $editor = $this->editor();

            $first = $editor->notice(EditorTestIds::EVENT);

            self::assertNotNull($first);
            self::assertNull(
            $editor->notice(EditorTestIds::EVENT),
            'The transient is deleted on first read, so the outcome is reported once.'
            );
        }

        /**
                     * A notice cannot be read for another event's id, because the transient
                     * is keyed by the event.
                     */
        public function testANoticeCannotBeReadForAnotherEvent(): void
        {
            $GLOBALS['adct_test_transients']['adct_pi_source_material_notice_' . EditorTestIds::EVENT] = [
            'message' => 'Published 1 file.',
            'success' => true,
            ];

            self::assertNull($this->editor()->notice(EditorTestIds::FOREIGN_EVENT));
            self::assertArrayHasKey(
            'adct_pi_source_material_notice_' . EditorTestIds::EVENT,
            $GLOBALS['adct_test_transients'],
            "Another event's read must not consume this event's notice."
            );
        }

        /**
                     * With nowhere to redirect to, the outcome is the response. Losing the
                     * message would leave a reviewer not knowing whether the file published.
                     */
        public function testWithNowhereToRedirectToTheOutcomeIsTheResponse(): void
        {
            $GLOBALS['adct_test_edit_links'] = [];
            $GLOBALS['adct_test_transients'] = [];
            $this->postAnAdd();

            try {
                $this->editor()->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(303, $refused->status, 'The work succeeded, so this is a redirect-shaped answer.');
                self::assertStringContainsString('Published 1 file', $refused->getMessage());

                return;
            }

            self::fail('Without a redirect target the message has to be the response.');
        }

        /**
                     * A file whose stored name has gone is refused rather than copied from
                     * nothing.
                     */
        public function testAFileThatIsNoLongerStoredIsRefused(): void
        {
            $database = new EditorQueueDatabase();
            $database->promotableRows = [[
            'id' => (string) EditorTestIds::ATTACHMENT,
            'message_id' => (string) EditorTestIds::MESSAGE,
            'filename' => 'poster.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => '2048',
            'storage_path' => '',
            'status' => 'stored',
            ]];
            $copier = new EditorRecordingCopier();
            $this->postAnAdd();

            try {
                $this->editor($database, $copier)->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('A file with no stored path cannot be copied.');
        }

        /**
                     * A notice whose message id is unusable has no files to publish. Named
                     * separately from the missing-origin case because it is a different
                     * fault: the link is there, the message behind it is not.
                     */
        public function testANoticeWithNoMessageHasNothingToPublish(): void
        {
            $database = new EditorQueueDatabase();
            $database->messageId = null;
            $copier = new EditorRecordingCopier();
            $this->postAnAdd();

            try {
                $this->editor($database, $copier)->handleAdd();
            } catch
            (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertStringContainsString('no source material', $refused->getMessage());
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('A notice with no message has nothing to publish from.');
        }

        /**
                     * A second poster demotes the first to a document. Two posters on one
                     * event is ambiguous on the public page, so the rule is one poster and
                     * the rest are documents.
                     */
        public function testAPublishedPosterDemotesTheFirstPosterToADocument(): void
        {
            $store = new EditorRecordingStore();
            $copier = new EditorRecordingCopier();
            $store->seed(
            EditorTestIds::EVENT,
            [new SourceMaterialReference(EditorTestIds::MEDIA_ATTACHMENT, SourceMaterialRole::POSTER, 'poster.jpg')]
            );

            // Two jpegs, both promotable: the shared fixture makes the second
            // attachment a pdf, and a pdf cannot be a poster, so without this the
            // test would be refused before the demotion rule was ever reached.
            $database = new EditorQueueDatabase();
            $database->promotableRows = [
                $database->promotableRowFor(EditorTestIds::ATTACHMENT, 'poster.jpg', 'image/jpeg'),
                $database->promotableRowFor(EditorTestIds::SECOND_ATTACHMENT, 'poster-2.jpg', 'image/jpeg'),
            ];

            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'selected' => [(string) EditorTestIds::SECOND_ATTACHMENT],
            'roles' => [(string) EditorTestIds::SECOND_ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            $this->redirectsTo(function () use ($database, $copier, $store): void {
                $this->editor($database, $copier, $store)->handleAdd();
            }
            );

            $roles = array_map(
            static fn (SourceMaterialReference $reference): string => $reference->role,
            $store->forEvent(EditorTestIds::EVENT)
            );

            self::assertSame(
            [SourceMaterialRole::POSTER, SourceMaterialRole::DOCUMENT],
            $roles,
            'The newly chosen poster is the poster; the one it replaced becomes a document.'
            );

            self::assertSame(
            [EditorTestIds::MEDIA_ATTACHMENT],
            $copier->featuredImageCalls,
            'The event picture follows the poster that is actually current.'
            );
        }

        /**
                     * The rendered box does not leak a file name it has no right to show:
                     * the attachment id is the only thing in the request, and the list comes
                     * from the notice.
                     */
        public function testTheRenderedBoxListsOnlyWhatTheEventOwns(): void
        {
            $database = new EditorQueueDatabase();
            $database->sourceCandidate = null;
            $store = new EditorRecordingStore();
            $store->seed(
            EditorTestIds::EVENT,
            [new SourceMaterialReference(EditorTestIds::MEDIA_ATTACHMENT, SourceMaterialRole::POSTER, 'another-parish.jpg')]
            );

            $markup = $this->render(database: $database, store: $store);

            self::assertStringContainsString('another-parish.jpg', $markup, 'What the event owns is listed.');
            self::assertStringNotContainsString(
            'Files from the notice that are not published yet',
            $markup,
            'With no candidate there is no notice, so no intake file is offered at all.'
            );
            self::assertStringNotContainsString(
            'id="adct-pi-publish-11"',
            $markup,
            'And so neither of the notice\'s files has a checkbox.'
            );

            self::assertSame(
            [EditorTestIds::EVENT],
            $store->readEvents,
            'Only the event the box is for is read: the store is asked for one event id, and a second read would mean the box was rendering another event\'s material.'
            );
        }

        /**
                     * A file name is escaped on the way out. Anonymised fixtures are the
                     * rule in this repository, but the name comes from a stranger's email,
                     * so it is not trusted to be harmless.
                     */
        public function testAFileNameIsEscapedOnTheWayOut(): void
        {
            $store = new EditorRecordingStore();
            $store->seed(
            EditorTestIds::EVENT,
            [new SourceMaterialReference(EditorTestIds::MEDIA_ATTACHMENT, SourceMaterialRole::POSTER, '<script>x</script>.jpg')]
            );

            $markup = $this->render(store: $store);

            self::assertStringNotContainsString('<script>x</script>', $markup);
            self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;.jpg', $markup);
        }

        /**
                     * The library link is text, not a broken control, when the attachment
                     * row has gone — somebody deleting it out of band is a real thing to
                     * find out about.
                     */
        public function testAMissingAttachmentRowIsReportedRatherThanLinked(): void
        {
            $store = new EditorRecordingStore();
            $store->seed(EditorTestIds::EVENT, [EditorTestIds::publishedPoster()]);

            $markup = $this->render(store: $store);

            self::assertStringContainsString('The attachment row is gone', $markup);
            self::assertStringNotContainsString('>5150</a>', $markup);
        }

        /**
                     * The library link is the media edit screen when there is one.
                     */
        public function testTheLibraryLinkPointsAtTheAttachmentWhenItIsThere(): void
        {
            $GLOBALS['adct_test_posts'][EditorTestIds::MEDIA_ATTACHMENT] = (object) [
            'ID' => EditorTestIds::MEDIA_ATTACHMENT,
            ];
            $GLOBALS['adct_test_edit_links'][EditorTestIds::MEDIA_ATTACHMENT] = 'https://adct.example.test/wp-admin/post.php?post=5150&action=edit';
            $store = new EditorRecordingStore();
            $store->seed(EditorTestIds::EVENT, [EditorTestIds::publishedPoster()]);

            $markup = $this->render(store: $store);

            self::assertStringContainsString(
            '<a href="https://adct.example.test/wp-admin/post.php?post=5150&amp;action=edit">#5150</a>',
            $markup
            );
        }

        /**
                     * A file whose type no browser can show, and which therefore cannot fill
                     * any role, is listed with no role control rather than an empty select
                     * that submits nothing.
                     */
        public function testAFileThatCanFillNoRoleIsListedWithNoRoleControl(): void
        {
            $database = new EditorQueueDatabase();
            $database->promotableRows = [[
            'id' => (string) EditorTestIds::ATTACHMENT,
            'message_id' => (string) EditorTestIds::MESSAGE,
            'filename' => 'notice.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => '128',
            'storage_path' => 'notice-intake.txt',
            'status' => 'stored',
            ]];

            $markup = $this->render(database: $database);

            self::assertStringContainsString('notice.txt', $markup);
            self::assertStringContainsString(
            "cannot be published beside an event",
            $markup,
            'The reviewer is told why there is nothing to choose.'
            );
            self::assertStringNotContainsString('name="roles[11]"', $markup);
        }

        /**
                     * The rendered form carries the event id as a hidden field, so the route
                     * knows which event it is for. It is a hidden field and not a URL
                     * because the route is a POST: a GET that acted would be reachable from
                     * a link in a stranger's email.
                     */
        public function testTheFormCarriesTheEventIdItIsFor(): void
        {
            $markup = $this->render();

            self::assertStringContainsString(
            '<input type="hidden" name="event_id"',
            $markup,
            'The add form says which event it is for.'
            );
            self::assertStringContainsString(
            'value="' . EditorTestIds::EVENT . '"',
            $markup,
            'And the id is the event under test, not a literal.'
            );
        }

        /**
                     * The routes are reachable only by POST. There is no `admin_post_nopriv_`
                     * hook and no `admin_post_` GET route: the forms are POST, and
                     * WordPress only routes `admin_post_` for the method the action names.
                     */
        public function testBothRoutesAreAdminPostActions(): void
        {
            self::assertStringStartsWith('adct_pi_event_', EventSourceMaterialEditor::ADD_ACTION);
            self::assertStringStartsWith('adct_pi_event_', EventSourceMaterialEditor::REMOVE_ACTION);
            self::assertStringContainsString(
            '<form method="post"',
            $this->render(),
            'The form that adds files is a POST.'
            );
        }

        /**
                     * The class holds no collaborators that need WordPress at construction
                     * time, so `scripts/check-release-bootstrap.php` can still boot it under
                     * plain PHP. Asserted by constructing it with nothing else present.
                     */
        public function testTheEditorCanBeConstructedWithoutWordPress(): void
        {
            $editor = $this->editor();

            self::assertInstanceOf(EventSourceMaterialEditor::class, $editor);
        }

        /**
                     * @param callable(): void $run
                     */
        private function redirectsTo(callable $run): void
        {
            try {
                $run();
            } catch
            (\AdctTestRedirect) {
                return;
            }

            self::fail('The route must end in a redirect.');
        }

        /**
                     * The rendered box.
                     */
        private function render(
        ?EditorQueueDatabase $database = null,
        ?EditorRecordingStore $store = null
        ): string {
            ob_start();
            $this->editor($database ?? new EditorQueueDatabase(), store: $store)->renderMetaBox(
            (object) ['ID' => EditorTestIds::EVENT]
            );

            return (string) ob_get_clean();
        }

        /**
                     * @return array{message: string, success: bool}|null
                     */
        private function noticeFor(int $eventId): ?array
        {
            $state = $GLOBALS['adct_test_transients']['adct_pi_source_material_notice_' . $eventId] ?? null;

            return is_array($state) ? $state : null;
        }

        private function editor(
        ?EditorQueueDatabase $database = null,
        ?EditorRecordingCopier $copier = null,
        ?EditorRecordingStore $store = null
        ): EventSourceMaterialEditor {
            $database ??= new EditorQueueDatabase();

            return new EventSourceMaterialEditor(
            new SourceMaterialPromotion($copier ?? new EditorRecordingCopier(), $store ?? new EditorRecordingStore()),
            new ReviewQueueRepository($database, new EditorClock()),
            new AttachmentRepository($database),
            new SourceMaterialAuditTrail(
            new EditorRecordingAudit($database),
            new EditorActorResolver()
            )
            );
        }

        /**
                     * A well-formed add request: one ticked poster.
                     */
        private function postAnAdd(): void
        {
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'selected' => [(string) EditorTestIds::ATTACHMENT],
            'roles' => [(string) EditorTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];
        }

        /**
                     * A well-formed remove request for the published poster.
                     */
        private function postARemove(): void
        {
            $_POST = [
            'event_id' => (string) EditorTestIds::EVENT,
            'attachment_id' => (string) EditorTestIds::MEDIA_ATTACHMENT,
            ];
        }
    }

    final class EditorTestIds
    {
        public const MESSAGE = 42;

        public const CANDIDATE = 77;

        public const EVENT = 4312;

        /** An event id a crafted form might try to name. */
        public const FOREIGN_EVENT = 9999;

        /** The intake attachment a poster arrives as. */
        public const ATTACHMENT = 11;

        public const SECOND_ATTACHMENT = 12;

        /** An intake attachment id that is not on this notice. */
        public const FOREIGN_ATTACHMENT = 99;

        /** The media-library row a copy lands in. */
        public const MEDIA_ATTACHMENT = 5150;

        public const STORAGE_STEM = 'poster-intake';

        public const STORAGE_NAME = self::STORAGE_STEM . '.jpg';

        public const PDF_STORAGE_NAME = self::STORAGE_STEM . '-2.pdf';

        /**
                     * The reference a poster publishes as, for the removal tests.
                     */
        public static function publishedPoster(): SourceMaterialReference
        {
            return new SourceMaterialReference(self::MEDIA_ATTACHMENT, SourceMaterialRole::POSTER, 'poster.jpg');
        }
    }

    final class EditorClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-03-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
        }
    }

    /**
                 * Records what the copier was asked to do, so a test can prove the copy
                 * happened once, did not happen at all, or never became a deletion.
                 */
    final class EditorRecordingCopier implements SourceMaterialCopierInterface
    {
        /** @var list<array<string, mixed>> */
        public array $calls = [];

        /** @var list<array{event_id: int, attachment_id: int}> */
        public array $detachCalls = [];

        /** @var list<int> */
        public array $featuredImageCalls = [];

        /** @var list<int> */
        public array $releasedFeaturedImages = [];

        /** The stored name to fail on, if any. */
        public string $failOn = '';

        public function copyIntoMediaLibrary(
        int $eventId,
        string $storageName,
        string $originalName,
        string $role
        ): int {
            if ($this->failOn !== '' && $storageName === $this->failOn) {
                throw new \RuntimeException('The uploads directory is not writable.');
            }

            $this->calls[] = [
            'event_id' => $eventId,
            'storage_name' => $storageName,
            'original_name' => $originalName,
            'role' => $role,
            ];

            return EditorTestIds::MEDIA_ATTACHMENT;
        }

        public function setFeaturedImage(int $eventId, int $attachmentId): bool
        {
            $this->featuredImageCalls[] = $attachmentId;

            return true;
        }

        public function detachFromEvent(int $eventId, int $attachmentId): bool
        {
            $this->detachCalls[] = ['event_id' => $eventId, 'attachment_id' => $attachmentId];
            $this->releasedFeaturedImages[] = $attachmentId;
            $this->calls[] = [
            'event_id' => $eventId,
            'storage_name' => '',
            'original_name' => '',
            'role' => 'detach',
            ];

            return true;
        }
    }

    /**
                 * A store that records what was written and, separately, which events were
                 * read, so a test can tell a read of another event from a read of this one.
                 */
    final class EditorRecordingStore implements SourceMaterialStoreInterface
    {
        /** @var list<array{event: int, references: list<SourceMaterialReference>}> */
        public array $writes = [];

        /** @var list<int> */
        public array $writtenEvents = [];

        /** @var list<int> */
        public array $readEvents = [];

        /** @var array<int, list<SourceMaterialReference>> */
        private array $current = [];

        public function forEvent(int $eventId): array
        {
            $this->readEvents[] = $eventId;

            return $this->current[$eventId] ?? [];
        }

        public function replaceForEvent(int $eventId, array $references): void
        {
            $this->writes[] = ['event' => $eventId, 'references' => array_values($references)];
            $this->writtenEvents[] = $eventId;
            $this->current[$eventId] = array_values($references);
        }

        /**
                     * Put the event's starting state in place without recording a write.
                     *
                     * Every test that needs files published beside an event is describing a
                     * state that was reached by an earlier decision, not by this request.
                     * Seeding through `replaceForEvent()` would make `writes` say the request
                     * under test rewrote the event, which is the one thing the removal tests
                     * need to be able to tell.
                     */
        public function seed(int $eventId, array $references): void
        {
            $this->current[$eventId] = array_values($references);
        }
    }

    final class EditorRecordingAudit implements AuditWriter
    {
        public function __construct(private readonly EditorQueueDatabase $database)
        {
        }

        public function write(
        string $actor,
        AuditAction $action,
        string $subjectType,
        int $subjectId,
        array $details
        ): int {
            $this->database->auditRows[] = [
            'actor' => $actor,
            'action' => $action->value,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'details' => $details,
            ];

            return 1;
        }
    }

    final class EditorActorResolver implements ActorResolver
    {
        public function actor(): string
        {
            $user = $GLOBALS['adct_test_current_user'] ?? null;

            return $user instanceof \WP_User ? (string) $user->user_email : '';
        }
    }

    /**
                 * Answers exactly the reads the editor makes: the candidate an event came
                 * from, that candidate's message, and the promotable attachments on the
                 * message. Every statement it does not recognise returns nothing, so a
                 * handler that started reaching for the rest of the schema would fail here
                 * rather than quietly pass on made-up rows.
                 */
    final class EditorQueueDatabase implements DatabaseConnectionInterface
    {
        /** The candidate the event came from, or null for a hand-made event. */
        public ?int $sourceCandidate = EditorTestIds::CANDIDATE;

        /** The message behind that candidate, or null. */
        public ?int $messageId = EditorTestIds::MESSAGE;

        /** The rows `findPromotableForMessage()` serves, or null to derive them. */
        public ?array $promotableRows = null;

        /** @var list<array<string, mixed>> */
        public array $auditRows = [];

        /** @var list<string> */
        public array $queries = [];

        private string $lastError = '';

        public function prefix(): string
        {
            return 'wp_';
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            $values = array_values($arguments);

            return preg_replace_callback(
            '/%[sdf]/',
            static function (array $match) use (&$values): string {
                $value = array_shift($values);

                return match ($match[0]) {
                    '%d' => (string) (int) $value,
                    '%f' => (string) (float) $value,
                default => (string) $value,
                }
                ;
            }
            ,
            $query
            ) ?? $query;
        }

        public function query(string $query): int|false
        {
            $this->queries[] = $query;
            $this->lastError = '';

            return 1;
        }

        public function getRow(string $query): ?array
        {
            return $this->getResults($query)[0] ?? null;
        }

        public function getResults(string $query): array
        {
            $this->queries[] = $query;

            // findPublishedCandidateForEvent(): the inverse lookup, which names
            // `meta_value` and is only reached after the forward lookup's `CAST`.
            if (str_contains($query, "meta_key = 'source_candidate_id'") && str_contains($query, 'meta.meta_value')) {
                return $this->sourceCandidate === null
                ? []
                : [[
                'ID' => (string) EditorTestIds::EVENT,
                'post_status' => 'publish',
                'meta_value' => (string) $this->sourceCandidate,
                ]];
            }

            // findMessageOf().
            if (str_contains($query, 'SELECT message_id FROM')) {
                return $this->messageId === null
                ? []
                : [['message_id' => (string) $this->messageId]];
            }

            // findPromotableForMessage().
            if (str_contains($query, 'mime_type IN')) {
                if ($this->promotableRows !== null) {
                    return $this->promotableRows;
                }

                return [
                $this->promotableRowFor(EditorTestIds::ATTACHMENT, 'poster.jpg', 'image/jpeg'),
                $this->promotableRowFor(EditorTestIds::SECOND_ATTACHMENT, 'bulletin.pdf', 'application/pdf'),
                ];
            }

            return [];
        }

        /**
                     * @return array<string, mixed>
                     */
        public function promotableRowFor(int $id, string $name, string $mime): array
        {
            return [
            'id' => (string) $id,
            'message_id' => (string) EditorTestIds::MESSAGE,
            'filename' => $name,
            'mime_type' => $mime,
            'size_bytes' => '2048',
            'storage_path' => $mime === 'application/pdf'
            ? EditorTestIds::PDF_STORAGE_NAME
            : EditorTestIds::STORAGE_NAME,
            'status' => 'stored',
            ];
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
            $this->lastError = '';
        }

        public function lastError(): string
        {
            return $this->lastError;
        }
    }
}

namespace ADCT\ParishIntake\WordPress\Events {

    /**
                 * The WordPress functions `EventSourceMaterialEditor` calls unqualified, in
                 * the namespace PHP resolves them in.
                 *
                 * add_meta_box(), remove_meta_box() and current_user_can() come from
                 * tests/Support/WordPressEventEditorStubs.php, which records boxes keyed
                 * by box id. Each stub below is guarded so a future shared stub wins
                 * rather than fataling with "Cannot redeclare", which is the failure
                 * mode PHPUnit's single process turns a duplicate stub into.
                 *
                 * Driven through these globals, cleared in tearDown():
                 *
                 *     adct_test_meta_boxes          box id => add_meta_box() arguments
                 *     adct_test_meta_boxes_removed  remove_meta_box() box ids
                 *     adct_test_post_types          post id => post type
                 *     adct_test_edit_links          post id => edit URL
                 *     adct_test_redirects_allowed   hosts wp_validate_redirect() accepts
                 *     adct_test_transients          transient name => value
                 *     adct_test_posts               post id => post object
                 */

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\get_post_type')) {
        function get_post_type(mixed $post = null): string|false
        {
            $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;

            return (string) ($GLOBALS['adct_test_post_types'][$id] ?? false);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\get_post')) {
        function get_post(mixed $post = null): mixed
        {
            $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;

            return $GLOBALS['adct_test_posts'][$id] ?? null;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\get_edit_post_link')) {
        function get_edit_post_link(int $postId, string $context = 'display'): string|false
        {
            $link = $GLOBALS['adct_test_edit_links'][$postId] ?? null;

            return is_string($link) && $link !== '' ? $link : false;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_validate_redirect')) {
        /**
                     * Accepts a URL only when it is on one of the hosts a test listed, and
                     * falls back to the fallback otherwise.
                     *
                     * The default list carries this site's own host, so a test that does not
                     * think about it still gets the production behaviour for on-site URLs
                     * and refuses off-site ones.
                     */
        function wp_validate_redirect(string $location, string $fallback = ''): string
        {
            $host = (string) parse_url($location, PHP_URL_HOST);

            if ($host !== '' && in_array($host, $GLOBALS['adct_test_redirects_allowed'] ?? [], true)) {
                return $location;
            }

            return $fallback;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\get_transient')) {
        function get_transient(string $name): mixed
        {
            return $GLOBALS['adct_test_transients'][$name] ?? false;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\set_transient')) {
        function set_transient(string $name, mixed $value, int $expiration = 0): bool
        {
            $GLOBALS['adct_test_transients'][$name] = $value;

            return true;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\delete_transient')) {
        function delete_transient(string $name): bool
        {
            unset($GLOBALS['adct_test_transients'][$name]);

            return true;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\absint')) {
        function absint(mixed $value): int
        {
            return is_numeric($value) ? abs((int) $value) : 0;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\esc_html')) {
        function esc_html(mixed $text): string
        {
            return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\esc_attr')) {
        function esc_attr(mixed $text): string
        {
            return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\esc_url')) {
        function esc_url(mixed $url): string
        {
            return htmlspecialchars(is_string($url) ? $url : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://adct.example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_nonce_field')) {
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true): string
        {
            $GLOBALS['adct_test_nonce_fields'][] = ['action' => $action, 'name' => $name];
            $markup = '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '" />';
            echo $markup;

            return $markup;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\check_admin_referer')) {
        /**
                     * Records the pair a handler demanded and refuses when a test says it
                     * should. The recorded pair is what the route tests assert.
                     */
        function check_admin_referer(string $action = '-1', string $name = '_wpnonce'): void
        {
            $GLOBALS['adct_test_nonce_checks'][] = ['action' => $action, 'name' => $name];

            if (($GLOBALS['adct_test_nonce_should_fail'] ?? false) === true) {
                throw new \AdctTestNonceRefused($action);
            }
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return is_string($value) ? stripslashes($value) : $value;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_die')) {
        function wp_die(string $message = '', $title = '', array $arguments = []): never
        {
            throw new \AdctTestWpDie($message, (int) ($arguments['response'] ?? 500));
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_safe_redirect')) {
        /**
                     * Records the target and throws, so a test can prove where the reviewer
                     * would have been sent without the request ending for real.
                     */
        function wp_safe_redirect(string $location, int $status = 302): bool
        {
            $GLOBALS['adct_test_redirect'] = $location;

            throw new \AdctTestRedirect($location);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\error_log')) {
        function error_log(string $message): bool
        {
            if (isset($GLOBALS['event_editor_logs']) && is_array($GLOBALS['event_editor_logs'])) {
                $GLOBALS['event_editor_logs'][] = $message;
            }

            return true;
        }
    }
}
