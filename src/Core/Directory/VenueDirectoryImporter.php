<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\VenueStoreInterface;
use DateTimeZone;

final class VenueDirectoryImporter
{
    private VenueNameNormalizer $normalizer;

    public function __construct(
        private VenueStoreInterface $venues,
        private ClockInterface $clock,
        ?VenueNameNormalizer $normalizer = null
    ) {
        $this->normalizer = $normalizer ?? new VenueNameNormalizer();
    }

    /**
     * @param list<array<string, mixed>> $parishes
     */
    public function import(array $parishes): int
    {
        $timestamp = $this->timestamp();
        $changed = 0;

        foreach ($parishes as $parish) {
            $parishId = (int) ($parish['id'] ?? 0);

            if ($parishId < 1) {
                continue;
            }

            $name = $this->venueName($parish);

            if ($name === '') {
                continue;
            }

            $aliases = $this->aliasesFor(
                $name,
                trim((string) ($parish['name'] ?? ''))
            );
            $default = new Venue(
                0,
                $parishId,
                $name,
                $aliases,
                $this->nullableText($parish['address'] ?? null),
                $this->nullableText($parish['suburb'] ?? null),
                $this->coordinate($parish['latitude'] ?? null, -90.0, 90.0),
                $this->coordinate($parish['longitude'] ?? null, -180.0, 180.0),
                true
            );

            if ($this->venues->ensureDefaultVenue($default, $timestamp)) {
                ++$changed;
            }
        }

        foreach ($parishes as $parish) {
            $kind = strtolower(trim((string) ($parish['kind'] ?? '')));
            $sourceParishId = (int) ($parish['id'] ?? 0);
            $parentParishId = (int) ($parish['parent_parish_id'] ?? 0);

            if (
                ! in_array($kind, ['outstation', 'mass_centre'], true)
                || $sourceParishId < 1
                || $parentParishId < 1
                || $parentParishId === $sourceParishId
            ) {
                continue;
            }

            $name = $this->venueName($parish);

            if ($name === '') {
                continue;
            }

            $venue = new Venue(
                0,
                $parentParishId,
                $name,
                $this->aliasesFor($name, trim((string) ($parish['name'] ?? ''))),
                $this->nullableText($parish['address'] ?? null),
                $this->nullableText($parish['suburb'] ?? null),
                $this->coordinate($parish['latitude'] ?? null, -90.0, 90.0),
                $this->coordinate($parish['longitude'] ?? null, -180.0, 180.0),
                false,
                Venue::ACTIVE,
                $sourceParishId
            );

            if ($this->venues->insertImportedVenue($venue, $timestamp)) {
                ++$changed;
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $parish
     */
    private function venueName(array $parish): string
    {
        $church = trim((string) ($parish['church'] ?? ''));

        return $church !== '' ? $church : trim((string) ($parish['name'] ?? ''));
    }

    /**
     * @return list<string>
     */
    private function aliasesFor(string $venueName, string $parishName): array
    {
        if (
            $parishName === ''
            || $this->normalizer->normalize($venueName) === $this->normalizer->normalize($parishName)
        ) {
            return [];
        }

        return [$parishName];
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }

        $coordinate = (float) $value;

        return $coordinate >= $minimum && $coordinate <= $maximum
            ? $coordinate
            : null;
    }

    private function timestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
