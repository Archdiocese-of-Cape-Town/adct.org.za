<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
    require_once __DIR__ . '/../../../Support/AdminWordPressStubs.php';
    require_once __DIR__ . '/../../../Support/WordPressCapabilityStubs.php';
    require_once __DIR__ . '/../../../Support/WordPressEventEditorStubs.php';

    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
    use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
    use ADCT\ParishIntake\Core\Audit\AuditAction;
    use ADCT\ParishIntake\Core\Audit\AuditEntry;
    use ADCT\ParishIntake\Core\Audit\AuditLogReader;
    use ADCT\ParishIntake\Core\Audit\AuditQuery;
    use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
    use ADCT\ParishIntake\Core\Directory\ContactService;
    use ADCT\ParishIntake\Core\Directory\DeaneryCsvImporter;
    use ADCT\ParishIntake\Core\Directory\ParishCsvImporter;
    use ADCT\ParishIntake\Core\Directory\VenueAdministrationService;
    use ADCT\ParishIntake\Core\Directory\VenueDirectoryImporter;
    use ADCT\ParishIntake\Core\Events\EventDetails;
    use ADCT\ParishIntake\Core\Events\EventValidator;
    use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
    use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
    use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
    use ADCT\ParishIntake\Core\Sources\SourceRegistryService;
    use ADCT\ParishIntake\WordPress\Admin\ParishesPage;
    use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
    use ADCT\ParishIntake\WordPress\Admin\SourcesPage;
    use ADCT\ParishIntake\WordPress\Admin\SubjectAuditPanel;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
    use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
    use ADCT\ParishIntake\WordPress\Directory\DirectoryImportService;
    use ADCT\ParishIntake\WordPress\Events\EventEditor;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;

    /**
     * Issue #58: the audit panel has to be reachable from the screens a reviewer
     * already works in, or it does not exist for them.
     *
     * What is pinned here is the wiring, because wiring is what silently rots:
     * that the candidate detail screen asks the panel for its own candidate
     * rather than reaching into the repository, that the unformatted action
     * names do not creep back, and that a page built without a panel still
     * renders. The panel's own behaviour — escaping, the retention window, the
     * page size, the honest failure — is in SubjectAuditPanelTest; the two files
     * exist so the renderer and its mounts are not confused for each other.
     */
    final class SubjectAuditMountTest extends TestCase
    {
        /** @var array<string, mixed> */
        private array $savedGet;

        protected function setUp(): void
        {
            parent::setUp();

            $this->savedGet = $_GET;

            $GLOBALS['adct_test_wp_caps'] = [Capabilities::REVIEW];
            $GLOBALS['adct_test_current_user'] = new \WP_User(9, 'dean@example.test');
            $GLOBALS['adct_test_is_admin'] = true;
            $GLOBALS['adct_test_post_caps'] = true;
        }

        protected function tearDown(): void
        {
            $_GET = $this->savedGet;

            unset(
                $GLOBALS['adct_test_wp_caps'],
                $GLOBALS['adct_test_current_user'],
                $GLOBALS['adct_test_is_admin'],
                $GLOBALS['adct_test_post_caps']
            );

            parent::tearDown();
        }

        public function testTheCandidateScreenReadsItsOwnTrailThroughThePanel(): void
        {
            $reader = new MountStubReader([
                    new AuditEntry(
                        1,
                    'dean@example.test',
                    'candidate_edited',
                    AuditSubjectType::EVENT_CANDIDATE,
                    42,
                    '{"title":"Changed"}',
                    new DateTimeImmutable('2026-10-09T15:04:05+00:00')
                    ),
            ]);

            $markup = $this->renderDetail($this->pageWithPanel(new MountCandidateDatabase(), $reader));

            self::assertStringContainsString('Audit trail', $markup);
            self::assertStringContainsString('Candidate edited', $markup);
            self::assertCount(1, $reader->queries);
            self::assertSame(AuditSubjectType::EVENT_CANDIDATE, $reader->queries[0]->subjectType);
            self::assertSame(42, $reader->queries[0]->subjectId);
        }

        /**
         * The old History block printed the raw action name and none of the
         * details. If the panel were ever bypassed and that block came back, the
         * screen would still show a table — and a reviewer would read
         * "candidate_edited" as if it were the whole story.
         */
        public function testTheCandidateScreenNoLongerShowsTheBareHistoryBlock(): void
        {
            $markup = $this->renderDetail(
                $this->pageWithPanel(new MountCandidateDatabase(), new MountStubReader())
            );

            self::assertStringNotContainsString('>History<', $markup);
            self::assertStringNotContainsString('When (UTC)', $markup);
        }

        /**
         * A page constructed without a panel must still render. Every caller in
         * the repository passes one, but an optional collaborator that quietly
         * becomes mandatory is a fatal error on somebody's screen rather than a
         * missing table.
         */
        public function testTheCandidateScreenStillRendersWithoutAPanel(): void
        {
            $markup = $this->renderDetail($this->pageWithoutAPanel(new MountCandidateDatabase()));

            self::assertStringContainsString('42', $markup);
        }

        /**
         * The parish screen's own tabs decide where a reviewer looks for
         * "who changed this". Without a fourth tab the trail exists but is
         * reachable only by guessing a query string.
         */
        public function testTheParishScreenOffersActivityAmongItsTabs(): void
        {
            $markup = $this->renderParish('details');

            self::assertStringContainsString('>Activity</a>', $markup);
            self::assertStringContainsString('tab=activity', $markup);
        }

        /**
         * The activity tab must read the contacts *of this parish*. A panel
         * pointed at the wrong subject type, or at one contact instead of all of
         * them, would still render a table of somebody else's activity.
         */
        public function testTheParishActivityTabReadsEveryContactOfThatParish(): void
        {
            $reader = new MountStubReader([
                    new AuditEntry(
                        1,
                    'dean@example.test',
                    'contact_verified',
                    AuditSubjectType::PARISH_CONTACT,
                    7,
                    '{"email":"office@parish.example.test"}',
                    new DateTimeImmutable('2026-10-09T15:04:05+00:00')
                    ),
                new AuditEntry(
                        2,
                    'dean@example.test',
                    'contact_blocked',
                    AuditSubjectType::PARISH_CONTACT,
                    8,
                    '{"email":"fr@parish.example.test"}',
                    new DateTimeImmutable('2026-10-10T09:00:00+00:00')
                    ),
            ]);

            $markup = $this->renderParish('activity', $reader);

            self::assertStringContainsString('Contact verified', $markup);
            self::assertStringContainsString('Contact blocked', $markup);
            self::assertCount(2, $reader->queries);
            self::assertSame(AuditSubjectType::PARISH_CONTACT, $reader->queries[0]->subjectType);
            self::assertSame([7, 8], [
                    $reader->queries[0]->subjectId,
                    $reader->queries[1]->subjectId,
                ]);
        }

        /**
         * The event editor is the only place a published event can be read, so
         * it has to carry the trail too. Registered without a panel there would
         * be an empty box; registered without the capability check it would be
         * a hole.
         */
        public function testTheEventEditorGivesItsOwnBoxToTheAuditTrail(): void
        {
            $reader = new MountStubReader([
                    new AuditEntry(
                        3,
                    'dean@example.test',
                            AuditAction::EVENT_PUBLISHED->value,
                    AuditSubjectType::EVENT,
                    77,
                    '{"title":"Changed"}',
                    new DateTimeImmutable('2026-10-09T15:04:05+00:00')
                    ),
            ]);

            $editor = $this->editor($reader);

            $GLOBALS['adct_test_meta_boxes'] = [];
            $editor->registerMetaBox();

            self::assertArrayHasKey('adct_event_audit', $GLOBALS['adct_test_meta_boxes']);
            self::assertSame('Audit trail', $GLOBALS['adct_test_meta_boxes']['adct_event_audit']['title']);

            $markup = $this->renderAuditBox($editor);

            self::assertStringContainsString('Event published', $markup);
            self::assertCount(1, $reader->queries);
            self::assertSame(AuditSubjectType::EVENT, $reader->queries[0]->subjectType);
            self::assertSame(77, $reader->queries[0]->subjectId);
        }

        public function testTheEventEditorRegistersNoAuditBoxWithoutAPanel(): void
        {
            $GLOBALS['adct_test_meta_boxes'] = [];

            $this->editorWithoutPanel()->registerMetaBox();

            self::assertArrayNotHasKey('adct_event_audit', $GLOBALS['adct_test_meta_boxes']);
            self::assertArrayHasKey('adct_event_details', $GLOBALS['adct_test_meta_boxes']);
        }

        public function testTheAuditBoxIsEmptyForSomebodyWhoCannotEditTheEvent(): void
        {
            $reader = new MountStubReader([
                    new AuditEntry(
                        3,
                    'dean@example.test',
                    'event_edited',
                    AuditSubjectType::EVENT,
                    77,
                    '{}',
                    new DateTimeImmutable('2026-10-09T15:04:05+00:00')
                    ),
            ]);

            $GLOBALS['adct_test_post_caps'] = false;

            try {
                $markup = $this->renderAuditBox($this->editor($reader));
            } finally {
                unset($GLOBALS['adct_test_post_caps']);
            }

            self::assertSame('', $markup);
            self::assertSame([], $reader->queries);
        }

        private function renderDetail(ReviewQueuePage $page): string
        {
            $_GET = ['candidate' => '42'];

            ob_start();

            try {
                $page->renderPage();
            } finally {
                return (string) ob_get_clean();
            }
        }

        private function pageWithPanel(
            MountCandidateDatabase $database,
            AuditLogReader $reader
        ): ReviewQueuePage {
            return new ReviewQueuePage(
                new ReviewQueueRepository($database, new MountClock()),
                new CandidatePublisher(new MountPublisherStore(), $this->validator()),
                auditPanel: new SubjectAuditPanel(
                    $reader,
                    new MountClock(),
                    new DateTimeZone('Africa/Johannesburg')
                )
            );
        }

        private function pageWithoutAPanel(MountCandidateDatabase $database): ReviewQueuePage
        {
            return new ReviewQueuePage(
                new ReviewQueueRepository($database, new MountClock()),
                new CandidatePublisher(new MountPublisherStore(), $this->validator())
            );
        }

        private function renderParish(string $tab, ?AuditLogReader $reader = null): string
        {
            $_GET = ['action' => 'edit', 'id' => '3', 'tab' => $tab];
            $GLOBALS['adct_test_wp_caps'] = [Capabilities::MANAGE_DIRECTORY];

            ob_start();

            try {
                $this->parishPage($reader)->renderPage();
            } finally {
                $markup = (string) ob_get_clean();
            }

            return $markup;
        }

        private function parishPage(?AuditLogReader $reader = null): ParishesPage
        {
            $database = new MountDirectoryDatabase();
            $clock = new MountClock();
            $parishes = new ParishRepository($database);
            $deaneries = new DeaneryRepository($database);
            $contacts = new ParishContactRepository($database);
            $venues = new VenueRepository($database);

            return new ParishesPage(
                $parishes,
                        $deaneries,
                        $contacts,
                        new ContactService($contacts, $clock),
                new DirectoryImportService(
                    new ParishCsvImporter(),
                    new DeaneryCsvImporter(),
                    $parishes,
                            $deaneries,
                            new ContactService($contacts, $clock),
                    $clock,
                            new SourceRegistryService(new SourceRepository($database), $clock),
                    new VenueDirectoryImporter($venues, $clock)
                ),
                        new ApprovalRouteResolver(new MountApprovalRouteRepository()),
                $venues,
                        new VenueAdministrationService($venues, $clock),
                $clock,
                        new SourcesPage(
                    new SourceRepository($database),
                    new SourceRegistryService(new SourceRepository($database), $clock),
                    $parishes
                ),
                        auditPanel: $reader === null
                ? null
                : new SubjectAuditPanel($reader, $clock, new DateTimeZone('Africa/Johannesburg'))
            );
        }

        private function editor(?AuditLogReader $reader): EventEditor
        {
            $database = new MountDirectoryDatabase();

            return new EventEditor(
                new ParishRepository($database),
                new VenueRepository($database),
                $this->validator(),
                new RRulePresetMapper(),
                new DateTimeZone('Africa/Johannesburg'),
                new MountClock(),
                $reader === null
                ? null
                : new SubjectAuditPanel($reader, new MountClock(), new DateTimeZone('Africa/Johannesburg'))
            );
        }

        private function editorWithoutPanel(): EventEditor
        {
            return $this->editor(null);
        }

        private function renderAuditBox(EventEditor $editor): string
        {
            ob_start();

            try {
                $editor->renderAuditMetaBox(new \WP_Post(77));
            } finally {
                $markup = (string) ob_get_clean();
            }

            return $markup;
        }

        private function validator(): EventValidator
        {
            return new EventValidator(new DateTimeZone('Africa/Johannesburg'));
        }
    }

    final class MountClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-12T09:30:00+02:00');
        }
    }

    final class MountStubReader implements AuditLogReader
    {
        /** @var list<AuditQuery> */
        public array $queries = [];

        /**
         * @param list<AuditEntry> $entries
         */
        public function __construct(private array $entries = [])
        {
        }

        public function entries(AuditQuery $query): array
        {
            $this->queries[] = $query;

            return $this->entries;
        }

        public function count(AuditQuery $query): int
        {
            $this->queries[] = $query;

            return count($this->entries);
        }
    }

    /**
     * One waiting candidate, and nothing else: the detail screen asks for this
     * row and for the list of duplicate suggestions, which it renders empty.
     */
    final class MountCandidateDatabase implements DatabaseConnectionInterface
    {
        /** @var list<string> */
        public array $executedQueries = [];

        private string $lastError = '';

        public function prefix(): string
        {
            return 'wp_';
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            return $query;
        }

        public function query(string $query): int|false
        {
            $this->executedQueries[] = $query;

            return 1;
        }

        public function getRow(string $query): ?array
        {
            if (str_contains($query, 'COUNT(*)')) {
                return ['category' => 'approval', 'total' => '1', 'awaiting' => '1'];
            }

            return $this->candidate();
        }

                /** @return array<int, array<string, mixed>> */
        public function getResults(string $query): array
        {
            if (str_contains($query, 'COUNT(*)')) {
                return [
                    ['category' => 'approval', 'total' => '1', 'awaiting' => '1'],
                    ['category' => 'approval', 'total' => '0', 'awaiting' => '0'],
                    ['category' => 'failed', 'total' => '0', 'awaiting' => '0'],
                ];
            }

            return [$this->candidate()];
        }

                /**
                 * @return array<string, mixed>
                 */
        private function candidate(): array
        {
            return [
                'id' => 42,
                        'status' => 'awaiting_approval',
                        'fields' => '{"title":"Parish Mass"}',
                        'recurrence' => null,
                        'source_message_id' => 5,
                        'message_id' => 5,
                        'sender_email' => 'office@parish.example.test',
                        'parish_name' => 'St Mary',
                        'parish_id' => 3,
                        'confidence' => '0.95',
                        'created_at' => '2026-10-01 08:00:00',
                        'updated_at' => '2026-10-01 08:00:00',
                        'match_event_id' => null,
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

    /**
     * Nothing here publishes anything. The screen only needs a publisher it can
     * hold without complaint.
     */
    final class MountPublisherStore implements PublicationStoreInterface
    {
        public function publish(int $candidateId, callable $prepare): int
        {
            return 1;
        }
    }

        /**
         * The route store is only read when a reviewer changes approval routing. The
         * activity tab never goes near it, but the constructor still insists on one.
         */
    final class MountApprovalRouteRepository implements ApprovalRouteRepositoryInterface
    {
            /**
             * The details tab, not the activity tab, reads the approval route — the
             * screen renders the "who approves this" box there. The activity tab never
             * touches it, but the constructor still insists on a store, so this
             * returns a route with no approvers rather than null, which the resolver
             * turns into a 404 the details tab cannot survive.
             */
        public function findForParish(int $parishId): ?ApprovalRouteSnapshot
        {
            return new ApprovalRouteSnapshot(1, true, []);
        }
    }

            /**
         * The directory row the parish Activity tab needs, plus the two contacts it
         * reads through. Nothing else on the page is exercised by these tests.
         */
    final class MountDirectoryDatabase implements DatabaseConnectionInterface
    {
            /** @var list<string> */
        public array $executedQueries = [];

        private string $lastError = '';

        public function prefix(): string
        {
            return 'wp_';
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            return $query;
        }

        public function query(string $query): int|false
        {
            $this->executedQueries[] = $query;

            return 1;
        }

        public function getRow(string $query): ?array
        {
            return $this->rowsFor($query)[0] ?? null;
        }

            /** @return array<int, array<string, mixed>> */
        public function getResults(string $query): array
        {
            return $this->rowsFor($query);
        }

            /** @return array<int, array<string, mixed>> */
        private function rowsFor(string $query): array
        {
                // findAllForImport() mentions the contacts as a subquery; findWithRelations()
                                // joins the deaneries. Keying on those two markers rather than on the
                                // bare table names is what keeps the four reads apart: a parish list
                                // row where a deanery row belongs asks for a key it does not have.
            if (str_contains($query, 'office_email') || str_contains($query, 'LEFT JOIN')) {
                return [$this->parish()];
            }

            if (str_contains($query, 'adct_pi_parish_contacts')) {
                return [
                    ['id' => 7, 'parish_id' => 3, 'email' => 'office@parish.example.test'],
                    ['id' => 8, 'parish_id' => 3, 'email' => 'fr@parish.example.test'],
                ];
            }

            return [];
        }

            /**
             * A full parish row: findWithRelations() joins deanery and parent onto it,
             * so the shape a real read produces is the shape the screen must survive.
             *
             * @return array<string, mixed>
             */
        private function parish(): array
        {
            return [
                'id' => 3,
                    'name' => 'St Mary',
                    'slug' => 'st-mary',
                    'kind' => 'parish',
                    'deanery_id' => 1,
                    'parent_parish_id' => null,
                    'deanery_name' => 'Cape Town Central',
                    'deanery_slug' => 'cape-town-central',
                    'parent_name' => null,
                    'parent_slug' => null,
                    'email' => 'office@parish.example.test',
                    'phone' => '',
                    'address' => '',
                    'latitude' => null,
                    'longitude' => null,
                    'active' => 1,
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