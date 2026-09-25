<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class VenueLookupResult
{
    /**
     * @param list<string> $notes
     */
    public function __construct(
        public readonly ?VenueMatch $match,
        public readonly array $notes = []
    ) {
    }
}
