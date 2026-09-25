<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Directory\Venue;

interface VenueLookupRepositoryInterface
{
    /**
     * @return list<Venue>
     */
    public function findActiveVenues(): array;
}
