<?php

namespace ADCT\ParishIntake\Core\Parsing\Input;

use DateTimeImmutable;

final class Message
{
    private string $sourceType;
    private string $sourceIdentifier;
    private string $senderEmail;
    private string $senderName;
    private string $subject;
    private string $body;
    private ?DateTimeImmutable $receivedAt;

    /** @var Attachment[] */
    private array $attachments;
    /** @var array<string, string[]> */
    private array $headers;
    private string $quotedText;
    private string $signatureText;
    private bool $forwarded;
    private ?string $originalSenderEmail;
    private ?string $originalSenderName;
    private ?string $originalDate;
    private ?string $originalSubject;
    private bool $bodyHtmlDerived;

    public function __construct(
        string $sourceType,
        string $sourceIdentifier,
        string $senderEmail,
        string $senderName,
        string $subject,
        string $body,
        array $attachments = [],
        ?DateTimeImmutable $receivedAt = null,
        array $headers = [],
        string $quotedText = '',
        string $signatureText = '',
        bool $forwarded = false,
        ?string $originalSenderEmail = null,
        ?string $originalSenderName = null,
        ?string $originalDate = null,
        ?string $originalSubject = null,
        bool $bodyHtmlDerived = false
    ) {
        $this->sourceType = $sourceType;
        $this->sourceIdentifier = $sourceIdentifier;
        $this->senderEmail = $senderEmail;
        $this->senderName = $senderName;
        $this->subject = $subject;
        $this->body = $body;
        $this->attachments = $attachments;
        $this->receivedAt = $receivedAt;
        $this->headers = $headers;
        $this->quotedText = $quotedText;
        $this->signatureText = $signatureText;
        $this->forwarded = $forwarded;
        $this->originalSenderEmail = $originalSenderEmail;
        $this->originalSenderName = $originalSenderName;
        $this->originalDate = $originalDate;
        $this->originalSubject = $originalSubject;
        $this->bodyHtmlDerived = $bodyHtmlDerived;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceIdentifier(): string
    {
        return $this->sourceIdentifier;
    }

    public function getSenderEmail(): string
    {
        return $this->senderEmail;
    }

    public function getSenderName(): string
    {
        return $this->senderName;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function withBody(string $body, ?string $subject = null): self
    {
        return new self(
            $this->sourceType,
            $this->sourceIdentifier,
            $this->senderEmail,
            $this->senderName,
            $subject ?? $this->subject,
            $body,
            $this->attachments,
            $this->receivedAt,
            $this->headers,
            $this->quotedText,
            $this->signatureText,
            $this->forwarded,
            $this->originalSenderEmail,
            $this->originalSenderName,
            $this->originalDate,
            $this->originalSubject,
            $this->bodyHtmlDerived
        );
    }

    public function getReceivedAt(): ?DateTimeImmutable
    {
        return $this->receivedAt;
    }

    /** @return Attachment[] */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getRawHeader(string $name): ?string
    {
        foreach ($this->headers as $headerName => $values) {
            if (strcasecmp($headerName, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    public function getMessageId(): ?string
    {
        return $this->getRawHeader('Message-ID');
    }

    public function getInReplyTo(): ?string
    {
        return $this->getRawHeader('In-Reply-To');
    }

    public function getReferences(): ?string
    {
        return $this->getRawHeader('References');
    }

    public function getAutoSubmitted(): ?string
    {
        return $this->getRawHeader('Auto-Submitted');
    }

    public function getListId(): ?string
    {
        return $this->getRawHeader('List-Id');
    }

    public function getAuthenticationResults(): ?string
    {
        return $this->getRawHeader('Authentication-Results');
    }

    public function getQuotedText(): string
    {
        return $this->quotedText;
    }

    public function getSignatureText(): string
    {
        return $this->signatureText;
    }

    public function isForwarded(): bool
    {
        return $this->forwarded;
    }

    public function getOriginalSenderEmail(): ?string
    {
        return $this->originalSenderEmail;
    }

    public function getOriginalSenderName(): ?string
    {
        return $this->originalSenderName;
    }

    public function getOriginalDate(): ?string
    {
        return $this->originalDate;
    }

    public function getOriginalSubject(): ?string
    {
        return $this->originalSubject;
    }

    public function isBodyHtmlDerived(): bool
    {
        return $this->bodyHtmlDerived;
    }

    public function fullText(): string
    {
        return trim($this->subject . "\n\n" . $this->body);
    }
}
