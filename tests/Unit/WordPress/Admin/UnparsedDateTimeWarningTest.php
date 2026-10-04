<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin {

        // Per-namespace copies in this test file, as `ParserPageTest` does. These two
        // are the ones the editor reaches for that tests/Support/WordPressStubs.php
        // does not declare, and the shared stub file is not this file's to widen.
        // `current_user_can` is deliberately absent: the single declaration stays in
        // tests/Support/WordPressStubs.php.
        if (! function_exists(__NAMESPACE__ . '\\esc_textarea')) {
            function esc_textarea(mixed $text): string
            {
                return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
            }
        }

        if (! function_exists(__NAMESPACE__ . '\\sanitize_textarea_field')) {
            function sanitize_textarea_field(string $value): string
            {
                return trim(strip_tags($value));
            }
        }

        // WordPress accepts add_query_arg() in two shapes, and each queue-row link
        // uses the key/value one. Only the array form is declared in the shared
        // stubs, so the other has to come from somewhere before a row can render.
        if (! function_exists(__NAMESPACE__ . '\\add_query_arg')) {
            function add_query_arg(mixed $key, mixed $value = null, string $url = ''): string
            {
                $arguments = is_array($key) ? $key : [$key => $value];
                $query = $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($arguments);

                return $arguments === [] ? $url : $query;
            }
        }
        }

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Admin {
        require_once __DIR__ . '/../../../Support/WordPressStubs.php';

    use ADCT\ParishIntake\Core\Events\EventValidator;
        use ADCT\ParishIntake\Core\Ports\ClockInterface;
        use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
        use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
        use ADCT\ParishIntake\WordPress\Admin\CandidateDetailView;
        use ADCT\ParishIntake\WordPress\Admin\CandidateEditForm;
        use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
        use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
        use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
        use PHPUnit\Framework\Attributes\DataProvider;
        use PHPUnit\Framework\TestCase;

    /**
     * Issue #167: a date or time the parser found and could not read used to reach
     * nobody. The date side produced no note at all, and the time side produced a
     * free-text note that both warning renderers fell straight through, so the
     * reason string was the whole of what a reviewer was shown.
     *
     * What is pinned here is the surfacing half on the two admin surfaces that show
     * a parser note: the candidate detail screen's provenance panel, and the review
     * queue's section warnings. Both are independent implementations of the same
     * idea, which is why both are asserted -- a shared test would pass while one of
     * them still printed the token.
     *
     * No reflection is used: ReflectionMethod::setAccessible() is deprecated in PHP
     * 8.5 and fails the advisory CI job.
     */
    final class UnparsedDateTimeWarningTest extends TestCase
    {
            private WarningRowDatabase $database;

            /** @var array<string, mixed> */
            private array $savedPost;

            /** @var array<string, mixed> */
            private array $savedGet;

            protected function setUp(): void
            {
                parent::setUp();

                $this->savedPost = $_POST;
                $this->savedGet = $_GET;
                $this->database = new WarningRowDatabase();

                $GLOBALS['adct_test_nonce_fields'] = [];
                $GLOBALS['adct_test_nonce_checks'] = [];
                $GLOBALS['adct_test_styles'] = [];
                $GLOBALS['adct_test_scripts'] = [];
                $GLOBALS['adct_test_current_user'] = new \WP_User(9, 'dean@example.test');
                $GLOBALS['adct_test_wp_caps'] = ['adct_pi_review'];
                $GLOBALS['adct_test_is_admin'] = true;
                $GLOBALS['adct_test_wp_screen'] = null;
                $GLOBALS['adct_test_redirect'] = null;
            }

            protected function tearDown(): void
            {
                $_POST = $this->savedPost;
                $_GET = $this->savedGet;

                unset(
                    $GLOBALS['adct_test_nonce_fields'],
                    $GLOBALS['adct_test_nonce_checks'],
                    $GLOBALS['adct_test_styles'],
                    $GLOBALS['adct_test_scripts'],
                    $GLOBALS['adct_test_current_user'],
                    $GLOBALS['adct_test_wp_caps'],
                    $GLOBALS['adct_test_is_admin'],
                    $GLOBALS['adct_test_wp_screen'],
                    $GLOBALS['adct_test_redirect']
                );

                parent::tearDown();
            }

        /**
         * @return iterable<string, array{list<string>, string}>
         */
        public static function unparsedShapes(): iterable
        {
            yield 'a date that names no day on the calendar' => [
                ['unparsed_date_candidate:32 October 2026'],
                '32 October 2026',
            ];
            yield 'a date in day-first order that is not a calendar day' => [
                ['unparsed_date_candidate:12/13/2026'],
                '12/13/2026',
            ];
            yield 'a time that reads as a clock but resolves to nothing' => [
                ['unparsed_time_candidate:2575am'],
                '2575am',
            ];
            yield 'both, in the order the parser writes them' => [
                ['unparsed_time_candidate:2575am', 'unparsed_date_candidate:12/13/2026'],
                '12/13/2026',
            ];
        }

        /**
         * The provenance panel is where a reviewer goes to find out what the parser
         * did, so the unreadable date has to be there in words, with the phrase the
         * recogniser actually matched.
         *
         * @param list<string> $notes
         */
        #[DataProvider('unparsedShapes')]
        public function testTheDetailScreenSaysInWordsWhatCouldNotBeRead(array $notes, string $phrase): void
        {
            $html = $this->renderDetail($notes);

            self::assertStringContainsString(
                'could not be read',
                $html,
                'The copy has to say the phrase could not be read. "Empty" would be a different '
                . 'and wrong claim: the notice did state a date, and the parser found it.'
            );
            self::assertStringContainsString(
                $phrase,
                $html,
                'A human can only fix the source text if they can see which text it was.'
            );
        }

        /**
         * The reason token is an internal vocabulary. A reviewer has no way to act
         * on it, and it names no date, so a screen that rendered it verbatim has
         * surfaced the failure without surfacing anything.
         *
         * @param list<string> $notes
         */
        #[DataProvider('unparsedShapes')]
        public function testTheDetailScreenNeverShowsTheReasonToken(array $notes): void
        {
            $html = $this->renderDetail($notes);

            self::assertStringNotContainsString('unparsed_date_candidate', $html);
            self::assertStringNotContainsString('unparsed_time_candidate', $html);
            self::assertStringNotContainsString('_candidate:', $html);
        }

        /**
         * The queue screen renders parser notes independently of the detail screen,
         * and this implementation has no fallback branch at all, so an unintercepted
         * token reaches the reviewer verbatim. This is the assertion that catches it.
         *
         * @param list<string> $notes
         */
        #[DataProvider('unparsedShapes')]
        public function testTheQueueScreenAlsoSaysItInWords(array $notes): void
        {
            $html = $this->renderQueue($notes);

            self::assertStringContainsString('could not be read', $html);
            self::assertStringNotContainsString('_candidate:', $html);
        }

        /**
         * Notes the plugin already had keep working exactly as they did. A change to
         * the warning renderer must not swallow one of these.
         */
        public function testTheExistingParserNotesAreStillRendered(): void
        {
            $notes = [
                'unknown_sender',
                'dmarc_fail',
                'skipped_sections: church_notice=1',
                'possible_missed_event_after_skipped_section:3',
                'unparsed_date_candidate:32 October 2026',
            ];

            $html = $this->renderDetail($notes);

                        // Two of these have a readable form on screen and two do not; the
                        // two that do not must still be there, or this renderer has started
                        // quietly dropping the warnings it used to show.
                        foreach (
                            [
                                'unknown_sender',
                                'dmarc_fail',
                                'The parser skipped sections of this message.',
                                'Possible missed event after 3 skipped sections.',
                            ] as $expected
                        ) {
                            self::assertStringContainsString(
                                $expected,
                                $html,
                                'Every established parser warning must survive alongside the new one.'
                            );
                        }
                    }

        /**
         * A row that never went through the parser can hold anything at all in this
         * column. A screen that throws on it would turn a bad row into an unreadable
         * screen, which is the opposite of what this issue is for.
         *
         * @return iterable<string, array{string}>
         */
        public static function unusableNotes(): iterable
        {
            yield 'not json' => ['not json at all'];
            yield 'a json object' => ['{"a":1}'];
            yield 'a json string' => ['"unparsed_date_candidate:12/10/2026"'];
            yield 'empty' => [''];
        }

        #[DataProvider('unusableNotes')]
        public function testAnUnreadableNotesColumnDegradesToNoWarnings(string $notes): void
        {
                    $html = $this->detailHtml($this->rawRow($notes), true, true);

            self::assertStringNotContainsString('could not be read', $html);
            self::assertStringNotContainsString('Parser warning', $html);
        }

        /**
         * The phrase is the only part of the source text that travels, so a long
         * line cannot smuggle an address or a phone number onto a public screen
         * through the warning.
         */
        public function testALongPhraseIsBoundedRatherThanRenderedWhole(): void
        {
            $phrase = str_repeat('9', 400);
            $html = $this->renderDetail(['unparsed_date_candidate:' . $phrase]);

            self::assertStringNotContainsString(
                $phrase,
                $html,
                'The matched phrase is bounded before it reaches a screen. An unbounded one would '
                . 'put whatever the line contained -- an address, a number -- in front of anyone '
                . 'with review rights.'
            );
        }

        /**
         * The acknowledgement is a question about approving, so it belongs on the
         * editor and only where approving is on offer. On an already-decided row
         * there is nothing left to acknowledge.
         */
        public function testTheAcknowledgementAppearsOnlyWhereApprovingIsOnOffer(): void
        {
            $open = $this->detailHtml($this->row(['unparsed_date_candidate:32 October 2026']), true, true);
            self::assertStringContainsString(
                ReviewQueuePage::UNPARSED_DATE_FIELD,
                $open,
                'An approver has to be able to say they mean it.'
            );
            self::assertStringContainsString('without a date the notice stated clearly', $open);

            $decided = $this->detailHtml($this->row(['unparsed_date_candidate:32 October 2026']), true, false);
            self::assertStringNotContainsString(
                ReviewQueuePage::UNPARSED_DATE_FIELD,
                $decided,
                'A decided row has nothing left to approve, so asking would be noise.'
            );
        }

        public function testNoAcknowledgementIsAskedWhenTheNoticeStatedADate(): void
        {
            $html = $this->detailHtml($this->row(['unknown_sender']), true, true);

            self::assertStringNotContainsString(
                ReviewQueuePage::UNPARSED_DATE_FIELD,
                $html,
                'Asking about a date that was read would train reviewers to tick boxes without reading.'
            );
        }

        public function testAnUnreadableTimeAloneIsSurfacedButAsksForNothing(): void
        {
            $html = $this->detailHtml($this->row(['unparsed_time_candidate:2575am']), true, true);

            self::assertStringContainsString('could not be read', $html, 'It is still surfaced.');
            self::assertStringNotContainsString(
                ReviewQueuePage::UNPARSED_DATE_FIELD,
                $html,
                'An unreadable time publishes as an all-day event, which is recoverable and may be '
                . 'what the parish meant. Only a missing date needs a signature.'
            );
        }

        /**
         * A GET render, which is what a bookmarked detail URL is, must not be read
         * as an acknowledgement. `hasAcknowledgedUnparsedDate()` therefore consults
         * the POSTed form, and this is the test that a future renderer of the
         * button cannot quietly pre-tick it.
         */
        public function testTheAcknowledgementIsNeverPreTicked(): void
        {
            $html = $this->detailHtml($this->row(['unparsed_date_candidate:32 October 2026']), true, true);

            self::assertStringNotContainsString(
                'name="' . ReviewQueuePage::UNPARSED_DATE_FIELD . '" value="1" checked',
                $html,
                'A box that arrived already ticked would approve every candidate with a bad date.'
            );
        }

        /**
         * @param list<string> $notes
         */
        private function renderDetail(array $notes): string
        {
                    return $this->detailHtml($this->row($notes), true, true);
        }

        /**
                 * @param list<string> $notes
                 */
                private function renderQueue(array $notes): string
                {
                    $this->database->rows = [$this->row($notes)];
                    $_GET = ['page' => ReviewQueuePage::PAGE_SLUG, 'tab' => 'awaiting_approval'];

                    ob_start();
                    try {
                        $this->page()->renderPage();
                    } finally {
                        $html = (string) ob_get_clean();
                    }

                    $_GET = [];

                    return $html;
                }

        /**
         * @param array<string, mixed> $row
         */
        private function detailHtml(array $row, bool $editable, bool $canApprove): string
        {
            ob_start();
            try {
                (new CandidateDetailView(new CandidateEditForm()))->render(
                    $row,
                    null,
                    [],
                    [],
                    'awaiting_approval',
                    '',
                    $editable,
                    $canApprove
                );
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        /**
         * @param list<string> $notes
         * @return array<string, mixed>
         */
        private function row(array $notes): array
        {
                    return $this->rawRow(json_encode($notes, JSON_THROW_ON_ERROR));
                }

                /**
                 * The `notes` column verbatim, so a test can put something in it that no
                 * writer would ever have produced.
                 */
                private function rawRow(string $notes): array
                {
                    return [
                                    'id' => 7,
                                    'status' => 'awaiting_approval',
                                    'parish_id' => 3,
                                    'parish_name' => 'Example Parish',
                                    'message_id' => 11,
                                    'sender_email' => 'parish@example.test',
                                    'parser_version' => 'test',
                                    'confidence' => 0.6,
                                    'ai_used' => 0,
                                    'match_kind' => 'new',
                                    'match_event_id' => null,
                                                                        // The two extra columns the queue row renders. They come
                                                                        // from the same query and are not what any assertion
                                                                        // below reads, but a row missing them warns.
                                                                        'name' => 'Example Parish',
                                                                        'category' => 'date_uncertain',
                                    'decided_by' => null,
                                    'decided_at' => null,
                                    'decision_note' => '',
                                    'updated_at' => '2026-10-12 07:00:00',
                                    // Stored as the JSON column, exactly as the repository reads it.
                                    'fields' => json_encode(
                                        ['title' => 'Fictional event', 'event_date' => '12/10/2026', 'event_time' => '10:00'],
                                        JSON_THROW_ON_ERROR
                                    ),
                                    'recurrence' => null,
                                    'notes' => $notes,
                                    'strategies' => '[]',
                                ];
                                            }

                                            private function page(): ReviewQueuePage
                                            {
                                                return new ReviewQueuePage(
                                                    new ReviewQueueRepository($this->database, new WarningFixedClock()),
                                                    new CandidatePublisher(
                                                        new WarningNullStore(),
                                                        new EventValidator(new \DateTimeZone('Africa/Johannesburg'))
                                                    )
                                                );
                                            }
                                        }

                                        /**
                                         * A fixed clock. Nothing in these tests reads the time, but the repository
                                         * asks for it, and an injected clock is the only honest answer.
                                         */
                                        final class WarningFixedClock implements ClockInterface
                                        {
                                            public function now(): \DateTimeImmutable
                                            {
                                                return new \DateTimeImmutable('2026-10-12 09:00:00', new \DateTimeZone('Africa/Johannesburg'));
                                            }
                                        }

                                        /**
                                         * Serves whatever rows a test set, and nothing for the count queries. The
                                         * queue page reads the counts to label its tabs; an empty count simply
                                         * renders "0", which is not what these tests are about.
                                         */
                                        final class WarningRowDatabase implements DatabaseConnectionInterface
                                        {
                                            /** @var list<array<string, mixed>> */
                                            public array $rows = [];

                                            public function prefix(): string
                                            {
                                                return 'wp_';
                                            }

                                            public function prepare(string $query, mixed ...$arguments): string
                                            {
                                                if ($arguments === []) {
                                                    return $query;
                                                }

                                                foreach ($arguments as $argument) {
                                                    $value = is_int($argument) || is_float($argument) ? (string) $argument : "'" . $argument . "'";
                                                    $query = preg_replace('/%[dfs]/', (string) $value, $query, 1) ?? $query;
                                                }

                                                return $query;
                                            }

                                            public function query(string $query): int|false
                                            {
                                                return str_starts_with($query, 'SELECT') ? 0 : 1;
                                            }

                                            public function getRow(string $query): ?array
                                            {
                                                return $this->getResults($query)[0] ?? null;
                                            }

                                            public function getResults(string $query): array
                                            {
                                                // `counts()` groups by category and reads the `category` column it
                                                // selected, so handing it a candidate row would raise an undefined
                                                // key. These tests are about warnings, not tab labels, and an
                                                // aggregate row is what that query asked for.
                                                if (str_contains($query, 'GROUP BY category')) {
                                                    return [];
                                                }

                                                return $this->rows;
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

                                        /**
                                         * A store that refuses to publish. Approving is not what any of these tests
                                         * do, and reaching it would mean the gate had let something through that
                                         * should not have gone.
                                         */
                                        final class WarningNullStore implements PublicationStoreInterface
                                        {
                                            public function publish(int $candidateId, callable $prepare): int
                                            {
                                                throw new \RuntimeException('These tests render screens; they never publish.');
                                            }
                                        }
                                    }
