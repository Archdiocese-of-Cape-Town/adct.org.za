<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use DateTimeImmutable;
use InvalidArgumentException;

final class ActionTokenRecord
{
    public function __construct(
        public readonly string $tokenHash,
        public readonly ActionTokenBinding $binding,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $usedAt,
        public readonly DateTimeImmutable $createdAt
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1) {
            throw new InvalidArgumentException('The stored action token hash is invalid.');
        }
    }

    /**
     * @return array<string, string|int|null>
     */
    public function __debugInfo(): array
    {
        return [
            'token_hash' => '[redacted]',
            'purpose' => $this->binding->purpose->value,
            'subject_type' => $this->binding->subjectType,
            'subject_id' => $this->binding->subjectId,
            'email' => '[redacted]',
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'used_at' => $this->usedAt?->format(DATE_ATOM),
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
