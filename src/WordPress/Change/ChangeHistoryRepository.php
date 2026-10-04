<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Change;

use ADCT\ParishIntake\Core\Ports\ChangeTrailReaderInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;

/**
 * Reads the change trail of a single event, for the history box on the event
 * edit screen.
 *
 * Deliberately separate from ReviewQueueRepository, whose reads are scoped by
 * reviewer and deanery for the approval queue. This one is scoped only by the
 * event id it is given, and the box that uses it has already checked that the
 * user may edit that event, so there is no second, weaker notion of scope to
 * keep in step.
 *
 * A plain SELECT over the columns the rest of the plugin already reads, so no
 * new schema is involved.
 */
final class ChangeHistoryRepository implements ChangeTrailReaderInterface
{
    public const LIMIT = 25;

    /**
     * before_payload and after_payload are read too: the field-level effect is
     * the reason the view exists.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id',
        'event_id',
        'actor',
        'kind',
        'before_payload',
        'after_payload',
        'notified_at',
        'reverted_by',
        'reverted_at',
        'created_at',
    ];

    public function __construct(private readonly DatabaseConnectionInterface $connection)
    {
    }

    /**
     * The newest changes first. A trail is read backwards, from the present
     * state towards how it got there.
     *
     * @return list<array<string, mixed>>
     */
    public function forEvent(int $eventId, int $limit = self::LIMIT): array
    {
        if ($eventId <= 0 || $limit < 1) {
            return [];
        }

        $rows = [];

        foreach ($this->connection->getResults($this->selectForEvent($eventId, $limit)) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function change(int $changeId): ?array
    {
        if ($changeId <= 0) {
            return null;
        }

        $sql = 'SELECT ' . implode(', ', self::COLUMNS)
            . ' FROM ' . $this->table()
            . ' WHERE id = ' . $this->connection->prepare('%d', $changeId);
        $row = $this->connection->getRow($sql);

        return is_array($row) ? $row : null;
    }

    private function selectForEvent(int $eventId, int $limit): string
    {
        return 'SELECT ' . implode(', ', self::COLUMNS)
            . ' FROM ' . $this->table()
            . ' WHERE event_id = ' . $this->connection->prepare('%d', $eventId)
            . ' ORDER BY id DESC'
            . ' LIMIT ' . $this->connection->prepare('%d', $limit);
    }

    private function table(): string
    {
            return $this->connection->prefix() . 'adct_pi_event_changes';
    }
}