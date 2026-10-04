<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #172: which published event a candidate's source material may attach to.
 *
 * The promote control on the review queue names a candidate, not an event, so
 * the handler has to work out the event itself. The link is the `source_candidate_id`
 * post meta the publish step already writes, cross-checked against the published
 * event. Three properties of the query are pinned here because each is the
 * difference between promoting onto the right event and promoting onto somebody
 * else's:
 *
 * - **The candidate id is bound, never interpolated.** It is bound with `%d`, so a
 *   crafted id cannot reach the meta comparison as SQL.
 * - **The event must actually be published.** A draft or trashed event whose meta
 *   still says `source_candidate_id` is not a legitimate target, so the query
 *   refuses it rather than copying a bulletin onto an unpublished page.
 * - **Nothing is trusted from the form.** The handler never reads an event id out
 *   of the request, so this lookup is the only way a promote can name its target.
 */
final class PublishedEventLookupTest extends TestCase
{
    public function testItFindsThePublishedEventThatCarriesThisCandidateAsItsSource(): void
    {
        $database = new CandidateEventLookupDatabase();
        $database->resultRows = [['ID' => '4312', 'post_status' => 'publish']];

        self::assertSame(
            4312,
            $this->repository($database)->findPublishedEventForCandidate(77)
        );
    }

    /**
     * An unpublished event with the same meta is not a target. A promote onto a
     * draft would put a world-readable file on a page nobody can see, and the file
     * itself would still be fetchable from the media library afterwards.
     */
    #[DataProvider('unpublishedStatuses')]
    public function testAnEventThatIsNotPublishedIsNotATarget(string $status): void
    {
        $database = new CandidateEventLookupDatabase();
        $database->resultRows = [['ID' => '4312', 'post_status' => $status]];
                // The double honours the statement's own status filter, so this row only
                // comes back if the SQL really asks for 'publish' and nothing else.
                $database->onlyForStatus = ['publish'];

                self::assertNull(
                    $this->repository($database)->findPublishedEventForCandidate(77),
                    'A ' . $status . ' event must not be promotable onto'
                );
    }

    /**
     * @return list<array{string}>
     */
    public static function unpublishedStatuses(): array
    {
        return [['draft'], ['pending'], ['private'], ['trash'], ['future'], ['auto-draft']];
    }

    public function testACandidateWithNoPublishedEventIsNull(): void
    {
        $database = new CandidateEventLookupDatabase();
        $database->resultRows = [];

        self::assertNull($this->repository($database)->findPublishedEventForCandidate(77));
    }

    /**
     * The query has to ask for the link it depends on. A statement that joined
     * something else would still return a row, and the wrong event would be
     * promoted onto.
     */
    public function testTheQueryLooksTheEventUpByItsOwnSourceCandidateMeta(): void
    {
        $database = new CandidateEventLookupDatabase();
        $database->resultRows = [['ID' => '4312', 'post_status' => 'publish']];

        $this->repository($database)->findPublishedEventForCandidate(77);

        $query = $database->lastQuery();

        self::assertStringContainsString('postmeta', $query, 'The candidate link lives in postmeta.');
        self::assertStringContainsString('source_candidate_id', $query);
        self::assertStringContainsString("post_type = 'adct_event'", $query);
        self::assertStringContainsString("post_status = 'publish'", $query);
                self::assertStringContainsString(
                    'CAST(77 AS CHAR)',
                    $query,
                    'The bound id has to reach the meta comparison.'
                );
            }

    /**
     * The one property that makes the rest safe: the id is a bound parameter, so
     * there is no way for a crafted value to change the meaning of the statement.
         *
         * Asserted on the *template*, not the interpolated statement. A hard-coded
         * `CAST(77 AS CHAR)` produces a byte-identical interpolated statement and an
         * identical `[77]` argument list, so only the template tells the two apart.
         */
        public function testTheCandidateIdIsBoundRatherThanInterpolated(): void
        {
            $database = new CandidateEventLookupDatabase();
            $database->resultRows = [['ID' => '4312', 'post_status' => 'publish']];

            $this->repository($database)->findPublishedEventForCandidate(77);

            self::assertSame([77], $database->boundValues, 'The id is bound as a value, not pasted into the SQL.');
            self::assertStringNotContainsString(
                '77',
                $database->templates[0],
                'The placeholder must survive into the statement the database receives.'
            );
            self::assertStringContainsString(
                'CAST(%d AS CHAR)',
                $database->templates[0],
                'Post meta holds text, so the id has to be bound as a string.'
            );
        }

    public function testACandidateIdThatIsNotPositiveIsRefusedRatherThanQueried(): void
    {
        $database = new CandidateEventLookupDatabase();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->repository($database)->findPublishedEventForCandidate(0);
        } finally {
            self::assertSame([], $database->queries, 'A refused id must not reach the database at all.');
        }
    }

    /**
     * The `post_status` filter does the work, but the row is checked too: a double
     * that answers with a draft row must not be able to talk this method into
     * returning it, and a media-library copy is not worth a wrong answer.
     */
    public function testARowThatSaysItIsNotPublishedIsRefusedEvenIfItComesBack(): void
    {
        $database = new CandidateEventLookupDatabase();
            // A double that ignores the WHERE clause and hands back a draft anyway.
            // The PHP-side re-check is the only thing standing between that and a
            // bulletin copied onto a page that was never published.
            $database->resultRows = [['ID' => '4312', 'post_status' => 'draft']];

            self::assertNull($this->repository($database)->findPublishedEventForCandidate(77));
        }

    /**
     * A candidate id of 77 and a row whose id is 0, negative or non-numeric must
     * all answer "no such event" rather than propagating 0 or throwing.
     */
    #[DataProvider('unusableIds')]
    public function testAnUnusableRowIdIsTreatedAsNoEvent(mixed $rawId): void
    {
        $database = new CandidateEventLookupDatabase();
        $database->resultRows = [['ID' => $rawId, 'post_status' => 'publish']];

        self::assertNull($this->repository($database)->findPublishedEventForCandidate(77));
    }

    /**
     * @return list<array{mixed}>
     */
    public static function unusableIds(): array
    {
        return [['0'], ['-3'], ['abc'], [''], [null]];
    }

    private function repository(CandidateEventLookupDatabase $database): ReviewQueueRepository
    {
        return new ReviewQueueRepository($database, new CandidateEventLookupClock());
    }
}

final class CandidateEventLookupClock implements \ADCT\ParishIntake\Core\Ports\ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-03-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));
    }
}

final class CandidateEventLookupDatabase implements DatabaseConnectionInterface
{
    /** @var list<string> */
    public array $queries = [];

        /**
         * The statements as `prepare()` received them, before any binding.
         *
         * Kept separately from {@see $queries} on purpose: an interpolated statement
         * looks identical whether the value was bound or hard-coded, so a test that
         * only reads the interpolated form cannot tell those two cases apart.
         *
         * @var list<string>
         */
        public array $templates = [];

    /** @var list<array<string, mixed>> */
    public array $resultRows = [];

    /**
     * Every bound value, in the order it was passed.
     *
     * @var list<mixed>
     */
    public array $boundValues = [];

        /**
         * Optional statuses the double will only serve once the *statement itself*
         * asks for them, mirroring a real `WHERE post_status = ...`.
         *
         * Without this, any row this double returns is returned whatever the query
         * says, which quietly makes a test of the SQL's own filtering meaningless.
         */
        public array $onlyForStatus = [];

        private string $error = '';

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
            $this->templates[] = $query;

            foreach ($arguments as $argument) {
            $this->boundValues[] = $argument;
        }

        // Interpolated the way $wpdb would, so the recorded statement is what the
        // database would actually receive.
        $this->queries[] = $arguments === []
            ? $query
            : vsprintf($query, array_map(
                static fn (mixed $value): string => is_int($value) || is_float($value)
                    ? (string) $value
                    : "'" . (is_bool($value) ? (int) $value : (string) $value) . "'",
                $arguments
            ));

        return $this->queries[count($this->queries) - 1];
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

            if ($this->onlyForStatus !== []) {
                $asked = [];
                foreach ($this->onlyForStatus as $status) {
                    if (str_contains($query, "post_status = '" . $status . "'")) {
                        $asked[] = $status;
                    }
                }
                if ($asked === []) {
                    return [];
                }

                return array_values(array_filter(
                    $this->resultRows,
                    static fn (array $row): bool => in_array((string) ($row['post_status'] ?? ''), $asked, true)
                ));
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
