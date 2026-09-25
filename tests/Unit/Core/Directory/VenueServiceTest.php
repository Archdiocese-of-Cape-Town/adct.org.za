<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Directory;

use ADCT\ParishIntake\Core\Directory\Venue;
use ADCT\ParishIntake\Core\Directory\VenueAdministrationService;
use ADCT\ParishIntake\Core\Directory\VenueDataValidator;
use ADCT\ParishIntake\Core\Directory\VenueDirectoryImporter;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\VenueLookupRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\VenueStoreInterface;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VenueServiceTest extends TestCase
{
    public function testCreatingTheFirstVenueMakesItDefaultAndSelectingAnotherUnsetsIt(): void
    {
        $store = new InMemoryVenueStore();
        $service = new VenueAdministrationService($store, new FixedVenueClock());

        $first = $service->save(3, 0, ['name' => 'Main Church']);
        $second = $service->save(3, 0, [
            'name' => 'Community Hall',
            'is_default' => '1',
        ]);

        self::assertTrue($first->isDefault);
        self::assertTrue($second->isDefault);
        self::assertFalse($store->findVenue($first->id)->isDefault);
        self::assertSame(1, $this->activeDefaultCount($store, 3));
    }

    public function testCoordinatesAreOptionalButMustBeWithinTheirValidRanges(): void
    {
        $validator = new VenueDataValidator();

        $valid = $validator->validate(['name' => 'Main Church']);
        $invalid = $validator->validate([
            'name' => 'Main Church',
            'latitude' => '90.000001',
            'longitude' => '-180.000001',
        ]);

        self::assertSame([], $valid->errors);
        self::assertNull($valid->values['latitude']);
        self::assertNull($valid->values['longitude']);
        self::assertCount(2, $invalid->errors);
    }

    public function testAliasInputAcceptsLinesAndCommasAndNormalizesDuplicates(): void
    {
        $validation = (new VenueDataValidator())->validate([
            'name' => 'Main Church',
            'aliases' => " St Mary's Hall, \nSaint Marys Hall\nCommunity Room, ",
        ]);

        self::assertSame([], $validation->errors);
        self::assertSame(
            ["St Mary's Hall", 'Community Room'],
            $validation->values['aliases']
        );
    }

    public function testDeactivatingTheDefaultPromotesAnotherActiveVenue(): void
    {
        $store = new InMemoryVenueStore();
        $service = new VenueAdministrationService($store, new FixedVenueClock());
        $default = $service->save(5, 0, ['name' => 'Main Church']);
        $other = $service->save(5, 0, ['name' => 'Community Hall']);

        $service->deactivate(5, $default->id);

        self::assertSame(Venue::INACTIVE, $store->findVenue($default->id)->status);
        self::assertTrue($store->findVenue($other->id)->isDefault);
        self::assertSame(1, $this->activeDefaultCount($store, 5));
    }

    public function testLastActiveDefaultCannotBeDeactivatedOrUnset(): void
    {
        $store = new InMemoryVenueStore();
        $service = new VenueAdministrationService($store, new FixedVenueClock());
        $default = $service->save(6, 0, ['name' => 'Main Church']);

        try {
            $service->deactivate(6, $default->id);
            self::fail('The last active venue was deactivated.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('last active venue', strtolower($failure->getMessage()));
        }

        try {
            $service->save(6, $default->id, [
                'name' => 'Main Church',
                'is_default' => '0',
            ]);
            self::fail('The parish was left without a default venue.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('default venue', strtolower($failure->getMessage()));
        }

        self::assertSame(1, $this->activeDefaultCount($store, 6));
    }

    public function testOutstationImportCreatesParentVenueAndDefaultsOnlyOnce(): void
    {
        $store = new InMemoryVenueStore();
        $clock = new FixedVenueClock();
        $importer = new VenueDirectoryImporter($store, $clock);
        $parishes = [
            [
                'id' => 10,
                'slug' => 'sample-parish',
                'name' => 'Sample Parish',
                'church' => "St Mary's",
                'kind' => 'parish',
                'parent_parish_id' => null,
                'address' => '1 Example Road',
                'suburb' => 'Sample Suburb',
                'latitude' => '-33.9',
                'longitude' => '18.4',
                'status' => 'active',
            ],
            [
                'id' => 11,
                'slug' => 'sample-outstation',
                'name' => 'Sample Area: St Mark',
                'church' => 'St Mark',
                'kind' => 'outstation',
                'parent_parish_id' => 10,
                'address' => '2 Example Road',
                'suburb' => 'Sample Area',
                'latitude' => '-33.8',
                'longitude' => '18.5',
                'status' => 'active',
            ],
            [
                'id' => 12,
                'slug' => 'sample-mass-centre',
                'name' => 'Sample Mass Centre',
                'church' => '',
                'kind' => 'mass_centre',
                'parent_parish_id' => null,
                'address' => null,
                'suburb' => null,
                'latitude' => null,
                'longitude' => null,
                'status' => 'active',
            ],
        ];
        $originalParishes = $parishes;

        $firstCreated = $importer->import($parishes);
        $secondCreated = $importer->import($parishes);

        self::assertSame(4, $firstCreated);
        self::assertSame(0, $secondCreated);
        self::assertSame($originalParishes, $parishes);
        self::assertCount(2, $store->findForParish(10));
        self::assertCount(1, $store->findForParish(11));
        self::assertCount(1, $store->findForParish(12));

        $outstationVenue = $store->findImportedFromParish(11);
        self::assertNotNull($outstationVenue);
        self::assertSame(10, $outstationVenue->parishId);
        self::assertSame('St Mark', $outstationVenue->name);
        self::assertContains('Sample Area: St Mark', $outstationVenue->aliases);
        self::assertSame(-33.8, $outstationVenue->latitude);
        self::assertTrue($this->isDefault($store->findForParish(12)));
    }

    /**
     * @param list<Venue> $venues
     */
    private function isDefault(array $venues): bool
    {
        foreach ($venues as $venue) {
            if ($venue->isDefault) {
                return true;
            }
        }

        return false;
    }

    private function activeDefaultCount(InMemoryVenueStore $store, int $parishId): int
    {
        return count(array_filter(
            $store->findForParish($parishId),
            static fn (Venue $venue): bool => $venue->status === Venue::ACTIVE && $venue->isDefault
        ));
    }
}

final class FixedVenueClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-25T01:00:00+00:00');
    }
}

final class InMemoryVenueStore implements VenueStoreInterface
{
    /**
     * @var array<int, Venue>
     */
    private array $venues = [];

    private int $nextId = 1;

    public function findActiveVenues(): array
    {
        return array_values(array_filter(
            $this->venues,
            static fn (Venue $venue): bool => $venue->status === Venue::ACTIVE
        ));
    }

    public function findForParish(int $parishId): array
    {
        return array_values(array_filter(
            $this->venues,
            static fn (Venue $venue): bool => $venue->parishId === $parishId
        ));
    }

    public function findVenue(int $venueId): ?Venue
    {
        return $this->venues[$venueId] ?? null;
    }

    public function findImportedFromParish(int $sourceParishId): ?Venue
    {
        foreach ($this->venues as $venue) {
            if ($venue->sourceParishId === $sourceParishId) {
                return $venue;
            }
        }

        return null;
    }

    public function saveVenue(Venue $venue, string $timestamp): Venue
    {
        $id = $venue->id > 0 ? $venue->id : $this->nextId++;
        $saved = $this->copy($venue, id: $id);
        $this->venues[$id] = $saved;

        if ($venue->isDefault) {
            $this->setDefaultForParish($venue->parishId, $id, $timestamp);
        }

        return $this->venues[$id];
    }

    public function setDefaultForParish(
        int $parishId,
        int $venueId,
        string $timestamp,
        bool $requireExisting = true
    ): void {
        $target = $this->venues[$venueId] ?? null;

        if (
            ! $target instanceof Venue
            || $target->parishId !== $parishId
            || $target->status !== Venue::ACTIVE
        ) {
            if ($requireExisting) {
                throw new InvalidArgumentException('The venue cannot be selected as the parish default.');
            }

            return;
        }

        foreach ($this->venues as $id => $venue) {
            if ($venue->parishId === $parishId) {
                $this->venues[$id] = $this->copy($venue, isDefault: $id === $venueId);
            }
        }
    }

    public function deactivateVenue(
        int $parishId,
        int $venueId,
        ?int $replacementDefaultVenueId,
        string $timestamp
    ): void {
        $venue = $this->venues[$venueId] ?? null;

        if (! $venue instanceof Venue || $venue->parishId !== $parishId) {
            throw new DomainException('The parish venue could not be found.');
        }

        $this->venues[$venueId] = $this->copy(
            $venue,
            status: Venue::INACTIVE,
            isDefault: false
        );

        if ($replacementDefaultVenueId !== null) {
            $this->setDefaultForParish($parishId, $replacementDefaultVenueId, $timestamp);
        }
    }

    public function reactivateVenue(
        int $parishId,
        int $venueId,
        bool $makeDefault,
        string $timestamp
    ): void {
        $venue = $this->venues[$venueId] ?? null;

        if (! $venue instanceof Venue || $venue->parishId !== $parishId) {
            throw new DomainException('The parish venue could not be found.');
        }

        $this->venues[$venueId] = $this->copy(
            $venue,
            status: Venue::ACTIVE,
            isDefault: false
        );

        if ($makeDefault) {
            $this->setDefaultForParish($parishId, $venueId, $timestamp);
        }
    }

    public function ensureDefaultVenue(Venue $venue, string $timestamp): bool
    {
        $existing = $this->findForParish($venue->parishId);

        if ($existing === []) {
            $this->saveVenue($this->copy($venue, isDefault: true), $timestamp);

            return true;
        }

        $active = array_values(array_filter(
            $existing,
            static fn (Venue $record): bool => $record->status === Venue::ACTIVE
        ));
        $defaults = array_values(array_filter(
            $active,
            static fn (Venue $record): bool => $record->isDefault
        ));

        if (count($defaults) === 1) {
            return false;
        }

        if ($active === []) {
            return false;
        }

        $this->setDefaultForParish($venue->parishId, $defaults[0]->id ?? $active[0]->id, $timestamp);

        return true;
    }

    public function insertImportedVenue(Venue $venue, string $timestamp): bool
    {
        if ($venue->sourceParishId === null || $this->findImportedFromParish($venue->sourceParishId) !== null) {
            return false;
        }

        $this->saveVenue($venue, $timestamp);

        return true;
    }

    private function copy(
        Venue $venue,
        ?int $id = null,
        ?bool $isDefault = null,
        ?string $status = null
    ): Venue {
        return new Venue(
            $id ?? $venue->id,
            $venue->parishId,
            $venue->name,
            $venue->aliases,
            $venue->address,
            $venue->suburb,
            $venue->latitude,
            $venue->longitude,
            $isDefault ?? $venue->isDefault,
            $status ?? $venue->status,
            $venue->sourceParishId
        );
    }
}
