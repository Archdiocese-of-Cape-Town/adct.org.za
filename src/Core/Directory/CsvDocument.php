<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class CsvDocument
{
    /**
     * @param array<int, array{row_number: int, values: array<string, string>, errors: string[]}> $rows
     * @param string[] $errors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors
    ) {
    }
}
