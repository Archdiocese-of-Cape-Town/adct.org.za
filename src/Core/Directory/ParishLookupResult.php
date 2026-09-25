<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class ParishLookupResult
{
    /**
     * @param list<string> $notes
     */
    public function __construct(
        public readonly ?ParishMatch $match,
        public readonly array $notes = []
    ) {
    }
}
