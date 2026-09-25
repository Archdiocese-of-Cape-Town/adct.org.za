<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use RuntimeException;

final class CsvDocumentReader
{
    /**
     * @param string[] $allowedHeaders
     * @param string[] $requiredHeaders
     */
    public function read(
        string $csv,
        array $allowedHeaders,
        array $requiredHeaders,
        int $maximumRows
    ): CsvDocument {
        $stream = fopen('php://temp', 'r+');

        if (! is_resource($stream)) {
            throw new RuntimeException('The CSV input stream could not be opened.');
        }

        if (fwrite($stream, $csv) !== strlen($csv)) {
            fclose($stream);
            throw new RuntimeException('The CSV input could not be read completely.');
        }

        rewind($stream);
        $rawHeaders = fgetcsv($stream, null, ',', '"', '');

        if ($rawHeaders === false || $this->isBlankRow($rawHeaders)) {
            fclose($stream);

            return new CsvDocument([], ['The CSV file is empty.']);
        }

        $headers = [];
        $errors = [];

        foreach ($rawHeaders as $index => $header) {
            $normalizedHeader = trim((string) $header);

            if ($index === 0) {
                $normalizedHeader = preg_replace('/^\xEF\xBB\xBF/', '', $normalizedHeader) ?? $normalizedHeader;
            }

            if ($normalizedHeader === '') {
                $errors[] = 'The CSV contains an empty column heading.';
            } elseif (in_array($normalizedHeader, $headers, true)) {
                $errors[] = 'The CSV contains the duplicate column heading "' . $normalizedHeader . '".';
            } elseif (! in_array($normalizedHeader, $allowedHeaders, true)) {
                $errors[] = 'The CSV contains an unknown column heading "' . $normalizedHeader . '".';
            }

            $headers[] = $normalizedHeader;
        }

        foreach ($requiredHeaders as $requiredHeader) {
            if (! in_array($requiredHeader, $headers, true)) {
                $errors[] = 'The CSV is missing the required column "' . $requiredHeader . '".';
            }
        }

        $rows = [];
        $rowNumber = 1;

        while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
            ++$rowNumber;

            if ($this->isBlankRow($fields)) {
                continue;
            }

            if (count($rows) >= $maximumRows) {
                $errors[] = sprintf('The CSV may contain no more than %d data rows.', $maximumRows);
                break;
            }

            $rowErrors = [];

            if (count($fields) !== count($headers)) {
                $rowErrors[] = sprintf(
                    'Row %d has %d values; the header has %d columns.',
                    $rowNumber,
                    count($fields),
                    count($headers)
                );
            }

            $values = array_pad(array_slice($fields, 0, count($headers)), count($headers), '');
            $normalizedValues = [];

            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $normalizedValues[$header] = trim(CsvFormulaGuard::unprotect(
                        (string) ($values[$index] ?? '')
                    ));
                }
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'values' => $normalizedValues,
                'errors' => $rowErrors,
            ];
        }

        fclose($stream);

        if ($rows === []) {
            $errors[] = 'The CSV contains no data rows.';
        }

        return new CsvDocument($rows, $errors);
    }

    /**
     * @param array<int, mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
