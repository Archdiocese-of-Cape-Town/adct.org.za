<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\ListingSelection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListingSelectionTest extends TestCase
{
    public function testMultipleTypesAndDirectoryFiltersRoundTrip(): void
    {
        $selection = new ListingSelection([
            'adct_period' => 'range',
            'adct_from' => '2026-10-01',
            'adct_to' => '2026-10-31',
            'adct_page' => '2',
            'adct_types' => ['9', '3', '9'],
            'adct_parish' => '150',
            'adct_deanery' => '4',
        ]);

        self::assertSame([3, 9], $selection->types);
        self::assertSame(150, $selection->parish);
        self::assertSame(4, $selection->deanery);
        self::assertSame(2, $selection->page);
        self::assertSame([
            'adct_period' => 'range',
            'adct_from' => '2026-10-01',
            'adct_to' => '2026-10-31',
            'adct_types' => [3, 9],
            'adct_parish' => 150,
            'adct_deanery' => 4,
            'adct_page' => 2,
        ], $selection->query());
        self::assertSame($selection->query(), (new ListingSelection($selection->query()))->query());
    }

    public function testMalformedAndOversizedInputsAreRejected(): void
    {
        foreach ([
            ['adct_types' => '1'],
            ['adct_types' => [['1']]],
            ['adct_types' => range(1, 21)],
            ['adct_types' => ['0']],
            ['adct_types' => ['999999999999999999999']],
            ['adct_parish' => ['1']],
            ['adct_deanery' => '-1'],
            ['adct_page' => '101'],
            ['adct_period' => str_repeat('x', 100)],
            ['adct_collapse' => ['1']],
            ['adct_pin' => 'true'],
                        ['adct_lat' => '91'],
                        ['adct_lat' => '-33,9'],
                        ['adct_lng' => '180.5'],
                        ['adct_lat' => '1e6'],
                        ['adct_lat' => ['-33.9']],
                        ['adct_lat' => 'abc'],
                        ['adct_lat' => '-33.9'],
                        ['adct_lng' => '18.4'],
                        ['adct_radius_km' => '26'],
                        ['adct_radius_km' => '-5'],
                        ['adct_radius_km' => 'ten'],
                        ['adct_radius_km' => '1e3'],
                        ['adct_suburb' => str_repeat('x', 101)],
                        ['adct_suburb' => ['Claremont']],
                    ] as $input) {
            try {
                new ListingSelection($input);
                self::fail('Malformed selection was accepted: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOptionalPresentationModesSurvivePagination(): void
    {
        $selection = new ListingSelection(['adct_collapse' => '1', 'adct_pin' => '1']);

        self::assertTrue($selection->collapse);
        self::assertTrue($selection->pin);
        self::assertSame('1', $selection->query(2)['adct_collapse']);
        self::assertSame('1', $selection->query(2)['adct_pin']);
        self::assertFalse((new ListingSelection([]))->collapse);
        self::assertFalse((new ListingSelection([]))->pin);
    }

    public function testANearMePointTravelsInTheUrlSoPagingAndSharingKeepIt(): void
    {
        $selection = new ListingSelection([
            'adct_lat' => '-33.957000',
            'adct_lng' => '18.476000',
            'adct_radius_km' => '50',
        ]);

        self::assertTrue($selection->hasNearMePoint());
        self::assertEqualsWithDelta(-33.957000, $selection->latitude, 1e-9);
        self::assertEqualsWithDelta(18.476000, $selection->longitude, 1e-9);
        self::assertSame(50.0, $selection->radiusKm);
        self::assertSame([
            'adct_period' => 'upcoming',
            'adct_lat' => '-33.957',
            'adct_lng' => '18.476',
            'adct_radius_km' => '50',
        ], $selection->query());

        /** Paging must not drop the sort, or page two would silently go back to date order. */
        self::assertSame('-33.957', $selection->query(2)['adct_lat']);
        self::assertSame(2, $selection->query(2)['adct_page']);

        /** And the URL has to survive a round trip through the parser unchanged. */
        $again = new ListingSelection($selection->query());
        self::assertTrue($again->hasNearMePoint());
        self::assertSame($selection->query(), $again->query());
    }

    public function testASuburbIsKeptButIsNotItselfAPoint(): void
    {
        $selection = new ListingSelection(['adct_suburb' => '  Claremont  ']);

        self::assertFalse($selection->hasNearMePoint());
        self::assertSame('Claremont', $selection->suburb);
        self::assertNull($selection->latitude);
        self::assertNull($selection->longitude);
        self::assertNull($selection->radiusKm);
        self::assertSame([
            'adct_period' => 'upcoming',
            'adct_suburb' => 'Claremont',
        ], $selection->query());
    }

    public function testNoNearMeParametersMeansNoNearMePoint(): void
    {
        $selection = new ListingSelection([]);

        self::assertFalse($selection->hasNearMePoint());
        self::assertNull($selection->latitude);
        self::assertNull($selection->longitude);
        self::assertNull($selection->radiusKm);
        self::assertNull($selection->suburb);
        self::assertArrayNotHasKey('adct_lat', $selection->query());
        self::assertArrayNotHasKey('adct_suburb', $selection->query());
    }

    public function testCoordinatesAreFormattedWithoutTrailingZeroes(): void
    {
        self::assertSame('-33.957', ListingSelection::formatNumber(-33.957000));
        self::assertSame('18.476', ListingSelection::formatNumber(18.476));
        self::assertSame('0', ListingSelection::formatNumber(0.0));
        self::assertSame('25', ListingSelection::formatNumber(25.0));
    }
}
