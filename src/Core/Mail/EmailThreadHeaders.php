<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;
use JsonException;

final readonly class EmailThreadHeaders
{
    private function __construct(
        public string $inReplyTo,
        public string $references
    ) {
    }

    public static function fromOriginalMessageId(?string $messageId): ?self
    {
        if ($messageId === null || preg_match('/[\x00-\x1F\x7F]/', $messageId) === 1) {
            return null;
        }

        $messageId = trim($messageId);

        if (
            strlen($messageId) > 258
            || preg_match('/\A<[A-Za-z0-9._%+-]{1,128}@[A-Za-z0-9.-]{1,128}>\z/D', $messageId) !== 1
        ) {
            return null;
        }

        return new self($messageId, $messageId);
    }

    public static function fromJson(?string $json): ?self
    {
        if ($json === null) {
            return null;
        }

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('Stored email thread headers are invalid JSON.', 0, $failure);
        }

        if (
            ! is_array($data)
            || ! is_string($data['in_reply_to'] ?? null)
            || ! is_string($data['references'] ?? null)
        ) {
            throw new InvalidArgumentException('Stored email thread headers have an invalid shape.');
        }

        $headers = self::fromOriginalMessageId($data['in_reply_to']);

        if ($headers === null || ! hash_equals($headers->references, $data['references'])) {
            throw new InvalidArgumentException('Stored email thread headers are not safe.');
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    public function toHeaderLines(): array
    {
        return [
            'In-Reply-To: ' . $this->inReplyTo,
            'References: ' . $this->references,
        ];
    }

    public function toJson(): string
    {
        return json_encode([
            'in_reply_to' => $this->inReplyTo,
            'references' => $this->references,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->inReplyTo, $other->inReplyTo)
            && hash_equals($this->references, $other->references);
    }
}
