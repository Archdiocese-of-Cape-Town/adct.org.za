<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use InvalidArgumentException;

final class ActionTokenBinding
{
    public readonly string $subjectType;
    public readonly int $subjectId;
    public readonly string $email;

    public function __construct(
        public readonly ActionTokenPurpose $purpose,
        string $subjectType,
        int $subjectId,
        string $email
    ) {
        $subjectType = strtolower(trim($subjectType));

        if (preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $subjectType) !== 1) {
            throw new InvalidArgumentException('The action token subject type is invalid.');
        }

        if ($subjectId < 1) {
            throw new InvalidArgumentException('The action token subject ID must be positive.');
        }

        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->email = self::normalizeEmailAddress($email);
    }

    public static function normalizeEmailAddress(string $email): string
    {
        $email = strtolower(trim($email));

        if (
            strlen($email) > 191
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException('The action token email address is invalid.');
        }

        return $email;
    }

    public function equals(self $other): bool
    {
        return $this->purpose === $other->purpose
            && $this->subjectId === $other->subjectId
            && hash_equals($this->subjectType, $other->subjectType)
            && hash_equals($this->email, $other->email);
    }

    /**
     * @return array<string, string|int>
     */
    public function __debugInfo(): array
    {
        return [
            'purpose' => $this->purpose->value,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'email' => '[redacted]',
        ];
    }
}
