<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Support;

final class CleanedEmailText
{
    public function __construct(
        private string $body,
        private string $quotedText = '',
        private string $signatureText = '',
        private bool $forwarded = false,
        private ?string $originalSenderEmail = null,
        private ?string $originalSenderName = null,
        private ?string $originalDate = null,
        private ?string $originalSubject = null
    ) {
    }

    public function getBody(): string
    {
        return $this->body;
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
}
