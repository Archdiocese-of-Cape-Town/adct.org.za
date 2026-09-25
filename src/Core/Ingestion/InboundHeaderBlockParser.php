<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;

final class InboundHeaderBlockParser
{
    public const MAX_HEADER_BYTES = 65536;

    private const AUTOMATION_HEADER_NAMES = [
        'auto-submitted' => 'Auto-Submitted',
        'x-autoreply' => 'X-Autoreply',
        'x-autorespond' => 'X-Autorespond',
        'precedence' => 'Precedence',
        'list-id' => 'List-Id',
        'list-post' => 'List-Post',
        'list-unsubscribe' => 'List-Unsubscribe',
        'list-subscribe' => 'List-Subscribe',
        'list-help' => 'List-Help',
        'list-archive' => 'List-Archive',
        'list-owner' => 'List-Owner',
        'mailing-list' => 'Mailing-List',
        'x-beenthere' => 'X-BeenThere',
        'return-path' => 'Return-Path',
        'content-type' => 'Content-Type',
        'x-auto-response-suppress' => 'X-Auto-Response-Suppress',
        'x-loop' => 'X-Loop',
    ];

    public function __construct(
        private readonly AutomatedMailDetector $automatedMailDetector = new AutomatedMailDetector()
    ) {
    }

    public function parse(string $headerBlock, ?string $senderEmail): InboundHeaderBlock
    {
        if (
            strlen($headerBlock) > self::MAX_HEADER_BYTES
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $headerBlock) === 1
        ) {
            throw new InvalidArgumentException('The inbound email header block is too large or contains invalid controls.');
        }

        $valuesByName = [];
        $currentName = null;

        foreach (preg_split('/\r\n|\n|\r/', $headerBlock) ?: [] as $line) {
            if ($line === '') {
                break;
            }

            if ($line[0] === ' ' || $line[0] === "\t") {
                if ($currentName === null) {
                    throw new InvalidArgumentException('The inbound email header block begins with an invalid continuation.');
                }

                $lastIndex = count($valuesByName[$currentName]) - 1;
                $valuesByName[$currentName][$lastIndex] .= ' ' . trim($line, " \t");

                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false || $separator === 0) {
                throw new InvalidArgumentException('The inbound email header block contains a malformed field.');
            }

            $name = strtolower(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1), " \t");

            if (
                preg_match('/\A[a-z0-9-]+\z/D', $name) !== 1
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
            ) {
                throw new InvalidArgumentException('The inbound email header block contains an invalid field.');
            }

            $valuesByName[$name] ??= [];
            $valuesByName[$name][] = $value;
            $currentName = $name;
        }

        $headers = [];

        foreach (self::AUTOMATION_HEADER_NAMES as $lowercaseName => $canonicalName) {
            if (isset($valuesByName[$lowercaseName])) {
                $headers[$canonicalName] = $valuesByName[$lowercaseName];
            }
        }

        $contentTypes = $headers['Content-Type'] ?? [];
        $automatedAssessment = $this->automatedMailDetector->inspect(
            $headers,
            $senderEmail,
            $contentTypes
        );
        $replyToEmail = $this->singleMailbox($valuesByName['reply-to'] ?? []);
        $messageId = $this->singleHeaderValue($valuesByName['message-id'] ?? []);

        return new InboundHeaderBlock(
            $headers,
            $replyToEmail,
            $messageId,
            $automatedAssessment
        );
    }

    /**
     * @param list<string> $values
     */
    private function singleMailbox(array $values): ?string
    {
        if (count($values) !== 1) {
            return null;
        }

        $value = trim($values[0]);

        if (preg_match('/\A[^<>]*<\s*([^<>\s,;]+)\s*>\z/D', $value, $matches) === 1) {
            $value = $matches[1];
        } elseif (strpbrk($value, '<>,;') !== false) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }

    /**
     * @param list<string> $values
     */
    private function singleHeaderValue(array $values): ?string
    {
        if (count($values) !== 1 || trim($values[0]) === '') {
            return null;
        }

        return trim($values[0]);
    }
}
