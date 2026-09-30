<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Review;

use ADCT\ParishIntake\Core\Events\EventDetails;
use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Events\RecurrenceSummary;
use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use ADCT\ParishIntake\Core\Events\RRuleValidator;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Validates a reviewer's candidate edit and produces the `fields` and
 * `recurrence` JSON to store.
 *
 * The validator is deliberately stricter than the parser: it runs the same
 * {@see EventValidator} the publisher runs, so a reviewer cannot save a
 * candidate that would later fail to publish. Every key the reviewer did not
 * submit is carried across from the stored candidate unchanged.
 */
final class CandidateEditValidator
{
    private const TITLE_LIMIT = 255;
    private const DESCRIPTION_LIMIT = 2000;
    private const EVENT_TYPE_LIMIT = 100;
    private const EXDATE_LIMIT = 100;
    private const RDATE_LIMIT = 100;

    private EventValidator $events;
    private RRulePresetMapper $presets;

    public function __construct(?EventValidator $events = null, ?RRulePresetMapper $presets = null)
    {
        $timezone = new DateTimeZone(CandidateFieldSet::TIMEZONE);
        $this->events = $events ?? new EventValidator($timezone);
        $this->presets = $presets ?? new RRulePresetMapper();
    }

    /**
     * @param array<string, mixed> $storedFields    the candidate's current `fields` JSON
     * @param array<string, mixed> $storedRecurrence the candidate's current `recurrence` JSON
     * @param array<string, mixed> $form            the unslashed candidate form
     */
    public function validate(array $storedFields, array $storedRecurrence, array $form): CandidateEditResult
    {
        $submitted = $this->readForm($form);
        $errors = [];

        $title = $this->limitedText($submitted['title'], self::TITLE_LIMIT, $errors, 'title');
        $eventType = $this->limitedText($submitted['event_type'], self::EVENT_TYPE_LIMIT, $errors, 'event_type');
        $description = $this->limitedText(
            $submitted['description'],
            self::DESCRIPTION_LIMIT,
            $errors,
            'description'
        );

        $allDay = $this->isChecked($form['all_day'] ?? null);
        $date = $this->date($submitted['event_date'], $errors, 'event_date', 'Enter a valid date (DD/MM/YYYY).');
        $endDate = $this->optionalDate($submitted['event_end_date'], $errors, 'event_end_date');
        $time = $this->time($submitted['event_time'], $errors, 'event_time');
        $endTime = $this->time($submitted['event_end_time'], $errors, 'event_end_time');

        $rrule = $this->recurrenceRule($form, $errors);
        $rrule = $rrule === null ? null : $this->publishableUntil($rrule, $allDay);
        $exdates = $this->dateList($submitted['exdates'], self::EXDATE_LIMIT, $errors, 'exdates', 'excluded');
        $rdates = $this->dateList(
            $submitted['rdates'],
            self::RDATE_LIMIT,
            $errors,
            'rdates',
            'additional'
        );
        $contact = $this->contact($form, $errors);

        $parishId = $this->positiveId($form['parish_id'] ?? null, $errors, 'parish_id');
        $venueId = $this->positiveId($form['venue_id'] ?? null, $errors, 'venue_id');
        $statusFlag = array_key_exists('status_flag', $form)
            ? CandidateFieldSet::stringValue($form['status_flag'])
            : CandidateFieldSet::statusFlagOf($storedFields);

        if (! in_array($statusFlag, CandidateFieldSet::STATUS_FLAGS, true)) {
            $errors['status_flag'] = 'Choose scheduled, cancelled or postponed.';
            $statusFlag = 'scheduled';
        }

        if ($date === null) {
            $errors['event_date'] ??= 'Enter a valid date (DD/MM/YYYY).';
        }

        // CandidatePublisher sets an end only when one of the two end keys is
        // present, and falls back to the start date and start time for whichever
        // half the reviewer left out. Build the same value here so the editor
        // validates exactly what will be published.
        $hasEnd = $endDate !== null || $endTime !== null;
        $endLocal = $hasEnd
            ? ($endDate ?? $date ?? '1970-01-01') . 'T' . ($endTime ?? $time ?? '00:00')
            : null;

        $validation = $this->events->validate(new EventDetails(
            $parishId,
            $venueId,
            ($date ?? '1970-01-01') . 'T' . ($time ?? '00:00'),
            $endLocal,
            $allDay,
            $rrule,
            $exdates,
            $rdates,
            $this->isChecked($form['featured'] ?? null),
            $statusFlag,
            null,
            $contact
        ));

        foreach ($validation->errors as $message) {
            $this->attachError($errors, $message);
        }

        $inputs = $this->inputs($form, $submitted, $statusFlag);

        if ($errors !== []) {
                    return new CandidateEditResult([], [], $errors, [], $inputs);
                }

        // The validator's own normalised values are stored, not the submitted
        // ones, so a timed event can never keep a stale 09:00 exception date and
        // the end is compared with the same precision the publisher will use.
        $values = $this->merge($storedFields, [
            'title' => $title,
            'event_date' => $date,
            'event_type' => $eventType,
            'description' => $description,
            'all_day' => $allDay,
            'status_flag' => $statusFlag,
            'featured' => (bool) $validation->values['featured'],
            'exdates' => $validation->values['exdates'],
            'rdates' => $validation->values['rdates'],
            'contact' => $validation->values['contact'],
        ], $time, $endDate, $endTime, $parishId, $venueId, $allDay);
        $recurrence = $this->mergeRecurrence($storedRecurrence, $rrule, $allDay);
        $changed = $this->changedKeys($storedFields, $storedRecurrence, $values, $recurrence);

        return new CandidateEditResult($values, $recurrence, [], $changed, $inputs);
    }

    /**
     * The form controls as the editor re-renders them, without validating them.
     *
     * This is derived from the raw request rather than the validated result so a
     * rejected edit still shows the reviewer's own day-first dates and
     * recurrence selection instead of snapping back to the stored values.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $fallbackFields    used when the form is empty
     * @param array<string, mixed> $fallbackRecurrence
     */
    public function inputsFrom(
        array $form,
        array $fallbackFields = [],
        array $fallbackRecurrence = []
    ): CandidateFieldSet {
        $stored = CandidateFieldSet::fromFields($fallbackFields, $fallbackRecurrence);

        if ($form === []) {
            return $stored;
        }

        $statusFlag = CandidateFieldSet::stringValue($form['status_flag'] ?? '');

        return $this->inputs(
            $form,
            $this->readForm($form),
            in_array($statusFlag, CandidateFieldSet::STATUS_FLAGS, true) ? $statusFlag : $stored->statusFlag
        );
    }

    /**
     * @return array<string, string>
     */
    private function readForm(array $form): array
    {
        $submitted = [];

        foreach (['title', 'event_date', 'event_time', 'event_end_date', 'event_end_time', 'event_type', 'description', 'exdates', 'rdates'] as $key) {
            $submitted[$key] = is_scalar($form[$key] ?? null) ? trim((string) $form[$key]) : '';
        }

        // Textareas carry one date per line; the form posts them as arrays only
        // when a helper has already split them.
        foreach (['exdates', 'rdates'] as $key) {
            if (is_array($form[$key] ?? null)) {
                $submitted[$key] = implode("\n", CandidateFieldSet::stringList($form[$key]));
            }
        }

        return $submitted;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $submitted
     */
    private function inputs(array $form, array $submitted, string $statusFlag): CandidateFieldSet
    {
        $preset = $this->presetValues($form);

        return new CandidateFieldSet(
            title: $submitted['title'],
            eventDate: $submitted['event_date'],
            eventTime: $submitted['event_time'],
            eventEndDate: $submitted['event_end_date'],
            eventEndTime: $submitted['event_end_time'],
            allDay: $this->isChecked($form['all_day'] ?? null),
            parishId: CandidateFieldSet::intOrNull($form['parish_id'] ?? null),
            venueId: CandidateFieldSet::intOrNull($form['venue_id'] ?? null),
            description: $submitted['description'],
            eventType: $submitted['event_type'],
            featured: $this->isChecked($form['featured'] ?? null),
            statusFlag: $statusFlag,
            exdates: CandidateFieldSet::stringList($submitted['exdates']),
            rdates: CandidateFieldSet::stringList($submitted['rdates']),
            contact: CandidateFieldSet::contactOf($this->contactFields($form)),
            recurrencePreset: $preset['preset'],
            recurrenceWeekday: $preset['weekday'],
            recurrenceOrdinal: $preset['ordinal'],
            recurrenceMonthDay: $preset['month_day'],
            recurrenceCustomRule: $preset['custom_rule'],
        );
    }

    /**
     * @param array<string, mixed> $form
     * @return array{preset: string, weekday: string, ordinal: string, month_day: string, custom_rule: string}
     */
    private function presetValues(array $form): array
    {
        $defaults = ['preset' => 'none', 'weekday' => 'MO', 'ordinal' => '1', 'month_day' => '1', 'custom_rule' => ''];

        if (! array_key_exists('recurrence_preset', $form)) {
            return $defaults;
        }

        return [
            'preset' => CandidateFieldSet::stringValue($form['recurrence_preset'] ?? '') ?: 'none',
            'weekday' => CandidateFieldSet::stringValue($form['recurrence_weekday'] ?? '') ?: 'MO',
            'ordinal' => CandidateFieldSet::stringValue($form['recurrence_ordinal'] ?? '') ?: '1',
            'month_day' => CandidateFieldSet::stringValue($form['recurrence_month_day'] ?? '') ?: '1',
            'custom_rule' => CandidateFieldSet::stringValue($form['recurrence_custom'] ?? ''),
        ] + $defaults;
    }

    /**
     * The rule the reviewer's recurrence controls produce. An unsupported
     * combination is reported against the control rather than thrown, so the
     * form can be re-rendered with the message attached.
     *
     * @param array<string, mixed> $form
     * @param array<string, string> $errors
     */
    private function recurrenceRule(array $form, array &$errors): ?string
    {
        if (! array_key_exists('recurrence_preset', $form)) {
            return null;
        }

        $preset = $this->presetValues($form);

        try {
            $rrule = $this->presets->toRRule(
                $preset['preset'],
                $preset['weekday'],
                $preset['ordinal'],
                $preset['month_day'],
                $preset['custom_rule']
            );
        } catch (InvalidArgumentException $rejection) {
            $errors['recurrence_preset'] = $rejection->getMessage();

            return null;
        }

        return $rrule === null || trim($rrule) === '' ? null : trim($rrule);
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $editable
     * @return array<string, mixed>
     */
    private function merge(
        array $stored,
        array $editable,
        ?string $time,
        ?string $endDate,
        ?string $endTime,
        ?int $parishId,
        ?int $venueId,
        bool $allDay
    ): array {
        $fields = $stored;

        foreach ($editable as $key => $value) {
            $fields[$key] = $value;
        }

        // A time range is only meaningful when one of the end keys is set, which
        // is exactly how CandidatePublisher decides whether an end exists.
        $this->assign($fields, 'event_time', $time);
        $this->assign($fields, 'event_end_date', $endDate);
        $this->assign($fields, 'event_end_time', $endTime);
        $this->assign($fields, 'parish_id', $parishId);
        $this->assign($fields, 'venue_id', $venueId);

        if ($allDay) {
            // An all-day event must not carry a start time: the publisher's
            // fallback for `all_day` is the presence of `event_time`.
            unset($fields['event_time'], $fields['event_end_time']);
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function assign(array &$fields, string $key, mixed $value): void
    {
        if ($value === null) {
            unset($fields[$key]);

            return;
        }

        $fields[$key] = $value;
    }

    /**
     * Keeps the parser's display metadata and rewrites only the rule itself, so
     * a reviewer's edit never loses the recurrence text or BYDAY detail the
     * parser recorded. The frequency and interval always describe the rule that
     * is actually stored, so the detail screen cannot show stale metadata.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function mergeRecurrence(array $stored, ?string $rrule, bool $allDay): array
    {
        if ($rrule === null) {
            return [];
        }

        $validation = (new RRuleValidator())->validate($rrule, $allDay);

        if (! $validation->isValid() || $validation->normalizedRule === null) {
            // EventValidator already reports this against the control; the rule
            // is stored unchanged so the reviewer can see what they typed.
            return ['rrule' => trim($rrule)];
        }

        $recurrence = $stored;
                $recurrence['rrule'] = $this->publishableUntil(
                    (string) $validation->normalizedRule,
                    $allDay
                );

        if (isset($recurrence['until']) && is_string($recurrence['until'])) {
                    // A bare `UNTIL` value needs the same treatment as the one inside the
                    // rule, so it is normalised through the same helper.
                    $recurrence['until'] = substr(
                        $this->publishableUntil('UNTIL=' . trim($recurrence['until']), $allDay),
                        strlen('UNTIL=')
                    );
                }

        $frequency = $validation->parts['FREQ'] ?? '';
        $recurrence['frequency'] = $frequency === '' ? '' : strtolower($frequency);
        $recurrence['interval'] = isset($validation->parts['INTERVAL'])
            ? max(1, (int) $validation->parts['INTERVAL'])
            : 1;
        $recurrence['text'] = RecurrenceSummary::describe((string) $recurrence['rrule']);

        // The rule is no longer ambiguous now that a reviewer has set it.
        unset($recurrence['ambiguous']);

        return $recurrence;
    }

    /**
         * The rule as it will be published. {@see RRuleValidator} rejects a date-only
         * `UNTIL` on a timed series and a date-time `UNTIL` on an all-day one, and
         * {@see EventValidator} applies that check to whatever the editor stores, so
         * the bound is corrected to match the all-day flag the reviewer chose. A
         * reviewer who types a bare date means "the series ends that day", not "at
         * midnight", so a timed series widens to the end of that day rather than
         * being rejected. A rule with no bound is returned unchanged.
         */
        private function publishableUntil(string $rule, bool $allDay): string
        {
            return preg_replace_callback(
                '/UNTIL=(\d{8})(?:T\d{6}Z?)?(?![0-9A-Z])/i',
                static fn (array $m): string => 'UNTIL=' . $m[1]
                    . ($allDay ? '' : 'T235959Z'),
                $rule,
                1
            ) ?? $rule;
        }

    /**
     * @param array<string, mixed> $storedFields
         * @param array<string, mixed> $storedRecurrence
         * @param array<string, mixed> $values
         * @param array<string, mixed> $recurrence
         * @return list<string>
         */
        private function changedKeys(
            array $storedFields,
            array $storedRecurrence,
            array $values,
            array $recurrence
        ): array {
            $changed = [];

            // `all_day` is materialised from the checkbox on every save, so comparing it
            // against a stored row that never had the key would report a change the
            // reviewer never made. Toggling it always drops or restores
            // `event_time`, and that is the entry the audit records.
            foreach (CandidateFieldSet::EDITABLE_KEYS as $key) {
                if ($key === 'all_day') {
                    continue;
                }

                if (($storedFields[$key] ?? null) !== ($values[$key] ?? null)) {
                    $changed[] = $key;
                }
            }

            if (array_key_exists('contact', $storedFields)
                && (($storedFields['contact'] ?? null) !== ($values['contact'] ?? null))) {
                $changed[] = 'contact';
            }

            // Recurrence is one control group, so it is one diff entry. Its
            // description, frequency and interval are derived from the rule, so only
            // the rule itself is compared: rewriting it on every save would report a
            // change the reviewer never made.
            if (CandidateFieldSet::rruleOf($storedRecurrence) !== CandidateFieldSet::rruleOf($recurrence)) {
                $changed[] = 'recurrence';
            }

            return array_values(array_unique($changed));
        }

    /**
     * @param array<string, string> $errors
     */
    private function limitedText(string $value, int $limit, array &$errors, string $field): string
    {
        if (preg_match('//u', $value) !== 1) {
            $errors[$field] = 'This text must be valid UTF-8.';

            return '';
        }

        $characters = preg_match_all('/./us', $value);

        if ($characters === false || $characters > $limit) {
            $errors[$field] = 'This text is too long.';

            return '';
        }

        return $value;
    }

    /**
     * @param array<string, string> $errors
     */
    private function date(string $value, array &$errors, string $field, string $message): ?string
    {
        if ($value === '') {
            $errors[$field] = $message;

            return null;
        }

        $stored = CandidateFieldSet::toStoredDate($value);

        if ($stored === null) {
            $errors[$field] = $message;

            return null;
        }

        return $stored;
    }

    /**
     * An end date the reviewer may leave empty; only an unparseable value is an
     * error. An all-day multi-day event needs no end time, because the publisher
     * derives the end from the start date.
     *
     * @param array<string, string> $errors
     */
    private function optionalDate(string $value, array &$errors, string $field): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        $stored = CandidateFieldSet::toStoredDate($value);

        if ($stored === null) {
            $errors[$field] = 'Enter a valid end date (DD/MM/YYYY), or leave it empty.';
        }

        return $stored;
    }

    /**
     * @param array<string, string> $errors
     */
    private function time(string $value, array &$errors, string $field): ?string
    {
        if ($value === '') {
            return null;
        }

        if (! CandidateFieldSet::isValidTime($value)) {
            $errors[$field] = 'Enter a time as HH:MM, for example 09:00.';

            return null;
        }

        return $value;
    }

    /**
     * Reviewer-entered exception and additional dates. The publisher stores
     * full local date-times, so a date-only entry is anchored to the start of
     * the day and an all-day event is normalised to midnight.
     *
     * @param array<string, string> $errors
     * @return list<string>
     */
    private function dateList(
        string $value,
        int $limit,
        array &$errors,
        string $field,
        string $label
    ): array {
        $entries = CandidateFieldSet::stringList($value);

        if (count($entries) > $limit) {
            $errors[$field] = 'Enter no more than ' . $limit . ' ' . $label . ' dates.';

            return [];
        }

        $normalized = [];

        foreach ($entries as $entry) {
            $parsed = $this->toLocalDateTime($entry);

            if ($parsed === null) {
                $errors[$field] = 'Each ' . $label . ' date must be a date (DD/MM/YYYY), optionally with a time.';

                continue;
            }

            $normalized[] = $parsed;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * A reviewer types a date first, so `12/10/2026` and `12/10/2026 09:00` are
     * both accepted. The publisher stores full local date-times, so a date-only
     * entry is anchored to midnight and an existing ISO value is kept as it is.
     */
    private function toLocalDateTime(string $entry): ?string
    {
        $entry = trim($entry);

        if ($entry === '') {
            return null;
        }

        if (preg_match('/\A(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})\z/D', $entry, $matches) === 1) {
            return $matches[1] . 'T' . $matches[2];
        }

        [$date, $time] = array_pad(explode(' ', $entry, 2), 2, '00:00');
        $date = trim($date);
        $time = trim($time) === '' ? '00:00' : trim($time);

        if (! CandidateFieldSet::isValidTime($time)) {
            return null;
        }

        $stored = CandidateFieldSet::toStoredDate($date);

        return $stored === null ? null : $stored . 'T' . $time;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $errors
     * @return array{name: string, email: string, phone: string}
     */
    private function contact(array $form, array &$errors): array
    {
        $values = $this->contactFields($form);

        if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['contact_email'] = 'Enter a valid email address, or leave it empty.';
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $form
     * @return array{name: string, email: string, phone: string}
     */
    private function contactFields(array $form): array
    {
        $contact = $form['contact'] ?? null;

        if (is_array($contact)) {
            return [
                'name' => CandidateFieldSet::stringValue($contact['name'] ?? ''),
                'email' => CandidateFieldSet::stringValue($contact['email'] ?? ''),
                'phone' => CandidateFieldSet::stringValue($contact['phone'] ?? ''),
            ];
        }

        return [
            'name' => CandidateFieldSet::stringValue($form['contact_name'] ?? ''),
            'email' => CandidateFieldSet::stringValue($form['contact_email'] ?? ''),
            'phone' => CandidateFieldSet::stringValue($form['contact_phone'] ?? ''),
        ];
    }

    /**
     * @param array<string, string> $errors
     */
    private function positiveId(mixed $value, array &$errors, string $field): ?int
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';

        if ($raw === '') {
            return null;
        }

        if (preg_match('/\A[1-9]\d*\z/D', $raw) !== 1) {
            $errors[$field] = 'Choose a valid option.';

            return null;
        }

        $id = (int) $raw;

        if ($id < 1) {
            $errors[$field] = 'Choose a valid option.';

            return null;
        }

        return $id;
    }

    private function isChecked(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
        }

        return false;
    }

    /**
     * Maps a validator message onto the field that produced it, so the form can
     * show the error next to the input instead of in a single summary block.
     *
     * Every {@see EventValidator} message is matched exhaustively. An unmatched
     * message is a bug rather than a user error, so it is reported against the
     * whole form instead of being pinned to an arbitrary field.
     *
     * @param array<string, string> $errors
     */
    private function attachError(array &$errors, string $message): void
    {
        $field = match ($message) {
            'The event start must be a valid local date and time.' => 'event_date',
            'The event end must be a valid local date and time.',
            'The event end must be on or after its start.' => 'event_end_date',
            'The parish ID must be positive when set.',
            'Choose a parish before selecting a venue.' => 'parish_id',
            'The venue ID must be positive when set.' => 'venue_id',
            'The event status must be scheduled, cancelled or postponed.' => 'status_flag',
            'Event contact details contain an unsupported field.',
            'Event contact details must be text.',
            'Event contact details must be valid UTF-8 text.' => 'contact',
            'Event contact name is too long.' => 'contact_name',
            'Event contact email is too long.' => 'contact_email',
            'Event contact phone is too long.' => 'contact_phone',
            'RRULE must be a date for an all-day event.',
            'UNTIL must include a time for a timed event.' => 'recurrence_custom',
            'The additional dates must be a list of local date and times.',
            'Each additional date must be a valid local date and time.' => 'rdates',
            'The exception dates must be a list of local date and times.',
            'Each exception date must be a valid local date and time.' => 'exdates',
            // UNTIL messages come from a custom rule, so they belong in the box
            // the reviewer types the rule into, not on the preset drop-down.
            default => str_contains($message, 'UNTIL') || str_starts_with($message, 'RRULE')
                ? 'recurrence_custom'
                : '',
        };

        if ($field === '') {
            $errors[''] ??= $message;

            return;
        }

        $errors[$field] ??= $message;
    }
}
