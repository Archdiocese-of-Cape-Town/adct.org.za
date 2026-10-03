<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class IcsCalendar
{
    /**
     * Caps on the character count of untrusted free text, applied before escaping and folding.
     *
     * A feed reader cannot render an unbounded SUMMARY or DESCRIPTION usefully, and the rolling-year
     * feed aggregates every event, so a single oversized bulletin would bloat the whole response.
     * The values are generous next to real bulletin text.
     */
    public const SUMMARY_MAX_CHARS = 1000;
    public const DESCRIPTION_MAX_CHARS = 8000;

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
            $lines[] = 'SUMMARY:' . $this->text($event['title'], self::SUMMARY_MAX_CHARS);
            if ($event['description'] !== '') {
                            $lines[] = 'DESCRIPTION:' . $this->text($event['description'], self::DESCRIPTION_MAX_CHARS);
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

    /**
         * Reduce untrusted free text to what RFC 5545 allows in a TEXT value, then escape it.
         *
         * Titles and excerpts reach the feed straight from an emailed bulletin, so they may contain
         * control characters, broken encodings and megabyte-long paragraphs. RFC 5545 §3.3.11 permits
         * only printable US-ASCII, HTAB, escaped characters and multi-byte UTF-8; anything else makes a
         * strict reader reject the whole VEVENT.
         */
        private function text(string $value, int $maximumChars): string
        {
            return str_replace(
                ["\\", ';', ',', "\r\n", "\r", "\n"],
                ['\\\\', '\;', '\,', '\n', '\n', '\n'],
                $this->limit($this->scrub($value), $maximumChars)
            );
        }

        /**
         * Drop every control character except TAB, then repair or replace invalid UTF-8.
         *
         * A bulletin pasted from a word processor routinely carries NUL, BEL, VT and FF, which no
         * calendar client can render. Invalid UTF-8 is replaced per character rather than discarded:
         * losing the whole property would leave a VEVENT without the SUMMARY that §3.6.1 requires.
         */
        private function scrub(string $value): string
        {
            // Line breaks are handled by text() as escaped \n, so CR and LF are kept until then.
                        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
                        if ($stripped === null) {
                // The /u modifier failed, so the bytes are not valid UTF-8. Replace the bad bytes.
                            $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
                            $stripped = $stripped === null ? '' : $this->repairUtf8($stripped);
            }

                        return $stripped;
        }

        private function repairUtf8(string $value): string
        {
            if (preg_match('//u', $value) === 1) {
                return $value;
            }
            // mb_convert_encoding substitutes the invalid sequences instead of dropping the value.
            if (function_exists('mb_convert_encoding')) {
                $repaired = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                if (is_string($repaired) && preg_match('//u', $repaired) === 1) {
                    return $repaired;
                }
            }

            $repaired = preg_replace('/[\x80-\xFF]/', '?', $value) ?? '';

            return preg_match('//u', $repaired) === 1 ? $repaired : '';
        }

        /** Truncate on a character boundary, marking the cut so readers do not show a silent cut. */
        private function limit(string $value, int $maximumChars): string
        {
            if ($maximumChars < 1 || mb_strlen($value, 'UTF-8') <= $maximumChars) {
                return $value;
            }
            $truncated = mb_substr($value, 0, $maximumChars - 1, 'UTF-8');

            return rtrim($truncated) . '…';
        }

        /**
         * Fold one content line to at most 75 octets, continuing with a single leading space.
         *
         * Folding counts octets, not characters, and only ever breaks between characters.
         */
        private function fold(string $line): string
        {
            $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
            if ($characters === false) {
                // Invalid UTF-8 must never reach here, but a dropped line would corrupt the calendar.
                $characters = str_split($line);
            }
            $result = '';
            $length = 0;
            foreach ($characters as $character) {
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
