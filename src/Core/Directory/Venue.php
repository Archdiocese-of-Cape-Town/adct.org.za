<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class Venue
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';

    /**
     * @param list<string> $aliases
     */
    public function __construct(
        public readonly int $id,
        public readonly int $parishId,
        public readonly string $name,
        public readonly array $aliases = [],
        public readonly ?string $address = null,
        public readonly ?string $suburb = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly bool $isDefault = false,
        public readonly string $status = self::ACTIVE,
        public readonly ?int $sourceParishId = null
    ) {
    }
}
