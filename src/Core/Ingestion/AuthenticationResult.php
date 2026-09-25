<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;

final readonly class AuthenticationResult
{
    public const METHODS = ['spf', 'dkim', 'dmarc'];
    public const VERDICTS = [
        'pass',
        'fail',
        'softfail',
        'neutral',
        'none',
        'temperror',
        'permerror',
        'policy',
        'unknown',
        'bestguesspass',
    ];

    public function __construct(
        public string $method,
        public string $result,
        public ?string $authservId,
        public bool $trusted
    ) {
        if (! in_array($method, self::METHODS, true) || ! in_array($result, self::VERDICTS, true)) {
            throw new InvalidArgumentException('An authentication result has an unsupported method or verdict.');
        }

        if (
            $authservId !== null
            && (
                strlen($authservId) > 253
                || preg_match('/\A[a-z0-9][a-z0-9._:-]*(?:\/[a-z0-9._-]+)?\z/iD', $authservId) !== 1
            )
        ) {
            throw new InvalidArgumentException('An authentication result has an invalid authserv-id.');
        }

        if ($trusted && $authservId === null) {
            throw new InvalidArgumentException('An authentication result without an authserv-id cannot be trusted.');
        }
    }

    /**
     * @return array{authserv_id: string|null, result: string, trusted: bool}
     */
    public function toArray(): array
    {
        return [
            'authserv_id' => $this->authservId,
            'result' => $this->result,
            'trusted' => $this->trusted,
        ];
    }

    public static function fromArray(string $method, mixed $value): self
    {
        if (
            ! is_array($value)
            || ! is_string($value['result'] ?? null)
            || ! array_key_exists('authserv_id', $value)
            || (! is_string($value['authserv_id']) && $value['authserv_id'] !== null)
            || ! is_bool($value['trusted'] ?? null)
        ) {
            throw new InvalidArgumentException('Stored authentication results contain an invalid verdict.');
        }

        return new self(
            $method,
            strtolower($value['result']),
            $value['authserv_id'],
            $value['trusted']
        );
    }
}
