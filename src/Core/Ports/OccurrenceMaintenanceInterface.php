<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Events\OccurrenceWindow;

interface OccurrenceMaintenanceInterface
{
    public function nextEventIdAfter(int $eventId): ?int;

    public function rebuildEvent(int $eventId, OccurrenceWindow $window): void;

    public function deleteEventOccurrences(int $eventId): void;
}
