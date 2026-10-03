<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use InvalidArgumentException;

/**
 * A place we already hold coordinates for: a parish, its area or suburb, or a venue.
 *
 * Every one of the archdiocese's parishes ships with a pin in `data/seed/parishes.csv`, and venues
 * may carry one too, so a visitor who types a suburb can be placed without paying anyone for a
 * geocoding lookup. Several places share a suburb name; this class carries one of them, and
 * SuburbResolver works out the middle of the group.
 */
final class KnownPlace
{
    public readonly string $name;

    public function __construct(string $name, public readonly float $latitude, public readonly float $longitude)
    {
        $trimmed = trim($name);

        if ($trimmed === '' || strlen($trimmed) > 191) {
            throw new InvalidArgumentException('Invalid place name.');
        }
        if (is_nan($latitude) || $latitude < -GeoDistance::MAX_LATITUDE || $latitude > GeoDistance::MAX_LATITUDE) {
            throw new InvalidArgumentException('Invalid place latitude.');
        }
        if (is_nan($longitude) || $longitude < -GeoDistance::MAX_LONGITUDE || $longitude > GeoDistance::MAX_LONGITUDE) {
            throw new InvalidArgumentException('Invalid place longitude.');
        }

        $this->name = $trimmed;
    }
}