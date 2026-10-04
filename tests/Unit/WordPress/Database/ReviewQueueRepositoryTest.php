<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
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
                         * A reviewer who edits a candidate's text must not unassign its parish.
                         *
                         * Neither the admin detail editor nor the front-end approval queue renders a
                         * `parish_id` field — parish assignment is a separate, reviewer-only flow
                         * (`assignParish`). So the validator returns `values` with no `parish_id` key
                                     * at all, and reading that key as "the caller wants no parish" wrote the column
                                     * as NULL. The candidate then matched no deanery's scope predicate, so the
                                     * very editor who corrected it could no longer see, approve or reject it,
                         * and neither could anybody else. Only an absent *form field* is an explicit
                         * request to clear the parish; a missing key must leave the stored one alone.
                                     *
                                     * The recording double's `query()` returns 0, so `updateFields()` reports
                                     * `not_editable` for every write; the statement is still recorded, and the
                                     * SQL is what this behaviour is about.
                                     */
                                    public function testAnEditThatOmitsParishKeepsTheStoredParish(): void
                                    {
                                        $update = $this->editThatOmitsParishFor([
                                            'id' => '4',
                                            'status' => 'awaiting_approval',
                                'parish_id' => '9',
                                'fields' => '{"title":"Before","parish_id":9}',
                                        ]);

                                        self::assertNotNull($update, 'updateFields() must write an UPDATE.');
                                        self::assertStringContainsString(
                                            "parish_id = NULLIF('9', '')",
                                            $update,
                                            'An edit that omits the parish must carry the stored one through, never a bare NULL.'
                                        );
                                        self::assertStringNotContainsString("NULLIF('0', '')", $update);
                                        self::assertStringNotContainsString("NULLIF('', '')", $update);
                                    }

                                    /**
                                     * A candidate that never had a parish still has nothing to preserve, so the
                                     * column is written as a real SQL NULL rather than the empty string — which
                                     * `bigint unsigned` would coerce to `0`, a parish that does not exist.
                                     */
                                    public function testAnEditPreservesAnAlreadyUnassignedParish(): void
                                    {
                                        $update = $this->editThatOmitsParishFor([
                                            'id' => '4',
                                            'status' => 'awaiting_approval',
                                            'parish_id' => null,
                                            'fields' => '{"title":"Before"}',
                                        ]);

                                        self::assertNotNull($update, 'updateFields() must write an UPDATE.');
                                        self::assertStringContainsString("parish_id = NULLIF('', '')", $update);
                                    }

                                    /**
                                     * A `parish_id` of zero is not a parish. The column is
                                     * `bigint unsigned`, so a NULL written here becomes '0' and the candidate
                                     * silently stops belonging to any deanery — the exact failure the absent-key
                                     * branch exists to prevent. An unusable value is ignored in favour of the
                                     * stored one, and clearing a parish stays `assignParish()`'s audited action.
                                     */
                                    public function testAnEditThatSubmitsAZeroParishKeepsTheStoredParish(): void
                                    {
                                        $database = new ReviewQueueRecordingDatabase();
                                        $queue = $this->repository($database);
                                        $database->resultRows = [[
                                            'id' => '4',
                                            'status' => 'awaiting_approval',
                                            'approved_by' => null,
                                            'decided_at' => null,
                                            'parish_id' => '9',
                                            'fields' => '{"title":"Before","parish_id":9}',
                                            'recurrence' => null,
                                        ]];

                                        $queue->updateFields(
                                            4,
                                            ['title' => 'After', 'parish_id' => 0],
                                            [],
                                            ['title'],
                                            7,
                                            'dean@example.test',
                                            false
                                        );

                                        $update = $this->updateOfFields($database->queries);
                                        self::assertNotNull($update, 'updateFields() must write an UPDATE.');
                                        self::assertStringContainsString("NULLIF('9', '')", $update);
                                        self::assertStringNotContainsString("NULLIF('0', '')", $update);
                                    }

                                    /**
                                     * When a caller does carry a usable `parish_id` it wins, so column and JSON
                                     * stay in step and `assignParish()`'s realignment still holds.
                                     */
                                    public function testAnEditThatSuppliesAParishWritesItToBothTheColumnAndTheJson(): void
                                    {
                                        $database = new ReviewQueueRecordingDatabase();
                                        $queue = $this->repository($database);
                                        $database->resultRows = [[
                                            'id' => '4',
                                            'status' => 'awaiting_approval',
                                            'approved_by' => null,
                                            'decided_at' => null,
                                            'parish_id' => '9',
                                            'fields' => '{"title":"Before","parish_id":9}',
                                            'recurrence' => null,
                                        ]];

                                        $queue->updateFields(
                                            4,
                                            ['title' => 'After', 'parish_id' => 12],
                                            [],
                                            ['parish_id'],
                                            7,
                                            'dean@example.test',
                                            false
                                        );

                                        $update = $this->updateOfFields($database->queries);
                                        self::assertNotNull($update, 'updateFields() must write an UPDATE.');
                                        self::assertStringContainsString('"parish_id":12', $update);
                                        self::assertStringContainsString("parish_id = NULLIF('12', '')", $update);
                                    }

                                    /**
                                     * Saves an edit of one key on a candidate with the given stored row, so the
                                     * parish-preservation tests can share the fixture setup.
                                     *
                                     * @param array<string, mixed> $storedRow
                                     */
                                    private function editThatOmitsParishFor(array $storedRow): ?string
                                    {
                                        $database = new ReviewQueueRecordingDatabase();
                                        $queue = $this->repository($database);
                                        $database->resultRows = [$storedRow + [
                                            'approved_by' => null,
                                            'decided_at' => null,
                                            'recurrence' => null,
                                        ]];

                                        $queue->updateFields(
                                            4,
                                            ['title' => 'After'],
                                            [],
                                            ['title'],
                                            7,
                                            'dean@example.test',
                                            false
                                        );

                                        return $this->updateOfFields($database->queries);
                                    }

                        /**
                         * The changes a change belongs to, and who may see it, are one rule shared by
                         * the list and the single-row lookup (issue #72).
             *
             * The rule is deliberately not the candidate rule. A change carries no parish
             * column of its own, so it inherits the candidate's parish and falls back to
             * the event's `parish_id` post meta. If the two rules were collapsed, every
             * change to an already-published event — which has no candidate link at all —
             * would fall outside every dean's scope, so the change would vanish from the
             * queue for the very people entitled to see it.
             */
            public function testRecordedChangesAreScopedByCandidateParishThenEventPostMeta(): void
            {
                $database = new ReviewQueueRecordingDatabase();
                $queue = $this->repository($database);

                $database->resultRows = [['id' => '4', 'parish_id' => '9', 'parish_name' => 'Fictional Parish']];
                $rows = $queue->recentChanges(7, 'dean@example.test', false);
                self::assertSame([['id' => '4', 'parish_id' => '9', 'parish_name' => 'Fictional Parish']], $rows);
                $listQuery = $database->lastQuery();

                $database->resultRows = [['id' => '4', 'parish_id' => '9', 'parish_name' => 'Fictional Parish']];
                $single = $queue->findScopedChange(4, 7, 'dean@example.test', false);
                self::assertSame(['id' => '4', 'parish_id' => '9', 'parish_name' => 'Fictional Parish'], $single);
                $singleQuery = $database->lastQuery();

                foreach ([$listQuery, $singleQuery] as $query) {
                    self::assertStringContainsString(
                        "COALESCE(c.parish_id, NULLIF(TRIM(meta.meta_value), ''))",
                        $query,
                        'A change inherits its candidate parish, then the event parish_id post meta.'
                    );
                    self::assertStringContainsString(
                        "meta.meta_key = 'parish_id'",
                        $query,
                        'The fallback must read the parish_id post meta, not any meta key.'
                    );
                    self::assertStringContainsString(
                        'a.wp_user_id = 7',
                        $query,
                        "A dean's scope is their own approver rows, not a caller-supplied flag."
                    );
                    self::assertStringContainsString("d.status = 'active'", $query, 'An inactive deanery grants nothing.');
                    self::assertStringContainsString('a.active = 1', $query, 'A deactivated assignment grants nothing.');
                    self::assertStringContainsString(
                        "AND meta.meta_key = 'parish_id'",
                        $query,
                        'The meta join is constrained in the JOIN, so the scope never doubles the row count.'
                    );
                    self::assertStringContainsString(
                        "COALESCE(c.parish_id, NULLIF(TRIM(meta.meta_value), '')) IS NOT NULL",
                        $query,
                        'A change with no parish at all belongs to nobody and must not be listed.'
                    );
                    }

                self::assertStringContainsString('ch.id = 4', $singleQuery, 'The single-row lookup is bound by ID.');
                self::assertStringNotContainsString('ch.id =', $listQuery, 'The list is not filtered to one change.');
                self::assertStringContainsString('ORDER BY ch.created_at DESC, ch.id DESC LIMIT 25', $listQuery);
            }

            /**
             * An archdiocese reviewer sees every change, which is the same carve-out
             * findScoped() makes, so the two surfaces cannot disagree about who is
             * archdiocese-wide.
             */
            public function testAReviewerIsNotScopedToAnyDeaneryForRecordedChanges(): void
            {
                $database = new ReviewQueueRecordingDatabase();
                $queue = $this->repository($database);

                $database->resultRows = [];
                $queue->recentChanges(7, 'reviewer@example.test', true);
                $query = $database->lastQuery();
                self::assertStringContainsString('AND (1 = 1)', $query);
                self::assertStringNotContainsString('a.wp_user_id =', $query);
                self::assertStringNotContainsString('EXISTS (SELECT 1 FROM', $query);
            }

            /**
             * Both bounds, so an ID of zero cannot reach the database as a real row and an
             * unbounded limit cannot be used to pull the whole history into one request.
             */
            public function testRecordedChangeIdentifiersAndLimitsAreBounded(): void
            {
                $database = new ReviewQueueRecordingDatabase();
                $queue = $this->repository($database);
                $database->resultRows = [];

                foreach ([
                    'change ID' => static fn () => $queue->findScopedChange(0, 7, 'dean@example.test', false),
                    'reviewer ID' => static fn () => $queue->recentChanges(0, 'dean@example.test', false),
                ] as $what => $call) {
                    try {
                        $call();
                        self::fail('A ' . $what . ' of zero must be refused.');
                    } catch (InvalidArgumentException $expected) {
                        self::assertStringContainsString('must be positive', $expected->getMessage());
                    }
                }

                $queue->recentChanges(7, 'dean@example.test', false, 5000);
                self::assertStringContainsString('LIMIT 100', $database->lastQuery());
                $queue->recentChanges(7, 'dean@example.test', false, 0);
                self::assertStringContainsString('LIMIT 1', $database->lastQuery());
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

    /**
     * The `UPDATE ... SET fields = …` that `updateFields()` issues, or null.
     *
     * @param list<string> $queries
     */
    private function updateOfFields(array $queries): ?string
    {
        foreach ($queries as $query) {
                    if (str_starts_with($query, 'UPDATE ') && str_contains($query, 'adct_pi_event_candidates` SET fields = ')) {
                return $query;
            }
        }

        return null;
    }

    private function repository(ReviewQueueRecordingDatabase $database): ReviewQueueRepository
    {
        return new ReviewQueueRepository($database, new ReviewQueueFixedClock());
    }

        /**
         * Issue #176: booking a confirmation resend.
         *
         * These live here rather than beside the admin page because the cooldown is
         * the point of the issue and the cooldown is enforced here, inside the same
         * transaction that writes the audit row. A test that only checked the page
         * would pass whether or not this guard existed.
         */
        public function testBookingAResendWritesTheAuditRowAndCommits(): void
        {
            $database = $this->resendDatabase();
            $database->writesSucceed = true;

            $messageId = $this->repository($database)->bookConfirmationResend(
                4,
                9,
                'reviewer@example.test',
                true,
                new DateTimeImmutable('2026-10-12 09:15:00', new DateTimeZone('Africa/Johannesburg')),
                3600
            );

            self::assertSame(55, $messageId, 'The booking returns the message to render from.');
            self::assertContains('START TRANSACTION', $database->queries);
            self::assertContains('COMMIT', $database->queries);

            $insert = $this->resendAuditInsert($database->queries);
            self::assertNotNull($insert, 'A resend is an outbound message to a parish and must be recorded.');
            self::assertStringContainsString('candidate_confirmation_resent', $insert);
            self::assertStringContainsString('reviewer@example.test', $insert);
            self::assertStringContainsString('"cooldown_seconds":3600', $insert);
            // 09:15 SAST is 07:15 UTC; the audit trail is written in UTC.
            self::assertStringContainsString('2026-10-12 07:15:00', $insert);
            self::assertStringContainsString('"next_allowed_at":"2026-10-12 08:15:00"', $insert);
        }

        public function testTheAuditDetailsDoNotClaimToCarryTheRecipient(): void
        {
            $database = $this->resendDatabase();
            $database->writesSucceed = true;

            $this->repository($database)->bookConfirmationResend(
                4,
                9,
                'reviewer@example.test',
                true,
                new DateTimeImmutable('2026-10-12 09:15:00', new DateTimeZone('Africa/Johannesburg')),
                3600
            );

            // The audit row is written before the recipient is resolved, so it cannot
            // honestly name one. The queued mail row is the record of who was written to.
            $insert = (string) $this->resendAuditInsert($database->queries);
            self::assertStringNotContainsString('recipient', $insert);
            self::assertStringNotContainsString('parish@example.test', $insert);
        }

        public function testASecondResendInsideTheHourIsRefusedAndRolledBack(): void
        {
            $database = $this->resendDatabase();
            $database->writesSucceed = true;
            $database->lastResendAuditRow = ['created_at' => '2026-10-12 07:00:00'];

            try {
                $this->repository($database)->bookConfirmationResend(
                    4,
                    9,
                    'reviewer@example.test',
                    true,
                    new DateTimeImmutable('2026-10-12 09:15:00', new DateTimeZone('Africa/Johannesburg')),
                    3600
                );
                self::fail('The cooldown should have refused the resend.');
            } catch (\ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendCooldownException $refusal) {
                // 07:00 UTC is 09:00 SAST, so the retry lands at 10:00 SAST.
                self::assertSame(
                    '2026-10-12 07:00:00',
                    $refusal->lastResentAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
                );
                self::assertSame(
                    '2026-10-12 08:00:00',
                    $refusal->retryAfter->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
                );
            }

            self::assertContains('ROLLBACK', $database->queries);
            self::assertNotContains('COMMIT', $database->queries);
            self::assertNull(
                $this->resendAuditInsert($database->queries),
                'A refused resend must not be recorded as having happened.'
            );
        }

        public function testTheCooldownIsAllowedOnceTheHourHasPassed(): void
        {
            $database = $this->resendDatabase();
            $database->writesSucceed = true;
            $database->lastResendAuditRow = ['created_at' => '2026-10-12 07:00:00'];

            $messageId = $this->repository($database)->bookConfirmationResend(
                4,
                9,
                'reviewer@example.test',
                true,
                new DateTimeImmutable('2026-10-12 10:00:00', new DateTimeZone('Africa/Johannesburg')),
                3600
            );

            self::assertSame(55, $messageId);
            self::assertContains('COMMIT', $database->queries);
        }

        public function testTheCooldownComparesInstantsNotWallTimes(): void
        {
            // A reviewer in another timezone pressing "resend" must not get an extra
            // hour, and a stored UTC row must not be read as SAST and so extend the wait
            // by two hours. Both fall out of comparing absolute times.
            $database = $this->resendDatabase();
            $database->writesSucceed = true;
            $database->lastResendAuditRow = ['created_at' => '2026-10-12 07:00:00'];

            $messageId = $this->repository($database)->bookConfirmationResend(
                4,
                9,
                'reviewer@example.test',
                true,
                new DateTimeImmutable('2026-10-12 11:00:00', new DateTimeZone('America/New_York')),
                3600
            );

            self::assertSame(55, $messageId, '15:00 UTC is well clear of an hour after 07:00 UTC.');
        }

        public function testACandidateOutsideTheReviewersScopeCannotBeResent(): void
        {
            $database = $this->resendDatabase();
            $database->writesSucceed = true;
            $database->resultRows = [];

            try {
                $this->repository($database)->bookConfirmationResend(
                    4,
                    9,
                    'reviewer@example.test',
                    true,
                    new DateTimeImmutable('2026-10-12 09:15:00', new DateTimeZone('Africa/Johannesburg')),
                    3600
                );
                self::fail('An out-of-scope candidate must be refused.');
            } catch (DomainException $refusal) {
                self::assertSame('That event is no longer available to you.', $refusal->getMessage());
            }

            self::assertContains('ROLLBACK', $database->queries);
            self::assertNull($this->resendAuditInsert($database->queries));
        }

        public function testASavedCandidateIsRequiredToResend(): void
        {
            $database = $this->resendDatabase();
            $database->writesSucceed = true;
            $database->resultRows = [['id' => '4', 'message_id' => '0', 'fields' => 'null']];

            try {
                $this->repository($database)->bookConfirmationResend(
                    4,
                    9,
                    'reviewer@example.test',
                    true,
                    new DateTimeImmutable('2026-10-12 09:15:00', new DateTimeZone('Africa/Johannesburg')),
                    3600
                );
                self::fail('A candidate with no message must be refused.');
            } catch (DomainException $refusal) {
                self::assertSame(
                    'This event has no message to resend a confirmation for.',
                    $refusal->getMessage()
                );
            }
        }

        public function testABookingIsValidatedBeforeItTouchesTheDatabase(): void
        {
            $database = $this->resendDatabase();
            $repository = $this->repository($database);
            $at = new DateTimeImmutable('2026-10-12 09:15:00', new DateTimeZone('Africa/Johannesburg'));

            try {
                $repository->bookConfirmationResend(0, 9, 'reviewer@example.test', true, $at, 3600);
                self::fail('A non-positive candidate ID must be refused.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $database->queries, 'A rejected argument must not open a transaction.');
            }

            try {
                $repository->bookConfirmationResend(4, 9, 'reviewer@example.test', true, $at, -1);
                self::fail('A negative cooldown must be refused.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $database->queries);
            }
        }

        public function testTheLastResendIsReadBackAsAnInstant(): void
        {
            $database = $this->resendDatabase();
            $database->lastResendAuditRow = ['created_at' => '2026-10-12 07:00:00'];

            $last = $this->repository($database)->lastConfirmationResentAt(4);

            self::assertNotNull($last);
            // Read as UTC, so the admin screen can format it in Africa/Johannesburg.
            self::assertSame('2026-10-12 09:00:00', $last->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('Y-m-d H:i:s'));
        }

        public function testACandidateNeverResentHasNoLastResend(): void
        {
            $database = $this->resendDatabase();

            self::assertNull($this->repository($database)->lastConfirmationResentAt(4));
        }

        /**
         * A candidate row the booking's `FOR UPDATE` read returns.
         */
        private function resendDatabase(): ReviewQueueRecordingDatabase
        {
            $database = new ReviewQueueRecordingDatabase();
            $database->resultRows = [[
                'id' => '4',
                'status' => 'awaiting_approval',
                'message_id' => '55',
                'fields' => '{"title":"Fictional event"}',
                'parish_id' => null,
            ]];

            return $database;
        }

        /**
         * The `INSERT INTO ... adct_pi_audit_log` a booking issued, or null.
         *
         * @param list<string> $queries
         */
        private function resendAuditInsert(array $queries): ?string
        {
            foreach ($queries as $query) {
                if (str_starts_with($query, 'INSERT INTO `wp_adct_pi_audit_log`')) {
                    return $query;
                }
            }

            return null;
        }

        /**
         * Issue #177: resolving an ambiguous match from the candidate detail screen.
     *
         * The two ambiguity keys are *removed*, not falsified —
         * `MatchReviewPolicy::requiresManualReview()` decides on key presence — so
         * the encoded `fields` written by the update must contain neither key at all.
         * Every statement here is checked against the SQL the repository really
         * sends, because `query()` returns 0 on this double and `assignParish()`
         * therefore reports `false` for every write.
         */
        public function testResolvingAMatchRemovesTheAmbiguityKeysAndWritesTheChosenParish(): void
        {
            $update = $this->resolveMatch(['parish_id' => 12], ['parish_id' => 12]);

            self::assertNotNull($update, 'Resolving a match must write an UPDATE.');
            self::assertStringNotContainsString('match_review_required', $update);
            self::assertStringNotContainsString('matched_candidate_id', $update);
            self::assertStringContainsString('"title":"Fictional event"', $update);
            self::assertStringContainsString('"parish_id":12', $update, 'Column and JSON must agree.');
            self::assertStringContainsString("parish_id = NULLIF('12', '')", $update);
        }

        /**
         * A venue is very often *why* the match was ambiguous, so the resolution
         * route has to be able to move it or take it away. Today a venue belonging to
         * another parish is refused outright with "resolve the venue first", which on
         * this screen means the reviewer is told to do the thing they are already doing.
         */
        public function testResolvingAMatchCanAlsoCorrectTheVenue(): void
        {
            $update = $this->resolveMatch(
                ['parish_id' => 12, 'venue_id' => 77],
                ['parish_id' => 12, 'venue_id' => 77],
                77
            );

            self::assertNotNull($update);
            self::assertStringContainsString('"venue_id":77', $update);
        }

        /**
         * Blanking the venue is a real answer — the poster named no venue, or named one
         * that does not exist — so the key is removed rather than written as 0.
         */
        public function testResolvingAMatchCanClearTheVenue(): void
        {
            $update = $this->resolveMatch(
                ['parish_id' => 12, 'venue_id' => 77],
                ['parish_id' => 12],
                0
            );

            self::assertNotNull($update);
            self::assertStringNotContainsString('"venue_id"', $update);
        }

        /**
         * "Leave it unassigned" is the other legitimate outcome: an ambiguous match
         * that genuinely belongs to no one parish goes to an archdiocese reviewer.
         *
         * The column is written as a real SQL NULL through `NULLIF(%s, '')`, because
         * `bigint unsigned` would coerce a 0 into "parish 0", and `fields['parish_id']`
         * is removed so `canBulkApprove()` does not read it as disagreeing with the
         * column. `venue_id` is dropped too — a venue without a parish cannot belong
         * anywhere.
         */
        public function testResolvingAMatchCanLeaveTheCandidateUnassigned(): void
        {
            $update = $this->resolveMatch(
                ['parish_id' => 12, 'venue_id' => 77],
                [],
                0,
                0
            );

            self::assertNotNull($update);
            self::assertStringContainsString("parish_id = NULLIF('', '')", $update);
            self::assertStringNotContainsString("NULLIF('0', '')", $update);
            self::assertStringNotContainsString('"parish_id"', $update);
            self::assertStringNotContainsString('"venue_id"', $update);
            self::assertStringNotContainsString('match_review_required', $update);
        }

        /**
         * Only the resolution route may clear a parish. The bulk assignment form
         * posts `parish_id = 0` when no parish was chosen, and that has to stay a
         * refusal rather than becoming a silent unassignment.
         */
        public function testBulkAssignmentStillRefusesAParishIdOfZero(): void
        {
            $database = new ReviewQueueRecordingDatabase();

            $this->expectException(InvalidArgumentException::class);
            try {
                $this->repository($database)->assignParish(4, 0, 7, 'reviewer@example.test');
            } finally {
                self::assertSame([], $database->queries, 'A refused request must not reach the database.');
            }
        }

        /**
         * A dean may resolve a candidate in their own scope. Scoped "exactly like
         * deciding" means the same predicate, not the reviewer-only one the bulk form
         * uses — otherwise a dean who is perfectly entitled to approve the item could
         * not unblock it, and would have to ask an archdiocese reviewer.
         */
        public function testADeanCanResolveAMatchInsideTheirOwnScope(): void
        {
            $database = new ReviewQueueRecordingDatabase();
            $database->resultRows = [[
                'id' => '4', 'status' => 'awaiting_approval', 'approved_by' => null, 'decided_at' => null,
                'parish_id' => null, 'fields' => '{"title":"Fictional event","match_review_required":true}',
            ]];
            $database->lookupRows = [12 => ['id' => '12']];

            $this->repository($database)->assignParish(
                4,
                12,
                7,
                'dean@example.test',
                false,
                null,
                true
            );

            $scoped = $this->lockedRead($database->queries);
            self::assertNotNull($scoped, 'The candidate must be re-read under a lock.');
            self::assertStringNotContainsString("'reviewer@example.test'", $scoped, $scoped);
            self::assertStringContainsString('dean@example.test', $scoped);
        }

        /**
         * A resolution writes one row describing what was chosen, so the trail names
         * who resolved it, what they chose and — importantly — whether there was an
         * ambiguity to clear. The bulk form keeps its own verb and its own details,
         * because it is a different act and the trail is read by people deciding what
         * happened.
         */
        public function testResolvingAMatchAuditsOneRowUnderItsOwnVerbAndBulkAssignKeepsItsOwn(): void
        {
            $database = new ReviewQueueRecordingDatabase();
            $database->writesSucceed = true;
            $database->resultRows = [[
                'id' => '4', 'status' => 'awaiting_approval', 'approved_by' => null, 'decided_at' => null,
                'parish_id' => null, 'fields' => '{"title":"Fictional event","match_review_required":true,"matched_candidate_id":9}',
            ]];
            $database->lookupRows = [12 => ['id' => '12']];

            $this->repository($database)->assignParish(4, 12, 7, 'reviewer@example.test', true, null, true);

            $resolved = $this->auditRow($database->queries, 'candidate_match_resolved');
            self::assertNotNull($resolved, 'Resolving a match must write a candidate_match_resolved row.');
            self::assertSame(1, $this->countAudits($database->queries), 'Exactly one audit row per resolution.');
            self::assertStringContainsString('"role":"reviewer"', $resolved);
            self::assertStringContainsString('"from_parish_id":null', $resolved);
            self::assertStringContainsString('"to_parish_id":12', $resolved);
            self::assertStringContainsString('"left_unassigned":false', $resolved);
            self::assertStringContainsString('"match_review_cleared":true', $resolved);
            self::assertNull(
                $this->auditRow($database->queries, 'candidate_parish_assigned'),
                'A resolution must not also write the plain assignment verb.'
            );

            $bulk = new ReviewQueueRecordingDatabase();
            $bulk->writesSucceed = true;
            $bulk->resultRows = [[
                'id' => '4', 'status' => 'awaiting_approval', 'approved_by' => null, 'decided_at' => null,
                'parish_id' => '3', 'fields' => '{"title":"Fictional event"}',
            ]];
            $bulk->lookupRows = [12 => ['id' => '12']];
            $this->repository($bulk)->assignParish(4, 12, 7, 'reviewer@example.test');

            $assigned = $this->auditRow($bulk->queries, 'candidate_parish_assigned');
            self::assertNotNull($assigned, 'The bulk form keeps writing the verb it always wrote.');
            self::assertStringContainsString('"from_parish_id":3', $assigned);
            self::assertStringContainsString('"to_parish_id":12', $assigned);
            self::assertNull(
                $this->auditRow($bulk->queries, 'candidate_match_resolved'),
                'A plain assignment is not a match resolution.'
            );
        }

        /**
         * Resolving a match nothing flagged would write an audit row claiming a block
         * was cleared that never existed, and would rewrite `fields` for no reason.
         */
        public function testResolvingAnAlreadyResolvedCandidateChangesNothing(): void
        {
            $database = new ReviewQueueRecordingDatabase();
            $database->resultRows = [[
                'id' => '4', 'status' => 'awaiting_approval', 'approved_by' => null, 'decided_at' => null,
                'parish_id' => '12', 'fields' => '{"title":"Fictional event","parish_id":12}',
            ]];
                    $database->lookupRows = [12 => ['id' => '12']];

            self::assertFalse(
                $this->repository($database)->assignParish(4, 12, 7, 'reviewer@example.test', true, null, true)
            );
            self::assertNull($this->updateOfParishAndFields($database->queries));
            self::assertSame(0, $this->countAudits($database->queries));
        }

        /**
         * A resolved candidate that has since been decided is refused, not silently
         * re-opened: the resolution runs under the same lock and the same guard as
         * every other write here.
         */
        public function testResolvingAMatchOnADecidedCandidateIsRefused(): void
        {
            $database = new ReviewQueueRecordingDatabase();
            $database->resultRows = [[
                'id' => '4', 'status' => 'approved', 'approved_by' => 'dean@example.test',
                'decided_at' => '2026-03-01 08:00:00', 'parish_id' => null,
                'fields' => '{"title":"Fictional event","match_review_required":true}',
            ]];

            $this->expectException(DomainException::class);
            $this->repository($database)->assignParish(4, 12, 7, 'reviewer@example.test', true, null, true);
        }

                /**
                 * Issue #218, the whole defect in one statement: a `duplicate` row is a
                 * candidate a reviewer still has to decide about, `canDecide()` says so
                 * (#217), and the queue lists it — yet the write path refused exactly the
                 * status the policy allows, so the detail screen offered a control that
                 * always failed with "Only an undecided candidate can be assigned a
                 * parish."
                 *
                 * Resolving one therefore returns it to `awaiting_approval` in the same
                 * statement that clears the block, and the `WHERE` names the status the
                 * row was actually locked under. Both halves matter: a row that stayed
                 * `duplicate` with the ambiguity keys removed drops out of
                 * {@see where()} — that listing keys off `fields` — and would be
                 * permanently invisible and unapprovable, which is the dead end #177
                 * exists to remove.
                 */
                public function testResolvingADuplicateReturnsItToAwaitingApproval(): void
                {
                    $update = $this->resolveMatch(['status' => 'duplicate', 'parish_id' => 12], ['parish_id' => 12]);

                    self::assertNotNull($update, 'A duplicate is an undecided candidate and must be resolvable.');
                    self::assertStringContainsString(
                        "status = 'awaiting_approval'",
                        $update,
                        'A human who resolved the duplicate proved it was not one, so it goes back to the '
                        . 'ordinary approval queue rather than vanishing from it.'
                    );
                    self::assertStringContainsString(
                        "status = 'duplicate'",
                        $update,
                        'The WHERE still names the status the row was locked under, so the write stays atomic '
                        . 'against a second reviewer racing this one.'
                    );
                    self::assertSame(
                        2,
                        substr_count($update, 'status = '),
                        "Exactly one status in SET and one in WHERE: naming 'awaiting_approval' in the guard as "
                        . 'well would write a row the WHERE could never match.'
                    );
                }

                /**
                 * The bulk assignment form is a different act and keeps its narrower rule.
                 *
                 * It has no resolution panel, so widening it would let a bulk assignment
                 * clear a duplicate's block without anybody naming a match — and would
                 * leave the row `duplicate` with the keys gone, i.e. invisible. The two
                 * routes disagree on purpose now, which is exactly the disagreement #218
                 * is about, resolved deliberately rather than inherited.
                 */
                public function testBulkAssignmentStillRefusesADuplicateCandidate(): void
                {
                    $database = new ReviewQueueRecordingDatabase();
                    $database->resultRows = [[
                        'id' => '4', 'status' => 'duplicate', 'approved_by' => null, 'decided_at' => null,
                        'parish_id' => null, 'fields' => '{"title":"Fictional event","match_review_required":true}',
                    ]];
                    $database->lookupRows = [12 => ['id' => '12']];

                    $message = null;
                    try {
                        $this->repository($database)->assignParish(4, 12, 7, 'reviewer@example.test');
                    } catch (DomainException $failure) {
                        $message = $failure->getMessage();
                    }

                    self::assertSame('Only an undecided candidate can be assigned a parish.', $message);
                    self::assertNull(
                        $this->updateOfParishAndFields($database->queries),
                        'The bulk route must write nothing for a duplicate.'
                    );
                }

                /**
                 * The widened guard must not become a wider door: `duplicate` is only as
                 * resolvable as it is undecided. A duplicate somebody already decided is
                 * still refused, which is the same `approved_by`/`decided_at` guard as
                 * before, not a new one.
                 */
                public function testResolvingADuplicateThatWasAlreadyDecidedIsRefused(): void
                {
                    $database = new ReviewQueueRecordingDatabase();
                    $database->resultRows = [[
                        'id' => '4', 'status' => 'duplicate', 'approved_by' => 'dean@example.test',
                        'decided_at' => '2026-03-01 08:00:00', 'parish_id' => null,
                        'fields' => '{"title":"Fictional event","match_review_required":true}',
                    ]];

                    $this->expectException(DomainException::class);
                    $this->repository($database)->assignParish(4, 12, 7, 'reviewer@example.test', true, null, true);
                }

                /**
                 * The trail has to name the status move, or an auditor reading
                 * `candidate_match_resolved` cannot tell a routine unblock from a
                 * duplicate being returned to the approval queue — which is the only
                 * thing that makes it visible there again.
                 */
                public function testResolvingADuplicateAuditsTheStatusItMovedFromAndTo(): void
                {
                    $database = new ReviewQueueRecordingDatabase();
                    $database->writesSucceed = true;
                    $database->resultRows = [[
                        'id' => '4', 'status' => 'duplicate', 'approved_by' => null, 'decided_at' => null,
                        'parish_id' => '12', 'fields' => '{"title":"Fictional event","match_review_required":true}',
                    ]];
                    $database->lookupRows = [12 => ['id' => '12']];

                    $this->repository($database)->assignParish(4, 12, 7, 'reviewer@example.test', true, null, true);

                    $resolved = $this->auditRow($database->queries, 'candidate_match_resolved');
                    self::assertNotNull($resolved);
                    self::assertStringContainsString('"from_status":"duplicate"', $resolved);
                    self::assertStringContainsString('"to_status":"awaiting_approval"', $resolved);
                }

                /**
                 * The plain assignment keeps its own details shape: it moves no status, so
                 * naming one it did not move would be a lie in the audit trail.
                 */
                public function testAPlainAssignmentAuditsNoStatusChange(): void
                {
                    $database = new ReviewQueueRecordingDatabase();
                    $database->writesSucceed = true;
                    $database->resultRows = [[
                        'id' => '4', 'status' => 'awaiting_approval', 'approved_by' => null, 'decided_at' => null,
                        'parish_id' => '3', 'fields' => '{"title":"Fictional event"}',
                    ]];
                    $database->lookupRows = [12 => ['id' => '12']];

                    $this->repository($database)->assignParish(4, 12, 7, 'reviewer@example.test');

                    $assigned = $this->auditRow($database->queries, 'candidate_parish_assigned');
                    self::assertNotNull($assigned);
                    self::assertStringNotContainsString('"from_status"', $assigned);
                    self::assertStringNotContainsString('"to_status"', $assigned);
                }

        /**
         * A venue from another parish is refused on both routes, but the two say
         * different things: the bulk form's reviewer must go and resolve the venue,
         * while the resolution route *is* resolving the venue, so it asks for a venue
         * of the parish just chosen.
         */
        public function testAVenueFromAnotherParishIsRefusedOnBothRoutes(): void
        {
            $rows = [[
                'id' => '4', 'status' => 'awaiting_approval', 'approved_by' => null, 'decided_at' => null,
                'parish_id' => '3', 'fields' => '{"title":"Fictional event","venue_id":77}',
            ]];

            $bulk = new ReviewQueueRecordingDatabase();
            $bulk->resultRows = $rows;
            // Parish 12 exists; venue 77 is not one of its venues, so the cross-parish
            // check has something real to refuse.
            $bulk->lookupRows = [12 => ['id' => '12']];
            $bulkFailure = null;            try {
                $this->repository($bulk)->assignParish(4, 12, 7, 'reviewer@example.test');
            } catch (DomainException $failure) {
                $bulkFailure = $failure->getMessage();
            }
            self::assertSame('The existing venue belongs to another parish; resolve the venue first.', $bulkFailure);

            // The venue lookup has to answer "no such venue of parish 12" for this to
                        // reach the message at all, while parish 12 itself must exist so the check
                        // before it passes: `lookupRows` keys by id, so an absent 77 is empty.
            $resolve = new ReviewQueueRecordingDatabase();
            $resolve->resultRows = $rows;
                        $resolve->lookupRows = [12 => ['id' => '12']];
                        $resolveFailure = null;
            try {
                $this->repository($resolve)->assignParish(4, 12, 7, 'reviewer@example.test', true, null, true);
            } catch (DomainException $failure) {
                $resolveFailure = $failure->getMessage();
            }
            self::assertNotNull($resolveFailure, 'A venue of another parish must still be refused.');
            self::assertStringContainsString('Choose a venue of the parish you selected', (string) $resolveFailure);
        }

        /**
         * Resolves an ambiguous candidate and returns the `UPDATE` it issued.
         *
                 * `$storedRow` is merged *over* the defaults with `array_merge()`, not with
                 * `+`: the union operator keeps the left-hand value for a key both sides
                 * have, so `['status' => 'awaiting_approval'] + ['status' => 'duplicate']`
                 * is still `awaiting_approval`, and issue #218's whole subject would have
                 * been silently unreachable from this helper.
                 *
                 * @param array<string, mixed> $storedRow  the locked candidate, as stored
                 * @param array<string, mixed> $expectFields keys that must survive into `fields`
                 */
                private function resolveMatch(
                    array $storedRow,
                    array $expectFields,
                    ?int $venueId = null,
                    int $parishId = 12
                ): ?string {
                    $database = new ReviewQueueRecordingDatabase();
                    $database->resultRows = [array_merge([
                        'id' => '4', 'status' => 'awaiting_approval', 'approved_by' => null, 'decided_at' => null,
                        'fields' => '{"title":"Fictional event","match_review_required":true,"matched_candidate_id":9}',
                    ], $storedRow)];
            $database->lookupRows = [12 => ['id' => '12'], 77 => ['id' => '77']];

            $this->repository($database)->assignParish(4, $parishId, 7, 'reviewer@example.test', true, $venueId, true);

            $update = $this->updateOfParishAndFields($database->queries);
            foreach ($expectFields as $key => $value) {
                self::assertNotNull($update);
                self::assertStringContainsString('"' . $key . '":' . $value, (string) $update);
            }

            return $update;
        }

        /**
         * The `UPDATE ... SET parish_id = …` that `assignParish()` issues, or null.
         *
         * @param list<string> $queries
         */
        private function updateOfParishAndFields(array $queries): ?string
        {
            foreach ($queries as $query) {
                if (str_starts_with($query, 'UPDATE ') && str_contains($query, 'SET parish_id = ')) {
                    return $query;
                }
            }

            return null;
        }

        /**
         * The `SELECT ... FOR UPDATE` a locked read issues, or null.
         *
         * @param list<string> $queries
         */
        private function lockedRead(array $queries): ?string
        {
            foreach ($queries as $query) {
                if (str_contains($query, 'FOR UPDATE')) {
                    return $query;
                }
            }

            return null;
        }

        /**
         * The audit INSERT carrying the given verb, or null.
         *
         * @param list<string> $queries
         */
        private function auditRow(array $queries, string $action): ?string
        {
                    foreach (array_unique($queries) as $query) {
                        if (str_contains($query, "'" . $action . "'")
                            && str_contains($query, '(actor, action, subject_type, subject_id, details, created_at, updated_at)')) {
                            return $query;
                        }
                    }

                    return null;
                }

                /**
                 * How many audit INSERTs were issued. The detail screen's promise is one row
                 * per resolution, so the count is the assertion rather than the verb alone.
                 *
                 * `prepare()` and `query()` both record the statement they were handed, so an
                 * INSERT arrives twice; `array_unique()` folds that back to one write before
                 * counting, otherwise "one row" would read as two.
                 *
                 * @param list<string> $queries
                 */
                private function countAudits(array $queries): int
                {
                    $count = 0;
                    foreach (array_unique($queries) as $query) {
                        if (str_starts_with($query, 'INSERT INTO ')
                            && str_contains($query, '(actor, action, subject_type, subject_id, details, created_at, updated_at)')) {
                            $count++;
                        }
                    }

                    return $count;
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

        // By default nothing this double is told to write actually lands, which is
        // what lets a test assert on the statement a rejected call would have sent.
        // `writesSucceed` is for the tests that need the call to run to completion
        // — an audit row is only written after a successful update.
        return $this->writesSucceed ? 1 : 0;
    }

    public function getRow(string $query): ?array
    {
        $rows = $this->getResults($query);

        return $rows[0] ?? null;
    }

    /**
     * Rows for a lookup by primary key, addressed the way MySQL addresses it.
     *
     * `resultRows` is a flat list served to every read, which is enough when the
     * code under test reads one table and nothing else. `assignParish()` reads
     * the candidate, then the parish and then the venue, so the existence of the
     * second and third depends on the id in the statement rather than on position.
     * Everything not named here is still refused, so a route that starts reaching
     * for the rest of the schema fails here rather than passing on made-up rows.
     *
     * @param array<int, array<string, mixed>> $ids id => row
     */
    public array $lookupRows = [];

    /** @var list<string> */
    public array $lookups = [];

        /**
         * The most recent `candidate_confirmation_resent` audit row, keyed for issue #176.
         *
         * The booking transaction reads two different things inside one transaction --
         * the candidate `FOR UPDATE`, then the audit history -- so the resend tests
         * need to answer both. `resultRows` serves the candidate; this serves the
         * history lookup. Null means "never resent".
         *
         * @var array{created_at: string}|null
         */
        public ?array $lastResendAuditRow = null;

        public function getResults(string $query): array
        {
            $this->queries[] = $query;

            if (str_contains($query, 'adct_pi_audit_log')) {
                return $this->lastResendAuditRow === null ? [] : [$this->lastResendAuditRow];
            }

            if (preg_match('/WHERE id = (\d+)/', $query, $parts) === 1
                        && preg_match('/adct_pi_(parishes|venues)/', $query, $table) === 1) {
            $this->lookups[] = $table[1] . '#' . $parts[1];
                    $row = $this->lookupRows[(int) $parts[1]] ?? null;

                    // An absent id is an empty result set, exactly as MySQL would answer,
                    // and not a row of empty columns — the difference between "no such
                    // parish" and "this parish has no columns".
                    return $row === null ? [] : [$row];
                }

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

    /** Whether a statement this double is asked to run reports having written a row. */
    public bool $writesSucceed = false;

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
