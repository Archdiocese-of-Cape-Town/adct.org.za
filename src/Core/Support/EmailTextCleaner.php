<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Support;

final class EmailTextCleaner
{
    public function clean(string $text, string $subject = '', bool $alreadyForwarded = false): CleanedEmailText
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $text = $this->removeNewsletterFooter($text);
        $lines = preg_split('/\n/', $text) ?: [];
        $forwarded = $alreadyForwarded;
        $originalHeaders = [];
        $forwardMarker = $this->findForwardMarker($lines, $subject);

        if ($forwardMarker !== null) {
            $forwarded = true;
            $markerLine = $lines[$forwardMarker] ?? '';
            $headerStart = $this->isForwardMarker($markerLine)
                || preg_match('/^\s*-{2,}\s*original\s+message\s*-{2,}\s*$/i', $markerLine)
                ? $forwardMarker + 1
                : $forwardMarker;
            $headerBlock = $this->readHeaderBlock($lines, $headerStart, false, false);

            if ($headerBlock !== null) {
                $originalHeaders = $headerBlock['headers'];
                $bodyStart = $headerBlock['end'];
            } else {
                $bodyStart = $forwardMarker + 1;
            }

            while (isset($lines[$bodyStart]) && trim($lines[$bodyStart]) === '') {
                ++$bodyStart;
            }

            $lines = array_merge(
                array_slice($lines, 0, $forwardMarker),
                array_slice($lines, $bodyStart)
            );
        }

        $quoteStart = $this->findQuoteStart($lines, $subject, $forwarded);
        $quotedLines = [];

        if ($quoteStart !== null) {
            $quotedLines = array_slice($lines, $quoteStart);
            $lines = array_slice($lines, 0, $quoteStart);
        }

        $signatureStart = $this->findSignatureStart($lines);
        $signatureLines = [];

        if ($signatureStart !== null) {
            $signatureLines = array_slice($lines, $signatureStart);
            $lines = array_slice($lines, 0, $signatureStart);
        }

        [$originalSenderName, $originalSenderEmail] = $this->parseAddress(
            $originalHeaders['from'] ?? ''
        );

        return new CleanedEmailText(
            $this->normalizeLines($lines),
            $this->normalizeLines($quotedLines),
            $this->normalizeLines($signatureLines),
            $forwarded,
            $originalSenderEmail,
            $originalSenderName,
            $originalHeaders['date'] ?? $originalHeaders['sent'] ?? null,
            $originalHeaders['subject'] ?? null
        );
    }

    private function removeNewsletterFooter(string $text): string
    {
        $patterns = [
            '/^\s*(?:view\s+(?:this\s+)?email\s+in\s+(?:your\s+)?(?:web\s+)?browser\b|open\s+(?:this\s+)?email\s+in\s+(?:your\s+)?browser\b)/i',
            '/^\s*(?:to\s+)?unsubscribe\b/i',
            '/^\s*(?:update|manage|change)\s+(?:your\s+)?(?:email\s+)?preferences\b/i',
            '/^\s*(?:email\s+)?marketing\s+by\s+mailchimp\b/i',
            '/^\s*this\s+email\s+was\s+sent\s+to\b/i',
            '/^\s*(?:follow|find|connect)\s+(?:us|with\s+us|.+?)\s+on\s+(?:facebook|instagram|twitter|x\b|youtube|linkedin|the\s+.+\s+website)\b/i',
            '/^\s*(?:facebook|instagram|twitter|youtube|linkedin)\s*(?:[|•,]\s*(?:facebook|instagram|twitter|youtube|linkedin)\s*)+$/i',
            '/^\s*\*\|(?:UNSUB|UPDATE_PROFILE|LIST:)/i',
        ];

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        foreach ($lines as $index => $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    return implode("\n", array_slice($lines, 0, $index));
                }
            }
        }

        return $text;
    }

    private function findForwardMarker(array $lines, string $subject): ?int
    {
        $forwardSubject = (bool) preg_match('/^\s*(?:fwd?|fw):\s*/i', $subject);

        foreach ($lines as $index => $line) {
            if ($this->isForwardMarker($line)) {
                return $index;
            }

            if ($forwardSubject && preg_match('/^\s*-{2,}\s*original\s+message\s*-{2,}\s*$/i', $line)) {
                return $index;
            }
        }

        if ($forwardSubject) {
            $headerBlock = $this->findOutlookHeaderBlock($lines);

            if ($headerBlock !== null) {
                return $headerBlock['start'];
            }
        }

        return null;
    }

    private function isForwardMarker(string $line): bool
    {
        return (bool) preg_match(
            '/^\s*(?:-{2,}\s*)?forwarded\s+message(?:\s*-{2,})?\s*$|^\s*begin\s+forwarded\s+message\s*:?\s*$/i',
            $line
        );
    }

    private function findQuoteStart(array $lines, string $subject, bool $forwarded): ?int
    {
        $replySubject = (bool) preg_match('/^\s*re:\s*/i', $subject);

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*on\s+.+\s+wrote:\s*$/i', $line)
                || preg_match('/^\s*-{2,}\s*original\s+message\s*-{2,}\s*$/i', $line)
                || preg_match('/^\s*>{1,}\s?.*$/', $line)
            ) {
                return $index;
            }

            if ($replySubject && ! $forwarded) {
                $headerBlock = $this->readHeaderBlock($lines, $index, true, false);

                if ($headerBlock !== null) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function findSignatureStart(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*--\s*$/', $line)
                || preg_match('/^\s*sent\s+from\s+(?:my\s+)?(?:iphone|ipad|android|mobile|samsung|pixel)\b.*$/i', $line)
            ) {
                return $index;
            }

            if (preg_match(
                '/^\s*(?:(?:kind|best|warm|many|with|yours|sincerely|faithfully)\s+)?(?:regards|blessings|thanks|thank\s+you|cheers|peace)[,!]?\s*$/i',
                $line
            ) && $this->hasSignatureContent(array_slice($lines, $index + 1))
            ) {
                return $index;
            }
        }

        return null;
    }

    private function hasSignatureContent(array $lines): bool
    {
        $examined = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (++$examined > 5) {
                return false;
            }

            if (Text::extractEmails($line) !== [] || Text::extractPhones($line) !== []) {
                return true;
            }

            if (preg_match(
                '/^(?:(?:Fr|Father|Rev|Reverend|Deacon|Sr|Sister|Dr)\.?\s+)?\p{Lu}[\p{L}\'’.-]*(?:\s+\p{Lu}[\p{L}\'’.-]*){0,3}$/u',
                $line
            )) {
                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * @return array{start: int, end: int, headers: array<string, string>}|null
     */
    private function findOutlookHeaderBlock(array $lines): ?array
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*from\s*:/i', $line)) {
                $headerBlock = $this->readHeaderBlock($lines, $index, true, false);

                if ($headerBlock !== null) {
                    return $headerBlock;
                }
            }
        }

        return null;
    }

    /**
     * @return array{start: int, end: int, headers: array<string, string>}|null
     */
    private function readHeaderBlock(
        array $lines,
        int $start,
        bool $requireDate = true,
        bool $requireSubject = true
    ): ?array
    {
        if (! isset($lines[$start]) || ! preg_match('/^\s*from\s*:\s*(.*)$/i', $lines[$start], $from)) {
            return null;
        }

        $headers = ['from' => trim($from[1])];
        $end = $start + 1;

        for ($index = $start + 1, $count = count($lines); $index < $count && $index - $start <= 10; ++$index) {
            $line = $lines[$index];

            if (trim($line) === '') {
                $end = $index + 1;
                break;
            }

            if (! preg_match('/^\s*(sent|date|to|cc|subject)\s*:\s*(.*)$/i', $line, $matches)) {
                break;
            }

            $headers[strtolower($matches[1])] = trim($matches[2]);
            $end = $index + 1;
        }

        $hasDate = isset($headers['sent']) || isset($headers['date']);

        if (($requireDate && ! $hasDate) || ($requireSubject && ! isset($headers['subject']))) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'headers' => $headers,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function parseAddress(string $from): array
    {
        $from = trim($from);

        if ($from === '') {
            return [null, null];
        }

        $email = null;

        if (preg_match('/<\s*([^<>\s]+@[^<>\s]+)\s*>/', $from, $matches)) {
            $email = $matches[1];
            $name = trim(str_replace($matches[0], '', $from), " \t\"'");

            return [$name !== '' ? $name : null, $email];
        }

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $from, $matches)) {
            $email = $matches[0];
            $name = trim(str_replace($email, '', $from), " \t\"'");

            return [$name !== '' ? $name : null, $email];
        }

        return [$from, null];
    }

    private function normalizeLines(array $lines): string
    {
        foreach ($lines as &$line) {
            $line = rtrim($line, " \t");
        }
        unset($line);

        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }

        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        $text = implode("\n", $lines);
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
