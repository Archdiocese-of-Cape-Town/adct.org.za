<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ports\ActionTokenRateLimitStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class WordPressActionTokenRateLimitStore implements ActionTokenRateLimitStoreInterface
{
    private const RETENTION_SECONDS = 48 * 60 * 60;

    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function consume(
        string $scopeHash,
        DateTimeImmutable $windowStart,
        int $limit
    ): bool {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $scopeHash) !== 1 || $limit < 1) {
            throw new InvalidArgumentException('The action token rate-limit bucket is invalid.');
        }

        $timestamp = self::formatUtc($windowStart);
        $table = $this->tableName();
        $this->pruneExpiredBuckets(
            $table,
            self::formatUtc($windowStart->modify('-' . self::RETENTION_SECONDS . ' seconds'))
        );
        $query = $this->database->prepare(
            "INSERT INTO {$table} (scope_hash, window_started_at, hit_count, created_at, updated_at) "
            . 'VALUES (%s, %s, LAST_INSERT_ID(1), %s, %s) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'updated_at = IF(window_started_at <> VALUES(window_started_at) OR hit_count < %d, VALUES(updated_at), updated_at), '
            . 'hit_count = LAST_INSERT_ID(IF(window_started_at = VALUES(window_started_at), '
            . 'LEAST(hit_count + 1, %d), 1)), '
            . 'window_started_at = VALUES(window_started_at)',
            $scopeHash,
            $timestamp,
            $timestamp,
            $timestamp,
            $limit,
            $limit + 1
        );
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The action token rate limit could not be recorded.');
        }

        $this->database->clearLastError();
        $row = $this->database->getRow('SELECT LAST_INSERT_ID() AS request_count');

        $requestCount = is_array($row)
            ? filter_var($row['request_count'] ?? null, FILTER_VALIDATE_INT)
            : false;

        if ($this->database->lastError() !== '' || ! is_int($requestCount) || $requestCount < 1) {
            throw new RuntimeException('The action token rate-limit decision could not be read.');
        }

        return $requestCount <= $limit;
    }

    private function pruneExpiredBuckets(string $table, string $cutoff): void
    {
        $query = $this->database->prepare(
            "DELETE FROM {$table} WHERE window_started_at < %s",
            $cutoff
        );
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('Expired action token rate limits could not be pruned.');
        }
    }

    private function tableName(): string
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The WordPress database prefix is invalid.');
        }

        return '`' . $prefix . 'adct_pi_action_token_rate_limits`';
    }

    private static function formatUtc(DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
