<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Matching;

final class EventNoticeMatcher
{
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
            $sameRule = is_string($rule) && $rule !== '' && $rule === $oldRule;
            $sameDate = isset($fields['event_date'], $old['event_date'])
                && $fields['event_date'] === $old['event_date'];
            $dateScore = $sameRule ? 1.0 : ($sameDate ? 1.0 : 0.0);
            if ($dateScore === 0.0 && $explicitChange && $titleScore >= 0.95
                && $this->nearbyDate($fields['event_date'] ?? null, $old['event_date'] ?? null)) {
                $dateScore = 0.7;
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
                $best = ['row' => $row, 'score' => $score, 'same_rule' => $sameRule, 'same_date' => $sameDate];
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
            return array_merge($none, [
                'note' => 'Possible change to pending candidate ' . $pendingId . ' requires manual review.',
                'score' => $best['score'],
            ]);
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
        $title = preg_replace('/^(?:cancellation|cancelled|postponement|postponed|update)\s*:\s*/i', '', $title) ?? $title;
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title) ?? $title;
        return substr(trim($title), 0, 120);
    }

    private function nearbyDate(mixed $first, mixed $second): bool
    {
        if (! is_string($first) || ! is_string($second)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $first) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $second) !== 1) {
            return false;
        }
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $first);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $second);
        return $a !== false && $b !== false && abs($a->getTimestamp() - $b->getTimestamp()) <= 60 * 86400;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function sameDetails(array $a, array $b): bool
    {
        foreach (['event_time', 'event_end_time', 'venue_id', 'venue', 'description'] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
