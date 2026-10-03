<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval {

    if (! function_exists(__NAMESPACE__ . '\\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_html')) {
        function esc_html(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_attr')) {
        function esc_attr(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\esc_url')) {
        function esc_url(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\sanitize_text_field')) {
        function sanitize_text_field(string $value): string
        {
            return trim(strip_tags($value));
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\sanitize_textarea_field')) {
        function sanitize_textarea_field(string $value): string
        {
            return trim(strip_tags($value));
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\wp_unslash')) {
        function wp_unslash(string $value): string
        {
            return stripslashes($value);
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\absint')) {
        function absint(mixed $value): int
        {
            return abs((int) $value);
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . $path;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\add_query_arg')) {
        /**
         * @param array<string, scalar> $query
         */
        function add_query_arg(array $query, string $url): string
        {
            $parts = parse_url($url);
            $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'example.test')
                . ($parts['path'] ?? '/');
            parse_str($parts['query'] ?? '', $existing);
            $args = http_build_query([...$existing, ...$query]);

            return $args === '' ? $base : $base . '?' . $args;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\remove_query_arg')) {
        /**
         * @param list<string> $keys
         */
        function remove_query_arg(array $keys, string $url): string
        {
            $parts = parse_url($url);
            $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'example.test')
                . ($parts['path'] ?? '/');
            parse_str($parts['query'] ?? '', $query);
            foreach ($keys as $key) {
                unset($query[$key]);
            }
            $args = http_build_query($query);

            return $args === '' ? $base : $base . '?' . $args;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://example.test' . $path;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\wp_get_referer')) {
        function wp_get_referer(): string|false
        {
            $referer = $_SERVER['HTTP_REFERER'] ?? false;

            return is_string($referer) && $referer !== '' ? $referer : false;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\wp_nonce_field')) {
        /**
         * The shared Approval-namespace copy in tests/Support/WordPressStubs.php
         * is authoritative and is loaded first by ReviewerNotificationPreferenceTest.
         * This declaration is retained only so this file still works when run on
         * its own; it records the same global, so a test cannot pass here and
         * fail there.
         */
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true): string
        {
            $GLOBALS['adct_test_nonce_fields'][] = ['action' => $action, 'name' => $name];
            $markup = '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '" />';
            echo $markup;

            return $markup;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\is_user_logged_in')) {
        function is_user_logged_in(): bool
        {
            return ($GLOBALS['adct_front_user'] ?? null) instanceof \WP_User;
        }
    }

        /**
         * Nonce verification is a WordPress cryptographic service, not plugin logic.
         * The double answers only for the token this test file hands out, so a handler
         * that verifies the wrong action still fails its own test.
         */
        if (! function_exists(__NAMESPACE__ . '\\wp_verify_nonce')) {
            function wp_verify_nonce(string $nonce, string $action): bool
            {
                return $nonce === 'nonce-for-' . $action;
            }
        }

        /**
         * `wp_safe_redirect()` ends the request by exiting, which no assertion could
         * observe. Throwing here instead leaves `handleSave()`'s decision on the
         * stack, so the test can read where the handler would have sent the dean.
         */
        if (! function_exists(__NAMESPACE__ . '\\wp_safe_redirect')) {
            function wp_safe_redirect(string $location, int $status = 302): bool
            {
                throw new \FrontQueueRedirected($location);
            }
        }

    if (! function_exists(__NAMESPACE__ . '\\current_user_can')) {
        /**
         * The shared Approval-namespace copy in tests/Support/WordPressStubs.php
         * is authoritative — it resolves capabilities per *user id* rather than
         * from one flat list, which is what lets a test hold a reviewer's
         * entitlement while denying the same capability to someone else. This
         * declaration is retained only so the file still works when run on its
         * own, and setUp() seeds both schemes so either answer is the same.
         *
         * @return bool
         */
        function current_user_can(string $capability)
        {
            return in_array($capability, $GLOBALS['adct_front_caps'] ?? [], true);
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\wp_get_current_user')) {
        function wp_get_current_user(): ?\WP_User
        {
            $user = $GLOBALS['adct_front_user'] ?? null;

            return $user instanceof \WP_User ? $user : null;
        }
    }

    if (! function_exists(__NAMESPACE__ . '\\add_shortcode')) {
        function add_shortcode(string $tag, callable $callback): void
        {
            $GLOBALS['adct_front_shortcodes'][$tag] = $callback;
        }
    }

        /**
         * Real `wp_die()` ends the request, which cannot be observed from a unit
         * test. Throwing instead lets `render()` take the branch it actually takes
         * in production — the `catch (DomainException)` — so the assertion covers
         * the code that ships rather than a rewritten copy of it.
         *
         * @param mixed[] $args
         */
        if (! function_exists(__NAMESPACE__ . '\\wp_die')) {
            function wp_die(string $message = '', string $title = '', array $args = []): void
            {
                throw new \DomainException(($args['response'] ?? 500) . ': ' . $message);
            }
        }
    }

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval {

    require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';

    /**
     * Stands in for the request ending, so a redirect can be asserted on.
     */
    final class FrontQueueRedirected extends \RuntimeException
    {
    }

        // The stub above is declared in the test namespace, but a function stub in
        // another namespace block cannot `use` it, so it throws a global alias.
        if (! class_exists('FrontQueueRedirected', false)) {
            class_alias(FrontQueueRedirected::class, 'FrontQueueRedirected');
        }

    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Events\EventValidator;
    use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
        use ADCT\ParishIntake\Core\Ports\ClockInterface;
        use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
    use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
    use ADCT\ParishIntake\WordPress\Approval\FrontEndApprovalQueue;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;

    /**
     * Issue #72: a dean approves from a front-end page, sees only their own
     * deaneries, and an archdiocese reviewer keeps using wp-admin.
     *
     * Scope — the subject of the acceptance criterion — is decided in SQL, so
     * the collaborators here are real (`ReviewQueueRepository` over a recording
     * `DatabaseConnectionInterface`) rather than stubs. A stub would only prove
     * the page called the method it was told to call; these tests assert the SQL
     * that actually left the object.
     *
     * The three POST handlers end in `exit`, so they are covered by
     * `tests/Integration/FrontEndApprovalQueueCheck.php` against a real database.
     * What is proven here is everything short of the write: the capability gate,
     * the scope predicate on every read, and the escaping of what reaches the
     * page.
     */
    final class FrontEndApprovalQueueTest extends TestCase
    {
        private FrontQueueRecordingDatabase $database;

        protected function setUp(): void
        {
            $_GET = [];
            $_POST = [];
            $_REQUEST = [];
            $_SERVER['HTTP_REFERER'] = 'https://example.test/approval-queue/';
            unset($GLOBALS['adct_front_shortcodes'], $GLOBALS['adct_test_blocks']);
            $this->actAs(11, 'dean@example.test', [Capabilities::APPROVE_DEANERY]);
            $this->database = new FrontQueueRecordingDatabase();
            $this->database->countRows = [['category' => 'approval', 'total' => 1, 'awaiting' => 1]];
        }

        protected function tearDown(): void
        {
            $_POST = [];
            $_REQUEST = [];
            unset(
                $GLOBALS['adct_front_caps'],
                $GLOBALS['adct_front_user'],
                $GLOBALS['adct_front_shortcodes'],
                $GLOBALS['adct_test_current_user_id'],
                $GLOBALS['adct_test_current_user'],
                $GLOBALS['adct_test_wp_caps'],
                $GLOBALS['adct_test_blocks']
            );

            parent::tearDown();
        }

        /**
         * Sets who is acting and what they may do.
         *
         * Two globals are written because the Approval namespace has two
         * `current_user_can()` implementations in this suite — this file's own,
         * which reads one flat list, and the shared copy in
         * tests/Support/WordPressStubs.php, which resolves per user id. Which one
         * is in force depends on which file PHPUnit happened to load first under
         * `executionOrder="random"`. Writing both here means the behaviour under
         * test is the same either way, so a test in this file cannot pass in
         * isolation and fail in the suite.
         *
         * Routing every grant through this method is what keeps them in step;
         * a test that assigned `adct_front_caps` directly would silently diverge
         * from the per-user table the shared stub reads.
         *
         * @param list<string> $capabilities
         */
        private function actAs(int $userId, ?string $email, array $capabilities): void
        {
            $GLOBALS['adct_front_caps'] = $capabilities;
            $GLOBALS['adct_front_user'] = $email === null ? null : new \WP_User($userId, $email);
            $GLOBALS['adct_test_current_user_id'] = $userId;
            $GLOBALS['adct_test_current_user'] = $GLOBALS['adct_front_user'];
            $GLOBALS['adct_test_wp_caps'] = [$userId => $capabilities];
        }

        /**
         * The half of the criterion that can be read: a dean sees their queue,
         * and the query that produced it is bounded to the deaneries whose live
         * approver rows name their user id.
         */
        public function testADeanSeesTheirOwnDeaneriesOnly(): void
        {
            $this->database->rows = [$this->candidate(21, 'Parish retreat')];

            $html = $this->renderQueue();

            self::assertStringContainsString('Parish retreat', $html);
            self::assertStringContainsString('Approve selected', $html);

            $scope = $this->scopeSql();
            self::assertStringContainsString('adct_pi_deanery_approvers', $scope);
            self::assertStringContainsString('adct_pi_deaneries', $scope);
            // Matched on the live approver's user id, through an *active* deanery
            // and an *active* approver row — never on the address typed into a form.
            self::assertStringContainsString('wp_user_id', $scope);
            self::assertStringContainsString("a.active = 1", $scope);
            self::assertStringContainsString("d.status = 'active'", $scope);
            // The current user is bound as a parameter, never concatenated in.
            self::assertStringContainsString('a.wp_user_id = 11', $scope);
        }

        /**
         * Every read the page makes is scoped. A single unscoped read would leak
         * another deanery's item into a page this user is entitled to see, so
         * each of the three queries is checked.
         */
        public function testEveryQueryThePageRunsIsScoped(): void
        {
            $this->database->rows = [$this->candidate(21, 'Parish retreat')];

            $this->renderQueue();

            $reads = array_values(array_filter(
                $this->database->queries,
                static fn (string $query): bool => str_starts_with($query, 'SELECT')
            ));

            self::assertCount(4, $reads, 'counts, awaiting, decided and changes were all read.');
            foreach ($reads as $read) {
                self::assertMatchesRegularExpression(
                    '/EXISTS \(SELECT 1 FROM `wp_adct_pi_parishes` scoped_parish/',
                    $read,
                    'An unscoped read reached the database: ' . $read
                );
            }
        }

        /**
         * The change list is scoped through the same predicate, but it cannot
         * use the candidate column: a change whose event has no candidate is
         * attributed through the event's `parish_id` post meta instead. Asserting
         * the post-meta join is what proves the two are not confused.
         */
        public function testChangesAreScopedThroughTheEventTheyBelongTo(): void
        {
            $this->database->rows = [$this->candidate(21, 'Retreat')];
            $this->database->changeRows = [$this->change(41, 21, 5)];

            $html = $this->renderQueue();

            self::assertStringContainsString('Recent changes', $html);

            $changeQuery = '';
            foreach ($this->database->queries as $query) {
                if (str_contains($query, 'adct_pi_event_changes')) {
                    $changeQuery = $query;
                }
            }

            self::assertNotSame('', $changeQuery, 'The changes query was not sent.');
            self::assertStringContainsString("meta.meta_key = 'parish_id'", $changeQuery);
            // And the scope is applied to *that* parish, not to c.parish_id.
            self::assertStringContainsString('scoped_parish.id = COALESCE(', $changeQuery);
            self::assertStringNotContainsString('scoped_parish.id = c.parish_id', $changeQuery);
        }

        /**
         * A change that can only be attributed through post meta — no candidate
         * behind it — is still in scope for the right dean, and still absent for
         * the wrong one. That is the case the two-scope split exists for.
         */
        public function testAChangeWithoutACandidateIsScopedByPostMetaAlone(): void
        {
            $this->database->changeRows = [$this->change(41, 900, 5)];
            $this->database->rows = [];

            $this->renderQueue();

            $changeQuery = '';
            foreach ($this->database->queries as $query) {
                if (str_contains($query, 'adct_pi_event_changes')) {
                    $changeQuery = $query;
                }
            }

            self::assertStringContainsString('LEFT JOIN `wp_adct_pi_event_candidates` c ON c.id = ch.candidate_id', $changeQuery);
            self::assertStringContainsString('COALESCE(c.parish_id, NULLIF(TRIM(meta.meta_value), \'\'))', $changeQuery);
        }

        /**
         * A reviewer is archdiocese-wide, so the approver join must not appear
         * at all — otherwise a reviewer would silently be narrowed to their own
         * deaneries.
         */
        public function testAReviewerGetsTheUnscopedBranch(): void
        {
            $this->actAs(11, 'dean@example.test', [Capabilities::REVIEW]);

            $this->renderQueue();

            $reads = array_values(array_filter(
                $this->database->queries,
                static fn (string $query): bool => str_starts_with($query, 'SELECT')
            ));
            foreach ($reads as $read) {
                self::assertStringNotContainsString('scoped_parish', $read);
            }
        }

        /**
         * The page is placeable by an ordinary editor, which for this archdiocese
         * means a shortcode and a block (ADR 0008 — deans never need wp-admin).
         */
        public function testTheQueueIsAvailableAsAShortcodeAndAsABlock(): void
        {
            $page = $this->page();
            $page->register();

            self::assertArrayHasKey(FrontEndApprovalQueue::SHORTCODE, $GLOBALS['adct_front_shortcodes']);
            self::assertArrayHasKey(FrontEndApprovalQueue::BLOCK, $GLOBALS['adct_test_blocks']);

            $this->database->rows = [$this->candidate(21, 'Parish retreat')];
            ob_start();

            try {
                $fromShortcode = ($GLOBALS['adct_front_shortcodes'][FrontEndApprovalQueue::SHORTCODE])();
                $fromBlock = ($GLOBALS['adct_test_blocks'][FrontEndApprovalQueue::BLOCK]['render_callback'])([]);
            } finally {
                // Both routes echo the nonce fields, as the shortcode does in
                // production; capture so the run stays free of stray output.
                ob_end_clean();
            }

            self::assertSame($fromShortcode, $fromBlock);
        }

        /**
         * A parish contact has no review capability, so the page offers the
         * sign-in prompt rather than an empty table — "nothing to do" and "not for
         * you" must not look the same.
         */
        public function testAParishContactSeesTheSignInPromptRatherThanAQueue(): void
        {
            $this->actAs(11, 'dean@example.test', ['adct_pi_parish_contact']);

            $html = $this->renderQueue();

            self::assertStringContainsString('sign in with the link emailed to you', $html);
            self::assertStringNotContainsString('Approve selected', $html);
        }

        public function testASignedOutVisitorSeesTheSignInPrompt(): void
        {
            $this->actAs(0, null, []);

            $html = $this->renderQueue();

            self::assertStringContainsString('sign in with the link emailed to you', $html);
            self::assertSame([], $this->database->queries, 'A signed-out visitor must not reach the database.');
        }

        /**
         * A deactivated account holds its capabilities but not its standing:
         * WordPress keeps `user_status` on the user, so the page must check it
         * rather than trusting `current_user_can()` alone.
         */
        public function testADeactivatedDeanCannotViewTheQueue(): void
        {
            // user_status 1 is WordPress's "deactivated" flag: capabilities survive it,
            // standing does not.
            $GLOBALS['adct_front_user'] = new \WP_User(11, 'dean@example.test', 1);
            $GLOBALS['adct_test_current_user'] = $GLOBALS['adct_front_user'];

            $html = $this->renderQueue();

            self::assertStringContainsString('unavailable right now', $html);
            self::assertStringNotContainsString('Approve selected', $html);
                        self::assertSame(
                            [],
                            $this->database->queries,
                            'A refused viewer must not reach the database at all.'
                        );
                    }

        /**
         * canView() is the gate the block and the shortcode both rely on, and it
         * accepts either role — a dean, or an archdiocese reviewer.
         */
        public function testTheGateAcceptsEitherReviewRole(): void
        {
            $this->actAs(11, 'dean@example.test', [Capabilities::REVIEW]);
            self::assertTrue($this->page()->canView());

            $this->actAs(11, 'dean@example.test', [Capabilities::APPROVE_DEANERY]);
            self::assertTrue($this->page()->canView());

            $this->actAs(11, 'dean@example.test', [Capabilities::MANAGE_SETTINGS]);
            self::assertFalse($this->page()->canView());

            $this->actAs(0, null, []);
            self::assertFalse($this->page()->canView());
        }

        /**
         * An empty queue says so plainly instead of rendering an empty table with
         * an Approve button that would approve nothing.
         */
        public function testAnEmptyQueueSaysSo(): void
        {
            $this->database->rows = [];

            $html = $this->renderQueue();

            self::assertStringContainsString('Nothing is waiting for you', $html);
            self::assertStringNotContainsString('Approve selected', $html);
        }

        /**
         * Parish, sender, title and actor all come out of the database, so they
         * are escaped on the way to the page.
         */
        public function testDatabaseValuesAreEscaped(): void
        {
            $row = $this->candidate(21, 'Retreat');
            $row['parish_name'] = '<script>alert(1)</script>';
            $row['sender_email'] = '"><img src=x onerror=alert(1)>';
            $row['fields'] = json_encode(
                ['title' => '<script>alert(2)</script>', 'event_date' => '12/10/2026'],
                JSON_THROW_ON_ERROR
            );
            $this->database->rows = [$row];

            $html = $this->renderQueue();

            self::assertStringNotContainsString('<script>', $html);
                        // The hostile address is escaped, so the attribute is inert text. What
                        // must never survive is the raw markup that would make it live again.
                        self::assertStringNotContainsString('<img src=x onerror=', $html);
                        self::assertStringContainsString('&lt;script&gt;', $html);
                        self::assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $html);
                    }

        public function testChangeActorAndParishAreEscaped(): void
        {
            $change = $this->change(41, 21, 5);
            $change['actor'] = '<script>alert(3)</script>';
            $change['parish_name'] = '<script>alert(4)</script>';
            $this->database->changeRows = [$change];
            $this->database->rows = [];

            $html = $this->renderQueue();

            self::assertStringNotContainsString('<script>', $html);
        }

        /**
         * A candidate whose stored details cannot be decoded must not take the
         * page down: the dean sees a marked row and a reviewer fixes it in
         * wp-admin, rather than an error page.
         */
        public function testUnreadableEventDetailsDoNotBreakThePage(): void
        {
            $row = $this->candidate(21, 'Retreat');
            $row['fields'] = 'not json at all';
            $this->database->rows = [$row];

            $html = $this->renderQueue();

            self::assertStringContainsString('needs manual repair', $html);
            self::assertStringNotContainsString('could not be loaded', $html);
        }

        public function testAMissingTitleIsLabelledRatherThanLeftBlank(): void
        {
            $row = $this->candidate(21, '');
            $this->database->rows = [$row];

            $html = $this->renderQueue();

            self::assertStringContainsString('(Title unavailable)', $html);
        }

        /**
         * A database failure must not surface a query, a table name or a
         * credential on a public page.
         */
        public function testADatabaseFailureIsReportedWithoutLeakingDetail(): void
        {
            $this->database->failure = 'SELECT * FROM wp_adct_pi_event_candidates password=hunter2';

            $html = $this->renderQueue();

            self::assertStringContainsString('could not be loaded', $html);
            self::assertStringNotContainsString('hunter2', $html);
        }

        /**
         * The database stores UTC; a dean reads in Cape Town.
         */
        public function testTimestampsAreShownInTheArchdioceseTimezone(): void
        {
            $row = $this->candidate(21, 'Retreat');
            $row['updated_at'] = '2026-10-12 07:00:00';
            $this->database->rows = [$row];

            $html = $this->renderQueue();

            // 07:00 UTC is 09:00 in Africa/Johannesburg (UTC+2; SA has no DST).
            self::assertStringContainsString('12/10/2026 09:00', $html);
        }

        /**
                 * The stored recurrence is decoded exactly the way `ReviewQueuePage`
                 * decodes it, so an editor for a candidate with no stored recurrence
                 * renders and validates instead of failing on a null argument.
                 *
                 * Regression: `handleSave()` passed `null` whenever the column was
                 * absent or still raw JSON, and `CandidateEditValidator::validate()`
                 * declares `array $storedRecurrence`, so a dean saving any one-off
                 * event hit a TypeError and never reached the queue again.
                 */
                public function testAnEditorRendersForACandidateWithNoStoredRecurrence(): void
                {
                    $row = $this->candidate(21, 'Retreat');
                    $_GET['adct_pi_edit'] = '21';
                    $this->database->rows = [$row];

                    $html = $this->renderQueue();

                    self::assertStringContainsString('Edit candidate #21', $html);
                    // Nothing stored means nothing selected, not a PHP warning. `none` is the
                                        // canonical empty preset, the same sentinel `CandidateEditForm` posts.
                                        self::assertStringContainsString(
                                            '<option value="none" selected>Does not repeat</option>',
                                            $html
                                        );
                }

                /**
                 * The recurrence column comes back as raw JSON on a single-row read,
                 * which the admin queue decodes rather than type-checks. A row stored
                 * that way must select the stored preset instead of losing it.
                 */
                public function testAStoredRecurrenceStoredAsJsonIsDecoded(): void
                {
                    $row = $this->candidate(21, 'Retreat');
                    $row['recurrence'] = json_encode(
                        ['preset' => 'monthly', 'interval' => 1],
                        JSON_THROW_ON_ERROR
                    );
                    $_GET['adct_pi_edit'] = '21';
                    $this->database->rows = [$row];

                    $html = $this->renderQueue();

                    self::assertStringContainsString('Edit candidate #21', $html);
                    self::assertStringContainsString('<option value="monthly" selected>Monthly</option>', $html);
                }

                /**
                                 * A rejected save re-renders the editor in place, and it must show the
                                 * dean what they typed — not the stored candidate, and not the
                                 * "invalid event details" fallback.
                                 *
                                 * Regression: the re-render folded the dean's values into
                                 * `$row['fields']` as a decoded array, but `ReviewQueuePolicy::fields()`
                                 * reads that column as the JSON string the database stores. The policy
                                 * threw, `safeFields()` swallowed it, and the form came back with the
                                 * marker title, an empty date box and none of the corrections — so a
                                 * dean correcting one typo lost the whole correction with no warning.
                                 */
                                public function testARejectedSaveReRendersTheValuesTheDeanTyped(): void
                                {
                                    $this->database->rows = [$this->candidate(21, 'Retreat')];
                                    $_POST = [
                                        'action' => FrontEndApprovalQueue::SAVE_ACTION,
                                        'candidate_id' => '21',
                                        'save_mode' => 'save',
                                        'title' => 'Dean corrected the title',
                                        // Not a day-first date, so validation fails and re-renders.
                                        'event_date' => '2026-10-12',
                                        'event_time' => '11:00',
                                        'description' => 'Fictional corrected description.',
                                        FrontEndApprovalQueue::SAVE_NONCE => 'nonce-for-' . FrontEndApprovalQueue::SAVE_ACTION,
                                    ];

                                    ob_start();
                                    try {
                                        $this->page()->handleSave();
                                    } catch (\AdctTestWpDie $refused) {
                                        ob_end_clean();
                                        self::fail(
                                            'A validation failure must re-render, not refuse ('
                                            . $refused->status . ' ' . $refused->getMessage() . ').'
                                        );
                                    } catch (\FrontQueueRedirected) {
                                        ob_end_clean();
                                        self::fail('A validation failure must re-render, not redirect.');
                                    }
                                    $html = (string) ob_get_clean();

                                    self::assertStringContainsString('Dean corrected the title', $html);
                                    self::assertStringContainsString('value="2026-10-12"', $html);
                                    self::assertStringContainsString('Fictional corrected description.', $html);
                                    // The stored title must not survive into a re-render of the dean's input.
                                    self::assertStringNotContainsString('needs manual repair', $html);
                                    self::assertStringContainsString('Enter a valid date', $html);
                                }

                                /**
                                                                 * "Save and approve" approves even when the corrections were already saved.
                                                                 *
                                                                 * Regression: the handler decided only when the save reported a change,
                                                                 * so `if ($mode !== 'save' && $saved !== 'unchanged')` silently turned a
                                                                 * save-and-approve into a plain save. A dean who pressed Save, went back,
                                                                 * corrected nothing and pressed "Save and approve" was redirected with
                                                                 * `saved=unchanged` and no decision: the item stayed in the queue and the
                                                                 * dean had no way to see that approving did nothing. An unchanged *text*
                                                                 * edit is not an unchanged *decision* — the two are separate acts and
                                                                 * `decide()` re-resolves scope, so calling it is both safe and required.
                                                                 */
                                                                    public function testSaveAndApproveDecidesEvenWhenTheTextDidNotChange(): void
                                                                {
                                                                    // The candidate exactly as the validator would have stored it, so
                                                                    // re-submitting the form produces no changed key at all and the
                                                                    // save legitimately reports `unchanged`.
                                                                    $row = $this->candidate(21, 'Retreat');
                                                                    $row['fields'] = json_encode([
                                                                        'title' => 'Retreat',
                                                                        'event_date' => '2026-10-12',
                                                                        'event_time' => '10:00',
                                                                        'event_type' => '',
                                                                        'description' => '',
                                                                        'all_day' => false,
                                                                        'status_flag' => 'scheduled',
                                                                        'featured' => false,
                                                                        'exdates' => [],
                                                                        'rdates' => [],
                                                                        'contact' => ['name' => '', 'email' => '', 'phone' => ''],
                                                                    ], JSON_THROW_ON_ERROR);
                                                                    $this->database->rows = [$row];
                                                                    $_POST = [
                                                                        'action' => FrontEndApprovalQueue::SAVE_ACTION,
                                                                        'candidate_id' => '21',
                                                                        'save_mode' => 'approve',
                                                                        'title' => 'Retreat',
                                                                        'event_date' => '12/10/2026',
                                                                        'event_time' => '10:00',
                                                                        'description' => '',
                                                                        FrontEndApprovalQueue::SAVE_NONCE => 'nonce-for-' . FrontEndApprovalQueue::SAVE_ACTION,
                                                                    ];

                                                                    $redirect = $this->redirectFromSave();

                                                                    self::assertStringContainsString('saved=unchanged', $redirect);
                                                                    self::assertStringContainsString(
                                                                        'decision=',
                                                                        $redirect,
                                                                        'An unchanged text edit must not swallow the approval the dean asked for.'
                                                                    );
                                                                    // The recording database reports no affected rows, so `decide()`
                                                                    // cannot return `decided` here. What matters is that the decision
                                                                    // was attempted at all: the approval UPDATE is the only statement
                                                                    // that stamps `approved_by`.
                                                                    self::assertNotSame(
                                                                        [],
                                                                        array_filter(
                                                                            $this->database->writes,
                                                                            static fn (string $write): bool => str_contains($write, 'approved_by = ')
                                                                        ),
                                                                        'The approval itself must reach the database.'
                                                                    );
                                                                }

                                                                private function redirectFromSave(): string
                                                                {
                                                                    ob_start();
                                                                    try {
                                                                        $this->page()->handleSave();
                                                                    } catch (\AdctTestWpDie $refusal) {
                                                                        ob_end_clean();
                                                                        self::fail(
                                                                            'A save must not be refused: '
                                                                            . $refusal->status . ' ' . $refusal->getMessage()
                                                                        );
                                                                    } catch (FrontQueueRedirected $redirected) {
                                                                        ob_end_clean();

                                                                        return $redirected->getMessage();
                                                                    }
                                                                    ob_end_clean();

                                                                    self::fail('A save must redirect.');
                                                                }

                                                                /**
                                                                 * The editor link is scoped to the item being edited and is offered only
                                                                 * while the item is still undecided.
                                                                 */
                public function testTheEditLinkIsOfferedOnlyWhileTheItemIsUndecided(): void
                {
                    $this->database->rows = [$this->candidate(21, 'Retreat')];

            $html = $this->renderQueue();

            self::assertStringContainsString('adct_pi_edit=21', $html);
            self::assertStringContainsString('Edit before deciding', $html);

            $decided = $this->candidate(22, 'Already done');
            $decided['status'] = 'rejected';
            $decided['decided_at'] = '2026-10-11 09:00:00';
            $decided['decided_by'] = 'dean@example.test';
            $this->database->rows = [$decided];

            self::assertStringNotContainsString('adct_pi_edit=22', $this->renderQueue());
        }

        /**
         * A row needing a human before publication must not be selectable for
         * ordinary approval — the same rule wp-admin applies — but must stay
         * rejectable, so a dean is never stuck with an item they cannot act on.
         */
        public function testAnAmbiguousItemIsNotOfferedForApprovalButIsForRejection(): void
        {
            $row = $this->candidate(21, 'Ambiguous retreat');
            $row['match_review_required'] = 1;
            $row['matched_candidate_id'] = 22;
            $this->database->rows = [$row];

            $html = $this->renderQueue();

            self::assertStringContainsString('Manual resolution required before approval', $html);
            // Selectable, so it can be rejected — the aria-label says which.
            self::assertStringContainsString('for rejection; manual resolution is required', $html);
        }

        /**
         * The result of the last action is read back from the query string, which
         * is the only place a redirect can carry it.
         */
        public function testTheOutcomeOfTheLastActionIsShown(): void
        {
            $_GET = ['changed' => '2', 'skipped' => '1', 'manual' => '0'];
            $this->database->rows = [];

            $html = $this->renderQueue();

            self::assertStringContainsString('2 updated, 1 already decided', $html);
        }

        /**
         * A change that has already been reverted offers no revert button, so a
         * dean is not invited to request a link that would be refused.
         */
        public function testAnAlreadyRevertedChangeOffersNoRevertButton(): void
        {
            $this->database->changeRows = [$this->change(41, 21, 5, reverted: true)];
            $this->database->rows = [];

            $html = $this->renderQueue();

            self::assertStringContainsString('Reverted by', $html);
            self::assertStringNotContainsString('Ask to revert', $html);
        }

        /**
         * Reverting an approved event notifies the parish, so the front end only
         * ever *asks*: the button posts to the request handler and says so, and
         * nothing on this page publishes or unpublishes an event.
         */
        public function testRevertingIsOnlyEverRequestedNotPerformed(): void
        {
            $this->database->changeRows = [$this->change(41, 21, 5)];
            $this->database->rows = [];

            $html = $this->renderQueue();

            self::assertStringContainsString(FrontEndApprovalQueue::REVERT_ACTION, $html);
            self::assertStringContainsString('Ask to revert', $html);
            self::assertStringNotContainsString('name="revert"', $html);
            self::assertSame([], $this->database->writes);
        }

        /**
         * Every form on the page posts a nonce for the action it performs, and
         * the edit link is not a form at all — a GET shows, only a POST acts.
         */
        public function testEveryFormIsNoncedAndOnlyFormsPost(): void
        {
            $this->database->rows = [$this->candidate(21, 'Retreat')];
            $this->database->changeRows = [$this->change(41, 21, 5)];

            $html = $this->renderQueue();

            self::assertStringContainsString('name="' . FrontEndApprovalQueue::BULK_NONCE . '"', $html);
            self::assertStringContainsString('name="' . FrontEndApprovalQueue::BULK_NONCE . '"', $html);
            self::assertStringContainsString('method="post"', $html);
            // The only anchor is the editor link, and it carries no state.
            self::assertSame(1, substr_count($html, '<a href='));
            self::assertStringContainsString('adct_pi_edit=21', $html);
        }

        private function page(): FrontEndApprovalQueue
        {
            return new FrontEndApprovalQueue(
                new ReviewQueueRepository($this->database, new FrontQueueFixedClock()),
                new CandidatePublisher(
                    new FrontQueueNullStore(),
                    new EventValidator(new DateTimeZone('Africa/Johannesburg'))
                ),
                new ReviewQueuePolicy()
            );
        }

        /**
         * `render()` both returns the markup and writes the nonce fields straight
         * to the output buffer, which is what WordPress does — `wp_nonce_field()`
         * echoes and returns. PHPUnit fails a test that prints anything
         * (`beStrictAboutOutputDuringTests`), so the echo is captured and discarded
         * here rather than left to escape into the test run.
         *
         * Buffering it is not a way of hiding a stray print: the assertions below
         * read the returned markup, and the nonce field's presence in it is what
         * several of them check.
         */
        private function renderQueue(): string
        {
            ob_start();

            try {
                $html = $this->page()->render();
            } finally {
                ob_end_clean();
            }

            return $html;
        }

        /**
         * The candidate rows a non-reviewer read is bounded by, taken from the
         * SQL itself rather than assumed.
         */
        private function scopeSql(): string
        {
            $scope = '';
            foreach ($this->database->queries as $query) {
                if (str_contains($query, 'scoped_parish')) {
                    $scope = $query;
                    break;
                }
            }
            self::assertNotSame('', $scope, 'No scoped query reached the database.');

            return $scope;
        }

        /** @return array<string, mixed> */
        private function candidate(int $id, string $title): array
        {
            return [
                'id' => $id,
                'status' => 'awaiting_approval',
                'approved_by' => null,
                'approved_at' => null,
                'decided_at' => null,
                'decided_by' => null,
                'parish_id' => 5,
                'message_id' => 3,
                'confidence' => 0.91,
                'match_kind' => 'new',
                'match_event_id' => 0,
                'match_review_required' => 0,
                'matched_candidate_id' => null,
                'can_retry' => 0,
                'updated_at' => '2026-10-12 07:00:00',
                'parish_name' => 'Fictional Parish',
                'sender_email' => 'parish@example.test',
                // Stored as the JSON column, exactly as the repository reads it.
                'fields' => json_encode(
                    ['title' => $title, 'event_date' => '12/10/2026', 'event_time' => '10:00'],
                    JSON_THROW_ON_ERROR
                ),
            ];
        }

        /** @return array<string, mixed> */
        private function change(int $id, int $eventId, int $parishId, bool $reverted = false): array
        {
            return [
                'id' => $id,
                'event_id' => $eventId,
                'candidate_id' => 12,
                'actor' => 'dean@example.test',
                'kind' => 'update',
                'notified_at' => null,
                'reverted_by' => $reverted ? 'dean@example.test' : null,
                'reverted_at' => $reverted ? '2026-10-12 08:00:00' : null,
                'created_at' => '2026-10-12 07:00:00',
                'parish_id' => $parishId,
                'parish_name' => 'Fictional Parish',
            ];
        }
    }

    final class FrontQueueFixedClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-12 09:00:00', new DateTimeZone('Africa/Johannesburg'));
        }
    }

    /**
     * Records the SQL instead of running it.
     *
     * The scope assertions in this test read the query text, so `prepare()` has
     * to interpolate the bound values the way `$wpdb` does — otherwise
     * `a.wp_user_id = %d` would never show the user the query was scoped to.
     */
    final class FrontQueueRecordingDatabase implements DatabaseConnectionInterface
    {
        /** @var list<array<string, mixed>> */
        public array $rows = [];

        /** @var list<array<string, mixed>> */
        public array $changeRows = [];

                /** @var list<array<string, mixed>> */
                public array $countRows = [];

                /** @var list<string> */
                public array $queries = [];

        /** @var list<string> */
        public array $writes = [];

        public string $failure = '';

        private string $error = '';

        public function prefix(): string
        {
            return 'wp_';
        }

        public function prepare(string $query, mixed ...$args): string
        {
            if ($args === []) {
                return $query;
            }

            foreach ($args as $arg) {
                $value = is_int($arg) || is_float($arg) ? (string) $arg : "'" . $arg . "'";
                $query = preg_replace('/%[dfs]/', (string) $value, $query, 1) ?? $query;
            }

            return $query;
        }

        public function query(string $query): int|false
        {
            $this->queries[] = $query;
            if (str_starts_with($query, 'INSERT') || str_starts_with($query, 'UPDATE')) {
                $this->writes[] = $query;
            }

            return str_starts_with($query, 'INSERT') ? 1 : 0;
        }

        public function getRow(string $query): ?array
        {
            return $this->getResults($query)[0] ?? null;
        }

        public function getResults(string $query): array
        {
            $this->queries[] = $query;
            if ($this->failure !== '') {
                $this->error = $this->failure;

                return [];
            }

            if (str_contains($query, 'adct_pi_event_changes')) {
                            return $this->changeRows;
                        }

                        // `counts()` groups by category and reads the `category` column it
                        // selected, so handing it a candidate row would raise an undefined
                        // key. Giving it the aggregate row it asked for keeps the
                        // collaborator real without pretending a GROUP BY returned items.
                        return str_contains($query, 'GROUP BY category') ? $this->countRows : $this->rows;
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
            $this->error = '';
        }

        public function lastError(): string
        {
            return $this->error;
        }

        public function lastQuery(): string
        {
            return $this->queries === [] ? '' : $this->queries[count($this->queries) - 1];
        }
    }

    /**
     * The publication store is never reached from `render()`, so an empty one is
     * enough — and it makes an accidental publish during a read fail loudly
     * instead of passing quietly.
     */
    final class FrontQueueNullStore implements PublicationStoreInterface
    {
        /** @var list<int> */
        public array $published = [];

        public function publish(int $candidateId, callable $prepare): int
        {
            $this->published[] = $candidateId;

            return $candidateId;
        }
    }
}