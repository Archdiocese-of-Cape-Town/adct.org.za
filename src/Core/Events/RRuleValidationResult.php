<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

final class RRuleValidationResult
{
    /**
     * @param array<string, string> $parts
     * @param list<string> $errors
     */
    public function __construct(
        public readonly ?string $normalizedRule,
        public readonly array $parts,
        public readonly array $errors
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
