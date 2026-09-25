<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class VenueMatch
{
    public function __construct(
        public readonly int $venueId,
        public readonly int $parishId,
        public readonly string $name,
        public readonly ?string $address,
        public readonly ?string $suburb,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly string $matchedAs
    ) {
    }
}
