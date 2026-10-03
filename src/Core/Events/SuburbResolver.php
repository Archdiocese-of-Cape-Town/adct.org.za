<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use ADCT\ParishIntake\Core\Ports\PlaceCoordinateLookupInterface;
use InvalidArgumentException;

/**
 * Turns a suburb a visitor typed into a point we can sort by, using only coordinates the
 * archdiocese already holds.
 *
 * There is no paid geocoding anywhere in this plugin (ADR 0020), so the lookup is a matching
 * exercise against the suburb, area and church names on our own parishes and venues. When several
 * parishes share a name the resolver takes the middle of their pins, because the centre of the
 * group is a fairer "you are here" than any single member of it. When a place name matches exactly
 * one pin, that pin is used on its own, which is the common case in the Cape Town suburbs.
 *
 * Matching ignores case, accents, spacing and punctuation, so "Claremont", "claremont" and
 * "Claremont  " all land on the same place, and a name that matches nothing is reported honestly
 * rather than guessed at. Pure logic, so it is unit-testable without WordPress.
 */
final class SuburbResolver
{
    /** A suburb list is offered in a datalist; this keeps that list from becoming the whole table. */
    public const MAX_SUGGESTIONS = 500;

    /** Longest suburb string accepted, matching the varchar(191) behind it. */
    private const MAX_QUERY_LENGTH = 100;

    public function __construct(private readonly PlaceCoordinateLookupInterface $lookup)
    {
    }

    /**
     * @return NearMePoint|null Null when nothing matched, which the screen reports as "we do not
     *                           know that suburb" and offers the whole list instead.
     */
    public function resolve(string $query, float $radiusKm = NearMePoint::DEFAULT_RADIUS_KM): ?NearMePoint
    {
        $needle = self::normalise($query);

        if ($needle === '') {
            return null;
        }

        $matches = $this->collect($needle);

        if ($matches === null) {
            return null;
        }

        [$latitude, $longitude, $label] = $matches;

        return NearMePoint::fromSuburb($label, $latitude, $longitude, $radiusKm);
    }

    /**
     * The suburb names a visitor can pick from, for the datalist on the filter form.
     *
     * @return list<string>
     */
    public function suggestions(): array
    {
        return $this->lookup->suburbNames(self::MAX_SUGGESTIONS);
    }

    /**
     * @return array{0: float, 1: float, 2: string}|null Latitude, longitude and the name to show.
     */
    private function collect(string $needle): ?array
    {
        $exact = [];
        $startsWith = [];
        $contains = [];

        foreach ($this->lookup->placesWithCoordinates(self::MAX_SUGGESTIONS * 4) as $place) {
            $haystack = self::normalise($place->name);

            if ($haystack === '') {
                continue;
            }

            if ($haystack === $needle) {
                $exact[] = $place;
            } elseif (str_starts_with($haystack, $needle)) {
                $startsWith[] = $place;
            } elseif (str_contains($haystack, $needle)) {
                $contains[] = $place;
            }
        }

        $best = $this->bestTier($exact, $startsWith, $contains);

        if ($best === []) {
            return null;
        }

        /**
         * The lowest name wins the tie so the same suburb always resolves to the same point,
         * no matter what order the database happened to return rows in.
         */
        usort(
            $best,
            static fn (KnownPlace $a, KnownPlace $b): int => strcmp($a->name, $b->name)
                ?: strcmp($a->latitude . ',' . $a->longitude, $b->latitude . ',' . $b->longitude)
        );

        $latitude = 0.0;
        $longitude = 0.0;
        foreach ($best as $place) {
            $latitude += $place->latitude;
            $longitude += $place->longitude;
        }
        $count = count($best);

        return [
            $latitude / $count,
            $longitude / $count,
            $best[0]->name,
        ];
    }

    /**
     * An exact name beats a prefix, which beats a substring: typing "Claremont" should not land on
     * "Claremont North" when a parish in Claremont itself exists.
     *
     * @param list<KnownPlace> $exact
     * @param list<KnownPlace> $startsWith
     * @param list<KnownPlace> $contains
     * @return list<KnownPlace>
     */
    private function bestTier(array $exact, array $startsWith, array $contains): array
    {
        foreach ([$exact, $startsWith, $contains] as $tier) {
            if ($tier !== []) {
                return $tier;
            }
        }

        return [];
    }

    /** Lower case, accent-folded, punctuation collapsed to single spaces, trimmed at both ends. */
    private static function normalise(string $value): string
    {
        if (strlen($value) > self::MAX_QUERY_LENGTH) {
            throw new InvalidArgumentException('Enter a suburb.');
        }

        $folded = preg_replace('/\s+/u', ' ', $value);

        if ($folded === null) {
            return '';
        }

        $lower = mb_strtolower($folded, 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $lower);

        if ($ascii !== false) {
            $lower = $ascii;
        }

        /**
         * An apostrophe elides a letter rather than separating two words, so it is dropped instead
         * of becoming a space. Without this, "St George's" folds to "st george s" and a visitor
         * who sensibly types "St Georges" gets no match at all.
         */
        $elided = preg_replace('/[\x27\x2018\x2019]/u', '', $lower);
        $lower = $elided ?? $lower;

        $lower = preg_replace('/[^a-z0-9]+/', ' ', $lower);

        return $lower === null ? '' : trim($lower);
    }
}