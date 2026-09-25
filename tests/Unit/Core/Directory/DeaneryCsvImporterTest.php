<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Directory;

use ADCT\ParishIntake\Core\Directory\DeaneryCsvImporter;
use PHPUnit\Framework\TestCase;

final class DeaneryCsvImporterTest extends TestCase
{
    public function testSeedDeaneriesImportWithoutErrors(): void
    {
        $plan = (new DeaneryCsvImporter())->preview($this->seed());

        self::assertTrue($plan->canImport(), implode('; ', $plan->fileErrors));
        self::assertSame([
            'create' => 8,
            'update' => 0,
            'unchanged' => 0,
            'errors' => 0,
        ], $plan->counts());
    }

    public function testDeaneryRowsMatchExistingSlugsAndNamesAreImportedAsDisplayFields(): void
    {
        $csv = "slug,name,dean,vice_dean,secretary\nsample,Sample Deanery,First,Second,Third\n";
        $importer = new DeaneryCsvImporter();
        $first = $importer->preview($csv);
        $existing = [$first->rows[0]->values];

        $unchanged = $importer->preview($csv, $existing);
        $updated = $importer->preview(
            "slug,name,dean,vice_dean,secretary\nsample,Updated Deanery,First,Second,Third\n",
            $existing
        );

        self::assertSame('unchanged', $unchanged->rows[0]->action);
        self::assertSame('update', $updated->rows[0]->action);
        self::assertSame('First', $updated->rows[0]->values['dean_name']);
        self::assertArrayNotHasKey('delete', $updated->counts());
    }

    public function testInvalidAndDuplicateDeaneryRowsAreReportedWithoutImport(): void
    {
        $csv = "slug,name,dean,vice_dean,secretary\n,Missing slug,,,\nsample,One,,,\nsample,Two,,,\n";
        $plan = (new DeaneryCsvImporter())->preview($csv);

        self::assertFalse($plan->canImport());
        self::assertSame(3, $plan->counts()['errors']);
        self::assertStringContainsString('slug', implode('; ', $plan->rows[0]->errors));
        self::assertStringContainsString('duplicate', implode('; ', $plan->rows[1]->errors));
        self::assertStringContainsString('duplicate', implode('; ', $plan->rows[2]->errors));
    }

    private function seed(): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'seed' . DIRECTORY_SEPARATOR . 'deaneries.csv'
        );
        self::assertIsString($contents, 'Seed deaneries CSV is missing.');

        return $contents;
    }
}
