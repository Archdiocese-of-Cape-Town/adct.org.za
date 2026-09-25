<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use DateTimeImmutable;
use InvalidArgumentException;

final class ExpandedOccurrence
{
    public function __construct(
        public readonly DateTimeImmutable $startLocal,
        public readonly ?DateTimeImmutable $endLocal
    ) {
        if ($endLocal !== null && $endLocal < $startLocal) {
            throw new InvalidArgumentException('An occurrence end must not precede its start.');
        }
    }
}
