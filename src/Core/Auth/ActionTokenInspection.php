<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use DateTimeImmutable;

final class ActionTokenInspection
{
    public function __construct(
        public readonly ActionTokenStatus $status,
        public readonly ?ActionTokenBinding $binding = null,
        public readonly ?DateTimeImmutable $expiresAt = null
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status->value,
            'purpose' => $this->binding?->purpose->value,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
        ];
    }
}
