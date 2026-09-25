<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use ADCT\ParishIntake\Core\Ports\VenueLookupRepositoryInterface;

final class SnapshotVenueLookupRepository implements VenueLookupRepositoryInterface
{
    /**
     * @param list<Venue> $venues
     */
    public function __construct(private array $venues)
    {
    }

    public function findActiveVenues(): array
    {
        return array_values(array_filter(
            $this->venues,
            static fn (Venue $venue): bool => $venue->status === Venue::ACTIVE
        ));
    }
}
