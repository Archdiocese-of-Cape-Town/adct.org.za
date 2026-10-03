<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use InvalidArgumentException;

final class ListingSelection
{
    public readonly string $period;
    public readonly string $from;
    public readonly string $through;
    public readonly int $page;
    /** @var list<int> */
    public readonly array $types;
    public readonly ?int $parish;
    public readonly ?int $deanery;
    public readonly bool $collapse;
    public readonly bool $pin;
    /**
     * The point the visitor asked to sort by, or null when they did not ask. Held separately from
     * the suburb text because the suburb is a lookup key and not coordinates: the coordinates come
     * from our own parish and venue tables, never from anything the visitor typed.
     */
    public readonly ?float $latitude;
    public readonly ?float $longitude;
    public readonly ?float $radiusKm;
    public readonly ?string $suburb;

    /** @param array<string, mixed> $input */
    public function __construct(array $input, string $defaultPeriod = 'upcoming')
    {
        $this->period = self::text($input['adct_period'] ?? $defaultPeriod);
        $this->from = self::text($input['adct_from'] ?? '');
        $this->through = self::text($input['adct_to'] ?? '');
        $this->page = self::id($input['adct_page'] ?? '1', 100);
        $this->parish = self::optionalId($input['adct_parish'] ?? '');
        $this->deanery = self::optionalId($input['adct_deanery'] ?? '');
        $this->collapse = self::toggle($input['adct_collapse'] ?? '');
        $this->pin = self::toggle($input['adct_pin'] ?? '');

        $this->latitude = self::coordinate($input['adct_lat'] ?? '', GeoDistance::MAX_LATITUDE);
        $this->longitude = self::coordinate($input['adct_lng'] ?? '', GeoDistance::MAX_LONGITUDE);
        $this->radiusKm = self::radius($input['adct_radius_km'] ?? '');
        $this->suburb = self::suburb($input['adct_suburb'] ?? '');

        /** A half-coordinate pair is not a location, and guessing the missing half would be worse. */
        if (($this->latitude !== null) !== ($this->longitude !== null)) {
            throw new InvalidArgumentException('That location is not on the map.');
        }

        $values = $input['adct_types'] ?? [];
        if (! is_array($values) || ! array_is_list($values) || count($values) > 20) {
            throw new InvalidArgumentException('Choose at most 20 event types.');
        }
        $types = [];
        foreach ($values as $value) {
            $types[] = self::id($value);
        }
        $types = array_values(array_unique($types));
        sort($types, SORT_NUMERIC);
        $this->types = $types;
    }

    /** @return array<string, string|int|list<int>> */
    public function query(int $page = 0): array
    {
        $query = ['adct_period' => $this->period];
        if ($this->period === 'range') {
            $query['adct_from'] = $this->from;
            $query['adct_to'] = $this->through;
        }
        if ($this->types !== []) {
            $query['adct_types'] = $this->types;
        }
        if ($this->parish !== null) {
            $query['adct_parish'] = $this->parish;
        }
        if ($this->deanery !== null) {
            $query['adct_deanery'] = $this->deanery;
        }
        if ($this->collapse) {
            $query['adct_collapse'] = '1';
        }
        if ($this->pin) {
            $query['adct_pin'] = '1';
        }
        /**
         * The near-me point travels in the URL so paging, sharing and the browser's back button all
         * keep the sort. These are the visitor's own coordinates, held for the length of their
         * visit and never written to the database or a log (ADR 0020).
         */
        if ($this->latitude !== null && $this->longitude !== null) {
            $query['adct_lat'] = self::formatNumber($this->latitude);
            $query['adct_lng'] = self::formatNumber($this->longitude);
        }
        if ($this->radiusKm !== null) {
            $query['adct_radius_km'] = self::formatNumber($this->radiusKm);
        }
        if ($this->suburb !== null) {
            $query['adct_suburb'] = $this->suburb;
        }
        if ($page > 0 || $this->page > 1) {
            $query['adct_page'] = $page > 0 ? $page : $this->page;
        }

        return $query;
    }

    /** True when the visitor asked to sort by distance, which is the only thing radiusKm means. */
    public function hasNearMePoint(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    private static function toggle(mixed $value): bool
    {
        if ($value !== '' && $value !== '1') {
            throw new InvalidArgumentException('Choose a valid event display option.');
        }

        return $value === '1';
    }

    /**
     * A latitude or longitude off the browser. Only the plain decimal form a browser produces is
     * accepted, so a crafted URL cannot smuggle anything else through to the query, and values off
     * the map are refused rather than silently clamped.
     */
    private static function coordinate(mixed $value, float $limit): ?float
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('That location is not on the map.');
        }

        $text = (string) $value;

        if (preg_match('/\A-?(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,6})?\z/D', $text) !== 1) {
            throw new InvalidArgumentException('That location is not on the map.');
        }

        $coordinate = (float) $text;

        if ($coordinate < -$limit || $coordinate > $limit) {
            throw new InvalidArgumentException('That location is not on the map.');
        }

        return $coordinate;
    }

    /** Only a radius off the fixed ladder, so the query always has a shape we recognise. */
    private static function radius(mixed $value): ?float
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('Choose how far away you are willing to travel.');
        }

        $text = (string) $value;

        if (preg_match('/\A[0-9]{1,4}(?:\.[0-9]{1,3})?\z/D', $text) !== 1) {
            throw new InvalidArgumentException('Choose how far away you are willing to travel.');
        }

        return NearMePoint::knownRadius((float) $text);
    }

    /**
     * A suburb the visitor typed, kept verbatim so the screen can echo it back and the resolver can
     * look it up. Length is bounded because it ends up in a URL, and it is never used to build SQL.
     */
    private static function suburb(mixed $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (! is_string($value) || strlen($value) > 100) {
            throw new InvalidArgumentException('Enter a suburb.');
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Renders a coordinate or radius for a URL. Six decimal places is the resolution of the
     * decimal(9,6) columns and about 10 cm of latitude, so echoing a value back never drifts.
     */
    public static function formatNumber(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    private static function text(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 32) {
            throw new InvalidArgumentException('Enter a valid event filter.');
        }

        return $value;
    }

    private static function optionalId(mixed $value): ?int
    {
        if ($value === '') {
            return null;
        }

        return self::id($value);
    }

    private static function id(mixed $value, int $maximum = 2147483647): int
    {
        if (
            (! is_string($value) && ! is_int($value))
            || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $value) !== 1
            || (float) $value > $maximum
        ) {
            throw new InvalidArgumentException('Choose a valid event filter value.');
        }

        return (int) $value;
    }
}