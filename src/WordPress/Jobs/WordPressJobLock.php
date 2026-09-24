<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs;

use ADCT\ParishIntake\Core\Ports\JobLockInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class WordPressJobLock implements JobLockInterface
{
    private const OPTION_PREFIX = 'adct_pi_job_lock_';
    private const MAX_ACQUIRE_ATTEMPTS = 3;

    public function acquire(string $jobId, DateTimeImmutable $now, int $expiresInSeconds): ?string
    {
        if ($expiresInSeconds < 1) {
            throw new InvalidArgumentException('A job lock expiry must be positive.');
        }

        $optionName = $this->optionName($jobId);

        for ($attempt = 0; $attempt < self::MAX_ACQUIRE_ATTEMPTS; ++$attempt) {
            $token = bin2hex(random_bytes(32));
            $newValue = json_encode([
                'token' => $token,
                'expires_at' => $now->getTimestamp() + $expiresInSeconds,
            ], JSON_THROW_ON_ERROR);

            if (add_option($optionName, $newValue, '', false)) {
                return $token;
            }

            $existingValue = $this->readRawOption($optionName);

            if ($existingValue === null) {
                continue;
            }

            $existingLock = $this->decodeLock($existingValue);

            if ($existingLock === null) {
                $this->deleteIfUnchanged($optionName, $existingValue);
                continue;
            }

            if ($existingLock['expires_at'] > $now->getTimestamp()) {
                return null;
            }

            $this->deleteIfUnchanged($optionName, $existingValue);
        }

        throw new RuntimeException('Unable to acquire an expired job lock after repeated attempts.');
    }

    public function isHeldBy(string $jobId, string $token, DateTimeImmutable $now): bool
    {
        $value = $this->readRawOption($this->optionName($jobId));

        if ($value === null) {
            return false;
        }

        $lock = $this->decodeLock($value);

        if ($lock === null) {
            return false;
        }

        return $lock['expires_at'] > $now->getTimestamp()
            && hash_equals($lock['token'], $token);
    }

    public function release(string $jobId, string $token): bool
    {
        $optionName = $this->optionName($jobId);
        $value = $this->readRawOption($optionName);

        if ($value === null) {
            return false;
        }

        $lock = $this->decodeLock($value);

        if ($lock === null) {
            return false;
        }

        if (! hash_equals($lock['token'], $token)) {
            return false;
        }

        return $this->deleteIfUnchanged($optionName, $value);
    }

    private function optionName(string $jobId): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $jobId) !== 1) {
            throw new InvalidArgumentException('Job IDs must be lowercase letters, digits, and underscores.');
        }

        return self::OPTION_PREFIX . $jobId;
    }

    /**
     * @return array{token: string, expires_at: int}|null
     */
    private function decodeLock(string $value): ?array
    {
        try {
            $lock = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            ! is_array($lock)
            || ! isset($lock['token'], $lock['expires_at'])
            || ! is_string($lock['token'])
            || preg_match('/\A[a-f0-9]{64}\z/', $lock['token']) !== 1
            || ! is_int($lock['expires_at'])
        ) {
            return null;
        }

        return [
            'token' => $lock['token'],
            'expires_at' => $lock['expires_at'],
        ];
    }

    private function readRawOption(string $optionName): ?string
    {
        $wpdb = $this->database();
        $query = $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $optionName
        );
        $value = $wpdb->get_var($query);
        $this->throwOnDatabaseError($wpdb);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException('A stored job lock has an invalid value.');
        }

        return $value;
    }

    private function deleteIfUnchanged(string $optionName, string $value): bool
    {
        $wpdb = $this->database();
        $query = $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $optionName,
            $value
        );
        $deleted = $wpdb->query($query);
        $this->throwOnDatabaseError($wpdb);
        $this->clearOptionCache($optionName);

        if (! is_int($deleted)) {
            throw new RuntimeException('Unable to compare-and-delete a job lock.');
        }

        if ($deleted > 1) {
            throw new RuntimeException('More than one option row matched a job lock.');
        }

        return $deleted === 1;
    }

    private function database(): object
    {
        global $wpdb;

        if (
            ! is_object($wpdb)
            || ! isset($wpdb->options)
            || ! is_string($wpdb->options)
            || preg_match('/\A[A-Za-z0-9_]+\z/', $wpdb->options) !== 1
            || ! method_exists($wpdb, 'prepare')
            || ! method_exists($wpdb, 'get_var')
            || ! method_exists($wpdb, 'query')
        ) {
            throw new RuntimeException('The WordPress options database is unavailable.');
        }

        return $wpdb;
    }

    private function throwOnDatabaseError(object $wpdb): void
    {
        if (isset($wpdb->last_error) && $wpdb->last_error !== '') {
            throw new RuntimeException('The job lock could not be read or updated in the WordPress database.');
        }
    }

    private function clearOptionCache(string $optionName): void
    {
        wp_cache_delete($optionName, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
