<?php
declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Approval {

    require_once __DIR__ . '/../../../Support/WordPressAuthDoubles.php';

    use ADCT\ParishIntake\Core\Auth\Capabilities;
    use ADCT\ParishIntake\Core\Events\EventValidator;
    use ADCT\ParishIntake\Core\Parsing\UnparsedDateTimeCandidate;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
    use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
    use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
    use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
    use ADCT\ParishIntake\WordPress\Approval\FrontEndApprovalQueue;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * #167, the dean's own screen.
     *
     * A dean never needs wp-admin (ADR 0008), so a warning that lives only on the
     * admin detail screen is in practice one that nobody who can fix the date ever
     * sees. The editor form is where a date gets corrected, so the warning and the
     * acknowledgement belong there -- and inside the form element, or the checkbox
     * renders, looks right, and submits nothing.
     */
    final class UnparsedDateTimeDeanQueueTest extends TestCase
    {
        private DeanQueueRecordingDatabase $database;

        /** @var bool Whether the last save returned by re-rendering instead of deciding. */
        private bool $reRendered = false;

        /** @var array<string, mixed> */
        private array $globalsBackup = [];

        protected function setUp(): void
        {
            parent::setUp();

            $this->database = new DeanQueueRecordingDatabase();
            $this->database->countRows = [['category' => 'approval', 'total' => 1, 'awaiting' => 1]];
            $_REQUEST = [];
            $_SERVER['HTTP_REFERER'] = 'https://example.test/approval-queue/';

            foreach ([
                'adct_front_caps',
                'adct_front_user',
                'adct_test_current_user_id',
                'adct_test_current_user',
                'adct_test_wp_caps',
                'adct_test_nonce_fields',
            ] as $key) {
                $this->globalsBackup[$key] = $GLOBALS[$key] ?? null;
                unset($GLOBALS[$key]);
            }

            // Both Approval-namespace current_user_can() copies read these globals --
            // this file's flat list and the shared per-user table -- so which one
            // happens to be loaded under executionOrder="random" cannot change the
            // answer.
            $GLOBALS['adct_front_caps'] = [Capabilities::APPROVE_DEANERY];
            $GLOBALS['adct_front_user'] = new \WP_User(11, 'dean@example.test');
            $GLOBALS['adct_test_current_user_id'] = 11;
            $GLOBALS['adct_test_current_user'] = $GLOBALS['adct_front_user'];
            $GLOBALS['adct_test_wp_caps'] = [11 => [Capabilities::APPROVE_DEANERY]];
            $GLOBALS['adct_test_nonce_fields'] = [];

            $_GET = [];
            $_POST = [];
        }

        protected function tearDown(): void
        {
            foreach ($this->globalsBackup as $key => $value) {
                if ($value === null) {
                    unset($GLOBALS[$key]);
                } else {
                    $GLOBALS[$key] = $value;
                }
            }
            $this->globalsBackup = [];

            $_GET = [];
            $_POST = [];
            $_REQUEST = [];

            parent::tearDown();
        }

        public function testTheEditorExplainsTheUnreadableDateAndAsksForAcknowledgement(): void
        {
            $html = $this->renderEditor($this->unreadableDateRow());

            self::assertStringContainsString(
                'The notice gives',
                $html,
                'The dean must be told a phrase was found, not left to find an empty date.'
            );
            self::assertStringContainsString('could not be read', $html);
            self::assertStringContainsString(
                ReviewQueuePage::UNPARSED_DATE_FIELD,
                $html,
                'The acknowledgement control must be on the form.'
            );
        }

        /**
         * The phrase is quoted so the dean can recognise it in the bulletin, and it
         * is bounded and escaped so a bulletin line cannot carry markup onto a page
         * the public can reach.
         */
        public function testTheQuotedPhraseIsEscapedAndBounded(): void
        {
            $row = $this->unreadableDateRow();
            $row['fields'] = json_encode(
                ['title' => 'Retreat', 'event_time' => '10:00'],
                JSON_THROW_ON_ERROR
            );
            $row['notes'] = json_encode([
                UnparsedDateTimeCandidate::DATE_REASON
                    . ':<script>alert(1)</script> ' . str_repeat('x', 80),
            ], JSON_THROW_ON_ERROR);

            $html = $this->renderEditor($row);

            self::assertStringNotContainsString('<script>', $html);
            self::assertStringContainsString('&lt;script&gt;', $html);
            self::assertStringNotContainsString(
                str_repeat('x', 41),
                $html,
                'The phrase is bounded, so a long bulletin line cannot flood the form.'
            );
        }

        /**
         * The whole point. A control outside the form element renders, looks right
         * and submits nothing, so the approval is refused for ever with no visible
         * cause -- exactly the silence #167 reports.
         */
        public function testTheAcknowledgementCheckboxIsInsideTheFormThatApproves(): void
        {
            $html = $this->renderEditor($this->unreadableDateRow());

            $formStart = strpos($html, '<form');
            $formEnd = strpos($html, '</form>');
            $checkbox = strpos($html, 'name="' . ReviewQueuePage::UNPARSED_DATE_FIELD . '"');

            self::assertNotFalse($formStart, 'The editor must be a form.');
            self::assertNotFalse($formEnd, 'The editor must be closed.');
            self::assertNotFalse($checkbox, 'The acknowledgement checkbox must exist.');
            self::assertGreaterThan($formStart, $formEnd);
            self::assertGreaterThan(
                $formStart,
                $checkbox,
                'An acknowledgement rendered outside the form can never be submitted.'
            );
            self::assertLessThan($formEnd, $checkbox);
        }

        public function testTheApproveButtonStaysOffered(): void
        {
            $html = $this->renderEditor($this->unreadableDateRow());

            self::assertStringContainsString('Save and approve', $html);
            self::assertStringNotContainsString(
                'needs manual resolution before it can be approved',
                $html,
                'An unreadable date is not a match-resolution problem.'
            );
        }

        public function testTheQueueRowFlagsTheUnreadableDate(): void
        {
            $this->database->rows = [$this->unreadableDateRow()];

            $html = $this->renderQueue();

            self::assertStringContainsString('could not be read', $html);
            self::assertStringContainsString('Approve selected', $html);
        }

        /**
         * A candidate whose date parsed cleanly carries no warning at all, or the
         * notice is noise that teaches a dean to skip it.
         */
        public function testAReadableDateCarriesNoWarning(): void
        {
            $html = $this->renderEditor($this->readableRow());

            self::assertStringNotContainsString(ReviewQueuePage::UNPARSED_DATE_FIELD, $html);
            self::assertStringNotContainsString('could not be read', $html);
        }

        /**
         * An unreadable time is recoverable -- the publisher makes the event all-day
         * -- so it is surfaced but not acknowledged. Asking a dean to tick a box
         * about a value they cannot act on only teaches them to tick boxes.
         */
        public function testAnUnreadableTimeIsSaidButNotAskedAbout(): void
        {
            $row = $this->readableRow();
            $row['notes'] = json_encode(
                [UnparsedDateTimeCandidate::TIME_REASON . ':ten past nine'],
                JSON_THROW_ON_ERROR
            );

            $html = $this->renderEditor($row);

            self::assertStringContainsString('could not be read', $html);
            self::assertStringNotContainsString(
                ReviewQueuePage::UNPARSED_DATE_FIELD,
                $html,
                'A time is corrected by asking the parish, not by an acknowledgement.'
            );
        }

        /**
         * The deadlock, and why the form is read before the gate.
         *
         * decide() re-reads the stored row, and the stored row still carries the
         * note -- saving a corrected date updates `fields` and leaves `notes` alone.
         * A gate that looked only at the stored row would refuse an approval whose
         * correction had just been saved. Entering a date that differs from the
         * stored one is itself the acknowledgement, and it has to reach decide().
         */
        public function testEnteringTheCorrectedDateAnswersTheGateAndReachesTheDecision(): void
        {
            $this->database->rows = [$this->unreadableDateRow()];
            $_POST = $this->approvePost(['event_date' => '12/10/2026']);

            $redirect = $this->redirectFromSave();

            self::assertStringContainsString(
                'decision=decided',
                $redirect,
                'A corrected date must reach the decision rather than dead-ending.'
            );
            self::assertNotSame(
                [],
                $this->approvals(),
                'The approval itself must reach the database.'
            );
            self::assertFalse(
                $this->reRenderedInPlace(),
                'The save must be decided, not re-rendered with validator errors.'
            );
        }

        /**
         * The box does what the checkbox says: it is a way of approving when the
         * date on file is the date the reviewer has read and chosen to accept.
         *
         * The stored date stays on the candidate -- `updateFields()` writes the
         * form's own date, so ticking the box approves the date the reviewer is
         * looking at rather than blanking it. An approval that produced an event
         * with no date at all would be worse than the silence #167 is fixing, and
         * `CandidateEditValidator` refuses that before any decision is taken.
         */
        public function testTickingTheBoxApprovesWithTheDateTheReviewerHasRead(): void
        {
            $row = $this->unreadableDateRow();
            $row['fields'] = json_encode(
                ['title' => 'Retreat', 'event_date' => '2026-10-12'],
                JSON_THROW_ON_ERROR
            );
            $this->database->rows = [$row];
            $_POST = $this->approvePost([
                'event_date' => '12/10/2026',
                ReviewQueuePage::UNPARSED_DATE_FIELD => '1',
            ]);

            $redirect = $this->redirectFromSave();

            self::assertStringContainsString('decision=decided', $redirect);
            self::assertNotSame([], $this->approvals());
        }

        /**
         * The half of #167 that keeps it from blocking indefinitely.
         *
         * The stored row still carries the note, so without the box this save is
         * refused with 409 and writes nothing. That is the intended shape: the
         * approver is told what could not be read, and cannot approve past it
         * silently. The way out is the date field, or the box -- both of which the
         * test above drives.
         */
        public function testAnApprovalWithNeitherAnswerIsRefusedAndWritesNothing(): void
        {
            $this->database->rows = [$this->unreadableDateRow()];
            $_POST = $this->approvePost(['event_date' => '']);

            try {
                $this->redirectFromSave();
                self::fail('An approval with no date and no acknowledgement must be refused.');
            } catch (\AdctTestWpDie $refused) {
                self::assertSame(409, $refused->status);
            }

            self::assertSame(
                [],
                $this->approvals(),
                'A refused approval must not stamp an approver.'
            );
        }

        /**
         * The acknowledgement rule read directly, so the gate is pinned where it is
         * decided rather than only through the handler.
         *
         * The form arrives through `readEditForm()`, which sanitises the same way the
         * POST does, so this drives that method rather than passing raw `$_POST`.
         */
        public function testTheAcknowledgementRuleIsTheOneTheRepositoryDocuments(): void
        {
            $queue = $this->queue();
            $read = new ReflectionMethod($queue, 'readEditForm');
            $rule = new ReflectionMethod($queue, 'hasAcknowledgedUnparsedDate');

            $unreadable = $this->unreadableDateRow();
            $readable = $this->readableRow();

            self::assertFalse(
                $rule->invoke($queue, $read->invoke($queue, []), $unreadable),
                'Nothing posted is no acknowledgement.'
            );

            self::assertTrue(
                $rule->invoke($queue, $read->invoke($queue, ['event_date' => '12/10/2026']), $unreadable),
                'Entering a date where there was none is the acknowledgement.'
            );

            self::assertFalse(
                $rule->invoke($queue, $read->invoke($queue, ['event_date' => '12/10/2026']), $readable),
                'A readable date raises no acknowledgement at all.'
            );
        }

        /**
         * `notes` is a JSON column and a row that never went through the parser can
         * hold anything, so a corrupt blob must not take the screen down.
         */
        public function testACorruptNotesBlobDoesNotBreakTheEditor(): void
        {
            $row = $this->readableRow();
            $row['notes'] = 'not json at all';

            $html = $this->renderEditor($row);

            self::assertStringContainsString('Save and approve', $html);
            self::assertStringNotContainsString('could not be read', $html);
        }

        /**
         * @param array<string, string> $overrides
         * @return array<string, string>
         */
        private function approvePost(array $overrides): array
        {
            return array_merge([
                'action' => FrontEndApprovalQueue::SAVE_ACTION,
                'candidate_id' => '21',
                'save_mode' => 'approve',
                'title' => 'Retreat',
                'event_time' => '10:00',
                'description' => '',
                FrontEndApprovalQueue::SAVE_NONCE => 'nonce-for-' . FrontEndApprovalQueue::SAVE_ACTION,
            ], $overrides);
        }

        /** @return list<string> */
        private function approvals(): array
        {
            return array_values(array_filter(
                $this->database->writes,
                static fn (string $write): bool => str_contains($write, 'approved_by = ')
            ));
        }

        private function queue(?CandidatePublisherPort $publisher = null): FrontEndApprovalQueue
        {
            return new FrontEndApprovalQueue(
                new ReviewQueueRepository($this->database, new DeanQueueFixedClock()),
                $publisher ?? new CandidatePublisher(
                    new DeanQueueNullStore(),
                    new EventValidator(new DateTimeZone('Africa/Johannesburg'))
                ),
                new ReviewQueuePolicy()
            );
        }

        /** @param array<string, mixed> $row */
        private function renderEditor(array $row): string
        {
            $this->database->rows = [$row];
            $_GET = ['adct_pi_edit' => '21'];

            return $this->renderQueue();
        }

        private function renderQueue(): string
        {
            ob_start();

            try {
                $html = $this->queue()->render();
            } finally {
                ob_end_clean();
            }

            return $html;
        }

        /**
         * Where the redirect is caught rather than rethrown.
         *
         * The redirect stub is declared inside `FrontEndApprovalQueueTest`, so
         * this file aliases its own throwable onto the same global name and both
         * catch the same object. `ob_end_clean()` in the handler closes the
         * buffer; matching it here keeps the test from leaving one open.
         */
        private function redirectFromSave(): string
        {
            $this->reRendered = false;
            ob_start();

            try {
                $this->queue()->handleSave();
            } catch (\AdctTestWpDie $refusal) {
                ob_end_clean();

                throw $refusal;
            } catch (\FrontQueueRedirected $redirected) {
                ob_end_clean();

                return $redirected->getMessage();
            }
            // No redirect and no refusal: the handler echoed the editor back with
            // the validator's errors attached. A decision was not taken.
            $this->reRendered = true;
            ob_end_clean();

            self::fail('A save must either redirect or be refused.');
        }

        /**
         * A form whose own fields do not validate re-renders the editor with the
         * problems attached and returns, rather than redirecting. Recording that
         * here is what keeps "returned quietly" from reading as "decided": these
         * tests assert on the redirect, and a silent return used to look like one.
         */
        private function reRenderedInPlace(): bool
        {
            return $this->reRendered;
        }

        /** @return array<string, mixed> */
        private function readableRow(): array
        {
            return [
                'id' => 21,
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
                'fields' => json_encode(
                    ['title' => 'Retreat', 'event_date' => '12/10/2026', 'event_time' => '10:00'],
                    JSON_THROW_ON_ERROR
                ),
                'notes' => '[]',
            ];
        }

        /**
         * Exactly what the parser stores when it sees `32 October 2026` and cannot
         * resolve it: no date in `fields`, and the machine-readable reason in `notes`.
         *
         * @return array<string, mixed>
         */
        private function unreadableDateRow(): array
        {
            $row = $this->readableRow();
            $row['fields'] = json_encode(
                ['title' => 'Retreat', 'event_time' => '10:00'],
                JSON_THROW_ON_ERROR
            );
            $row['notes'] = json_encode(
                [UnparsedDateTimeCandidate::DATE_REASON . ':32 October 2026'],
                JSON_THROW_ON_ERROR
            );

            return $row;
        }
    }

    final class DeanQueueFixedClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable(
                '2026-10-12 09:00:00',
                new DateTimeZone('Africa/Johannesburg')
            );
        }
    }

    /**
     * The redirect is caught, not defined here: the production
     * `wp_safe_redirect()` stub lives in `FrontEndApprovalQueueTest` and throws
     * that file's `FrontQueueRedirected`. A second throwable here would compile,
     * never match, and let a silent re-render read as a redirect -- which is
     * exactly the confusion #167 exists to remove.
     */

    /**
     * Records the SQL instead of running it, so the scope assertions read the query
     * text. prepare() interpolates bound values the way $wpdb does.
     *
     * `query()` answers by the shape of the statement, because these tests assert on
     * which write actually landed: `UPDATE ... SET approved_by` is the approval, an
     * `UPDATE ... SET fields` is the correction, and anything else affecting the
     * candidates table counts as the row having moved out from under us.
     */
    final class DeanQueueRecordingDatabase implements DatabaseConnectionInterface
    {
        /** @var list<array<string, mixed>> */
        public array $rows = [];

        /** @var list<string> */
        public array $queries = [];

        /** @var list<string> */
        public array $writes = [];

        /** @var list<array<string, mixed>> */
        public array $countRows = [];

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

                return 1;
            }

            // A transaction statement never reports an error, whatever it is.
            return preg_match('/\A(START TRANSACTION|COMMIT|ROLLBACK)\z/', $query) === 1 ? 0 : 1;
        }
        public function getRow(string $query): ?array
        {
            return $this->getResults($query)[0] ?? null;
        }

        public function getResults(string $query): array
        {
            $this->queries[] = $query;

            if (str_contains($query, 'adct_pi_event_changes')) {
                return [];
            }

            // counts() groups by category and reads the `category` column it selected,
            // so handing it a candidate row would raise an undefined key. Giving it
            // the aggregate row it asked for keeps the collaborator real without
            // pretending a GROUP BY returned items.
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
     * Publication is never reached from render(), so an empty store is enough --
     * and it makes an accidental publish during a read fail loudly rather than
     * passing quietly.
     */
    final class DeanQueueNullStore implements PublicationStoreInterface
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