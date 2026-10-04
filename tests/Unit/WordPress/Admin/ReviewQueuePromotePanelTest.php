<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
    require_once dirname(__DIR__, 4) . '/tests/Support/WordPressStubs.php';
    // current_user_can() and \WP_User live in the capability stubs, exactly as in
    // ReviewQueuePromoteSourceMaterialTest.php.
    require_once dirname(__DIR__, 4) . '/tests/Support/WordPressCapabilityStubs.php';

    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
    use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
    use ADCT\ParishIntake\Core\Events\EventValidator;
    use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    /**
     * Issue #172: what the review queue *offers*, as opposed to what the promote
     * route does with it.
     *
     * `ReviewQueuePromoteSourceMaterialTest` covers the handler. This covers the
     * display decisions that decide whether the handler is reachable at all:
     *
     * - **The panel reads the same list the route acts on.** It is built from
     *   `findPromotableForMessage()`, so a file the route would refuse is never
     *   offered, and a file it would accept is never hidden.
     * - **The panel appears only once the event exists.** Publishing attaches to
     *   a published event, so a candidate that is not one yet shows an
     *   explanation and no form rather than a button that would be refused.
     * - **The event id is derived, never read from the query string.**
     * - **The confirmation appears on the screen the redirect lands on.**
     * - **A broken repository does not fatal the screen.** The panel is on the
     *   display path, and an admin GET that dies takes the queue with it.
     */
    final class ReviewQueuePromotePanelTest extends TestCase
    {
        private array $savedPost;

        private array $savedGet;

        protected function setUp(): void
        {
            parent::setUp();

            $this->savedPost = $_POST;
            $this->savedGet = $_GET;
            $_POST = [];

            $GLOBALS['adct_test_wp_caps'] = [Capabilities::REVIEW];
            $GLOBALS['adct_test_current_user'] = new \WP_User(9, 'dean@example.test');
            $GLOBALS['adct_test_nonce_fields'] = [];
            $GLOBALS['adct_test_redirect'] = null;
            $GLOBALS['adct_test_is_admin'] = true;
        }

        protected function tearDown(): void
        {
            $_POST = $this->savedPost;
            $_GET = $this->savedGet;

            unset(
                $GLOBALS['adct_test_wp_caps'],
                $GLOBALS['adct_test_current_user'],
                $GLOBALS['adct_test_nonce_fields'],
                $GLOBALS['adct_test_redirect'],
                $GLOBALS['adct_test_is_admin']
            );

            parent::tearDown();
        }

        // ------------------------------------------------------------ the panel

        public function testAStoredPosterIsOfferedOnTheCandidateScreen(): void
        {
            $rendered = $this->renderDetail(new PanelQueueDatabase());

            self::assertStringContainsString(
                'Publish the poster or bulletin',
                $rendered,
                'A reviewer has to be able to see the choice exists before they look for it.'
            );
            self::assertStringContainsString(
                ReviewQueuePage::PROMOTE_SOURCE_ACTION,
                $rendered,
                'The panel must post to the route that checks its own nonce.'
            );
        }

        /**
         * The panel's list and the route's list are the same query. If they ever
         * diverge, the reviewer is either offered a file that will be refused or
         * denied one that would have worked, and neither failure is visible.
         */
        public function testThePanelOffersTheFilesThePromoteRouteWouldAccept(): void
        {
            $rendered = $this->renderDetail(new PanelQueueDatabase());

            self::assertStringContainsString(
                'poster.jpg',
                $rendered,
                'The stored poster is the one thing this screen exists to offer.'
            );
            self::assertStringContainsString(
                'value="' . PanelTestIds::ATTACHMENT . '"',
                $rendered,
                'The promotable file is selectable by id.'
            );
        }

        public function testACandidateWithNoPublishedEventShowsNoFormAtAll(): void
        {
            $database = new PanelQueueDatabase();
            $database->publishedEvent = null;

            $rendered = $this->renderDetail($database);

            self::assertStringContainsString(
                'has not been published as an event yet',
                $rendered,
                'A silent panel would read as a broken screen.'
            );
            self::assertStringNotContainsString(
                ReviewQueuePage::PROMOTE_SOURCE_ACTION,
                $rendered,
                'Publishing needs an event; offering the form first means a button that is refused.'
            );
        }

        public function testAPublishedEventWithNoPromotableFileExplainsRatherThanShowsAnEmptyTable(): void
        {
            $database = new PanelQueueDatabase();
            $database->promotableRows = [];

            $rendered = $this->renderDetail($database);

            self::assertStringContainsString(
                'no file that can be published',
                $rendered,
                'A notice whose only poster is a HEIC must say so rather than look broken.'
            );
            self::assertStringNotContainsString(
                ReviewQueuePage::PROMOTE_SOURCE_ACTION,
                $rendered,
                'An empty form would post nothing and look like a failure.'
            );
        }

        /**
         * The route derives the event from the candidate and reads no event id out
         * of the request. The panel has to agree, or the screen offers a button for
         * an event the route would not use.
         */
        public function testTheEventIdIsNeverTakenFromTheQueryString(): void
        {
            $database = new PanelQueueDatabase();
            $database->publishedEvent = null;

            $rendered = $this->renderDetail($database, extraGet: ['event_id' => '9999']);

            self::assertStringContainsString(
                'has not been published as an event yet',
                $rendered,
                'A crafted ?event_id must not conjure a published event into existence.'
            );
            self::assertStringNotContainsString(
                ReviewQueuePage::PROMOTE_SOURCE_ACTION,
                $rendered,
                'No event, no form, whatever the query string claims.'
            );
        }

        /**
         * The panel is on the display path. An admin GET that fatals takes the
         * review queue with it, and the queue is the screen the diocese opens when
         * a deadline has passed.
         */
        public function testABrokenAttachmentRepositoryDoesNotTakeTheScreenDown(): void
        {
            $database = new PanelQueueDatabase();
            $database->failPromotableLookup = true;

            $rendered = $this->renderDetail($database);

            self::assertStringContainsString(
                'Publish the poster or bulletin',
                $rendered,
                'The screen still renders; only the list inside the panel goes missing.'
            );
            self::assertStringContainsString(
                'no file that can be published',
                $rendered,
                'A failed read must read as nothing to publish, never as a crash.'
            );
            self::assertStringNotContainsString(
                ReviewQueuePage::PROMOTE_SOURCE_ACTION,
                $rendered,
                'Nothing can be selected from a list that could not be read.'
            );
        }

        public function testAScreenWithNoAttachmentRepositoryAtAllStillRenders(): void
        {
            $rendered = $this->renderDetail(new PanelQueueDatabase(), withoutAttachments: true);

            self::assertStringContainsString(
                'no file that can be published',
                $rendered,
                'Missing collaborators must read as nothing to publish, not as a crash.'
            );
        }

        // ----------------------------------------------------------- the notice

        /**
         * The promote route redirects to `?candidate=<id>&promoted=1`, which is the
         * detail screen. The confirmation therefore has to render there; a notice
         * that only appeared on the queue listing would leave the reviewer staring
         * at the event with no word about what just happened to the file.
         */
        public function testTheSuccessNoticeAppearsOnTheScreenTheRedirectLandsOn(): void
        {
            $rendered = $this->renderDetail(new PanelQueueDatabase(), notice: 'promoted');

            self::assertStringContainsString(
                'They are public from now on',
                $rendered,
                'The confirmation has to be on the page the redirect lands on, not only on the listing.'
            );
            self::assertStringContainsString(
                'will not delete the file',
                $rendered,
                'Detaching is not deletion, and saying so is what stops a reviewer assuming it is.'
            );
            self::assertStringContainsString(
                'class="notice notice-success"',
                $rendered,
                'It has to look like a success; a neutral banner for "this is now public" is a different message.'
            );
        }

        public function testTheSuccessNoticeOnlyAppearsWhenTheRedirectAskedForIt(): void
        {
            $rendered = $this->renderDetail(new PanelQueueDatabase());

            self::assertStringNotContainsString(
                'They are public from now on',
                $rendered,
                'A bookmarked or reloaded detail URL must not claim something was published.'
            );
        }

        /**
         * The same argument applies to the two notices that were already there
         * (manual entry, #218 resolve). Both redirect to `candidate=`, so both were
         * invisible; they are pinned here so a fix cannot be made narrowly for
         * `promoted` and leave the others broken.
         */
        public function testTheOtherSuccessNoticesThatRedirectToTheDetailAlsoAppear(): void
        {
            $resolved = $this->renderDetail(new PanelQueueDatabase(), notice: 'resolved');
            self::assertStringContainsString(
                'The ambiguous match is resolved',
                $resolved,
                'handleResolveMatch() redirects to the detail screen with ?resolved=1.'
            );

            $created = $this->renderDetail(new PanelQueueDatabase(), notice: 'created');
            self::assertStringContainsString(
                'A blank event has been created',
                $created,
                'handleCreateManual() redirects to the detail screen with ?created=1.'
            );
        }

        // -------------------------------------------------------------- helpers

        /**
         * @param array<string, string> $extraGet
         */
        private function renderDetail(
            PanelQueueDatabase $database,
            bool $withoutAttachments = false,
            ?string $notice = null,
            array $extraGet = []
        ): string {
            $_GET = ['candidate' => (string) PanelTestIds::CANDIDATE] + $extraGet;
            if ($notice !== null) {
                $_GET[$notice] = '1';
            }

            $page = new ReviewQueuePage(
                new ReviewQueueRepository($database, new PanelQueueClock()),
                PanelPublisherRefusal::publisher(),
                attachments: $withoutAttachments ? null : new AttachmentRepository($database)
            );

            ob_start();
            try {
                $page->renderPage();
            } finally {
                $rendered = (string) ob_get_clean();
            }

            return $rendered;
        }
    }

    final class PanelTestIds
    {
        public const CANDIDATE = 7;

        public const MESSAGE = 42;

        public const EVENT = 4312;

        public const ATTACHMENT = 11;
    }

    /**
     * A fixed clock, because a screen that renders "updated" must not read the
     * host clock (issue #172 dates and times are injected, never read).
     */
    final class PanelQueueClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-03-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
        }
    }

    /**
     * A publisher that dies if it is reached. The detail screen only ever displays,
     * so anything arriving here means the display path has started publishing.
     */
    final class PanelPublisherRefusal
    {
        public static function publisher(): CandidatePublisher
        {
            return new CandidatePublisher(
                new class implements PublicationStoreInterface {
                    public function publish(int $candidateId, callable $prepare): int
                    {
                        throw new \LogicException('A screen that only displays must never publish.');
                    }
                },
                new EventValidator(new DateTimeZone('Africa/Johannesburg'))
            );
        }
    }

    /**
     * A database double for the whole detail screen.
     *
     * The handler's double hard-wires the candidate as already published, and the
     * screen asks for several reads it never sees (the tab counts, the active
     * parishes, the audit trail, and the whole `findScoped()` projection). So this
     * stands on its own and answers exactly the statements the display path issues.
     * Anything it does not recognise returns nothing, so a screen that started
     * reaching for the rest of the schema would fail here rather than quietly pass
     * on made-up rows.
     */
    final class PanelQueueDatabase implements DatabaseConnectionInterface
    {
        /** Whether the candidate is inside the reviewer's own queue. */
        public bool $visible = true;

        /** The published event the candidate resolves to, or null. */
        public ?int $publishedEvent = PanelTestIds::EVENT;

        /** The rows `findPromotableForMessage()` serves. */
        public ?array $promotableRows = null;

        /**
         * @return list<array<string, mixed>>
         */
        public function promotableRows(): array
        {
            return $this->promotableRows ?? [
                [
                    'id' => (string) PanelTestIds::ATTACHMENT,
                    'message_id' => (string) PanelTestIds::MESSAGE,
                    'filename' => 'poster.jpg',
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => '2048',
                    'storage_path' => str_repeat('a', 64) . '.jpg',
                    'status' => 'stored',
                ],
            ];
        }

        /** Whether the promotable-attachment read fails. */
        public bool $failPromotableLookup = false;

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
            $this->clearLastError();

            return 1;
        }

        public function getRow(string $query): ?array
        {
            return $this->getResults($query)[0] ?? null;
        }

        public function getResults(string $query): array
        {
            // counts(): the tab totals, which decide the pagination above the table.
            if (str_contains($query, 'COUNT(*) AS total')) {
                return [['category' => 'recently_decided', 'total' => '1', 'awaiting' => '0']];
            }

            // findScoped(): the candidate, as the queue scopes it.
            if (str_contains($query, 'SELECT c.*')) {
                return $this->visible ? [$this->candidate()] : [];
            }

            // findMessageOf().
            if (str_contains($query, 'SELECT message_id FROM')) {
                return [['message_id' => (string) PanelTestIds::MESSAGE]];
            }

            // findPublishedEventForCandidate().
            if (str_contains($query, "meta_key = 'source_candidate_id'")) {
                return $this->publishedEvent === null
                    ? []
                    : [['ID' => (string) $this->publishedEvent, 'post_status' => 'publish']];
            }

            // findPromotableForMessage().
            if (str_contains($query, 'FROM wp_adct_pi_attachments') && str_contains($query, 'mime_type IN')) {
                if ($this->failPromotableLookup) {
                    throw new RuntimeException('The attachments table is unavailable.');
                }

                return $this->promotableRows();
            }

            // findByMessageId(): the attachments listed on the detail screen.
            if (str_contains($query, 'FROM wp_adct_pi_attachments') && str_contains($query, 'message_id = %d')) {
                return [];
            }

            // findStoredImagesForMessage(): the poster preview.
            if (str_contains($query, "mime_type IN ('image/jpeg'")) {
                return [];
            }

            // activeParishes().
            if (str_contains($query, 'WHERE status =') && str_contains($query, 'ORDER BY name ASC')) {
                return [['id' => '3', 'name' => 'Test Parish']];
            }

            // history(): the audit trail under the editor.
            if (str_contains($query, 'FROM wp_adct_pi_audit')) {
                return [];
            }

            return [];
        }

        /**
         * @return array<string, mixed>
         */
        private function candidate(): array
        {
            return [
                'id' => (string) PanelTestIds::CANDIDATE,
                'message_id' => (string) PanelTestIds::MESSAGE,
                'parish_id' => '3',
                'status' => 'published',
                'approved_by' => 'dean@example.test',
                'decided_at' => '2026-02-20 09:00:00',
                'fields' => '{"title":{"value":"Parish Mass","confidence":"1.00"}}',
                'recurrence' => '{}',
                'notes' => '[]',
                'parser_version' => '1.0.0',
                'confidence' => '0.91',
                'ai_used' => '0',
                'match_kind' => 'new',
                'match_event_id' => null,
                'sender_email' => 'secretary@example.test',
                'parish_name' => 'Test Parish',
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
        }

        public function lastError(): string
        {
            return '';
        }
    }
}
