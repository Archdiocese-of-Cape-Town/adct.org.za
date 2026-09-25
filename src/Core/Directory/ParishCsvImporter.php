<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class ParishCsvImporter
{
    public const HEADERS = [
        'slug',
        'name',
        'area',
        'church',
        'kind',
        'is_mother_parish',
        'parent_slug',
        'deanery_slug',
        'address',
        'office_email',
        'latitude',
        'longitude',
        'suburb',
        'website',
        'phone',
        'expected_cadence_days',
        'reminders_enabled',
        'status',
        'notes',
    ];

    private const PERSISTED_FIELDS = [
        'name',
        'slug',
        'area',
        'church',
        'kind',
        'parent_slug',
        'deanery_slug',
        'address',
        'suburb',
        'latitude',
        'longitude',
        'website',
        'phone',
        'expected_cadence_days',
        'reminders_enabled',
        'status',
        'notes',
    ];

    private const MAXIMUM_ROWS = 2000;

    private CsvDocumentReader $reader;
    private ParishDataValidator $validator;

    public function __construct(
        ?CsvDocumentReader $reader = null,
        ?ParishDataValidator $validator = null
    ) {
        $this->reader = $reader ?? new CsvDocumentReader();
        $this->validator = $validator ?? new ParishDataValidator();
    }

    /**
     * @param array<int, array<string, mixed>|string> $existingDeaneries
     * @param array<int, array<string, mixed>> $existingParishes
     */
    public function preview(
        string $csv,
        array $existingDeaneries = [],
        array $existingParishes = []
    ): ImportPlan {
        $document = $this->reader->read(
            $csv,
            self::HEADERS,
            ['slug', 'name', 'kind'],
            self::MAXIMUM_ROWS
        );
        $fileSlugs = [];

        foreach ($document->rows as $row) {
            $slug = strtolower(trim((string) ($row['values']['slug'] ?? '')));

            if ($slug !== '') {
                $fileSlugs[] = $slug;
            }
        }

        $slugCounts = array_count_values($fileSlugs);
        $knownParishes = array_merge($existingParishes, $fileSlugs);
        $existingBySlug = [];

        foreach ($existingParishes as $existing) {
            $slug = strtolower(trim((string) ($existing['slug'] ?? '')));

            if ($slug !== '') {
                $existingBySlug[$slug] = $existing;
            }
        }

        $rows = [];

        foreach ($document->rows as $record) {
            $validation = $this->validator->validate(
                $record['values'],
                $existingDeaneries,
                $knownParishes
            );
            $values = $validation->values;
            $errors = array_merge($record['errors'], $validation->errors);
            $slug = (string) $values['slug'];

            if ($slug !== '' && ($slugCounts[$slug] ?? 0) > 1) {
                $errors[] = 'The slug "' . $slug . '" is duplicated in this CSV.';
            }

            $existing = $existingBySlug[$slug] ?? null;

            if ($errors !== []) {
                $action = ImportRow::ERROR;
            } elseif ($existing === null) {
                $action = ImportRow::CREATE;
            } else {
                $action = $this->isUnchanged($values, $existing)
                    ? ImportRow::UNCHANGED
                    : ImportRow::UPDATE;
            }

            $rows[] = new ImportRow($record['row_number'], $values, $action, $errors);
        }

        return new ImportPlan($rows, $document->errors);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $existing
     */
    private function isUnchanged(array $values, array $existing): bool
    {
        foreach (self::PERSISTED_FIELDS as $field) {
            if ($this->comparable($field, $values[$field] ?? null) !== $this->comparable(
                $field,
                $existing[$field] ?? null
            )) {
                return false;
            }
        }

        return true;
    }

    private function comparable(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $field === 'status' ? 'active' : null;
        }

        if ($field === 'latitude' || $field === 'longitude') {
            return is_numeric($value) ? number_format((float) $value, 6, '.', '') : (string) $value;
        }

        if ($field === 'expected_cadence_days') {
            return (int) $value;
        }

        if ($field === 'reminders_enabled') {
            return (int) $value;
        }

        if ($field === 'parent_slug' || $field === 'deanery_slug' || $field === 'slug') {
            return strtolower(trim((string) $value));
        }

        if (is_string($value)) {
            $normalized = trim($value);

            return $normalized === '' ? null : $normalized;
        }

        return $value;
    }
}
