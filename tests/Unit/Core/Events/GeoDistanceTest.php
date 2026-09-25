<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Events;

use ADCT\ParishIntake\Core\Events\GeoDistance;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GeoDistanceTest extends TestCase
{
    public function testIdenticalPointsHaveZeroDistance(): void
    {
        self::assertSame(0.0, GeoDistance::kilometresBetween(-33.9258, 18.4232, -33.9258, 18.4232));
    }

    public function testOneDegreeAlongTheEquatorIsAboutOneHundredElevenKilometres(): void
    {
        self::assertEqualsWithDelta(
            111.1951,
            GeoDistance::kilometresBetween(0.0, 0.0, 0.0, 1.0),
            0.001
        );
    }

    public function testHaversineDistanceHandlesTheInternationalDateLine(): void
    {
        self::assertEqualsWithDelta(
            22.239,
            GeoDistance::kilometresBetween(0.0, 179.9, 0.0, -179.9),
            0.01
        );
    }

    public function testBoundingBoxWrapsAtTheDateLineAndCoversEveryLongitudeNearAPole(): void
    {
        $dateLine = GeoDistance::boundingBox(0.0, 179.99, 5.0);
        self::assertTrue($dateLine['wraps_longitude']);
        self::assertGreaterThan($dateLine['max_longitude'], $dateLine['min_longitude']);

        $polar = GeoDistance::boundingBox(89.99, 0.0, 5.0);
        self::assertTrue($polar['all_longitudes']);
    }

    public function testInvalidCoordinatesAndRadiiAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoDistance::kilometresBetween(91.0, 0.0, 0.0, 0.0);
    }

    public function testUnsupportedBoundingBoxRadiusIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoDistance::boundingBox(0.0, 0.0, 101.0);
    }
}
