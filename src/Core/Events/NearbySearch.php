<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use InvalidArgumentException;

final class NearbySearch
{
    public const RADII_KM = [5, 10, 25, 50, 100];

    private const REQUEST_KEYS = [
        'near_mode',
        'near_radius_km',
        'near_suburb',
        'near_latitude',
        'near_longitude',
    ];

    private const LEGACY_REQUEST_KEYS = [
        'adct_near_me',
        'adct_near_me_radius',
        'adct_near_me_suburb',
        'adct_near_me_lat',
        'adct_near_me_lng',
    ];

    public readonly string $mode;
    public readonly int $radiusKm;
    public readonly ?float $latitude;
    public readonly ?float $longitude;
    public readonly ?string $suburb;

    /** @param array<string, mixed> $input */
    public function __construct(array $input)
    {
        $mode = $input['near_mode'] ?? null;
        if (! is_string($mode) || ! in_array($mode, ['browser', 'suburb'], true)) {
            throw new InvalidArgumentException('Choose a valid nearby location.');
        }

        $radius = $input['near_radius_km'] ?? 25;
        if (
            (! is_string($radius) && ! is_int($radius))
            || ! in_array((int) $radius, self::RADII_KM, true)
            || (string) (int) $radius !== (string) $radius
        ) {
            throw new InvalidArgumentException('Choose a valid nearby radius.');
        }
        $this->radiusKm = (int) $radius;
        $this->mode = $mode;

        $hasLatitude = array_key_exists('near_latitude', $input);
        $hasLongitude = array_key_exists('near_longitude', $input);
        $hasSuburb = array_key_exists('near_suburb', $input);

        if ($mode === 'browser') {
            if ($hasSuburb || ! $hasLatitude || ! $hasLongitude) {
                throw new InvalidArgumentException('Choose one nearby location.');
            }
            $this->latitude = self::coordinate($input['near_latitude'], -90.0, 90.0);
            $this->longitude = self::coordinate($input['near_longitude'], -180.0, 180.0);
            $this->suburb = null;

            return;
        }

        if ($hasLatitude || $hasLongitude || ! $hasSuburb) {
            throw new InvalidArgumentException('Choose one nearby location.');
        }
        $suburb = $input['near_suburb'];
        if (! is_string($suburb) || strlen($suburb) > 64 || trim($suburb) === '') {
            throw new InvalidArgumentException('Choose a suburb or parish from the list.');
        }
        $this->latitude = null;
        $this->longitude = null;
        $this->suburb = trim($suburb);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromRequest(array $input): ?self
    {
        if (array_intersect(self::LEGACY_REQUEST_KEYS, array_keys($input)) !== []) {
            throw new InvalidArgumentException('Use the nearby controls to submit a location.');
        }

        if (array_intersect(self::REQUEST_KEYS, array_keys($input)) === []) {
            return null;
        }

        return new self($input);
    }

    /** @param array<string, mixed> $input */
    public static function hasLocationFields(array $input): bool
    {
        return array_intersect(
            array_merge(self::REQUEST_KEYS, self::LEGACY_REQUEST_KEYS),
            array_keys($input)
        ) !== [];
    }

    private static function coordinate(mixed $value, float $minimum, float $maximum): float
    {
        if (
            (! is_string($value) && ! is_int($value) && ! is_float($value))
            || (is_string($value) && (strlen($value) > 32 || trim($value) !== $value))
            || ! is_numeric($value)
        ) {
            throw new InvalidArgumentException('Choose a valid nearby location.');
        }

        $coordinate = (float) $value;
        if (! is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
            throw new InvalidArgumentException('Choose a valid nearby location.');
        }

        return $coordinate;
    }
}
