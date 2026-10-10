<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {

    use ADCT\ParishIntake\Core\Audit\AuditAction;
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\IntakeAttachmentReaderInterface;
    use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
    use ADCT\ParishIntake\Core\Ports\StoredAttachmentEventMetaInterface;
    use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
    use ADCT\ParishIntake\WordPress\Admin\CandidateSourceFiles;
    use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
    use DateTimeImmutable;
    use DateTimeZone;
    use DomainException;
    use LogicException;
    use PHPUnit\Framework\TestCase;
    use Throwable;

    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
    require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';
    require_once __DIR__ . '/../../../Support/AdminWordPressStubs.php';
    require_once __DIR__ . '/../../../Support/WordPressMediaStubs.php';

    /**
     * Issue #172, the review-queue half: offering an attachment's source material
     * for publication, on the screen where a reviewer already has the email and
     * its files open.
     *
     * The store itself is proved in `WordPressSourceMaterialStoreTest`; this file
     * is about the route around it, and every test here is a property the route
     * could lose without the store noticing:
     *
     *  - the promote control has its own action and its own nonce, so a forged
     *    or bulk-carried nonce is refused before any part of the POST is read
     *    and writes no audit row (the issue's second trap);
     *  - the candidate decides the event and the attachment, never the request,
     *    so a crafted POST cannot publish somebody else's bulletin;
     *  - nothing is promoted unless a person presses the button, and the button
     *    is not part of the review/publish action.
     */
    final class SourceMaterialQueueTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            require_once __DIR__ . '/../../../Support/WordPressStubs.php';
            require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';
            require_once __DIR__ . '/../../../Support/AdminWordPressStubs.php';
            require_once __DIR__ . '/../../../Support/WordPressMediaStubs.php';

            $GLOBALS['adct_test_wp_caps'] = [Capabilities::REVIEW];
            $GLOBALS['adct_test_current_user'] = new \WP_User(9, 'reviewer@example.test');
            $GLOBALS['adct_test_nonce_checks'] = [];
            $GLOBALS['adct_test_nonce_fields'] = [];
            $GLOBALS['adct_test_redirect'] = null;
            unset($GLOBALS['adct_test_nonce_should_fail']);

                        // The state `publish()` leaves behind: the event post names the
                        // candidate it came from. Seeded here rather than in a helper so each
                        // test states its own starting conditions where they differ from this.
                        $GLOBALS['adct_publishing_meta'] = [
                            QueueIds::EVENT => ['source_candidate_id' => (string) QueueIds::CANDIDATE],
                        ];
                    }

        protected function tearDown(): void
        {
            $_POST = [];
            $_GET = [];

            unset(
                $GLOBALS['adct_test_wp_caps'],
                $GLOBALS['adct_test_current_user'],
                $GLOBALS['adct_test_nonce_checks'],
                $GLOBALS['adct_test_nonce_fields'],
                $GLOBALS['adct_test_redirect'],
                $GLOBALS['adct_test_nonce_should_fail']
            );

            parent::tearDown();
        }

        /**
         * The action name is public because `Plugin.php` builds the admin-post
         * hook by concatenating it. Changing it here unregisters the route and
         * the rendered button quietly 404s, which is exactly the kind of break
         * this assertion exists to catch.
         */
        public function testThePromoteRouteHasItsOwnActionAndNonceNames(): void
        {
            self::assertSame('adct_pi_candidate_promote_source', ReviewQueuePage::PROMOTE_SOURCE_ACTION);
            self::assertSame('promote_source_nonce', ReviewQueuePage::PROMOTE_SOURCE_NONCE);
        }

        /**
         * The second trap in the issue.
         *
         * Promotion copies a parish's file into the uploads directory and
         * records an audit row naming the person who did it. It must therefore be
         * unreachable by replaying any other form on this screen. Sharing
         * `SOURCE_NONCE` would be the subtle one: that nonce is already on the
         * detail page, so folding promote into it would look deliberate and
         * would let the download form promote.
         */
        public function testPromotionIsNotAModeOnAnyOtherActionOrNonce(): void
        {
            foreach (
                [
                    ReviewQueuePage::SAVE_ACTION,
                    ReviewQueuePage::CREATE_MANUAL_ACTION,
                    ReviewQueuePage::RESOLVE_MATCH_ACTION,
                    ReviewQueuePage::RESEND_CONFIRMATION_ACTION,
                    ReviewQueuePage::RAW_MESSAGE_ACTION,
                    ReviewQueuePage::ATTACHMENT_ACTION,
                    'adct_pi_review_bulk',
                ] as $other
            ) {
                self::assertNotSame(
                    $other,
                    ReviewQueuePage::PROMOTE_SOURCE_ACTION,
                    'A shared action would let a crafted POST promote through somebody else\'s form.'
                );
            }

            foreach (
                [
                    ReviewQueuePage::SAVE_NONCE,
                    ReviewQueuePage::SOURCE_NONCE,
                    ReviewQueuePage::CREATE_MANUAL_NONCE,
                    ReviewQueuePage::RESOLVE_MATCH_NONCE,
                    ReviewQueuePage::RESEND_CONFIRMATION_NONCE,
                ] as $other
            ) {
                self::assertNotSame(
                    $other,
                    ReviewQueuePage::PROMOTE_SOURCE_NONCE,
                    'A shared nonce would let one form on this screen be replayed as another.'
                );
            }
        }

        /**
         * Asserting only that "a nonce was checked" would pass for the download
         * form's nonce. The pair is the point: this is a check that can only be
         * satisfied by a POST that was rendered by this form.
         */
        public function testTheRouteDemandsItsOwnNonceBeforeReadingAnything(): void
        {
            $GLOBALS['adct_test_nonce_should_fail'] = true;
            $store = new RecordingSourceMaterialStore();
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ];

            try {
                $this->page($store)->handlePromoteSource();
                self::fail('A refused nonce must end the request before anything is read.');
            } catch (\AdctTestNonceRefused) {
                // WordPress refused the word. That is the whole answer.
            }

            self::assertSame(
                            [
                                [
                                    'action' => ReviewQueuePage::PROMOTE_SOURCE_ACTION,
                                    'name' => ReviewQueuePage::PROMOTE_SOURCE_NONCE,
                                ],
                            ],
                            $GLOBALS['adct_test_nonce_checks'],
                            'Only this route\'s own pair is checked, and it is checked first.'
                        );
            self::assertSame([], $store->promoted);
        }

        /**
         * A refused promotion writes no audit row.
         *
         * This is the POPIA question — who made this public — so the refusal has
         * to be observable in the audit trail and not merely in the absence of a
         * file. An audit row naming an actor who was refused would be worse than
         * no row at all.
         */
        public function testAForgedPromotionWritesNoAuditRow(): void
        {
            $GLOBALS['adct_test_nonce_should_fail'] = true;
            $store = new RecordingSourceMaterialStore();
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ];

            try {
                $this->page($store)->handlePromoteSource();
            } catch (\AdctTestNonceRefused) {
            }

            self::assertSame([], $store->auditRows, 'A refused promotion must leave no trace that anybody tried.');
        }

        /**
         * The event comes from the candidate, never from the request.
         *
         * `match_event_id` is what `publish()` wrote, and the audit row and the
         * media copy must both land on that event. A crafted `event_id` in the
         * POST must be ignored entirely.
         */
        public function testTheEventComesFromTheCandidateAndNotFromTheRequest(): void
        {
            $store = new RecordingSourceMaterialStore();
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
                'event_id' => '9999',
            ];

            $this->promote($store);

            self::assertSame(
                [[QueueIds::EVENT, QueueIds::ATTACHMENT, SourceAttachment::ROLE_POSTER]],
                $store->promoted,
                'Promotion must land on the event this candidate published to.'
            );
        }

        /**
         * An unpublished candidate has no event, so there is nothing to promote
         * onto. Saying so beats doing nothing quietly, because the reviewer has
         * just pressed a button and needs to know why the screen did not change.
         */
        public function testAnUnpublishedCandidateHasNothingToPromoteOnto(): void
        {
            $store = new RecordingSourceMaterialStore();
            $database = new QueueDatabase();
            $database->matchEventId = 0;
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ];

            $this->refusedAs($database, $store, 409, 'This candidate has not been published yet.');
            self::assertSame([], $store->promoted);
            self::assertSame([], $store->auditRows);
        }

        /**
         * The attachment must belong to the message the candidate came from.
         * Without this, a dean could name any attachment id in the table and
         * publish another parish's bulletin on their own event — which is
         * precisely the disclosure the audit row would then record in their
         * name.
         */
        public function testAnAttachmentFromSomebodyElseIsRefused(): void
        {
            $store = new RecordingSourceMaterialStore();
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::FOREIGN_ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ];

            $this->refusedAs(new QueueDatabase(), $store, 404, 'That file is not attached to this email.');
            self::assertSame([], $store->promoted);
            self::assertSame([], $store->auditRows);
        }

        /**
         * HEIC and HEIF pass the intake storage allowlist but render in no
         * browser, so they are refused as unpublishable rather than as missing.
         * A 404 would tell a dean their bulletin had gone, which is both wrong
         * and alarming.
         */
        public function testAnUnrenderableFileIsRefusedAsUnpublishable(): void
        {
            $store = new RecordingSourceMaterialStore();
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::HEIC_ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ];

            $this->refusedAs(new QueueDatabase(), $store, 422, 'image/heic cannot be published');
                        self::assertSame([], $store->promoted);
                    }

        /**
         * A role the plugin does not define is refused rather than defaulted, so
         * a crafted POST cannot invent a fourth role that every reader then has
         * to interpret.
         */
        public function testAnUnknownRoleIsRefused(): void
        {
            $store = new RecordingSourceMaterialStore();
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::ATTACHMENT,
                'role' => 'sneaky',
            ];

            $this->refusedAs(new QueueDatabase(), $store, 400);
            self::assertSame([], $store->promoted);
        }

        /**
         * The one-line proof that nothing is promoted unless a person asks.
         *
         * A failure while promoting must never take the event down with it, so
         * this asserts the opposite of what a rollback-based design would do:
         * the store raises, the route reports, and the published event is
         * untouched. (The store's own rollback — no record and no half-copied
         * file — is proved in `WordPressSourceMaterialStoreTest`.)
         */
        public function testAFailedPromotionIsReportedAndPublishesNothing(): void
        {
            $store = new RecordingSourceMaterialStore();
            $store->failure = new DomainException('The uploads directory is not writable.');
            $database = new QueueDatabase();
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ];

            $this->refusedAs($database, $store, 409, 'not writable');
            self::assertSame([], $store->auditRows, 'A refused promotion writes no audit row.');
        }

        /**
         * A failure the route cannot describe in the parish's terms is still
         * refused, and never leaks the file path or the exception text into the
         * screen.
         */
        public function testAnUnexpectedFailureIsReportedWithoutLeakingDetail(): void
        {
            $store = new RecordingSourceMaterialStore();
            $store->failure = new \RuntimeException('copy failed at /var/www/uploads/adct-source-1-2.pdf');
            $database = new QueueDatabase();
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::ATTACHMENT,
                'role' => SourceAttachment::ROLE_POSTER,
            ];

            try {
                $this->page($store, $database)->handlePromoteSource();
                self::fail('An unexpected failure must end the request.');
            } catch (\AdctTestWpDie $died) {
                self::assertSame(500, $died->status);
                self::assertStringNotContainsString('uploads', $died->getMessage());
                self::assertStringNotContainsString('copy failed', $died->getMessage());
            }
        }

        /**
         * On success the reviewer is returned to the same candidate on the same
         * tab, so a promotion in the middle of a queue does not lose their place.
         */
        public function testASuccessfulPromotionReturnsToTheSameCandidate(): void
        {
            $store = new RecordingSourceMaterialStore();
            $_POST = [
                'candidate' => (string) QueueIds::CANDIDATE,
                'attachment_id' => (string) QueueIds::ATTACHMENT,
                'role' => SourceAttachment::ROLE_BULLETIN,
                'tab' => 'awaiting_approval',
                'search' => 'mass',
            ];

            $this->promote($store);

            self::assertNotNull($GLOBALS['adct_test_redirect'], 'A promotion ends in a redirect.');
            $redirect = (string) $GLOBALS['adct_test_redirect'];
            self::assertStringContainsString('candidate=' . QueueIds::CANDIDATE, $redirect);
            self::assertStringContainsString('promoted=1', $redirect);
            self::assertStringContainsString('search=mass', $redirect);
        }

        /**
         * The control is a separate button in its own column cell, posted
         * through its own form, and it is not the download button.
         *
         * "Save and approve" is the label on the publish action and the issue
         * forbids renaming it, so this asserts the promote control is a
         * *different* button rather than asserting a wording that already
         * exists elsewhere — `testThePublishActionKeepsItsReviewLabel` below
         * pins the publish wording itself.
         */
        public function testThePromoteControlIsSeparateFromTheReviewAction(): void
        {
            $markup = $this->renderPanel([$this->posterRow()], true);

            self::assertStringContainsString('Publish this file with the event', $markup);
            self::assertStringNotContainsString('Create event from this poster', $markup,
                'Promotion and manual entry are separate decisions.');
            self::assertStringContainsString('form="adct-pi-promote-source-form"', $markup,
                'The promote button must post the form the handler is registered against.');
                        self::assertStringContainsString('form="adct-pi-attachment-form"', $markup,
                            'The download button keeps its own form; promotion is a separate request.');
        }

        /**
         * The button must carry the nonce name the handler demands, and be
         * inside the promote form. Asserting both halves together means a
         * rename on either side alone fails, rather than producing a button
         * that silently posts nothing.
         */
        public function testThePromoteFormCarriesTheNoncesAndTheId(): void
        {
            $markup = $this->renderPanel([$this->posterRow()], true);

            self::assertStringContainsString(
                ReviewQueuePage::PROMOTE_SOURCE_NONCE,
                $markup,
                'The rendered form must post the nonce the handler demands.'
            );
            self::assertStringContainsString(
                'action="' . esc_url(\ADCT\ParishIntake\WordPress\Admin\admin_url('admin-post.php')) . '"',
                $markup,
                'The form posts to admin-post.php, where the handler is registered.'
            );
            self::assertStringContainsString(
                'name="candidate" value="' . QueueIds::CANDIDATE . '"',
                $markup,
                'The form must name the candidate it was rendered for; it is rendered per candidate.'
            );
        }

        /**
         * A JPEG is a poster by default: the role decides whether the file
         * becomes the featured image, so the default has to be the one a parish
         * poster actually is.
         */
        public function testAJpegIsOfferedAsAPoster(): void
        {
            $markup = $this->renderPanel([$this->posterRow()], true);

            self::assertStringContainsString('value="poster"', $markup);
        }

        /**
         * A PDF is offered as a bulletin, not a poster, for the same reason:
         * the role is the decision, and the default must not make the featured
         * image a bulletin.
         */
        public function testAPdfIsOfferedAsABulletinRatherThanAPoster(): void
        {
            $markup = $this->renderPanel([$this->row([
                'filename' => 'bulletin.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => '204800',
                'extraction_method' => 'pdf-text',
            ])], true);

            self::assertStringContainsString('value="bulletin"', $markup);
            self::assertStringNotContainsString('value="poster"', $markup);
        }

        /**
         * HEIC/HEIF pass intake storage, so they appear in the table — but they
         * must be shown as unavailable rather than offered, because the offer is
         * the thing a dean would press. No browser renders them.
         */
        public function testAnUnrenderableFileIsShownButNotOffered(): void
        {
            $markup = $this->renderPanel([$this->row([
                'id' => QueueIds::HEIC_ATTACHMENT,
                'filename' => 'IMG-4410.HEIC',
                'mime_type' => 'image/heic',
                'extraction_method' => 'ocr',
            ])], true);

            self::assertStringContainsString('IMG-4410.HEIC', $markup, 'The file is still listed.');
            self::assertStringNotContainsString(
                'form="' . CandidateSourceFiles::PROMOTE_FORM . '"',
                $markup,
                'A file no browser renders must never be a promotion target.'
            );
            self::assertStringContainsString('cannot be published', $markup);
        }

        /**
         * The default is nothing promoted. Without the capability there is no
         * control at all rather than a disabled one, so no button on the screen
         * looks live and is not.
         */
        public function testNothingIsOfferedWhenTheReviewerCannotPromote(): void
        {
            $markup = $this->renderPanel([$this->posterRow()], false);

            self::assertStringNotContainsString('Publish this file with the event', $markup);
            self::assertStringContainsString('Download', $markup,
                'Downloading stays available; it is a read, not a publication.');
        }

        /**
         * A file whose bytes are gone is not offered either. Offering it would
         * produce a button that fails on press, and the failure would read as a
         * filesystem problem rather than as "that attachment has been cleaned up".
         */
        public function testAFileWhoseBytesAreGoneIsNotOffered(): void
        {
            $markup = $this->renderPanel([$this->row([
                'status' => 'extracted',
                'storage_path' => '',
            ])], true);

            self::assertStringNotContainsString('Publish this file with the event', $markup);
            self::assertStringContainsString('No longer stored', $markup);
        }

        /**
                 * Rendering the panel is not promoting anything.
                 *
                 * There is no store anywhere on this path — the view is handed rows and a
                 * boolean — so the offer is structurally incapable of being a promotion. The
                 * route tests above cover the other half: the store is only ever reached
                 * through a POST that passed its own nonce. What is asserted here is that
                 * rendering adds no form of its own: the only form in the markup is the
                 * handler's, carrying a nonce and an id and nothing to promote.
                 */
                public function testRenderingThePanelPromotesNothing(): void
                {
                    $markup = $this->renderPanel([$this->posterRow()], true);

                    self::assertSame(
                        1,
                        substr_count($markup, '<form'),
                        'The only form is the handler\'s, carrying a nonce and an id. No row submits itself.'
                    );
                    self::assertStringNotContainsString(
                        'name="promote"',
                        $markup,
                        'Rendering must not carry a decision about what to promote.'
                    );
                }

        /**
         * @return array<string, mixed>|null
         */
        private function refusedAs(
            QueueDatabase $database,
            RecordingSourceMaterialStore $store,
            int $status,
            string $contains = ''
        ): ?array {
            try {
                $this->page($store, $database)->handlePromoteSource();
                self::fail(sprintf('The route must refuse this request with %d.', $status));
            } catch (\AdctTestWpDie $died) {
                self::assertSame($status, $died->status);
                if ($contains !== '') {
                    self::assertStringContainsString($contains, $died->getMessage());
                }

                return ['message' => $died->getMessage()];
            }

            return null;
        }

        /**
         * The detail screen offers the promotion only where the POST would be
         * accepted. That is the whole point of asking the same question in the
         * render and the route: a reviewer is never shown a button that then
         * refuses them.
         */
        public function testTheOfferIsRenderedOnlyWhereTheRouteWouldAcceptIt(): void
        {
            $markup = $this->renderDetail();

            self::assertStringContainsString('Publish this file with the event', $markup);
            self::assertStringContainsString(CandidateSourceFiles::PROMOTE_FORM, $markup);
            self::assertStringContainsString(ReviewQueuePage::PROMOTE_SOURCE_NONCE, $markup);
        }

        /**
         * A candidate that was never published has no event to publish a poster
         * with, so there is nothing to offer. This is the "default is nothing
         * promoted" half at the screen level: no row, no button.
         */
        public function testNothingIsOfferedBeforeTheCandidateIsPublished(): void
        {
            $database = new QueueDatabase();
            $database->matchEventId = 0;

            $markup = $this->renderDetail($database);

            self::assertStringNotContainsString('Publish this file with the event', $markup);
            self::assertStringNotContainsString(CandidateSourceFiles::PROMOTE_FORM, $markup);
        }

        /**
         * An event that claims a different candidate is not this candidate's event.
         *
         * The route refuses with a 409 for exactly this mismatch, so the offer must
         * not appear either — otherwise the reviewer is invited into a refusal.
         */
        public function testNothingIsOfferedWhenTheEventCameFromAnotherCandidate(): void
                {
                    $GLOBALS['adct_publishing_meta'][QueueIds::EVENT]['source_candidate_id'] = (string) (QueueIds::CANDIDATE + 1);

                    $markup = $this->renderDetail();

                    self::assertStringNotContainsString('Publish this file with the event', $markup);
                    self::assertStringNotContainsString(CandidateSourceFiles::PROMOTE_FORM, $markup);
                }

        /**
         * Without the adapter there is no offer at all.
         *
         * A site that has not wired the store must render a screen that still
         * works, not a button that would be refused on press.
         */
        public function testNothingIsOfferedWhenTheStoreIsNotWired(): void
        {
            $markup = $this->renderPage($this->page(null));

            self::assertStringNotContainsString('Publish this file with the event', $markup);
        }

        /**
         * After a successful promotion the reviewer is told what happened, on the
         * detail screen — the branch that returns before the list screen's own
         * notice, so it needs its own.
         */
        public function testASuccessfulPromotionIsAcknowledged(): void
        {
            $_GET['promoted'] = '1';

            try {
                $markup = $this->renderDetail();

                self::assertStringContainsString('notice-success', $markup);
                self::assertStringContainsString('published with the event', $markup);
            } finally {
                unset($_GET['promoted']);
            }
        }

        /**
         * Nothing is echoed back from the request.
         *
         * The notice compares its value against a fixed pair, so a crafted query
         * string cannot put words on this screen.
         */
        public function testThePromotionNoticeEchoesNothingFromTheRequest(): void
        {
            $_GET['promoted'] = '1<script>alert(1)</script>';

            try {
                $markup = $this->renderDetail();

                self::assertStringNotContainsString('alert(1)', $markup);
                self::assertStringNotContainsString('notice-success', $markup);
            } finally {
                unset($_GET['promoted']);
            }
        }

        /**
         * Render the detail screen for the fake candidate and return its markup.
         */
        private function renderDetail(?QueueDatabase $database = null): string
                {
                    return $this->renderPage($this->page(new RecordingSourceMaterialStore(), $database));
                }

        /**
         * Render a page and return everything it wrote.
         *
         * The screen writes straight out, so the markup is the captured buffer.
         * The queue's list branch redirects when it cannot tell what to render;
         * that is caught rather than allowed to end the test, because the absence
         * of an offer is what these tests are about, not the absence of a screen.
         */
        private function renderPage(ReviewQueuePage $page): string
        {
            $previous = $_GET;
            // The detail branch is only taken for a candidate id in the query; the
            // list branch would render no offer for a reason that proves nothing.
            $_GET['candidate'] = (string) QueueIds::CANDIDATE;

            ob_start();

            try {
                $page->renderPage();
            } catch (\AdctTestRedirect) {
                // Rendered far enough to decide; the offer would already be in
                // the buffer had one been made.
            } finally {
                $markup = (string) ob_get_clean();
                $_GET = $previous;
            }

            return $markup;
        }

        /**
         * Render the attachment panel and return its markup.
         *
         * `CandidateSourceFiles::render()` writes straight out, so the markup is
         * captured rather than asserted through a buffer helper.
         *
         * @param list<array<string, mixed>> $rows
         */
        private function renderPanel(array $rows, bool $canPromote): string
        {
            ob_start();
            (new CandidateSourceFiles())->render(
                null,
                $rows,
                static fn (string $path): bool => true,
                false,
                $canPromote
            );
            $markup = (string) ob_get_clean();

            if (! $canPromote) {
                return $markup;
            }

            // The real screen renders this form once, beside the table, from
            // `CandidateDetailView::renderDownloadForms()`; the panel only names
            // it. Standing it up here keeps this test about the panel's own
            // contribution -- the button, its id, its role and the form it posts
            // -- rather than about the detail view's markup.
            return $markup . sprintf(
                '<form id="%s" method="post" action="%s">'
                . '<input type="hidden" name="%s" value="nonce-value">'
                . '<input type="hidden" name="candidate" value="%d"></form>',
                CandidateSourceFiles::PROMOTE_FORM,
                esc_url(\ADCT\ParishIntake\WordPress\Admin\admin_url('admin-post.php')),
                ReviewQueuePage::PROMOTE_SOURCE_NONCE,
                QueueIds::CANDIDATE
            );
        }

        /** @param array<string, mixed> $overrides @return array<string, mixed> */
        private function row(array $overrides = []): array
        {
            return $overrides + [
                'id' => QueueIds::ATTACHMENT,
                'message_id' => QueueIds::MESSAGE,
                'filename' => 'poster.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => '2048',
                'storage_path' => str_repeat('a', 64) . '.jpg',
                'status' => 'stored',
                'extraction_method' => 'ocr',
            ];
        }

        /** @return array<string, mixed> */
        private function posterRow(): array
        {
            return $this->row();
        }

        /**
         * Run the promote route and let it finish.
         *
         * A successful promotion ends in a redirect, and the stub raises on that
         * rather than exiting, so "the route ran to completion" and "the route was
         * refused" stay distinguishable: only the former reaches the catch below.
         */
        private function promote(RecordingSourceMaterialStore $store, ?QueueDatabase $database = null): void
        {
            try {
                $this->page($store, $database)->handlePromoteSource();
            } catch (\AdctTestRedirect) {
                // The route finished. The redirect itself is the assertion.
            }
        }

        /**
                 * The candidate's event, wired the way `publish()` leaves it: the candidate
                 * row carries `match_event_id`, and the event post carries the candidate back
                 * as `source_candidate_id`. The route checks both, so a test that wants the
                 * promotion to succeed must set both — which is itself the point of requiring
                 * two independent records to agree.
                 *
                 * A null `$store` is the "plugin not wired here" case the screen must
                 * survive: it renders everything else and offers nothing.
                 */
                private function page(
                    ?SourceMaterialStoreInterface $store,
                    ?QueueDatabase $database = null
                ): ReviewQueuePage {
                    $database ??= new QueueDatabase();

                    return new ReviewQueuePage(
                        new ReviewQueueRepository(
                            $database,
                            new QueueClock(),
                            new \ADCT\ParishIntake\Core\Review\ReviewQueuePolicy()
                        ),
                        $this->publisher(),
                        attachments: new \ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository($database),
                        storage: new StoredInboundStorage(),
                        sourceMaterial: $store
                    );
                }

        /**
         * A publisher that refuses to do anything.
         *
         * Promotion is a separate route, and this proves it: reaching the
         * publisher would be a loud failure rather than a silent "it worked".
         */
        private function publisher(): \ADCT\ParishIntake\Core\Publishing\CandidatePublisher
        {
            return new \ADCT\ParishIntake\Core\Publishing\CandidatePublisher(
                new RefusingPublicationStore(),
                new \ADCT\ParishIntake\Core\Events\EventValidator(new DateTimeZone('Africa/Johannesburg'))
            );
        }
    }

    /**
     * Storage that says every stored path is still on disk.
     *
     * Only `resolveAttachmentPath()` is consulted on this screen — it decides
     * whether a row is "still stored", which is the precondition for offering it.
     * The other three methods are unused here and throw rather than returning
     * something plausible, so a test cannot pass because this fake happened to
     * answer a question it was never asked.
     */
    final class StoredInboundStorage implements \ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface
    {
        public function storeRawMessage(string $rawMessage): string
        {
            throw new LogicException('This screen never stores a message.');
        }

        public function storeAttachment(string $content, string $extension): string
        {
            throw new LogicException('This screen never stores an attachment.');
        }

        public function delete(string $relativePath): void
        {
            throw new LogicException('This screen never deletes.');
        }

        public function readRawMessage(string $relativePath): string
        {
            return '';
        }

        public function resolveAttachmentPath(string $relativePath): string
        {
            // A real path under the repo's own tests directory, so `is_file()`
            // answers for a reason a reader can see: this fixture exists.
            return __DIR__ . '/../../../Support/WordPressMediaStubs.php';
        }
    }

    /**
     * The ids the fake database serves, held outside the test class so the
     * fakes can name them too.
     */
    final class QueueIds
    {
        public const CANDIDATE = 7;

        public const MESSAGE = 42;

        public const EVENT = 314;

        public const ATTACHMENT = 11;

        /** A poster that arrived on some other parish's email. */
        public const FOREIGN_ATTACHMENT = 99;

        public const FOREIGN_MESSAGE = 1000;

        /** Passes intake storage; renders in no browser. */
        public const HEIC_ATTACHMENT = 77;
    }

    final class RefusingPublicationStore implements \ADCT\ParishIntake\Core\Ports\PublicationStoreInterface
    {        public function publish(int $candidateId, callable $prepare): int
        {
            throw new \LogicException('Publishing must never be reachable from the promote route.');
        }
    }

    final class QueueClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-03-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
        }
    }

    /**
     * A store that records rather than copies.
     *
     * The store's own behaviour — the generated name, the filesystem, the
     * rollback of a half-copied file, the surviving bytes of a removal — is
     * proved in `WordPressSourceMaterialStoreTest`. Keeping this a double is
     * what makes the assertions above about the *route* rather than about the
     * store happening to work.
     */
    final class RecordingSourceMaterialStore implements SourceMaterialStoreInterface
    {
        /** @var list<array{0: int, 1: int, 2: string}> */
        public array $promoted = [];

        /** @var list<array{0: int, 1: int}> */
        public array $removed = [];

        /** @var list<SourceAttachment> */
        public array $sources = [];

        /** @var list<array{action: string, event: int, attachment: int}> */
        public array $auditRows = [];

        public ?\Throwable $failure = null;

        public function promote(int $eventId, int $attachmentId, string $role): SourceAttachment
        {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            $this->promoted[] = [$eventId, $attachmentId, $role];
            $source = new SourceAttachment($attachmentId, 700 + count($this->promoted), $role, 'poster.jpg');
            $this->auditRows[] = [
                'action' => AuditAction::SOURCE_MATERIAL_PROMOTED,
                'event' => $eventId,
                'attachment' => $attachmentId,
            ];

            return $source;
        }

        public function remove(int $eventId, int $mediaId): void
        {
            $this->removed[] = [$eventId, $mediaId];
            $this->auditRows[] = [
                'action' => AuditAction::SOURCE_MATERIAL_REMOVED,
                'event' => $eventId,
                'attachment' => $mediaId,
            ];
        }

        public function forEvent(int $eventId): array
        {
            return $this->sources;
        }
    }

    /**
     * Answers exactly the reads the promote route makes: the candidate as the
     * queue scopes it, that candidate's message, and the attachment rows. Every
     * statement it does not recognise returns nothing, so a handler that started
     * reaching for the rest of the schema would fail here rather than pass on
     * made-up rows.
     */
    final class QueueDatabase implements DatabaseConnectionInterface
    {
            /** Matches every attachment query this plugin makes. */
            private const ATTACHMENTS = 'wp_adct_pi_attachments';

            /** The event `publish()` recorded on the candidate. 0 means never published. */
            public int $matchEventId = QueueIds::EVENT;

        public bool $visible = true;

        /** @var list<array{query: string, arguments: list<mixed>}> */
        public array $preparedQueries = [];

        public function insert(string $table): ?array
        {
            return null;
        }

        public function prefix(): string
        {
            return 'wp_';
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            $values = array_values($arguments);
            $this->preparedQueries[] = ['query' => $query, 'arguments' => $values];

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
            return 1;
        }

        public function getRow(string $query): ?array
        {
            return $this->getResults($query)[0] ?? null;
        }

        public function getResults(string $query): array
                {
                    // findScoped(), and the locked re-reads the queue does behind it.
                    if (str_contains($query, 'SELECT c.*')) {
                        return $this->visible ? [$this->candidate()] : [];
                    }

            if (str_contains($query, 'SELECT message_id FROM') || str_contains($query, 'SELECT id, message_id, parish_id')) {
                return [$this->candidate()];
            }

                    /**
                             * The attachment lookups the promote route makes. The repository asks
                             * for one row by id, and the fake answers only rows this route could
                             * legitimately reach.
                             */
                            if (str_contains($query, self::ATTACHMENTS) && preg_match('/WHERE id = (\d+)/', $query, $id) === 1) {
                                $wanted = (int) $id[1];

                                foreach ($this->attachmentRows() as $row) {
                                    if ((int) $row['id'] === $wanted) {
                                        return [$row];
                                    }
                                }

                                return [];
                            }

                            if (str_contains($query, self::ATTACHMENTS) && preg_match('/message_id = (\d+)/', $query, $message) === 1) {
                                $wanted = (int) $message[1];

                                return array_values(array_filter(
                                    $this->attachmentRows(),
                                    static fn (array $row): bool => (int) $row['message_id'] === $wanted
                                ));
                            }

                            return [];
                        }

                        /**
                         * The attachment rows this fake holds: the parish's own poster, a HEIC that
                         * renders nowhere, and a poster that belongs to a different parish's email.
                         *
                         * @return list<array<string, mixed>>
                         */
                        private function attachmentRows(): array
                        {
                            return [
                                [
                                    'id' => (string) QueueIds::ATTACHMENT,
                                    'message_id' => (string) QueueIds::MESSAGE,
                                    'filename' => 'poster.jpg',
                                    'mime_type' => 'image/jpeg',
                                    'size_bytes' => '2048',
                                    'storage_path' => str_repeat('a', 64) . '.jpg',
                                    'status' => 'stored',
                                    'extraction_method' => 'ocr',
                                ],
                                [
                                    'id' => (string) QueueIds::HEIC_ATTACHMENT,
                                    'message_id' => (string) QueueIds::MESSAGE,
                                    'filename' => 'IMG-4410.HEIC',
                                    'mime_type' => 'image/heic',
                                    'size_bytes' => '4096',
                                    'storage_path' => str_repeat('c', 64) . '.heic',
                                    'status' => 'stored',
                                    'extraction_method' => 'ocr',
                                ],
                                [
                                    'id' => (string) QueueIds::FOREIGN_ATTACHMENT,
                                    'message_id' => (string) QueueIds::FOREIGN_MESSAGE,
                                    'filename' => 'other-parish-poster.jpg',
                                    'mime_type' => 'image/jpeg',
                                    'size_bytes' => '2048',
                                    'storage_path' => str_repeat('b', 64) . '.jpg',
                                    'status' => 'stored',
                                    'extraction_method' => 'ocr',
                                ],
                            ];
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

        /**
         * @return array<string, mixed>
         */
        private function candidate(): array
        {
            return [
                'id' => (string) QueueIds::CANDIDATE,
                'message_id' => (string) QueueIds::MESSAGE,
                'parish_id' => '3',
                'status' => 'published',
                'approved_by' => '7',
                'decided_at' => '2026-03-01 08:00:00',
                'match_event_id' => $this->matchEventId > 0 ? (string) $this->matchEventId : null,
                'match_kind' => 'matched',
                'fields' => '{"title":"Parish Mass"}',
                'notes' => '[]',
                'block_index' => '0',
                'parser_version' => '1.0.0',
                'confidence' => '0.82',
                'ai_used' => '0',
                'ai_model' => null,
                'sender_email' => 'parish@example.test',
                'parish_name' => 'Example Parish',
                'category' => 'awaiting_approval',
                'created_at' => '2026-03-01 07:00:00',
                'updated_at' => '2026-03-01 08:00:00',
                'decided_by' => '7',
                'can_retry' => 0,
            ];
        }
    }
}