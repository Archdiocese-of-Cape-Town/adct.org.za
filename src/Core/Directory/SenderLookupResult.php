<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class SenderLookupResult
{
    /**
     * @param list<int> $parishIds
     */
    public function __construct(
        public readonly string $email,
        public readonly string $trust,
        public readonly array $parishIds
    ) {
    }
}
