<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Matching;

use ADCT\ParishIntake\Core\Events\EventDetails;
use ADCT\ParishIntake\Core\Events\OccurrenceExpander;
use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

final class EventNoticeMatcher
{
    private DateTimeZone $timezone;
    private OccurrenceExpander $occurrences;

    public function __construct(
        ?DateTimeZone $timezone = null,
        ?OccurrenceExpander $occurrences = null
    ) {
        $this->timezone = $timezone ?? new DateTimeZone('Africa/Johannesburg');
        $this->occurrences = $occurrences ?? new OccurrenceExpander($this->timezone);
    }

    /**
     * @param array<string, mixed> $incoming
     * @param list<array<string, mixed>> $existing
     * @return array{kind: string, event_id: int|null, candidate_id: int|null, score: float, note: string}
     */
    public function match(array $incoming, array $existing): array
    {
        $none = ['kind' => 'new', 'event_id' => null, 'candidate_id' => null, 'score' => 0.0, 'note' => ''];
        $fields = $incoming['fields'] ?? [];
        $parish = $incoming['parish_id'] ?? null;
        if (! is_int($parish) || $parish < 1 || ! is_array($fields)) {
            return $none;
        }

        $title = $this->title($fields['title'] ?? null);
        if ($title === '') {
            return $none;
        }
        $notice = strtolower(
            (is_string($fields['source_snippet'] ?? null) ? $fields['source_snippet'] : '')
            . ' ' . (string) ($fields['title'] ?? '')
        );
        $cancel = preg_match('/\b(?:cancelled|canceled|cancellation|no mass this week)\b/i', $notice) === 1;
        $postpone = preg_match('/\b(?:postponed|postponement)\b/i', $notice) === 1;
        $explicitChange = $cancel || $postpone
            || preg_match('/\b(?:rescheduled|changed|new date|new time|updated)\b/i', $notice) === 1;
        $best = null;
        $runnerUp = 0.0;

        foreach ($existing as $row) {
            if (($row['parish_id'] ?? null) !== $parish || ! is_array($row['fields'] ?? null)) {
                continue;
            }
            $old = $row['fields'];
            $oldTitle = $this->title($old['title'] ?? null);
            if ($oldTitle === '') {
                continue;
            }
            $titleScore = 1 - levenshtein($title, $oldTitle) / max(strlen($title), strlen($oldTitle));
            if ($titleScore < 0.78) {
                continue;
            }
            $rule = $incoming['recurrence']['rrule'] ?? null;
            $oldRule = $row['recurrence']['rrule'] ?? null;
            $sameRule = is_string($rule) && $rule !== ''
                && is_string($oldRule) && $oldRule !== ''
                && $this->recurrencesOverlap($fields, $incoming['recurrence'], $old, $row['recurrence']);
            $sameDate = isset($fields['event_date'], $old['event_date'])
                && $fields['event_date'] === $old['event_date'];
            $dateScore = $sameRule ? 1.0 : ($sameDate ? 1.0 : 0.0);
            if ($dateScore === 0.0 && $explicitChange && $titleScore >= 0.95
                && $this->nearbyDate($fields['event_date'] ?? null, $old['event_date'] ?? null)) {
                $dateScore = 0.8;
            }
            if ($dateScore === 0.0) {
                continue;
            }
            $score = round(0.6 * $titleScore + 0.4 * $dateScore, 3);
            if ($best !== null && $score > $best['score']) {
                $runnerUp = $best['score'];
            } elseif ($best !== null && $score > $runnerUp) {
                $runnerUp = $score;
            }
            if ($best === null || $score > $best['score']) {
                $best = [
                    'row' => $row,
                    'score' => $score,
                    'same_rule' => $sameRule,
                    'same_date' => $sameDate,
                ];
            }
        }

        if ($best === null || $best['score'] < 0.88 || $best['score'] - $runnerUp < 0.08) {
            return $best === null ? $none : array_merge($none, [
                'note' => 'Possible repeat requires manual review; no event was selected.',
                'score' => $best['score'],
            ]);
        }

        $row = $best['row'];
        $eventId = $row['event_id'] ?? null;
        $pendingId = $eventId === null ? ($row['id'] ?? null) : null;
        $unchanged = ! $cancel && ! $postpone
            && ($best['same_date'] || $best['same_rule'])
            && ($incoming['recurrence']['rrule'] ?? null) === ($row['recurrence']['rrule'] ?? null)
            && $this->sameDetails($fields, $row['fields']);
        $kind = $cancel ? 'cancellation' : ($postpone ? 'postponement' : ($unchanged ? 'duplicate' : 'update'));

        if ($eventId === null && $kind !== 'duplicate') {
            return [
                'kind' => $kind,
                'event_id' => null,
                'candidate_id' => $pendingId,
                'score' => $best['score'],
                'note' => 'Possible ' . $kind . ' of pending candidate ' . $pendingId
                    . ' requires manual review.',
            ];
        }

        return [
            'kind' => $kind,
            'event_id' => $eventId,
            'candidate_id' => $pendingId,
            'score' => $best['score'],
            'note' => '',
        ];
    }

    private function title(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $title = strtolower(trim($value));
        $title = preg_replace('/^(?:cancellation|cancelled|canceled|postponement|postponed|update)\s*:\s*/i', '', $title) ?? $title;
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title) ?? $title;
        return substr(trim($title), 0, 120);
    }

    private function nearbyDate(mixed $first, mixed $second): bool
    {
        if (! is_string($first) || ! is_string($second)) {
            return false;
        }
        $a = $this->parseDate($first);
        $b = $this->parseDate($second);
        return $a !== false && $b !== false && abs($a->getTimestamp() - $b->getTimestamp()) <= 60 * 86400;
    }

    /**
     * @param array<string, mixed> $firstFields
     * @param array<string, mixed> $firstRecurrence
     * @param array<string, mixed> $secondFields
     * @param array<string, mixed> $secondRecurrence
     */
    private function recurrencesOverlap(
        array $firstFields,
        array $firstRecurrence,
        array $secondFields,
        array $secondRecurrence
    ): bool {
        if (
            ($firstRecurrence['ambiguous'] ?? false) === true
            || ($secondRecurrence['ambiguous'] ?? false) === true
        ) {
            return false;
        }
        $firstRule = $firstRecurrence['rrule'] ?? null;
        $secondRule = $secondRecurrence['rrule'] ?? null;
        $firstDate = $this->parseDate($firstFields['event_date'] ?? null);
        $secondDate = $this->parseDate($secondFields['event_date'] ?? null);
        if (
            ! is_string($firstRule)
            || ! is_string($secondRule)
            || $firstRule === ''
            || $firstRule !== $secondRule
            || $firstDate === false
            || $secondDate === false
        ) {
            return false;
        }

        $windowStart = $firstDate > $secondDate ? $firstDate : $secondDate;
        $window = new OccurrenceWindow($windowStart, $windowStart->modify('+12 months'));
        try {
            $first = $this->occurrences->expand(
                $this->eventDetails($firstFields, $firstRule),
                $window
            );
            $second = $this->occurrences->expand(
                $this->eventDetails($secondFields, $secondRule),
                $window
            );
        } catch (DomainException | InvalidArgumentException) {
            return false;
        }

        $firstDates = [];
        foreach ($first as $occurrence) {
            $firstDates[$occurrence->startLocal->format('Y-m-d')] = true;
        }
        foreach ($second as $occurrence) {
            if (isset($firstDates[$occurrence->startLocal->format('Y-m-d')])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function eventDetails(array $fields, string $rule): EventDetails
    {
        $allDay = ($fields['all_day'] ?? ! isset($fields['event_time'])) === true;
        $time = $fields['event_time'] ?? '00:00';
        if (! is_string($time) || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $time) !== 1) {
            $time = '00:00';
        }
        return new EventDetails(
            null,
            null,
            $fields['event_date'] . 'T' . ($allDay ? '00:00' : $time),
            null,
            $allDay,
            $rule,
            $this->dateList($fields['exdates'] ?? []),
            $this->dateList($fields['rdates'] ?? []),
            false,
            'scheduled',
            null,
            []
        );
    }

    /**
     * @return list<string>
     */
    private function dateList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        return array_values(array_filter($value, static fn (mixed $date): bool => is_string($date)));
    }

    private function parseDate(mixed $value): DateTimeImmutable|false
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            return false;
        }
        return $date;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function sameDetails(array $a, array $b): bool
    {
        foreach ([
            'event_time',
            'event_end_date',
            'event_end_time',
            'all_day',
            'venue_id',
            'venue',
            'venue_text',
            'venue_address',
            'venue_suburb',
            'description',
        ] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return false;
            }
        }
        return $this->sameDateList($a['exdates'] ?? [], $b['exdates'] ?? [])
            && $this->sameDateList($a['rdates'] ?? [], $b['rdates'] ?? []);
    }

    private function sameDateList(mixed $a, mixed $b): bool
    {
        if (! is_array($a) || ! array_is_list($a) || ! is_array($b) || ! array_is_list($b)) {
            return false;
        }
        foreach ([$a, $b] as $dates) {
            foreach ($dates as $date) {
                if (! is_string($date)) {
                    return false;
                }
            }
        }
        sort($a, SORT_STRING);
        sort($b, SORT_STRING);
        return $a === $b;
    }
}
