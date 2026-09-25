<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use DateTimeImmutable;

final class IssuedActionToken
{
    public function __construct(
        private string $secret,
        private DateTimeImmutable $expiration
    ) {
    }

    public function token(): string
    {
        return $this->secret;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiration;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'token' => '[redacted]',
            'expires_at' => $this->expiration->format(DATE_ATOM),
        ];
    }
}
