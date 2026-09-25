<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Events\ExpandedOccurrence;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\OccurrenceRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OccurrenceRepositoryTest extends TestCase
{
    public function testReplacementDeletesAndInsertsWithinOneTransaction(): void
    {
        $database = new RecordingOccurrenceDatabase();
        $repository = new OccurrenceRepository($database);

        $repository->replaceForEvent(
            17,
            [$this->occurrence('2026-10-02T09:00', '2026-10-02T10:00')],
            9,
            23,
            -33.9,
            18.4,
            false,
            new DateTimeImmutable('2026-09-25T08:00:00+02:00')
        );

        self::assertSame([
            'START TRANSACTION',
            'DELETE FROM wp_adct_pi_occurrences WHERE event_id = %d',
            'INSERT INTO wp_adct_pi_occurrences',
            'COMMIT',
        ], array_map(
            static fn (string $query): string => str_starts_with($query, 'INSERT INTO')
                ? 'INSERT INTO wp_adct_pi_occurrences'
                : $query,
            $database->executedQueries
        ));
        self::assertSame([17], $database->preparedQueries[0]['arguments']);
        self::assertSame([
            17,
            '2026-10-02 07:00:00',
            '2026-10-02 08:00:00',
            '2026-10-02',
            9,
            23,
            -33.9,
            18.4,
            0,
            '2026-09-25 06:00:00',
            '2026-09-25 06:00:00',
        ], $database->preparedQueries[1]['arguments']);
        self::assertStringContainsString('event_type_term_id', $database->preparedQueries[1]['query']);
        self::assertStringContainsString('latitude', $database->preparedQueries[1]['query']);
    }

    public function testReplacementStoresANullParishForArchdioceseWideEvents(): void
    {
        $database = new RecordingOccurrenceDatabase();
        $repository = new OccurrenceRepository($database);

        $repository->replaceForEvent(
            17,
            [$this->occurrence('2026-10-02T09:00', null)],
            null,
            null,
            null,
            null,
            false,
            new DateTimeImmutable('2026-09-25T08:00:00+02:00')
        );

        self::assertStringContainsString(
            'VALUES (%d, %s, NULL, %s, NULL, NULL, NULL, NULL, %d, %s, %s)',
            $database->preparedQueries[1]['query']
        );
        self::assertSame([
            17,
            '2026-10-02 07:00:00',
            '2026-10-02',
            0,
            '2026-09-25 06:00:00',
            '2026-09-25 06:00:00',
        ], $database->preparedQueries[1]['arguments']);
    }

    public function testReplacementRollsBackWhenAnInsertFails(): void
    {
        $database = new RecordingOccurrenceDatabase();
        $database->failureQueryPrefix = 'INSERT INTO wp_adct_pi_occurrences';
        $repository = new OccurrenceRepository($database);

        try {
            $repository->replaceForEvent(
                17,
                [$this->occurrence('2026-10-02T09:00', null)],
                9,
                23,
                -33.9,
                18.4,
                true,
                new DateTimeImmutable('2026-09-25T08:00:00+02:00')
            );
            self::fail('An occurrence insert failure must be reported.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('database insert failed', $failure->getMessage());
        }

        self::assertSame([
            'START TRANSACTION',
            'DELETE FROM wp_adct_pi_occurrences WHERE event_id = %d',
            'INSERT INTO wp_adct_pi_occurrences',
            'ROLLBACK',
        ], array_map(
            static fn (string $query): string => str_starts_with($query, 'INSERT INTO')
                ? 'INSERT INTO wp_adct_pi_occurrences'
                : $query,
            $database->executedQueries
        ));
    }

    public function testPublishedEventCursorUsesPreparedPostTypeStatusAndId(): void
    {
        $database = new RecordingOccurrenceDatabase();
        $database->rowResult = ['ID' => '25'];
        $repository = new OccurrenceRepository($database);

        self::assertSame(25, $repository->nextPublishedEventId(17));
        self::assertSame([
            'adct_event',
            'publish',
            17,
        ], $database->preparedQueries[0]['arguments']);
        self::assertStringContainsString('post_status = %s', $database->preparedQueries[0]['query']);
        self::assertStringContainsString('ID > %d', $database->preparedQueries[0]['query']);
    }

    public function testLocationUsesVenueCoordinatesAndParishFallback(): void
    {
        $database = new RecordingOccurrenceDatabase();
        $repository = new OccurrenceRepository($database);
        $database->rowResults = [
            [
                'venue_parish_id' => '9',
                'venue_latitude' => null,
                'venue_longitude' => null,
                'parish_latitude' => '-33.900000',
                'parish_longitude' => '18.400000',
            ],
            [
                'latitude' => '-33.910000',
                'longitude' => '18.410000',
            ],
        ];

        $venueLocation = $repository->locationForEvent(9, 19);
        $parishLocation = $repository->locationForEvent(9, null);

        self::assertSame([
            'latitude' => -33.9,
            'longitude' => 18.4,
        ], $venueLocation);
        self::assertSame([
            'latitude' => -33.91,
            'longitude' => 18.41,
        ], $parishLocation);
    }

    private function occurrence(string $startLocal, ?string $endLocal): ExpandedOccurrence
    {
        $timezone = new \DateTimeZone('Africa/Johannesburg');
        $start = new DateTimeImmutable($startLocal, $timezone);
        $end = $endLocal === null ? null : new DateTimeImmutable($endLocal, $timezone);

        return new ExpandedOccurrence($start, $end);
    }
}

final class RecordingOccurrenceDatabase implements DatabaseConnectionInterface
{
    /**
     * @var list<array{query: string, arguments: array<int, mixed>}>
     */
    public array $preparedQueries = [];

    /**
     * @var list<string>
     */
    public array $executedQueries = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $rowResult = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $rowResults = [];

    public ?string $failureQueryPrefix = null;
    public string $error = '';

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->preparedQueries[] = [
            'query' => $query,
            'arguments' => $arguments,
        ];

        return $query;
    }

    public function query(string $query): int|false
    {
        $this->executedQueries[] = $query;

        if ($this->failureQueryPrefix !== null && str_starts_with($query, $this->failureQueryPrefix)) {
            $this->error = 'database insert failed';

            return false;
        }

        return 1;
    }

    public function getRow(string $query): ?array
    {
        if ($this->rowResults !== []) {
            return array_shift($this->rowResults);
        }

        return $this->rowResult;
    }

    public function getResults(string $query): array
    {
        return [];
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
        return '';
    }

    public function clearLastError(): void
    {
        $this->error = '';
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
