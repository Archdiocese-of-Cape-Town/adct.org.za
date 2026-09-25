<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use ADCT\ParishIntake\Core\Ports\VenueLookupInterface;
use ADCT\ParishIntake\Core\Ports\VenueLookupRepositoryInterface;
use InvalidArgumentException;

final class VenueLookup implements VenueLookupInterface
{
    private VenueNameNormalizer $normalizer;

    public function __construct(
        private VenueLookupRepositoryInterface $venues,
        ?VenueNameNormalizer $normalizer = null
    ) {
        $this->normalizer = $normalizer ?? new VenueNameNormalizer();
    }

    public function match(string $text, ?int $parishId = null): ?VenueMatch
    {
        return $this->lookup($text, $parishId)->match;
    }

    public function lookup(string $text, ?int $parishId = null): VenueLookupResult
    {
        if ($parishId !== null && $parishId < 1) {
            throw new InvalidArgumentException('A parish ID must be positive when supplied.');
        }

        $textVariants = $this->normalizer->variants($text);

        if ($textVariants === []) {
            return new VenueLookupResult(null);
        }

        $matches = [];

        foreach ($this->venues->findActiveVenues() as $venue) {
            if (
                $venue->id < 1
                || $venue->parishId < 1
                || $venue->status !== Venue::ACTIVE
            ) {
                continue;
            }

            $aliases = $this->normalizer->uniqueAliases(array_merge(
                [$venue->name],
                $venue->aliases
            ));
            $best = null;

            foreach ($aliases as $alias) {
                foreach ($this->normalizer->variants($alias) as $aliasVariant) {
                    foreach ($textVariants as $textVariant) {
                        if (! str_contains(' ' . $textVariant . ' ', ' ' . $aliasVariant . ' ')) {
                            continue;
                        }

                        $score = strlen($aliasVariant);

                        if ($best === null || $score > $best['score']) {
                            $best = [
                                'score' => $score,
                                'matched_as' => $alias,
                            ];
                        }
                    }
                }
            }

            if ($best !== null) {
                $matches[$venue->id] = [
                    'venue' => $venue,
                    'score' => $best['score'],
                    'matched_as' => $best['matched_as'],
                ];
            }
        }

        if ($matches === []) {
            return new VenueLookupResult(null);
        }

        $allMatches = array_values($matches);
        $preferredMatches = $parishId === null
            ? $allMatches
            : array_values(array_filter(
                $allMatches,
                static fn (array $entry): bool => $entry['venue']->parishId === $parishId
            ));
        $candidates = $preferredMatches === [] ? $allMatches : $preferredMatches;
        $highestScore = max(array_column($candidates, 'score'));
        $bestMatches = array_values(array_filter(
            $candidates,
            static fn (array $entry): bool => $entry['score'] === $highestScore
        ));

        if (count($bestMatches) !== 1) {
            return new VenueLookupResult(
                null,
                ['The text matches more than one active venue; no venue was selected.']
            );
        }

        $best = $bestMatches[0];
        $venue = $best['venue'];
        $notes = [];

        if ($preferredMatches !== [] && count($allMatches) > count($preferredMatches)) {
            $notes[] = 'The venue in the selected parish was preferred over matches in other parishes.';
        }

        return new VenueLookupResult(
            new VenueMatch(
                $venue->id,
                $venue->parishId,
                $venue->name,
                $venue->address,
                $venue->suburb,
                $venue->latitude,
                $venue->longitude,
                $best['matched_as']
            ),
            $notes
        );
    }

    public function defaultVenueFor(int $parishId): ?VenueMatch
    {
        if ($parishId < 1) {
            throw new InvalidArgumentException('A parish ID must be positive.');
        }

        $defaults = array_values(array_filter(
            $this->venues->findActiveVenues(),
            static fn (Venue $venue): bool => $venue->parishId === $parishId
                && $venue->status === Venue::ACTIVE
                && $venue->isDefault
        ));

        if (count($defaults) !== 1) {
            return null;
        }

        $venue = $defaults[0];

        return new VenueMatch(
            $venue->id,
            $venue->parishId,
            $venue->name,
            $venue->address,
            $venue->suburb,
            $venue->latitude,
            $venue->longitude,
            $venue->name
        );
    }
}
