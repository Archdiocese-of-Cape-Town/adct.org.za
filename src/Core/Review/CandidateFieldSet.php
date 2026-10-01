<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Review;

use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * Maps the stored `fields` and `recurrence` JSON of an event candidate onto the
 * editable inputs of the review screen, and back again.
 *
 * Only the keys in {@see self::EDITABLE_KEYS} may be changed by a reviewer. Every
 * other key the parser or the store wrote is carried across untouched, so a
 * reviewer's correction never silently drops provenance, geocoding, screening or
 * AI-fill bookkeeping.
 */
final class CandidateFieldSet
{
    public const TIMEZONE = 'Africa/Johannesburg';

    public const STATUS_FLAGS = ['scheduled', 'cancelled', 'postponed'];

    /**
     * The scalar keys a reviewer may change on the candidate form.
     */
    public const EDITABLE_KEYS = [
        'title',
        'event_date',
        'event_time',
        'event_end_date',
        'event_end_time',
        'parish_id',
        'venue_id',
        'description',
        'event_type',
        'featured',
        'status_flag',
        'exdates',
        'rdates',
    ];

    /**
     * Parses the stored JSON of a candidate row defensively. A candidate whose
     * `fields` column is unusable is still readable on the detail screen; the
     * editor just starts from the keys that can be recovered.
     *
     * @return array<string, mixed>
     */
    public static function decodeFields(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $recurrence
     * @return array<string, mixed>
     */
    public static function fromFields(array $fields, array $recurrence = []): self
    {
        $allDay = self::storedAllDay($fields);
        $preset = (new RRulePresetMapper())->fromRRule(self::rruleOf($recurrence));

        return new self(
            title: self::stringValue($fields['title'] ?? ''),
            eventDate: self::toEditDate($fields['event_date'] ?? null),
            eventTime: self::stringValue($fields['event_time'] ?? ''),
            eventEndDate: self::toEditDate($fields['event_end_date'] ?? null),
            eventEndTime: self::stringValue($fields['event_end_time'] ?? ''),
            allDay: $allDay,
            parishId: self::intOrNull($fields['parish_id'] ?? null),
            venueId: self::intOrNull($fields['venue_id'] ?? null),
            description: self::stringValue($fields['description'] ?? ''),
            eventType: self::stringValue($fields['event_type'] ?? ''),
            featured: (bool) ($fields['featured'] ?? false),
            statusFlag: self::statusFlagOf($fields),
            exdates: self::stringList($fields['exdates'] ?? []),
            rdates: self::stringList($fields['rdates'] ?? []),
            contact: self::contactOf($fields['contact'] ?? null),
            recurrencePreset: $preset['preset'],
            recurrenceWeekday: $preset['weekday'],
            recurrenceOrdinal: $preset['ordinal'],
            recurrenceMonthDay: $preset['month_day'],
            recurrenceCustomRule: $preset['custom_rule'],
        );
    }

    /**
     * @param array{name: string, email: string, phone: string} $contact
     */
    public function __construct(
        public readonly string $title,
        public readonly string $eventDate,
        public readonly string $eventTime,
        public readonly string $eventEndDate,
        public readonly string $eventEndTime,
        public readonly bool $allDay,
        public readonly ?int $parishId,
        public readonly ?int $venueId,
        public readonly string $description,
        public readonly string $eventType,
        public readonly bool $featured,
        public readonly string $statusFlag,
        /** @var list<string> */
        public readonly array $exdates,
        /** @var list<string> */
        public readonly array $rdates,
        /** @var array{name: string, email: string, phone: string} */
        public readonly array $contact,
        public readonly string $recurrencePreset,
        public readonly string $recurrenceWeekday,
        public readonly string $recurrenceOrdinal,
        public readonly string $recurrenceMonthDay,
        public readonly string $recurrenceCustomRule,
    ) {
    }

    /**
     * The reviewer's inputs, with the dates still in day-first display form.
     *
     * @return array<string, mixed>
     */
    public function toInputs(): array
    {
        return [
            'title' => $this->title,
            'event_date' => $this->eventDate,
            'event_time' => $this->eventTime,
            'event_end_date' => $this->eventEndDate,
            'event_end_time' => $this->eventEndTime,
            'all_day' => $this->allDay,
            'parish_id' => $this->parishId,
            'venue_id' => $this->venueId,
            'description' => $this->description,
            'event_type' => $this->eventType,
            'featured' => $this->featured,
            'status_flag' => $this->statusFlag,
            'exdates' => $this->exdates,
            'rdates' => $this->rdates,
            'contact' => $this->contact,
            'recurrence_preset' => $this->recurrencePreset,
            'recurrence_weekday' => $this->recurrenceWeekday,
            'recurrence_ordinal' => $this->recurrenceOrdinal,
            'recurrence_month_day' => $this->recurrenceMonthDay,
            'recurrence_custom' => $this->recurrenceCustomRule,
        ];
    }

    /**
     * The stored `all_day` flag, defaulting to the publisher's own rule: an
     * event without a start time is an all-day event.
     *
     * @param array<string, mixed> $fields
     */
    public static function storedAllDay(array $fields): bool
    {
        if (array_key_exists('all_day', $fields)) {
            return (bool) $fields['all_day'];
        }

        return ! isset($fields['event_time']);
    }

    /**
     * `12/10/2026` to `2026-10-12`, or null when the value is not a day-first date.
     */
    public static function toStoredDate(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $parsed = self::parseEditDate($trimmed);

        return $parsed?->format('Y-m-d');
    }

    /**
     * `2026-10-12` to `12/10/2026`, the form the day-first reviewer sees.
     */
    public static function toEditDate(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $trimmed = trim($value);

        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $trimmed, $matches) !== 1) {
            return '';
        }

        if (self::parseEditDate($matches[3] . '/' . $matches[2] . '/' . $matches[1]) === null) {
            return '';
        }

        return $matches[3] . '/' . $matches[2] . '/' . $matches[1];
    }

    /**
     * A strict day-first date parse: `31/02/2026` is rejected rather than rolled
     * forward into March.
     */
    public static function parseEditDate(string $value): ?DateTimeImmutable
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $parsed = DateTimeImmutable::createFromFormat('!d/m/Y', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false) {
            return null;
        }

        // PHP 8.2 returns false instead of an array when there are no warnings.
        if (is_array($errors) && ($errors['warning_count'] ?? 0) > 0) {
            return null;
        }

        return $parsed->format('d/m/Y') === $value ? $parsed : null;
    }

    public static function isValidTime(string $value): bool
    {
        return preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', trim($value)) === 1;
    }

    /**
     * Only `rrule` is read by the publisher; the remaining recurrence keys are
     * display metadata the parser produced.
     */
    public static function rruleOf(array $recurrence): ?string
    {
        $rrule = $recurrence['rrule'] ?? null;

        return is_string($rrule) && trim($rrule) !== '' ? trim($rrule) : null;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function statusFlagOf(array $fields): string
    {
        $flag = $fields['status_flag'] ?? null;

        if (is_string($flag) && in_array($flag, self::STATUS_FLAGS, true)) {
            return $flag;
        }

        return 'scheduled';
    }

    /**
     * @return array{name: string, email: string, phone: string}
     */
    public static function contactOf(mixed $value): array
    {
        $empty = ['name' => '', 'email' => '', 'phone' => ''];

        if (is_string($value)) {
            // The parser stores a free-text contact line. Keep it visible so a
            // reviewer can copy it into the structured fields.
            return ['name' => trim($value), 'email' => '', 'phone' => ''];
        }

        if (! is_array($value)) {
            return $empty;
        }

        return [
            'name' => self::stringValue($value['name'] ?? ''),
            'email' => self::stringValue($value['email'] ?? ''),
            'phone' => self::stringValue($value['phone'] ?? ''),
        ];
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $values = [];

        foreach ($value as $entry) {
            $trimmed = self::stringValue($entry);

            if ($trimmed !== '' && ! in_array($trimmed, $values, true)) {
                $values[] = $trimmed;
            }
        }

        return $values;
    }

    public static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A\d+\z/D', trim($value)) !== 1) {
            return null;
        }

        $parsed = (int) trim($value);

        return $parsed > 0 ? $parsed : null;
    }

    public static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
