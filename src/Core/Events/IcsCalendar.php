<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class IcsCalendar
{
    private DateTimeZone $timezone;
    private DateTimeZone $utc;

    public function __construct()
    {
        $this->timezone = new DateTimeZone('Africa/Johannesburg');
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @param list<array{id: int, uid_domain: string, title: string, description: string, url: string, modified: string, start: string, end: string|null, all_day: bool, rrule: string, exdates: list<string>, rdates: list<string>, cancelled: bool}> $events
     */
    public function render(array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//ADCT//Parish Intake Events//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-TIMEZONE:Africa/Johannesburg',
            'BEGIN:VTIMEZONE',
            'TZID:Africa/Johannesburg',
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0200',
            'TZNAME:SAST',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];

        foreach ($events as $event) {
            if ($event['id'] < 1 || preg_match('/\A[a-z0-9.-]+\z/D', $event['uid_domain']) !== 1) {
                throw new InvalidArgumentException('Invalid event identity for calendar output.');
            }
            $start = $this->local($event['start']);
            $end = $event['end'] === null ? null : $this->local($event['end']);
            $modified = new DateTimeImmutable($event['modified'], $this->utc);
            $allDay = $event['all_day'];
            $rule = (new RRuleValidator())->validate($event['rrule'], $allDay);
            if ($rule->errors !== []) {
                throw new InvalidArgumentException('Invalid event recurrence for calendar output.');
            }
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:adct-event-' . $event['id'] . '@' . $event['uid_domain'];
            $lines[] = 'DTSTAMP:' . $modified->setTimezone($this->utc)->format('Ymd\THis\Z');
            $lines[] = 'LAST-MODIFIED:' . $modified->setTimezone($this->utc)->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:' . $this->text($event['title']);
            if ($event['description'] !== '') {
                $lines[] = 'DESCRIPTION:' . $this->text($event['description']);
            }
            $lines[] = 'URL:' . $event['url'];
            $lines[] = $allDay
                ? 'DTSTART;VALUE=DATE:' . $start->format('Ymd')
                : 'DTSTART;TZID=Africa/Johannesburg:' . $start->format('Ymd\THis');
            if ($end !== null) {
                $lines[] = $allDay
                    ? 'DTEND;VALUE=DATE:' . $end->modify('+1 day')->format('Ymd')
                    : 'DTEND;TZID=Africa/Johannesburg:' . $end->format('Ymd\THis');
            } elseif ($allDay) {
                $lines[] = 'DTEND;VALUE=DATE:' . $start->modify('+1 day')->format('Ymd');
            }
            if ($rule->normalizedRule !== null) {
                $parts = $rule->parts;
                if (isset($parts['UNTIL']) && ! $allDay && ! str_ends_with($parts['UNTIL'], 'Z')) {
                    $parts['UNTIL'] = (new DateTimeImmutable($parts['UNTIL'], $this->timezone))
                        ->setTimezone($this->utc)->format('Ymd\THis\Z');
                }
                $lines[] = 'RRULE:' . implode(';', array_map(
                    static fn (string $name, string $value): string => $name . '=' . $value,
                    array_keys($parts),
                    array_values($parts)
                ));
            }
            foreach (['exdates' => 'EXDATE', 'rdates' => 'RDATE'] as $key => $property) {
                foreach ($event[$key] as $date) {
                    $local = $this->local($date);
                    $lines[] = $allDay
                        ? $property . ';VALUE=DATE:' . $local->format('Ymd')
                        : $property . ';TZID=Africa/Johannesburg:' . $local->format('Ymd\THis');
                }
            }
            if ($event['cancelled']) {
                $lines[] = 'STATUS:CANCELLED';
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        $output = '';
        foreach ($lines as $line) {
            $output .= $this->fold($line) . "\r\n";
        }
        return $output;
    }

    private function local(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Invalid local event date for calendar output.');
        }
        return $date;
    }

    private function text(string $value): string
    {
        return str_replace(
            ["\\", ';', ',', "\r\n", "\r", "\n"],
            ['\\\\', '\;', '\,', '\n', '\n', '\n'],
            $value
        );
    }

    private function fold(string $line): string
    {
        $result = '';
        $length = 0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $bytes = strlen($character);
            if ($length + $bytes > 75) {
                $result .= "\r\n ";
                $length = 1;
            }
            $result .= $character;
            $length += $bytes;
        }
        return $result;
    }
}
