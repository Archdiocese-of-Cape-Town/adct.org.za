<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Directory;

use ADCT\ParishIntake\Core\Directory\DeaneryCsvImporter;
use ADCT\ParishIntake\Core\Directory\ImportRow;
use ADCT\ParishIntake\Core\Directory\ParishCsvImporter;
use PHPUnit\Framework\TestCase;

final class ParishCsvImporterTest extends TestCase
{
    public function testSeedParishesImportWithoutErrorsAfterLoadingSeedDeaneries(): void
    {
        $deaneryPlan = (new DeaneryCsvImporter())->preview($this->seed('deaneries.csv'));
        $parishPlan = (new ParishCsvImporter())->preview(
            $this->seed('parishes.csv'),
            array_map(static fn (ImportRow $row): array => $row->values, $deaneryPlan->rows)
        );

        self::assertTrue($deaneryPlan->canImport());
        self::assertSame([
            'create' => 8,
            'update' => 0,
            'unchanged' => 0,
            'errors' => 0,
        ], $deaneryPlan->counts());
        self::assertTrue($parishPlan->canImport(), implode('; ', $this->allErrors($parishPlan)));
        self::assertSame([
            'create' => 124,
            'update' => 0,
            'unchanged' => 0,
            'errors' => 0,
        ], $parishPlan->counts());
    }

    public function testReimportingTheSameParishRowsPlansOnlyUnchangedRows(): void
    {
        $deaneries = array_map(
            static fn (ImportRow $row): array => $row->values,
            (new DeaneryCsvImporter())->preview($this->seed('deaneries.csv'))->rows
        );
        $importer = new ParishCsvImporter();
        $csv = $this->seed('parishes.csv');
        $firstPlan = $importer->preview($csv, $deaneries);
        $existingParishes = array_map(
            static fn (ImportRow $row): array => $row->values,
            $firstPlan->rows
        );

        $secondPlan = $importer->preview($csv, $deaneries, $existingParishes);

        self::assertTrue($secondPlan->canImport());
        self::assertSame([
            'create' => 0,
            'update' => 0,
            'unchanged' => 124,
            'errors' => 0,
        ], $secondPlan->counts());
    }

    public function testParentSlugResolvesRegardlessOfCsvRowOrder(): void
    {
        $importer = new ParishCsvImporter();
        $child = $this->parishRow('child', [
            'name' => 'Sample Outstation',
            'kind' => 'outstation',
            'is_mother_parish' => 'no',
            'parent_slug' => 'mother',
            'deanery_slug' => 'central',
        ]);
        $parent = $this->parishRow('mother', [
            'name' => 'Sample Parish',
            'kind' => 'parish',
            'is_mother_parish' => 'yes',
            'deanery_slug' => 'central',
        ]);
        $deaneries = [['slug' => 'central']];

        $childFirst = $importer->preview($this->csv([$child, $parent]), $deaneries);
        $parentFirst = $importer->preview($this->csv([$parent, $child]), $deaneries);

        self::assertTrue($childFirst->canImport(), implode('; ', $this->allErrors($childFirst)));
        self::assertTrue($parentFirst->canImport(), implode('; ', $this->allErrors($parentFirst)));
        self::assertSame('mother', $childFirst->rows[0]->values['parent_slug']);
        self::assertSame('mother', $parentFirst->rows[1]->values['parent_slug']);
    }

    public function testValidationErrorsAreAttachedToTheirRowsAndDuplicateSlugsAreRejected(): void
    {
        $importer = new ParishCsvImporter();
        $rows = [
            $this->parishRow('missing-name', ['name' => '']),
            $this->parishRow('bad-kind', ['kind' => 'chapel']),
            $this->parishRow('bad-latitude', ['latitude' => '90.1']),
            $this->parishRow('bad-longitude', ['longitude' => '-181']),
            $this->parishRow('bad-deanery', ['deanery_slug' => 'unknown-deanery']),
            $this->parishRow('bad-parent', ['parent_slug' => 'unknown-parent']),
            $this->parishRow('bad-email', ['office_email' => 'not-an-email']),
            $this->parishRow('duplicate-slug'),
            $this->parishRow('duplicate-slug'),
        ];

        $plan = $importer->preview($this->csv($rows), [['slug' => 'central']]);

        self::assertFalse($plan->canImport());
        self::assertSame(9, $plan->counts()['errors']);
        self::assertStringContainsString('name', implode('; ', $plan->rows[0]->errors));
        self::assertStringContainsString('kind', implode('; ', $plan->rows[1]->errors));
        self::assertStringContainsString('latitude', implode('; ', $plan->rows[2]->errors));
        self::assertStringContainsString('longitude', implode('; ', $plan->rows[3]->errors));
        self::assertStringContainsString('deanery', implode('; ', $plan->rows[4]->errors));
        self::assertStringContainsString('parent', implode('; ', $plan->rows[5]->errors));
        self::assertStringContainsString('email', implode('; ', $plan->rows[6]->errors));
        self::assertStringContainsString('duplicate', implode('; ', $plan->rows[7]->errors));
        self::assertStringContainsString('duplicate', implode('; ', $plan->rows[8]->errors));
        self::assertSame('error', $plan->rows[0]->action);
    }

    public function testExistingSlugPlansAnUpdateAndOmittedRowsAreNotPlannedForDeletion(): void
    {
        $row = $this->parishRow('sample-parish');
        $importer = new ParishCsvImporter();
        $current = $importer->preview($this->csv([$row]), [['slug' => 'central']])->rows[0]->values;
        $changed = $row;
        $changed['area'] = 'Updated area';

        $plan = $importer->preview($this->csv([$changed]), [['slug' => 'central']], [$current]);

        self::assertSame(1, count($plan->rows));
        self::assertSame('update', $plan->rows[0]->action);
        self::assertSame('sample-parish', $plan->rows[0]->values['slug']);
        self::assertArrayNotHasKey('delete', $plan->counts());
    }

    public function testUnknownHeadersAndMissingRequiredHeadersBlockTheImport(): void
    {
        $plan = (new ParishCsvImporter())->preview("slug,name,kind,unexpected\nsample,Sample,parish,value\n");

        self::assertFalse($plan->canImport());
        self::assertNotEmpty($plan->fileErrors);
        self::assertStringContainsString('unexpected', implode('; ', $plan->fileErrors));

        $missingRequired = (new ParishCsvImporter())->preview("slug,name\nsample,Sample\n");

        self::assertFalse($missingRequired->canImport());
        self::assertNotEmpty($missingRequired->fileErrors);
    }

    public function testCsvRowLimitIsEnforcedAtTwoThousandDataRows(): void
    {
        $csv = "slug,name,kind\n";

        for ($row = 1; $row <= 2001; ++$row) {
            $csv .= 'parish-' . $row . ',Sample Parish,parish' . "\n";
        }

        $plan = (new ParishCsvImporter())->preview($csv);

        self::assertFalse($plan->canImport());
        self::assertCount(2000, $plan->rows);
        self::assertStringContainsString('2000 data rows', implode('; ', $plan->fileErrors));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function parishRow(string $slug, array $overrides = []): array
    {
        return array_replace(array_fill_keys(ParishCsvImporter::HEADERS, ''), [
            'slug' => $slug,
            'name' => 'Sample Parish',
            'area' => 'Sample area',
            'church' => 'Sample church',
            'kind' => 'parish',
            'is_mother_parish' => 'yes',
            'deanery_slug' => 'central',
            'latitude' => '-33.900000',
            'longitude' => '18.500000',
            'office_email' => 'office@example.invalid',
        ], $overrides);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fputcsv($stream, ParishCsvImporter::HEADERS, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_values($row), ',', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        self::assertIsString($csv);

        return $csv;
    }

    private function seed(string $file): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . $file
        );
        self::assertIsString($contents, 'Seed file is missing: ' . $file);

        return $contents;
    }

    /**
     * @return string[]
     */
    private function allErrors($plan): array
    {
        $errors = $plan->fileErrors;

        foreach ($plan->rows as $row) {
            $errors = array_merge($errors, $row->errors);
        }

        return $errors;
    }
}
