<?php

namespace ADCT\ParishIntake\Parsing\Input;

final class Message
{
    private string $sourceType;
    private string $sourceIdentifier;
    private string $senderEmail;
    private string $senderName;
    private string $subject;
    private string $body;

    /** @var Attachment[] */
    private array $attachments;

    public function __construct(
        string $sourceType,
        string $sourceIdentifier,
        string $senderEmail,
        string $senderName,
        string $subject,
        string $body,
        array $attachments = []
    ) {
        $this->sourceType = $sourceType;
        $this->sourceIdentifier = $sourceIdentifier;
        $this->senderEmail = $senderEmail;
        $this->senderName = $senderName;
        $this->subject = $subject;
        $this->body = $body;
        $this->attachments = $attachments;
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

    /** @return Attachment[] */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function fullText(): string
    {
        return trim($this->subject . "\n\n" . $this->body);
    }
}
