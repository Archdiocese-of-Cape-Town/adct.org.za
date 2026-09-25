<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use DateTimeImmutable;
use RuntimeException;

final class RawMessageInspector
{
    private const MIME_PARSER_CLASSES = [
        'ZBateson\\MailMimeParser\\MailMimeParser',
        'ADCT\\ParishIntake\\Dependencies\\ZBateson\\MailMimeParser\\MailMimeParser',
    ];

    public function inspect(string $rawMessage, DateTimeImmutable $fallbackReceivedAt): InspectedInboundMail
    {
        $parserClass = $this->mimeParserClass();
        $mimeMessage = (new $parserClass())->parse($rawMessage, false);
        $attachments = [];

        foreach ($mimeMessage->getAllAttachmentParts() as $index => $part) {
            $content = $part->getContent();

            if (! is_string($content)) {
                throw new RuntimeException('An email attachment could not be read.');
            }

            $filename = trim((string) $part->getFilename());
            $mimeType = strtolower(trim(explode(
                ';',
                (string) $part->getContentType('application/octet-stream'),
                2
            )[0]));

            $attachments[] = new RawEmailAttachment(
                $filename !== '' ? $filename : 'attachment-' . ((int) $index + 1),
                $mimeType !== '' ? $mimeType : 'application/octet-stream',
                $content
            );
        }

        [$senderName, $senderEmail] = $this->sender($mimeMessage);

        return new InspectedInboundMail(
            $this->headerValue($mimeMessage, 'Message-ID'),
            $senderEmail,
            $senderName,
            trim((string) $mimeMessage->getSubject()),
            $this->body($mimeMessage),
            $this->receivedAt($mimeMessage, $fallbackReceivedAt),
            $attachments
        );
    }

    private function mimeParserClass(): string
    {
        foreach (self::MIME_PARSER_CLASSES as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        throw new RuntimeException('The configured MIME parser dependency is not available.');
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function sender(mixed $mimeMessage): array
    {
        $from = $mimeMessage->getHeader('From');

        if (is_object($from) && method_exists($from, 'getEmail')) {
            $email = trim((string) $from->getEmail());
            $name = trim((string) $from->getPersonName());

            return [
                $name !== '' ? $name : ($email !== '' ? $email : null),
                $email !== '' ? $email : null,
            ];
        }

        if (is_object($from) && method_exists($from, 'getDecodedValue')) {
            $value = (string) $from->getDecodedValue();

            if (preg_match('/^(.*?)\s*<\s*([^<>\s]+)\s*>$/', $value, $matches) === 1) {
                $name = trim($matches[1], " \t\"'");
                $email = trim($matches[2]);

                return [$name !== '' ? $name : $email, $email !== '' ? $email : null];
            }

            $value = trim($value);

            return [$value !== '' ? $value : null, null];
        }

        return [null, null];
    }

    private function body(mixed $mimeMessage): string
    {
        for ($index = 0, $count = $mimeMessage->getTextPartCount(); $index < $count; ++$index) {
            $plain = $mimeMessage->getTextContent($index);

            if (is_string($plain) && trim($plain) !== '') {
                return $plain;
            }
        }

        for ($index = 0, $count = $mimeMessage->getHtmlPartCount(); $index < $count; ++$index) {
            $html = $mimeMessage->getHtmlContent($index);

            if (is_string($html) && trim($html) !== '') {
                return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return '';
    }

    private function receivedAt(mixed $mimeMessage, DateTimeImmutable $fallback): DateTimeImmutable
    {
        $dateHeader = $mimeMessage->getHeader('Date');

        if (is_object($dateHeader) && method_exists($dateHeader, 'getDateTimeImmutable')) {
            $dateTime = $dateHeader->getDateTimeImmutable();

            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime;
            }
        }

        $rawDate = $this->headerValue($mimeMessage, 'Date');

        if ($rawDate === null || trim($rawDate) === '') {
            return $fallback;
        }

        try {
            return new DateTimeImmutable($rawDate);
        } catch (\Exception) {
            return $fallback;
        }
    }

    private function headerValue(mixed $mimeMessage, string $name): ?string
    {
        $header = $mimeMessage->getHeader($name);

        if (! is_object($header) || ! method_exists($header, 'getRawValue')) {
            return null;
        }

        $value = trim((string) $header->getRawValue());

        return $value !== '' ? $value : null;
    }
}
