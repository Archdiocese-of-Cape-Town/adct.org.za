<?php

namespace ADCT\ParishIntake\Parsing\Stages;

use ADCT\ParishIntake\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\ParseContext;
use ADCT\ParishIntake\Parsing\ParseResult;
use ADCT\ParishIntake\Support\Text;
use DateTimeImmutable;
use Throwable;

final class RuleBasedExtractionStage implements StageInterface
{
    private const EVENT_KEYWORDS = ['mass', 'healing', 'retreat', 'novena', 'pilgrimage', 'fundraiser', 'conference', 'celebration', 'vigil', 'feast'];
    private const NOTICE_KEYWORDS = ['notice', 'announcement', 'newsletter', 'update', 'bulletin'];

    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $text = $result->getNormalizedText();
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);

        $classification = $this->classify($lower);
        $result->setClassification($classification);
        $result->setField('title', $this->extractTitle($message, $text));
        $result->setField('parish_name', $this->extractParishName($text, $message->getSenderName()));
        $result->setField('event_date', $this->extractDate($text));
        $result->setField('event_time', $this->extractTime($text));
        $result->setField('venue', $this->extractVenue($text));
        $result->setField('contact', $this->extractContact($text, $message->getSenderEmail()));
        $result->setField('description', $message->getBody());
        $result->setField('attachment_names', array_map(static fn ($attachment) => $attachment->getName(), $message->getAttachments()));
        $result->addStrategy('rule_based_extraction');

        return $result;
    }

    private function classify(string $lower): string
    {
        if ($this->containsRecurringHint($lower)) {
            return 'recurring_event';
        }

        foreach (self::EVENT_KEYWORDS as $keyword) {
            if (strpos($lower, $keyword) !== false) {
                return 'event';
            }
        }

        if ($this->extractDate($lower) || $this->extractTime($lower)) {
            return 'event';
        }

        foreach (self::NOTICE_KEYWORDS as $keyword) {
            if (strpos($lower, $keyword) !== false) {
                return 'general_notice';
            }
        }

        return 'low_confidence';
    }

    private function containsRecurringHint(string $lower): bool
    {
        return (bool) preg_match('/\b(?:every|weekly|monthly|fortnightly|first\s+friday|last\s+sunday)\b/i', $lower);
    }

    private function extractTitle(Message $message, string $text): ?string
    {
        $subject = trim(preg_replace('/^(?:re|fwd):\s*/i', '', $message->getSubject()) ?? $message->getSubject());

        if ($subject !== '') {
            return $subject;
        }

        return Text::firstMeaningfulLine($text);
    }

    private function extractParishName(string $text, string $senderName): ?string
    {
        $patterns = [
            '/parish\s*[:\-]\s*([^\n]+)/i',
            '/\b((?:St\.?|Saint|Our Lady of|Church of) [A-Z][A-Za-z\'\- ]+(?:Parish|Church)?)\b/u',
            '/\b([A-Z][A-Za-z\'\- ]+ Parish)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[1]);
            }
        }

        if (stripos($senderName, 'parish') !== false || stripos($senderName, 'church') !== false) {
            return trim($senderName);
        }

        return null;
    }

    private function extractDate(string $text): ?string
    {
        $patterns = [
            '/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/',
            '/\b(\d{1,2}\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+\d{2,4})\b/i',
            '/\b(?:on\s+)?((?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+\d{1,2},?\s+\d{2,4})\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            try {
                $date = new DateTimeImmutable($matches[1]);

                return $date->format('Y-m-d');
            } catch (Throwable $exception) {
                continue;
            }
        }

        return null;
    }

    private function extractTime(string $text): ?string
    {
        if (preg_match('/\b(\d{1,2}(?::\d{2})?\s?(?:am|pm))\b/i', $text, $matches)) {
            return strtoupper(str_replace(' ', '', $matches[1]));
        }

        if (preg_match('/\b(\d{1,2}:\d{2})\b/', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractVenue(string $text): ?string
    {
        $patterns = [
            '/(?:venue|where|location)\s*[:\-]\s*([^\n]+)/i',
            '/\bat\s+([A-Z][A-Za-z0-9\'\- &,]{4,80})/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim(rtrim($matches[1], '.'));
            }
        }

        return null;
    }

    private function extractContact(string $text, string $fallbackEmail): ?string
    {
        $emails = Text::extractEmails($text);
        $phones = Text::extractPhones($text);
        $parts = [];

        if (! empty($emails)) {
            $parts[] = $emails[0];
        }

        if (! empty($phones)) {
            $parts[] = $phones[0];
        }

        if (empty($parts) && $fallbackEmail !== '') {
            $parts[] = $fallbackEmail;
        }

        return empty($parts) ? null : implode(' | ', $parts);
    }
}
