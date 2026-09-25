<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ConfirmationEmailRenderer
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.55;

    private DateTimeZone $timezone;

    public function __construct(?DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?? new DateTimeZone('Africa/Johannesburg');
    }

    public function render(
        ConfirmationEmailBatch $batch,
        ConfirmationEmailActionLinks $links
    ): ConfirmationEmailContent {
        if ($batch->candidates === []) {
            throw new InvalidArgumentException('A confirmation preview email needs at least one candidate.');
        }

        $html = [
            '<!doctype html>',
            '<html lang="en">',
            '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>',
            '<body style="margin:0;padding:0;background-color:#f4f5f7;color:#263238;font-family:Arial,Helvetica,sans-serif;">',
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;background-color:#f4f5f7;">',
            '<tr><td align="center" style="padding:24px 12px;">',
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;border-collapse:collapse;background-color:#ffffff;">',
            '<tr><td style="padding:28px 24px 12px;">',
            '<h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;color:#17324d;">Please review your event preview</h1>',
            '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;">We found '
                . count($batch->candidates)
                . (count($batch->candidates) === 1 ? ' event' : ' events')
                . ' in your notice. Please check each preview below.</p>',
            '<p style="margin:0 0 20px;padding:12px 14px;border-left:4px solid #d99a00;background-color:#fff4d6;font-size:14px;line-height:1.5;">'
                . 'Highlighted details may be uncertain or missing. Online action links are not active yet; clicking one will not approve, deny, edit, or publish an event. '
                . 'Every new event still needs approval by a dean or an Archdiocese reviewer.</p>',
            '</td></tr>',
        ];
        $text = [
            'Please review your event preview',
            '',
            sprintf(
                'We found %d %s in your notice. Please check each preview below.',
                count($batch->candidates),
                count($batch->candidates) === 1 ? 'event' : 'events'
            ),
            'Highlighted details may be uncertain or missing. Online action links are not active yet; clicking one will not approve, deny, edit, or publish an event.',
            'Every new event still needs approval by a dean or an Archdiocese reviewer.',
            '',
        ];

        foreach ($batch->candidates as $index => $candidate) {
            $candidateLinks = $links->forCandidate($candidate->id);
            $card = $this->candidateCard($candidate);
            $number = $index + 1;
            $html[] = '<tr><td style="padding:0 24px 20px;">';
            $html[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #d7dee5;border-collapse:collapse;">';
            $titleBackground = $card['title_uncertain'] ? '#fff4d6' : '#edf3f8';
            $html[] = '<tr><td style="padding:16px 18px;background-color:' . $titleBackground . ';">';
            $html[] = '<h2 style="margin:0;font-size:19px;line-height:1.35;color:#17324d;">Event '
                . $number . ': ' . $this->escape($card['title'])
                . ($card['title_uncertain']
                    ? ' <span style="font-size:13px;font-weight:normal;color:#704f00;">Please check</span>'
                    : '')
                . '</h2></td></tr>';
            $html[] = '<tr><td style="padding:16px 18px 4px;">';
            $html[] = $this->renderFieldRow('Date', $card['date'], $card['date_uncertain']);
            $html[] = $this->renderFieldRow('Time', $card['time'], $card['time_uncertain']);
            $html[] = $this->renderFieldRow('Location', $card['location'], $card['location_uncertain']);
            $html[] = $this->renderFieldRow('Parish', $card['parish'], $card['parish_uncertain']);
            $html[] = $this->renderFieldRow('Description', $card['description'], $card['description_uncertain']);
            $html[] = $this->renderFieldRow('Event type', $card['event_type'], $card['event_type_uncertain']);
            $html[] = $this->renderFieldRow('Contact', $card['contact'], $card['contact_uncertain']);
            $html[] = $this->renderFieldRow('Recurrence', $card['recurrence'], $card['recurrence_uncertain']);
            $html[] = $this->renderFieldRow('Review notes', $card['notes'], $card['notes_uncertain']);
            $html[] = '</td></tr>';
            $html[] = '<tr><td style="padding:8px 18px 16px;font-size:13px;line-height:1.5;color:#596773;">'
                . 'Parser confidence: ' . number_format($candidate->confidence * 100, 0) . '%'
                . ($candidate->confidence < self::LOW_CONFIDENCE_THRESHOLD
                    ? ' — please review all details carefully.'
                    : '')
                . '</td></tr>';
            $html[] = '<tr><td style="padding:0 18px 18px;">';
            $html[] = $this->renderActionLink($candidateLinks['approve'], 'Approve (not active)', '#285d3c');
            $html[] = $this->renderActionLink($candidateLinks['deny'], 'Deny (not active)', '#7a3030');
            $html[] = $this->renderActionLink($candidateLinks['edit'], 'Edit (not active)', '#385b78');
            $html[] = '</td></tr>';
            $html[] = '</table></td></tr>';

            $text[] = 'Event ' . $number . ': ' . $card['title']
                . ($card['title_uncertain'] ? ' (please check)' : '');
            $text[] = 'Date: ' . $card['date'];
            $text[] = 'Time: ' . $card['time'];
            $text[] = 'Location: ' . $card['location'];
            $text[] = 'Parish: ' . $card['parish'];
            $text[] = 'Description: ' . $card['description'];
            $text[] = 'Event type: ' . $card['event_type'];
            $text[] = 'Contact: ' . $card['contact'];
            $text[] = 'Recurrence: ' . $card['recurrence'];
            $text[] = 'Review notes: ' . $card['notes'];
            $text[] = 'Parser confidence: ' . number_format($candidate->confidence * 100, 0) . '%';
            $text[] = 'Approve (not active): ' . $candidateLinks['approve'];
            $text[] = 'Deny (not active): ' . $candidateLinks['deny'];
            $text[] = 'Edit (not active): ' . $candidateLinks['edit'];
            $text[] = '';
        }

        $html[] = '<tr><td style="padding:0 24px 24px;">'
            . '<p style="margin:0 0 12px;font-size:14px;line-height:1.5;">'
            . '<a href="' . $this->escape($links->approveAll) . '" '
            . 'style="display:inline-block;padding:12px 18px;background-color:#285d3c;color:#ffffff;text-decoration:none;font-weight:bold;">'
            . 'Approve all (not active)</a></p>'
            . '<p style="margin:0;font-size:14px;line-height:1.5;">If any detail is wrong, contact '
            . '<a href="mailto:events@adct.org.za" style="color:#17324d;">events@adct.org.za</a>. '
            . 'Replies to this message are not yet processed automatically.</p>'
            . '</td></tr>'
            . '<tr><td style="padding:18px 24px;background-color:#edf3f8;font-size:12px;line-height:1.5;color:#46525c;">'
            . '<p style="margin:0 0 8px;">You received this because an event notice was sent to events@adct.org.za with this address as the sender or a trusted Reply-To.</p>'
            . '<p style="margin:0;">Archdiocese of Cape Town · <a href="mailto:events@adct.org.za" style="color:#17324d;">events@adct.org.za</a></p>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        $text[] = 'Approve all (not active): ' . $links->approveAll;
        $text[] = '';
        $text[] = 'If any detail is wrong, contact events@adct.org.za. Replies to this message are not yet processed automatically.';
        $text[] = '';
        $text[] = 'You received this because an event notice was sent to events@adct.org.za with this address as the sender or a trusted Reply-To.';
        $text[] = 'Archdiocese of Cape Town · events@adct.org.za';

        return new ConfirmationEmailContent(
            'Please review your event preview — Archdiocese of Cape Town',
            implode("\n", $html),
            implode("\n", $text)
        );
    }

    /**
     * @return array{
     *     title: string,
     *     title_uncertain: bool,
     *     date: string,
     *     date_uncertain: bool,
     *     time: string,
     *     time_uncertain: bool,
     *     location: string,
     *     location_uncertain: bool,
     *     parish: string,
     *     parish_uncertain: bool,
     *     description: string,
     *     description_uncertain: bool,
     *     event_type: string,
     *     event_type_uncertain: bool,
     *     contact: string,
     *     contact_uncertain: bool,
     *     recurrence: string,
     *     recurrence_uncertain: bool,
     *     notes: string,
     *     notes_uncertain: bool
     * }
     */
    private function candidateCard(ConfirmationEmailCandidate $candidate): array
    {
        $fields = $candidate->fields;
        $notes = implode(' ', $candidate->notes);
        $lowConfidence = $candidate->confidence < self::LOW_CONFIDENCE_THRESHOLD;
        $title = $this->displayValue($fields['title'] ?? null, 512);
        $allDay = ($fields['all_day'] ?? false) === true
            || in_array(strtolower((string) ($fields['all_day'] ?? '')), ['1', 'yes', 'true'], true);
        $date = $this->formatDateRange($fields['event_date'] ?? null, $fields['event_end_date'] ?? null);
        $time = $this->formatTimeRange(
            $fields['event_time'] ?? null,
            $fields['event_end_time'] ?? null,
            $allDay
        );
        $locationParts = [];

        foreach (['venue', 'venue_text', 'venue_address', 'venue_suburb'] as $field) {
            $value = $this->displayValue($fields[$field] ?? null, 512);

            if ($value !== 'Not identified' && ! in_array($value, $locationParts, true)) {
                $locationParts[] = $value;
            }
        }

        $parish = $this->displayValue($fields['parish_name'] ?? null, 512);
        $description = $this->displayValue($fields['description'] ?? null, 4000);
        $eventType = $this->displayValue($fields['event_type'] ?? null, 512);
        $contact = $this->displayValue($fields['contact'] ?? null, 1000);
        $recurrence = $this->recurrenceLabel($candidate->recurrence);
        $reviewNotes = $candidate->notes === []
            ? 'No extraction notes.'
            : $this->displayValue(implode(' ', $candidate->notes), 2000);
        $dateUncertain = $date === 'Not identified'
            || $this->containsAny($notes, ['weekday does not match', 'ambiguous', 'verify the date'])
            || (bool) ($candidate->recurrence['anchor_inferred'] ?? false);
        $timeUncertain = ! $allDay && $time === 'Not identified'
            || $this->containsAny($notes, ['end time', 'verify the time']);
        $locationUncertain = $locationParts === [];
        $parishUncertain = $parish === 'Not identified';
        $descriptionUncertain = $description === 'Not identified';
        $eventTypeUncertain = $eventType === 'Not identified';
        $contactUncertain = $contact === 'Not identified';
        $recurrenceUncertain = $this->containsAny($notes, ['recurrence', 'repeats', 'schedule'])
            || (bool) ($candidate->recurrence['ambiguous'] ?? false);

        if ($lowConfidence) {
            $dateUncertain = true;
            $timeUncertain = true;
            $locationUncertain = true;
            $parishUncertain = true;
            $descriptionUncertain = true;
            $eventTypeUncertain = true;
            $contactUncertain = true;
            $recurrenceUncertain = $recurrenceUncertain || $candidate->recurrence !== [];
        }

        return [
            'title' => $title === 'Not identified' ? 'Title not identified — please check' : $title,
            'title_uncertain' => $title === 'Not identified' || $lowConfidence,
            'date' => $date,
            'date_uncertain' => $dateUncertain,
            'time' => $time,
            'time_uncertain' => $timeUncertain,
            'location' => $locationParts === [] ? 'Not identified' : implode(', ', $locationParts),
            'location_uncertain' => $locationUncertain,
            'parish' => $parish,
            'parish_uncertain' => $parishUncertain,
            'description' => $description,
            'description_uncertain' => $descriptionUncertain,
            'event_type' => $eventType,
            'event_type_uncertain' => $eventTypeUncertain,
            'contact' => $contact,
            'contact_uncertain' => $contactUncertain,
            'recurrence' => $recurrence,
            'recurrence_uncertain' => $recurrenceUncertain,
            'notes' => $reviewNotes,
            'notes_uncertain' => $candidate->notes !== [],
        ];
    }

    private function formatDateRange(mixed $start, mixed $end): string
    {
        $startDate = $this->formatDate($start);

        if ($startDate === null) {
            return 'Not identified';
        }

        $endDate = $this->formatDate($end);

        return $endDate === null || $endDate === $startDate
            ? $startDate
            : $startDate . ' to ' . $endDate;
    }

    private function formatDate(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 64 || trim($value) === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== trim($value)
        ) {
            return null;
        }

        return $date->format('j F Y');
    }

    private function formatTimeRange(mixed $start, mixed $end, bool $allDay): string
    {
        if ($allDay) {
            return 'All day';
        }

        $startTime = $this->formatTime($start);

        if ($startTime === null) {
            return 'Not identified';
        }

        $endTime = $this->formatTime($end);

        return $endTime === null ? $startTime : $startTime . ' to ' . $endTime;
    }

    private function formatTime(mixed $value): ?string
    {
        if (
            ! is_string($value)
            || strlen($value) > 32
            || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', trim($value)) !== 1
        ) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!H:i', trim($value), $this->timezone);

        return $date === false ? null : $date->format('g:i a');
    }

    /**
     * @param array<string, mixed> $recurrence
     */
    private function recurrenceLabel(array $recurrence): string
    {
        $sourcePhrase = $this->displayValue($recurrence['text'] ?? null, 512);

        if ($sourcePhrase !== 'Not identified') {
            return 'Repeats: ' . $sourcePhrase;
        }

        $frequency = $recurrence['frequency'] ?? $recurrence['freq'] ?? $recurrence['type'] ?? null;

        if (is_string($frequency) && trim($frequency) !== '' && strtolower($frequency) !== 'none') {
            $label = 'Repeats ' . strtolower($frequency);
            $interval = $recurrence['interval'] ?? null;

            if (is_numeric($interval) && (int) $interval > 1) {
                $label .= ' every ' . (int) $interval . ' intervals';
            }

            return $label;
        }

        return ($recurrence['is_recurring'] ?? false) === true || $recurrence !== []
            ? 'Recurring schedule — please check'
            : 'One-time event';
    }

    private function displayValue(mixed $value, int $maximumBytes = 2000): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return 'Not identified';
        }

        $value = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            ' ',
            (string) $value
        ) ?? (string) $value;
        $value = trim($value);

        if ($value === '') {
            return 'Not identified';
        }

        if (strlen($value) > $maximumBytes) {
            $value = substr($value, 0, $maximumBytes);

            while ($value !== '' && preg_match('//u', $value) !== 1) {
                $value = substr($value, 0, -1);
            }

            return rtrim($value) . '…';
        }

        return $value;
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function renderFieldRow(string $label, string $value, bool $uncertain): string
    {
        $background = $uncertain ? '#fff4d6' : '#ffffff';
        $labelColor = $uncertain ? '#704f00' : '#46525c';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="border-collapse:collapse;background-color:' . $background . ';">'
            . '<tr><td width="115" valign="top" style="width:115px;padding:8px 8px 8px 0;font-size:14px;line-height:1.45;font-weight:bold;color:'
            . $labelColor . ';">' . $this->escape($label)
            . ($uncertain ? ' <span aria-label="please check">*</span>' : '')
            . '</td><td valign="top" style="padding:8px 0;font-size:14px;line-height:1.45;color:#263238;">'
            . nl2br($this->escape($value), false)
            . '</td></tr></table>';
    }

    private function renderActionLink(string $url, string $label, string $color): string
    {
        return '<a href="' . $this->escape($url) . '" style="display:inline-block;margin:0 8px 8px 0;'
            . 'padding:10px 14px;background-color:' . $color
            . ';color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;">'
            . $this->escape($label) . '</a>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
