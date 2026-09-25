<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use InvalidArgumentException;

final class GeoDistance
{
    public const EARTH_RADIUS_KM = 6371.0088;

    public static function kilometresBetween(
        float $latitudeA,
        float $longitudeA,
        float $latitudeB,
        float $longitudeB
    ): float {
        self::assertCoordinate($latitudeA, $longitudeA);
        self::assertCoordinate($latitudeB, $longitudeB);

        $latitudeARadians = deg2rad($latitudeA);
        $latitudeBRadians = deg2rad($latitudeB);
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($latitudeARadians) * cos($latitudeBRadians) * sin($longitudeDelta / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(sqrt(min(1.0, max(0.0, $haversine))));
    }

    /**
     * @return array{
     *     min_latitude: float,
     *     max_latitude: float,
     *     min_longitude: float,
     *     max_longitude: float,
     *     wraps_longitude: bool,
     *     all_longitudes: bool
     * }
     */
    public static function boundingBox(float $latitude, float $longitude, float $radiusKm): array
    {
        self::assertCoordinate($latitude, $longitude);
        if (! is_finite($radiusKm) || $radiusKm <= 0 || $radiusKm > 100.0) {
            throw new InvalidArgumentException('The nearby radius is outside the supported range.');
        }

        $angularRadius = $radiusKm / self::EARTH_RADIUS_KM;
        $latitudeRadians = deg2rad($latitude);
        $minimumLatitudeRadians = max(-M_PI / 2, $latitudeRadians - $angularRadius);
        $maximumLatitudeRadians = min(M_PI / 2, $latitudeRadians + $angularRadius);
        $minimumLongitude = -180.0;
        $maximumLongitude = 180.0;
        $wrapsLongitude = false;
        $allLongitudes = $minimumLatitudeRadians <= -M_PI / 2
            || $maximumLatitudeRadians >= M_PI / 2
            || abs(cos($latitudeRadians)) < 1.0e-15;

        if (! $allLongitudes) {
            $longitudeDelta = rad2deg(asin(min(1.0, sin($angularRadius) / cos($latitudeRadians))));
            $minimumLongitude = $longitude - $longitudeDelta;
            $maximumLongitude = $longitude + $longitudeDelta;

            if ($minimumLongitude < -180.0) {
                $minimumLongitude += 360.0;
                $wrapsLongitude = true;
            } elseif ($maximumLongitude > 180.0) {
                $maximumLongitude -= 360.0;
                $wrapsLongitude = true;
            }
        }

        return [
            'min_latitude' => rad2deg($minimumLatitudeRadians),
            'max_latitude' => rad2deg($maximumLatitudeRadians),
            'min_longitude' => $minimumLongitude,
            'max_longitude' => $maximumLongitude,
            'wraps_longitude' => $wrapsLongitude,
            'all_longitudes' => $allLongitudes,
        ];
    }

    private static function assertCoordinate(float $latitude, float $longitude): void
    {
        if (
            ! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180
        ) {
            throw new InvalidArgumentException('Coordinates are outside the supported range.');
        }
    }
}
