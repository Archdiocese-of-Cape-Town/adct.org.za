<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Events\KnownPlace;
use ADCT\ParishIntake\Core\Ports\PlaceCoordinateLookupInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use RuntimeException;

/**
 * Reads the coordinates the archdiocese already holds so a visitor who types a suburb can be placed.
 *
 * There is no paid geocoding in this plugin, so this adapter is the whole suburb lookup: it asks
 * the parish and venue tables for the names that have a pin attached. Parish name, area and suburb
 * all count, because a visitor types whatever they call the place and the three fields in the seed
 * data are not always the same string.
 *
 * Rows are capped and ordered by id, so the result is stable for a given archive and a crafted
 * request cannot make this read the whole table.
 */
final class PlaceCoordinateLookup implements PlaceCoordinateLookupInterface
{
    /** The seed data has roughly 120 parishes; this leaves room for venues without reading a huge table. */
    public const HARD_LIMIT = 5000;

    public function __construct(private readonly DatabaseConnectionInterface $database)
    {
    }

    /** @return list<KnownPlace> */
    public function placesWithCoordinates(int $limit): array
    {
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        $venues = $this->database->prefix() . 'adct_pi_venues';
        $bounded = max(1, min($limit, self::HARD_LIMIT));

        /**
         * A UNION of the three parish name columns with the venues' own pins. The names are read
         * out of the database rather than taken from the query string, so a suburb is never
         * interpolated into SQL; only the row cap and the id, both integers, go through prepare().
         */
        $query = $this->database->prepare(
            "SELECT name, latitude, longitude FROM (
                SELECT id, name, latitude, longitude FROM {$parishes}
                 WHERE status = 'active' AND latitude IS NOT NULL AND longitude IS NOT NULL
                   AND name <> '' AND name IS NOT NULL
                UNION ALL
                SELECT id, area, latitude, longitude FROM {$parishes}
                 WHERE status = 'active' AND latitude IS NOT NULL AND longitude IS NOT NULL
                   AND area <> '' AND area IS NOT NULL
                UNION ALL
                SELECT id, suburb, latitude, longitude FROM {$parishes}
                 WHERE status = 'active' AND latitude IS NOT NULL AND longitude IS NOT NULL
                   AND suburb <> '' AND suburb IS NOT NULL
                UNION ALL
                SELECT id, name, latitude, longitude FROM {$venues}
                 WHERE status = 'active' AND latitude IS NOT NULL AND longitude IS NOT NULL
                   AND name <> '' AND name IS NOT NULL
                UNION ALL
                SELECT id, suburb, latitude, longitude FROM {$venues}
                 WHERE status = 'active' AND latitude IS NOT NULL AND longitude IS NOT NULL
                   AND suburb <> '' AND suburb IS NOT NULL
             ) AS places
             ORDER BY name ASC, latitude ASC, longitude ASC
             LIMIT %d",
            $bounded
        );

        $this->database->clearLastError();
        $rows = $this->database->getResults($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The suburb lookup failed: ' . $this->database->lastError());
        }

        $places = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $latitude = $row['latitude'] ?? null;
            $longitude = $row['longitude'] ?? null;

            if (! is_string($name) || ! is_numeric($latitude) || ! is_numeric($longitude)) {
                continue;
            }

            try {
                $places[] = new KnownPlace($name, (float) $latitude, (float) $longitude);
            } catch (\InvalidArgumentException) {
                /** A row whose coordinates fall outside the map is skipped, not fatal. */
                continue;
            }
        }

        return $places;
    }

    /** @return list<string> */
    public function suburbNames(int $limit): array
    {
        $parishes = $this->database->prefix() . 'adct_pi_parishes';
        $venues = $this->database->prefix() . 'adct_pi_venues';
        $bounded = max(1, min($limit, self::HARD_LIMIT));

        $query = $this->database->prepare(
            "SELECT DISTINCT name FROM (
                SELECT suburb AS name FROM {$parishes} WHERE suburb IS NOT NULL AND suburb <> ''
                UNION
                SELECT area AS name FROM {$parishes} WHERE area IS NOT NULL AND area <> ''
                UNION
                SELECT suburb AS name FROM {$venues} WHERE suburb IS NOT NULL AND suburb <> ''
             ) AS names
             ORDER BY name ASC
             LIMIT %d",
            $bounded
        );

        $this->database->clearLastError();
        $rows = $this->database->getResults($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The suburb list failed: ' . $this->database->lastError());
        }

        $names = [];
        foreach ($rows as $row) {
            if (is_string($row['name'] ?? null) && trim($row['name']) !== '') {
                $names[] = trim($row['name']);
            }
        }

        return $names;
    }
}