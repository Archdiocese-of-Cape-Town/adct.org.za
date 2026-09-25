<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs;

use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\MailboxCheckpoint;
use ADCT\ParishIntake\Core\Jobs\AbstractJob;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\MailboxSettingsStoreInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RetentionCleanupJob extends AbstractJob
{
    private const DEFAULT_INTERVAL_SECONDS = 86400;
    private const BATCH_SIZE = 25;
    private const PROCESSED_SEARCH_WINDOW = 500;
    private const ACTION_TOKEN_RETENTION_DAYS = 30;
    private const AUDIT_LOG_RETENTION_MONTHS = 24;
    /** @var callable */
    private $settingsProvider;

    /** @var callable */
    private $passwordResolver;

    /** @var callable */
    private $mailboxFactory;

    public function __construct(
        private DatabaseConnectionInterface $database,
        private InboundMailStorageReaderInterface $storage,
        private MailboxSettingsStoreInterface $mailboxes,
        callable $settingsProvider,
        callable $passwordResolver,
        callable $mailboxFactory,
        private ClockInterface $clock,
        private int $batchSize = self::BATCH_SIZE
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('The retention cleanup batch size must be positive.');
        }

        parent::__construct('retention_cleanup', 'Retention cleanup', self::DEFAULT_INTERVAL_SECONDS);
        $this->settingsProvider = $settingsProvider;
        $this->passwordResolver = $passwordResolver;
        $this->mailboxFactory = $mailboxFactory;
    }

    public function isDue(DateTimeImmutable $now, JobState $state): bool
    {
        $settings = $this->settings();

        return $settings->configurationError() === null
            && $settings->hasAnyCleanupEnabled()
            && parent::isDue($now, $state);
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        $settings = $this->settings();

        if ($settings->configurationError() !== null || ! $settings->hasAnyCleanupEnabled()) {
            return null;
        }

        $state = $this->decodeCheckpoint($checkpoint);
        $phase = $state['phase'];

        if ($phase === 'done') {
            return null;
        }

        if ($phase === 'tokens') {
            if (! $settings->actionTokenCleanupEnabled()) {
                return $this->advancePhase('audit', $settings);
            }

            $result = $this->pruneActionTokens($state['tokens_last_id'], $settings);

            if ($result['more']) {
                return JobStepResult::continueAt($this->encodeCheckpoint('tokens', $result['last_id']));
            }

            return $this->advancePhase('audit', $settings);
        }

        if ($phase === 'audit') {
            if (! $settings->auditCleanupEnabled()) {
                return $this->advancePhase('raw', $settings);
            }

            $result = $this->pruneAuditLog($state['audit_last_id'], $settings);

            if ($result['more']) {
                return JobStepResult::continueAt($this->encodeCheckpoint('audit', $result['last_id']));
            }

            return $this->advancePhase('raw', $settings);
        }

        if ($phase === 'raw') {
            if (! $settings->rawCleanupEnabled()) {
                return $this->advancePhase('processed', $settings);
            }

            $result = $this->pruneRawMessages($state['raw_last_id'], $settings);

            if ($result['more']) {
                return JobStepResult::continueAt($this->encodeCheckpoint('raw', $result['last_id']));
            }

            return $this->advancePhase('processed', $settings);
        }

        if ($phase === 'processed') {
            if (! $settings->processedCleanupEnabled()) {
                return JobStepResult::completeAt($this->encodeCheckpoint('done', null));
            }

            $result = $this->pruneProcessedFolder($state['processed'], $settings);

            if ($result['more']) {
                return JobStepResult::continueAt($this->encodeProcessedCheckpoint($result['cursor']));
            }

            if ($result['next_cursor']['source_id'] > 0) {
                return JobStepResult::continueAt($this->encodeProcessedCheckpoint($result['next_cursor']));
            }

            return JobStepResult::completeAt($this->encodeCheckpoint('done', null));
        }

        throw new RuntimeException('The retention cleanup checkpoint is invalid.');
    }

    /**
     * @return array{phase: string, tokens_last_id: int, audit_last_id: int, raw_last_id: int, processed: array{source_id: int, last_uid: int}}
     */
    private function decodeCheckpoint(?string $checkpoint): array
    {
        if ($checkpoint === null || trim($checkpoint) === '') {
            return [
                'phase' => 'tokens',
                'tokens_last_id' => 0,
                'audit_last_id' => 0,
                'raw_last_id' => 0,
                'processed' => ['source_id' => 0, 'last_uid' => 0],
            ];
        }

        try {
            $value = json_decode($checkpoint, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $failure) {
            throw new RuntimeException('The retention cleanup checkpoint could not be decoded.', 0, $failure);
        }

        if (! is_array($value)) {
            throw new RuntimeException('The retention cleanup checkpoint is invalid.');
        }

        $phase = $this->checkpointPhase($value['phase'] ?? null);

        return [
            'phase' => $phase,
            'tokens_last_id' => $this->positiveCheckpointId($value['tokens_last_id'] ?? 0),
            'audit_last_id' => $this->positiveCheckpointId($value['audit_last_id'] ?? 0),
            'raw_last_id' => $this->positiveCheckpointId($value['raw_last_id'] ?? 0),
            'processed' => [
                'source_id' => $this->positiveCheckpointId($value['processed']['source_id'] ?? 0),
                'last_uid' => $this->positiveCheckpointId($value['processed']['last_uid'] ?? 0),
            ],
        ];
    }

    private function advancePhase(string $nextPhase, RetentionSettings $settings): JobStepResult
    {
        $phase = $this->nextEnabledPhase($nextPhase, $settings);

        if ($phase === 'done') {
            return JobStepResult::completeAt($this->encodeCheckpoint('done', null));
        }

        return JobStepResult::continueAt($this->encodeCheckpoint($phase, null));
    }

    /**
     * @return array{last_id: int, more: bool}
     */
    private function pruneActionTokens(int $lastId, RetentionSettings $settings): array
    {
        $cutoff = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify('-' . self::ACTION_TOKEN_RETENTION_DAYS . ' days')
            ->format('Y-m-d H:i:s');

        $rows = $this->fetchRows($this->database->prepare(
            'SELECT id FROM ' . $this->tableName('adct_pi_action_tokens')
            . ' WHERE expires_at <= %s AND id > %d ORDER BY id ASC LIMIT %d',
            $cutoff,
            $lastId,
            $this->batchSize
        ));

        $ids = $this->idsFromRows($rows);

        if ($ids === []) {
            return ['last_id' => $lastId, 'more' => false];
        }

        $this->deleteIds('adct_pi_action_tokens', $ids);

        return [
            'last_id' => max($ids),
            'more' => count($ids) === $this->batchSize,
        ];
    }

    /**
     * @return array{last_id: int, more: bool}
     */
    private function pruneAuditLog(int $lastId, RetentionSettings $settings): array
    {
        $cutoff = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify('-' . self::AUDIT_LOG_RETENTION_MONTHS . ' months')
            ->format('Y-m-d H:i:s');

        $rows = $this->fetchRows($this->database->prepare(
            'SELECT id FROM ' . $this->tableName('adct_pi_audit_log')
            . ' WHERE created_at < %s AND id > %d ORDER BY id ASC LIMIT %d',
            $cutoff,
            $lastId,
            $this->batchSize
        ));

        $ids = $this->idsFromRows($rows);

        if ($ids === []) {
            return ['last_id' => $lastId, 'more' => false];
        }

        $this->deleteIds('adct_pi_audit_log', $ids);

        return [
            'last_id' => max($ids),
            'more' => count($ids) === $this->batchSize,
        ];
    }

    /**
     * @return array{last_id: int, more: bool}
     */
    private function pruneRawMessages(int $lastId, RetentionSettings $settings): array
    {
        $clockNow = $this->clock->now();
        $now = $this->utc($clockNow);
        $cutoff = $this->utc($clockNow->modify('-' . $settings->rawRetentionDays() . ' days'));
        $messagesTable = $this->tableName('adct_pi_inbound_messages');
        $candidatesTable = $this->tableName('adct_pi_event_candidates');
        $query = $this->database->prepare(
            'SELECT m.id, m.raw_path FROM ' . $messagesTable . ' m'
            . ' WHERE m.retention_until <= %s'
            . ' AND m.received_at <= %s'
            . ' AND m.raw_path IS NOT NULL'
            . ' AND m.raw_path REGEXP %s'
            . ' AND m.status IN (%s, %s, %s)'
            . ' AND m.id > %d'
            . ' AND NOT EXISTS ('
            . ' SELECT 1 FROM ' . $candidatesTable . ' c'
            . ' WHERE c.message_id = m.id'
            . ' AND c.status IN (%s, %s, %s, %s)'
            . ' )'
            . ' ORDER BY m.id ASC LIMIT %d',
            $now,
            $cutoff,
            '^[a-f0-9]{64}\\.eml$',
            'parsed',
            'ignored',
            'skipped',
            $lastId,
            'draft',
            'awaiting_submitter',
            'awaiting_approval',
            'approved',
            $this->batchSize
        );
        $rows = $this->fetchRows($query);

        if ($rows === []) {
            return ['last_id' => $lastId, 'more' => false];
        }

        $messageIds = [];

        foreach ($rows as $row) {
            $messageId = $this->positiveCheckpointId($row['id'] ?? null);
            $rawPath = is_string($row['raw_path'] ?? null) ? $row['raw_path'] : '';

            if ($messageId < 1 || $rawPath === '') {
                continue;
            }

            $messageIds[] = $messageId;
            $this->storage->delete($rawPath);
            $this->deleteAttachmentFilesForMessage($messageId);
            $updated = $this->database->query($this->database->prepare(
                'UPDATE ' . $messagesTable . ' SET raw_path = NULL, updated_at = %s WHERE id = %d AND raw_path IS NOT NULL',
                $this->utc($this->clock->now()),
                $messageId
            ));

            if ($updated !== 1 || $this->database->lastError() !== '') {
                throw new RuntimeException('Retention cleanup could not clear the raw message pointer.');
            }
        }

        if ($messageIds === []) {
            return ['last_id' => $lastId, 'more' => false];
        }

        return [
            'last_id' => max($messageIds),
            'more' => count($rows) === $this->batchSize,
        ];
    }

    /**
     * @return array{cursor: array{source_id: int, last_uid: int}, next_cursor: array{source_id: int, last_uid: int}, more: bool}
     */
    private function pruneProcessedFolder(array $cursor, RetentionSettings $settings): array
    {
        $mailboxes = $this->mailboxes->findActiveMailboxes();
        $mailboxes = array_values(array_filter(
            $mailboxes,
            static fn ($mailbox): bool => $mailbox instanceof \ADCT\ParishIntake\Core\Ingestion\MailboxSettings
        ));

        usort(
            $mailboxes,
            static fn ($left, $right): int => $left->sourceId <=> $right->sourceId
        );

        $sourceId = $cursor['source_id'] ?? 0;
        $lastUid = $cursor['last_uid'] ?? 0;
        $mailboxIndex = $this->mailboxIndexForSourceId($mailboxes, $sourceId);

        if ($mailboxIndex >= count($mailboxes)) {
            return [
                'cursor' => [
                    'source_id' => 0,
                    'last_uid' => 0,
                ],
                'next_cursor' => [
                    'source_id' => 0,
                    'last_uid' => 0,
                ],
                'more' => false,
            ];
        }

        $mailboxSettings = $mailboxes[$mailboxIndex];
        $password = ($this->passwordResolver)($mailboxSettings);

        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException('No mailbox password is configured.');
        }

        $mailbox = ($this->mailboxFactory)($mailboxSettings, $password);

        if (! $mailbox instanceof MailboxInterface) {
            throw new RuntimeException('The mailbox factory returned an invalid adapter.');
        }

        try {
            $cutoff = $this->clock->now()->modify('-' . $settings->processedRetentionDays() . ' days');
            $windowStart = max(0, $lastUid);
            $windowEnd = min(MailboxCheckpoint::MAX_UID, $windowStart + self::PROCESSED_SEARCH_WINDOW);

            if ($windowEnd <= $windowStart) {
                return [
                    'cursor' => [
                        'source_id' => $mailboxSettings->sourceId,
                        'last_uid' => $windowStart,
                    ],
                    'next_cursor' => [
                        'source_id' => $this->nextMailboxSourceId($mailboxes, $mailboxSettings->sourceId),
                        'last_uid' => 0,
                    ],
                    'more' => false,
                ];
            }

            $uids = $mailbox->searchFolder(
                new MailboxSearchCriteria(
                    before: $cutoff,
                    afterUid: $windowStart > 0 ? $windowStart : null,
                    beforeUid: $windowEnd,
                    folder: $mailboxSettings->processedFolder
                ),
                $mailboxSettings->processedFolder
            );

            if ($uids === []) {
                return [
                    'cursor' => [
                        'source_id' => $mailboxSettings->sourceId,
                        'last_uid' => $windowEnd,
                    ],
                    'next_cursor' => [
                        'source_id' => $this->nextMailboxSourceId($mailboxes, $mailboxSettings->sourceId),
                        'last_uid' => 0,
                    ],
                    'more' => false,
                ];
            }

            sort($uids, SORT_NUMERIC);
            $uids = array_slice($uids, 0, $this->batchSize);
            $deletedUid = $windowStart;

            foreach ($uids as $uid) {
                $mailbox->delete($uid, $mailboxSettings->processedFolder);
                $deletedUid = $uid;
            }

            $processedAllInWindow = count($uids) < $this->batchSize;

            return [
                'cursor' => [
                    'source_id' => $mailboxSettings->sourceId,
                    'last_uid' => $processedAllInWindow ? $windowEnd : $deletedUid,
                ],
                'next_cursor' => [
                    'source_id' => $this->nextMailboxSourceId($mailboxes, $mailboxSettings->sourceId),
                    'last_uid' => 0,
                ],
                'more' => ! $processedAllInWindow || $windowEnd < MailboxCheckpoint::MAX_UID,
            ];
        } finally {
            $mailbox->close();
        }
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<int>
     */
    private function idsFromRows(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $this->positiveCheckpointId($row['id'] ?? null);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     */
    private function deleteIds(string $tableSuffix, array $ids): void
    {
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $query = $this->database->prepare(
            'DELETE FROM ' . $this->tableName($tableSuffix) . ' WHERE id IN (' . $placeholders . ')',
            ...$ids
        );
        $result = $this->database->query($query);

        if ($result === false) {
            throw new RuntimeException('Retention cleanup could not remove expired rows.');
        }
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function fetchRows(string $query): array
    {
        $this->database->clearLastError();
        $rows = $this->database->getResults($query);

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('Retention cleanup could not read from the database.');
        }

        return $rows;
    }

    private function deleteAttachmentFilesForMessage(int $messageId): void
    {
        $attachmentsTable = $this->tableName('adct_pi_attachments');
        $rows = $this->fetchRows($this->database->prepare(
            'SELECT storage_path FROM ' . $attachmentsTable . ' WHERE message_id = %d'
            . ' AND storage_path REGEXP %s ORDER BY id ASC',
            $messageId,
            '^[a-f0-9]{64}\\.(?:pdf|jpg|png|webp|heic|heif)$'
        ));

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['storage_path'] ?? null)) {
                continue;
            }

            $this->storage->delete($row['storage_path']);
        }
    }

    private function checkpointPhase(mixed $phase): string
    {
        if (! is_string($phase) || ! in_array($phase, ['tokens', 'audit', 'raw', 'processed', 'done'], true)) {
            throw new RuntimeException('The retention cleanup checkpoint phase is invalid.');
        }

        return $phase;
    }

    private function positiveCheckpointId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($id) ? $id : 0;
    }

    private function nextEnabledPhase(string $phase, RetentionSettings $settings): string
    {
        if ($phase === 'tokens') {
            return $this->nextEnabledPhase('audit', $settings);
        }

        if ($phase === 'audit') {
            if ($settings->auditCleanupEnabled()) {
                return 'audit';
            }

            return $settings->rawCleanupEnabled()
                ? 'raw'
                : ($settings->processedCleanupEnabled() ? 'processed' : 'done');
        }

        if ($phase === 'raw') {
            return $settings->processedCleanupEnabled() ? 'processed' : 'done';
        }

        return 'done';
    }

    /**
     * @param list<\ADCT\ParishIntake\Core\Ingestion\MailboxSettings> $mailboxes
     */
    private function mailboxIndexForSourceId(array $mailboxes, int $sourceId): int
    {
        if ($sourceId <= 0) {
            return 0;
        }

        foreach ($mailboxes as $index => $mailboxSettings) {
            if ($mailboxSettings->sourceId >= $sourceId) {
                return $index;
            }
        }

        return count($mailboxes);
    }

    /**
     * @param list<\ADCT\ParishIntake\Core\Ingestion\MailboxSettings> $mailboxes
     */
    private function nextMailboxSourceId(array $mailboxes, int $sourceId): int
    {
        foreach ($mailboxes as $mailboxSettings) {
            if ($mailboxSettings->sourceId > $sourceId) {
                return $mailboxSettings->sourceId;
            }
        }

        return 0;
    }

    private function encodeCheckpoint(string $phase, ?int $cursor): string
    {
        $state = [
            'phase' => $phase,
            'tokens_last_id' => 0,
            'audit_last_id' => 0,
            'raw_last_id' => 0,
            'processed' => [
                'source_id' => 0,
                'last_uid' => 0,
            ],
        ];

        if ($phase === 'tokens') {
            $state['tokens_last_id'] = max(0, (int) $cursor);
        } elseif ($phase === 'audit') {
            $state['audit_last_id'] = max(0, (int) $cursor);
        } elseif ($phase === 'raw') {
            $state['raw_last_id'] = max(0, (int) $cursor);
        }

        return json_encode($state, JSON_THROW_ON_ERROR);
    }

    private function encodeProcessedCheckpoint(array $cursor): string
    {
        $state = [
            'phase' => 'processed',
            'tokens_last_id' => 0,
            'audit_last_id' => 0,
            'raw_last_id' => 0,
            'processed' => [
                'source_id' => max(0, (int) ($cursor['source_id'] ?? 0)),
                'last_uid' => max(0, (int) ($cursor['last_uid'] ?? 0)),
            ],
        ];

        return json_encode($state, JSON_THROW_ON_ERROR);
    }

    private function settings(): RetentionSettings
    {
        $settings = ($this->settingsProvider)();

        if (! $settings instanceof RetentionSettings) {
            throw new RuntimeException('The retention settings provider returned an invalid value.');
        }

        return $settings;
    }

    private function tableName(string $suffix): string
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The WordPress database prefix is invalid.');
        }

        if (preg_match('/\A[a-z0-9_]+\z/D', $suffix) !== 1) {
            throw new InvalidArgumentException('The requested database table suffix is invalid.');
        }

        return '`' . $prefix . $suffix . '`';
    }

    private function utc(DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
