<?php

namespace ADCT\ParishIntake\Core\Parsing;

final class SectionSkipper
{
    public const CATEGORIES = [
        'mass_times' => 'Mass times',
        'mass_intentions' => 'Mass intentions',
        'sick_list' => 'Sick list',
        'deceased' => 'Recently deceased',
        'anniversaries' => 'Anniversaries',
        'raffle_winners' => 'Raffle winners',
        'collections_finances' => 'Collections and finances',
        'banking_details' => 'Banking details',
        'readings' => 'Readings',
    ];

    private const DEFAULT_KEYWORDS = [
        'mass_times' => [
            'Mass times',
            'Mass time',
            'Mass timetable',
            'Mass schedule',
            'Weekly Mass times',
            'Weekly Mass schedule',
            'Weekday Mass times',
            'Weekday Mass schedule',
        ],
        'mass_intentions' => [
            'Mass intentions',
            'Mass intention',
            'Intentions',
            'For the intentions of',
            'Masses offered for',
        ],
        'sick_list' => [
            'Sick list',
            'Sick',
            'Please pray for',
            'Pray for the sick',
            'Prayers for the sick',
            'Those who are sick',
        ],
        'deceased' => [
            'Recently deceased',
            'Deceased',
            'In memoriam',
            'Those who have died',
            'Pray for the dead',
        ],
        'anniversaries' => [
            'Anniversaries',
            'Wedding anniversary',
            'Wedding anniversaries',
            'Death anniversaries',
        ],
        'raffle_winners' => [
            'Raffle winners',
            'Raffle winner',
            'Raffle results',
            'Draw winners',
        ],
        'collections_finances' => [
            'Collections',
            'Collection',
            'Collections and finances',
            'Collection and finances',
            'Finances',
            'Finance report',
            'Financial report',
            'Stewardship report',
            'Weekly collection',
        ],
        'banking_details' => [
            'Banking details',
            'Bank details',
            'Bank account details',
            'Account details',
            'EFT details',
            'Banking information',
        ],
        'readings' => [
            'Readings',
            'Sunday readings',
            'Weekly readings',
            'Scripture readings',
            'Liturgical readings',
        ],
    ];

    private const WEEKDAY_PATTERN = '(?:Monday|Mon|Tuesday|Tues|Tue|Wednesday|Wed|Thursday|Thurs|Thur|Thu|Friday|Fri|Saturday|Sat|Sunday|Sun)';
    private const MONTH_PATTERN = '(?:January|Jan|February|Feb|March|Mar|April|Apr|May|June|Jun|July|Jul|August|Aug|September|Sept|Sep|October|Oct|November|Nov|December|Dec)';

    private array $normalizedKeywordLists;

    public function __construct(?array $keywordLists = null)
    {
        $sanitized = self::sanitizeKeywordLists(
            $keywordLists ?? self::defaultKeywordLists()
        );
        $this->normalizedKeywordLists = [];

        foreach ($sanitized as $category => $phrases) {
            $this->normalizedKeywordLists[$category] = array_map(
                static fn (string $phrase): string => self::normalizePhrase($phrase),
                $phrases
            );
        }
    }

    public static function defaultKeywordLists(): array
    {
        return self::DEFAULT_KEYWORDS;
    }

    public static function sanitizeKeywordLists(array $keywordLists): array
    {
        $sanitized = self::defaultKeywordLists();

        foreach (self::CATEGORIES as $category => $label) {
            if (! array_key_exists($category, $keywordLists)) {
                continue;
            }

            $value = $keywordLists[$category];

            if (is_string($value)) {
                $phrases = preg_split('/\R/u', $value) ?: [];
            } elseif (is_array($value)) {
                $phrases = $value;
            } else {
                continue;
            }

            $categoryPhrases = [];
            $seen = [];

            foreach ($phrases as $phrase) {
                if (! is_string($phrase)) {
                    continue;
                }

                $phrase = strip_tags($phrase);
                $phrase = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $phrase) ?? $phrase;
                $phrase = trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase);

                if ($phrase === '') {
                    continue;
                }

                $normalized = self::normalizePhrase($phrase);

                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }

                $seen[$normalized] = true;
                $categoryPhrases[] = $phrase;
            }

            if ($categoryPhrases !== []) {
                $sanitized[$category] = $categoryPhrases;
            }
        }

        return $sanitized;
    }

    public function matchCategory(string $line): ?string
    {
        $line = preg_replace('/^\s*#{1,6}\s*/u', '', trim($line)) ?? $line;
        $line = preg_replace('/^\s*(?:[-*•]\s+|\d+[.)]\s+)/u', '', $line) ?? $line;
        $normalizedLine = self::normalizePhrase($line);

        if ($normalizedLine === '') {
            return null;
        }

        foreach ($this->normalizedKeywordLists as $category => $phrases) {
            foreach ($phrases as $phrase) {
                if (
                    $phrase !== ''
                    && (
                        $normalizedLine === $phrase
                        || strpos($normalizedLine, $phrase . ' ') === 0
                    )
                ) {
                    return $category;
                }
            }
        }

        return null;
    }

    public function isWeeklyMassTimesTableHeader(string $line): bool
    {
        if (strpos($line, '|') === false) {
            return false;
        }

        $cells = preg_split('/[|\t]+/u', trim($line, " \t|")) ?: [];
        $normalizedCells = array_map(
            static fn (string $cell): string => self::normalizePhrase($cell),
            $cells
        );
        $hasDay = in_array('day', $normalizedCells, true)
            || in_array('weekday', $normalizedCells, true);
        $hasTime = in_array('time', $normalizedCells, true);
        $hasMassOrService = false;

        foreach ($normalizedCells as $cell) {
            if (preg_match('/\b(?:mass|service)\b/u', $cell)) {
                $hasMassOrService = true;
                break;
            }
        }

        return $hasDay && $hasTime && $hasMassOrService;
    }

    public function isWeeklyMassTimesTableRow(string $line): bool
    {
        $line = preg_replace('/^\s*(?:[-*•]\s+|\d+[.)]\s+)/u', '', trim($line)) ?? $line;

        if (! preg_match('/^' . self::WEEKDAY_PATTERN . '\b\.?/iu', $line, $weekdayMatch)) {
            return false;
        }

        $afterWeekday = ltrim(
            substr($line, strlen($weekdayMatch[0])),
            " \t|,:-–—"
        );

        if (
            ! preg_match(
                '/^(?:at\s+)?(?:\d{1,2}(?::|\.)\d{2}\s*(?:(?:a|p)\.?m\.?)?|\d{1,2}\s*(?:a|p)\.?m\.?)/iu',
                $afterWeekday
            )
            || ! preg_match('/\b(?:mass|service)\b/iu', $line)
        ) {
            return false;
        }

        return ! (bool) preg_match(
            '/\b\d{1,2}(?:st|nd|rd|th)?\s+' . self::MONTH_PATTERN . '\b|\b\d{1,2}[.\/-]\d{1,2}(?:[.\/-]\d{2,4})?\b/iu',
            $line
        );
    }

    private static function normalizePhrase(string $phrase): string
    {
        $phrase = function_exists('mb_strtolower')
            ? mb_strtolower($phrase, 'UTF-8')
            : strtolower($phrase);
        $phrase = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $phrase) ?? $phrase;

        return trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase);
    }
}
