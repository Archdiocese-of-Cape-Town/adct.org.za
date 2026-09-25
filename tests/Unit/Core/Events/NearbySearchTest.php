<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\NearbySearch;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NearbySearchTest extends TestCase
{
    public function testBrowserSearchAcceptsOnlyValidatedCoordinatesAndRadius(): void
    {
        $search = NearbySearch::fromRequest([
            'near_mode' => 'browser',
            'near_latitude' => -33.9258,
            'near_longitude' => 18.4232,
            'near_radius_km' => '25',
        ]);

        self::assertInstanceOf(NearbySearch::class, $search);
        self::assertSame('browser', $search->mode);
        self::assertSame(-33.9258, $search->latitude);
        self::assertSame(18.4232, $search->longitude);
        self::assertSame(25, $search->radiusKm);
        self::assertNull($search->suburb);
    }

    public function testSuburbSearchContainsNoCoordinates(): void
    {
        $search = new NearbySearch([
            'near_mode' => 'suburb',
            'near_suburb' => '  Exampleville  ',
            'near_radius_km' => 50,
        ]);

        self::assertSame('suburb', $search->mode);
        self::assertSame('Exampleville', $search->suburb);
        self::assertNull($search->latitude);
        self::assertNull($search->longitude);
        self::assertSame(50, $search->radiusKm);
    }

    public function testBrowserAndSuburbModesCannotBeCombined(): void
    {
        foreach ([
            [
                'near_mode' => 'browser',
                'near_latitude' => -33.9,
                'near_longitude' => 18.4,
                'near_suburb' => '',
            ],
            [
                'near_mode' => 'suburb',
                'near_suburb' => 'Exampleville',
                'near_latitude' => -33.9,
            ],
            ['near_radius_km' => 25],
            ['near_mode' => 'suburb', 'near_suburb' => ''],
        ] as $input) {
            $this->expectInvalidSearch($input);
        }
    }

    public function testLocationCoordinatesAndRadiusAreRangeChecked(): void
    {
        foreach ([
            ['near_mode' => 'browser', 'near_latitude' => -91, 'near_longitude' => 18],
            ['near_mode' => 'browser', 'near_latitude' => -33, 'near_longitude' => 181],
            ['near_mode' => 'browser', 'near_latitude' => NAN, 'near_longitude' => 18],
            ['near_mode' => 'suburb', 'near_suburb' => str_repeat('x', 65)],
            ['near_mode' => 'suburb', 'near_suburb' => 'Exampleville', 'near_radius_km' => 15],
        ] as $input) {
            $this->expectInvalidSearch($input);
        }
    }

    public function testLocationDataIsNeverPartOfShareableListingFilters(): void
    {
        self::assertNull(NearbySearch::fromRequest(['adct_period' => 'upcoming']));

        foreach ([
            ['adct_near_me' => 'browser'],
            ['near_latitude' => -33.9],
            ['near_suburb' => 'Exampleville'],
        ] as $input) {
            self::assertTrue(NearbySearch::hasLocationFields($input));
        }

        self::assertFalse(NearbySearch::hasLocationFields(['adct_period' => 'upcoming']));
    }

    /** @param array<string, mixed> $input */
    private function expectInvalidSearch(array $input): void
    {
        try {
            NearbySearch::fromRequest($input);
            self::fail('Invalid nearby search was accepted: ' . json_encode($input));
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
