<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use DateTimeImmutable;
use DateTimeZone;

final class EventValidator
{
    private const STATUSES = [
        'scheduled',
        'cancelled',
        'postponed',
    ];

    private const CONTACT_LIMITS = [
        'name' => 191,
        'email' => 254,
        'phone' => 64,
    ];

    private RRuleValidator $rruleValidator;

    public function __construct(
        private DateTimeZone $timezone,
        ?RRuleValidator $rruleValidator = null
    ) {
        $this->rruleValidator = $rruleValidator ?? new RRuleValidator();
    }

    public function validate(EventDetails $details): EventValidationResult
    {
        $errors = [];
        $start = $this->parseLocalDateTime(trim($details->startLocal));
        $startLocal = trim($details->startLocal);

        if ($start === null) {
            $errors[] = 'The event start must be a valid local date and time.';
        } else {
            $startLocal = $this->normalizedLocalValue($start, $details->allDay);
        }

        $end = null;
        $endLocal = $details->endLocal === null ? null : trim($details->endLocal);

        if ($endLocal !== null && $endLocal !== '') {
            $end = $this->parseLocalDateTime($endLocal);

            if ($end === null) {
                $errors[] = 'The event end must be a valid local date and time.';
            } else {
                $endLocal = $this->normalizedLocalValue($end, $details->allDay);
            }
        } else {
            $endLocal = null;
        }

        if ($start !== null && $end !== null) {
            $normalizedStart = $details->allDay ? $start->setTime(0, 0) : $start;
            $normalizedEnd = $details->allDay ? $end->setTime(0, 0) : $end;

            if ($normalizedEnd < $normalizedStart) {
                $errors[] = 'The event end must be on or after its start.';
            }
        }

        if ($details->parishId !== null && $details->parishId < 1) {
            $errors[] = 'The parish ID must be positive when set.';
        }

        if ($details->venueId !== null && $details->venueId < 1) {
            $errors[] = 'The venue ID must be positive when set.';
        }

        if ($details->venueId !== null && $details->parishId === null) {
            $errors[] = 'Choose a parish before selecting a venue.';
        }

        if ($details->sourceCandidateId !== null && $details->sourceCandidateId < 1) {
            $errors[] = 'The source candidate ID must be positive when set.';
        }

        if (! in_array($details->statusFlag, self::STATUSES, true)) {
            $errors[] = 'The event status must be scheduled, cancelled or postponed.';
        }

        $rruleValidation = $this->rruleValidator->validate($details->rrule, $details->allDay);
        array_push($errors, ...$rruleValidation->errors);

        $exdates = $this->normalizeDateList($details->exdates, $details->allDay, 'exception', $errors);
        $rdates = $this->normalizeDateList($details->rdates, $details->allDay, 'additional', $errors);
        $contact = $this->validateContact($details->contact, $errors);

        return new EventValidationResult([
            'parish_id' => $details->parishId,
            'venue_id' => $details->venueId,
            'start_local' => $startLocal,
            'end_local' => $endLocal,
            'all_day' => $details->allDay,
            'rrule' => $rruleValidation->isValid() ? $rruleValidation->normalizedRule : trim((string) $details->rrule),
            'exdates' => $exdates,
            'rdates' => $rdates,
            'featured' => $details->featured,
            'status_flag' => $details->statusFlag,
            'source_candidate_id' => $details->sourceCandidateId,
            'contact' => $contact,
        ], array_values(array_unique($errors)));
    }

    private function parseLocalDateTime(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i') !== $value
        ) {
            return null;
        }

        return $date;
    }

    private function normalizedLocalValue(DateTimeImmutable $date, bool $allDay): string
    {
        return ($allDay ? $date->setTime(0, 0) : $date)->format('Y-m-d\TH:i');
    }

    /**
     * @param array<mixed> $dates
     * @param list<string> $errors
     * @return list<string>
     */
    private function normalizeDateList(array $dates, bool $allDay, string $kind, array &$errors): array
    {
        if (! array_is_list($dates)) {
            $errors[] = $this->dateListLabel($kind) . ' must be a list of local date and times.';

            return [];
        }

        $normalized = [];

        foreach ($dates as $dateValue) {
            if (! is_string($dateValue)) {
                $errors[] = 'Each ' . $kind . ' date must be a valid local date and time.';
                continue;
            }

            $date = $this->parseLocalDateTime(trim($dateValue));

            if ($date === null) {
                $errors[] = 'Each ' . $kind . ' date must be a valid local date and time.';
                continue;
            }

            $normalized[] = $this->normalizedLocalValue($date, $allDay);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<mixed> $contact
     * @param list<string> $errors
     * @return array{name: string, email: string, phone: string}
     */
    private function validateContact(array $contact, array &$errors): array
    {
        $values = [
            'name' => '',
            'email' => '',
            'phone' => '',
        ];

        foreach ($contact as $field => $value) {
            if (! isset(self::CONTACT_LIMITS[$field])) {
                $errors[] = 'Event contact details contain an unsupported field.';
                continue;
            }

            if (! is_string($value)) {
                $errors[] = 'Event contact details must be text.';
                continue;
            }

            $value = trim($value);
            $characterCount = preg_match_all('/./us', $value);

            if (preg_match('//u', $value) !== 1) {
                $errors[] = 'Event contact details must be valid UTF-8 text.';
                continue;
            }

            if ($characterCount === false || $characterCount > self::CONTACT_LIMITS[$field]) {
                $errors[] = 'Event contact ' . $field . ' is too long.';
                continue;
            }

            $values[$field] = $value;
        }

        if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'The event contact email address is invalid.';
        }

        return $values;
    }

    private function dateListLabel(string $kind): string
    {
        return $kind === 'exception' ? 'Exception dates' : 'Additional dates';
    }
}
