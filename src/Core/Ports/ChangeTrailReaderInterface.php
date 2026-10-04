<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

/**
 * Reads the change trail of one event, for the change-history box on the event
 * edit screen.
 *
 * A port for the same reason {@see ApprovalRouteRepositoryInterface} exists:
 * the box decides what a person is allowed to be shown about a trail, and that
 * decision should not be entangled with the SQL that fetches it.
 */
interface ChangeTrailReaderInterface
{
    /**
     * The newest changes first.
     *
     * @return list<array<string, mixed>>
     */
    public function forEvent(int $eventId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function change(int $changeId): ?array;
}