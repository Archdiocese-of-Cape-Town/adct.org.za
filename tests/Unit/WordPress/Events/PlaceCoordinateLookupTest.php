<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Events;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Events\PlaceCoordinateLookup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The suburb lookup is the only place a typed suburb becomes a point, so what the query asks the
 * database for is pinned here: the name columns it reads, the cap it puts on the result, and the
 * rows it refuses.
 */
final class PlaceCoordinateLookupTest extends TestCase
{
    public function testEveryNameColumnAPersonMightUseIsRead(): void
    {
        $database = new SuburbLookupDatabase();
        $database->rows = [
            ['name' => 'St Mary Claremont', 'latitude' => '-33.957000', 'longitude' => '18.476000'],
            ['name' => 'Claremont', 'latitude' => '-33.953000', 'longitude' => '18.470000'],
        ];

        $places = (new PlaceCoordinateLookup($database))->placesWithCoordinates(100);

        $query = $database->preparedQueries[0]['query'];

        self::assertStringContainsString('FROM wp_adct_pi_parishes', $query);
        self::assertStringContainsString('FROM wp_adct_pi_venues', $query);

        /** Parish name, area and suburb, plus venue name and suburb: five ways of naming a place. */
        foreach (['name', 'area', 'suburb'] as $column) {
            self::assertMatchesRegularExpression(
                '/SELECT id, ' . $column . ', latitude, longitude FROM/',
                $query,
                'The parish ' . $column . ' column was never read'
            );
        }
        self::assertSame(4, substr_count($query, 'UNION ALL'));

        /** A retired parish must not put a visitor in the wrong place. */
        self::assertStringContainsString("status = 'active'", $query);

        /** No pin, no match: a parish without coordinates cannot place anybody. */
        self::assertStringContainsString('latitude IS NOT NULL', $query);
        self::assertStringContainsString('longitude IS NOT NULL', $query);

        self::assertCount(2, $places);
        self::assertSame('St Mary Claremont', $places[0]->name);
        self::assertSame(-33.957000, $places[0]->latitude);
        self::assertSame(18.476000, $places[0]->longitude);
    }

    public function testTheTypedSuburbIsNeverPutIntoTheQuery(): void
    {
        $database = new SuburbLookupDatabase();

        /**
         * The adapter takes no suburb at all, which is the guarantee: there is nowhere to inject
         * one, so nothing a visitor types can reach the SQL.
         */
        self::assertSame([], (new PlaceCoordinateLookup($database))->placesWithCoordinates(10));
        self::assertSame([], (new PlaceCoordinateLookup($database))->suburbNames(10));

        foreach ($database->preparedQueries as $prepared) {
            self::assertMatchesRegularExpression(
                '/LIMIT %d/',
                $prepared['query'],
                'The row cap has to be prepared, not interpolated'
            );
            self::assertCount(1, $prepared['arguments']);
            self::assertIsInt($prepared['arguments'][0]);
        }
    }

    public function testTheRowCapIsBoundedSoARequestCannotReadTheWholeTable(): void
    {
        $database = new SuburbLookupDatabase();

        foreach ([1, 500, 1000000, -1, 0] as $limit) {
            $database->preparedQueries = [];
            (new PlaceCoordinateLookup($database))->placesWithCoordinates($limit);

            $cap = $database->preparedQueries[0]['arguments'][0];

            self::assertGreaterThanOrEqual(1, $cap, 'A cap of zero would return nothing at all');
            self::assertLessThanOrEqual(PlaceCoordinateLookup::HARD_LIMIT, $cap);
        }
    }

    public function testRowsThatCannotPlaceSomebodyAreSkippedRatherThanFatal(): void
    {
        $database = new SuburbLookupDatabase();
        $database->rows = [
            ['name' => 'Bishopscourt', 'latitude' => '-33.917000', 'longitude' => '18.447000'],
            ['name' => 'Off The Map', 'latitude' => '91.000000', 'longitude' => '18.447000'],
            ['name' => '', 'latitude' => '-33.917000', 'longitude' => '18.447000'],
            ['name' => 'No Pin', 'latitude' => null, 'longitude' => '18.447000'],
            ['name' => 'Not A Number', 'latitude' => 'north', 'longitude' => '18.447000'],
            ['name' => 'Missing Column', 'latitude' => '-33.917000'],
            'not an array',
        ];

        $places = (new PlaceCoordinateLookup($database))->placesWithCoordinates(100);

        self::assertCount(1, $places);
        self::assertSame('Bishopscourt', $places[0]->name);
    }

    public function testSuburbNamesAreTrimmedAndBlanksDropped(): void
    {
        $database = new SuburbLookupDatabase();
        $database->rows = [
            ['name' => 'Bishopscourt'],
            ['name' => '  Claremont  '],
            ['name' => '   '],
            ['name' => ''],
            ['name' => null],
        ];

        self::assertSame(['Bishopscourt', 'Claremont'], (new PlaceCoordinateLookup($database))->suburbNames(100));
        self::assertStringContainsString('SELECT DISTINCT', $database->preparedQueries[0]['query']);
    }

    public function testADatabaseErrorIsRaisedRatherThanSilentlyShowingNothing(): void
    {
        $database = new SuburbLookupDatabase();
        $database->error = 'table does not exist';

        $this->expectException(RuntimeException::class);

        (new PlaceCoordinateLookup($database))->placesWithCoordinates(100);
    }

    public function testADatabaseErrorIsRaisedFromTheNameListToo(): void
    {
        $database = new SuburbLookupDatabase();
        $database->error = 'table does not exist';

        $this->expectException(RuntimeException::class);

        (new PlaceCoordinateLookup($database))->suburbNames(100);
    }
}

final class SuburbLookupDatabase implements DatabaseConnectionInterface
{
    /** @var list<mixed> */
    public array $rows = [];

    public string $error = '';

    /** @var list<array{query: string, arguments: array<int, mixed>}> */
    public array $preparedQueries = [];

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
        return 1;
    }

    public function getRow(string $query): ?array
    {
        return null;
    }

    public function getResults(string $query): array
    {
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
        return 'utf8mb4_unicode_ci';
    }

    public function clearLastError(): void
    {
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
