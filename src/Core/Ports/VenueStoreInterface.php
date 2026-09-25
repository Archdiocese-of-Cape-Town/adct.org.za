<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Directory\Venue;

interface VenueStoreInterface extends VenueLookupRepositoryInterface
{
    /**
     * @return list<Venue>
     */
    public function findForParish(int $parishId): array;

    public function findVenue(int $venueId): ?Venue;

    public function saveVenue(Venue $venue, string $timestamp): Venue;

    public function setDefaultForParish(int $parishId, int $venueId, string $timestamp): void;

    public function deactivateVenue(
        int $parishId,
        int $venueId,
        ?int $replacementDefaultVenueId,
        string $timestamp
    ): void;

    public function reactivateVenue(
        int $parishId,
        int $venueId,
        bool $makeDefault,
        string $timestamp
    ): void;

    public function ensureDefaultVenue(Venue $venue, string $timestamp): bool;

    public function insertImportedVenue(Venue $venue, string $timestamp): bool;
}
