<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\ProcessedMailRetentionInterface;
use ADCT\ParishIntake\Core\Ports\RetentionStoreInterface;
use ADCT\ParishIntake\Core\Retention\RetentionSettings;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class RetentionJob extends AbstractJob
{
    /** @var Closure(): RetentionSettings */
    private Closure $settings;
    /** @var Closure(): list<int> */
    private Closure $mailboxIds;
    /** @var Closure(int): ProcessedMailRetentionInterface */
    private Closure $mailbox;

    /**
     * @param callable(): RetentionSettings $settings
     * @param callable(): list<int> $mailboxIds
     * @param callable(int): ProcessedMailRetentionInterface $mailbox
     */
    public function __construct(
        private RetentionStoreInterface $store,
        private ClockInterface $clock,
        callable $settings,
        callable $mailboxIds,
        callable $mailbox
    ) {
        parent::__construct('retention', 'Remove expired private data', 86400);
        $this->settings = Closure::fromCallable($settings);
        $this->mailboxIds = Closure::fromCallable($mailboxIds);
        $this->mailbox = Closure::fromCallable($mailbox);
    }

    public function isDue(DateTimeImmutable $now, JobState $state): bool
    {
        return $state->checkpoint !== null || parent::isDue($now, $state);
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        $cursor = $checkpoint === null
            ? ['phase' => 'files', 'id' => 0, 'uid' => 0, 'validity' => 0, 'mailboxDone' => true,
                'at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]
            : $this->decode($checkpoint);
        $at = new DateTimeImmutable($cursor['at'] . ' UTC');
        $settings = ($this->settings)();
        if (! $settings instanceof RetentionSettings) {
            throw new RuntimeException('The retention settings are invalid.');
        }

        if ($cursor['phase'] === 'files') {
            $id = $this->store->nextExpiredMessageId($cursor['id'], $cursor['at']);
            if ($id !== null) {
                $this->store->removeExpiredMessageFiles($id, $cursor['at']);
                $cursor['id'] = $id;
                return JobStepResult::continueAt($this->encode($cursor));
            }
            $cursor['phase'] = 'mail';
            $cursor['id'] = 0;
        }

        if ($cursor['phase'] === 'mail') {
            $ids = ($this->mailboxIds)();
            sort($ids, SORT_NUMERIC);
            $mailboxId = null;
            if (! $cursor['mailboxDone'] && $cursor['id'] > 0 && in_array($cursor['id'], $ids, true)) {
                $mailboxId = $cursor['id'];
            } else {
                foreach ($ids as $id) {
                    if (! is_int($id) || $id < 1) {
                        throw new RuntimeException('A retention mailbox ID is invalid.');
                    }
                    if ($id > $cursor['id']) {
                        $mailboxId = $id;
                        break;
                    }
                }
            }
            if ($mailboxId !== null) {
                $box = ($this->mailbox)($mailboxId);
                try {
                    $next = $box->nextOldProcessedUid(
                        $at->modify('-' . $settings->processedDays . ' days'),
                        ! $cursor['mailboxDone'] && $mailboxId === $cursor['id'] ? $cursor['uid'] : 0
                    );
                    if ($next['validity'] < 1
                        || (! $cursor['mailboxDone'] && $mailboxId === $cursor['id']
                            && $cursor['validity'] !== $next['validity'])) {
                        throw new RuntimeException('The Processed folder UID identity changed; review the retention job before retrying.');
                    }
                    if ($next['uid'] !== null) {
                        $afterUid = ! $cursor['mailboxDone'] && $mailboxId === $cursor['id']
                            ? $cursor['uid'] : 0;
                        if ($next['uid'] <= $afterUid) {
                            throw new RuntimeException('The Processed folder returned an invalid UID cursor.');
                        }
                        $box->deleteOldProcessedUid($next['uid'], $next['validity']);
                        $cursor['id'] = $mailboxId;
                        $cursor['uid'] = $next['uid'];
                        $cursor['validity'] = $next['validity'];
                        $cursor['mailboxDone'] = false;
                        return JobStepResult::continueAt($this->encode($cursor));
                    }
                    $cursor['id'] = $mailboxId;
                    $cursor['uid'] = 0;
                    $cursor['validity'] = $next['validity'];
                    $cursor['mailboxDone'] = true;
                                        return JobStepResult::continueAt($this->encode($cursor));
                } finally {
                    $box->close();
                }
                return JobStepResult::continueAt($this->encode($cursor));
            }
            $cursor['phase'] = 'tokens';
            $cursor['id'] = 0;
            $cursor['uid'] = 0;
            $cursor['validity'] = 0;
            $cursor['mailboxDone'] = true;
        }

        if ($cursor['phase'] === 'tokens') {
            $cutoff = $at->modify('-30 days')->format('Y-m-d H:i:s');
            $id = $this->store->nextExpiredTokenId($cursor['id'], $cutoff);
            if ($id !== null) {
                $this->store->removeExpiredToken($id, $cutoff);
                $cursor['id'] = $id;
                return JobStepResult::continueAt($this->encode($cursor));
            }
            $cursor['phase'] = 'audit';
            $cursor['id'] = 0;
        }

        $cutoff = $at->modify('-24 months')->format('Y-m-d H:i:s');
        $id = $this->store->nextExpiredAuditId($cursor['id'], $cutoff);
        if ($id !== null) {
            $this->store->removeExpiredAudit($id, $cutoff);
            $cursor['id'] = $id;
            return JobStepResult::continueAt($this->encode($cursor));
        }

        return JobStepResult::completeAt(null);
    }

    /** @return array{phase: string, id: int, uid: int, validity: int, mailboxDone: bool, at: string} */
    private function decode(string $checkpoint): array
    {
        $data = json_decode($checkpoint, true);
        if (! is_array($data)
            || ! in_array($data['phase'] ?? null, ['files', 'mail', 'tokens', 'audit'], true)
            || ! isset($data['id'], $data['uid'], $data['validity'], $data['at'])
            || ! is_int($data['id']) || $data['id'] < 0
            || ! is_int($data['uid']) || $data['uid'] < 0
            || ! is_int($data['validity']) || $data['validity'] < 0
            || ! isset($data['mailboxDone']) || ! is_bool($data['mailboxDone'])
            || ! is_string($data['at'])
            || DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $data['at'], new DateTimeZone('UTC'))?->format('Y-m-d H:i:s') !== $data['at']) {
            throw new RuntimeException('The retention checkpoint is invalid; review it before retrying.');
        }
        return $data;
    }

    /** @param array{phase: string, id: int, uid: int, validity: int, mailboxDone: bool, at: string} $cursor */
    private function encode(array $cursor): string
    {
        return json_encode($cursor, JSON_THROW_ON_ERROR);
    }
}
