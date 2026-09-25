<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use InvalidArgumentException;

final class DirectoryLookup
{
    private VenueNameNormalizer $normalizer;
    private ?int $venueLookupVersion = null;
    private ?VenueLookup $venueLookup = null;

    public function __construct(
        private DirectorySnapshotProviderInterface $snapshots,
        ?VenueNameNormalizer $normalizer = null
    ) {
        $this->normalizer = $normalizer ?? new VenueNameNormalizer();
    }

    public function lookupSender(string $email): SenderLookupResult
    {
        $email = EmailAddress::normalize($email);
        $contacts = array_values(array_filter(
            $this->snapshot()->contacts,
            static fn (array $contact): bool => strtolower(trim((string) ($contact['email'] ?? ''))) === $email
        ));

        return SenderLookup::fromRows($email, $contacts);
    }

    public function parishById(int $parishId): ?ParishMatch
    {
        if ($parishId < 1) {
            throw new InvalidArgumentException('A parish ID must be positive.');
        }

        foreach ($this->snapshot()->parishes as $parish) {
            if (
                (int) ($parish['id'] ?? 0) !== $parishId
                || (string) ($parish['status'] ?? 'active') !== 'active'
            ) {
                continue;
            }

            $name = trim((string) ($parish['name'] ?? ''));

            if ($name === '') {
                return null;
            }

            return new ParishMatch(
                $parishId,
                $name,
                $this->nullableString($parish['suburb'] ?? null),
                $name,
                1.0
            );
        }

        return null;
    }

    /**
     * @param list<int>|null $eligibleParishIds
     */
    public function matchParish(string $text, ?array $eligibleParishIds = null): ParishLookupResult
    {
        if (trim($text) === '') {
            return new ParishLookupResult(null);
        }

        if (preg_match('//u', $text) !== 1) {
            return new ParishLookupResult(null, ['Directory parish matching skipped invalid UTF-8 text.']);
        }

        $textVariants = $this->normalizer->variants($text);
        $eligible = $eligibleParishIds === null
            ? null
            : array_fill_keys(array_map('intval', $eligibleParishIds), true);
        $matches = [];

        foreach ($this->snapshot()->parishes as $parish) {
            $parishId = (int) ($parish['id'] ?? 0);
            $status = (string) ($parish['status'] ?? 'active');

            if (
                $parishId < 1
                || $status !== 'active'
                || ($eligible !== null && ! isset($eligible[$parishId]))
            ) {
                continue;
            }

            $name = trim((string) ($parish['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $best = null;

            foreach ($this->parishAliases($parish) as $alias) {
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
                $matches[$parishId] = [
                    'parish' => $parish,
                    'score' => $best['score'],
                    'matched_as' => $best['matched_as'],
                ];
            }
        }

        if ($matches === []) {
            return new ParishLookupResult(null);
        }

        $highestScore = max(array_column($matches, 'score'));
        $bestMatches = array_values(array_filter(
            $matches,
            static fn (array $entry): bool => $entry['score'] === $highestScore
        ));

        if (count($bestMatches) !== 1) {
            return new ParishLookupResult(
                null,
                ['The text matches more than one active parish; no parish was selected.']
            );
        }

        $best = $bestMatches[0];
        $parish = $best['parish'];

        return new ParishLookupResult(new ParishMatch(
            (int) $parish['id'],
            trim((string) $parish['name']),
            $this->nullableString($parish['suburb'] ?? null),
            $best['matched_as'],
            0.9
        ));
    }

    public function lookupVenue(string $text, ?int $parishId = null): VenueLookupResult
    {
        $this->venueLookup();

        return $this->venueLookup->lookup($text, $parishId);
    }

    public function defaultVenueFor(int $parishId): ?VenueMatch
    {
        $this->venueLookup();

        return $this->venueLookup->defaultVenueFor($parishId);
    }

    private function venueLookup(): void
    {
        $snapshot = $this->snapshot();

        if ($this->venueLookup !== null && $this->venueLookupVersion === $snapshot->version) {
            return;
        }

        $this->venueLookup = new VenueLookup(
            new SnapshotVenueLookupRepository($snapshot->venues),
            $this->normalizer
        );
        $this->venueLookupVersion = $snapshot->version;
    }

    /**
     * @param array<string, mixed> $parish
     * @return list<string>
     */
    private function parishAliases(array $parish): array
    {
        $aliases = [];

        foreach (['name', 'church', 'slug'] as $key) {
            if (isset($parish[$key]) && is_string($parish[$key]) && trim($parish[$key]) !== '') {
                $aliases[] = trim($parish[$key]);
            }
        }

        if (isset($parish['aliases'])) {
            if (! is_array($parish['aliases']) || ! array_is_list($parish['aliases'])) {
                throw new InvalidArgumentException('Directory parish aliases must be a list.');
            }

            foreach ($parish['aliases'] as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    throw new InvalidArgumentException('Directory parish aliases must be non-empty text.');
                }

                $aliases[] = trim($alias);
            }
        }

        $area = trim((string) ($parish['area'] ?? ''));
        $church = trim((string) ($parish['church'] ?? ''));

        if ($area !== '' && $church !== '') {
            $aliases[] = $area . ': ' . $church;
        }

        $baseAliases = $aliases;
        $suburb = trim((string) ($parish['suburb'] ?? ''));

        if ($suburb !== '') {
            foreach ($baseAliases as $alias) {
                $aliases[] = $alias . ', ' . $suburb;
                $aliases[] = $alias . ' (' . $suburb . ')';
            }
        }

        $expanded = [];

        foreach ($aliases as $alias) {
            $expanded[] = $alias;
            $withoutPossessive = preg_replace('/[\'’‘ʼ＇]s\b/iu', '', $alias);

            if (is_string($withoutPossessive) && $withoutPossessive !== $alias) {
                $expanded[] = $withoutPossessive;
            }
        }

        $unique = [];

        foreach ($expanded as $alias) {
            $variants = $this->normalizer->variants($alias);

            if ($variants !== []) {
                $unique[implode('|', $variants)] = $alias;
            }
        }

        return array_values($unique);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function snapshot(): DirectorySnapshot
    {
        return $this->snapshots->getSnapshot();
    }
}
