<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

final class EventDetails
{
    /**
     * @param list<string> $exdates
     * @param list<string> $rdates
     * @param array<string, string> $contact
     */
    public function __construct(
        public readonly ?int $parishId,
        public readonly ?int $venueId,
        public readonly string $startLocal,
        public readonly ?string $endLocal,
        public readonly bool $allDay,
        public readonly ?string $rrule,
        public readonly array $exdates,
        public readonly array $rdates,
        public readonly bool $featured,
        public readonly string $statusFlag,
        public readonly ?int $sourceCandidateId,
        public readonly array $contact
    ) {
    }
}
