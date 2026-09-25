<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Directory;

use ADCT\ParishIntake\Core\Directory\DeaneryCsvImporter;
use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\ImportPlan;
use ADCT\ParishIntake\Core\Directory\ImportRow;
use ADCT\ParishIntake\Core\Directory\ParishCsvImporter;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class DirectoryImportService
{
    public function __construct(
        private ParishCsvImporter $parishImporter,
        private DeaneryCsvImporter $deaneryImporter,
        private ParishRepository $parishes,
        private DeaneryRepository $deaneries,
        private ContactService $contacts,
        private ClockInterface $clock
    ) {
    }

    public function previewParishes(string $csv): ImportPlan
    {
        return $this->parishImporter->preview(
            $csv,
            $this->deaneries->findAll(),
            $this->parishes->findAllForImport()
        );
    }

    public function importParishes(string $csv): ImportPlan
    {
        $deaneryRows = $this->deaneries->findAll();
        $parishRows = $this->parishes->findAllForImport();
        $plan = $this->parishImporter->preview($csv, $deaneryRows, $parishRows);
        $this->assertImportable($plan);

        $timestamp = $this->timestamp();
        $deaneryIds = [];

        foreach ($deaneryRows as $deanery) {
            $slug = strtolower((string) ($deanery['slug'] ?? ''));

            if ($slug !== '') {
                $deaneryIds[$slug] = (int) ($deanery['id'] ?? 0);
            }
        }

        $parishIds = [];
        $currentParishes = [];

        foreach ($parishRows as $parish) {
            $slug = strtolower((string) ($parish['slug'] ?? ''));

            if ($slug !== '') {
                $parishIds[$slug] = (int) ($parish['id'] ?? 0);
                $currentParishes[$slug] = $parish;
            }
        }

        foreach ($plan->rows as $row) {
            $slug = (string) $row->values['slug'];
            $current = $currentParishes[$slug] ?? null;

            if ($row->action === ImportRow::CREATE) {
                $values = $this->parishValues($row->values, $deaneryIds);
                $values['parent_parish_id'] = null;
                $values['created_at'] = $timestamp;
                $values['updated_at'] = $timestamp;
                $id = $this->parishes->insert($values);
                $parishIds[$slug] = $id;
                $currentParishes[$slug] = [
                    'id' => $id,
                    'slug' => $slug,
                    'parent_parish_id' => null,
                ];
            } elseif ($row->action === ImportRow::UPDATE) {
                $id = (int) ($current['id'] ?? 0);

                if ($id < 1) {
                    throw new RuntimeException('An existing parish could not be found during import.');
                }

                $values = $this->parishValues($row->values, $deaneryIds);
                $values['updated_at'] = $timestamp;
                $this->parishes->update($id, $values);
                $parishIds[$slug] = $id;
            }
        }

        foreach ($plan->rows as $row) {
            $slug = (string) $row->values['slug'];
            $id = (int) ($parishIds[$slug] ?? 0);
            $parentSlug = $row->values['parent_slug'];
            $parentId = $parentSlug === null ? null : (int) ($parishIds[$parentSlug] ?? 0);

            if ($id < 1 || ($parentSlug !== null && $parentId < 1)) {
                throw new RuntimeException('A parish or its parent could not be resolved during import.');
            }

            $currentParentId = $currentParishes[$slug]['parent_parish_id'] ?? null;
            $currentParentId = $currentParentId === null ? null : (int) $currentParentId;

            if ($currentParentId !== $parentId) {
                $this->parishes->update($id, [
                    'parent_parish_id' => $parentId,
                    'updated_at' => $timestamp,
                ]);
                $currentParishes[$slug]['parent_parish_id'] = $parentId;
            }

            $officeEmail = $row->values['office_email'];

            if ($officeEmail !== null) {
                $this->contacts->linkOfficial($id, (string) $officeEmail);
            }
        }

        return $plan;
    }

    public function previewDeaneries(string $csv): ImportPlan
    {
        return $this->deaneryImporter->preview($csv, $this->deaneries->findAll());
    }

    public function importDeaneries(string $csv): ImportPlan
    {
        $existingRows = $this->deaneries->findAll();
        $plan = $this->deaneryImporter->preview($csv, $existingRows);
        $this->assertImportable($plan);

        $existingBySlug = [];

        foreach ($existingRows as $existing) {
            $slug = strtolower((string) ($existing['slug'] ?? ''));

            if ($slug !== '') {
                $existingBySlug[$slug] = $existing;
            }
        }

        $timestamp = $this->timestamp();

        foreach ($plan->rows as $row) {
            if ($row->action === ImportRow::UNCHANGED) {
                continue;
            }

            $values = [
                'slug' => $row->values['slug'],
                'name' => $row->values['name'],
                'dean_name' => $row->values['dean_name'],
                'vice_dean_name' => $row->values['vice_dean_name'],
                'secretary_name' => $row->values['secretary_name'],
                'updated_at' => $timestamp,
            ];

            if ($row->action === ImportRow::CREATE) {
                $values['status'] = 'active';
                $values['created_at'] = $timestamp;
                $this->deaneries->insert($values);
                continue;
            }

            $id = (int) ($existingBySlug[$row->values['slug']]['id'] ?? 0);

            if ($id < 1) {
                throw new RuntimeException('An existing deanery could not be found during import.');
            }

            $this->deaneries->update($id, $values);
        }

        return $plan;
    }

    private function assertImportable(ImportPlan $plan): void
    {
        if (! $plan->canImport()) {
            throw new InvalidArgumentException('The CSV contains validation errors and cannot be imported.');
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, int> $deaneryIds
     * @return array<string, scalar|null>
     */
    private function parishValues(array $values, array $deaneryIds): array
    {
        $deanerySlug = $values['deanery_slug'];

        return [
            'name' => $values['name'],
            'slug' => $values['slug'],
            'area' => $values['area'],
            'church' => $values['church'],
            'kind' => $values['kind'],
            'deanery_id' => $deanerySlug === null ? null : ($deaneryIds[$deanerySlug] ?? null),
            'address' => $values['address'],
            'suburb' => $values['suburb'],
            'latitude' => $values['latitude'],
            'longitude' => $values['longitude'],
            'website' => $values['website'],
            'phone' => $values['phone'],
            'expected_cadence_days' => $values['expected_cadence_days'],
            'reminders_enabled' => $values['reminders_enabled'],
            'status' => $values['status'],
            'notes' => $values['notes'],
        ];
    }

    private function timestamp(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
