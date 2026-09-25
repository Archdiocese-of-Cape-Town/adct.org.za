<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class DeaneryCsvImporter
{
    public const HEADERS = ['slug', 'name', 'dean', 'vice_dean', 'secretary'];

    private const IMPORTED_FIELDS = [
        'slug',
        'name',
        'dean_name',
        'vice_dean_name',
        'secretary_name',
    ];

    private const MAXIMUM_ROWS = 2000;

    private CsvDocumentReader $reader;

    public function __construct(?CsvDocumentReader $reader = null)
    {
        $this->reader = $reader ?? new CsvDocumentReader();
    }

    /**
     * @param array<int, array<string, mixed>> $existingDeaneries
     */
    public function preview(string $csv, array $existingDeaneries = []): ImportPlan
    {
        $document = $this->reader->read(
            $csv,
            self::HEADERS,
            ['slug', 'name'],
            self::MAXIMUM_ROWS
        );
        $slugCounts = [];

        foreach ($document->rows as $record) {
            $slug = strtolower(trim((string) ($record['values']['slug'] ?? '')));

            if ($slug !== '') {
                $slugCounts[$slug] = ($slugCounts[$slug] ?? 0) + 1;
            }
        }

        $existingBySlug = [];

        foreach ($existingDeaneries as $existing) {
            $slug = strtolower(trim((string) ($existing['slug'] ?? '')));

            if ($slug !== '') {
                $existingBySlug[$slug] = $existing;
            }
        }

        $rows = [];

        foreach ($document->rows as $record) {
            $input = $record['values'];
            $slug = strtolower(trim((string) ($input['slug'] ?? '')));
            $name = trim((string) ($input['name'] ?? ''));
            $values = [
                'slug' => $slug,
                'name' => $name,
                'dean_name' => $this->nullableText($input['dean'] ?? null),
                'vice_dean_name' => $this->nullableText($input['vice_dean'] ?? null),
                'secretary_name' => $this->nullableText($input['secretary'] ?? null),
            ];
            $errors = $record['errors'];

            if ($slug === '') {
                $errors[] = 'A slug is required.';
            } elseif (! ParishDataValidator::isValidSlug($slug)) {
                $errors[] = 'The slug must contain lowercase letters, numbers and single hyphens only.';
            }

            if ($name === '') {
                $errors[] = 'A name is required.';
            }

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
        foreach (self::IMPORTED_FIELDS as $field) {
            $value = $values[$field] ?? null;
            $current = $existing[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
            }

            if (is_string($current)) {
                $current = trim($current) === '' ? null : trim($current);
            }

            if ($value !== $current) {
                return false;
            }
        }

        return true;
    }

    private function nullableText(mixed $input): ?string
    {
        $value = trim((string) ($input ?? ''));

        return $value === '' ? null : $value;
    }
}
