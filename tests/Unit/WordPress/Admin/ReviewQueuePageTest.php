<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';

    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Events\EventValidator;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\PreviewableImageRepositoryInterface;
    use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
    use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
    use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
    use ADCT\ParishIntake\WordPress\Attachments\AttachmentImageEndpoint;
    use ADCT\ParishIntake\WordPress\Attachments\OcrControl;
    use ADCT\ParishIntake\WordPress\Attachments\WordPressPreviewableImageRepository;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
    use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;

    /**
     * Issue #63: the button that opens a blank event beside a stored poster.
     *
     * The rendered screen itself is exercised by tests/Integration/CandidateDetailCheck.php
     * under a real WordPress. What is pinned here is everything a unit test can
     * decide on its own: the action and nonce the form, the handler and
     * `Plugin.php` all have to name identically; when the OCR module is loaded
     * at all; and — the part that matters most — that this route refuses
     * everything the ordinary save route refuses, and records no approver.
     *
     * The publisher's own refusal of a hand-typed row is proved in
     * CandidatePublisherTest, beside the allow-list that decides it.
     *
     * No reflection is used anywhere: ReflectionMethod::setAccessible() is
     * deprecated in PHP 8.5 and would fail the advisory CI job.
     */
    final class ReviewQueuePageTest extends TestCase
    {
        private const PLUGIN_FILE = '/wp-content/plugins/adct-parish-intake/plugin.php';

        private const CANDIDATES_TABLE = 'adct_pi_event_candidates';

        /** @var array<string, mixed> */
        private array $savedPost;

        /** @var array<string, mixed> */
        private array $savedGet;

        protected function setUp(): void
        {
            parent::setUp();

            $this->savedPost = $_POST;
            $this->savedGet = $_GET;

            $GLOBALS['adct_test_wp_caps'] = [Capabilities::REVIEW];
            $GLOBALS['adct_test_current_user'] = new \WP_User(9, 'dean@example.test');
            $GLOBALS['adct_test_nonce_checks'] = [];
            $GLOBALS['adct_test_nonce_fields'] = [];
            $GLOBALS['adct_test_styles'] = [];
            $GLOBALS['adct_test_scripts'] = [];
            $GLOBALS['adct_test_redirect'] = null;
            $GLOBALS['adct_test_is_admin'] = true;
            $GLOBALS['adct_test_wp_screen'] = null;

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
                $GLOBALS['adct_test_nonce_fields'],
                $GLOBALS['adct_test_styles'],
                $GLOBALS['adct_test_scripts'],
                $GLOBALS['adct_test_redirect'],
                $GLOBALS['adct_test_is_admin'],
                $GLOBALS['adct_test_wp_screen'],
                $GLOBALS['adct_test_nonce_should_fail']
            );

            parent::tearDown();
        }

        /**
         * `Plugin.php` builds the admin-post hook by concatenating this constant,
         * so changing it here silently unregisters the route and the button 404s.
         */
        public function testTheManualEntryActionNameIsTheOneTheHookIsBuiltFrom(): void
        {
            self::assertSame(
                'adct_pi_candidate_create_manual',
                ReviewQueuePage::CREATE_MANUAL_ACTION,
                'A cross-file contract: Plugin.php builds the hook from this constant, and the form '
                . 'posts to it. Nothing else notices if the two drift apart.'
            );
            self::assertSame(
                'manual_nonce',
                ReviewQueuePage::CREATE_MANUAL_NONCE,
                'A cross-file contract: the rendered form and this handler have to name the nonce alike.'
            );
        }

        /**
         * Opening a blank event is a different act from saving one, so it gets
         * its own action and its own nonce. Sharing either would let one form be
         * replayed as the other.
         */
        public function testManualEntryIsNotAModeOnTheSaveRoute(): void
        {
            self::assertNotSame(
                ReviewQueuePage::SAVE_ACTION,
                ReviewQueuePage::CREATE_MANUAL_ACTION,
                'A shared action would let a crafted POST reach the editor through the save route.'
            );
            self::assertNotSame(
                ReviewQueuePage::SAVE_NONCE,
                ReviewQueuePage::CREATE_MANUAL_NONCE,
                'A shared nonce would let one form be replayed as the other.'
            );
        }

        /**
         * The nonce is checked against this action and this name, before any
         * part of the POST is read. Asserting the pair is the point: a handler
         * that checked the save nonce would pass every other assertion here.
         */
        public function testTheRouteDemandsItsOwnNoncedActionBeforeReadingAnything(): void
        {
            $GLOBALS['adct_test_nonce_should_fail'] = true;
            $database = new ManualEntryDatabase();
            $_POST = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            try {
                $this->page($database)->handleCreateManual();
                        } catch (\AdctTestNonceRefused) {
                            // WordPress refused the word, which is the whole answer here.
                            self::assertNull(
                                $database->insert(self::CANDIDATES_TABLE),
                                'A refused nonce must not write anything, whatever WordPress then does.'
                            );

                            return;
                        }

                        self::assertSame(
                [
                    ['action' => 'adct_pi_candidate_create_manual', 'name' => 'manual_nonce'],
                ],
                $GLOBALS['adct_test_nonce_checks'],
                'The recorded action and name are the whole point of this test.'
            );
        }

        public function testANonceWordPressRefusesOpensNoEvent(): void
        {
            $GLOBALS['adct_test_nonce_should_fail'] = true;
            $database = new ManualEntryDatabase();
            $_POST = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            $this->expectException(\AdctTestNonceRefused::class);

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestRedirect) {
                self::fail('A refused nonce must never reach the insert.');
            }
        }

        /**
         * The button opens an empty candidate and sends the reviewer to it,
         * keeping the tab and search they were working in.
         */
        public function testTheButtonOpensABlankEventAndTakesTheReviewerToIt(): void
        {
            $database = new ManualEntryDatabase();
            $_POST = [
                'candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE,
                'attachment_id' => (string) ReviewQueuePageTestIds::OWN_ATTACHMENT,
                'tab' => 'low_confidence',
                'search' => 'retreat',
            ];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestRedirect) {
                // wp_safe_redirect() throws here; the request would end instead.
            }

            self::assertNotNull(
                $database->insert(self::CANDIDATES_TABLE),
                'Pressing the button must create the event.'
            );

            $redirect = $GLOBALS['adct_test_redirect'];
            self::assertIsString($redirect);
            self::assertStringContainsString(
                'candidate=' . ReviewQueuePageTestIds::NEW_CANDIDATE,
                $redirect,
                'The reviewer lands on the blank event they just opened.'
            );
            self::assertStringContainsString(
                'created=1',
                $redirect,
                'So the screen can tell them what happened, in words.'
            );
            self::assertStringContainsString('tab=low_confidence', $redirect, 'Their tab is kept.');
            self::assertStringContainsString('search=retreat', $redirect, 'Their search is kept.');
        }

        /**
         * The safety property, as one test: nothing this route writes could let
         * the row reach the publisher. It records no approver at all, so only
         * the ordinary editor — which is where the reviewer has to go anyway —
         * can supply one.
         */
        public function testTheOpenedEventCarriesNoApproverSoApprovalIsStillRequired(): void
        {
            $database = new ManualEntryDatabase();
            $_POST = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestRedirect) {
                // Expected.
            }

            $columns = $this->columns($database->insert(self::CANDIDATES_TABLE)['query']);

            foreach (['approved_by', 'approved_via', 'approved_at', 'decided_by', 'confirmed_by'] as $column) {
                self::assertArrayNotHasKey(
                    $column,
                    $columns,
                    'A hand-typed event must not carry "' . $column . '", or approval would be skipped.'
                );
            }
        }

        /**
         * The event is anchored to the message and parish the reviewer was
         * already looking at, not to anything in the request. That is what keeps
         * a typed event routing to the same dean a parsed one would reach.
         */
        public function testTheEventIsAnchoredToTheEmailTheReviewerWasAlreadyOn(): void
        {
            $database = new ManualEntryDatabase();
            $database->sourceId = 8123;
            $database->sourceMessageId = 8749;
            $database->sourceParishId = 3317;
            $_POST = ['candidate' => '8123'];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestRedirect) {
                // Expected.
            }

            $arguments = $database->insert(self::CANDIDATES_TABLE)['arguments'];
            self::assertSame(
                8749,
                $arguments[0],
                'The message comes from the source candidate, never from the request. These ids are '
                . 'not the ones the default fixture serves, so a hardcoded number cannot pass.'
            );
            self::assertSame(
                3317,
                $arguments[2],
                'The parish comes from the source candidate, so approval routes where a parsed one would.'
            );
            self::assertSame(
                ReviewQueuePageTestIds::EXISTING_BLOCKS + 1,
                $arguments[1],
                'The blank event lands after the blocks already on the email, not on top of one.'
            );
        }

        /**
         * A candidate outside the reviewer's own queue is a 404, not an insert.
         * The live relationship is re-resolved on every entry point rather than
         * trusted from the form, exactly as `ApprovalEditHandler::save()` does.
         */
        public function testACandidateOutsideTheReviewersQueueIsRefused(): void
        {
            $database = new ManualEntryDatabase();
            $database->visible = false;
            $_POST = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(404, $refused->status);
                self::assertNull(
                    $database->insert(self::CANDIDATES_TABLE),
                    'Nothing may be written for a candidate this reviewer cannot see.'
                );

                return;
            }

            self::fail('An out-of-scope candidate must be refused.');
        }

        /**
         * Starting from an already-decided candidate would produce a second
         * event nobody approved, so the source has to still be open.
         */
        public function testAnAlreadyDecidedCandidateCannotBeStartedFrom(): void
        {
            $database = new ManualEntryDatabase();
            $database->sourceStatus = 'published';
            $_POST = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(409, $refused->status);
                self::assertNull($database->insert(self::CANDIDATES_TABLE));

                return;
            }

            self::fail('A decided candidate must not be a starting point.');
        }

        /**
         * The POSTed attachment id is never trusted on its own: it is checked
         * against the message the candidate actually belongs to, server-side.
         */
        public function testAPosterFromSomebodyElsesEmailIsRefused(): void
        {
            $database = new ManualEntryDatabase();
            $_POST = [
                            'candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE,
                            'attachment_id' => (string) ReviewQueuePageTestIds::OTHER_ATTACHMENT,
                        ];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(404, $refused->status);
                self::assertNull(
                    $database->insert(self::CANDIDATES_TABLE),
                    'A crafted POST must not start an event from another parish\'s poster.'
                );

                return;
            }

            self::fail('An attachment belonging to another email must be refused.');
        }

        public function testAPosterThisPluginDoesNotHoldIsRefused(): void
        {
            $database = new ManualEntryDatabase();
            $_POST = [
                'candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE,
                'attachment_id' => '4242',
            ];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(404, $refused->status);

                return;
            }

            self::fail('An unknown attachment must be refused.');
        }

        /**
         * A poster is a convenience, not a requirement: a candidate with no
         * attachment can still be typed out, and records no attachment at all.
         */
        public function testAnEventCanBeTypedWithoutAnyPosterAtAll(): void
        {
            $database = new ManualEntryDatabase();
            $_POST = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestRedirect) {
                // Expected.
            }

            self::assertNotNull($database->insert(self::CANDIDATES_TABLE));
        }

        public function testACandidateIdIsRequired(): void
        {
            $database = new ManualEntryDatabase();
            $_POST = ['candidate' => '0'];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(400, $refused->status);
                self::assertNull($database->insert(self::CANDIDATES_TABLE));

                return;
            }

            self::fail('A missing candidate id must be refused before anything is read.');
        }

        /**
         * Someone who cannot review cannot start an event either. The capability
         * gate runs before the nonce, so there is nothing here to probe.
         */
        public function testSomeoneWhoCannotReviewCannotStartAnEvent(): void
        {
            $GLOBALS['adct_test_wp_caps'] = [Capabilities::MANAGE_SETTINGS];
            $database = new ManualEntryDatabase();
            $_POST = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            try {
                $this->page($database)->handleCreateManual();
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(403, $refused->status);
                self::assertNull($database->insert(self::CANDIDATES_TABLE));
                self::assertSame(
                    [],
                    $GLOBALS['adct_test_nonce_checks'],
                    'The capability is checked first, so the nonce is never even asked for.'
                );

                return;
            }

            self::fail('Reviewing is required.');
        }

        /**
         * The OCR module is only worth loading where a poster is actually shown
         * (ADR 0018), so the enqueue is deliberately narrow.
         */
        public function testTheOcrModuleLoadsOnTheDetailScreenOfThisPlugin(): void
        {
            $GLOBALS['adct_test_wp_screen'] = $this->screen(ReviewQueuePage::PAGE_SLUG);
            $_GET = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            $this->page(new ManualEntryDatabase())->enqueueDetailOcrAssets(
                'toplevel_page_' . ReviewQueuePage::PAGE_SLUG
            );

            $scripts = array_column($GLOBALS['adct_test_scripts'], 'handle');
            self::assertContains(
                'adct-parish-intake-ocr',
                $scripts,
                'The reader itself has to load where a poster is shown.'
            );
            self::assertContains(
                'adct-parish-intake-ocr-settings',
                $scripts,
                'ocr.js reads its layout list from ocr-settings.js and does nothing without it, so '
                . 'loading only the reader would leave the button silently dead.'
            );
            self::assertContains(
                'adct-parish-intake-ocr',
                array_column($GLOBALS['adct_test_styles'], 'handle'),
                'The control is unstyled without the stylesheet.'
            );
        }

        public function testTheOcrModuleDoesNotLoadOnAnotherScreen(): void
        {
            $GLOBALS['adct_test_wp_screen'] = $this->screen('adct-parish-intake-parser');
            $_GET = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            $this->page(new ManualEntryDatabase())->enqueueDetailOcrAssets(
                'toplevel_page_' . ReviewQueuePage::PAGE_SLUG
            );

            self::assertSame([], $GLOBALS['adct_test_scripts'], 'Nothing else may load a parser.');
        }

        public function testTheOcrModuleDoesNotLoadOnTheQueueList(): void
        {
            $GLOBALS['adct_test_wp_screen'] = $this->screen(ReviewQueuePage::PAGE_SLUG);
            $_GET = [];

            $this->page(new ManualEntryDatabase())->enqueueDetailOcrAssets(
                'toplevel_page_' . ReviewQueuePage::PAGE_SLUG
            );

            self::assertSame([], $GLOBALS['adct_test_scripts'], 'The list shows no poster.');
        }

        public function testTheOcrModuleDoesNotLoadOutsideTheAdmin(): void
        {
            $GLOBALS['adct_test_is_admin'] = false;
            $GLOBALS['adct_test_wp_screen'] = $this->screen(ReviewQueuePage::PAGE_SLUG);
            $_GET = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            $this->page(new ManualEntryDatabase())->enqueueDetailOcrAssets(
                'toplevel_page_' . ReviewQueuePage::PAGE_SLUG
            );

            self::assertSame([], $GLOBALS['adct_test_scripts'], 'A front-end request has no poster.');
        }

        public function testTheOcrModuleIsNotLoadedForAnotherHookOnTheSameScreen(): void
        {
            $GLOBALS['adct_test_wp_screen'] = $this->screen(ReviewQueuePage::PAGE_SLUG);
            $_GET = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            $this->page(new ManualEntryDatabase())->enqueueDetailOcrAssets(
                'admin_enqueue_network'
            );

            self::assertSame([], $GLOBALS['adct_test_scripts'], 'Only this screen\'s own hook.');
        }

        /**
         * Nothing to read with, nothing to load: the handler tolerates being
         * built without the poster panel at all.
         */
        public function testTheOcrModuleIsNotLoadedWhenThereIsNoneToRenderWith(): void
        {
            $GLOBALS['adct_test_wp_screen'] = $this->screen(ReviewQueuePage::PAGE_SLUG);
            $_GET = ['candidate' => (string) ReviewQueuePageTestIds::SOURCE_CANDIDATE];

            $page = new ReviewQueuePage(
                new ReviewQueueRepository(new ManualEntryDatabase(), new ManualEntryClock()),
                $this->publisher(new ManualEntryDatabase()),
                pluginFile: self::PLUGIN_FILE
            );
            $page->enqueueDetailOcrAssets('toplevel_page_' . ReviewQueuePage::PAGE_SLUG);

            self::assertSame([], $GLOBALS['adct_test_scripts']);
            self::assertSame([], $GLOBALS['adct_test_styles']);
        }

        private function page(ManualEntryDatabase $database): ReviewQueuePage
        {
            $attachments = new AttachmentRepository($database);
            $images = new WordPressPreviewableImageRepository($attachments);

            return new ReviewQueuePage(
                new ReviewQueueRepository($database, new ManualEntryClock()),
                $this->publisher($database),
                attachments: $attachments,
                pluginFile: self::PLUGIN_FILE,
                ocr: new OcrControl('ocr.js', 'ocr.css', 'ocr-settings.js'),
                imageEndpoint: new AttachmentImageEndpoint($images, new ProtectedInboundMailStorage())
            );
        }

        /**
         * A publisher that refuses to do anything. It is never reached from this
         * route; it is here so that reaching it would be an obvious failure
         * rather than a silent success.
         */
        private function publisher(ManualEntryDatabase $database): CandidatePublisher
        {
            return new CandidatePublisher(
                new class ($database) implements PublicationStoreInterface {
                    public function __construct(private readonly ManualEntryDatabase $database)
                    {
                    }

                    public function publish(int $candidateId, callable $prepare): int
                    {
                        throw new \LogicException('Publishing is not available on the manual-entry route.');
                    }
                },
                new EventValidator(new DateTimeZone('Africa/Johannesburg'))
            );
        }

        private function screen(string $base): \WP_Screen
        {
            $screen = new \WP_Screen();
            $screen->base = $base;

            return $screen;
        }

        /**
         * The insert names its columns, so an expectation can be written against
         * a column rather than a position that only means something inside one
         * call.
         *
         * @return array<string, true>
         */
        private function columns(string $query): array
        {
            if (preg_match('/\(([^()]*)\)\s*VALUES/', $query, $match) !== 1) {
                self::fail('The manual candidate insert has no column list: ' . $query);
            }

            $columns = [];
            foreach (explode(',', $match[1]) as $name) {
                $columns[trim($name, " `\t\n\r")] = true;
            }

            return $columns;
        }
    }

    /**
     * Frozen away from every rolling window, so nothing in these expectations
     * depends on when the suite runs.
     */
    final class ManualEntryClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-03-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
        }
    }

    /**
     * Answers exactly the reads the manual-entry route makes, and nothing else:
     * the source candidate as the queue scopes it, that candidate's message,
     * the attachment it claims, and the blocks the message already uses.
     *
     * Every query it does not recognise returns nothing, so a handler that
     * started reaching for the rest of the schema would fail here rather than
     * quietly passing on made-up rows.
     */
    final class ManualEntryDatabase implements DatabaseConnectionInterface
    {
        public const NEW_ID = ReviewQueuePageTestIds::NEW_CANDIDATE;

        /** Whether the source candidate is inside the reviewer's own queue. */
        public bool $visible = true;

        public int $sourceId = ReviewQueuePageTestIds::SOURCE_CANDIDATE;

        public int $sourceMessageId = ReviewQueuePageTestIds::MESSAGE;

        public int $sourceParishId = ReviewQueuePageTestIds::PARISH;

        /** The status the source candidate is served with. */
        public string $sourceStatus = 'awaiting_approval';

        /** Which message the served attachment belongs to. */
        public int $attachmentMessageId = ReviewQueuePageTestIds::MESSAGE;

        /** @var list<array{query: string, arguments: list<mixed>}> */
        public array $preparedQueries = [];

        /** @var list<string> */
        public array $executedQueries = [];

        /** @var array<string, array{query: string, arguments: list<mixed>}> */
        private array $inserts = [];

        private string $lastError = '';

        public function insert(string $table): ?array
        {
            return $this->inserts[$table] ?? null;
        }

        public function prefix(): string
        {
            return 'wp_';
        }

        /**
         * Real `$wpdb->prepare()` substitutes here; this does too, so a read can
         * be answered per statement rather than by guessing from the SQL alone.
         */
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
            $this->executedQueries[] = $query;
            if (preg_match('/^INSERT INTO `([^`]+)`/i', $query, $parts) === 1) {
                $this->inserts[str_replace('wp_', '', $parts[1])] = [
                    'query' => $query,
                    'arguments' => $this->lastArguments(),
                ];
                $this->lastError = '';
            }

            return $this->lastError === '' ? 1 : false;
        }

        public function getRow(string $query): ?array
        {
            return $this->getResults($query)[0] ?? null;
        }

        public function getResults(string $query): array
        {
            $table = $this->tableIn($query);

            if ($table === 'adct_pi_attachments') {
                return $this->attachmentRow($query);
            }

            // The attachments table arrives unquoted, from tableName().
            if (str_contains($query, 'FROM wp_adct_pi_attachments')) {
                return $this->attachmentRow($query);
            }

            if ($table !== 'adct_pi_event_candidates') {
                return [];
            }

            // The next free block on the message.
            if (str_contains($query, 'SELECT block_index')) {
                return [['block_index' => (string) ReviewQueuePageTestIds::EXISTING_BLOCKS]];
            }

            // findScoped(): the candidate as the reviewer's own queue serves it.
            if (str_contains($query, 'SELECT c.*')) {
                return $this->visible ? [$this->sourceCandidate()] : [];
            }

            // findMessageOf(), and createManualCandidate()'s locked re-read. Both
            // read straight from the table, so scoping is not their business:
            // the route authorises through findScoped() before either is called.
            if (str_contains($query, 'SELECT message_id FROM') || str_contains($query, 'SELECT id, message_id, parish_id')) {
                return [$this->sourceCandidate()];
            }

            return [];
        }

        public function escapeLike(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function insertId(): int
        {
            return self::NEW_ID;
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

        /**
         * The candidate every read is served from.
         *
         * Its ids are properties rather than constants so a test can move them
         * and still expect the same outcome — which is what stops an anchoring
         * assertion from passing just because the code hardcoded the number the
         * fake happens to serve.
         *
         * @return array<string, mixed>
         */
        private function sourceCandidate(): array
        {
            return [
                'id' => (string) $this->sourceId,
                'message_id' => (string) $this->sourceMessageId,
                'parish_id' => (string) $this->sourceParishId,
                'status' => $this->sourceStatus,
                'approved_by' => null,
                'decided_at' => null,
                'fields' => '{}',
                'notes' => '[]',
                'block_index' => '0',
            ];
        }

        /**
         * Two posters are held. One belongs to the email under review; the other
         * is real but belongs to a different parish's email, so a crafted POST
         * naming it is refused by the route's own message check rather than by this
         * fake declining to serve it. Any other id is not held at all.
         *
         * @return list<array<string, mixed>>
         */
        private function attachmentRow(string $query): array
        {
            if (preg_match('/WHERE id = (\d+)/', $query, $parts) !== 1) {
                return [];
            }
            $id = (int) $parts[1];
            $owned = $id === ReviewQueuePageTestIds::OWN_ATTACHMENT;
            if (! $owned && $id !== ReviewQueuePageTestIds::OTHER_ATTACHMENT) {
                return [];
            }

            return [[
                'id' => (string) $id,
                'message_id' => (string) ($owned ? $this->attachmentMessageId : ReviewQueuePageTestIds::FOREIGN_MESSAGE),
                'filename' => 'poster.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => '2048',
                'storage_path' => str_repeat('a', 64) . '.jpg',
                'status' => 'stored',
            ]];
        }

        private function tableIn(string $query): string
        {
            if (preg_match('/(?:FROM|INTO) `([^`]+)`/i', $query, $parts) !== 1) {
                return '';
            }

            return str_starts_with($parts[1], 'wp_') ? substr($parts[1], 3) : $parts[1];
        }

        /**
         * The arguments of the statement most recently prepared, which is the
         * one `query()` is about to run.
         *
         * @return list<mixed>
         */
        private function lastArguments(): array
        {
            $prepared = end($this->preparedQueries);
            if ($prepared === false) {
                return [];
            }

            return $prepared['arguments'];
        }
    }

    /**
     * The ids the fake database serves, kept out of the test class itself so
     * the fake can name them too.
     */
    final class ReviewQueuePageTestIds
    {
        public const SOURCE_CANDIDATE = 7;

        public const MESSAGE = 42;

        public const PARISH = 3;

        public const OWN_ATTACHMENT = 11;

                /** A poster on some other parish's email; served only when asked for by id. */
                public const OTHER_ATTACHMENT = 99;

        /** Which message that other poster arrived on. */
        public const FOREIGN_MESSAGE = 1000;

                public const NEW_CANDIDATE = 501;

                /** Blocks already on the message, so the new event cannot land on one. */
                public const EXISTING_BLOCKS = 2;
    }
}
