<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class VenueValidationResult
{
    /**
     * @param array{
     *     name: string,
     *     aliases: list<string>,
     *     address: ?string,
     *     suburb: ?string,
     *     latitude: ?float,
     *     longitude: ?float,
     *     is_default: bool
     * } $values
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $values,
        public readonly array $errors
    ) {
    }
}
