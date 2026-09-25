<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use InvalidArgumentException;

final class ParishDataValidator
{
    public const KINDS = [
        'parish',
        'outstation',
        'mass_centre',
        'mission',
        'group',
        'archdiocese',
        'school',
        'other',
    ];

    public const STATUSES = ['active', 'inactive'];

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>|string> $knownDeaneries
     * @param array<int, array<string, mixed>|string> $knownParishes
     */
    public function validate(
        array $input,
        array $knownDeaneries,
        array $knownParishes
    ): ParishValidationResult {
        $errors = [];
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $kind = strtolower(trim((string) ($input['kind'] ?? '')));
        $parentSlug = $this->nullableSlug($input['parent_slug'] ?? null);
        $deanerySlug = $this->nullableSlug($input['deanery_slug'] ?? null);

        if ($slug === '') {
            $errors[] = 'A slug is required.';
        } elseif (! self::isValidSlug($slug)) {
            $errors[] = 'The slug must contain lowercase letters, numbers and single hyphens only.';
        }

        if ($name === '') {
            $errors[] = 'A name is required.';
        }

        if ($kind === '') {
            $errors[] = 'A kind is required.';
        } elseif (! in_array($kind, self::KINDS, true)) {
            $errors[] = 'The kind must be one of: ' . implode(', ', self::KINDS) . '.';
        }

        $deanerySlugs = self::slugs($knownDeaneries);
        $parishSlugs = self::slugs($knownParishes);

        if ($deanerySlug !== null && ! in_array($deanerySlug, $deanerySlugs, true)) {
            $errors[] = 'The deanery slug "' . $deanerySlug . '" is not known.';
        }

        if ($parentSlug !== null) {
            if ($parentSlug === $slug) {
                $errors[] = 'A parish cannot be its own parent.';
            } elseif (! in_array($parentSlug, $parishSlugs, true)) {
                $errors[] = 'The parent slug "' . $parentSlug . '" is not known.';
            }
        }

        $officeEmail = strtolower(trim((string) ($input['office_email'] ?? '')));

        if ($officeEmail !== '') {
            try {
                $officeEmail = EmailAddress::normalize($officeEmail);
            } catch (InvalidArgumentException) {
                $errors[] = 'The office email address is not valid.';
            }
        }

        $motherParish = strtolower(trim((string) ($input['is_mother_parish'] ?? '')));

        if ($motherParish !== '' && ! in_array($motherParish, ['yes', 'no'], true)) {
            $errors[] = 'The mother-parish value must be yes or no.';
        }

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
        $cadence = $this->cadence($input['expected_cadence_days'] ?? null, $errors);
        $remindersEnabled = $this->remindersEnabled($input['reminders_enabled'] ?? null, $errors);
        $status = strtolower(trim((string) ($input['status'] ?? '')));

        if ($status === '') {
            $status = 'active';
        } elseif (! in_array($status, self::STATUSES, true)) {
            $errors[] = 'The status must be active or inactive.';
        }

        return new ParishValidationResult([
            'slug' => $slug,
            'name' => $name,
            'area' => $this->nullableText($input['area'] ?? null),
            'church' => $this->nullableText($input['church'] ?? null),
            'kind' => $kind,
            'is_mother_parish' => $motherParish === '' ? null : $motherParish,
            'parent_slug' => $parentSlug,
            'deanery_slug' => $deanerySlug,
            'address' => $this->nullableText($input['address'] ?? null),
            'suburb' => $this->nullableText($input['suburb'] ?? null),
            'office_email' => $officeEmail === '' ? null : $officeEmail,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'website' => $this->nullableText($input['website'] ?? null),
            'phone' => $this->nullableText($input['phone'] ?? null),
            'expected_cadence_days' => $cadence,
            'reminders_enabled' => $remindersEnabled,
            'status' => $status,
            'notes' => $this->nullableText($input['notes'] ?? null),
        ], $errors);
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1;
    }

    /**
     * @param array<int, array<string, mixed>|string> $records
     * @return string[]
     */
    public static function slugs(array $records): array
    {
        $slugs = [];

        foreach ($records as $record) {
            $value = is_string($record) ? $record : ($record['slug'] ?? '');
            $slug = strtolower(trim((string) $value));

            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
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
    ): ?string {
        $value = trim((string) ($input ?? ''));

        if ($value === '') {
            return null;
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            $errors[] = 'The ' . $field . ' must be a number.';

            return $value;
        }

        $number = (float) $value;

        if ($number < $minimum || $number > $maximum) {
            $errors[] = sprintf(
                'The %s must be between %s and %s.',
                $field,
                (string) $minimum,
                (string) $maximum
            );

            return $value;
        }

        return number_format($number, 6, '.', '');
    }

    /**
     * @param string[] $errors
     */
    private function cadence(mixed $input, array &$errors): ?int
    {
        $value = trim((string) ($input ?? ''));

        if ($value === '') {
            return null;
        }

        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
            $errors[] = 'Expected cadence must be a whole number from 1 to 65535 days.';

            return null;
        }

        return (int) $value;
    }

    /**
     * @param string[] $errors
     */
    private function remindersEnabled(mixed $input, array &$errors): int
    {
        $value = strtolower(trim((string) ($input ?? '')));

        if ($value === '') {
            return 1;
        }

        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }

        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        $errors[] = 'Reminders enabled must be yes or no.';

        return 1;
    }

    private function nullableSlug(mixed $input): ?string
    {
        $value = strtolower(trim((string) ($input ?? '')));

        return $value === '' ? null : $value;
    }

    private function nullableText(mixed $input): ?string
    {
        $value = trim((string) ($input ?? ''));

        return $value === '' ? null : $value;
    }
}
