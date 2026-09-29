<?php

namespace ADCT\ParishIntake\Core\Parsing;

/**
 * Finds the masthead of a parish bulletin: the publication date in the opening of a
 * document or in its subject line.
 *
 * Three shapes are understood, tried widest-first so a day range is not mistaken for
 * the single date it begins with:
 *
 *  1. a day range, "28 September to 4 October 2026";
 *  2. a single date, "1 September 2026";
 *  3. a bare month and year, "September 2026".
 *
 * Only forms 2 and 3 are needed by a masthead, but all three are accepted so a range
 * is read as a range. A masthead is only honoured alongside the word "bulletin" or
 * "newsletter": honouring any "Month YYYY" would let an ordinary sentence about a
 * future year hijack the reference date.
 *
 * The match is a document-level fact rather than a per-event one, so both the block
 * splitter (which lifts the masthead out of the body) and the extraction stage (which
 * turns it into a reference date) read it from here. #133 existed because the two
 * disagreed about which forms exist.
 */
final class BulletinMastheadMatcher
{
    private const MONTH_PATTERN = '(?:January|Jan|February|Feb|March|Mar|April|Apr|May|June|Jun|July|Jul|August|Aug|September|Sept|Sep|October|Oct|November|Nov|December|Dec)';
    private const HEADER_CHARS = 512;

    /**
     * @return array{month: string, year: int, day: int, end_month: int|null, date: \DateTimeImmutable, offset: int, start_line: int, match: string}|null
     */
    public function match(string $text): ?array
    {
        if (trim($text) === '') {
            return null;
        }

        $header = substr($text, 0, self::HEADER_CHARS);
        $month = self::MONTH_PATTERN;
        $ordinal = '(?:st|nd|rd|th)?';
        $shapes = [
            'range' => [
                'pattern' => '~\b(?:bulletin|newsletter)\b[\s\S]{0,120}?\b(?<start_day>\d{1,2})' . $ordinal
                    . '\s+(?<start_month>' . $month . ')\.?\s+(?:to|[-–])\s*(?<end_day>\d{1,2})' . $ordinal
                    . '\s+(?<end_month>' . $month . ')\.?,?\s+(?<year>\d{4})\b~iu',
                'has_day' => true,
            ],
            'single' => [
                'pattern' => '~\b(?:bulletin|newsletter)\b[\s\S]{0,120}?\b(?<day>\d{1,2})' . $ordinal
                    . '\s+(?<month>' . $month . ')\.?,?\s+(?<year>\d{4})\b~iu',
                'has_day' => true,
            ],
            'month_year' => [
                'pattern' => '~\b(?:bulletin|newsletter)\b[\s\S]{0,120}?\b(?<month>' . $month . ')\.?,?\s+(?<year>\d{4})\b~iu',
                'has_day' => false,
            ],
        ];

        foreach ($shapes as $shape) {
            if (! preg_match($shape['pattern'], $header, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
                continue;
            }

            $found = $this->build($matches, $shape['has_day'], $matches[0][1], $text);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string|int, array{0: string, 1: int}|null> $matches
     * @return array{month: string, year: int, day: int, end_month: int|null, date: \DateTimeImmutable, offset: int, start_line: int, match: string}|null
     */
    private function build(array $matches, bool $hasDay, int $offset, string $text): ?array
    {
        $year = (int) ($matches['year'][0] ?? '');

        // A year is only credible if it is one the bulletin could plausibly cover.
        // This rejects stray numbers such as a phone number or a bank account without
        // needing to know today's date.
        if ($year < 1900 || $year > 2200) {
            return null;
        }

        $monthText = isset($matches['start_month']) ? $matches['start_month'][0] : $matches['month'][0];
        $month = $this->monthNumber($monthText);

        if ($month === null) {
            return null;
        }

        $endMonth = isset($matches['end_month']) && $matches['end_month'] !== null
            ? $this->monthNumber($matches['end_month'][0])
            : null;

        // A bare month and year has no day, so the first of the month is used. It is
        // only ever a reference point, never a published event date.
        $dayKey = isset($matches['start_day']) ? 'start_day' : 'day';
        $day = $hasDay ? (int) $matches[$dayKey][0] : 1;
        $date = $this->makeDate($year, $month, $day);

        if ($date !== null && $endMonth !== null && $month > $endMonth) {
            // The single year written belongs to the end month, so in
            // "28 December to 4 January 2027" the 4 January is in 2027 and the 28
            // December is in 2026, not the following year.
            $date = $this->makeDate($year - 1, $month, $day);
        }

        if ($date === null) {
            return null;
        }

        return [
            'month' => $monthText,
            'year' => $year,
            'day' => $day,
            'end_month' => $endMonth,
            'date' => $date,
            'offset' => $offset,
            // Which line the masthead begins on, so a caller working line by line knows
            // where the header starts. The match itself runs to the date, which for a
            // two-line masthead is the following line.
            'start_line' => substr_count(substr($text, 0, $offset), "\n"),
            'match' => $matches[0][0],
        ];
    }

    private function monthNumber(string $month): ?int
    {
        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];
        $key = strtolower(substr($month, 0, 3));

        return $months[$key] ?? null;
    }

    private function makeDate(int $year, int $month, int $day): ?\DateTimeImmutable
    {
        if ($year < 1 || ! checkdate($month, $day, $year)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%04d-%02d-%02d', $year, $month, $day),
            new \DateTimeZone('Africa/Johannesburg')
        );

        return $date instanceof \DateTimeImmutable ? $date : null;
    }
}
