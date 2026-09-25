<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use ADCT\ParishIntake\Core\Parsing\Input\Attachment;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Support\EmailTextCleaner;
use ADCT\ParishIntake\Core\Support\HtmlToTextConverter;
use DateTimeImmutable;
use RuntimeException;

final class MimeMessageParser
{
    private const MIME_PARSER_CLASSES = [
        'ZBateson\\MailMimeParser\\MailMimeParser',
        'ADCT\\ParishIntake\\Dependencies\\ZBateson\\MailMimeParser\\MailMimeParser',
    ];

    private const RETAINED_HEADERS = [
        'Date',
        'Message-ID',
        'In-Reply-To',
        'References',
        'Auto-Submitted',
        'List-Id',
        'Authentication-Results',
    ];

    private EmailTextCleaner $textCleaner;
    private HtmlToTextConverter $htmlConverter;

    public function __construct(
        ?EmailTextCleaner $textCleaner = null,
        ?HtmlToTextConverter $htmlConverter = null
    ) {
        $this->textCleaner = $textCleaner ?? new EmailTextCleaner();
        $this->htmlConverter = $htmlConverter ?? new HtmlToTextConverter();
    }

    public function parse(string $rawMessage, ?string $sourceIdentifier = null): Message
    {
        $parserClass = $this->mimeParserClass();
        $mimeMessage = (new $parserClass())->parse($rawMessage, false);
        $subject = trim((string) $mimeMessage->getSubject());
        [$senderName, $senderEmail] = $this->sender($mimeMessage);
        [$body, $htmlDerived] = $this->body($mimeMessage);
        $cleaned = $this->textCleaner->clean($body, $subject);
        $headers = $this->retainedHeaders($mimeMessage);
        $receivedAt = $this->receivedAt($mimeMessage);
        $messageId = $this->headerValue($mimeMessage, 'Message-ID');
        $identifier = $sourceIdentifier
            ?? $messageId
            ?? 'sha256:' . hash('sha256', $rawMessage);

        $attachments = [];

        foreach ($mimeMessage->getAllAttachmentParts() as $index => $part) {
            $filename = trim((string) $part->getFilename());
            $mimeType = strtolower(trim(explode(';', $part->getContentType('application/octet-stream'), 2)[0]));

            $attachments[] = new Attachment(
                $filename !== '' ? $filename : 'attachment-' . ((int) $index + 1),
                $mimeType !== '' ? $mimeType : 'application/octet-stream',
                null,
                'mime-part:' . $index,
                $part->getContentId()
            );
        }

        return new Message(
            'email',
            $identifier,
            $senderEmail,
            $senderName,
            $subject,
            $cleaned->getBody(),
            $attachments,
            $receivedAt,
            $headers,
            $cleaned->getQuotedText(),
            $cleaned->getSignatureText(),
            $cleaned->isForwarded(),
            $cleaned->getOriginalSenderEmail(),
            $cleaned->getOriginalSenderName(),
            $cleaned->getOriginalDate(),
            $cleaned->getOriginalSubject(),
            $htmlDerived
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
     * @return array{0: string, 1: string}
     */
    private function sender(mixed $mimeMessage): array
    {
        $from = $mimeMessage->getHeader('From');

        if (is_object($from) && method_exists($from, 'getEmail')) {
            $email = trim((string) $from->getEmail());
            $name = trim((string) $from->getPersonName());

            return [$name !== '' ? $name : $email, $email];
        }

        if (is_object($from) && method_exists($from, 'getDecodedValue')) {
            $value = $from->getDecodedValue();

            if (preg_match('/^(.*?)\s*<\s*([^<>\s]+)\s*>$/', $value, $matches)) {
                $name = trim($matches[1], " \t\"'");

                return [$name !== '' ? $name : $matches[2], trim($matches[2])];
            }
        }

        return ['', ''];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function body(mixed $mimeMessage): array
    {
        $plainFallback = null;

        for ($index = 0, $count = $mimeMessage->getTextPartCount(); $index < $count; ++$index) {
            $plain = $mimeMessage->getTextContent($index);

            if (! is_string($plain)) {
                continue;
            }

            $plainFallback ??= $plain;

            if ($this->isSubstantiveText($plain)) {
                return [$plain, false];
            }
        }

        for ($index = 0, $count = $mimeMessage->getHtmlPartCount(); $index < $count; ++$index) {
            $html = $mimeMessage->getHtmlContent($index);

            if (! is_string($html)) {
                continue;
            }

            $text = $this->htmlConverter->convert($html);

            if ($this->isSubstantiveText($text)) {
                return [$text, true];
            }
        }

        return [$plainFallback ?? '', false];
    }

    private function isSubstantiveText(string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        return ! (bool) preg_match(
            '/^(?:this\s+is\s+a\s+multi[- ]part\s+message\s+in\s+mime\s+format|this\s+message\s+is\s+in\s+mime\s+format|html\s+version\s+of\s+this\s+message\s+is\s+best\s+viewed\s+in\s+.+)[.!]?\s*$/i',
            $text
        );
    }

    private function receivedAt(mixed $mimeMessage): ?DateTimeImmutable
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
            return null;
        }

        try {
            return new DateTimeImmutable($rawDate);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, string[]>
     */
    private function retainedHeaders(mixed $mimeMessage): array
    {
        $headers = [];

        foreach (self::RETAINED_HEADERS as $name) {
            $values = [];

            foreach ($mimeMessage->getAllHeadersByName($name) as $header) {
                $values[] = $header->getRawValue();
            }

            if ($values !== []) {
                $headers[$name] = $values;
            }
        }

        return $headers;
    }

    private function headerValue(mixed $mimeMessage, string $name): ?string
    {
        $header = $mimeMessage->getHeader($name);

        if (! is_object($header) || ! method_exists($header, 'getRawValue')) {
            return null;
        }

        return $header->getRawValue();
    }
}
