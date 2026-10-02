<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final class OutboundEmail
{
    public readonly string $recipient;
    public readonly string $subject;
    public readonly string $htmlBody;
    public readonly string $textBody;
    public readonly MailPriority $priority;

    /**
     * Stable idempotency key for one already-composed message to this recipient, not a coalescing bucket.
     */
    public readonly ?string $groupKey;
    public readonly ?EmailThreadHeaders $threadHeaders;
    public readonly ?string $payloadFingerprint;

    public function __construct(
        string $recipient,
        string $subject,
        string $htmlBody,
        string $textBody,
        MailPriority $priority,
        ?string $groupKey = null,
        ?EmailThreadHeaders $threadHeaders = null,
        ?string $payloadFingerprint = null
    ) {
        $recipient = strtolower(trim($recipient));

        if (
            strlen($recipient) > 191
            || preg_match('/[\x00-\x20\x7F]/', $recipient) === 1
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException('The outbound email recipient is invalid.');
        }

        $subjectLength = preg_match_all('/./us', $subject);

        if (
            $subjectLength === false
            || $subjectLength < 1
            || $subjectLength > 255
            || preg_match('/[\x00-\x1F\x7F\p{Zl}\p{Zp}]/u', $subject) !== 0
        ) {
            throw new InvalidArgumentException('The outbound email subject is invalid.');
        }

        if ($htmlBody === '' && $textBody === '') {
            throw new InvalidArgumentException('An outbound email must have a body.');
        }

        if (
            $groupKey !== null
            && preg_match('/\A[a-z0-9][a-z0-9._:-]{0,190}\z/D', $groupKey) !== 1
        ) {
            throw new InvalidArgumentException('The outbound email group key is invalid.');
        }

        if (
            $payloadFingerprint !== null
            && preg_match('/\A[a-f0-9]{64}\z/D', $payloadFingerprint) !== 1
        ) {
            throw new InvalidArgumentException('The outbound email payload fingerprint must be a SHA-256 value.');
        }

        $this->recipient = $recipient;
        $this->subject = $subject;
        $this->htmlBody = $htmlBody;
        $this->textBody = $textBody;
        $this->priority = $priority;
        $this->groupKey = $groupKey;
        $this->threadHeaders = $threadHeaders;
        $this->payloadFingerprint = $payloadFingerprint;
    }

    public function hasSamePayload(self $other): bool
    {
        return $this->recipient === $other->recipient
            && $this->subject === $other->subject
            && $this->htmlBody === $other->htmlBody
            && $this->textBody === $other->textBody
            && $this->priority === $other->priority
            && $this->groupKey === $other->groupKey
            && $this->threadHeadersEqual($other)
            && $this->payloadFingerprint === $other->payloadFingerprint;
    }

    private function threadHeadersEqual(self $other): bool
    {
        if ($this->threadHeaders === null || $other->threadHeaders === null) {
            return $this->threadHeaders === $other->threadHeaders;
        }

        return $this->threadHeaders->equals($other->threadHeaders);
    }
}
