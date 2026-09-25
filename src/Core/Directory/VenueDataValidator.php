<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use InvalidArgumentException;

final class VenueDataValidator
{
    private VenueNameNormalizer $normalizer;

    public function __construct(?VenueNameNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new VenueNameNormalizer();
    }

    /**
     * @param array<string, mixed> $input
     */
    public function validate(array $input): VenueValidationResult
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $errors[] = 'A venue name is required.';
        } elseif (! $this->hasAtMost191Characters($name)) {
            $errors[] = 'The venue name must be 191 characters or fewer.';
        } elseif (preg_match('//u', $name) !== 1) {
            $errors[] = 'The venue name must be valid UTF-8 text.';
        }

        $aliases = $this->aliases($input['aliases'] ?? '', $errors);
        $latitude = $this->coordinate(
            'latitude',
            $input['latitude'] ?? null,
            -90.0,
            90.0,
            $errors
        );
        $longitude = $this->coordinate(
            'longitude',
            $input['longitude'] ?? null,
            -180.0,
            180.0,
            $errors
        );
        $isDefault = $this->boolean($input['is_default'] ?? false, $errors);

        return new VenueValidationResult([
            'name' => $name,
            'aliases' => $aliases,
            'address' => $this->nullableText($input['address'] ?? null),
            'suburb' => $this->nullableText($input['suburb'] ?? null),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'is_default' => $isDefault,
        ], $errors);
    }

    /**
     * @param string[] $errors
     * @return list<string>
     */
    private function aliases(mixed $input, array &$errors): array
    {
        if (! is_string($input) && ! is_scalar($input) && $input !== null) {
            $errors[] = 'Venue aliases must be text.';

            return [];
        }

        $raw = (string) ($input ?? '');

        if (preg_match('//u', $raw) !== 1) {
            $errors[] = 'Venue aliases must be valid UTF-8 text.';

            return [];
        }

        $parts = preg_split('/[\r\n,]+/u', $raw);

        if (! is_array($parts)) {
            $errors[] = 'Venue aliases could not be read.';

            return [];
        }

        $aliases = [];

        foreach ($parts as $part) {
            $alias = trim($part);

            if ($alias === '') {
                continue;
            }

            if (! $this->hasAtMost191Characters($alias)) {
                $errors[] = 'Each venue alias must be 191 characters or fewer.';

                continue;
            }

            $aliases[] = $alias;
        }

        try {
            return $this->normalizer->uniqueAliases($aliases);
        } catch (InvalidArgumentException) {
            $errors[] = 'Venue aliases must be valid UTF-8 text.';

            return [];
        }
    }

    /**
     * @param string[] $errors
     */
    private function coordinate(
        string $field,
        mixed $input,
        float $minimum,
        float $maximum,
        array &$errors
    ): ?float {
        if (! is_scalar($input) && $input !== null) {
            $errors[] = 'The ' . $field . ' must be a number.';

            return null;
        }

        $value = trim((string) ($input ?? ''));

        if ($value === '') {
            return null;
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            $errors[] = 'The ' . $field . ' must be a finite number.';

            return null;
        }

        $number = (float) $value;

        if ($number < $minimum || $number > $maximum) {
            $errors[] = sprintf(
                'The %s must be between %s and %s.',
                $field,
                (string) $minimum,
                (string) $maximum
            );

            return null;
        }

        return $number;
    }

    /**
     * @param string[] $errors
     */
    private function boolean(mixed $input, array &$errors): bool
    {
        if (in_array($input, [true, 1, '1', 'true', 'on'], true)) {
            return true;
        }

        if (in_array($input, [false, 0, '0', '', null, 'false', 'off'], true)) {
            return false;
        }

        $errors[] = 'The default venue setting must be yes or no.';

        return false;
    }

    private function nullableText(mixed $input): ?string
    {
        $value = trim((string) ($input ?? ''));

        return $value === '' ? null : $value;
    }

    private function hasAtMost191Characters(string $value): bool
    {
        $characters = preg_match_all('/./us', $value);

        return $characters !== false && $characters <= 191;
    }
}
