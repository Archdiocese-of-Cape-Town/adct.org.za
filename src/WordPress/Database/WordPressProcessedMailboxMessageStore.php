<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Ingestion\MailboxCheckpoint;
use ADCT\ParishIntake\Core\Ingestion\MailboxMoveReceipt;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ports\ProcessedMailboxMessageStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class WordPressProcessedMailboxMessageStore implements ProcessedMailboxMessageStoreInterface
{
    private const MAX_BATCH_SIZE = 100;

    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function recordMoved(
        MailboxSettings $settings,
        MailboxMoveReceipt $receipt,
        DateTimeImmutable $internalDate,
        DateTimeImmutable $recordedAt
    ): void {
        $this->assertMailboxSettings($settings);
        $table = $this->tableName();
        $timestamp = self::formatUtc($recordedAt);
        $query = $this->database->prepare(
            "INSERT INTO {$table} "
            . '(source_id, mailbox_identity, processed_folder, uid_validity, uid, internal_date, created_at, updated_at) '
            . 'VALUES (%d, %s, %s, %d, %d, %s, %s, %s) '
            . 'ON DUPLICATE KEY UPDATE internal_date = VALUES(internal_date), updated_at = VALUES(updated_at)',
            $settings->sourceId,
            $settings->processedFolderIdentity(),
            $settings->processedFolder,
            $receipt->destinationUidValidity,
            $receipt->destinationUid,
            self::formatUtc($internalDate),
            $timestamp,
            $timestamp
        );

        $this->executeWrite($query, 'Processed mailbox ownership could not be recorded.');
    }

    public function findExpired(
        MailboxSettings $settings,
        int $uidValidity,
        DateTimeImmutable $cutoff,
        int $limit
    ): array {
        $this->assertMailboxSettings($settings);
        $this->assertUid($uidValidity);

        if ($limit < 1) {
            throw new InvalidArgumentException('The processed-mail lookup limit must be positive.');
        }

        $limit = min(self::MAX_BATCH_SIZE, $limit);
        $table = $this->tableName();
        $query = $this->database->prepare(
            "SELECT uid FROM {$table} "
            . 'WHERE source_id = %d AND mailbox_identity = %s AND processed_folder = %s '
            . 'AND uid_validity = %d AND internal_date <= %s '
            . 'ORDER BY uid ASC LIMIT %d',
            $settings->sourceId,
            $settings->processedFolderIdentity(),
            $settings->processedFolder,
            $uidValidity,
            self::formatUtc($cutoff),
            $limit
        );

        $this->database->clearLastError();
        $rows = $this->database->getResults($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('Expired processed-mail ownership could not be read.');
        }

        $uids = [];

        foreach ($rows as $row) {
            $uid = filter_var(
                $row['uid'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => MailboxCheckpoint::MAX_UID]]
            );

            if (! is_int($uid)) {
                throw new RuntimeException('The processed-mail ownership store returned an invalid UID.');
            }

            $uids[] = $uid;
        }

        return $uids;
    }

    public function discardStale(MailboxSettings $settings, int $currentUidValidity): void
    {
        $this->assertMailboxSettings($settings);
        $this->assertUid($currentUidValidity);
        $table = $this->tableName();
        $query = $this->database->prepare(
            "DELETE FROM {$table} WHERE source_id = %d "
            . 'AND (mailbox_identity <> %s OR processed_folder <> %s OR uid_validity <> %d)',
            $settings->sourceId,
            $settings->processedFolderIdentity(),
            $settings->processedFolder,
            $currentUidValidity
        );

        $this->executeWrite($query, 'Stale processed-mail ownership could not be discarded.');
    }

    public function deleteOwned(MailboxSettings $settings, int $uidValidity, int $uid): void
    {
        $this->assertMailboxSettings($settings);
        $this->assertUid($uidValidity);
        $this->assertUid($uid);
        $table = $this->tableName();
        $query = $this->database->prepare(
            "DELETE FROM {$table} WHERE source_id = %d AND mailbox_identity = %s "
            . 'AND processed_folder = %s AND uid_validity = %d AND uid = %d',
            $settings->sourceId,
            $settings->processedFolderIdentity(),
            $settings->processedFolder,
            $uidValidity,
            $uid
        );

        $this->executeWrite($query, 'Processed-mail ownership could not be removed.');
    }

    private function assertMailboxSettings(MailboxSettings $settings): void
    {
        if ($settings->sourceId < 1 || $settings->processedFolder === '') {
            throw new InvalidArgumentException('Processed-mail ownership needs a saved source and folder.');
        }
    }

    private function assertUid(int $uid): void
    {
        if ($uid < 1 || $uid > MailboxCheckpoint::MAX_UID) {
            throw new InvalidArgumentException('Processed-mail ownership needs a valid UID value.');
        }
    }

    private function executeWrite(string $query, string $failureMessage): void
    {
        $this->database->clearLastError();
        $result = $this->database->query($query);

        if ($result === false || $this->database->lastError() !== '') {
            throw new RuntimeException($failureMessage);
        }
    }

    private function tableName(): string
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The WordPress database prefix is invalid.');
        }

        return '`' . $prefix . 'adct_pi_processed_mail_ownership`';
    }

    private static function formatUtc(DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
