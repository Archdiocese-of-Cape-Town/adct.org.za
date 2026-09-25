<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitKeyProviderInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ActionTokenRateLimiter
{
    public const EMAIL_REQUEST_LIMIT = 3;
    public const IP_REQUEST_LIMIT = 20;
    public const WINDOW_SECONDS = 3600;

    public function __construct(
        private ActionTokenRateLimitStoreInterface $store,
        private ClockInterface $clock,
        private string|ActionTokenRateLimitKeyProviderInterface $hmacKey,
        private int $emailRequestLimit = self::EMAIL_REQUEST_LIMIT,
        private int $ipRequestLimit = self::IP_REQUEST_LIMIT,
        private int $windowSeconds = self::WINDOW_SECONDS
    ) {
        if (is_string($hmacKey) && strlen($hmacKey) < 32) {
            throw new InvalidArgumentException('The action token rate-limit key is too short.');
        }

        if ($emailRequestLimit < 1 || $ipRequestLimit < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('Action token rate-limit settings must be positive.');
        }
    }

    public function allowRequest(string $email, string $remoteAddress): bool
    {
        $hmacKey = $this->getHmacKey();
        $normalizedEmail = ActionTokenBinding::normalizeEmailAddress($email);
        $normalizedIp = self::normalizeIpAddress($remoteAddress);
        $now = $this->clock->now();
        $windowStart = $this->windowStart($now);

        $emailAllowed = $this->store->consume(
            hash_hmac('sha256', 'email:' . $normalizedEmail, $hmacKey),
            $windowStart,
            $this->emailRequestLimit
        );
        $ipAllowed = $this->store->consume(
            hash_hmac('sha256', 'ip:' . $normalizedIp, $hmacKey),
            $windowStart,
            $this->ipRequestLimit
        );

        return $emailAllowed && $ipAllowed;
    }

    private function getHmacKey(): string
    {
        $hmacKey = $this->hmacKey instanceof ActionTokenRateLimitKeyProviderInterface
            ? $this->hmacKey->getKey()
            : $this->hmacKey;

        if (strlen($hmacKey) < 32) {
            throw new InvalidArgumentException('The action token rate-limit key is too short.');
        }

        return $hmacKey;
    }

    private function windowStart(DateTimeImmutable $now): DateTimeImmutable
    {
        $timestamp = intdiv($now->getTimestamp(), $this->windowSeconds) * $this->windowSeconds;

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    private static function normalizeIpAddress(string $remoteAddress): string
    {
        $remoteAddress = trim($remoteAddress);
        $validated = filter_var($remoteAddress, FILTER_VALIDATE_IP);

        if (! is_string($validated)) {
            return 'unknown';
        }

        $packed = inet_pton($validated);

        return is_string($packed) ? bin2hex($packed) : 'unknown';
    }
}
