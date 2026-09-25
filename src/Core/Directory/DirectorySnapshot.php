<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use InvalidArgumentException;

final class DirectorySnapshot
{
    /**
     * @param list<array<string, mixed>> $parishes
     * @param list<Venue> $venues
     * @param list<array<string, mixed>> $contacts
     */
    public function __construct(
        public readonly array $parishes,
        public readonly array $venues,
        public readonly array $contacts,
        public readonly int $version = 0
    ) {
        if ($version < 0) {
            throw new InvalidArgumentException('A directory snapshot version cannot be negative.');
        }

        foreach ($parishes as $parish) {
            if (! is_array($parish)) {
                throw new InvalidArgumentException('Directory parish entries must be arrays.');
            }
        }

        foreach ($venues as $venue) {
            if (! $venue instanceof Venue) {
                throw new InvalidArgumentException('Directory venue entries must be Venue values.');
            }
        }

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                throw new InvalidArgumentException('Directory contact entries must be arrays.');
            }
        }
    }

    public function withVersion(int $version): self
    {
        return new self($this->parishes, $this->venues, $this->contacts, $version);
    }

    /**
     * @return array{
     *     version: int,
     *     parishes: list<array<string, mixed>>,
     *     venues: list<array<string, mixed>>,
     *     contacts: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'parishes' => $this->parishes,
            'venues' => array_map(
                static fn (Venue $venue): array => [
                    'id' => $venue->id,
                    'parish_id' => $venue->parishId,
                    'name' => $venue->name,
                    'aliases' => $venue->aliases,
                    'address' => $venue->address,
                    'suburb' => $venue->suburb,
                    'latitude' => $venue->latitude,
                    'longitude' => $venue->longitude,
                    'is_default' => $venue->isDefault,
                    'status' => $venue->status,
                    'source_parish_id' => $venue->sourceParishId,
                ],
                $this->venues
            ),
            'contacts' => $this->contacts,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['parishes', 'venues', 'contacts'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key]) || ! array_is_list($data[$key])) {
                throw new InvalidArgumentException('A directory snapshot must contain list-valued ' . $key . '.');
            }
        }

        $venues = [];

        foreach ($data['venues'] as $venue) {
            if (! is_array($venue)) {
                throw new InvalidArgumentException('Directory snapshot venue data must be an object.');
            }

            $venues[] = new Venue(
                self::requiredPositiveInteger($venue, 'id'),
                self::requiredPositiveInteger($venue, 'parish_id'),
                self::requiredString($venue, 'name'),
                self::stringList($venue['aliases'] ?? []),
                self::nullableString($venue['address'] ?? null),
                self::nullableString($venue['suburb'] ?? null),
                self::nullableFloat($venue['latitude'] ?? null),
                self::nullableFloat($venue['longitude'] ?? null),
                (bool) ($venue['is_default'] ?? false),
                (string) ($venue['status'] ?? Venue::ACTIVE),
                isset($venue['source_parish_id']) && $venue['source_parish_id'] !== null
                    ? self::requiredPositiveInteger($venue, 'source_parish_id')
                    : null
            );
        }

        return new self(
            $data['parishes'],
            $venues,
            $data['contacts'],
            self::version($data['version'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requiredPositiveInteger(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if ((! is_int($value) && (! is_string($value) || preg_match('/^\d+$/D', $value) !== 1)) || (int) $value < 1) {
            throw new InvalidArgumentException('Directory snapshot ' . $key . ' must be a positive integer.');
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Directory snapshot ' . $key . ' must be non-empty text.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Directory venue aliases must be a list.');
        }

        foreach ($value as $alias) {
            if (! is_string($alias) || trim($alias) === '') {
                throw new InvalidArgumentException('Directory venue aliases must be non-empty text.');
            }
        }

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Directory snapshot text values must be strings or null.');
        }

        return $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException('Directory snapshot coordinates must be finite numbers or null.');
        }

        return (float) $value;
    }

    private static function version(mixed $value): int
    {
        if (
            (! is_int($value) && (! is_string($value) || preg_match('/^\d+$/D', $value) !== 1))
            || (int) $value < 0
        ) {
            throw new InvalidArgumentException('A directory snapshot version must be a non-negative integer.');
        }

        return (int) $value;
    }
}
