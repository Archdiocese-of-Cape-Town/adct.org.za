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

    public function __construct(
        string $recipient,
        string $subject,
        string $htmlBody,
        string $textBody,
        MailPriority $priority,
        ?string $groupKey = null
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

        $this->recipient = $recipient;
        $this->subject = $subject;
        $this->htmlBody = $htmlBody;
        $this->textBody = $textBody;
        $this->priority = $priority;
        $this->groupKey = $groupKey;
    }

    public function hasSamePayload(self $other): bool
    {
        return $this->recipient === $other->recipient
            && $this->subject === $other->subject
            && $this->htmlBody === $other->htmlBody
            && $this->textBody === $other->textBody
            && $this->priority === $other->priority
            && $this->groupKey === $other->groupKey;
    }
}
