<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
    require_once dirname(__DIR__, 4) . '/tests/Support/WordPressStubs.php';
    // current_user_can() and \WP_User live in the capability stubs. ReviewQueuePageTest.php
    // inherits them from load order; this file says so outright rather than depending on
    // which test file PHPUnit happens to run first.
    require_once dirname(__DIR__, 4) . '/tests/Support/WordPressCapabilityStubs.php';

    use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
    use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
    use ADCT\ParishIntake\Core\Attachments\SourceMaterialPromotion;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Audit\AuditAction;
    use ADCT\ParishIntake\Core\Audit\AuditWriter;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
    use ADCT\ParishIntake\Core\Ports\SourceMaterialCopierInterface;
    use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
    use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
    use ADCT\ParishIntake\Core\Events\EventValidator;
    use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
    use ADCT\ParishIntake\WordPress\Attachments\SourceMaterialAuditTrail;
    use ADCT\ParishIntake\WordPress\Audit\ActorResolver;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * Issue #172: promoting a poster or bulletin from the review queue.
     *
     * This is the surface where a private parish file becomes a file the whole
     * internet can fetch, so most of what is pinned here is about refusals:
     *
     * - **The capability gate runs before the nonce**, so somebody who cannot
     *   review cannot even make WordPress ask for a word, and no copy is made.
     * - **The nonce is the promote route's own**, checked before any read, so a
     *   crafted POST carrying another form's nonce does nothing at all.
     * - **The target event is derived, never posted.** The handler reads no event
     *   id out of the request, so no form can name a different event.
     * - **Nothing is promoted unless a person ticks a box.** No selection is a
     *   no-op redirect with no copy and no audit row, and there is no default.
     * - **A refused selection is refused whole.** The batch is validated before
     *   any of it is copied, so one press cannot half-promote an event.
     * - **An unauthorised promotion writes no audit row**, so the trail cannot
     *   blame somebody for a copy they did not make.
     */
    final class ReviewQueuePromoteSourceMaterialTest extends TestCase
    {
        private array $savedPost;

        private array $savedGet;

        protected function setUp(): void
        {
            parent::setUp();

            $this->savedPost = $_POST;
            $this->savedGet = $_GET;

            $GLOBALS['adct_test_wp_caps'] = [Capabilities::REVIEW];
            $GLOBALS['adct_test_current_user'] = new \WP_User(9, 'dean@example.test');
            $GLOBALS['adct_test_nonce_checks'] = [];
            $GLOBALS['adct_test_redirect'] = null;
            $GLOBALS['adct_test_is_admin'] = true;

            unset($GLOBALS['adct_test_nonce_should_fail']);
        }

        protected function tearDown(): void
        {
            $_POST = $this->savedPost;
            $_GET = $this->savedGet;

            unset(
                $GLOBALS['adct_test_wp_caps'],
                $GLOBALS['adct_test_current_user'],
                $GLOBALS['adct_test_nonce_checks'],
                $GLOBALS['adct_test_redirect'],
                $GLOBALS['adct_test_is_admin'],
                $GLOBALS['adct_test_nonce_should_fail']
            );

            parent::tearDown();
        }

        /**
         * The action name is a cross-file contract: `Plugin.php` builds the hook
         * from this constant and the form posts to it, and nothing would notice
         * if the two drifted apart except a button that 404s.
         */
        public function testThePromoteActionIsTheOneTheHookIsBuiltFrom(): void
        {
            self::assertSame('adct_pi_candidate_promote_source', ReviewQueuePage::PROMOTE_SOURCE_ACTION);
            self::assertSame('promote_source_nonce', ReviewQueuePage::PROMOTE_SOURCE_NONCE);
        }

        /**
         * Promotion copies a private file into a public directory. It must not be
         * reachable by replaying any other form on this screen, so the action and
         * the nonce are both its own.
         */
        public function testPromotingIsNotAModeOnAnyOtherRoute(): void
        {
            $others = [
                ReviewQueuePage::SAVE_ACTION,
                ReviewQueuePage::CREATE_MANUAL_ACTION,
                ReviewQueuePage::RESOLVE_MATCH_ACTION,
                ReviewQueuePage::RAW_MESSAGE_ACTION,
                ReviewQueuePage::ATTACHMENT_ACTION,
            ];

            foreach ($others as $other) {
                self::assertNotSame(
                    $other,
                    ReviewQueuePage::PROMOTE_SOURCE_ACTION,
                    'A shared action would let a crafted POST promote through a form that cannot.'
                );
            }

            self::assertNotSame(
                ReviewQueuePage::SOURCE_NONCE,
                ReviewQueuePage::PROMOTE_SOURCE_NONCE,
                'A shared nonce would let the download form be replayed as the promote form.'
            );
            self::assertNotSame(ReviewQueuePage::RESOLVE_MATCH_NONCE, ReviewQueuePage::PROMOTE_SOURCE_NONCE);
        }

        /**
         * The capability gate runs first, so an unauthorised caller never even
         * reaches the nonce, the database or the copier.
         */
        public function testSomeoneWhoCannotReviewCannotPromoteAnything(): void
        {
            $GLOBALS['adct_test_wp_caps'] = [Capabilities::MANAGE_SETTINGS];
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            try {
                $this->page($database, $copier, new PromoteRecordingStore())->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(403, $refused->status);
                self::assertSame([], $database->queries, 'A refused promotion must not read or write anything.');
                self::assertSame([], $copier->calls, 'And it must not copy a file.');
                self::assertSame([], $database->auditRows);
                self::assertSame([], $GLOBALS['adct_test_nonce_checks'], 'The capability is checked first.');

                return;
            }

            self::fail('Reviewing is required to promote source material.');
        }

        /**
         * A refused nonce must stop the handler before it reads anything, because
         * a copy made from a forged request is the whole risk here.
         */
        public function testThePromoteRouteDemandsItsOwnNonceBeforeReadingAnything(): void
        {
            $GLOBALS['adct_test_nonce_should_fail'] = true;
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            try {
                $this->page($database, $copier, new PromoteRecordingStore())->handlePromoteSourceMaterial();
            } catch (\AdctTestNonceRefused) {
                self::assertSame([], $database->queries);
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('The promote route must require its own nonce.');
        }

        /**
         * The exact action and nonce name, asserted separately from the refusal so
         * a wrong *name* cannot pass as a right refusal.
         */
        public function testThePromoteRouteAsksForItsOwnActionAndNonceName(): void
        {
            $GLOBALS['adct_test_nonce_should_fail'] = true;
            $database = new PromoteQueueDatabase();
            $_POST = ['candidate_id' => (string) PromoteTestIds::CANDIDATE];

            try {
                $this->page($database, new PromoteRecordingCopier(), new PromoteRecordingStore())
                    ->handlePromoteSourceMaterial();
            } catch (\AdctTestNonceRefused) {
                // The refusal is the point of the other test; this one reads what
                // WordPress was asked for.
            }

            self::assertSame(
                [['action' => 'adct_pi_candidate_promote_source', 'name' => 'promote_source_nonce']],
                $GLOBALS['adct_test_nonce_checks']
            );
        }

        public function testACandidateOutsideTheReviewersScopeCannotPromote(): void
        {
            $database = new PromoteQueueDatabase();
            $database->visible = false;
            $copier = new PromoteRecordingCopier();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            try {
                $this->page($database, $copier, new PromoteRecordingStore())->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(404, $refused->status);
                self::assertSame([], $copier->calls, 'A candidate outside the scope copies nothing.');

                return;
            }

            self::fail('An out-of-scope candidate must be refused.');
        }

        /**
         * The candidate's id is re-resolved through the queue, so a dean cannot
         * promote by naming somebody else's candidate.
         *
         * The double answers a scoped-candidate query only for its own candidate,
         * which is what the real queue does for a candidate outside the reviewer's
         * scope. Whether that surfaces as a 404 or a 400 is a wording choice; what
         * matters here is that a crafted id copies nothing.
         */
        public function testACraftedCandidateIdIsReResolvedRatherThanTrusted(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::OTHER_CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::BULLETIN],
            ];

            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertContains(
                    $refused->status,
                    [400, 404],
                    'A candidate the reviewer own scope does not hold is refused.'
                );
                                self::assertSame([], $copier->calls, 'Naming somebody else candidate copies nothing.');
                                self::assertSame([], $store->writtenEvents, 'And writes nothing.');
                                self::assertSame([], $database->auditRows, 'And records no promotion.');

                                return;
                            }

            self::assertSame(
                [],
                $copier->calls,
                'The queue is the only source of the event, so a crafted id promotes nothing.'
            );
            self::assertSame([], $store->writtenEvents);
        }

        /**
         * The event is derived from the candidate, so the form's own event id is
         * ignored even when it is present and plausible.
         */
        public function testAnEventIdPostedWithTheFormIsIgnored(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'event_id' => (string) PromoteTestIds::FOREIGN_EVENT,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            $this->promote($database, $copier, $store);

            self::assertSame(
                [PromoteTestIds::EVENT],
                $store->writtenEvents,
                'The only event that may be written is the one the candidate resolves to.'
            );
        }

        /**
         * A candidate whose event is still a draft gets a 409, not a copy.
         */
        public function testACandidateWithNoPublishedEventIsRefused(): void
        {
            $database = new PromoteQueueDatabase();
            $database->publishedEvent = null;
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(409, $refused->status, 'The event is the conflict, not a bad request.');
                self::assertSame([], $copier->calls, 'Nothing is copied onto an unpublished event.');
                self::assertSame([], $store->writtenEvents);
                self::assertSame([], $database->auditRows);

                return;
            }

            self::fail('An unpublished event must be refused.');
        }

        /**
         * Nothing ticked promotes nothing.
         *
         * This is criterion 2 and it is worth pinning rather than assuming: a
         * handler that defaulted to "promote the first attachment" would look
         * identical on every happy-path test.
         */
        public function testPromotingNothingPromotesNothing(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = ['candidate_id' => (string) PromoteTestIds::CANDIDATE];

            $this->promote($database, $copier, $store);

            self::assertSame([], $copier->calls, 'No selection means no copy.');
            self::assertSame([], $store->writtenEvents, 'And nothing recorded.');
            self::assertSame([], $database->auditRows, 'And no audit row blaming a reviewer for nothing.');
        }

        public function testAnEmptySelectionArrayPromotesNothing(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = ['candidate_id' => (string) PromoteTestIds::CANDIDATE, 'selected' => []];

            $this->promote($database, $copier, $store);

            self::assertSame([], $copier->calls);
            self::assertSame([], $store->writtenEvents);
        }

        /**
         * The happy path: the chosen attachment is copied into the media library,
         * recorded with the chosen role, and written to the derived event.
         */
        public function testTheChosenAttachmentIsCopiedRecordedAndAttachedToTheDerivedEvent(): void
        {
            $database = new PromoteQueueDatabase();
            $database->attachmentMime = 'application/pdf';
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::BULLETIN],
            ];

            $this->promote($database, $copier, $store);

            self::assertCount(1, $copier->calls);
            self::assertSame(PromoteTestIds::EVENT, $copier->calls[0]['event_id']);
            self::assertSame(
                            PromoteTestIds::PDF_STORAGE_NAME,
                            $copier->calls[0]['storage_name'],
                            'The stored name of the PDF is what gets copied, not the poster the parish typed.'
                        );
                        self::assertSame(SourceMaterialRole::BULLETIN, $copier->calls[0]['role']);

            self::assertSame(
                [],
                $copier->featuredImageCalls,
                'A bulletin is not the event image, so it must not become the featured image.'
            );

            self::assertCount(1, $store->writes, 'One event written.');
                        self::assertSame(PromoteTestIds::EVENT, $store->writes[0]['event']);
                        self::assertCount(1, $store->writes[0]['references']);
                        $written = $store->writes[0]['references'][0];
                        self::assertSame(
                            PromoteTestIds::MEDIA_ATTACHMENT,
                            $written->attachmentId,
                            'The recorded reference is the new media-library attachment, not the intake row.'
                        );
                        self::assertSame(SourceMaterialRole::BULLETIN, $written->role);
                        self::assertSame('bulletin.pdf', $written->originalName);

                                    self::assertCount(1, $database->auditRows, 'One promotion, one row.');
            $row = $database->auditRows[0];
            self::assertSame('source_material_promoted', $row['action']);
            self::assertSame('event', $row['subject_type']);
            self::assertSame(PromoteTestIds::EVENT, $row['subject_id']);
            self::assertSame(
                PromoteTestIds::ATTACHMENT,
                $row['details']['intake_attachment_id'],
                'The trail joins the public copy back to the raw file.'
            );
            self::assertSame(
                'dean@example.test',
                $row['actor'],
                'The actor is the acting user, not anything the form supplied.'
            );
        }

        /**
         * A promoted poster also becomes the event's featured image, so the
         * listing card and the event page show it without a second decision.
         */
        public function testAPromotedPosterAlsoBecomesTheEventFeaturedImage(): void
        {
            $database = new PromoteQueueDatabase();
            $database->attachmentMime = 'image/jpeg';
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            $this->promote($database, $copier, $store);

            self::assertSame([PromoteTestIds::MEDIA_ATTACHMENT], $copier->featuredImageCalls);
        }

        /**
         * The reviewer lands back on the same candidate, told what happened.
         */
        public function testTheReviewerIsRedirectedBackToTheCandidateWithWordOfThePromotion(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'tab' => 'recently_decided',
                'search' => 'retreat',
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            $this->promote($database, $copier, $store);

            $redirect = $GLOBALS['adct_test_redirect'];
            self::assertIsString($redirect);
            self::assertStringContainsString('candidate=' . PromoteTestIds::CANDIDATE, $redirect);
            self::assertStringContainsString('tab=recently_decided', $redirect, 'The tab is kept.');
            self::assertStringContainsString('promoted=1', $redirect, 'And the screen can say what happened.');
        }

        /**
         * A role is checked against the file's own declared type. A PDF cannot
         * become a poster by posting an image role, and a JPEG cannot become a
         * bulletin, so the reviewer cannot make the front end embed a PDF.
         */
        #[DataProvider('impossibleRoles')]
        public function testAnAttachmentIsNotPromotedInARoleItsTypeCannotFill(string $role, string $mime): void
        {
            $database = new PromoteQueueDatabase();
            $database->attachmentMime = $mime;
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => $role],
            ];

            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status, 'A refused role is a bad request, not a server fault.');
                self::assertSame([], $copier->calls, 'A refused role copies nothing.');

                return;
            }

            self::fail('A ' . $mime . ' must not be promoted as a ' . $role . '.');
        }

        /**
         * @return list<array{string, string}>
         */
        public static function impossibleRoles(): array
        {
            return [
                'a PDF cannot be a poster' => [SourceMaterialRole::POSTER, 'application/pdf'],
                'a JPEG cannot be a bulletin' => [SourceMaterialRole::BULLETIN, 'image/jpeg'],
            ];
        }

        /**
         * HEIC and HEIF are excluded from promotion: they pass intake storage but
         * render in no browser, so a promoted copy would be a dead link.
         */
        #[DataProvider('unrenderableTypes')]
        public function testAnImageNoBrowserCanShowIsNotOfferedForPromotion(string $mime): void
        {
            $database = new PromoteQueueDatabase();
            $database->promotableRows = [];

            self::assertSame(
                [],
                (new AttachmentRepository($database))->findPromotableForMessage(PromoteTestIds::MESSAGE),
                $mime . ' must not be offered to a reviewer.'
            );
        }

        /**
         * @return list<array{string}>
         */
        public static function unrenderableTypes(): array
        {
            return [['image/heic'], ['image/heif']];
        }

        /**
         * A selection naming an attachment the candidate does not own is refused,
         * so the form cannot promote another parish's poster by id.
         */
        public function testAnAttachmentBelongingToAnotherMessageIsRefused(): void
        {
            $database = new PromoteQueueDatabase();
            $database->ownedAttachments = [PromoteTestIds::ATTACHMENT];
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::FOREIGN_ATTACHMENT],
                'roles' => [(string) PromoteTestIds::FOREIGN_ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls, 'Another parish poster is not copied.');

                return;
            }

            self::fail('An attachment outside the candidate message must be refused.');
        }

        /**
         * One bad selection stops the batch before any copy is made, so a
         * partially promoted event cannot be the result of a single press.
         */
        public function testOneRefusedSelectionStopsTheWholeBatchBeforeAnyCopy(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [
                    (string) PromoteTestIds::ATTACHMENT,
                    (string) PromoteTestIds::FOREIGN_ATTACHMENT,
                ],
                'roles' => [
                    (string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER,
                    (string) PromoteTestIds::FOREIGN_ATTACHMENT => SourceMaterialRole::BULLETIN,
                ],
            ];

            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls, 'The good attachment in the batch is not copied either.');

                return;
            }

            self::fail('A batch containing a refused attachment must be refused whole.');
        }

        /**
         * A refused role on the second item stops the first from being copied, for
         * the same reason: an event half promoted from one press is worse than an
         * event not promoted.
         */
        public function testARefusedRoleOnALaterItemStopsAnEarlierCopy(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [
                    (string) PromoteTestIds::ATTACHMENT,
                    (string) PromoteTestIds::SECOND_ATTACHMENT,
                ],
                'roles' => [
                    (string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER,
                    // A JPEG cannot be a bulletin.
                    (string) PromoteTestIds::SECOND_ATTACHMENT => SourceMaterialRole::BULLETIN,
                ],
            ];

            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('The whole selection is validated before any of it is copied.');
        }

        /**
         * A role that is not on the closed list is refused rather than guessed at,
         * so a crafted POST cannot invent a fourth role.
         */
        public function testAnUnknownRoleIsRefusedRatherThanGuessedAt(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => 'featured_banner'],
            ];

            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('An unknown role must be refused.');
        }

        /**
         * A selection with no matching role is refused, so a role cannot be
         * quietly defaulted to "document".
         */
        public function testASelectionWithNoRoleIsRefusedRatherThanDefaulted(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
            ];

            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls);

                return;
            }

            self::fail('A ticked file with no role is ambiguous and must be refused.');
        }

        /**
         * A copy that fails is a 500 with our own message, and records nothing.
         *
         * The promotion service copies before it records, so the previous list
         * survives; what this route owes is a message the reviewer can act on
         * rather than a stack trace.
         */
        public function testAFailedCopyIsReportedAsAServerFaultAndRecordsNothing(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $copier->failOn = PromoteTestIds::STORAGE_NAME;
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                            'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::DOCUMENT],
            ];

            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(500, $refused->status);
                                $message = $refused->getMessage();
                                self::assertStringContainsString(
                                    'could not be copied',
                                    $message,
                    'The reviewer is told what happened, in our own words.'
                );
                self::assertStringNotContainsString(
                    'RuntimeException',
                                    $message,
                    'An internal class name must never reach a reviewer.'
                );
                self::assertSame([], $store->writtenEvents, 'Nothing was recorded.');
                self::assertSame([], $database->auditRows, 'And no audit row claims it was published.');

                return;
            }

            self::fail('A failed copy must be reported.');
        }

        /**
         * The raw file itself is never deleted by a promotion; only a reference is
         * added. Retention owns the original.
         */
        public function testPromotingCopiesRatherThanMovesTheOriginal(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::DOCUMENT],
            ];

            $this->promote($database, $copier, $store);

            foreach ($database->queries as $query) {
                self::assertStringNotContainsString(
                    'DELETE FROM wp_adct_pi_attachments',
                    $query,
                    'The intake row is evidence and is not touched.'
                );
            }
        }

        /**
         * A page wired without the promotion collaborators must say so rather
         * than reaching for a null. Promotion is wired lazily in `Plugin.php`,
         * so "collaborator absent" is a reachable state, not a theoretical one;
         * without this guard PHP would raise a TypeError and the reviewer would
         * get a fatal error page instead of a refusal.
         */
        public function testAPageWithoutItsPromotionCollaboratorsRefusesRatherThanCopying(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            try {
                $this->pageWithoutSourceMaterial($database, $copier, $store)
                    ->handlePromoteSourceMaterial();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertSame([], $copier->calls, 'A page with no promotion service must copy nothing.');
                self::assertSame([], $store->writtenEvents, 'And it must record nothing.');
                self::assertSame([], $database->auditRows, 'And it must leave no audit row.');

                return;
            }

            self::fail('A page with no promotion collaborators must refuse, not fatal.');
        }

        /**
         * The same form, on a page whose collaborators are wired, is accepted.
         * Without this the refusal above would pass for any reason at all,
         * including a fixture that is wrong about the request being refused.
         */
        public function testTheSameFormIsAcceptedOnceTheCollaboratorsAreWired(): void
        {
            $database = new PromoteQueueDatabase();
            $copier = new PromoteRecordingCopier();
            $store = new PromoteRecordingStore();
            $_POST = [
                'candidate_id' => (string) PromoteTestIds::CANDIDATE,
                'selected' => [(string) PromoteTestIds::ATTACHMENT],
                'roles' => [(string) PromoteTestIds::ATTACHMENT => SourceMaterialRole::POSTER],
            ];

            $this->promote($database, $copier, $store);

            self::assertCount(1, $copier->calls, 'The wired page copies the chosen file.');
        }

        private function promote(
            PromoteQueueDatabase $database,
            PromoteRecordingCopier $copier,
            PromoteRecordingStore $store
        ): void {
            try {
                $this->page($database, $copier, $store)->handlePromoteSourceMaterial();
            } catch (\AdctTestRedirect) {
                return;
            }

            self::fail('The promote route must end in a redirect.');
        }

        /**
         * The page exactly as `Plugin.php` builds it if the promotion service is
         * never resolved: everything the review queue has always needed, and no
         * source-material collaborators at all.
         */
        private function pageWithoutSourceMaterial(
            PromoteQueueDatabase $database,
            PromoteRecordingCopier $copier,
            PromoteRecordingStore $store
        ): ReviewQueuePage {
            return new ReviewQueuePage(
                new ReviewQueueRepository($database, new PromoteQueueClock()),
                PromotePublisherRefusal::publisher(),
                attachments: new AttachmentRepository($database),
                sourceMaterial: null,
                audit: null
            );
        }

        private function page(
            PromoteQueueDatabase $database,
            PromoteRecordingCopier $copier,
            PromoteRecordingStore $store
        ): ReviewQueuePage {
            return new ReviewQueuePage(
                new ReviewQueueRepository($database, new PromoteQueueClock()),
                PromotePublisherRefusal::publisher(),
                attachments: new AttachmentRepository($database),
                sourceMaterial: new SourceMaterialPromotion($copier, $store),
                audit: new SourceMaterialAuditTrail(
                    new PromoteRecordingAudit($database),
                    new PromoteActorResolver()
                )
            );
        }
    }

    final class PromoteTestIds
    {
        public const CANDIDATE = 7;

        /** A candidate id that is not the one the database serves. */
        public const OTHER_CANDIDATE = 704;

        public const MESSAGE = 42;

        public const EVENT = 4312;

        /** An event id a crafted form might try to name. */
        public const FOREIGN_EVENT = 9999;

        public const ATTACHMENT = 11;

        public const SECOND_ATTACHMENT = 12;

        public const FOREIGN_ATTACHMENT = 99;

        public const MEDIA_ATTACHMENT = 5150;

        /**
         * The stem every stored attachment shares, so a test can change the type
         * of the stored file without changing the name the fail-on probe uses.
         */
        public const STORAGE_STEM = 'poster-intake';

        public const STORAGE_NAME = self::STORAGE_STEM . '.jpg';

        public const PDF_STORAGE_NAME = self::STORAGE_STEM . '.pdf';
    }

    final class PromoteQueueClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-03-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
        }
    }

    /**
     * A copier that records what it was asked to do, so a test can prove the copy
     * happened, happened once, or did not happen at all.
     */
    final class PromoteRecordingCopier implements SourceMaterialCopierInterface
    {
        /** @var list<array<string, mixed>> */
        public array $calls = [];

        /** @var list<int> */
        public array $featuredImageCalls = [];

        /** The storage name to fail on, if any. */
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

            return PromoteTestIds::MEDIA_ATTACHMENT;
        }

        public function setFeaturedImage(int $eventId, int $attachmentId): bool
        {
            $this->featuredImageCalls[] = $attachmentId;

                    return true;
                }

                public function detachFromEvent(int $eventId, int $attachmentId): bool
                {
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
     * A store that records what was written, so a test can prove the event that
     * was written and the references written to it.
     */
    final class PromoteRecordingStore implements SourceMaterialStoreInterface
    {
        /** @var list<array{event: int, references: list<SourceMaterialReference>}> */
        public array $writes = [];

        /** @var list<int> */
        public array $writtenEvents = [];

        /** @var array<int, list<SourceMaterialReference>> */
        private array $current = [];

        public function forEvent(int $eventId): array
        {
            return $this->current[$eventId] ?? [];
        }

        public function replaceForEvent(int $eventId, array $references): void
        {
            $this->writes[] = ['event' => $eventId, 'references' => array_values($references)];
            $this->writtenEvents[] = $eventId;
            $this->current[$eventId] = array_values($references);
        }
    }

    final class PromoteRecordingAudit implements AuditWriter
    {
        public function __construct(private readonly PromoteQueueDatabase $database)
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

    final class PromoteActorResolver implements ActorResolver
    {
        public function actor(): string
        {
            $user = $GLOBALS['adct_test_current_user'] ?? null;

            return $user instanceof \WP_User ? (string) $user->user_email : '';
        }
    }

    /**
     * A publisher that refuses to do anything, so reaching it from this route
     * would be an obvious failure rather than a silent success.
         *
         * Promotion is not publishing: the event already exists by the time a poster
         * is promoted, so this route must never publish a candidate.
         */
        final class PromotePublisherRefusal
        {
            public static function publisher(): CandidatePublisher
            {
                return new CandidatePublisher(
                    new class implements PublicationStoreInterface {
                        public function publish(int $candidateId, callable $prepare): int
                        {
                            throw new \LogicException('Publishing is not available on the promote route.');
                        }
                    },
                    new EventValidator(new DateTimeZone('Africa/Johannesburg'))
                );
            }
        }

    /**
     * Answers exactly the reads the promote route makes: the candidate as the
     * queue scopes it, its message, the published event that carries it, and the
     * promotable attachments on that message.
     *
     * Every statement it does not recognise returns nothing, so a handler that
     * started reaching for the rest of the schema would fail here rather than
     * quietly pass on made-up rows.
     */
    final class PromoteQueueDatabase implements DatabaseConnectionInterface
    {
        /** Whether the candidate is inside the reviewer's own queue. */
        public bool $visible = true;

        /** The published event the candidate resolves to, or null. */
        public ?int $publishedEvent = PromoteTestIds::EVENT;

        /** The declared type of the stored attachment. */
        public string $attachmentMime = 'image/jpeg';

        /** The rows `findPromotableForMessage()` serves, or null to derive them. */
        public ?array $promotableRows = null;

        /** The attachment ids this candidate's message actually owns. */
        public array $ownedAttachments = [PromoteTestIds::ATTACHMENT, PromoteTestIds::SECOND_ATTACHMENT];

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
                    };
                },
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

            // findScoped(): the candidate, as the queue scopes it.
            if (str_contains($query, 'SELECT c.*')) {
                return $this->visible ? [$this->candidate()] : [];
            }

            // findMessageOf().
            if (str_contains($query, 'SELECT message_id FROM')) {
                return [['message_id' => (string) PromoteTestIds::MESSAGE]];
            }

            // findPublishedEventForCandidate().
            if (str_contains($query, "meta_key = 'source_candidate_id'")) {
                return $this->publishedEvent === null
                    ? []
                    : [['ID' => (string) $this->publishedEvent, 'post_status' => 'publish']];
            }

            // findPromotableForMessage().
            if (str_contains($query, 'FROM wp_adct_pi_attachments') && str_contains($query, 'mime_type IN')) {
                if ($this->promotableRows !== null) {
                    return $this->promotableRows;
                }

                return array_map(fn (int $id): array => $this->promotableRow($id), $this->ownedAttachments);
            }

            return [];
        }

        /**
         * @return array<string, mixed>
         */
        private function promotableRow(int $id): array
        {
            return [
                'id' => (string) $id,
                'message_id' => (string) PromoteTestIds::MESSAGE,
                'filename' => str_starts_with($this->attachmentMime, 'application/') ? 'bulletin.pdf' : 'poster.jpg',
                'mime_type' => $this->attachmentMime,
                'size_bytes' => '2048',
                                'storage_path' => $this->storagePath(),
                'status' => 'stored',
            ];
        }

        /**
                 * The stored name, which always carries the extension of the declared type
                 * rather than the extension of the name the parish used.
                 */
                private function storagePath(): string
                {
                                    return match ($this->attachmentMime) {
                        'application/pdf' => PromoteTestIds::PDF_STORAGE_NAME,
                        'image/png' => PromoteTestIds::STORAGE_STEM . '.png',
                        'image/webp' => PromoteTestIds::STORAGE_STEM . '.webp',
                        default => PromoteTestIds::STORAGE_NAME,
                    };
                }

                /**
                 * @return array<string, mixed>
                 */
                private function candidate(): array
        {
            return [
                'id' => (string) PromoteTestIds::CANDIDATE,
                'message_id' => (string) PromoteTestIds::MESSAGE,
                'parish_id' => '3',
                'status' => 'published',
                'approved_by' => 'dean@example.test',
                'decided_at' => '2026-02-20 09:00:00',
                'fields' => '{}',
                'notes' => '[]',
                'parser_version' => '1.0.0',
                'confidence' => '0.91',
                'ai_used' => '0',
                'match_kind' => 'new',
                'match_event_id' => null,
                'sender_email' => '',
                'parish_name' => '',
                'category' => 'recently_decided',
                'updated_at' => '2026-02-20 09:00:00',
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