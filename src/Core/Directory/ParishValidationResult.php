<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class ParishValidationResult
{
    /**
     * @param array<string, mixed> $values
     * @param string[] $errors
     */
    public function __construct(
        public readonly array $values,
        public readonly array $errors
    ) {
    }
}
