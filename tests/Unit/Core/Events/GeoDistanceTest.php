<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\GeoDistance;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The distance calculation the listing sorts on, pinned down here rather than only inside a query.
 *
 * The reference figures below come from the same haversine formula evaluated at full precision, and
 * the two real-world pairs are the kind of distances this feature actually has to get right: across
 * a Cape Town suburb, and across the peninsula.
 */
final class GeoDistanceTest extends TestCase
{
    public function testADistanceToItselfIsZero(): void
    {
        self::assertSame(0.0, GeoDistance::between(-33.957000, 18.476000, -33.957000, 18.476000));
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float} $case
     */
    #[DataProvider('knownDistances')]
    public function testKnownDistances(float $fromLat, float $fromLng, float $toLat, float $toLng, float $expectedKm): void
    {
        $actual = GeoDistance::between($fromLat, $fromLng, $toLat, $toLng);

        /** 10 m of slack is far finer than the decimal(9,6) columns we store, and far too tight
         *  to be flaky: the largest error here is a rounding artefact, not a wrong formula. */
        self::assertEqualsWithDelta($expectedKm, $actual, 0.01);
    }

    /** @return array<string, array{0: float, 1: float, 2: float, 3: float, 4: float}> */
    public static function knownDistances(): array
    {
        return [
            'one degree of latitude' => [0.0, 0.0, 1.0, 0.0, 111.195080233532],
            'one degree at the equator' => [0.0, 0.0, 0.0, 1.0, 111.195080233532],
            // 0.014 degrees of longitude at Cape Town's latitude is about 1.29 km, plus 0.145 km
            // of latitude, so a hair over 1.3 km rather than the 1.38 an eyeball suggests.
            'across Rondebosch' => [-33.957000, 18.476000, -33.958300, 18.490000, 1.299298],
            // 0.4429 degrees of longitude near the Cape is about 40.8 km: a long drive, not 43.
            'Cape Town to Stellenbosch' => [-33.924900, 18.424100, -33.938000, 18.867000, 40.887535],
            'equator to pole' => [0.0, 0.0, 90.0, 0.0, GeoDistance::EARTH_RADIUS_KM * M_PI / 2],
            'across the antimeridian' => [0.0, 179.9, 0.0, -179.9, 22.239055],
            'due south' => [10.0, 20.0, -10.0, 20.0, 2223.901600],
        ];
    }

    public function testDistanceIsSymmetricAndReverseMatches(): void
    {
        $forward = GeoDistance::between(-33.957000, 18.476000, -33.938000, 18.867000);
        $backward = GeoDistance::between(-33.938000, 18.867000, -33.957000, 18.476000);

        self::assertEqualsWithDelta($forward, $backward, 1e-9);
    }

    public function testAntipodalPointsDoNotProduceANan(): void
    {
        $distance = GeoDistance::between(0.0, 0.0, 0.0, 180.0);

        self::assertIsFloat($distance);
        self::assertEqualsWithDelta(GeoDistance::EARTH_RADIUS_KM * M_PI, $distance, 1e-6);
    }

    public function testNearbyPointsAreOrderedByDistance(): void
    {
        /** The property the listing depends on: a nearer point always sorts first. */
        $visitorLat = -33.957000;
        $visitorLng = 18.476000;
        $near = GeoDistance::between($visitorLat, $visitorLng, -33.957100, 18.477000);
        $middle = GeoDistance::between($visitorLat, $visitorLng, -33.960000, 18.490000);
        $far = GeoDistance::between($visitorLat, $visitorLng, -33.990000, 18.550000);

        self::assertLessThan($middle, $near);
        self::assertLessThan($far, $middle);
    }

    /** @param array{0: float, 1: float} $point */
    #[DataProvider('offTheMapPoints')]
    public function testPointsOffTheMapAreRejected(float $latitude, float $longitude): void
    {
        $this->expectException(InvalidArgumentException::class);

        GeoDistance::between($latitude, $longitude, -33.9, 18.4);
    }

    /** @return array<string, array{0: float, 1: float}> */
    public static function offTheMapPoints(): array
    {
        return [
            'latitude past the north pole' => [90.001, 0.0],
            'latitude past the south pole' => [-91.0, 0.0],
            'longitude past the antimeridian' => [0.0, 180.5],
            'longitude below the antimeridian' => [0.0, -181.0],
            'not a number latitude' => [NAN, 0.0],
            'not a number longitude' => [0.0, NAN],
        ];
    }

    public function testTheOtherEndIsValidatedToo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GeoDistance::between(-33.9, 18.4, 95.0, 18.4);
    }

    public function testDistancesAreFormattedForAParishMember(): void
    {
        self::assertSame('0 m away', GeoDistance::formatKilometres(0.0));
        self::assertSame('340 m away', GeoDistance::formatKilometres(0.34));
        self::assertSame('999 m away', GeoDistance::formatKilometres(0.999));
        self::assertSame('1.0 km away', GeoDistance::formatKilometres(1.0));
        self::assertSame('3.2 km away', GeoDistance::formatKilometres(3.24));
        self::assertSame('43.4 km away', GeoDistance::formatKilometres(43.3995));
        self::assertSame('100.0 km away', GeoDistance::formatKilometres(100.0));
    }

    public function testMetresAreRoundedToTheNearestWholeMetre(): void
    {
        self::assertSame('1 m away', GeoDistance::formatKilometres(0.0014));
        self::assertSame('2 m away', GeoDistance::formatKilometres(0.0015));
    }

    public function testANegativeDistanceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GeoDistance::formatKilometres(-0.1);
    }
}