<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\VenueStoreInterface;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

final class VenueAdministrationService
{
    private VenueDataValidator $validator;

    public function __construct(
        private VenueStoreInterface $venues,
        private ClockInterface $clock,
        ?VenueDataValidator $validator = null
    ) {
        $this->validator = $validator ?? new VenueDataValidator();
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(int $parishId, int $venueId, array $input): Venue
    {
        if ($parishId < 1 || $venueId < 0) {
            throw new InvalidArgumentException('A parish and a valid venue ID are required.');
        }

        $validation = $this->validator->validate($input);

        if ($validation->errors !== []) {
            throw new InvalidArgumentException(implode(' ', $validation->errors));
        }

        $current = $venueId > 0 ? $this->venues->findVenue($venueId) : null;

        if ($venueId > 0 && ($current === null || $current->parishId !== $parishId)) {
            throw new DomainException('The parish venue could not be found.');
        }

        $parishVenues = $this->venues->findForParish($parishId);
        $activeVenues = array_values(array_filter(
            $parishVenues,
            static fn (Venue $venue): bool => $venue->status === Venue::ACTIVE
        ));
        $activeDefaults = array_values(array_filter(
            $activeVenues,
            static fn (Venue $venue): bool => $venue->isDefault
        ));
        $status = $current?->status ?? Venue::ACTIVE;
        $isDefault = $validation->values['is_default'];

        if ($status !== Venue::ACTIVE && $isDefault) {
            throw new DomainException('An inactive venue cannot be the parish default.');
        }

        if ($current?->isDefault && ! $isDefault) {
            throw new DomainException('Select another active venue as the parish default venue before unsetting this one.');
        }

        if ($status === Venue::ACTIVE && ! $isDefault && $activeDefaults === []) {
            $isDefault = true;
        }

        if (! $isDefault && count($activeDefaults) > 1) {
            $this->venues->setDefaultForParish(
                $parishId,
                $activeDefaults[0]->id,
                $this->timestamp()
            );
        }

        $saved = $this->venues->saveVenue(
            new Venue(
                $venueId,
                $parishId,
                $validation->values['name'],
                $validation->values['aliases'],
                $validation->values['address'],
                $validation->values['suburb'],
                $validation->values['latitude'],
                $validation->values['longitude'],
                $isDefault,
                $status,
                $current?->sourceParishId
            ),
            $this->timestamp()
        );

        return $saved;
    }

    public function deactivate(int $parishId, int $venueId): void
    {
        $venue = $this->requireVenue($parishId, $venueId);

        if ($venue->status === Venue::INACTIVE) {
            return;
        }

        $active = array_values(array_filter(
            $this->venues->findForParish($parishId),
            static fn (Venue $record): bool => $record->status === Venue::ACTIVE
                && $record->id !== $venueId
        ));

        if ($active === []) {
            throw new DomainException('The last active venue cannot be deactivated.');
        }

        $defaults = array_values(array_filter(
            $active,
            static fn (Venue $record): bool => $record->isDefault
        ));
        $replacement = $defaults[0] ?? $active[0];

        $this->venues->deactivateVenue(
            $parishId,
            $venueId,
            $replacement->id,
            $this->timestamp()
        );
    }

    public function reactivate(int $parishId, int $venueId): void
    {
        $venue = $this->requireVenue($parishId, $venueId);

        if ($venue->status === Venue::ACTIVE) {
            return;
        }

        $active = array_values(array_filter(
            $this->venues->findForParish($parishId),
            static fn (Venue $record): bool => $record->status === Venue::ACTIVE
        ));
        $defaults = array_values(array_filter(
            $active,
            static fn (Venue $record): bool => $record->isDefault
        ));

        if (count($defaults) > 1) {
            $this->venues->setDefaultForParish($parishId, $defaults[0]->id, $this->timestamp());
        }

        $this->venues->reactivateVenue(
            $parishId,
            $venueId,
            $defaults === [],
            $this->timestamp()
        );
    }

    private function requireVenue(int $parishId, int $venueId): Venue
    {
        if ($parishId < 1 || $venueId < 1) {
            throw new InvalidArgumentException('A parish and venue ID are required.');
        }

        $venue = $this->venues->findVenue($venueId);

        if ($venue === null || $venue->parishId !== $parishId) {
            throw new DomainException('The parish venue could not be found.');
        }

        return $venue;
    }

    private function timestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
