<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class ParishMatch
{
    public function __construct(
        public readonly int $parishId,
        public readonly string $name,
        public readonly ?string $suburb,
        public readonly string $matchedAs,
        public readonly float $confidence,
        public readonly bool $churchNameOnly = false
    ) {
    }
}
