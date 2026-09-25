<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Publishing;

use ADCT\ParishIntake\Core\Events\EventDetails;
use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
use DomainException;
use RuntimeException;

final class CandidatePublisher
{
    public function __construct(
        private PublicationStoreInterface $store,
        private EventValidator $validator
    ) {
    }

    public function publish(int $candidateId): int
    {
        if ($candidateId < 1) {
            throw new \InvalidArgumentException('A candidate ID must be positive.');
        }

        return $this->store->publish($candidateId, fn (array $row): Publication => $this->prepare($candidateId, $row));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function prepare(int $id, array $row): Publication
    {
        if ((int) ($row['id'] ?? 0) !== $id) {
            throw new RuntimeException('The locked candidate does not match the requested ID.');
        }

        $status = $row['status'] ?? null;
        $via = $row['approved_via'] ?? null;
        if (
            ! in_array($status, ['awaiting_approval', 'approved', 'published'], true)
            || ! in_array($via, ['dean', 'reviewer', 'self'], true)
            || ! is_string($row['approved_by'] ?? null)
            || trim($row['approved_by']) === ''
            || ! is_string($row['approved_at'] ?? null)
            || trim($row['approved_at']) === ''
        ) {
            throw new DomainException('Publication requires a recorded dean, reviewer or self approval.');
        }

        $kind = $row['match_kind'] ?? null;
        if (! in_array($kind, ['new', 'update', 'cancellation', 'postponement'], true)) {
            throw new DomainException('The candidate has no publishable match kind.');
        }

        $eventId = $this->optionalId($row['match_event_id'] ?? null);
        if (($kind === 'new' && $eventId !== null && $status !== 'published')
            || ($kind !== 'new' && $eventId === null)
            || ($status === 'published' && $eventId === null)) {
            throw new DomainException('The candidate must target an existing event only for a change.');
        }

        $fields = $this->jsonObject($row['fields'] ?? null, 'fields');
        $recurrence = $this->jsonObject($row['recurrence'] ?? null, 'recurrence');
        $title = $fields['title'] ?? null;
        if (! is_string($title) || trim($title) === '') {
            throw new DomainException('The candidate needs a title before publication.');
        }

        $date = $this->text($fields, 'event_date');
        $time = $this->text($fields, 'event_time', '00:00');
        $endDate = $this->text($fields, 'event_end_date', $date);
        $endTime = $this->text($fields, 'event_end_time', $time);
        $allDay = $this->boolean($fields, 'all_day', ! isset($fields['event_time']));
        $contact = $fields['contact'] ?? [];
        if (is_string($contact)) {
            // Parser contact text is not a structured public contact record.
            $contact = [];
        }
        if (! is_array($contact)) {
            throw new DomainException('The candidate contact must be structured data.');
        }

        $statusFlag = match ($kind) {
            'cancellation' => 'cancelled',
            'postponement' => 'postponed',
            default => $this->text($fields, 'status_flag', 'scheduled'),
        };
        $details = new EventDetails(
            $this->optionalId($fields['parish_id'] ?? $row['parish_id'] ?? null),
            $this->optionalId($fields['venue_id'] ?? null),
            $date . 'T' . $time,
            isset($fields['event_end_date']) || isset($fields['event_end_time'])
                ? $endDate . 'T' . $endTime : null,
            $allDay,
            $this->text($recurrence, 'rrule', '') ?: null,
            $this->stringList($fields, 'exdates'),
            $this->stringList($fields, 'rdates'),
            $this->boolean($fields, 'featured', false),
            $statusFlag,
            $id,
            $contact
        );
        $result = $this->validator->validate($details);
        if (! $result->isValid()) {
            throw new DomainException('The candidate event is invalid: ' . implode(' ', $result->errors));
        }

        $values = $result->values;
        $eventType = $this->text($fields, 'event_type');
        return new Publication(
            $id,
            $eventId,
            $kind,
            trim($row['approved_by']),
            trim($title),
            $this->text($fields, 'description', ''),
            new EventDetails(
                $values['parish_id'],
                $values['venue_id'],
                $values['start_local'],
                $values['end_local'],
                $values['all_day'],
                $values['rrule'],
                $values['exdates'],
                $values['rdates'],
                $values['featured'],
                $values['status_flag'],
                $id,
                $values['contact']
            ),
            $eventType === '' ? null : $eventType
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(mixed $value, string $label): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_string($value)) {
            throw new DomainException('The candidate ' . $label . ' must be JSON.');
        }
        $object = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
        if (! $object instanceof \stdClass) {
            throw new DomainException('The candidate ' . $label . ' must be a JSON object.');
        }
        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if ((! is_int($value) && (! is_string($value) || preg_match('/^[1-9]\d*$/D', $value) !== 1))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException('The candidate contains an invalid ID.');
        }
        return (int) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        if (! is_string($value)) {
            throw new DomainException('The candidate ' . $key . ' must be text.');
        }
        return trim($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function boolean(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;
        if (! is_bool($value)) {
            throw new DomainException('The candidate ' . $key . ' must be a boolean.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException('The candidate ' . $key . ' must be a list.');
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new DomainException('The candidate ' . $key . ' must contain text.');
            }
        }
        return $value;
    }
}
