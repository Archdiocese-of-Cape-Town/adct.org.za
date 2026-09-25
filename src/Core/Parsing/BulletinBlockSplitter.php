<?php

namespace ADCT\ParishIntake\Core\Parsing;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Support\EmailTextCleaner;

final class BulletinBlockSplitter
{
    private const WEEKDAY_PATTERN = '(?:Saturday|Sat|Sunday|Sun|Monday|Mon|Tuesday|Tues|Tue|Wednesday|Wed|Thursday|Thurs|Thur|Thu|Friday|Fri)';
    private const MONTH_PATTERN = '(?:January|Jan|February|Feb|March|Mar|April|Apr|May|June|Jun|July|Jul|August|Aug|September|Sept|Sep|October|Oct|November|Nov|December|Dec)';

    private EmailTextCleaner $textCleaner;

    public function __construct(?EmailTextCleaner $textCleaner = null)
    {
        $this->textCleaner = $textCleaner ?? new EmailTextCleaner();
    }

    public function split(Message $message): BlockSplitResult
    {
        $cleaned = $this->textCleaner->clean(
            $message->getBody(),
            $message->getSubject(),
            $message->isForwarded()
        );
        $body = $cleaned->getBody();
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = preg_split('/\n/u', $body) ?: [];
        $underlinedHeadings = $this->markUnderlinedHeadings($lines);
        $context = [
            'shared_signature_text' => $cleaned->getSignatureText(),
            'shared_quoted_text' => $cleaned->getQuotedText(),
        ];
        $subjectContext = $this->contextUpdate($message->getSubject());

        if ($subjectContext !== null) {
            $context = array_merge($context, $subjectContext);
        }
        $tableHeader = null;
        $sectionSkipReason = null;
        $pendingTitle = null;
        $pendingSkippedHeading = null;
        $current = null;
        $rawBlocks = [];
        $nextIndex = 0;

        foreach ($lines as $lineIndex => $line) {
            $line = trim($line);

            if ($line === '') {
                $this->flushCurrent($rawBlocks, $current, $nextIndex);
                continue;
            }

            if (preg_match('/^\s*[-_=*~]{3,}\s*$/u', $line)) {
                continue;
            }

            $contextUpdate = $this->contextUpdate($line);

            if ($contextUpdate !== null) {
                if ($current === null) {
                    $context = array_merge($context, $contextUpdate);
                } else {
                    foreach ($contextUpdate as $key => $value) {
                        $context[$key] = $value;
                        $current['context'][$key] = $value;
                    }

                    $current['lines'][] = $line;
                    $current['parse_text'] .= "\n" . $line;
                }
                continue;
            }

            if ($this->isVenueHeading($line)) {
                $context['venue'] = $line;

                if ($current !== null) {
                    $current['context']['venue'] = $line;
                    $current['lines'][] = $line;
                    $current['parse_text'] .= "\n" . $line;
                }
                continue;
            }

            if ($this->isTableRow($line)) {
                $cells = $this->tableCells($line);
                $header = $this->tableHeader($cells);

                if ($header !== null) {
                    $this->flushCurrent($rawBlocks, $current, $nextIndex);
                    $tableHeader = $header;
                    $pendingTitle = null;
                    continue;
                }
            }

            if ($this->isDateLedLine($line, $context)
                || $this->isListItem($line)
                || $this->isTableRow($line)
            ) {
                $this->flushCurrent($rawBlocks, $current, $nextIndex);
                $tableRow = $this->isTableRow($line)
                    ? $this->tableRow($line, $tableHeader)
                    : null;
                $sourceLines = [];

                if ($pendingSkippedHeading !== null) {
                    $sourceLines[] = $pendingSkippedHeading;
                    $pendingSkippedHeading = null;
                }

                if ($pendingTitle !== null) {
                    $sourceLines[] = $pendingTitle;
                }

                $sourceLines[] = $line;
                $title = $tableRow['title'] ?? $pendingTitle;
                $parseText = $tableRow['parse_text'] ?? implode("\n", $sourceLines);
                $current = [
                    'lines' => $sourceLines,
                    'parse_text' => $parseText,
                    'context' => $context,
                    'title' => $title,
                    'skip_reason' => $sectionSkipReason,
                    'table_row' => $tableRow !== null,
                    'table_has_date' => $tableRow['has_date'] ?? false,
                ];
                $pendingTitle = null;
                continue;
            }

            $sectionReason = $this->nonEventSectionReason($line);

            if ($sectionReason !== null) {
                $this->flushCurrent($rawBlocks, $current, $nextIndex);
                $sectionSkipReason = $sectionReason;
                $pendingTitle = null;
                $pendingSkippedHeading = $line;
                continue;
            }

            if ($this->isEventSectionHeading($line)) {
                $this->flushCurrent($rawBlocks, $current, $nextIndex);
                $sectionSkipReason = null;
                $pendingTitle = null;
                $pendingSkippedHeading = null;
                $tableHeader = null;
                continue;
            }

            $heading = $this->headingText($line, isset($underlinedHeadings[$lineIndex]));

            if ($heading !== null) {
                $this->flushCurrent($rawBlocks, $current, $nextIndex);
                $sectionReason = $this->nonEventSectionReason($heading);

                if ($sectionReason !== null) {
                    $sectionSkipReason = $sectionReason;
                    $pendingTitle = null;
                    $pendingSkippedHeading = $heading;
                } elseif ($this->isEventSectionHeading($heading)) {
                    $sectionSkipReason = null;
                    $pendingTitle = null;
                    $pendingSkippedHeading = null;
                } else {
                    $pendingTitle = $heading;
                    $pendingSkippedHeading = null;
                }
                continue;
            }

            if ($current === null) {
                $sourceLines = [];

                if ($pendingSkippedHeading !== null) {
                    $sourceLines[] = $pendingSkippedHeading;
                    $pendingSkippedHeading = null;
                }

                if ($pendingTitle !== null) {
                    $sourceLines[] = $pendingTitle;
                }

                $sourceLines[] = $line;
                $current = [
                    'lines' => $sourceLines,
                    'parse_text' => implode("\n", $sourceLines),
                    'context' => $context,
                    'title' => $pendingTitle,
                    'skip_reason' => $sectionSkipReason,
                    'table_row' => false,
                    'table_has_date' => false,
                ];
                $pendingTitle = null;
            } else {
                $current['lines'][] = $line;
                $current['parse_text'] .= "\n" . $line;
            }
        }

        $this->flushCurrent($rawBlocks, $current, $nextIndex);

        return $this->classifyBlocks($rawBlocks);
    }

    private function flushCurrent(array &$rawBlocks, ?array &$current, int &$nextIndex): void
    {
        if ($current === null) {
            return;
        }

        $sourceText = trim(implode("\n", $current['lines']));
        $parseText = trim($current['parse_text']);

        if ($sourceText !== '') {
            $rawBlocks[] = [
                'block_index' => $nextIndex++,
                'source_text' => $sourceText,
                'parse_text' => $parseText !== '' ? $parseText : $sourceText,
                'context' => $current['context'],
                'title' => $current['title'],
                'skip_reason' => $current['skip_reason'],
                'table_row' => $current['table_row'] ?? false,
                'table_has_date' => $current['table_has_date'] ?? false,
                'classification' => null,
                'candidate' => false,
                'reason' => null,
            ];
        }

        $current = null;
    }

    private function classifyBlocks(array $rawBlocks): BlockSplitResult
    {
        $eventIndexes = [];

        foreach ($rawBlocks as $index => $block) {
            if (
                $block['skip_reason'] === null
                && ! $this->isObviousNonEvent($block['source_text'])
                && (! $block['table_row'] || $block['table_has_date'])
                && $this->hasEventSignal($block['parse_text'], $block['context'])
            ) {
                $eventIndexes[] = $index;
            }
        }

        if ($eventIndexes === []) {
            $noticeIndexes = [];

            foreach ($rawBlocks as $index => $block) {
                if ($block['skip_reason'] === null && ! $this->isObviousNonEvent($block['source_text'])) {
                    $noticeIndexes[] = $index;
                    $rawBlocks[$index]['classification'] = 'notice';
                    $rawBlocks[$index]['reason'] = 'no_event_signal';
                } else {
                    $rawBlocks[$index]['classification'] = 'skipped';
                    $rawBlocks[$index]['reason'] = $block['skip_reason'] ?? 'obvious_non_event';
                }
            }

            if ($noticeIndexes !== []) {
                $first = $noticeIndexes[0];
                $sourceText = [];
                $parseText = [];

                foreach ($noticeIndexes as $index) {
                    $sourceText[] = $rawBlocks[$index]['source_text'];
                    $parseText[] = $rawBlocks[$index]['parse_text'];
                    $rawBlocks[$index]['candidate'] = $index === $first;
                    $rawBlocks[$index]['reason'] = $index === $first
                        ? 'standalone_notice'
                        : 'merged_into_notice';
                }

                $rawBlocks[$first]['source_text'] = implode("\n\n", $sourceText);
                $rawBlocks[$first]['parse_text'] = implode("\n\n", $parseText);
                $rawBlocks[$first]['classification'] = 'notice';
                $blocks = $this->toEventBlocks($rawBlocks);
                $notes = count($noticeIndexes) > 1
                    ? ['No event blocks were found; the remaining content was kept as a notice.']
                    : [];

                return new BlockSplitResult($blocks, $notes);
            }

            foreach ($rawBlocks as $index => $block) {
                if ($block['skip_reason'] === null) {
                    $rawBlocks[$index]['classification'] = 'skipped';
                    $rawBlocks[$index]['reason'] = 'obvious_non_event';
                }
            }

            $skippedCount = count($rawBlocks);
            $notes = $skippedCount > 0
                ? [sprintf('Skipped %d obvious non-event block(s).', $skippedCount)]
                : [];

            return new BlockSplitResult($this->toEventBlocks($rawBlocks), $notes);
        }

        if (count($eventIndexes) === 1) {
            $eventIndex = $eventIndexes[0];

            foreach ($rawBlocks as $index => $block) {
                if (
                    $index === $eventIndex
                    || $block['skip_reason'] !== null
                    || $this->isObviousNonEvent($block['source_text'])
                ) {
                    continue;
                }

                $title = $this->titleFragment($block['source_text']);
                $prefix = $index < $eventIndex;

                if ($title !== null && $prefix) {
                    $rawBlocks[$eventIndex]['source_text'] = trim(
                        $block['source_text'] . "\n" . $rawBlocks[$eventIndex]['source_text']
                    );
                    $rawBlocks[$eventIndex]['parse_text'] = trim(
                        $block['parse_text'] . "\n" . $rawBlocks[$eventIndex]['parse_text']
                    );

                    if (empty($rawBlocks[$eventIndex]['title'])) {
                        $rawBlocks[$eventIndex]['title'] = $title;
                    }

                    $rawBlocks[$index]['classification'] = 'context';
                    $rawBlocks[$index]['reason'] = 'merged_into_event';
                } else {
                    $rawBlocks[$index]['classification'] = 'notice';
                    $rawBlocks[$index]['reason'] = 'no_event_signal';
                }
            }
        } else {
            foreach ($rawBlocks as $index => $block) {
                if (
                    $block['skip_reason'] !== null
                    || in_array($index, $eventIndexes, true)
                    || $this->isObviousNonEvent($block['source_text'])
                ) {
                    continue;
                }

                $title = $this->titleFragment($block['source_text']);

                if ($title !== null) {
                    $target = $this->nextEventIndex($index, $eventIndexes);

                    if ($target === null) {
                        $target = $eventIndexes[count($eventIndexes) - 1];
                    }

                    if ($target === $eventIndexes[0] || $target > $index) {
                        $rawBlocks[$target]['source_text'] = trim(
                            $block['source_text'] . "\n" . $rawBlocks[$target]['source_text']
                        );
                        $rawBlocks[$target]['parse_text'] = trim(
                            $block['parse_text'] . "\n" . $rawBlocks[$target]['parse_text']
                        );
                        if (empty($rawBlocks[$target]['title'])) {
                            $rawBlocks[$target]['title'] = $title;
                        }
                        $rawBlocks[$index]['classification'] = 'context';
                        $rawBlocks[$index]['reason'] = 'merged_into_event';
                    } else {
                        $rawBlocks[$index]['classification'] = 'notice';
                        $rawBlocks[$index]['reason'] = 'no_event_signal';
                    }
                } else {
                    $rawBlocks[$index]['classification'] = 'notice';
                    $rawBlocks[$index]['reason'] = 'no_event_signal';
                }
            }
        }

        foreach ($rawBlocks as $index => $block) {
            if (in_array($index, $eventIndexes, true)) {
                $rawBlocks[$index]['classification'] = 'event';
                $rawBlocks[$index]['candidate'] = true;
                $rawBlocks[$index]['reason'] = null;
            } elseif ($block['classification'] === null) {
                $rawBlocks[$index]['classification'] = 'skipped';
                $rawBlocks[$index]['reason'] = $block['skip_reason'] ?? 'obvious_non_event';
            }
        }

        $skippedCount = count(array_filter(
            $rawBlocks,
            static fn (array $block): bool => ! $block['candidate']
        ));
        $notes = [];

        if (count($eventIndexes) > 1) {
            $notes[] = sprintf('Split message into %d event blocks.', count($eventIndexes));
        }

        if ($skippedCount > 0) {
            $notes[] = sprintf('Classified or skipped %d non-event block(s).', $skippedCount);
        }

        return new BlockSplitResult($this->toEventBlocks($rawBlocks), $notes);
    }

    private function toEventBlocks(array $rawBlocks): array
    {
        $blocks = [];

        foreach ($rawBlocks as $block) {
            $isEvent = $block['classification'] === 'event';
            $isNoticeCandidate = $block['classification'] === 'notice' && $block['candidate'];
            $candidate = $isEvent || $isNoticeCandidate;
            $title = $block['title'];

            if ($candidate && $isEvent && (! is_string($title) || trim($title) === '')) {
                $title = $this->inferTitle($block['parse_text']);
            }

            $blocks[] = new EventBlock(
                $block['block_index'],
                $block['source_text'],
                $block['parse_text'],
                $block['context'],
                is_string($title) && trim($title) !== '' ? trim($title) : null,
                $block['classification'] ?? 'skipped',
                $block['reason'],
                $candidate
            );
        }

        return $blocks;
    }

    private function contextUpdate(string $line): ?array
    {
        if (preg_match('/^\s*parish\s*[:\-]\s*(?<name>.+?)\s*$/iu', $line, $matches)) {
            return ['parish_name' => trim($matches['name'])];
        }

        if (preg_match(
            '/^\s*(?<month>' . self::MONTH_PATTERN . ')\s+(?<year>20\d{2})\s*$/iu',
            $line,
            $matches
        )) {
            return [
                'month' => $matches['month'],
                'year' => (int) $matches['year'],
            ];
        }

        if (
            preg_match('/\b(?:bulletin|newsletter)\b/i', $line)
            && preg_match(
                '/\b\d{1,2}(?:st|nd|rd|th)?\s+' . self::MONTH_PATTERN . '\.?\s+(?:to|[-–])\s*\d{1,2}(?:st|nd|rd|th)?\s+' . self::MONTH_PATTERN . '\.?,?\s+\d{4}\b/iu',
                $line
            )
        ) {
            return ['bulletin_date_range' => $line];
        }

        if (preg_match('/^\s*(?:venue|where|location)\s*[:\-]\s*(?<venue>.+?)\s*$/iu', $line, $matches)) {
            return ['venue' => trim($matches['venue'], " \t\n\r\0\x0B.,;")];
        }

        return null;
    }

    private function isVenueHeading(string $line): bool
    {
        if (preg_match('/\b(?:is|are|was|were|at|for|join|gathering|meeting|event|mass|picnic|retreat)\b/i', $line)) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:(?:St\.?|Saint)\s+)?[\p{L}][\p{L}\p{N}\'’.,& -]{1,70}\s+(?:church|hall|chapel|community\s+centre|community\s+center|centre|center|gardens?)$/iu',
            $line
        );
    }

    private function markUnderlinedHeadings(array &$lines): array
    {
        $headings = [];

        foreach ($lines as $index => $line) {
            if (! preg_match('/^\s*[-_=*~]{3,}\s*$/u', trim($line))) {
                continue;
            }

            for ($previous = $index - 1; $previous >= 0; --$previous) {
                if (trim($lines[$previous]) !== '') {
                    $headings[$previous] = true;
                    break;
                }
            }

            $lines[$index] = '';
        }

        return $headings;
    }

    private function headingText(string $line, bool $underlined): ?string
    {
        if (preg_match('/^\s*#{1,6}\s+(.+?)\s*#*\s*$/u', $line, $matches)) {
            return trim($matches[1]);
        }

        if ($underlined) {
            return trim($line);
        }

        if (preg_match('/:\s*$/u', $line)) {
            return trim(rtrim($line, ':'));
        }

        $letters = preg_replace('/[^A-Za-z]/', '', $line) ?? '';

        if (strlen($letters) >= 3 && strtoupper($letters) === $letters) {
            return trim($line);
        }

        return null;
    }

    private function isEventSectionHeading(string $line): bool
    {
        $heading = strtolower(trim($line, " \t:#"));

        return (bool) preg_match(
            '/^(?:upcoming\s+events?|forthcoming\s+events?|events?|community\s+calendar|parish\s+calendar|calendar|this\s+week|what\'?s\s+on)$/i',
            $heading
        );
    }

    private function nonEventSectionReason(string $line): ?string
    {
        return preg_match(
            '/\b(?:mass\s+times?|mass\s+timetable|mass\s+schedule)\b/i',
            $line
        ) ? 'non_event_section' : null;
    }

    private function isListItem(string $line): bool
    {
        return (bool) preg_match('/^\s*(?:[-*•]\s+|\d+[.)]\s+)\S/u', $line);
    }

    private function isTableRow(string $line): bool
    {
        return (bool) preg_match('/\s+\|\s+|^\s*\|/u', $line);
    }

    private function tableCells(string $line): array
    {
        $line = trim(trim($line), '|');

        return array_map('trim', explode('|', $line));
    }

    private function tableHeader(array $cells): ?array
    {
        $header = [];

        foreach ($cells as $index => $cell) {
            $cell = strtolower(trim($cell));

            if (preg_match('/^(?:date|day|when)$/', $cell)) {
                $header['date'] = $index;
            } elseif (preg_match('/^(?:event|title|activity|occasion|what)$/', $cell)) {
                $header['title'] = $index;
            } elseif (preg_match('/^time$/', $cell)) {
                $header['time'] = $index;
            } elseif (preg_match('/^(?:venue|location|where|place)$/', $cell)) {
                $header['venue'] = $index;
            }
        }

        return isset($header['title'])
            && (isset($header['date']) || isset($header['time']) || isset($header['venue']))
            ? $header
            : null;
    }

    private function tableRow(string $line, ?array $header): array
    {
        $cells = $this->tableCells($line);
        $dateIndex = $header['date'] ?? ($header === null ? 0 : null);
        $titleIndex = $header['title'] ?? 1;
        $timeIndex = $header['time'] ?? ($header === null ? 2 : null);
        $venueIndex = $header['venue'] ?? ($header === null ? 3 : null);
        $date = $dateIndex === null ? '' : trim($cells[$dateIndex] ?? '');
        $title = trim($cells[$titleIndex] ?? '');
        $time = $timeIndex === null ? '' : trim($cells[$timeIndex] ?? '');
        $venue = $venueIndex === null ? '' : trim($cells[$venueIndex] ?? '');
        $parts = array_filter(
            [
                $date,
                $title,
                $time !== '' ? 'at ' . $time : '',
                $venue !== '' ? 'at ' . $venue : '',
            ],
            static fn (string $part): bool => $part !== ''
        );

        return [
            'title' => $title !== '' ? $title : null,
            'has_date' => $date !== '' && $this->isDateLedLine($date, []),
            'parse_text' => implode(' - ', $parts),
        ];
    }

    private function isDateLedLine(string $line, array $context): bool
    {
        $line = preg_replace('/^\s*(?:date|when)\s*:\s*/iu', '', $line) ?? $line;
        $weekday = self::WEEKDAY_PATTERN;
        $patterns = [
            '/^(?:' . $weekday . '\.?\s+)?\d{4}-\d{1,2}-\d{1,2}\b/iu',
            '/^(?:' . $weekday . '\.?\s+)?\d{1,2}[.\/-]\d{1,2}(?:[.\/-]\d{2,4})?\b/iu',
            '/^(?:' . $weekday . '\.?\s+)?\d{1,2}(?:st|nd|rd|th)?\s+' . self::MONTH_PATTERN . '\b/iu',
            '/^(?:' . $weekday . '\.?\s+)?' . self::MONTH_PATTERN . '\.?\s+\d{1,2}(?:st|nd|rd|th)?\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        if (! isset($context['month'])) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:' . $weekday . '\.?\s+)?\d{1,2}(?:st|nd|rd|th)?\s*(?:[-–—|:]|$)/iu',
            $line
        );
    }

    private function hasEventSignal(string $text, array $context): bool
    {
        if (
            preg_match(
                '/\b(?:' . self::WEEKDAY_PATTERN . '\s+)?\d{1,2}(?:st|nd|rd|th)?\s+' . self::MONTH_PATTERN . '\b|\b' . self::MONTH_PATTERN . '\.?\s+\d{1,2}(?:st|nd|rd|th)?\b|\b\d{4}-\d{1,2}-\d{1,2}\b|\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}\b|\b(?:today|tomorrow|tonight|this\s+(?:Saturday|Sunday|Monday|Tuesday|Wednesday|Thursday|Friday)|next\s+(?:Saturday|Sunday|Monday|Tuesday|Wednesday|Thursday|Friday))\b/iu',
                $text
            )
            || preg_match('/\b\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)\b|\b\d{1,2}:\d{2}\b/iu', $text)
            || preg_match('/\b(?:every|weekly|monthly|fortnightly)\b/iu', $text)
            || preg_match(
                '/\b(?:event|gathering|meeting|picnic|fundraiser|festival|retreat|novena|pilgrimage|celebration|concert|workshop|market|prayer\s+service|healing\s+mass|youth\s+(?:group|meeting|gathering))\b/iu',
                $text
            )
        ) {
            return true;
        }

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if ($this->isDateLedLine(trim($line), $context)) {
                return true;
            }
        }

        return false;
    }

    private function isObviousNonEvent(string $text): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return (bool) preg_match(
            '/^(?:dear\b[^.!?]{0,100}[,!]?|hello\b[^.!?]{0,100}[,!]?|hi\b[^.!?]{0,100}[,!]?|(?:kind|best|warm|many|with|yours|sincerely|faithfully)?\s*(?:regards|blessings|thanks|thank you|cheers|peace)[,!]?)$/iu',
            $normalized
        );
    }

    private function titleFragment(string $text): ?string
    {
        if (strpos($text, "\n") !== false) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (
            $text === ''
            || strlen($text) > 100
            || preg_match('/[.!?]$/u', $text)
            || preg_match('/[@\d]/u', $text)
            || $this->isEventSectionHeading($text)
            || $this->nonEventSectionReason($text) !== null
        ) {
            return null;
        }

        return $this->cleanTitle($text);
    }

    private function inferTitle(string $text): ?string
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '') {
                return $this->cleanTitle($line);
            }
        }

        return null;
    }

    private function cleanTitle(string $text): ?string
    {
        $text = trim($text);
        $text = preg_replace('/^\s*(?:[-*•]\s+|\d+[.)]\s+|#{1,6}\s*)/u', '', $text) ?? $text;
        $text = preg_replace(
            '/^\s*(?:please\s+)?(?:join\s+us\s+for|come\s+and\s+join\s+us\s+for)\s+/iu',
            '',
            $text
        ) ?? $text;
        $weekday = self::WEEKDAY_PATTERN;
        $month = self::MONTH_PATTERN;
        $datePrefix = '/^(?:(?:' . $weekday . ')\.?\s+)?(?:\d{1,2}(?:st|nd|rd|th)?\s+' . $month . '(?:\.?,?\s+\d{2,4})?|'
            . $month . '\.?\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{2,4})?|\d{4}-\d{1,2}-\d{1,2}|'
            . '\d{1,2}[.\/-]\d{1,2}(?:[.\/-]\d{2,4})?|(?:' . $weekday . ')\.?\s+\d{1,2}(?:st|nd|rd|th)?)'
            . '\s*(?:[-–—|:]\s*|,\s*|\s+)(.*)$/iu';

        if (preg_match($datePrefix, $text, $matches)) {
            $text = trim($matches[1]);
            $text = preg_replace(
                '/^(?:at\s+)?\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)?\s*[-–—|:]?\s*/iu',
                '',
                $text
            ) ?? $text;
        } else {
            $date = '(?:(?:' . $weekday . ')\.?\s+)?(?:\d{1,2}(?:st|nd|rd|th)?\s+' . $month . '(?:\.?,?\s+\d{2,4})?|'
                . $month . '\.?\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{2,4})?|\d{4}-\d{1,2}-\d{1,2}|'
                . '\d{1,2}[.\/-]\d{1,2}(?:[.\/-]\d{2,4})?)';
            $text = preg_replace('/\s+(?:is\s+)?(?:on\s+)?' . $date . '\b.*$/iu', '', $text) ?? $text;
        }

        $text = preg_replace(
            '/\s+(?:at|from)\s+\d{1,2}(?:[:.]\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)?(?:\s+(?:at|in)\s+.+)?[.,;]?\s*$/iu',
            '',
            $text
        ) ?? $text;
        $text = preg_replace('/^(?:a|an|the)\s+/iu', '', trim($text)) ?? trim($text);
        $text = trim($text, " \t\n\r\0\x0B-–—:|,.;");

        if ($text === '') {
            return null;
        }

        if (function_exists('mb_strtolower') && $text === mb_strtoupper($text, 'UTF-8')) {
            $text = mb_strtolower($text, 'UTF-8');
        } elseif ($text === strtoupper($text)) {
            $text = strtolower($text);
        }

        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8')
                . mb_substr($text, 1, null, 'UTF-8');
        }

        return ucfirst($text);
    }

    private function nextEventIndex(int $index, array $eventIndexes): ?int
    {
        foreach ($eventIndexes as $eventIndex) {
            if ($eventIndex > $index) {
                return $eventIndex;
            }
        }

        return null;
    }
}
