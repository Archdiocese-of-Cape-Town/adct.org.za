<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class ImportPlan
{
    /**
     * @param ImportRow[] $rows
     * @param string[] $fileErrors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $fileErrors = []
    ) {
    }

    /**
     * @return array{create: int, update: int, unchanged: int, errors: int}
     */
    public function counts(): array
    {
        $counts = [
            ImportRow::CREATE => 0,
            ImportRow::UPDATE => 0,
            ImportRow::UNCHANGED => 0,
            'errors' => count($this->fileErrors),
        ];

        foreach ($this->rows as $row) {
            if ($row->action === ImportRow::ERROR) {
                ++$counts['errors'];
            } else {
                ++$counts[$row->action];
            }
        }

        return $counts;
    }

    public function canImport(): bool
    {
        if ($this->fileErrors !== []) {
            return false;
        }

        foreach ($this->rows as $row) {
            if ($row->errors !== [] || $row->action === ImportRow::ERROR) {
                return false;
            }
        }

        return true;
    }
}
