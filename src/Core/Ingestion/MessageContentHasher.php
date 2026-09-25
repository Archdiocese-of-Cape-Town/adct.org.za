<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class MessageContentHasher
{
    /**
     * @param list<string> $attachmentHashes
     */
    public function hash(string $body, array $attachmentHashes = []): string
    {
        foreach ($attachmentHashes as $attachmentHash) {
            if (preg_match('/\A[a-f0-9]{64}\z/i', $attachmentHash) !== 1) {
                throw new InvalidArgumentException('Every attachment hash must be a SHA-256 value.');
            }
        }

        sort($attachmentHashes, SORT_STRING);

        try {
            $canonicalContent = json_encode([
                'version' => 1,
                'body' => $this->normalizeBody($body),
                'attachments' => array_values($attachmentHashes),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The message content could not be encoded for de-duplication.', 0, $failure);
        }

        return hash('sha256', $canonicalContent);
    }

    public function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/[ \t\f\v]+/', ' ', $body) ?? $body;
        $body = preg_replace('/ *\n */', "\n", $body) ?? $body;
        $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;

        return trim($body);
    }
}
