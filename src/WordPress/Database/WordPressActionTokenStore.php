<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
use ADCT\ParishIntake\Core\Ports\ActionTokenStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class WordPressActionTokenStore implements ActionTokenStoreInterface
{
    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function create(ActionTokenRecord $record): void
    {
        $table = $this->tableName();
        $now = self::formatUtc($record->createdAt);
        $query = $this->database->prepare(
            "INSERT INTO {$table} "
            . '(token_hash, purpose, subject_type, subject_id, email, expires_at, used_at, created_ip, created_at, updated_at) '
            . 'VALUES (%s, %s, %s, %d, %s, %s, NULL, NULL, %s, %s)',
            $record->tokenHash,
            $record->binding->purpose->value,
            $record->binding->subjectType,
            $record->binding->subjectId,
            $record->binding->email,
            self::formatUtc($record->expiresAt),
            $now,
            $now
        );

        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false || $result < 1 || $this->database->lastError() !== '') {
            throw new RuntimeException('The action token could not be stored.');
        }
    }

    public function findByHash(string $tokenHash): ?ActionTokenRecord
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1) {
            throw new RuntimeException('The action token lookup is invalid.');
        }

        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT token_hash, purpose, subject_type, subject_id, email, expires_at, used_at, created_at "
            . "FROM {$table} WHERE token_hash = %s LIMIT 1",
            $tokenHash
        );
        $this->database->clearLastError();
        $row = $this->database->getRow($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The action token could not be read.');
        }

        return $row === null ? null : $this->recordFromRow($row);
    }

    public function consume(
        string $tokenHash,
        ActionTokenBinding $binding,
        DateTimeImmutable $now
    ): bool {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1) {
            return false;
        }

        $timestamp = self::formatUtc($now);
        $table = $this->tableName();
        $query = $this->database->prepare(
            "UPDATE {$table} SET used_at = %s, updated_at = %s "
            . 'WHERE token_hash = %s AND purpose = %s AND subject_type = %s '
            . 'AND subject_id = %d AND email = %s AND used_at IS NULL AND expires_at > %s',
            $timestamp,
            $timestamp,
            $tokenHash,
            $binding->purpose->value,
            $binding->subjectType,
            $binding->subjectId,
            $binding->email,
            $timestamp
        );
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('The action token could not be consumed.');
        }

        return $result === 1;
    }

    private function recordFromRow(array $row): ActionTokenRecord
    {
        $purpose = ActionTokenPurpose::tryFrom((string) ($row['purpose'] ?? ''));
        $email = $row['email'] ?? null;
        $subjectId = filter_var($row['subject_id'] ?? null, FILTER_VALIDATE_INT);

        if (
            $purpose === null
            || ! is_string($row['token_hash'] ?? null)
            || ! is_string($row['subject_type'] ?? null)
            || ! is_int($subjectId)
            || $subjectId < 1
            || ! is_string($email)
        ) {
            throw new RuntimeException('The stored action token is invalid.');
        }

        try {
            $binding = new ActionTokenBinding(
                $purpose,
                $row['subject_type'],
                $subjectId,
                $email
            );
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('The stored action token is invalid.');
        }

        return new ActionTokenRecord(
            $row['token_hash'],
            $binding,
            self::parseUtc($row['expires_at'] ?? null),
            ($row['used_at'] ?? null) === null
                ? null
                : self::parseUtc($row['used_at']),
            self::parseUtc($row['created_at'] ?? null)
        );
    }

    private function tableName(): string
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The WordPress database prefix is invalid.');
        }

        return '`' . $prefix . 'adct_pi_action_tokens`';
    }

    private static function formatUtc(DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private static function parseUtc(mixed $value): DateTimeImmutable
    {
        if (! is_string($value)) {
            throw new RuntimeException('The stored action token timestamp is invalid.');
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $dateTime === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $dateTime->format('Y-m-d H:i:s') !== $value
        ) {
            throw new RuntimeException('The stored action token timestamp is invalid.');
        }

        return $dateTime;
    }
}
