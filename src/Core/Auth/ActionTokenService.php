<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use InvalidArgumentException;

final class ActionTokenService
{
    private const TOKEN_BYTES = 32;

    public function __construct(
        private ActionTokenStoreInterface $store,
        private ClockInterface $clock
    ) {
    }

    public function issue(
        ActionTokenBinding $binding,
        ?DateTimeImmutable $expiresAt = null
    ): IssuedActionToken {
        $now = $this->clock->now();
        $expiresAt ??= $now->modify('+' . $binding->purpose->defaultLifetimeSeconds() . ' seconds');

        if ($expiresAt <= $now) {
            throw new InvalidArgumentException('An action token must expire in the future.');
        }

        $secret = self::encodeToken(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $secret);
        $this->store->create(new ActionTokenRecord(
            $tokenHash,
            $binding,
            $expiresAt,
            null,
            $now
        ));

        return new IssuedActionToken($secret, $expiresAt);
    }

    public function inspect(
        string $secret,
        ?ActionTokenBinding $expectedBinding = null
    ): ActionTokenInspection {
        if (! self::isValidSecret($secret)) {
            return new ActionTokenInspection(ActionTokenStatus::INVALID);
        }

        $record = $this->store->findByHash(hash('sha256', $secret));

        if ($record === null || (
            $expectedBinding !== null && ! $record->binding->equals($expectedBinding)
        )) {
            return new ActionTokenInspection(ActionTokenStatus::INVALID);
        }

        if ($record->usedAt !== null) {
            return new ActionTokenInspection(
                ActionTokenStatus::USED,
                $record->binding,
                $record->expiresAt
            );
        }

        if ($record->expiresAt <= $this->clock->now()) {
            return new ActionTokenInspection(
                ActionTokenStatus::EXPIRED,
                $record->binding,
                $record->expiresAt
            );
        }

        return new ActionTokenInspection(
            ActionTokenStatus::VALID,
            $record->binding,
            $record->expiresAt
        );
    }

    public function consume(
        string $secret,
        ActionTokenBinding $expectedBinding
    ): ActionTokenInspection {
        $inspection = $this->inspect($secret, $expectedBinding);

        if ($inspection->status !== ActionTokenStatus::VALID) {
            return $inspection;
        }

        $tokenHash = hash('sha256', $secret);
        $now = $this->clock->now();

        if ($this->store->consume($tokenHash, $expectedBinding, $now)) {
            return new ActionTokenInspection(
                ActionTokenStatus::CONSUMED,
                $expectedBinding,
                $inspection->expiresAt
            );
        }

        $afterAttempt = $this->inspect($secret, $expectedBinding);

        return $afterAttempt->status === ActionTokenStatus::VALID
            ? new ActionTokenInspection(ActionTokenStatus::INVALID)
            : $afterAttempt;
    }

    private static function encodeToken(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function isValidSecret(string $secret): bool
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $secret) !== 1) {
            return false;
        }

        $decoded = base64_decode(strtr($secret, '-_', '+/') . '=', true);

        return is_string($decoded)
            && strlen($decoded) === self::TOKEN_BYTES
            && hash_equals(self::encodeToken($decoded), $secret);
    }
}
