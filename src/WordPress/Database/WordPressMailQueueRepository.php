<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueClaimResult;
use ADCT\ParishIntake\Core\Mail\MailQueueClaimStatus;
use ADCT\ParishIntake\Core\Mail\MailQueueConfiguration;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueRecord;
use ADCT\ParishIntake\Core\Mail\MailQueueStats;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class WordPressMailQueueRepository implements MailQueueRepositoryInterface
{
    public const SUPPRESSED_PREVIEW_LIMIT = 20;
    public const SUPPRESSED_PREVIEW_BODY_LIMIT = 2000;

    private const TABLE_SUFFIX = 'adct_pi_mail_queue';
    private const LOCK_WAIT_SECONDS = 5;
    private const INTERRUPTED_ERROR_CODE = 'delivery_outcome_unknown_after_interruption';

    public function __construct(private readonly DatabaseConnectionInterface $database)
    {
    }

    public function enqueue(
        OutboundEmail $email,
        MailQueueStatus $initialStatus,
        DateTimeImmutable $now
    ): MailQueueEnqueueResult {
        if (! in_array($initialStatus, [MailQueueStatus::QUEUED, MailQueueStatus::SUPPRESSED], true)) {
            throw new InvalidArgumentException('A new email can only be queued or suppressed.');
        }

        if ($email->groupKey !== null) {
            $lockName = $this->groupLockName($email);

            if (! $this->acquireNamedLock($lockName)) {
                throw new RuntimeException('The mail queue idempotency lock is unavailable.');
            }

            try {
                $existing = $this->findByRecipientAndGroup($email);

                if ($existing !== null) {
                    return $this->existingResult($existing, $email);
                }

                return $this->insertEmail($email, $initialStatus, $now);
            } finally {
                $this->releaseNamedLock($lockName);
            }
        }

        return $this->insertEmail($email, $initialStatus, $now);
    }

    public function findNextDue(DateTimeImmutable $now): ?MailQueueRecord
    {
        $row = $this->fetchRow($this->database->prepare(
            'SELECT * FROM ' . $this->tableName()
            . ' WHERE status = %s AND (next_attempt_at IS NULL OR next_attempt_at <= %s)'
            . ' ORDER BY priority ASC, created_at ASC, id ASC LIMIT 1',
            MailQueueStatus::QUEUED->value,
            $this->timestamp($now)
        ));

        return $row === null ? null : $this->mapRecord($row);
    }

    public function findExpiredClaim(DateTimeImmutable $cutoff): ?MailQueueRecord
    {
        $row = $this->fetchRow($this->database->prepare(
            'SELECT * FROM ' . $this->tableName()
            . ' WHERE status = %s AND updated_at <= %s'
            . ' ORDER BY updated_at ASC, id ASC LIMIT 1',
            MailQueueStatus::SENDING->value,
            $this->timestamp($cutoff)
        ));

        return $row === null ? null : $this->mapRecord($row);
    }

    public function claim(
        int $id,
        DateTimeImmutable $now,
        int $hourlyCap,
        int $windowSeconds
    ): MailQueueClaimResult {
        if ($id < 1 || $hourlyCap < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('A queue claim requires positive limits and a positive row ID.');
        }

        $lockName = $this->capLockName();

        if (! $this->acquireNamedLock($lockName)) {
            return new MailQueueClaimResult(MailQueueClaimStatus::LOCK_BUSY);
        }

        try {
            $row = $this->fetchRow($this->database->prepare(
                'SELECT * FROM ' . $this->tableName()
                . ' WHERE id = %d AND status = %s'
                . ' AND (next_attempt_at IS NULL OR next_attempt_at <= %s) LIMIT 1',
                $id,
                MailQueueStatus::QUEUED->value,
                $this->timestamp($now)
            ));

            if ($row === null) {
                return new MailQueueClaimResult(MailQueueClaimStatus::NOT_CLAIMABLE);
            }

            $cutoff = $this->timestamp($now->modify('-' . $windowSeconds . ' seconds'));
            $usage = $this->fetchRow($this->database->prepare(
                'SELECT ('
                . 'SELECT COUNT(*) FROM ' . $this->tableName()
                . ' WHERE status = %s AND sent_at > %s'
                . ') + ('
                . 'SELECT COUNT(*) FROM ' . $this->tableName()
                . ' WHERE status = %s AND updated_at > %s'
                . ') AS reserved_count',
                MailQueueStatus::SENT->value,
                $cutoff,
                MailQueueStatus::SENDING->value,
                $cutoff
            ));
            $reservedCount = $this->integerValue($usage['reserved_count'] ?? null, 'cap reservation count');

            if ($reservedCount >= $hourlyCap) {
                return new MailQueueClaimResult(MailQueueClaimStatus::CAP_REACHED);
            }

            $updated = $this->execute($this->database->prepare(
                'UPDATE ' . $this->tableName()
                . ' SET status = %s, attempts = attempts + 1, next_attempt_at = NULL,'
                . ' error = NULL, updated_at = %s'
                . ' WHERE id = %d AND status = %s'
                . ' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)',
                MailQueueStatus::SENDING->value,
                $this->timestamp($now),
                $id,
                MailQueueStatus::QUEUED->value,
                $this->timestamp($now)
            ));

            if ($updated !== 1) {
                return new MailQueueClaimResult(MailQueueClaimStatus::NOT_CLAIMABLE);
            }

            $row['status'] = MailQueueStatus::SENDING->value;
            $row['attempts'] = (int) $row['attempts'] + 1;
            $row['next_attempt_at'] = null;
            $row['error'] = null;
            $row['updated_at'] = $this->timestamp($now);

            return new MailQueueClaimResult(
                MailQueueClaimStatus::CLAIMED,
                $this->mapRecord($row)
            );
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    public function markSent(MailQueueRecord $claim, DateTimeImmutable $sentAt): bool
    {
        $updated = $this->execute($this->database->prepare(
            'UPDATE ' . $this->tableName()
            . ' SET status = %s, sent_at = %s, next_attempt_at = NULL, error = NULL, updated_at = %s'
            . ' WHERE id = %d AND status = %s AND attempts = %d AND updated_at = %s',
            MailQueueStatus::SENT->value,
            $this->timestamp($sentAt),
            $this->timestamp($sentAt),
            $claim->id,
            MailQueueStatus::SENDING->value,
            $claim->attempts,
            $this->timestamp($claim->updatedAt)
        ));

        return $updated === 1;
    }

    public function markDeliveryFailure(
        MailQueueRecord $claim,
        DateTimeImmutable $failedAt,
        ?DateTimeImmutable $retryAt,
        string $errorCode
    ): bool {
        $this->assertSafeErrorCode($errorCode);
        $status = $retryAt === null ? MailQueueStatus::FAILED : MailQueueStatus::QUEUED;
        $nextAttemptSql = $retryAt === null ? 'NULL' : '%s';
        $query = 'UPDATE ' . $this->tableName()
            . ' SET status = %s, next_attempt_at = ' . $nextAttemptSql
            . ', sent_at = NULL, error = %s, updated_at = %s'
            . ' WHERE id = %d AND status = %s AND attempts = %d AND updated_at = %s';
        $arguments = [
            $status->value,
        ];

        if ($retryAt !== null) {
            $arguments[] = $this->timestamp($retryAt);
        }

        array_push(
            $arguments,
            $errorCode,
            $this->timestamp($failedAt),
            $claim->id,
            MailQueueStatus::SENDING->value,
            $claim->attempts,
            $this->timestamp($claim->updatedAt)
        );

        return $this->execute($this->database->prepare($query, ...$arguments)) === 1;
    }

    public function markSuppressed(MailQueueRecord $queued, DateTimeImmutable $suppressedAt): bool
    {
        $updated = $this->execute($this->database->prepare(
            'UPDATE ' . $this->tableName()
            . ' SET status = %s, next_attempt_at = NULL, sent_at = NULL,'
            . ' error = %s, updated_at = %s'
            . ' WHERE id = %d AND status = %s AND attempts = %d AND updated_at = %s',
            MailQueueStatus::SUPPRESSED->value,
            'recipient_not_allowlisted',
            $this->timestamp($suppressedAt),
            $queued->id,
            MailQueueStatus::QUEUED->value,
            $queued->attempts,
            $this->timestamp($queued->updatedAt)
        ));

        return $updated === 1;
    }

    public function markInterrupted(
        MailQueueRecord $claim,
        DateTimeImmutable $recoveredAt,
        ?DateTimeImmutable $retryAt
    ): bool {
        $status = $retryAt === null ? MailQueueStatus::FAILED : MailQueueStatus::QUEUED;
        $nextAttemptSql = $retryAt === null ? 'NULL' : '%s';
        $query = 'UPDATE ' . $this->tableName()
            . ' SET status = %s, next_attempt_at = ' . $nextAttemptSql
            . ', sent_at = NULL, error = %s, updated_at = %s'
            . ' WHERE id = %d AND status = %s AND attempts = %d AND updated_at = %s';
        $arguments = [$status->value];

        if ($retryAt !== null) {
            $arguments[] = $this->timestamp($retryAt);
        }

        array_push(
            $arguments,
            self::INTERRUPTED_ERROR_CODE,
            $this->timestamp($recoveredAt),
            $claim->id,
            MailQueueStatus::SENDING->value,
            $claim->attempts,
            $this->timestamp($claim->updatedAt)
        );

        return $this->execute($this->database->prepare($query, ...$arguments)) === 1;
    }

    public function stats(DateTimeImmutable $now, int $windowSeconds): MailQueueStats
    {
        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('The mail statistics window must be positive.');
        }

        $pending = $this->fetchRow(
            'SELECT COUNT(*) AS pending_count, MIN(created_at) AS oldest_created_at'
            . ' FROM ' . $this->tableName()
            . ' WHERE status IN (\'' . MailQueueStatus::QUEUED->value . '\', \''
            . MailQueueStatus::SENDING->value . '\')'
        );
        $pendingCount = $this->integerValue($pending['pending_count'] ?? null, 'pending count');
        $oldestAge = null;

        if ($pendingCount > 0) {
            $oldestCreatedAt = $pending['oldest_created_at'] ?? null;

            if (! is_string($oldestCreatedAt) || $oldestCreatedAt === '') {
                throw new RuntimeException('The mail queue oldest pending timestamp is invalid.');
            }

            $oldestAge = max(
                0,
                $now->getTimestamp() - $this->parseTimestamp($oldestCreatedAt)->getTimestamp()
            );
        }

        $cutoff = $this->timestamp($now->modify('-' . $windowSeconds . ' seconds'));
        $sent = $this->fetchRow($this->database->prepare(
            'SELECT COUNT(*) AS sent_count FROM ' . $this->tableName()
            . ' WHERE status = %s AND sent_at > %s',
            MailQueueStatus::SENT->value,
            $cutoff
        ));
        $failed = $this->fetchRow($this->database->prepare(
            'SELECT COUNT(*) AS failed_count FROM ' . $this->tableName() . ' WHERE status = %s',
            MailQueueStatus::FAILED->value
        ));

        return new MailQueueStats(
            $pendingCount,
            $oldestAge,
            $this->integerValue($sent['sent_count'] ?? null, 'sent count'),
            $this->integerValue($failed['failed_count'] ?? null, 'failed count')
        );
    }

    /**
     * @return list<array{id: int, recipient: string, subject: string, body_preview: string, suppressed_at: string}>
     */
    public function findRecentSuppressed(): array
    {
        $this->database->clearLastError();
        $rows = $this->database->getResults($this->database->prepare(
            'SELECT id, recipient, subject,'
            . ' LEFT(CASE WHEN body_text = %s THEN body_html ELSE body_text END, %d) AS body_preview,'
            . ' updated_at FROM ' . $this->tableName()
            . ' WHERE status = %s ORDER BY updated_at DESC, id DESC LIMIT %d',
            '',
            self::SUPPRESSED_PREVIEW_BODY_LIMIT,
            MailQueueStatus::SUPPRESSED->value,
            self::SUPPRESSED_PREVIEW_LIMIT
        ));

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('A mail queue suppressed preview read failed.');
        }

        $messages = [];

        foreach ($rows as $row) {
            $recipient = $row['recipient'] ?? null;
            $subject = $row['subject'] ?? null;
            $bodyPreview = $row['body_preview'] ?? null;

            if (! is_string($recipient) || ! is_string($subject) || ! is_string($bodyPreview)) {
                throw new RuntimeException('A stored suppressed mail preview has invalid message fields.');
            }

            $messages[] = [
                'id' => $this->integerValue($row['id'] ?? null, 'row ID'),
                'recipient' => $recipient,
                'subject' => $subject,
                'body_preview' => $bodyPreview,
                'suppressed_at' => $this->parseTimestamp($row['updated_at'] ?? null)
                    ->format('Y-m-d H:i:s'),
            ];
        }

        return $messages;
    }

    public function pruneSentBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        if ($limit < 1 || $limit > MailQueueConfiguration::PRUNE_BATCH_SIZE) {
            throw new InvalidArgumentException('The mail queue prune batch is outside the supported limit.');
        }

        return $this->execute($this->database->prepare(
            'DELETE FROM ' . $this->tableName()
            . ' WHERE status = %s AND sent_at < %s'
            . ' ORDER BY sent_at ASC, id ASC LIMIT %d',
            MailQueueStatus::SENT->value,
            $this->timestamp($cutoff),
            $limit
        ));
    }

    private function insertEmail(
        OutboundEmail $email,
        MailQueueStatus $status,
        DateTimeImmutable $now
    ): MailQueueEnqueueResult {
        $groupKeySql = $email->groupKey === null ? 'NULL' : '%s';
        $errorSql = $status === MailQueueStatus::SUPPRESSED ? '%s' : 'NULL';
        $query = 'INSERT INTO ' . $this->tableName()
            . ' (recipient, subject, body_html, body_text, priority, group_key, status, attempts,'
            . ' next_attempt_at, sent_at, error, created_at, updated_at)'
            . ' VALUES (%s, %s, %s, %s, %d, ' . $groupKeySql . ', %s, 0, NULL, NULL, '
            . $errorSql . ', %s, %s)'
            . ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)';
        $arguments = [
            $email->recipient,
            $email->subject,
            $email->htmlBody,
            $email->textBody,
            $email->priority->value,
        ];

        if ($email->groupKey !== null) {
            $arguments[] = $email->groupKey;
        }

        $arguments[] = $status->value;

        if ($status === MailQueueStatus::SUPPRESSED) {
            $arguments[] = 'recipient_not_allowlisted';
        }

        array_push($arguments, $this->timestamp($now), $this->timestamp($now));

        $affectedRows = $this->execute($this->database->prepare($query, ...$arguments));
        $id = $this->database->insertId();

        if ($id < 1) {
            throw new RuntimeException('The queued email insert did not return a row ID.');
        }

        $row = $this->fetchRow($this->database->prepare(
            'SELECT * FROM ' . $this->tableName() . ' WHERE id = %d LIMIT 1',
            $id
        ));

        if ($row === null) {
            throw new RuntimeException('The queued email could not be read after insertion.');
        }

        $record = $this->mapRecord($row);

        if (! $record->email->hasSamePayload($email)) {
            throw new DomainException('Different composed content already uses this recipient group key.');
        }

        return new MailQueueEnqueueResult(
            $record->id,
            $record->status,
            $email->groupKey !== null && $affectedRows !== 1
        );
    }

    private function findByRecipientAndGroup(OutboundEmail $email): ?MailQueueRecord
    {
        $row = $this->fetchRow($this->database->prepare(
            'SELECT * FROM ' . $this->tableName()
            . ' WHERE recipient = %s AND group_key = %s ORDER BY id ASC LIMIT 1',
            $email->recipient,
            $email->groupKey
        ));

        return $row === null ? null : $this->mapRecord($row);
    }

    private function existingResult(MailQueueRecord $existing, OutboundEmail $email): MailQueueEnqueueResult
    {
        if (! $existing->email->hasSamePayload($email)) {
            throw new DomainException('Different composed content already uses this recipient group key.');
        }

        return new MailQueueEnqueueResult($existing->id, $existing->status, true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRecord(array $row): MailQueueRecord
    {
        $id = $this->integerValue($row['id'] ?? null, 'row ID');
        $attempts = $this->integerValue($row['attempts'] ?? null, 'attempt count');
        $priorityValue = $this->integerValue($row['priority'] ?? null, 'priority');
        $priority = MailPriority::tryFrom($priorityValue);
        $status = MailQueueStatus::tryFrom((string) ($row['status'] ?? ''));
        $groupKey = $row['group_key'] ?? null;

        if (
            $id < 1
            || $priority === null
            || $status === null
            || ($groupKey !== null && ! is_string($groupKey))
        ) {
            throw new RuntimeException('A stored mail queue row has invalid fields.');
        }

        try {
            $email = new OutboundEmail(
                (string) ($row['recipient'] ?? ''),
                (string) ($row['subject'] ?? ''),
                (string) ($row['body_html'] ?? ''),
                (string) ($row['body_text'] ?? ''),
                $priority,
                $groupKey
            );
        } catch (InvalidArgumentException) {
            throw new RuntimeException('A stored mail queue row has invalid message data.');
        }

        return new MailQueueRecord(
            $id,
            $email,
            $status,
            $attempts,
            $this->parseTimestamp($row['created_at'] ?? null),
            $this->parseTimestamp($row['updated_at'] ?? null),
            $this->nullableTimestamp($row['next_attempt_at'] ?? null),
            $this->nullableTimestamp($row['sent_at'] ?? null),
            null
        );
    }

    private function tableName(): string
    {
        return $this->database->prefix() . self::TABLE_SUFFIX;
    }

    private function capLockName(): string
    {
        return 'adct_pi_mq_cap_' . substr(hash('sha256', $this->tableName()), 0, 40);
    }

    private function groupLockName(OutboundEmail $email): string
    {
        return 'adct_pi_mq_group_' . substr(
            hash('sha256', $this->tableName() . '|' . $email->recipient . '|' . $email->groupKey),
            0,
            40
        );
    }

    private function acquireNamedLock(string $lockName): bool
    {
        $row = $this->fetchRow($this->database->prepare(
            'SELECT GET_LOCK(%s, %d) AS acquired',
            $lockName,
            self::LOCK_WAIT_SECONDS
        ));

        if ($row === null || ! array_key_exists('acquired', $row) || $row['acquired'] === null) {
            throw new RuntimeException('The mail queue database reservation lock failed.');
        }

        $acquired = $this->integerValue($row['acquired'], 'lock result');

        if ($acquired !== 0 && $acquired !== 1) {
            throw new RuntimeException('The mail queue database reservation lock returned an invalid result.');
        }

        return $acquired === 1;
    }

    private function releaseNamedLock(string $lockName): void
    {
        $row = $this->fetchRow($this->database->prepare(
            'SELECT RELEASE_LOCK(%s) AS released',
            $lockName
        ));

        if ($row === null || (int) ($row['released'] ?? 0) !== 1) {
            throw new RuntimeException('The mail queue database reservation lock could not be released.');
        }
    }

    private function fetchRow(string $query): ?array
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('A mail queue database read failed.');
        }

        return $row;
    }

    private function execute(string $query): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException('A mail queue database write failed.');
        }

        return $result;
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function parseTimestamp(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value) !== 1) {
            throw new RuntimeException('A stored mail queue timestamp is invalid.');
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new RuntimeException('A stored mail queue timestamp is invalid.');
        }
    }

    private function nullableTimestamp(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->parseTimestamp($value);
    }

    private function integerValue(mixed $value, string $field): int
    {
        if (
            (! is_int($value) && ! is_string($value))
            || preg_match('/\A\d+\z/D', (string) $value) !== 1
        ) {
            throw new RuntimeException('A stored mail queue ' . $field . ' is invalid.');
        }

        return (int) $value;
    }

    private function assertSafeErrorCode(string $errorCode): void
    {
        if (preg_match('/\A[a-z0-9_]{1,64}\z/D', $errorCode) !== 1) {
            throw new InvalidArgumentException('A mail queue error code must be a safe identifier.');
        }
    }
}
