<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class ImportRow
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const UNCHANGED = 'unchanged';
    public const ERROR = 'error';

    /**
     * @param array<string, mixed> $values
     * @param string[] $errors
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $values,
        public readonly string $action,
        public readonly array $errors = []
    ) {
    }
}
