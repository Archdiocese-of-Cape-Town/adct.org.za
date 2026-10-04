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
}

namespace ADCT\ParishIntake\WordPress\Change {
    if (! function_exists('ADCT\ParishIntake\WordPress\Change\add_meta_box')) {
        /**
         * Records the registration instead of rendering, so a test can assert the
         * box is attached to the event screen and not somewhere else.
         *
         * @param callable $callback
         */
        function add_meta_box(
            string $id,
            string $title,
            callable $callback,
            string $screen = '',
            string $context = 'advanced',
            string $priority = 'default',
            mixed ...$args
        ): void {
            $GLOBALS['change_history_boxes'][] = [
                'id' => $id,
                'title' => $title,
                'callback' => $callback,
                'screen' => $screen,
                'context' => $context,
                'priority' => $priority,
            ];
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Change\esc_html')) {
        function esc_html(mixed $text): string
        {
            return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Change\esc_attr')) {
        function esc_attr(mixed $text): string
        {
            return htmlspecialchars(is_string($text) ? $text : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Change\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Change\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Change\current_user_can')) {
        /**
                 * The per-namespace copy the box's unqualified call resolves to. It
                 * answers edit_post only when the test granted the capability AND asked
                 * about the event the test is rendering, which is what makes the gate
                 * testable: $GLOBALS['adct_test_edit_post'] is the "and the user may
                 * edit this particular event" half, so a test can grant the capability
                 * and still see the box refuse a post the user does not own.
                 */
                function current_user_can(string $capability, mixed ...$arguments): bool
                {
                    if ($capability !== 'edit_post') {
                        return false;
                    }

                    $target = (int) ($arguments[0] ?? 0);
                    $allowed = $GLOBALS['adct_test_edit_post'] ?? [];

                    return is_array($allowed) && in_array($target, $allowed, true);
                }
            }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Change {
    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Events\ChangeDiff;
        use ADCT\ParishIntake\Core\Ports\ChangeTrailReaderInterface;
        use ADCT\ParishIntake\WordPress\Change\ChangeHistoryBox;
        use ADCT\ParishIntake\WordPress\Change\ChangeHistoryRepository;
        use ADCT\ParishIntake\WordPress\Events\EventPostType;
        use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
        use PHPUnit\Framework\TestCase;

    final class ChangeHistoryBoxTest extends TestCase
    {
        private const EVENT_ID = 501;

        protected function setUp(): void
        {
            $GLOBALS['adct_test_wp_caps'] = [Capabilities::EDIT_EVENTS];
                        $GLOBALS['adct_test_edit_post'] = [self::EVENT_ID];
                        $GLOBALS['change_history_boxes'] = [];
                    }

            /**
             * The box's only data source is a SELECT, so the repository has no
             * write path to guard -- but the id it filters on does have to reach the
             * statement as a bound integer rather than as interpolation, and that
             * is only observable at the connection.
             */
            public function testTheRepositoryReadsOnlyTheEventItIsGivenThroughPrepare(): void
            {
                $database = new RecordingHistoryDatabase();
                $database->results = [
                    ['id' => '1', 'event_id' => '501', 'actor' => 'office@example.test', 'kind' => 'update'],
                ];

                $rows = (new ChangeHistoryRepository($database))->forEvent(self::EVENT_ID);

                self::assertCount(1, $rows);
                self::assertCount(1, $database->queries);

                $query = $database->queries[0];
                                // The table name is spelled out rather than built from the same
                                // constant the repository uses: a shared constant would let the
                                // assertion agree with a wrong name, which is exactly what it did.
                                self::assertStringContainsString('wp_adct_pi_event_changes', $query);
                                self::assertStringContainsString('WHERE event_id = 501', $query, 'No interpolation of a raw id.');
                                self::assertStringContainsString('ORDER BY id DESC', $query, 'Newest first.');
                                self::assertStringContainsString('LIMIT 25', $query);
            }

            public function testTheRepositoryAsksForNoRowsForAnImpossibleEventId(): void
            {
                $database = new RecordingHistoryDatabase();

                self::assertSame([], (new ChangeHistoryRepository($database))->forEvent(0));
                self::assertSame([], (new ChangeHistoryRepository($database))->forEvent(-3));
                self::assertNull((new ChangeHistoryRepository($database))->change(0));
                self::assertSame([], $database->queries, 'A query that cannot match must not be sent.');
            }

            public function testTheRepositorySkipsNothingItCannotUse(): void
            {
                $database = new RecordingHistoryDatabase();
                $database->results = ['not an array', ['id' => '1'], ['id' => '2']];

                $rows = (new ChangeHistoryRepository($database))->forEvent(self::EVENT_ID);

                self::assertCount(2, $rows, 'A row that is not an array must not become a fatal.');
            }

        protected function tearDown(): void
        {
            $GLOBALS['change_history_boxes'] = [];
        }

        public function testItRegistersOnTheEventEditScreen(): void
        {
            $this->box()->register();

            self::assertCount(1, $GLOBALS['change_history_boxes']);
            self::assertSame(EventPostType::POST_TYPE, $GLOBALS['change_history_boxes'][0]['screen']);
        }

        /**
         * The box sits below the details form, not beside it: the history is a
         * record to read, and a narrow sidebar column turns a before/after
         * table into wrapping fragments.
         */
        public function testItIsRegisteredInTheMainColumnAtNormalPriority(): void
        {
            $this->box()->register();

            self::assertSame('normal', $GLOBALS['change_history_boxes'][0]['context']);
            self::assertSame('high', $GLOBALS['change_history_boxes'][0]['priority']);
        }

        /**
         * Meta boxes render for any user who can reach the edit screen, so the
         * capability check has to happen inside the renderer.
         */
        public function testItRendersNothingForAUserWhoCannotEditThisEvent(): void
        {
                    $GLOBALS['adct_test_edit_post'] = [];

            ob_start();
            $this->box()->renderMetaBox($this->post(self::EVENT_ID));
            $html = (string) ob_get_clean();

            self::assertSame('', $html, 'The trail names the address that made each change.');

                    $GLOBALS['adct_test_edit_post'] = [self::EVENT_ID];
        }

                /**
                 * Holding the capability is not the same as holding it for this post.
                 * A parish contact who may edit their own event must not read the trail
                 * of someone else's, so the gate is asked about the post, not the
                 * capability alone.
                 */
                public function testItRendersNothingForAnEventTheUserMayNotEdit(): void
                {
                    $GLOBALS['adct_test_edit_post'] = [999];

                    ob_start();
                    $this->box()->renderMetaBox($this->post(self::EVENT_ID));
                    $html = (string) ob_get_clean();

                    self::assertSame('', $html);

                    $GLOBALS['adct_test_edit_post'] = [self::EVENT_ID];
                }

        public function testItShowsTheTrailNewestFirstWithWhoWhatAndWhen(): void
        {
            $trail = $this->trail();
            $trail->seedChange(3, self::EVENT_ID, 'office@example.test', 'update', 1);
            $trail->seedChange(2, self::EVENT_ID, 'dean@example.test', 'update', 2);
            $trail->seedChange(1, self::EVENT_ID, 'office@example.test', 'unpublish', 3);

            $html = $this->render(self::EVENT_ID, $trail);

                    self::assertSame([self::EVENT_ID], $trail->asked, 'One read, for the event being rendered.');
                    self::assertSame(
                        ['3', '2', '1'],
                        $this->changeIds($html),
                        'A trail is read backwards, from the present state.'
                    );

                    self::assertStringContainsString('dean@example.test', $html);
                    // created_at is stored in UTC and the reader is in Africa/Johannesburg
                    // (UTC+2), so 09:00 UTC is 11:00 local, day-first.
                    self::assertStringContainsString('01/10/2026 11:00', $html);
                }

                /**
                 * @return list<string>
                 */
                private function changeIds(string $html): array
                {
                    preg_match_all('/Change #(\d+)/', $html, $matches);

                    return $matches[1];
                }

        /**
         * The whole point of the view is the before/after, rendered by the same
         * ChangeDiff the change notice mail uses.
         */
        public function testItRendersTheSameDiffWordingAsTheChangeNoticeMail(): void
        {
            $trail = $this->trail();
            $trail->seedChange(7, self::EVENT_ID, 'office@example.test', 'update', 2);

            $row = $trail->change(7);
            self::assertIsArray($row);
            $before = ChangeDiff::decode($row['before_payload']);
            $after = ChangeDiff::decode($row['after_payload']);
            $expected = [];
            foreach (ChangeDiff::rows($before['snapshot'], $after['snapshot']) as $field) {
                $expected[] = $field['label'] . ': ' . $field['before'] . ' -> ' . $field['after'];
            }
            self::assertNotSame([], $expected);

            $html = $this->render(self::EVENT_ID, $trail);

                        // The box escapes each line, so the arrow in "->" is escaped too.
                        // Comparing against the escaped form is what pins that the *wording*
                        // comes from ChangeDiff: a hand-written label would differ before
                        // escaping, not after.
                        foreach ($expected as $line) {
                            self::assertStringContainsString(esc_html($line), $html);
                        }
                    }

        /**
         * A change whose payloads will not decode must not claim every field was
         * cleared. ChangeDiff reads a missing key as "nothing", so the box has
         * to check readability itself, exactly as the revert confirmation page
         * and the notice mail do.
         */
        public function testItSaysSoWhenTheRecordedValuesCannotBeRead(): void
        {
            $trail = $this->trail();
            $trail->seedChange(4, self::EVENT_ID, 'office@example.test', 'update', 1);
            $trail->corruptPayload(4, 'after_payload', '{not json');

            $html = $this->render(self::EVENT_ID, $trail);

            self::assertStringContainsString(
                'the before and after details of this change could not be read',
                $html
            );
            self::assertStringNotContainsString(
                'Title:',
                $html,
                'Nothing may be diffed against an unreadable side.'
            );
        }

        /**
         * A reverted change stays in the trail and says who undid it, so the
         * record reads as a sequence rather than losing the undone state.
         */
        public function testItShowsThatAChangeWasRevertedAndByWhom(): void
        {
            $trail = $this->trail();
            $trail->seedChange(9, self::EVENT_ID, 'office@example.test', 'update', 1);
            $trail->markReverted(9, 'dean@example.test', '2026-10-13 08:00:00');

            $html = $this->render(self::EVENT_ID, $trail);

            self::assertStringContainsString('Reverted by dean@example.test', $html);
        }

        public function testItSaysSoWhenThereIsNoHistory(): void
        {
            $html = $this->render(self::EVENT_ID, $this->trail());

            self::assertStringContainsString('No changes have been recorded', $html);
        }

        /**
         * A parish bulletin is hostile input. A title or an actor address
         * carrying markup must not reach the page unescaped.
         */
        public function testEveryRenderedValueIsEscaped(): void
        {
            $trail = $this->trail();
            $trail->seedChange(
                11,
                self::EVENT_ID,
                '<script>alert(1)</script>@example.test',
                'update',
                1,
                ['title' => '<img src=x onerror=alert(1)>']
            );

            $html = $this->render(self::EVENT_ID, $trail);

            self::assertStringNotContainsString('<script>', $html);
            self::assertStringNotContainsString('<img src=x', $html);
            self::assertStringContainsString('&lt;script&gt;', $html);
        }

        /**
         * The view is read-only. Reverting or unpublishing from the edit screen
         * would give an editor a second, un-audited path to rewrite a published
         * event, so no action may be offered here.
         */
        public function testItOffersNoFormAndNoActionTokenLink(): void
        {
            $trail = $this->trail();
            $trail->seedChange(5, self::EVENT_ID, 'office@example.test', 'update', 1);

            $html = $this->render(self::EVENT_ID, $trail);

            self::assertStringNotContainsString('<form', $html);
            self::assertStringNotContainsString('_wpnonce', $html);
            self::assertStringNotContainsString('adct_action_token', $html);
            self::assertStringNotContainsString('<button', $html);
        }

        /**
         * The trail of one event must not show on another event's screen.
         */
        public function testItOnlyReadsTheEventItIsRendering(): void
        {
            $trail = $this->trail();
            $trail->seedChange(1, 999, 'elsewhere@example.test', 'update', 1);

            $html = $this->render(self::EVENT_ID, $trail);

            self::assertStringNotContainsString('elsewhere@example.test', $html);
        }

        private function render(int $eventId, FakeChangeTrail $trail): string
        {
            ob_start();
            $this->box($trail)->renderMetaBox($this->post($eventId));

            return (string) ob_get_clean();
        }

        private function box(?FakeChangeTrail $trail = null): ChangeHistoryBox
        {
            return new ChangeHistoryBox($trail ?? $this->trail());
        }

        private function trail(): FakeChangeTrail
                    {
                        return new FakeChangeTrail();
                    }

                    private function post(int $id): \WP_Post
                    {
                        $post = new \WP_Post($id);
                        $post->post_type = EventPostType::POST_TYPE;

                        return $post;
                    }
                }

    /**
         * Answers only the one read the history view performs, and records the
         * event id it was asked about so a test can prove the box does not read a
         * different event's trail.
         */
        final class FakeChangeTrail implements ChangeTrailReaderInterface
        {
            /** @var list<array<string, mixed>> */
            private array $rows = [];

            /** @var list<int> */
            public array $asked = [];

            public function seedChange(
                int $id,
                int $eventId,
                string $actor,
                string $kind,
                int $day,
                ?array $after = null
            ): void {
            $before = [
                'title' => 'Parish retreat day',
                'content' => 'The original description.',
                'excerpt' => '',
                'status' => 'publish',
                'event_type_term_ids' => [42],
                'featured_image_id' => 0,
                'meta' => [
                    'venue_id' => 7,
                    'start_local' => '2026-10-12T09:00',
                    'end_local' => '2026-10-12T12:00',
                    'status_flag' => '',
                    'contact' => 'contact@example.test',
                ],
            ];
            $after = $after ?? [
                'title' => 'Retreat day (renamed)',
                'content' => 'The amended description.',
                'excerpt' => '',
                'status' => 'publish',
                'event_type_term_ids' => [42],
                'featured_image_id' => 0,
                'meta' => array_merge($before['meta'], ['venue_id' => 8]),
            ];

            $this->rows[] = [
                'id' => $id,
                'event_id' => $eventId,
                'actor' => $actor,
                'kind' => $kind,
                'before_payload' => (string) json_encode($before),
                'after_payload' => (string) json_encode($after),
                'notified_at' => '2026-10-12 10:00:00',
                'reverted_by' => null,
                'reverted_at' => null,
                'created_at' => sprintf('2026-10-%02d 09:00:00', $day),
            ];
        }

        /**
         * @return list<array<string, mixed>>
         */
                public function forEvent(int $eventId): array
        {
            $this->asked[] = $eventId;

            $rows = array_values(array_filter(
                $this->rows,
                static fn (array $row): bool => (int) $row['event_id'] === $eventId
            ));
            usort(
                $rows,
                static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']
            );

            return array_slice($rows, 0, 25);
        }

        /**
         * @return array<string, mixed>|null
         */
        public function change(int $id): ?array
        {
            foreach ($this->rows as $row) {
                if ((int) $row['id'] === $id) {
                    return $row;
                }
            }

            return null;
        }

        public function markReverted(int $id, string $email, string $at): void
        {
            foreach ($this->rows as $index => $row) {
                if ((int) $row['id'] === $id) {
                    $this->rows[$index]['reverted_by'] = $email;
                    $this->rows[$index]['reverted_at'] = $at;

                    return;
                }
            }
        }

        public function corruptPayload(int $id, string $column, string $value): void
        {
            foreach ($this->rows as $index => $row) {
                if ((int) $row['id'] === $id) {
                    $this->rows[$index][$column] = $value;

                    return;
                }
            }
        }
    }

    /**
         * Records every statement so a test can see what the repository sent,
         * rendering each %d placeholder as its bound integer exactly as $wpdb
         * does -- which is what makes "the id was prepared, not interpolated"
         * an assertion about the query rather than about a regex.
         */
        final class RecordingHistoryDatabase implements DatabaseConnectionInterface
        {
            /** @var list<string> */
            public array $queries = [];

            /** @var array<int, mixed> */
            public array $results = [];

            public function prefix(): string
            {
                return 'wp_';
            }

            public function prepare(string $query, mixed ...$arguments): string
            {
                if (count($arguments) === 1) {
                    return str_replace('%d', (string) (int) $arguments[0], $query);
                }

                return str_replace(
                    ['%d', '%s'],
                    [(string) (int) $arguments[0], (string) $arguments[1]],
                    $query
                );
            }

            public function query(string $query): int|false
            {
                $this->queries[] = $query;

                return 0;
            }

            public function getRow(string $query): ?array
            {
                $this->queries[] = $query;

                return null;
            }

            public function getResults(string $query): array
            {
                $this->queries[] = $query;

                return $this->results;
            }

            public function escapeLike(string $text): string
            {
                return $text;
            }

            public function insertId(): int
            {
                return 0;
            }

            public function charsetCollate(): string
            {
                return '';
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