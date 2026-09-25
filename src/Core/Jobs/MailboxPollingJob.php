<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ingestion\InboundAttachmentRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ingestion\InspectedInboundMail;
use ADCT\ParishIntake\Core\Ingestion\MailboxCheckpoint;
use ADCT\ParishIntake\Core\Ingestion\MailboxPollingCursor;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ingestion\MessageContentHasher;
use ADCT\ParishIntake\Core\Ingestion\MessageIdentifier;
use ADCT\ParishIntake\Core\Ingestion\PreparedEmailAttachment;
use ADCT\ParishIntake\Core\Ingestion\RawEmailAttachment;
use ADCT\ParishIntake\Core\Ingestion\RawMessageInspector;
use ADCT\ParishIntake\Core\Ingestion\Imap\MessageTooLarge;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageInterface;
use ADCT\ParishIntake\Core\Ports\InboundMessageStoreInterface;
use ADCT\ParishIntake\Core\Ports\MailboxCheckpointStoreInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\MailboxSettingsStoreInterface;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class MailboxPollingJob extends AbstractJob implements JobRunLifecycleInterface
{
    public const DEFAULT_INTERVAL_SECONDS = 600;
    public const TOO_LARGE_FOLDER = 'Too large';
    public const FAILURE_RETRY_BASE_MINUTES = 10;
    public const FAILURE_RETRY_MAX_MINUTES = 360;

    /** @var Closure(MailboxSettings): string */
    private Closure $passwordResolver;

    /** @var Closure(MailboxSettings, string): MailboxInterface */
    private Closure $mailboxFactory;

    /** @var array<string, list<int>> */
    private array $searchedUids = [];

    /** @var array<string, true> */
    private array $attemptedUids = [];

    /** @var array<string, list<int>> */
    private array $failedUids = [];

    /** @var array<int, true> */
    private array $sourcesWithFailures = [];

    /** @var array<int, true> */
    private array $dueSources = [];

    /** @var array<int, true> */
    private array $notDueSources = [];

    /** @var array<int, true> */
    private array $stoppedSources = [];

    /** @var array<int, MailboxCheckpoint|null> */
    private array $runCheckpoints = [];

    /**
     * @param callable(MailboxSettings): string $passwordResolver
     * @param callable(MailboxSettings, string): MailboxInterface $mailboxFactory
     */
    public function __construct(
        private MailboxSettingsStoreInterface $mailboxes,
        private MailboxCheckpointStoreInterface $checkpoints,
        private InboundMessageStoreInterface $messages,
        private InboundMailStorageInterface $storage,
        private SourceHealthRecorder $healthRecorder,
        private RawMessageInspector $inspector,
        private AttachmentStoragePolicy $attachmentPolicy,
        private MessageContentHasher $contentHasher,
        private ClockInterface $clock,
        callable $passwordResolver,
        callable $mailboxFactory
    ) {
        parent::__construct('poll_mailboxes', 'Poll intake mailboxes', self::DEFAULT_INTERVAL_SECONDS);
        $this->passwordResolver = Closure::fromCallable($passwordResolver);
        $this->mailboxFactory = Closure::fromCallable($mailboxFactory);
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        $mailboxes = $this->mailboxes->findActiveMailboxes();

        if ($mailboxes === []) {
            return null;
        }

        $mailboxesBySource = [];

        foreach ($mailboxes as $settings) {
            if (! $settings instanceof MailboxSettings || $settings->sourceId < 1) {
                throw new RuntimeException('The active mailbox list contains invalid settings.');
            }

            if (isset($mailboxesBySource[$settings->sourceId])) {
                throw new RuntimeException('The active mailbox list contains a duplicate source.');
            }

            $mailboxesBySource[$settings->sourceId] = $settings;
        }

        $sourceIds = array_keys($mailboxesBySource);
        sort($sourceIds, SORT_NUMERIC);
        $cursor = MailboxPollingCursor::fromJson($checkpoint);
        $sourceId = $cursor->nextSourceId($sourceIds);

        if ($sourceId === null) {
            return JobStepResult::completeAt($cursor->finishSweep()->toJson());
        }

        $didWork = $this->pollMailbox($mailboxesBySource[$sourceId]);

        if ($didWork) {
            return JobStepResult::continueAt($cursor->afterWork($sourceId)->toJson());
        }

        $cursor = $cursor->afterIdleCheck($sourceId);

        if ($cursor->hasVisitedAll($sourceIds)) {
            return JobStepResult::completeAt($cursor->finishSweep()->toJson());
        }

        return JobStepResult::continueAt($cursor->toJson());
    }

    public function beginRun(): void
    {
        $this->searchedUids = [];
        $this->attemptedUids = [];
        $this->failedUids = [];
        $this->sourcesWithFailures = [];
        $this->dueSources = [];
        $this->notDueSources = [];
        $this->stoppedSources = [];
        $this->runCheckpoints = [];
    }

    private function pollMailbox(MailboxSettings $settings): bool
    {
        if (
            isset($this->stoppedSources[$settings->sourceId])
            || isset($this->notDueSources[$settings->sourceId])
        ) {
            return false;
        }

        if (! isset($this->dueSources[$settings->sourceId])) {
            try {
                $mailboxIsDue = $this->isMailboxDue($settings);
            } catch (Throwable $failure) {
                $this->stoppedSources[$settings->sourceId] = true;
                $this->recordFailure($settings->sourceId, null, $failure);

                return false;
            }

            if (! $mailboxIsDue) {
                $this->notDueSources[$settings->sourceId] = true;

                return false;
            }

            $this->dueSources[$settings->sourceId] = true;
        }

        $mailbox = null;
        $failure = null;
        $messageFailure = null;
        $messageUid = null;
        $didWork = false;
        $checkedWithoutWork = false;
        $itemAt = null;
        $uidValidity = null;

        try {
            $password = ($this->passwordResolver)($settings);

            if (! is_string($password) || trim($password) === '') {
                throw new RuntimeException('No mailbox password is configured.');
            }

            $mailbox = ($this->mailboxFactory)($settings, $password);

            if (! $mailbox instanceof MailboxInterface) {
                throw new RuntimeException('The mailbox factory returned an invalid adapter.');
            }

            $uidValidity = $mailbox->uidValidity();
            $savedCheckpoint = $this->findCheckpointForRun($settings->sourceId);
            $mailboxCheckpoint = $savedCheckpoint === null
                ? new MailboxCheckpoint($uidValidity, 0)
                : $savedCheckpoint->forUidValidity($uidValidity);

            if (
                $savedCheckpoint === null
                || $savedCheckpoint->uidValidity !== $mailboxCheckpoint->uidValidity
            ) {
                $this->saveCheckpoint($settings->sourceId, $mailboxCheckpoint);
            }

            if ($mailboxCheckpoint->scanComplete) {
                $mailboxCheckpoint = $mailboxCheckpoint->beginScan();
                $this->saveCheckpoint($settings->sourceId, $mailboxCheckpoint);
            }

            $uid = $this->nextUnattemptedUid($settings, $mailbox, $mailboxCheckpoint);

            if ($uid === null) {
                $checkedWithoutWork = true;

                if (($this->failedUids[$this->mailboxKey($settings->sourceId, $mailboxCheckpoint->uidValidity)] ?? []) === []) {
                    $this->saveCheckpoint($settings->sourceId, $mailboxCheckpoint->completeScan());
                }

                $this->stoppedSources[$settings->sourceId] = true;
            } else {
                $messageUid = $uid;
                $didWork = true;

                try {
                    $itemAt = $this->processMessage(
                        $settings,
                        $mailbox,
                        $mailboxCheckpoint,
                        $uidValidity,
                        $uid
                    );
                } catch (Throwable $caughtFailure) {
                    $messageFailure = $caughtFailure;
                    $failure = $caughtFailure;
                }
            }
        } catch (Throwable $caughtFailure) {
            $failure ??= $caughtFailure;
        } finally {
            if ($mailbox instanceof MailboxInterface) {
                try {
                    $mailbox->close();
                } catch (Throwable $closeFailure) {
                    $failure ??= $closeFailure;
                }
            }
        }

        if ($failure !== null) {
            if ($messageFailure !== null && $messageUid !== null && $uidValidity !== null) {
                $this->rememberFailedUid($settings->sourceId, $uidValidity, $messageUid);
            } elseif ($messageUid === null) {
                $this->stoppedSources[$settings->sourceId] = true;
            }

            $this->recordFailure($settings->sourceId, $messageUid, $failure);

            return $didWork;
        }

        if ($didWork || $checkedWithoutWork) {
            $this->recordSuccessfulPoll($settings->sourceId, $itemAt);
        }

        return $didWork;
    }

    private function isMailboxDue(MailboxSettings $settings): bool
    {
        if ($settings->lastCheckedAt === null) {
            return true;
        }

        $lastCheckedAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $settings->lastCheckedAt,
            new DateTimeZone('UTC')
        );
        $dateErrors = DateTimeImmutable::getLastErrors();

        if (
            $lastCheckedAt === false
            || (
                $dateErrors !== false
                && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)
            )
            || $lastCheckedAt->format('Y-m-d H:i:s') !== $settings->lastCheckedAt
        ) {
            throw new RuntimeException('The source last-checked timestamp is invalid.');
        }

        if ($settings->consecutiveFailures === 0) {
            $checkpoint = $this->findCheckpointForRun($settings->sourceId);

            if ($checkpoint !== null && ! $checkpoint->scanComplete) {
                return true;
            }

            $delayMinutes = $settings->pollIntervalMinutes;
        } else {
            $delayMinutes = $this->retryDelayMinutes($settings->consecutiveFailures);
        }

        $elapsedSeconds = $this->clock->now()->getTimestamp() - $lastCheckedAt->getTimestamp();

        return $elapsedSeconds >= $delayMinutes * 60;
    }

    private function retryDelayMinutes(int $consecutiveFailures): int
    {
        $delayMinutes = self::FAILURE_RETRY_BASE_MINUTES;

        for (
            $failure = 1;
            $failure < $consecutiveFailures && $delayMinutes < self::FAILURE_RETRY_MAX_MINUTES;
            ++$failure
        ) {
            $delayMinutes = min(self::FAILURE_RETRY_MAX_MINUTES, $delayMinutes * 2);
        }

        return $delayMinutes;
    }

    private function nextUnattemptedUid(
        MailboxSettings $settings,
        MailboxInterface $mailbox,
        MailboxCheckpoint $checkpoint
    ): ?int {
        $key = $this->mailboxKey($settings->sourceId, $checkpoint->uidValidity);

        if (! array_key_exists($key, $this->searchedUids)) {
            $uids = $mailbox->search(MailboxSearchCriteria::afterUid($checkpoint->lastUid));
            sort($uids, SORT_NUMERIC);
            $this->searchedUids[$key] = array_values(array_unique($uids));
        }

        foreach ($this->searchedUids[$key] as $uid) {
            if (! is_int($uid) || $uid < 1 || $uid > MailboxCheckpoint::MAX_UID) {
                throw new RuntimeException('The mailbox returned an invalid message UID.');
            }

            if (! isset($this->attemptedUids[$this->uidKey($settings->sourceId, $checkpoint->uidValidity, $uid)])) {
                return $uid;
            }
        }

        return null;
    }

    private function processMessage(
        MailboxSettings $settings,
        MailboxInterface $mailbox,
        MailboxCheckpoint $checkpoint,
        int $uidValidity,
        int $uid
    ): ?DateTimeImmutable {
        $uidKey = $this->uidKey($settings->sourceId, $uidValidity, $uid);
        $this->attemptedUids[$uidKey] = true;

        try {
            $rawMessage = $mailbox->fetch($uid);
        } catch (MessageTooLarge $tooLarge) {
            $record = new InboundMessageRecord(
                $settings->sourceId,
                MessageIdentifier::fromMessageId(null, $uidValidity, $uid),
                null,
                null,
                null,
                'Oversized message',
                $this->clock->now(),
                null,
                [],
                InboundMessageRecord::STATUS_SKIPPED,
                sprintf(
                    'Message size %d bytes exceeds the configured limit of %d bytes.',
                    $tooLarge->sizeBytes,
                    $tooLarge->maxBytes
                )
            );
            $this->messages->store($record, $this->timestamp());

            if ($tooLarge->connectionUsable) {
                $mailbox->ensureFolder(self::TOO_LARGE_FOLDER);
                $mailbox->move($uid, self::TOO_LARGE_FOLDER);
            } else {
                $this->moveOversizedOnFreshConnection($settings, $uidValidity, $uid);
            }

            $this->advanceCheckpoint($settings->sourceId, $checkpoint, $uid);

            return $this->clock->now();
        }

        $inspected = $this->inspector->inspect($rawMessage->raw, $rawMessage->internalDate);
        $preparedAttachments = array_map(
            fn (RawEmailAttachment $attachment): PreparedEmailAttachment =>
                $this->attachmentPolicy->prepare($attachment),
            $inspected->attachments
        );
        $attachmentHashes = array_map(
            static fn (PreparedEmailAttachment $attachment): string => $attachment->contentHash,
            $preparedAttachments
        );
        $contentHash = $this->contentHasher->hash($inspected->body, $attachmentHashes);
        $externalId = MessageIdentifier::fromMessageId($inspected->messageId, $uidValidity, $uid);

        if ($this->messages->findDuplicate($settings->sourceId, $externalId, $contentHash) !== null) {
            $mailbox->ensureFolder($settings->processedFolder);
            $mailbox->move($uid, $settings->processedFolder);
            $this->advanceCheckpoint($settings->sourceId, $checkpoint, $uid);

            return null;
        }

        return $this->storeAndMoveMessage(
            $settings,
            $mailbox,
            $checkpoint,
            $uidValidity,
            $uid,
            $rawMessage->raw,
            $inspected,
            $externalId,
            $contentHash,
            $preparedAttachments
        );
    }

    /**
     * @param list<PreparedEmailAttachment> $preparedAttachments
     */
    private function storeAndMoveMessage(
        MailboxSettings $settings,
        MailboxInterface $mailbox,
        MailboxCheckpoint $checkpoint,
        int $uidValidity,
        int $uid,
        string $raw,
        InspectedInboundMail $inspected,
        string $externalId,
        string $contentHash,
        array $preparedAttachments
    ): ?DateTimeImmutable {
        $createdFiles = [];

        try {
            $rawPath = $this->storage->storeRawMessage($raw);
            $createdFiles[] = $rawPath;
            $attachments = [];

            foreach ($preparedAttachments as $prepared) {
                $storagePath = '';

                if ($prepared->status === AttachmentStoragePolicy::STATUS_PENDING) {
                    if ($prepared->extension === null) {
                        throw new RuntimeException('An allowed attachment has no storage extension.');
                    }

                    $storagePath = $this->storage->storeAttachment($prepared->content, $prepared->extension);
                    $createdFiles[] = $storagePath;
                }

                $attachments[] = new InboundAttachmentRecord(
                    $prepared->filename,
                    $prepared->mimeType,
                    $prepared->sizeBytes,
                    $storagePath,
                    $prepared->contentHash,
                    $prepared->status
                );
            }

            $record = new InboundMessageRecord(
                $settings->sourceId,
                $externalId,
                $contentHash,
                $inspected->senderEmail,
                $inspected->senderName,
                $inspected->subject,
                $inspected->receivedAt,
                $rawPath,
                $attachments
            );
            $stored = $this->messages->store($record, $this->timestamp());

            if ($stored->duplicate) {
                $filesToDelete = $createdFiles;
                $createdFiles = [];
                $this->deleteCreatedFiles($filesToDelete);
            } else {
                $createdFiles = [];
            }

            $mailbox->ensureFolder($settings->processedFolder);
            $mailbox->move($uid, $settings->processedFolder);
            $this->advanceCheckpoint($settings->sourceId, $checkpoint, $uid);

            return $stored->duplicate ? null : $inspected->receivedAt;
        } catch (Throwable $failure) {
            try {
                $this->deleteCreatedFiles($createdFiles);
            } catch (Throwable $cleanupFailure) {
                throw new RuntimeException(
                    'Inbound message handling failed and its temporary files could not all be removed.',
                    0,
                    $failure
                );
            }

            throw $failure;
        }
    }

    private function moveOversizedOnFreshConnection(
        MailboxSettings $settings,
        int $uidValidity,
        int $uid
    ): void
    {
        $password = ($this->passwordResolver)($settings);

        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException('No mailbox password is configured.');
        }

        $mailbox = ($this->mailboxFactory)($settings, $password);

        if (! $mailbox instanceof MailboxInterface) {
            throw new RuntimeException('The mailbox factory returned an invalid adapter.');
        }

        try {
            if ($mailbox->uidValidity() !== $uidValidity) {
                $this->stoppedSources[$settings->sourceId] = true;
                throw new RuntimeException(
                    'The mailbox UIDVALIDITY changed before the oversized message could be moved.'
                );
            }

            $mailbox->ensureFolder(self::TOO_LARGE_FOLDER);
            $mailbox->move($uid, self::TOO_LARGE_FOLDER);
        } finally {
            $mailbox->close();
        }
    }

    private function advanceCheckpoint(int $sourceId, MailboxCheckpoint $checkpoint, int $uid): void
    {
        if ($this->hasEarlierFailedUid($sourceId, $checkpoint->uidValidity, $uid)) {
            return;
        }

        $this->saveCheckpoint($sourceId, $checkpoint->advanceTo($uid));
    }

    private function saveCheckpoint(int $sourceId, MailboxCheckpoint $checkpoint): void
    {
        $this->checkpoints->saveCheckpoint($sourceId, $checkpoint, $this->timestamp());
        $this->runCheckpoints[$sourceId] = $checkpoint;
    }

    private function findCheckpointForRun(int $sourceId): ?MailboxCheckpoint
    {
        if (! array_key_exists($sourceId, $this->runCheckpoints)) {
            $this->runCheckpoints[$sourceId] = $this->checkpoints->findCheckpoint($sourceId);
        }

        return $this->runCheckpoints[$sourceId];
    }

    private function hasEarlierFailedUid(int $sourceId, int $uidValidity, int $uid): bool
    {
        foreach ($this->failedUids[$this->mailboxKey($sourceId, $uidValidity)] ?? [] as $failedUid) {
            if ($failedUid < $uid) {
                return true;
            }
        }

        return false;
    }

    private function rememberFailedUid(int $sourceId, int $uidValidity, int $uid): void
    {
        $key = $this->mailboxKey($sourceId, $uidValidity);
        $this->failedUids[$key] ??= [];

        if (! in_array($uid, $this->failedUids[$key], true)) {
            $this->failedUids[$key][] = $uid;
        }
    }

    private function recordSuccessfulPoll(int $sourceId, ?DateTimeImmutable $itemAt): void
    {
        if (isset($this->sourcesWithFailures[$sourceId])) {
            $this->healthRecorder->recordChecked($sourceId);
        } else {
            $this->healthRecorder->recordSuccess($sourceId, $itemAt);
        }
    }

    private function recordFailure(int $sourceId, ?int $uid, Throwable $failure): void
    {
        if (isset($this->sourcesWithFailures[$sourceId])) {
            return;
        }

        $this->sourcesWithFailures[$sourceId] = true;
        $message = $uid === null
            ? 'Mailbox poll failed (' . get_class($failure) . ').'
            : 'Mailbox message UID ' . $uid . ' failed (' . get_class($failure) . ').';

        $this->healthRecorder->recordFailure($sourceId, $message);
    }

    /**
     * @param list<string> $paths
     */
    private function deleteCreatedFiles(array $paths): void
    {
        $cleanupFailure = null;

        foreach ($paths as $path) {
            try {
                $this->storage->delete($path);
            } catch (Throwable $failure) {
                $cleanupFailure ??= $failure;
            }
        }

        if ($cleanupFailure !== null) {
            throw new RuntimeException('One or more temporary inbound files could not be removed.', 0, $cleanupFailure);
        }
    }

    private function timestamp(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function mailboxKey(int $sourceId, int $uidValidity): string
    {
        return $sourceId . ':' . $uidValidity;
    }

    private function uidKey(int $sourceId, int $uidValidity, int $uid): string
    {
        return $this->mailboxKey($sourceId, $uidValidity) . ':' . $uid;
    }
}
