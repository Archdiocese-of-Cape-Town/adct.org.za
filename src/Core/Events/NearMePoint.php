<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use InvalidArgumentException;

/**
 * Where the visitor said they are, so the listing can sort events by distance from that point.
 *
 * Two ways in, both entirely visitor-driven. Either the browser reports a position after the
 * visitor pressed the "Near me" button, or the visitor types a suburb we already hold coordinates
 * for. Both end up as a validated point plus a radius, and neither is ever written down: the
 * coordinates live in the visitor's URL for the length of their visit and go nowhere else
 * (ADR 0020). Pure value object, so it is unit-testable without WordPress.
 */
final class NearMePoint
{
    /**
     * A fixed ladder of radii rather than a free number. A select of recognisable choices is kinder
     * to a visitor than a text box, and a closed set means a crafted URL cannot hand the database
     * an arbitrary range to scan the archive with.
     */
    public const RADIUS_CHOICES_KM = [5.0, 10.0, 25.0, 50.0, 100.0];

    public const DEFAULT_RADIUS_KM = 25.0;

    /** No suburb name longer than a place-name is plausible; the column behind it is varchar(191). */
    private const MAX_LABEL_LENGTH = 100;

    public readonly float $latitude;
    public readonly float $longitude;
    public readonly float $radiusKm;

    /**
     * @param string|null $label The suburb the visitor typed, or null when the browser reported a
     *                           position. Carried through to the screen so the listing can say
     *                           which place the distances are measured from.
     */
    private function __construct(float $latitude, float $longitude, float $radiusKm, private ?string $label)
    {
        $this->latitude = self::clampLatitude($latitude);
        $this->longitude = self::clampLongitude($longitude);
        $this->radiusKm = self::knownRadius($radiusKm);
    }

    /** The browser reported a position after the visitor pressed the button. */
    public static function fromBrowserLocation(float $latitude, float $longitude, float $radiusKm = self::DEFAULT_RADIUS_KM): self
    {
        return new self($latitude, $longitude, $radiusKm, null);
    }

    /** The visitor typed a suburb we resolved to coordinates we already hold. */
    public static function fromSuburb(string $label, float $latitude, float $longitude, float $radiusKm = self::DEFAULT_RADIUS_KM): self
    {
        $trimmed = trim($label);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_LABEL_LENGTH) {
            throw new InvalidArgumentException('Enter a suburb.');
        }

        return new self($latitude, $longitude, $radiusKm, $trimmed);
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function isSuburb(): bool
    {
        return $this->label !== null;
    }

    /**
     * What to tell the visitor. A browser location has no name to show, so it is described rather
     * than named; saying "your location" is honest and does not pretend to know more than we do.
     */
    public function describe(): string
    {
        return $this->label === null ? 'your location' : $this->label;
    }

    /** Only a radius from the ladder is accepted, so the query always has a known shape. */
    public static function knownRadius(float $radiusKm): float
    {
        foreach (self::RADIUS_CHOICES_KM as $choice) {
            if (abs($radiusKm - $choice) < 0.001) {
                return $choice;
            }
        }

        throw new InvalidArgumentException('Choose how far away you are willing to travel.');
    }

    /** Plain-English label for a radius, so the select reads as a choice rather than a number. */
    public static function radiusLabel(float $radiusKm): string
    {
        $km = self::knownRadius($radiusKm);

        return $km >= 100.0 ? 'Up to 100 km away' : 'Within ' . (int) $km . ' km';
    }

    /**
     * The three floats the haversine SQL in PublicEventListing::distanceSql() expects, in the order
     * its placeholders appear: latitude, latitude, longitude.
     *
     * Returning them from one place is what keeps the prepared arguments aligned with the SQL when
     * the expression is repeated for the SELECT, the radius filter and the ORDER BY.
     *
     * @return list<float>
     */
    public function distanceArguments(): array
    {
        return [$this->latitude, $this->latitude, $this->longitude];
    }

    private static function clampLatitude(float $value): float
    {
        if (is_nan($value) || $value < -GeoDistance::MAX_LATITUDE || $value > GeoDistance::MAX_LATITUDE) {
            throw new InvalidArgumentException('That location is not on the map.');
        }

        return $value;
    }

    private static function clampLongitude(float $value): float
    {
        if (is_nan($value) || $value < -GeoDistance::MAX_LONGITUDE || $value > GeoDistance::MAX_LONGITUDE) {
            throw new InvalidArgumentException('That location is not on the map.');
        }

        return $value;
    }
}