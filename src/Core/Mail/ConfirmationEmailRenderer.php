<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use DateTimeImmutable;
use DateTimeZone;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use InvalidArgumentException;

final class ConfirmationEmailRenderer
{
    /**
     * A field scoring below this is highlighted for the submitter. Defaults to the pipeline's own
     * default so an unconfigured install highlights the same fields the pipeline flags.
     */
    public const DEFAULT_FIELD_THRESHOLD = 0.60;

    private DateTimeZone $timezone;
    private float $fieldThreshold;

    public function __construct(?DateTimeZone $timezone = null, ?float $fieldThreshold = null)
    {
        $this->timezone = $timezone ?? new DateTimeZone('Africa/Johannesburg');
        $this->fieldThreshold = $fieldThreshold !== null && is_finite($fieldThreshold)
            ? max(0.0, min(1.0, $fieldThreshold))
            : self::DEFAULT_FIELD_THRESHOLD;
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
                . 'Highlighted details may be uncertain or missing. Where a detail is marked &quot;not stated in the notice&quot;, the parser could not find it in your text and filled in a guess — please correct it. Confirm and Deny links now open a review page; the Edit link is not active yet. '
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
            'Highlighted details may be uncertain or missing. Where a detail is marked "not stated in the notice", the parser could not find it in your text and filled in a guess — please correct it. Confirm and Deny links now open a review page; the Edit link is not active yet.',
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
                . ($card['title_fabricated']
                    ? ' <span style="font-size:13px;font-weight:normal;color:#7a3030;">Not stated in the notice — please check</span>'
                    : ($card['title_uncertain']
                        ? ' <span style="font-size:13px;font-weight:normal;color:#704f00;">Please check</span>'
                        : ''))
                . '</h2></td></tr>';
            $html[] = '<tr><td style="padding:16px 18px 4px;">';
            if ($candidate->matchTitle !== null && in_array($candidate->matchKind, ['update', 'cancellation', 'postponement'], true)) {
                $label = $candidate->matchKind === 'cancellation' ? 'This will cancel: '
                    : ($candidate->matchKind === 'postponement' ? 'This will postpone: ' : 'This will update: ');
                $html[] = '<p style="padding:10px;background-color:#fff4d6;">'
                    . $this->escape($label . $candidate->matchTitle) . '</p>';
                $text[] = $label . $candidate->matchTitle;
            }
            $html[] = $this->renderFieldRow('Date', $card['date'], $card['date_uncertain'], $card['date_fabricated']);
            $html[] = $this->renderFieldRow('Time', $card['time'], $card['time_uncertain'], $card['time_fabricated']);
            $html[] = $this->renderFieldRow('Location', $card['location'], $card['location_uncertain'], $card['location_fabricated']);
            $html[] = $this->renderFieldRow('Parish', $card['parish'], $card['parish_uncertain'], $card['parish_fabricated']);
            $html[] = $this->renderFieldRow('Description', $card['description'], $card['description_uncertain'], $card['description_fabricated']);
            $html[] = $this->renderFieldRow('Event type', $card['event_type'], $card['event_type_uncertain'], $card['event_type_fabricated']);
            $html[] = $this->renderFieldRow('Contact', $card['contact'], $card['contact_uncertain'], $card['contact_fabricated']);
            $html[] = $this->renderFieldRow('Recurrence', $card['recurrence'], $card['recurrence_uncertain'], $card['recurrence_fabricated']);
            $html[] = $this->renderFieldRow('Review notes', $card['notes'], $card['notes_uncertain']);
            $html[] = '</td></tr>';
            $html[] = '<tr><td style="padding:8px 18px 16px;font-size:13px;line-height:1.5;color:#596773;">'
                . 'Parser confidence: ' . number_format($candidate->confidence * 100, 0) . '%'
                . ($candidate->confidence < $this->lowConfidenceThreshold()
                    ? ' — please review all details carefully.'
                    : '')
                . '</td></tr>';
            $html[] = '<tr><td style="padding:0 18px 18px;">';
            $html[] = $this->renderActionLink($candidateLinks['approve'], 'Confirm', '#285d3c');
            $html[] = $this->renderActionLink($candidateLinks['deny'], 'Deny', '#7a3030');
            $html[] = $this->renderActionLink($candidateLinks['edit'], 'Edit (not active)', '#385b78');
            $html[] = '</td></tr>';
            $html[] = '</table></td></tr>';

            $text[] = 'Event ' . $number . ': ' . $card['title']
                . ($card['title_uncertain'] ? ' (please check)' : '');
            $text[] = $this->textFieldRow('Date', $card['date'], $card['date_uncertain'], $card['date_fabricated']);
            $text[] = $this->textFieldRow('Time', $card['time'], $card['time_uncertain'], $card['time_fabricated']);
            $text[] = $this->textFieldRow('Location', $card['location'], $card['location_uncertain'], $card['location_fabricated']);
            $text[] = $this->textFieldRow('Parish', $card['parish'], $card['parish_uncertain'], $card['parish_fabricated']);
            $text[] = $this->textFieldRow('Description', $card['description'], $card['description_uncertain'], $card['description_fabricated']);
            $text[] = $this->textFieldRow('Event type', $card['event_type'], $card['event_type_uncertain'], $card['event_type_fabricated']);
            $text[] = $this->textFieldRow('Contact', $card['contact'], $card['contact_uncertain'], $card['contact_fabricated']);
            $text[] = $this->textFieldRow('Recurrence', $card['recurrence'], $card['recurrence_uncertain'], $card['recurrence_fabricated']);
            $text[] = 'Review notes: ' . $card['notes'];
            $text[] = 'Parser confidence: ' . number_format($candidate->confidence * 100, 0) . '%';
            $text[] = 'Confirm: ' . $candidateLinks['approve'];
            $text[] = 'Deny: ' . $candidateLinks['deny'];
            $text[] = 'Edit (not active): ' . $candidateLinks['edit'];
            $text[] = '';
        }

        $html[] = '<tr><td style="padding:0 24px 24px;">'
            . '<p style="margin:0 0 12px;font-size:14px;line-height:1.5;">'
            . '<a href="' . $this->escape($links->approveAll) . '" '
            . 'style="display:inline-block;padding:12px 18px;background-color:#285d3c;color:#ffffff;text-decoration:none;font-weight:bold;">'
            . 'Confirm all</a></p>'
            . '<p style="margin:0;font-size:14px;line-height:1.5;">If any detail is wrong, contact '
            . '<a href="mailto:events@adct.org.za" style="color:#17324d;">events@adct.org.za</a>. '
            . 'Replies to this message are not yet processed automatically.</p>'
            . '</td></tr>'
            . '<tr><td style="padding:18px 24px;background-color:#edf3f8;font-size:12px;line-height:1.5;color:#46525c;">'
            . '<p style="margin:0 0 8px;">You received this because an event notice was sent to events@adct.org.za with this address as the sender or a trusted Reply-To.</p>'
            . '<p style="margin:0;">Archdiocese of Cape Town · <a href="mailto:events@adct.org.za" style="color:#17324d;">events@adct.org.za</a></p>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        $text[] = 'Confirm all: ' . $links->approveAll;
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
     *     title_fabricated: bool,
     *     date: string,
     *     date_uncertain: bool,
     *     date_fabricated: bool,
     *     time: string,
     *     time_uncertain: bool,
     *     time_fabricated: bool,
     *     location: string,
     *     location_uncertain: bool,
     *     location_fabricated: bool,
     *     parish: string,
     *     parish_uncertain: bool,
     *     parish_fabricated: bool,
     *     description: string,
     *     description_uncertain: bool,
     *     description_fabricated: bool,
     *     event_type: string,
     *     event_type_uncertain: bool,
     *     event_type_fabricated: bool,
     *     contact: string,
     *     contact_uncertain: bool,
     *     contact_fabricated: bool,
     *     recurrence: string,
     *     recurrence_uncertain: bool,
     *     recurrence_fabricated: bool,
     *     notes: string,
     *     notes_uncertain: bool
     * }
     */
    private function candidateCard(ConfirmationEmailCandidate $candidate): array
    {
        $fields = $candidate->fields;
                $fieldScores = $candidate->fieldScores();
                $notes = implode(' ', $candidate->notes);
        $lowConfidence = $candidate->confidence < $this->lowConfidenceThreshold();
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
                    || $this->fieldWeak($fieldScores, 'event_date')
                    || $this->containsAny($notes, ['weekday does not match', 'ambiguous', 'verify the date'])
                    || (bool) ($candidate->recurrence['anchor_inferred'] ?? false);
                $timeUncertain = (! $allDay && $time === 'Not identified')
                    || $this->fieldWeak($fieldScores, 'event_time')
                    || $this->containsAny($notes, ['end time', 'verify the time']);
                $locationUncertain = $locationParts === [] || $this->fieldWeak($fieldScores, 'venue');
                $parishUncertain = $parish === 'Not identified' || $this->fieldWeak($fieldScores, 'parish_name');
                $descriptionUncertain = $description === 'Not identified'
                    || $this->fieldWeak($fieldScores, 'description');
                $eventTypeUncertain = $eventType === 'Not identified'
                    || $this->fieldWeak($fieldScores, 'event_type');
                $contactUncertain = $contact === 'Not identified' || $this->fieldWeak($fieldScores, 'contact');
                $recurrenceUncertain = $this->fieldWeak($fieldScores, 'recurrence')
                    || $this->containsAny($notes, ['recurrence', 'repeats', 'schedule'])
                    || (bool) ($candidate->recurrence['ambiguous'] ?? false);

                // A field with origin "unsupported" has no support anywhere in the source block, so its value
        // is an invention. That is a different claim from "please check", and it is the case #130
        // reported: a plausible fabricated value read as though the notice had stated it.
        $parishFabricated = $this->fieldFabricated($fieldScores, 'parish_name');
        $titleFabricated = $this->fieldFabricated($fieldScores, 'title');
        $dateFabricated = $this->fieldFabricated($fieldScores, 'event_date');
        $timeFabricated = $this->fieldFabricated($fieldScores, 'event_time');
        $locationFabricated = $this->fieldFabricated($fieldScores, 'venue');
        $descriptionFabricated = $this->fieldFabricated($fieldScores, 'description');
        $eventTypeFabricated = $this->fieldFabricated($fieldScores, 'event_type');
        $contactFabricated = $this->fieldFabricated($fieldScores, 'contact');
        $recurrenceFabricated = $this->fieldFabricated($fieldScores, 'recurrence');

        if ($lowConfidence && $fieldScores === []) {
                    // Only when no per-field evidence exists. Once the parser reports a score per field,
                    // a weak overall score should not blank out fields the parser did support, or the
                    // submitter is asked to re-check detail that is in fact the best-evidenced part.
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
            'title_uncertain' => $title === 'Not identified'
                || $this->fieldWeak($fieldScores, 'title')
                || ($lowConfidence && $fieldScores === []),
            'title_fabricated' => $titleFabricated,
            'date' => $date,
            'date_uncertain' => $dateUncertain,
            'date_fabricated' => $dateFabricated,
            'time' => $time,
            'time_uncertain' => $timeUncertain,
            'time_fabricated' => $timeFabricated,
            'location' => $locationParts === [] ? 'Not identified' : implode(', ', $locationParts),
            'location_uncertain' => $locationUncertain,
            'location_fabricated' => $locationFabricated,
            'parish' => $parish,
            'parish_uncertain' => $parishUncertain,
            'parish_fabricated' => $parishFabricated,
            'description' => $description,
            'description_uncertain' => $descriptionUncertain,
            'description_fabricated' => $descriptionFabricated,
            'event_type' => $eventType,
            'event_type_uncertain' => $eventTypeUncertain,
            'event_type_fabricated' => $eventTypeFabricated,
            'contact' => $contact,
            'contact_uncertain' => $contactUncertain,
            'contact_fabricated' => $contactFabricated,
            'recurrence' => $recurrence,
            'recurrence_uncertain' => $recurrenceUncertain,
            'recurrence_fabricated' => $recurrenceFabricated,
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

    /**
         * Whether the named field scored below the field threshold.
         *
         * A field with no recorded score is *not* treated as weak: absence of evidence about a field
         * is not evidence that the field is doubtful, and the "not identified" checks already cover
         * the case where the parser produced nothing at all.
         *
         * @param array<string, array{score: float, origin: string, flags: list<string>}> $fieldScores
         */
        private function fieldWeak(array $fieldScores, string $field): bool
        {
            $entry = $fieldScores[$field] ?? null;

            return $entry !== null && $entry['score'] < $this->fieldThreshold;
        }

        /**
         * Whether the parser invented the named field outright.
         *
         * Distinct from a merely weak field: "unsupported" means the scorer found no support for the
         * value in the source block at all, so whatever is shown came from a fallback, not the notice.
         *
         * @param array<string, array{score: float, origin: string, flags: list<string>}> $fieldScores
         */
        private function fieldFabricated(array $fieldScores, string $field): bool
        {
            $entry = $fieldScores[$field] ?? null;

            return $entry !== null && $entry['origin'] === 'unsupported';
        }

        private function lowConfidenceThreshold(): float
        {
            return ReviewQueuePolicy::DEFAULT_CONFIDENCE_THRESHOLD;
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

    /**
     * Plain-text field row.
     *
     * A fabricated value gets its own wording, not the generic "please check". "Please check" only
     * says the value may be wrong; the submitter cannot tell that the notice never contained it and
     * will leave a plausible invented parish name in place.
     */
    private function textFieldRow(string $label, string $value, bool $uncertain, bool $fabricated = false): string
    {
        if ($fabricated) {
            return $label . ': ' . $value
                . ' (not stated in the notice — the parser guessed this, please correct it)';
        }

        return $label . ': ' . $value . ($uncertain ? ' (please check)' : '');
    }

    private function renderFieldRow(string $label, string $value, bool $uncertain, bool $fabricated = false): string
    {
        $background = $fabricated ? '#fdecec' : ($uncertain ? '#fff4d6' : '#ffffff');
        $labelColor = $fabricated ? '#7a3030' : ($uncertain ? '#704f00' : '#46525c');

        $marker = $fabricated
            ? ' <span aria-label="not stated in the notice — please check">*</span>'
            : ($uncertain ? ' <span aria-label="please check">*</span>' : '');
        $note = $fabricated
            ? '<br><span style="font-size:13px;line-height:1.45;color:#7a3030;">'
                . 'Not stated in the notice — the parser guessed this, please correct it.</span>'
            : '';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="border-collapse:collapse;background-color:' . $background . ';">'
            . '<tr><td width="115" valign="top" style="width:115px;padding:8px 8px 8px 0;font-size:14px;line-height:1.45;font-weight:bold;color:'
            . $labelColor . ';">' . $this->escape($label)
            . $marker
            . '</td><td valign="top" style="padding:8px 0;font-size:14px;line-height:1.45;color:#263238;">'
            . nl2br($this->escape($value), false)
            . $note
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
