<?php

declare(strict_types=1);

namespace {
    /**
     * Test support: the WordPress functions the screens in the
     * ADCT\ParishIntake\WordPress\Admin namespace call, for screen tests that
     * do not declare their own.
     *
     * PHP allows a namespaced function to be declared only once per process,
     * and PHPUnit loads every test file into a single process. So one screen
     * test cannot unconditionally declare a stub another screen test has
     * already declared. Every declaration here is guarded by function_exists()
     * with the fully-qualified name, so whichever test file is loaded first
     * wins and the rest fall back to these.
     *
     * That is safe because each stub below is either a pass-through no test
          * observes, or one whose behaviour the screen tests never vary. The one
          * stub a test does control — wp_die() — is declared in the test file
          * itself and is not listed here.
     *
          * current_user_can() is not stubbed in either support file: it is declared
          * once, in WordPressStubs.php, so that every test in this namespace
          * shares one implementation and this test can grant capabilities the same
          * way ParserPageTest and WordPressHelpTest do.
          *
          * This is test scaffolding. The WordPress names are unavoidable here
          * because the screens under test call them unqualified.
          */
         require_once dirname(__DIR__, 3) . '/Support/WordPressStubs.php';
         require_once dirname(__DIR__, 3) . '/Support/AdminWordPressStubs.php';
}

namespace {
    /**
     * Stands in for the wp_die() stub, which never returns in WordPress.
     */
    final class AuditPageWentDie extends RuntimeException
    {
    }
}

namespace ADCT\ParishIntake\WordPress\Admin {
    // current_user_can() and error_log() live in tests/Support so that the
    // screen tests needing different behaviour from the same names cannot
    // collide. wp_die() is guarded for the same reason: ReviewQueuePageTest
    // (#63) also drives the refusal paths through wp_die(), and phpunit.xml.dist
    // has no bootstrap, so whichever file PHPUnit includes first wins. This
    // copy records audit_page_died and throws AuditPageWentDie; the shared copy
    // in tests/Support/WordPressStubs.php throws AdctTestWpDie carrying the
    // status code. Both are handled below so the outcome does not depend on
    // which one loaded.
    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\wp_die')) {
        function wp_die(string $message, string $title = '', array $arguments = []): never
        {
            $GLOBALS['audit_page_died'] = ['message' => $message, 'title' => $title, 'arguments' => $arguments];

            throw new \AuditPageWentDie();
        }
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
    use ADCT\ParishIntake\Core\Audit\AuditEntry;
    use ADCT\ParishIntake\Core\Audit\AuditLogReader;
    use ADCT\ParishIntake\Core\Audit\AuditQuery;
        use ADCT\ParishIntake\Core\Auth\Capabilities;
        use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\WordPress\Admin\AuditLogPage;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class AuditLogPageTest extends TestCase
    {
        private const NOW = '2026-10-12T09:30:00+02:00';

        protected function setUp(): void
        {
                    // The shared current_user_can() stub in tests/Support
                    // reads this list, so the gate below is exercised against the same
                    // stub every other admin screen test uses rather than a copy of
                    // its own that would win or lose on include order.
                    $GLOBALS['adct_test_wp_caps'] = [Capabilities::VIEW_REPORTS];
                    $GLOBALS['audit_page_logs'] = [];
                    $GLOBALS['audit_page_died'] = null;
                    $_GET = [];
                }

                protected function tearDown(): void
                {
                    unset($GLOBALS['adct_test_wp_caps']);
                    unset($GLOBALS['audit_page_logs'], $GLOBALS['audit_page_died']);
                }

                public function testAVisitorWithoutTheReportsCapabilityIsRefusedWithA403(): void
                {
                    // A reviewer who cannot read reports must be refused. Granting
                    // REVIEW rather than emptying the list proves the gate names
                    // VIEW_REPORTS specifically instead of accepting any logged-in
                    // user, which a permissive stub would have let through.
                    $GLOBALS['adct_test_wp_caps'] = [Capabilities::REVIEW];
                    $reader = new StubAuditLogReader();

            try {
                $this->page($reader)->renderPage();
                self::fail('The screen rendered for a user without the reports capability.');
            } catch (\AuditPageWentDie) {
                self::assertSame(403, $GLOBALS['audit_page_died']['arguments']['response']);
                self::assertStringContainsString(
                    'do not have permission',
                    $GLOBALS['audit_page_died']['message']
                );
                            } catch (\AdctTestWpDie $refused) {
                                // The shared wp_die() in tests/Support/WordPressStubs.php won the
                                // include order, so the same refusal arrived as AdctTestWpDie.
                                // The status and the message are asserted identically.
                                self::assertSame(403, $refused->status);
                                self::assertStringContainsString('do not have permission', $refused->getMessage());
                            }

            self::assertSame([], $reader->queries, 'A refused visitor must not reach the database.');
        }

        public function testTheDefaultWindowIsThreeMonths(): void
        {
            $reader = new StubAuditLogReader();
            $this->render($this->page($reader));

            self::assertSame('2026-07-12', $reader->queries[0]->since->format('Y-m-d'));
        }

        public function testEveryRequestIsBoundedOnBothWindowAndPageSize(): void
        {
            $reader = new StubAuditLogReader();
            $this->render($this->page($reader));

            $query = $reader->queries[0];
            self::assertNotNull($query->since, 'The window may never be unbounded.');
            self::assertLessThanOrEqual(AuditQuery::MAXIMUM_LIMIT, $query->limit);
            self::assertGreaterThanOrEqual(0, $query->offset);
        }

        public function testAWindowLongerThanRetentionIsPulledBackIntoRange(): void
        {
            $_GET['window'] = '120';

            $reader = new StubAuditLogReader();
            $output = $this->render($this->page($reader));

            // 120 months is beyond the retention horizon, so it falls back to
            // the three month default rather than being obeyed: the log is only
            // kept for 24 months, and an unbounded window is what times a
            // shared host out. The visitor is told rather than silently given
            // a window they did not ask for.
            self::assertSame('2026-07-12', $reader->queries[0]->since->format('Y-m-d'));
            self::assertStringContainsString('not recognised', $output);
        }

        public function testAShortWindowIsHonoured(): void
        {
            $_GET['window'] = '1';

            $reader = new StubAuditLogReader();
            $this->render($this->page($reader));

            self::assertSame('2026-09-12', $reader->queries[0]->since->format('Y-m-d'));
        }

        public function testAnUnrecognisedActionIsIgnoredRatherThanQueried(): void
        {
            $_GET['action'] = 'approver_deleted';

            $reader = new StubAuditLogReader();
            $output = $this->render($this->page($reader));

            self::assertSame('', $reader->queries[0]->action);
            self::assertStringContainsString('not recognised', $output);
        }

        public function testANegativeSubjectIdIsIgnoredRatherThanQueried(): void
        {
            $_GET['subject_id'] = '-7';

            $reader = new StubAuditLogReader();
            $this->render($this->page($reader));

            self::assertSame(0, $reader->queries[0]->subjectId);
        }

        public function testTheSubjectFiltersReachTheRepository(): void
        {
            $_GET = [
                'subject_type' => 'event_candidate',
                'subject_id' => '42',
                'actor' => 'chaplain@example.test',
                'action' => 'approver_approved',
            ];

            $reader = new StubAuditLogReader();
            $this->render($this->page($reader));

            $query = $reader->queries[0];
            self::assertSame('approver_approved', $query->action);
            self::assertSame('event_candidate', $query->subjectType);
            self::assertSame(42, $query->subjectId);
            self::assertSame('chaplain@example.test', $query->actor);
        }

        public function testAHostileActorFromAParsedEmailIsEscapedNotRendered(): void
        {
            $reader = new StubAuditLogReader([
                $this->entry(
                    actor: '<script>alert(1)</script>@evil.test',
                    details: '{"title":"<img src=x onerror=alert(2)>"}'
                ),
            ]);

            $output = $this->render($this->page($reader));

            self::assertStringNotContainsString('<script>', $output);
            self::assertStringNotContainsString('<img src=x', $output);
            self::assertStringContainsString('&lt;script&gt;', $output);
            self::assertStringContainsString('&lt;img src=x', $output);
        }

        public function testADetailsColumnThatIsNotJsonIsShownEscapedRatherThanDropped(): void
        {
            $reader = new StubAuditLogReader([
                $this->entry(details: 'not json at all <b>bold</b>'),
            ]);

            $output = $this->render($this->page($reader));

            self::assertStringContainsString('not json at all', $output);
            self::assertStringContainsString('&lt;b&gt;', $output);
            self::assertStringNotContainsString('<b>bold</b>', $output);
        }

        public function testAnActorFilterIsEchoedIntoTheFormAsAnAttributeNotMarkup(): void
        {
            $_GET['actor'] = 'a"><script>alert(1)</script>';

            $output = $this->render($this->page(new StubAuditLogReader()));

            self::assertStringNotContainsString('<script>', $output);
            self::assertStringContainsString('&quot;&gt;', $output);
        }

        public function testTheScreenOffersNoWriteActionAtAll(): void
        {
            $output = $this->render($this->page(new StubAuditLogReader()));

            // A log that can be edited or deleted is not evidence of anything,
            // so there is no nonce field and no POST form anywhere on the page.
            // The only form is the GET filter form, which changes nothing.
            self::assertStringNotContainsString('method="post"', strtolower($output));
            self::assertStringNotContainsString('_wpnonce', $output);
            self::assertStringNotContainsString('delete', strtolower($output));
            self::assertSame(
                1,
                substr_count(strtolower($output), '<form'),
                'The screen renders exactly one form: the read-only filter form.'
            );
            self::assertSame(1, substr_count(strtolower($output), 'method="get"'));
        }

        public function testASettingsDiffIsRenderedAsBeforeAndAfter(): void
        {
            $reader = new StubAuditLogReader([
                $this->entry(
                    action: 'settings_updated',
                    subjectType: 'settings',
                    subjectId: 0,
                    details: json_encode([
                        'changed' => [
                            ['option' => 'adct_pi_ai_provider', 'before' => 'none', 'after' => 'openrouter'],
                        ],
                    ], JSON_THROW_ON_ERROR)
                ),
            ]);

            $output = $this->render($this->page($reader));

            self::assertStringContainsString('adct_pi_ai_provider', $output);
            self::assertStringContainsString('none', $output);
            self::assertStringContainsString('openrouter', $output);
        }

        public function testAnEmptyResultSaysSoRatherThanRenderingAnEmptyTable(): void
        {
            $output = $this->render($this->page(new StubAuditLogReader()));

            self::assertStringContainsString('No audit entries match this filter.', $output);
        }

        public function testAFailingCountIsAlsoCaughtRatherThanFatal(): void
        {
            $reader = new StubAuditLogReader();
            $reader->countFailure = new RuntimeException('COUNT failed on the audit table');

            $output = $this->render($this->page($reader));

            self::assertStringContainsString(
                'The audit log could not be read (RuntimeException).',
                $this->logged()
            );
            self::assertSame([], $reader->queries, 'No rows are read when the count itself failed.');
            self::assertStringContainsString('No audit entries match this filter.', $output);
        }

        public function testAReadFailureIsLoggedWithoutTheDatabaseMessageAndDoesNotRenderAsEmpty(): void
        {
            $reader = new StubAuditLogReader();
            $reader->failure = new RuntimeException('SELECT failed: user wp_admin, password hunter2');

            $output = $this->render($this->page($reader));

            self::assertStringContainsString(
                'The audit log could not be read (RuntimeException).',
                $this->logged()
            );
            self::assertStringNotContainsString('hunter2', $output);
            self::assertStringNotContainsString('hunter2', $this->logged());
        }

        public function testPaginationIsOfferedOnlyWhenThereIsMoreThanOnePage(): void
        {
            $single = new StubAuditLogReader([$this->entry()], 1);
            self::assertStringNotContainsString('Page 1 of', $this->render($this->page($single)));

            $many = new StubAuditLogReader([$this->entry()], 260);
            $manyOutput = $this->render($this->page($many));
            self::assertStringContainsString('Page 1 of 11', $manyOutput);
            self::assertStringContainsString('Next', $manyOutput);
        }

        public function testAPageBeyondTheLastOneIsClampedRatherThanLeftEmpty(): void
        {
            $_GET['paged'] = '999';

            $reader = new StubAuditLogReader([$this->entry()], 1);
            $output = $this->render($this->page($reader));

            self::assertStringNotContainsString('Page 999', $output);
            self::assertSame(0, $reader->queries[0]->offset);
        }

        public function testTimestampsAreShownDayFirstInTheSitesOwnTimezone(): void
        {
            $entry = new AuditEntry(
                1,
                'chaplain@example.test',
                'approver_approved',
                'event_candidate',
                7,
                null,
                new DateTimeImmutable('2026-10-09T15:04:05+00:00')
            );

            $output = $this->render($this->page(new StubAuditLogReader([$entry])));

            self::assertStringContainsString('09/10/2026 17:04', $output);
        }

        private function logged(): string
        {
            $lines = $GLOBALS['audit_page_logs'];

            return is_array($lines) ? implode('', $lines) : '';
        }

        private function entry(
            string $actor = 'chaplain@example.test',
            string $action = 'approver_approved',
            string $subjectType = 'event_candidate',
            int $subjectId = 7,
            ?string $details = null
        ): AuditEntry {
            return new AuditEntry(
                1,
                $actor,
                $action,
                $subjectType,
                $subjectId,
                $details,
                new DateTimeImmutable(self::NOW)
            );
        }

        private function page(AuditLogReader $reader): AuditLogPage
        {
            return new AuditLogPage(
                $reader,
                new StubAuditClock(),
                new DateTimeZone('Africa/Johannesburg')
            );
        }

        private function render(AuditLogPage $page): string
        {
            ob_start();

            try {
                $page->renderPage();
            } finally {
                $markup = (string) ob_get_clean();
            }

            return $markup;
        }
    }

    final class StubAuditClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-12T09:30:00+02:00');
        }
    }

    final class StubAuditLogReader implements AuditLogReader
    {
        /** @var list<AuditQuery> */
        public array $queries = [];

        public ?RuntimeException $failure = null;

        public ?RuntimeException $countFailure = null;

        /** @var list<AuditQuery> */
        public array $counts = [];

        /**
         * @param list<AuditEntry> $entries
         */
        public function __construct(private array $entries = [], private int $total = 0)
        {
        }

        public function entries(AuditQuery $query): array
        {
            $this->queries[] = $query;

            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $this->entries;
        }

        public function count(AuditQuery $query): int
        {
            $this->counts[] = $query;

            if ($this->countFailure !== null) {
                throw $this->countFailure;
            }

            return $this->total;
        }
    }
}