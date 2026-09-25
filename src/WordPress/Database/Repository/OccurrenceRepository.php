<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

use ADCT\ParishIntake\Core\Events\ExpandedOccurrence;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class OccurrenceRepository
{
    private DateTimeZone $utc;

    public function __construct(private DatabaseConnectionInterface $database)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function nextPublishedEventId(int $afterEventId): ?int
    {
        if ($afterEventId < 0) {
            throw new InvalidArgumentException('The event cursor cannot be negative.');
        }

        $posts = $this->database->prefix() . 'posts';
        $query = $this->database->prepare(
            "SELECT ID FROM {$posts} "
            . 'WHERE post_type = %s AND post_status = %s AND ID > %d '
            . 'ORDER BY ID ASC LIMIT 1',
            EventPostType::POST_TYPE,
            'publish',
            $afterEventId
        );
        $row = $this->fetchRow($query);

        if ($row === null) {
            return null;
        }

        $eventId = filter_var($row['ID'] ?? null, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
            ],
        ]);

        if (! is_int($eventId) || $eventId <= $afterEventId) {
            throw new RuntimeException('The occurrence cursor query returned an invalid event ID.');
        }

        return $eventId;
    }

    /**
     * @return array{latitude: float|null, longitude: float|null}
     */
    public function locationForEvent(?int $parishId, ?int $venueId): array
    {
        if (($parishId !== null && $parishId < 1) || ($venueId !== null && $venueId < 1)) {
            throw new InvalidArgumentException('Event parish and venue IDs must be positive when set.');
        }

        if ($venueId !== null) {
            $venues = $this->database->prefix() . 'adct_pi_venues';
            $parishes = $this->database->prefix() . 'adct_pi_parishes';
            $query = $this->database->prepare(
                "SELECT v.parish_id AS venue_parish_id, "
                . 'v.latitude AS venue_latitude, v.longitude AS venue_longitude, '
                . 'p.latitude AS parish_latitude, p.longitude AS parish_longitude '
                . "FROM {$venues} v "
                . "LEFT JOIN {$parishes} p ON p.id = v.parish_id "
                . 'WHERE v.id = %d LIMIT 1',
                $venueId
            );
            $row = $this->fetchRow($query);

            if ($row === null) {
                throw new RuntimeException('The event venue no longer exists.');
            }

            if ($parishId !== null && (int) ($row['venue_parish_id'] ?? 0) !== $parishId) {
                throw new RuntimeException('The event venue no longer belongs to its selected parish.');
            }

            $venueCoordinates = $this->coordinates(
                $row['venue_latitude'] ?? null,
                $row['venue_longitude'] ?? null
            );

            if ($venueCoordinates['latitude'] !== null) {
                return $venueCoordinates;
            }

            return $this->coordinates(
                $row['parish_latitude'] ?? null,
                $row['parish_longitude'] ?? null
            );
        }

        if ($parishId === null) {
            return [
                'latitude' => null,
                'longitude' => null,
            ];
        }

        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        $query = $this->database->prepare(
            "SELECT latitude, longitude FROM {$parishes} WHERE id = %d LIMIT 1",
            $parishId
        );
        $row = $this->fetchRow($query);

        if ($row === null) {
            throw new RuntimeException('The event parish no longer exists.');
        }

        return $this->coordinates($row['latitude'] ?? null, $row['longitude'] ?? null);
    }

    /**
     * @param list<ExpandedOccurrence> $occurrences
     */
    public function replaceForEvent(
        int $eventId,
        array $occurrences,
        ?int $parishId,
        ?int $eventTypeTermId,
        ?float $latitude,
        ?float $longitude,
        bool $isCancelled,
        DateTimeImmutable $now,
        bool $transactional = true
    ): void {
        $this->assertEventMetadata($eventId, $parishId, $eventTypeTermId, $latitude, $longitude);

        if (! array_is_list($occurrences)) {
            throw new InvalidArgumentException('Occurrences must be provided as an ordered list.');
        }

        foreach ($occurrences as $occurrence) {
            if (! $occurrence instanceof ExpandedOccurrence) {
                throw new InvalidArgumentException('Every stored occurrence must be an expanded occurrence.');
            }
        }

        $createdAt = $now->setTimezone($this->utc)->format('Y-m-d H:i:s');
        $table = $this->occurrencesTable();

        $replace = function () use (
            $eventId,
            $occurrences,
            $parishId,
            $eventTypeTermId,
            $latitude,
            $longitude,
            $isCancelled,
            $createdAt,
            $table
        ): void {
            $delete = $this->database->prepare(
                "DELETE FROM {$table} WHERE event_id = %d",
                $eventId
            );
            $this->execute($delete, 'delete');

            foreach ($occurrences as $occurrence) {
                $this->insertOccurrence(
                    $table,
                    $eventId,
                    $occurrence,
                    $parishId,
                    $eventTypeTermId,
                    $latitude,
                    $longitude,
                    $isCancelled,
                    $createdAt
                );
            }
        };

        if ($transactional) {
            $this->transaction($replace);
        } else {
            $replace();
        }
    }

    public function deleteForEvent(int $eventId): void
    {
        $this->assertEventMetadata($eventId, null, null, null, null);
        $table = $this->occurrencesTable();

        $this->transaction(function () use ($table, $eventId): void {
            $query = $this->database->prepare(
                "DELETE FROM {$table} WHERE event_id = %d",
                $eventId
            );
            $this->execute($query, 'delete');
        });
    }

    private function insertOccurrence(
        string $table,
        int $eventId,
        ExpandedOccurrence $occurrence,
        ?int $parishId,
        ?int $eventTypeTermId,
        ?float $latitude,
        ?float $longitude,
        bool $isCancelled,
        string $createdAt
    ): void {
        $placeholders = [
            '%d',
            '%s',
            $occurrence->endLocal === null ? 'NULL' : '%s',
            '%s',
            $parishId === null ? 'NULL' : '%d',
            $eventTypeTermId === null ? 'NULL' : '%d',
            $latitude === null ? 'NULL' : '%f',
            $longitude === null ? 'NULL' : '%f',
            '%d',
            '%s',
            '%s',
        ];
        $arguments = [
            $eventId,
            $occurrence->startLocal->setTimezone($this->utc)->format('Y-m-d H:i:s'),
        ];

        if ($occurrence->endLocal !== null) {
            $arguments[] = $occurrence->endLocal->setTimezone($this->utc)->format('Y-m-d H:i:s');
        }

        $arguments[] = $occurrence->startLocal->format('Y-m-d');

        if ($parishId !== null) {
            $arguments[] = $parishId;
        }

        if ($eventTypeTermId !== null) {
            $arguments[] = $eventTypeTermId;
        }

        if ($latitude !== null) {
            $arguments[] = $latitude;
        }

        if ($longitude !== null) {
            $arguments[] = $longitude;
        }

        $arguments[] = $isCancelled ? 1 : 0;
        $arguments[] = $createdAt;
        $arguments[] = $createdAt;

        $query = "INSERT INTO {$table} "
            . '(event_id, start_utc, end_utc, start_local_date, parish_id, event_type_term_id, '
            . 'latitude, longitude, is_cancelled, created_at, updated_at) '
            . 'VALUES (' . implode(', ', $placeholders) . ')';
        $this->execute($this->database->prepare($query, ...$arguments), 'insert');
    }

    /**
     * @param callable(): void $operation
     */
    private function transaction(callable $operation): void
    {
        $this->execute('START TRANSACTION', 'start transaction');

        try {
            $operation();
            $this->execute('COMMIT', 'commit');
        } catch (Throwable $failure) {
            try {
                $this->execute('ROLLBACK', 'rollback');
            } catch (Throwable $rollbackFailure) {
                throw new RuntimeException(
                    'Occurrence data failed and its transaction could not be rolled back: '
                    . $rollbackFailure->getMessage(),
                    0,
                    $failure
                );
            }

            throw $failure;
        }
    }

    private function execute(string $query, string $operation): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false) {
            throw new RuntimeException(
                'The occurrence database ' . $operation . ' failed: ' . $this->database->lastError()
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $query): ?array
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The occurrence database read failed: ' . $this->database->lastError());
        }

        return $row;
    }

    private function coordinates(mixed $latitude, mixed $longitude): array
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return [
                'latitude' => null,
                'longitude' => null,
            ];
        }

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw new RuntimeException('The event location contains invalid coordinates.');
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (
            ! is_finite($latitude)
            || ! is_finite($longitude)
            || $latitude < -90
            || $latitude > 90
            || $longitude < -180
            || $longitude > 180
        ) {
            throw new RuntimeException('The event location contains out-of-range coordinates.');
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function assertEventMetadata(
        int $eventId,
        ?int $parishId,
        ?int $eventTypeTermId,
        ?float $latitude,
        ?float $longitude
    ): void {
        if ($eventId < 1 || ($parishId !== null && $parishId < 1)) {
            throw new InvalidArgumentException('Event and parish IDs are invalid for occurrence storage.');
        }

        if ($eventTypeTermId !== null && $eventTypeTermId < 1) {
            throw new InvalidArgumentException('An event type term ID must be positive when set.');
        }

        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException('Occurrence coordinates must contain both latitude and longitude.');
        }

        $this->coordinates($latitude, $longitude);
    }

    private function occurrencesTable(): string
    {
        return $this->database->prefix() . 'adct_pi_occurrences';
    }
}
