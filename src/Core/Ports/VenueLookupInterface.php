<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Directory\VenueMatch;

interface VenueLookupInterface
{
    public function match(string $text, ?int $parishId = null): ?VenueMatch;

    public function defaultVenueFor(int $parishId): ?VenueMatch;
}
