<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Support;

use ADCT\ParishIntake\Core\Parsing\Input\Attachment;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use DateTimeImmutable;
use RuntimeException;

final class EmailFixtureLoader
{
    public function load(string $path): Message
    {
        $source = file_get_contents($path);

        if (! is_string($source)) {
            throw new RuntimeException('Unable to read email fixture: ' . $path);
        }

        [$headerText, $body] = $this->splitEntity($source);
        $headers = $this->parseHeaders($headerText);
        $from = $this->header($headers, 'from');
        $date = $this->header($headers, 'date');

        if ($from === null || trim($from) === '') {
            throw new RuntimeException('Email fixture is missing its From header: ' . $path);
        }

        if ($date === null || trim($date) === '') {
            throw new RuntimeException('Email fixture is missing its Date header: ' . $path);
        }

        if (! preg_match('/(?:\b(?:UT|UTC|GMT|Z)|[+-]\d{2}:?\d{2})$/i', trim($date))) {
            throw new RuntimeException('Email fixture Date header must include an explicit timezone: ' . $path);
        }

        [$senderName, $senderEmail] = $this->parseFrom($from);

        if ($senderEmail === '') {
            throw new RuntimeException('Email fixture From header has no sender address: ' . $path);
        }

        try {
            $receivedAt = new DateTimeImmutable($date);
        } catch (\Exception $exception) {
            throw new RuntimeException('Email fixture has an invalid Date header: ' . $path, 0, $exception);
        }

        $entity = $this->decodeEntity($headers, $body);

        return new Message(
            'email',
            pathinfo($path, PATHINFO_FILENAME),
            $senderEmail,
            $senderName,
            $this->decodeHeader($this->header($headers, 'subject') ?? ''),
            $entity['body'],
            $entity['attachments'],
            $receivedAt
        );
    }

    /**
     * @return array{body: string, attachments: Attachment[]}
     */
    private function decodeEntity(array $headers, string $body): array
    {
        $contentType = $this->header($headers, 'content-type') ?? 'text/plain';
        $mimeType = strtolower(trim(explode(';', $contentType, 2)[0]));

        if (str_starts_with($mimeType, 'multipart/')) {
            $boundary = $this->parameter($contentType, 'boundary');

            if ($boundary === null || $boundary === '') {
                throw new RuntimeException('Multipart email fixture has no boundary.');
            }

            $textParts = [];
            $attachments = [];

            foreach ($this->splitMultipart($body, $boundary) as $part) {
                [$partHeaderText, $partBody] = $this->splitEntity($part);
                $partHeaders = $this->parseHeaders($partHeaderText);
                $filename = $this->attachmentFilename($partHeaders);
                $disposition = strtolower($this->header($partHeaders, 'content-disposition') ?? '');

                if ($filename !== null || str_starts_with($disposition, 'attachment')) {
                    $partContentType = $this->header($partHeaders, 'content-type') ?? 'application/octet-stream';
                    $partMimeType = strtolower(trim(explode(';', $partContentType, 2)[0]));
                    $attachments[] = new Attachment($filename ?? 'attachment', $partMimeType);
                    continue;
                }

                $decodedPart = $this->decodeEntity($partHeaders, $partBody);

                if ($decodedPart['body'] !== '') {
                    $textParts[] = $decodedPart['body'];
                }

                $attachments = array_merge($attachments, $decodedPart['attachments']);
            }

            return [
                'body' => trim(implode("\n\n", $textParts)),
                'attachments' => $attachments,
            ];
        }

        $filename = $this->attachmentFilename($headers);
        $disposition = strtolower($this->header($headers, 'content-disposition') ?? '');

        if ($filename !== null || str_starts_with($disposition, 'attachment')) {
            return [
                'body' => '',
                'attachments' => [
                    new Attachment(
                        $filename ?? 'attachment',
                        $mimeType !== '' ? $mimeType : 'application/octet-stream'
                    ),
                ],
            ];
        }

        if ($mimeType !== '' && $mimeType !== 'text/plain') {
            return ['body' => '', 'attachments' => []];
        }

        return [
            'body' => trim($this->decodeTransferEncoding($body, $this->header($headers, 'content-transfer-encoding'))),
            'attachments' => [],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitEntity(string $source): array
    {
        if (! preg_match('/\r\n\r\n|\n\n|\r\r/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return ['', $source];
        }

        $separatorOffset = $matches[0][1];
        $separatorLength = strlen($matches[0][0]);

        return [
            substr($source, 0, $separatorOffset),
            substr($source, $separatorOffset + $separatorLength),
        ];
    }

    /**
     * @return array<string, string[]>
     */
    private function parseHeaders(string $headerText): array
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', trim($headerText)) ?? trim($headerText);
        $headers = [];

        if ($unfolded === '') {
            return $headers;
        }

        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            if (! preg_match('/^([A-Za-z0-9-]+):[ \t]*(.*)$/', $line, $matches)) {
                throw new RuntimeException('Email fixture contains a malformed header line.');
            }

            $headers[strtolower($matches[1])][] = trim($matches[2]);
        }

        return $headers;
    }

    private function parseFrom(string $from): array
    {
        $from = $this->decodeHeader(trim($from));

        if (preg_match('/^(.*?)\s*<\s*([^<>\s]+)\s*>$/', $from, $matches)) {
            $name = trim($matches[1], " \t\"'");
            $email = trim($matches[2]);

            return [$name !== '' ? $name : $email, $email];
        }

        if (preg_match('/[^\s<>]+@[^\s<>]+/', $from, $matches)) {
            $email = $matches[0];
            $name = trim(str_replace($email, '', $from), " \t\"'");

            return [$name !== '' ? $name : $email, $email];
        }

        return ['', ''];
    }

    private function decodeHeader(string $value): string
    {
        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([bq])\?([^?]*)\?=/i',
            static function (array $matches): string {
                if (strtolower($matches[2]) === 'b') {
                    $word = base64_decode($matches[3], true);

                    if (! is_string($word)) {
                        throw new RuntimeException('Email fixture contains an invalid encoded header word.');
                    }

                    return $word;
                }

                return quoted_printable_decode(str_replace('_', ' ', $matches[3]));
            },
            $value
        );

        return is_string($decoded) ? $decoded : $value;
    }

    private function decodeTransferEncoding(string $body, ?string $encoding): string
    {
        $encoding = strtolower(trim($encoding ?? '7bit'));

        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($body);
        }

        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);

            if (! is_string($decoded)) {
                throw new RuntimeException('Email fixture contains an invalid base64 body.');
            }

            return $decoded;
        }

        if (in_array($encoding, ['', '7bit', '8bit', 'binary'], true)) {
            return $body;
        }

        throw new RuntimeException('Unsupported email fixture transfer encoding: ' . $encoding);
    }

    /**
     * @return string[]
     */
    private function splitMultipart(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $parts = [];
        $currentPart = [];
        $insidePart = false;

        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            $delimiterLine = rtrim($line, " \t");

            if ($delimiterLine === $delimiter || $delimiterLine === $delimiter . '--') {
                if ($insidePart) {
                    $parts[] = trim(implode("\r\n", $currentPart), "\r\n");
                }

                if ($delimiterLine === $delimiter . '--') {
                    $insidePart = false;
                    break;
                }

                $insidePart = true;
                $currentPart = [];
                continue;
            }

            if ($insidePart) {
                $currentPart[] = $line;
            }
        }

        if ($insidePart && $currentPart !== []) {
            $parts[] = trim(implode("\r\n", $currentPart), "\r\n");
        }

        return $parts;
    }

    private function attachmentFilename(array $headers): ?string
    {
        foreach (['content-disposition', 'content-type'] as $headerName) {
            $value = $this->header($headers, $headerName);

            if ($value !== null && preg_match('/(?:filename|name)\*?=(?:"([^"]+)"|([^;\s]+))/i', $value, $matches)) {
                return stripcslashes($matches[1] !== '' ? $matches[1] : $matches[2]);
            }
        }

        return null;
    }

    private function parameter(string $header, string $name): ?string
    {
        $pattern = '/(?:^|;)\s*' . preg_quote($name, '/') . '=(?:"([^"]+)"|([^;\s]+))/i';

        if (! preg_match($pattern, $header, $matches)) {
            return null;
        }

        return $matches[1] !== '' ? $matches[1] : $matches[2];
    }

    private function header(array $headers, string $name): ?string
    {
        return $headers[strtolower($name)][0] ?? null;
    }
}
