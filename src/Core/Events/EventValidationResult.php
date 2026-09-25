<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

final class EventValidationResult
{
    /**
     * @param array{
     *     parish_id: ?int,
     *     venue_id: ?int,
     *     start_local: string,
     *     end_local: ?string,
     *     all_day: bool,
     *     rrule: ?string,
     *     exdates: list<string>,
     *     rdates: list<string>,
     *     featured: bool,
     *     status_flag: string,
     *     source_candidate_id: ?int,
     *     contact: array{name: string, email: string, phone: string}
     * } $values
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $values,
        public readonly array $errors
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
