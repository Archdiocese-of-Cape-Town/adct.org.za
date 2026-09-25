<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Publishing;

use ADCT\ParishIntake\Core\Events\EventDetails;

final class Publication
{
    public function __construct(
        public readonly int $candidateId,
        public readonly ?int $eventId,
        public readonly string $kind,
        public readonly string $actor,
        public readonly string $title,
        public readonly string $description,
        public readonly EventDetails $details,
        public readonly ?string $eventType
    ) {
    }
}
