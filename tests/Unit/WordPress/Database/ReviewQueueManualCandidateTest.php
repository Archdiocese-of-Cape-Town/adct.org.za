<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Issue #63: a poster the parser cannot read has to be enterable by hand.
 *
 * A reviewer creates an empty candidate beside the poster, then types the event
 * into the ordinary edit form and approves it there. Nothing about that route
 * may become a way around approval, so what is pinned here is the row itself:
 * blank fields, no approver recorded, and a status only the approval queue
 * accepts. The publisher's own refusal of such a row is proved in
 * CandidatePublisherTest, next to the allow-list that decides it.
 *
 * No reflection is used anywhere: ReflectionMethod::setAccessible() is
 * deprecated in PHP 8.5 and would fail the advisory CI job.
 */
final class ReviewQueueManualCandidateTest extends TestCase
{
    private const SOURCE_CANDIDATE_ID = 7;

    private const MESSAGE_ID = 42;

    private const PARISH_ID = 3;

    /** 09:00 Africa/Johannesburg is 07:00 UTC; every timestamp written here is UTC. */
    private const CREATED_AT = '2026-03-01 07:00:00';

    private const CANDIDATES = 'adct_pi_event_candidates';

    private const AUDIT = 'adct_pi_audit_log';

    /**
     * A manual candidate is stored awaiting approval with nothing filled in and
     * no approver recorded. That is the safety property in one place: the row
     * reaches the approval queue and nowhere else.
     */
    public function testAManualCandidateIsWrittenAwaitingApprovalWithNothingRecorded(): void
    {
        $database = new ManualCandidateDatabase();
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        $id = $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'dean@example.test', false);

        self::assertSame(ManualCandidateDatabase::NEW_ID, $id, 'The new candidate id comes from the insert.');
        $insert = $database->insert(self::CANDIDATES);
        self::assertStringStartsWith(
            'INSERT INTO `wp_' . self::CANDIDATES . '`',
            $insert['query'],
            'A manual candidate is a single insert into the candidate table.'
        );
        $columns = $this->columns($insert['query']);
        self::assertArrayNotHasKey(
            'approved_by',
            $columns,
            'A blank candidate must not carry an approver, so nothing is written into it.'
        );
        self::assertArrayNotHasKey('approved_via', $columns);
        self::assertArrayNotHasKey('approved_at', $columns);
        self::assertArrayNotHasKey('decided_by', $columns);
        self::assertArrayNotHasKey('confirmed_by', $columns);

        self::assertSame([
            self::MESSAGE_ID,
            1,
            self::PARISH_ID,
            '{}',
            0.0,
            '',
            '["manual_entry"]',
            'new',
            'awaiting_approval',
            self::CREATED_AT,
            self::CREATED_AT,
        ], $insert['arguments'], 'The blank candidate row changed: ' . $insert['query']);
    }

    /**
     * The parish and the message come from the candidate the reviewer was
     * already looking at, never from the request, so the typed event reaches the
     * same dean or reviewer a parsed one would. The read happens inside the
     * transaction, because that row can move between the page being rendered and
     * the button being pressed.
     */
    public function testTheParishAndMessageComeFromTheSourceCandidateNotTheRequest(): void
    {
        $database = new ManualCandidateDatabase();
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'dean@example.test', false);

        $insert = $database->insert(self::CANDIDATES);
        self::assertSame(self::MESSAGE_ID, $insert['arguments'][0], 'The message is the source row message.');
        self::assertSame(self::PARISH_ID, $insert['arguments'][2], 'The parish is the source row parish.');
        self::assertSame(
            [self::SOURCE_CANDIDATE_ID],
            $database->preparedQueries[0]['arguments'],
            'The source candidate is the only thing the caller names: ' . $database->preparedQueries[0]['query']
        );
        self::assertStringContainsString(
            'FOR UPDATE',
            $database->preparedQueries[0]['query'],
            'The source candidate is read under a lock.'
        );
    }

    /**
     * The blank fields must be a JSON object, not an array and not an empty
     * string: ReviewQueuePolicy::fields() throws on anything json_decode() does
     * not turn into a stdClass, and a throwing policy takes the detail screen
     * down for a candidate that is blank only for a moment.
     */
    public function testTheBlankManualCandidateStillDecodesAsAFieldsObject(): void
    {
        $database = new ManualCandidateDatabase();
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        $id = $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'dean@example.test', false);
        $arguments = $database->insert(self::CANDIDATES)['arguments'];
        $row = $this->manualCandidateRow([
            'id' => (string) $id,
            'parish_id' => (string) $arguments[2],
            'fields' => (string) $arguments[3],
        ]);
        $policy = new ReviewQueuePolicy();

        self::assertSame([], $policy->fields($row), 'A blank candidate has no fields yet, and that is not an error.');
        self::assertTrue($policy->canDecide($row), 'A blank manual candidate still awaits a decision.');
    }

    /**
     * `notes` is what the warnings renderer reads to explain a candidate to a
     * person. A manual candidate has to say it was typed in, and it must avoid
     * the parser markers: `unknown_sender` in particular is what files a
     * candidate under Unknown senders, which reads as "this needs a parish
     * lookup" rather than "a person filled this in".
     */
    public function testTheManualMarkerExplainsTheRowWithoutBorrowingAParserMarker(): void
    {
        $database = new ManualCandidateDatabase();
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        $id = $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'dean@example.test', false);
        $insert = $database->insert(self::CANDIDATES);
        $columns = $this->columns($insert['query']);

        // Read the marker back off the row the insert actually produced, rather
        // than off a literal repeated here, so this cannot pass by drifting from
        // the code it is meant to pin.
        $stored = (string) $insert['arguments'][$columns['notes']];
        $notes = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);

        self::assertIsArray($notes);
        self::assertSame(['manual_entry'], $notes);
        foreach ($notes as $note) {
            self::assertIsString($note);
            self::assertStringNotContainsString('unknown_sender', $note);
            self::assertStringNotContainsString('manual_review_required', $note);
        }

        // And the marker has to survive the queue's own view of the row, because
        // that is what decides which tab the candidate appears under.
        $policy = new ReviewQueuePolicy();
        $row = $this->manualCandidateRow(['id' => (string) $id, 'notes' => $stored]);
        self::assertFalse(
            $policy->requiresMatchResolution($row),
            'A typed-in event must not look like it needs a match resolved.'
        );
    }

    /**
     * `block_index` is unique per message, so a second manual candidate on the
     * same email has to land after the blocks already there. The read that
     * decides this is locked FOR UPDATE, matching the parser's own precedent.
     */
    public function testTheBlockIndexIsTheNextFreeOneOnTheMessage(): void
    {
        $database = new ManualCandidateDatabase();
        $database->blocks = [['block_index' => '0'], ['block_index' => '1'], ['block_index' => '4']];
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'dean@example.test', false);

        self::assertSame(5, $database->insert(self::CANDIDATES)['arguments'][1]);
        $lock = $database->preparedQueries[1];
        self::assertStringContainsString('block_index', $lock['query']);
        self::assertStringContainsString('FOR UPDATE', $lock['query'], 'The next free block index is read under a lock.');
        self::assertSame([self::MESSAGE_ID], $lock['arguments']);
    }

    /** A message with no candidates yet starts at block zero. */
    public function testTheFirstManualCandidateOnAMessageTakesBlockZero(): void
    {
        $database = new ManualCandidateDatabase();
        $database->blocks = [];
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'dean@example.test', false);

        self::assertSame(0, $database->insert(self::CANDIDATES)['arguments'][1]);
    }

    /**
     * The whole write is one transaction. A rollback has to happen, or an orphan
     * candidate sits in the approval queue with no audit trail behind it.
     */
    public function testAFailedInsertRollsBackAndSaysSo(): void
    {
        $database = new ManualCandidateDatabase();
        $database->failInserts = true;
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        try {
            $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'dean@example.test', false);
            self::fail('A candidate that could not be inserted must not be reported as created.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('could not be saved', $failure->getMessage());
        }

        self::assertContains('ROLLBACK', $database->executedQueries);
        self::assertNotContains('COMMIT', $database->executedQueries);
        self::assertSame(
            [],
            $database->inserts(self::AUDIT),
            'A candidate that was never written is not audited as though it was.'
        );
    }

    /**
     * A source candidate whose row has since gone cannot be anchored to a
     * message, so it is refused rather than written as an orphan.
     */
    public function testAMissingSourceCandidateIsRefused(): void
    {
        $database = new ManualCandidateDatabase();
        $database->source = null;
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        $this->expectException(RuntimeException::class);

        try {
            $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'dean@example.test', false);
        } finally {
            self::assertNotContains('COMMIT', $database->executedQueries);
            self::assertSame([], $database->inserts(self::CANDIDATES));
        }
    }

    /** A candidate that has not been saved has no poster to type an event from. */
    public function testASourceCandidateIdIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReviewQueueRepository(new ManualCandidateDatabase(), new ManualCandidateClock()))
            ->createManualCandidate(0, 'dean@example.test', false);
    }

    /**
     * The creation is audited against the candidate that now exists, by the
     * person who made it and the role they held — the same shape as every other
     * review audit entry, so the history reads uniformly.
     */
    public function testTheCreationIsAuditedAgainstTheNewCandidate(): void
    {
        $database = new ManualCandidateDatabase();
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        $id = $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'reviewer@example.test', true, 19);

        self::assertSame([
            'reviewer@example.test',
            'candidate_created_by_hand',
            'event_candidate',
            $id,
            json_encode([
                'role' => 'reviewer',
                'source_candidate_id' => self::SOURCE_CANDIDATE_ID,
                'attachment_id' => 19,
            ], JSON_THROW_ON_ERROR),
            self::CREATED_AT,
            self::CREATED_AT,
        ], $database->insert(self::AUDIT)['arguments'], 'The audit row changed.');
    }

    /** A dean is recorded as a dean, exactly as the other review entries do. */
    public function testTheRecordedRoleIsTheOneTheCallerActuallyHeld(): void
    {
        $database = new ManualCandidateDatabase();
        $queue = new ReviewQueueRepository($database, new ManualCandidateClock());

        $queue->createManualCandidate(self::SOURCE_CANDIDATE_ID, 'dean@example.test', false);

        $payload = json_decode(
            (string) $database->insert(self::AUDIT)['arguments'][4],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('dean', $payload['role']);
        self::assertArrayNotHasKey('attachment_id', $payload, 'No poster was named, so none is claimed.');
    }

    /**
     * The insert names its columns, so an expectation can be written against a
     * column rather than a position that only means something inside one call.
     *
     * @return array<string, int>
     */
    private function columns(string $query): array
    {
        if (preg_match('/\(([^()]*)\)\s*VALUES/', $query, $match) !== 1) {
            self::fail('The manual candidate insert has no column list: ' . $query);
        }
        $columns = [];
        foreach (explode(',', $match[1]) as $position => $name) {
            $columns[trim($name, " `\t\n\r")] = $position;
        }

        return $columns;
    }

    /**
     * The candidate the manual route produces, as the queue and the publisher
     * see it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function manualCandidateRow(array $overrides = []): array
    {
        return array_replace([
            'id' => (string) ManualCandidateDatabase::NEW_ID,
            'message_id' => (string) self::MESSAGE_ID,
            'parish_id' => null,
            'status' => 'awaiting_approval',
            'match_kind' => 'new',
            'match_event_id' => null,
            'fields' => '{}',
            'recurrence' => null,
            'notes' => '["manual_entry"]',
            'approved_by' => null,
            'approved_at' => null,
            'approved_via' => null,
            'decided_by' => null,
            'decided_at' => null,
        ], $overrides);
    }
}

/**
 * A clock frozen away from every rolling window, so nothing in these
 * expectations depends on when the suite runs.
 */
final class ManualCandidateClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-03-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
    }
}

/**
 * Records the statements the repository really issues, and answers the two
 * locked reads the manual route depends on: the source candidate, and the blocks
 * its message already uses.
 */
final class ManualCandidateDatabase implements DatabaseConnectionInterface
{
    public const NEW_ID = 91;

    public const SOURCE_ID = 7;

    public const MESSAGE_ID = 42;

    public const PARISH_ID = 3;

    /** @var list<array{query: string, arguments: array<int, mixed>}> */
    public array $preparedQueries = [];

    /** @var list<string> */
    public array $executedQueries = [];

    /** @var list<array<string, mixed>> */
    public array $blocks = [['block_index' => '0']];

    /** @var array<string, mixed>|null */
    public ?array $source;

    public bool $failInserts = false;

    private string $lastError = '';

    public function __construct()
    {
        $this->source = [
            'id' => (string) self::SOURCE_ID,
            'message_id' => (string) self::MESSAGE_ID,
            'parish_id' => (string) self::PARISH_ID,
        ];
    }

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->preparedQueries[] = ['query' => $query, 'arguments' => $arguments];

        return $query;
    }

    public function query(string $query): int|false
    {
        $this->executedQueries[] = $query;
        // An error belongs to the statement that raised it and is cleared before
        // the next one runs, exactly as wpdb does. Leaving it set would make the
        // rollback look like a second failure.
        $fails = $this->failInserts && str_starts_with($query, 'INSERT');
        $this->lastError = $fails ? 'Duplicate entry' : '';

        return $fails ? false : 1;
    }

    public function getRow(string $query): ?array
    {
        return $this->source;
    }

    /** @return array<int, array<string, mixed>> */
    public function getResults(string $query): array
    {
        return $this->blocks;
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
     * Every insert into one table, exactly as the repository built them.
     *
     * @return list<array{query: string, arguments: array<int, mixed>}>
     */
    public function inserts(string $table): array
    {
        $prefix = 'INSERT INTO `wp_' . $table . '`';

        return array_values(array_filter(
            $this->preparedQueries,
            static fn (array $prepared): bool => str_starts_with($prepared['query'], $prefix)
        ));
    }

    /**
     * The insert into one table, exactly as the repository built it.
     *
     * @return array{query: string, arguments: array<int, mixed>}
     */
    public function insert(string $table): array
    {
        $inserts = $this->inserts($table);
        if ($inserts === []) {
            self::fail('Nothing was inserted into ' . $table . '.');
        }

        return $inserts[0];
    }
}
