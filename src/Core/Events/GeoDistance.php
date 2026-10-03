<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use InvalidArgumentException;

/**
 * Great-circle distance between two points on the earth's surface.
 *
 * The listing sorts events by how far the visitor is from them, and the same calculation runs in
 * the database so that a whole page of results can be ordered at once. Keeping a PHP copy here
 * means the arithmetic the tests pin down is the same arithmetic that describes the SQL.
 *
 * The haversine formula is numerically stable for the short distances a suburb listing deals
 * in, which is what makes it a better choice than the law-of-cosines shortcut. Pure maths, so it
 * is unit-testable without WordPress or a database.
 */
final class GeoDistance
{
    /** Mean earth radius in kilometres (the IUGG mean radius R1). */
    public const EARTH_RADIUS_KM = 6371.0088;

    /** The two poles and the antimeridian are the extremes any coordinate can reach. */
    public const MAX_LATITUDE = 90.0;
    public const MAX_LONGITUDE = 180.0;

    /** Radians in one degree; kept as a constant so the conversions below read as the formula they are. */
    private const DEGREES_TO_RADIANS = M_PI / 180.0;

    /**
     * @param float $latitude  Degrees north of the equator, -90 to 90.
     * @param float $longitude Degrees east of the prime meridian, -180 to 180.
     */
    public static function between(float $latitude, float $longitude, float $otherLatitude, float $otherLongitude): float
    {
        self::assertLatitude($latitude);
        self::assertLongitude($longitude);
        self::assertLatitude($otherLatitude);
        self::assertLongitude($otherLongitude);

        $lat1 = $latitude * self::DEGREES_TO_RADIANS;
        $lat2 = $otherLatitude * self::DEGREES_TO_RADIANS;
        $deltaLat = ($otherLatitude - $latitude) * self::DEGREES_TO_RADIANS;
        $deltaLon = ($otherLongitude - $longitude) * self::DEGREES_TO_RADIANS;

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        /** Floating-point error can push a haversine term a hair above 1 for antipodal points. */
        $a = min(1.0, max(0.0, $a));

        return self::EARTH_RADIUS_KM * 2 * asin(sqrt($a));
    }

    /**
     * "3.2 km away" reads better than a runaway decimal, and 0.1 km is about 100 metres, which is
     * well inside the accuracy of the coordinates we hold.
     */
    public static function formatKilometres(float $kilometres): string
    {
        if ($kilometres < 0.0) {
            throw new InvalidArgumentException('Distance cannot be negative.');
        }

        if ($kilometres < 1.0) {
            return round($kilometres * 1000) . ' m away';
        }

        return number_format($kilometres, 1) . ' km away';
    }

    private static function assertLatitude(float $value): void
    {
        if (is_nan($value) || $value < -self::MAX_LATITUDE || $value > self::MAX_LATITUDE) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }
    }

    private static function assertLongitude(float $value): void
    {
        if (is_nan($value) || $value < -self::MAX_LONGITUDE || $value > self::MAX_LONGITUDE) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
    }
}