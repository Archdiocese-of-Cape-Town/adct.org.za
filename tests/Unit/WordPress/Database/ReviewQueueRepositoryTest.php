<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Issue #59 requires that every non-final candidate appears in exactly one
 * review queue tab.
 *
 * The tab is chosen by the SQL CASE expression in ReviewQueueRepository::category(),
 * so this test proves the partition without a database. It takes the expression the
 * repository really sends to MySQL, reads its branches, decides each branch against
 * a synthetic candidate row with a small fail-fast interpreter, and compares the
 * result with an oracle written out by hand from the documented queue. Two
 * independent derivations have to agree.
 *
 * Nothing here uses reflection. ReflectionMethod::setAccessible() is deprecated in
 * PHP 8.5 and fails the advisory CI job, and it has been a no-op since PHP 8.1
 * anyway; everything below goes through the public counts() and find() surface.
 *
 * There is no CandidateStatus enum in this codebase: SchemaDefinitions declares
 * status as a free-form varchar(32) with no CHECK constraint, so the status list
 * below is derived from the data model, and the test additionally probes a status
 * that no branch has ever heard of.
 */
final class ReviewQueueRepositoryTest extends TestCase
{
    /**
     * Every candidate status the schema and the data model describe.
     *
     * @var list<string>
     */
    private const CANDIDATE_STATUSES = [
        'draft',
        'awaiting_submitter',
        'awaiting_approval',
        'approved',
        'published',
        'rejected',
        'expired',
        'duplicate',
        'superseded',
        'failed',
    ];

    /** A status no branch names, proving the terminal ELSE keeps the queue exhaustive. */
    private const UNRECOGNISED_STATUS = 'awaiting_reviewer';

    /** The label the terminal ELSE assigns. */
    private const FALLBACK_LABEL = 'failed';

    /**
     * The one category label that is not a tab key: the primary approval
     * category. counts() reports it as `primary_approval` and find() reaches it
     * through the overlapping `awaiting_approval` tab.
     */
    private const PRIMARY_APPROVAL_LABEL = 'approval';

    /** Tabs that are not categories: the placeholder and the overlapping view. */
    private const NON_CATEGORY_TABS = ['awaiting_approval', 'recent_changes'];

    /** Categories that find() selects with a single equality filter. */
    private const DISJOINT_TABS = [
        'unknown_senders',
        'low_confidence',
        'failed',
        'awaiting_submitter',
        'recently_published',
        'recently_decided',
    ];

    /**
     * Documented statuses that the classification is allowed never to name, because
     * ReviewQueueRepository::where() keeps them out of the queue entirely. Neither is
     * final: a duplicate stays in the queue while a human checks whether it really is
     * one, and a superseded candidate is the earlier of a pair the submitter replaced.
     */
    private const SCOPED_ELSEWHERE = ['duplicate', 'superseded'];

    /**
     * One known contact: parish 4 wrote from parish@example.test and is verified.
     * Every other sender in the matrix is unverified by definition.
     *
     * @var list<array{parish_id: int|null, email: string|null, trust: string}>
     */
    private array $contacts = [['parish_id' => 4, 'email' => 'parish@example.test', 'trust' => 'verified']];

    public function testEveryQueueQueryClassifiesCandidatesWithOneIdenticalExpression(): void
    {
        $database = new ReviewQueueRecordingDatabase();
        $queue = $this->repository($database);
        $expressions = [];

        $expressions[] = $this->recordedCategory($database, static fn (): array => $queue->counts(
            7,
            'reviewer@example.test',
            true,
            'parish'
        ));
        foreach (array_keys(ReviewQueueRepository::TABS) as $tab) {
            if ($tab === 'recent_changes') {
                continue; // The placeholder answers without touching the database (issue #71).
            }
            $expressions[] = $this->recordedCategory($database, static fn (): array => $queue->find(
                $tab,
                7,
                'reviewer@example.test',
                true,
                'parish',
                25,
                0
            ));
        }

        self::assertCount(count(ReviewQueueRepository::TABS), $expressions);
        self::assertNotSame('', $expressions[0]);
        self::assertCount(
            1,
            array_unique($expressions),
            'Every queue query must classify candidates with one and the same expression, otherwise a '
            . 'tab and its badge count could disagree about which candidates are queued.'
        );
    }

    public function testTheCategoryIsAFirstMatchCaseWithATerminalElse(): void
    {
        $expression = $this->category();

        self::assertStringStartsWith('CASE WHEN ', $expression);
        self::assertStringEndsWith(' END', $expression);
        self::assertSame(
            1,
            preg_match("/ ELSE '([a-z_]+)' END\z/D", $expression, $terminal),
            'The category expression must close with exactly one terminal ELSE. Without it a candidate in '
            . 'a new status would fall out of every tab instead of landing in exactly one.'
        );
        self::assertSame(self::FALLBACK_LABEL, $terminal[1]);

        $parsed = $this->parse($expression);
        self::assertGreaterThan(1, count($parsed['branches']));
        self::assertSame(
            count($parsed['branches']),
            preg_match_all("/ THEN '[a-z_]+'/D", $expression),
            'Every branch must carry exactly one label.'
        );
    }

    public function testEveryCategoryLabelNamesAVisitableTab(): void
    {
        $database = new ReviewQueueRecordingDatabase();
        $queue = $this->repository($database);
        $parsed = $this->parse($this->category());
        $labels = $this->labels($parsed);

        self::assertContains(self::FALLBACK_LABEL, $labels);
        self::assertNotContains('recent_changes', $labels, 'Recent changes has its own feed (issue #71).');

        foreach (array_unique($labels) as $label) {
            $tab = $this->tabFor($label);
            self::assertArrayHasKey(
                $tab,
                ReviewQueueRepository::TABS,
                sprintf('The category %s is not rendered by the queue, so %s::TABS would have to gain it.', $label, ReviewQueueRepository::class)
            );
            self::assertNotSame(
                'recent_changes',
                $tab,
                sprintf('The category %s must map to a category tab, not to the recent changes feed.', $label)
            );
            self::assertContains(
                $tab,
                $this->tabsReachableAsACategory(),
                sprintf('The category %s is not reachable through any tab of the queue.', $label)
            );
            // find() throws for an unknown tab, so this proves the label is visitable.
            self::assertSame([], $queue->find($tab, 7, 'reviewer@example.test', true, '', 25, 0));
        }
    }

    public function testEveryBranchConditionIsReadableByTheInterpreter(): void
    {
        $parsed = $this->parse($this->category());
        $row = $this->matrix()[0]['row'];
        $branches = count($parsed['branches']);

        foreach ($parsed['branches'] as $branch) {
            self::assertIsBool($this->decide($branch['condition'], $row), sprintf(
                'The interpreter could not decide the branch labelled %s: %s',
                $branch['label'],
                $branch['condition']
            ));
        }

        self::assertGreaterThan(5, $branches);
    }

    public function testEveryCandidateStatusIsClaimedByABranchOrScopedOut(): void
    {
        $branches = $this->parse($this->category())['branches'];

        // Exhaustiveness: the branches between them test every status the data model
        // describes except the two that where() scopes out of the queue altogether, so
        // no documented state is left to the fallback because nobody thought of it.
        $exhausted = [];
        foreach ($branches as $branch) {
            $exhausted = array_merge($exhausted, $branch['statuses']);
        }
        self::assertSame(
            self::SCOPED_ELSEWHERE,
            array_values(array_diff(self::CANDIDATE_STATUSES, $exhausted)),
            sprintf(
                'A documented status is classified by neither a branch nor the fallback: %s',
                implode(', ', $exhausted)
            )
        );
        self::assertSame([], array_values(array_diff($exhausted, self::CANDIDATE_STATUSES)));

        // And no branch invents a status the schema never produces.
        foreach ($branches as $branch) {
            self::assertSame([], array_values(array_diff(
                $branch['statuses'],
                self::CANDIDATE_STATUSES
            )), sprintf('The branch labelled %s tests a status the schema never produces.', $branch['label']));
        }
    }

    /**
     * The CASE is evaluated first match wins, so its branches need not be disjoint:
     * ordering is what keeps a candidate in one tab, and the terminal ELSE is what keeps
     * it out of none. This drives the real classification over every documented row and
     * asserts the assignment is total and single valued, then checks it against a
     * hand written oracle derived from docs/data-model.md.
     */
    public function testEveryCandidateIsAssignedToExactlyOneTab(): void
        {
    $parsed = $this->parse($this->category());
    $branches = $parsed['branches'];
    $placements = [];
    $checked = 0;

    foreach ($this->scopedRows() as $name => $row) {
        $hits = [];
        foreach ($branches as $branch) {
            if ($this->decide($branch['condition'], $row)) {
                $hits[] = $branch['label'];
            }
        }

        $assigned = $hits === [] ? $parsed['fallback'] : $hits[0];
        ++$checked;

        // Total: the terminal ELSE means a row the branches do not recognise still
        // gets a tab instead of falling out of the queue entirely.
        self::assertNotSame('', $assigned, sprintf('%s was assigned no category at all.', $name));

        // Single valued: first match wins, and the label is one of the SQL labels, so
        // the row can satisfy only one of the equality filters find() applies.
        self::assertContains($assigned, $this->labels($parsed), sprintf(
            '%s was assigned %s, which no classification branch produces.',
            $name,
            $assigned
        ));

        // Correct: the SQL and the oracle were derived independently.
        self::assertSame($this->expected($row), $assigned, sprintf(
            '%s should be queued under %s.',
            $name,
            $this->expected($row)
        ));

        $placements[$name] = $assigned;
    }

    self::assertGreaterThan(20, $checked, 'The matrix has to be wide enough to be worth asserting on.');

    // Exactly one tab per candidate means exactly one placement per row, and every
    // rendered category tab is reachable from some documented state. The primary
    // approval category is reached through the overlapping awaiting approval tab, so
    // the tab each label resolves to has to be one of the tabs find() can filter on.
    $tabs = array_unique(array_map($this->tabFor(...), $placements));
    self::assertSame($checked, count($placements));
    self::assertSame([], array_values(array_diff(self::DISJOINT_TABS, $tabs)), sprintf(
        'A rendered tab has no documented candidate state behind it. Tabs in use: %s.',
        implode(', ', $tabs)
    ));
    self::assertSame([], array_values(array_diff($tabs, $this->tabsReachableAsACategory())), sprintf(
        'A candidate was queued under a tab find() cannot filter on: %s.',
        implode(', ', array_values(array_diff($tabs, $this->tabsReachableAsACategory())))
    ));
        }

    public function testCategoryTabsAreSelectedByASingleEqualityFilter(): void
    {
        $database = new ReviewQueueRecordingDatabase();
        $queue = $this->repository($database);
        $visitors = [];
        $category = $this->category();

        foreach (array_keys(ReviewQueueRepository::TABS) as $tab) {
            if (in_array($tab, self::NON_CATEGORY_TABS, true)) {
                continue;
            }
            $queue->find($tab, 7, 'reviewer@example.test', true, '', 25, 0);
            $visitors[$tab] = $database->lastQuery();
        }

        self::assertNotSame([], $visitors);
        foreach ($visitors as $tab => $query) {
            // One occurrence for the SELECT list, one for the tab filter: the same expression.
            self::assertSame(2, substr_count($query, $category), sprintf(
                'The %s query must embed the category expression once for display and once as its filter.',
                $tab
            ));
            self::assertSame(1, preg_match_all('/ AS category, /D', $query), sprintf(
                'The %s query must select the category exactly once.',
                $tab
            ));
            Assert::assertStringContainsString(
                $category . " = '" . $tab . "'",
                $query,
                sprintf('The %s tab must be selected with a single equality filter.', $tab)
            );
            self::assertStringNotContainsString('CASE IS', $query, sprintf(
                'The %s tab must be selected with an equality filter, not a pattern match.',
                $tab
            ));
        }
    }

    public function testTheAwaitingApprovalTabIsAnOverlappingStatusScopedView(): void
    {
        $database = new ReviewQueueRecordingDatabase();
        $queue = $this->repository($database);

        $queue->find('awaiting_approval', 7, 'reviewer@example.test', true, '', 25, 0);
        $overlapping = $database->lastQuery();

        // It overlaps the primary approval category on purpose, so it is scoped by status
        // instead of by category and binds no category label.
        self::assertStringContainsString(" AND c.status = 'awaiting_approval'", $overlapping);
        self::assertStringNotContainsString($this->category() . ' = ', $overlapping);
        self::assertSame(1, preg_match_all('/ AS category, /D', $overlapping));
        self::assertSame(1, substr_count($overlapping, $this->category()), sprintf(
            'The overlapping tab selects the category for display but does not filter on it: %s',
            $overlapping
        ));

        // Every awaiting candidate is in this tab, whether or not a category tab also shows
        // it. That overlap is the one documented exception to "exactly one tab".
        $awaiting = array_filter(
            $this->matrix(),
            static fn (array $case): bool => $case['row']['status'] === 'awaiting_approval'
        );
        self::assertNotSame([], $awaiting);
        foreach ($awaiting as $case) {
            self::assertContains($case['expected'], [
                self::PRIMARY_APPROVAL_LABEL,
                'unknown_senders',
                'low_confidence',
                self::FALLBACK_LABEL,
            ], sprintf(
                '%s: an awaiting candidate belongs to the approval family, whatever its column state.',
                $case['name']
            ));
        }
        foreach ($this->matrix() as $case) {
            if ($case['row']['status'] === 'awaiting_approval') {
                continue;
            }
            self::assertNotContains($case['expected'], [
                self::PRIMARY_APPROVAL_LABEL,
                'unknown_senders',
                'low_confidence',
            ], sprintf('%s must not leak into the approval family.', $case['name']));
        }

        // Recent changes is still the placeholder for issue #71, so it is a tab with no
        // query behind it rather than a category nobody produces.
        $before = count($database->queries);
        self::assertSame([], $queue->find('recent_changes', 7, 'reviewer@example.test', true, '', 25, 0));
        self::assertCount($before, $database->queries, 'Recent changes must not run a query.');
        self::assertStringNotContainsString('recent_changes', $this->category(), sprintf(
            'Recent changes has to stay a placeholder until issue #71 lands: %s',
            $this->category()
        ));
    }

    public function testCountsPlacesEachCategoryExactlyOnceAndRefusesUnknownLabels(): void
    {
        $database = new ReviewQueueRecordingDatabase();
        $queue = $this->repository($database);

        $database->resultRows = [
            ['category' => 'approval', 'total' => '3', 'awaiting' => '3'],
            ['category' => 'low_confidence', 'total' => '1', 'awaiting' => '1'],
            ['category' => self::FALLBACK_LABEL, 'total' => '2', 'awaiting' => '0'],
        ];
        $counts = $queue->counts(7, 'reviewer@example.test', true, '');

        self::assertSame(3, $counts['primary_approval'], 'The approval category is reported under its own key.');
        self::assertSame(1, $counts['low_confidence']);
        self::assertSame(2, $counts[self::FALLBACK_LABEL]);
        self::assertSame(4, $counts['awaiting_approval'], 'Every awaiting candidate is counted once, in the overlap.');
        self::assertSame(0, $counts['recently_published']);
        self::assertSame(
            ['awaiting_approval', 'unknown_senders', 'low_confidence', 'failed', 'awaiting_submitter',
                'recently_published', 'recently_decided', 'recent_changes', 'primary_approval'],
            array_keys($counts),
            'Every tab must report a count, including the tabs that are not categories.'
        );

        $query = $database->lastQuery();
        self::assertSame(1, substr_count($query, $this->category()), 'The badge counts use the same expression.');
        self::assertStringContainsString('GROUP BY category', $query);

        $database->resultRows = [['category' => 'nowhere', 'total' => '1', 'awaiting' => '0']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A review queue category was not recognized.');
        $queue->counts(7, 'reviewer@example.test', true, '');
    }

    public function testTheConfidenceThresholdIsInterpolatedIntoTheClassification(): void
    {
        $expression = $this->categoryOf(0.9);

        self::assertStringContainsString('c.confidence < 0.900', $expression);
        self::assertStringNotContainsString('0.550', $expression);

        $database = new ReviewQueueRecordingDatabase();
        $queue = new ReviewQueueRepository(
            $database,
            new ReviewQueueFixedClock(),
            new ReviewQueuePolicy(),
            0.9
        );
        $queue->find('low_confidence', 7, 'reviewer@example.test', true, '', 25, 0);
        self::assertStringContainsString('c.confidence < 0.900', $database->lastQuery());
    }

    /**
     * Reads the exact expression the repository sends to the database.
     */
    private function category(): string
    {
        $database = new ReviewQueueRecordingDatabase();
        $this->repository($database)->counts(7, 'reviewer@example.test', true, '');

        self::assertSame(
            1,
            preg_match('/(CASE WHEN c\.status = \'published\'.*?ELSE \'[a-z_]+\' END) AS category,/Ds', $database->lastQuery(), $found),
            'Could not read the category expression out of the badge count query: ' . $database->lastQuery()
        );

        return $found[1];
    }

    private function categoryOf(float $threshold): string
    {
        $database = new ReviewQueueRecordingDatabase();
        $queue = new ReviewQueueRepository($database, new ReviewQueueFixedClock(), new ReviewQueuePolicy(), $threshold);
        $queue->counts(7, 'reviewer@example.test', true, '');

        self::assertSame(
            1,
            preg_match('/(CASE WHEN c\.status = \'published\'.*?ELSE \'[a-z_]+\' END) AS category,/Ds', $database->lastQuery(), $found)
        );

        return $found[1];
    }

    /**
     * The category expression the queries a call issues really send to MySQL. Every statement
     * in the call that selects a category has to select the identical expression, and every
     * statement has to select one at all.
     */
    private function recordedCategory(ReviewQueueRecordingDatabase $database, callable $run): string
        {
            $before = count($database->queries);
            $run();

            $queries = array_slice($database->queries, $before);
            self::assertNotSame([], $queries, 'The call did not reach the database.');
            $categories = [];
            foreach ($queries as $query) {
                if (preg_match('/(CASE WHEN c\.status = \'published\'.*?ELSE \'[a-z_]+\' END) AS category,/Ds', $query, $found) === 1) {
                    $categories[] = $found[1];
                }
            }
            self::assertSame(
                1,
                count(array_unique($categories)),
                'Every statement in one call must classify candidates with one identical expression: '
                . implode("\n", $queries)
            );
            self::assertCount(count($queries), $categories, 'The call selected no category expression.');

            return $categories[0];
        }

    private function repository(ReviewQueueRecordingDatabase $database): ReviewQueueRepository
    {
        return new ReviewQueueRepository($database, new ReviewQueueFixedClock());
    }

    /**
     * Splits the CASE expression into its branches and the terminal fallback label.
     *
     * @return array{branches: list<array{statuses: list<string>, condition: string, label: string}>, fallback: string}
     */
    private function parse(string $expression): array
    {
        self::assertSame(
            1,
            preg_match("/ELSE '([a-z_]+)' END\z/D", $expression, $terminal),
            'The category expression does not end with a single terminal ELSE: ' . $expression
        );
        $expression = (string) preg_replace("/\s*ELSE '[a-z_]+' END\z/D", '', $expression);
        $head = substr($expression, strlen('CASE WHEN '));

        $branches = [];
        $statuses = [];
        foreach ($this->splitTopLevel($head) as $part) {
            if (preg_match("/^(.*) THEN '([a-z_]+)'$/Ds", $part, $found) !== 1) {
                Assert::fail('Unrecognised classification branch: ' . $part);
            }
            $branches[] = [
                'statuses' => $this->statuses($found[1]),
                'condition' => trim($found[1]),
                'label' => $found[2],
            ];
            $statuses[] = $found[2];
        }

        return ['branches' => $branches, 'fallback' => $terminal[1]];
    }

    /** @param list<array{statuses: list<string>, condition: string, label: string}> $parsed */
    private function labels(array $parsed): array
    {
        return array_column($parsed['branches'], 'label');
    }

    /**
     * The statuses the branches test with a whole-candidate comparison, as opposed to the
     * literals a branch happens to mention inside a sender or confirmation check.
     *
     * @return list<string>
     */
    private function statuses(string $condition): array
    {
        $statuses = [];
        foreach ($this->splitTopLevel($condition, 'OR') as $disjunct) {
            foreach ($this->splitTopLevel($disjunct, 'AND') as $conjunct) {
                $conjunct = trim($conjunct);
                if (preg_match("/\A[cm]\.status = '([a-z_]+)'\z/D", $conjunct, $found) === 1) {
                    $statuses[] = $found[1];
                    continue;
                }
                if (preg_match("/\A[cm]\.status != '([a-z_]+)'\z/D", $conjunct, $found) === 1) {
                    continue;
                }
                if (preg_match("/\A[cm]\.status (?:NOT )?IN \(([^()]*)\)\z/D", $conjunct, $found) === 1) {
                    preg_match_all("/'([a-z_]+)'/D", $found[1], $values);
                    $statuses = array_merge($statuses, $values[1]);
                }
            }
        }

        return $statuses;
    }

    /**
     * Splits on a keyword that appears outside brackets, parentheses and string literals.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $subject, string $keyword = 'WHEN'): array
    {
        $parts = [];
        $depth = 0;
        $quote = '';
        $buffer = '';
        $length = strlen($subject);

        for ($i = 0; $i < $length; ++$i) {
            $character = $subject[$i];
            if ($quote !== '') {
                $buffer .= $character;
                if ($character === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $buffer .= $character;
                continue;
            }
            if ($character === '(') {
                ++$depth;
            } elseif ($character === ')') {
                --$depth;
            }
            if ($depth === 0 && str_starts_with(substr($subject, $i), ' ' . $keyword . ' ')) {
                $parts[] = trim($buffer);
                $buffer = '';
                $i += strlen($keyword) + 1;
                continue;
            }
            $buffer .= $character;
        }
        $parts[] = trim($buffer);

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Decides one branch condition against a synthetic candidate row.
     *
     * SQL binds AND tighter than OR, so the condition is split the way the
     * database reads it. Every shape the repository writes is understood;
     * anything else fails the test loudly instead of quietly returning a
     * plausible answer.
     *
     * @param array<string, mixed> $row
     */
    private function decide(string $condition, array $row): bool
    {
        $condition = trim($condition);

        $disjuncts = $this->splitTopLevel($condition, 'OR');
        if (count($disjuncts) > 1) {
            foreach ($disjuncts as $disjunct) {
                if ($this->decide($disjunct, $row)) {
                    return true;
                }
            }

            return false;
        }

        $conjuncts = $this->splitTopLevel($disjuncts[0], 'AND');
        if (count($conjuncts) > 1) {
            foreach ($conjuncts as $conjunct) {
                if (! $this->decide($conjunct, $row)) {
                    return false;
                }
            }

            return true;
        }

        $atom = trim($disjuncts[0]);
        if (str_starts_with($atom, '(') && $this->wrapsWholeExpression($atom)) {
            return $this->decide(substr($atom, 1, -1), $row);
        }

        return $this->atom($atom, $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function atom(string $atom, array $row): bool
    {
        if (str_starts_with($atom, 'c.status IN ')) {
            return in_array($row['status'], $this->stringList($atom), true);
        }
        if (str_starts_with($atom, 'c.status NOT IN ')) {
            return ! in_array($row['status'], $this->stringList($atom), true);
        }
        if (str_starts_with($atom, 'c.status = ')) {
            return $row['status'] === $this->quoted($atom, 'c.status = ');
        }
        if (str_starts_with($atom, 'c.status != ')) {
            return $row['status'] !== $this->quoted($atom, 'c.status != ');
        }
        if (str_starts_with($atom, 'm.confirmation_status IN ')) {
            return in_array($row['confirmation'], $this->stringList($atom), true);
        }
        if (str_starts_with($atom, 'c.confidence < ')) {
            return $row['confidence'] < (float) trim(substr($atom, strlen('c.confidence < ')));
        }
        if (str_starts_with($atom, 'CASE WHEN JSON_VALID(c.notes)')) {
            Assert::assertStringContainsString('JSON_CONTAINS(c.notes', $atom, 'Unrecognised notes condition.');
            Assert::assertSame(1, preg_match(
                '/\A[A-Za-z_ ().,\'"]*ELSE 0 END\z/D',
                $atom
            ), 'Unrecognised notes condition: ' . $atom);

            return $row['unknown_sender'];
        }
        if (str_starts_with($atom, 'NOT EXISTS (SELECT 1 FROM')) {
            Assert::assertStringContainsString("contact.trust = 'verified'", $atom, 'Unrecognised contact check.');
            Assert::assertStringContainsString('contact.parish_id = c.parish_id', $atom);
            Assert::assertStringContainsString('contact.email = m.sender_email', $atom);

            return ! in_array(
                ['parish_id' => $row['parish_id'], 'email' => $row['sender_email'], 'trust' => 'verified'],
                $this->contacts,
                true
            );
        }
        if (preg_match('/\A[cm]\.([a-z_]+) IS (NOT NULL|NULL)\z/D', $atom, $found) === 1) {
            Assert::assertArrayHasKey($found[1], $row, 'The matrix row is missing ' . $found[1] . '.');

            return ($row[$found[1]] !== null) === ($found[2] === 'NOT NULL');
        }

        Assert::fail('Unrecognised classification condition: ' . $atom);
    }

    /** True when the opening bracket of $atom closes only at its final character. */
    private function wrapsWholeExpression(string $atom): bool
    {
        $depth = 0;
        for ($i = 0, $length = strlen($atom); $i < $length; ++$i) {
            if ($atom[$i] === '(') {
                ++$depth;
            } elseif ($atom[$i] === ')' && --$depth === 0) {
                return $i === $length - 1;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function stringList(string $condition): array
    {
        self::assertSame(1, preg_match("/\(([^()]*)\)\z/D", $condition, $found), 'Malformed list: ' . $condition);
        preg_match_all("/'([a-z_]+)'/D", $found[1], $values);

        return $values[1];
    }

    private function quoted(string $condition, string $prefix): string
    {
        self::assertSame(
            1,
            preg_match('/\A' . preg_quote($prefix, '/') . "'([a-z_]+)'\z/D", $condition, $found),
            'Malformed comparison: ' . $condition
        );

        return $found[1];
    }

    /**
     * The tab a category label is reachable through. Every label except the primary
     * approval category is its own tab; `approval` is shown under the overlapping
     * `awaiting_approval` tab, which also carries the unknown sender and low confidence
     * candidates (see the help text in ReviewQueuePage).
     */
    private function tabFor(string $label): string
    {
        return $label === self::PRIMARY_APPROVAL_LABEL ? 'awaiting_approval' : $label;
    }

    /**
     * The tabs a category label is reachable through. Every disjoint category is its own
     * tab; the primary approval category is shown under the overlapping awaiting approval
     * tab, which also carries the unknown sender and low confidence candidates (see the
     * help text in ReviewQueuePage). The recent changes placeholder is excluded because
     * it answers before touching the database.
     *
     * @return list<string>
     */
    private function tabsReachableAsACategory(): array
        {
            return [...self::DISJOINT_TABS, 'awaiting_approval'];
        }

    /**
     * The matrix rows that ReviewQueueRepository::where() actually lets through, keyed
     * by their name.
     *
     * Duplicate and superseded rows are dropped because where() scopes them out of the
     * queue; everything else survives, and the category expression then has to place
     * this row in exactly one tab.
     *
     * @return array<string, array<string, mixed>>
     */
    private function scopedRows(): array
    {
        $rows = [];
        foreach ($this->matrix() as $case) {
            if (in_array($case['row']['status'], self::SCOPED_ELSEWHERE, true)) {
                continue;
            }
            $rows[$case['name']] = $case['row'];
        }

        return $rows;
    }

    private function matrix(): array
    {
        $matrix = [];
        $variants = [
            'known sender' => ['parish_id' => 4, 'sender_email' => 'parish@example.test', 'unknown_sender' => false, 'confidence' => 0.90],
            'unlinked parish' => ['parish_id' => null, 'sender_email' => 'parish@example.test', 'unknown_sender' => false, 'confidence' => 0.90],
            'missing sender' => ['parish_id' => 4, 'sender_email' => null, 'unknown_sender' => false, 'confidence' => 0.90],
            'flagged unknown sender' => ['parish_id' => 4, 'sender_email' => 'stranger@example.test', 'unknown_sender' => true, 'confidence' => 0.90],
            'unverified contact' => ['parish_id' => 4, 'sender_email' => 'stranger@example.test', 'unknown_sender' => false, 'confidence' => 0.90],
            'low confidence' => ['parish_id' => 4, 'sender_email' => 'parish@example.test', 'unknown_sender' => false, 'confidence' => 0.20],
        ];
        $columns = [
            '' => [],
            'approved' => ['approved_by' => 11],
            'decided' => ['decided_at' => '2026-03-01 09:00:00'],
            'confirmation failed' => ['confirmation' => 'failed'],
            'confirmation suppressed' => ['confirmation' => 'suppressed'],
            'confirmation sent' => ['confirmation' => 'sent'],
        ];

        foreach (self::CANDIDATE_STATUSES as $status) {
            foreach ($variants as $name => $variant) {
                foreach ($columns as $column => $overrides) {
                    $row = array_merge([
                        'status' => $status,
                        'parish_id' => 4,
                        'sender_email' => 'parish@example.test',
                        'unknown_sender' => false,
                        'confidence' => 0.90,
                        'approved_by' => null,
                        'decided_at' => null,
                        'confirmation' => 'pending',
                    ], $variant, $overrides);
                    $matrix[] = [
                        'name' => sprintf('%s, %s%s', $status, $name, $column === '' ? '' : ', ' . $column),
                        'row' => $row,
                        'expected' => $this->expected($row),
                    ];
                }
            }
        }

        // A status the schema never produces still has to land somewhere: the terminal
        // ELSE. Without it a new status would simply vanish from the queue.
        $unknown = array_merge([
            'status' => self::UNRECOGNISED_STATUS,
            'parish_id' => 4,
            'sender_email' => 'parish@example.test',
            'unknown_sender' => false,
            'confidence' => 0.90,
            'approved_by' => null,
            'decided_at' => null,
            'confirmation' => 'pending',
        ]);
        $matrix[] = [
            'name' => self::UNRECOGNISED_STATUS . ', known sender',
            'row' => $unknown,
            'expected' => $this->expected($unknown),
        ];

        return $matrix;
    }

    /**
     * The documented expectation, written independently of the SQL.
     *
     * @param array<string, mixed> $row
     */
    private function expected(array $row): string
    {
        $awaiting = $row['status'] === 'awaiting_approval';
        $known = $row['parish_id'] !== null
            && $row['sender_email'] !== null
            && $row['unknown_sender'] !== true
            && in_array(
                ['parish_id' => $row['parish_id'], 'email' => $row['sender_email'], 'trust' => 'verified'],
                $this->contacts,
                true
            );

        return match (true) {
            $row['status'] === 'published' => 'recently_published',
            $row['status'] === 'rejected' => 'recently_decided',
            $awaiting && ($row['approved_by'] !== null || $row['decided_at'] !== null) => self::FALLBACK_LABEL,
            in_array($row['status'], ['failed', 'expired', 'approved'], true) => self::FALLBACK_LABEL,
            $row['status'] === 'draft' && in_array($row['confirmation'], ['failed', 'suppressed'], true) => self::FALLBACK_LABEL,
            in_array($row['status'], ['draft', 'awaiting_submitter'], true) => 'awaiting_submitter',
            $awaiting && ! $known => 'unknown_senders',
            $awaiting && $row['confidence'] < 0.55 => 'low_confidence',
            $awaiting => self::PRIMARY_APPROVAL_LABEL,
            default => self::FALLBACK_LABEL,
        };
    }
}

/**
 * A clock frozen away from the thirty day window, so the classification under test
 * does not depend on when the suite runs.
 */
final class ReviewQueueFixedClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-03-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
    }
}

final class ReviewQueueRecordingDatabase implements DatabaseConnectionInterface
{
    /** @var list<string> */
    public array $queries = [];

    /** @var list<array<string, mixed>> */
    public array $resultRows = [];

    private string $error = '';

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        if ($arguments !== []) {
            $values = array_map(static fn (mixed $value): string => is_int($value) || is_float($value)
                ? (string) $value
                : "'" . (is_bool($value) ? (int) $value : (string) $value) . "'", $arguments);
            $query = preg_replace('/%[sdf]/', '%s', $query);
            Assert::assertIsString($query);
            $query = vsprintf($query, $values);
        }
        $this->queries[] = $query;

        return $query;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;

        return 0;
    }

    public function getRow(string $query): ?array
    {
        $rows = $this->getResults($query);

        return $rows[0] ?? null;
    }

    public function getResults(string $query): array
    {
        $this->queries[] = $query;

        return $this->resultRows;
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
