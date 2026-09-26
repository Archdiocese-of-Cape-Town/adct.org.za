<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\JobLockInterface;
use ADCT\ParishIntake\Core\Ports\JobStateStoreInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class JobRunner
{
    public const DEFAULT_TIME_BUDGET_SECONDS = 60;
    public const DEFAULT_ITEM_BUDGET = 100;
    public const DEFAULT_LOCK_TTL_SECONDS = 180;

    private JobLockInterface $lock;
    private JobStateStoreInterface $stateStore;
    private ClockInterface $clock;
    private int $timeBudgetSeconds;
    private int $itemBudget;
    private int $lockTtlSeconds;

    public function __construct(
        JobLockInterface $lock,
        JobStateStoreInterface $stateStore,
        ClockInterface $clock,
        int $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS,
        int $itemBudget = self::DEFAULT_ITEM_BUDGET,
        int $lockTtlSeconds = self::DEFAULT_LOCK_TTL_SECONDS
    ) {
        if ($timeBudgetSeconds < 1 || $itemBudget < 1) {
            throw new InvalidArgumentException('Job time and item budgets must be positive.');
        }

        if ($lockTtlSeconds <= $timeBudgetSeconds) {
            throw new InvalidArgumentException('The lock expiry must exceed the job time budget.');
        }

        $this->lock = $lock;
        $this->stateStore = $stateStore;
        $this->clock = $clock;
        $this->timeBudgetSeconds = $timeBudgetSeconds;
        $this->itemBudget = $itemBudget;
        $this->lockTtlSeconds = $lockTtlSeconds;
    }

    public function run(
        JobInterface $job,
        bool $force = false,
        ?int $timeBudgetSeconds = null,
        ?int $itemBudget = null,
        string $trigger = 'internal'
    ): JobRunResult {
        $jobId = $job->id();
        $this->assertJobId($jobId);

        $timeBudgetSeconds = $timeBudgetSeconds ?? $this->timeBudgetSeconds;
        $itemBudget = $itemBudget ?? $this->itemBudget;

        if ($timeBudgetSeconds < 1 || $itemBudget < 1) {
            throw new InvalidArgumentException('Job time and item budgets must be positive.');
        }

        if ($timeBudgetSeconds >= $this->lockTtlSeconds) {
            throw new InvalidArgumentException('The lock expiry must exceed the job time budget.');
        }

        $lockToken = $this->lock->acquire($jobId, $this->clock->now(), $this->lockTtlSeconds);

        if ($lockToken === null) {
            return new JobRunResult(JobRunStatus::LOCKED, 0);
        }

        try {
            if ($job instanceof JobRunLifecycleInterface) {
                $job->beginRun();
            }

            $state = $this->stateStore->load($jobId);

            if (! $force) {
                try {
                    if (! $job->isDue($this->clock->now(), $state)) {
                        return new JobRunResult(JobRunStatus::NOT_DUE, 0);
                    }
                } catch (Throwable $failure) {
                    $startedAt = $this->clock->now();

                    if (! $this->lock->isHeldBy($jobId, $lockToken, $startedAt)) {
                        return new JobRunResult(JobRunStatus::LOCK_LOST, 0, $failure->getMessage());
                    }

                    $state = $state->withRunStarted($startedAt, $trigger);

                    return $this->recordFailure($jobId, $lockToken, $state, 0, $failure);
                }
            }

            $startedAt = $this->clock->now();

            if (! $this->lock->isHeldBy($jobId, $lockToken, $startedAt)) {
                return new JobRunResult(JobRunStatus::LOCK_LOST, 0);
            }

            $state = $state->withRunStarted($startedAt, $trigger);
            $checkpoint = $state->checkpoint;
            $itemsProcessed = 0;
            $status = JobRunStatus::COMPLETED;

            try {
                $this->stateStore->save($jobId, $state);

                while (true) {
                    if ($itemsProcessed >= $itemBudget) {
                        $status = JobRunStatus::ITEM_BUDGET_REACHED;
                        break;
                    }

                    if ($this->elapsedSeconds($startedAt) >= $timeBudgetSeconds) {
                        $status = JobRunStatus::TIME_BUDGET_REACHED;
                        break;
                    }

                    if (! $this->lock->isHeldBy($jobId, $lockToken, $this->clock->now())) {
                        return new JobRunResult(JobRunStatus::LOCK_LOST, $itemsProcessed);
                    }

                    $step = $job->processNext($checkpoint);

                    if ($step === null) {
                        break;
                    }

                    ++$itemsProcessed;
                    $checkpoint = $step->checkpoint();

                    if (! $this->lock->isHeldBy($jobId, $lockToken, $this->clock->now())) {
                        return new JobRunResult(JobRunStatus::LOCK_LOST, $itemsProcessed);
                    }

                    $state = $state->withCheckpoint($checkpoint, $itemsProcessed);
                    $this->stateStore->save($jobId, $state);

                    if ($step->isComplete()) {
                        break;
                    }
                }

                $finishedAt = $this->clock->now();

                if (! $this->lock->isHeldBy($jobId, $lockToken, $finishedAt)) {
                    return new JobRunResult(JobRunStatus::LOCK_LOST, $itemsProcessed);
                }

                $successfulState = $state->withRunSucceeded($finishedAt, $itemsProcessed);
                $this->stateStore->save($jobId, $successfulState);

                return new JobRunResult($status, $itemsProcessed);
            } catch (Throwable $failure) {
                return $this->recordFailure($jobId, $lockToken, $state, $itemsProcessed, $failure);
            }
        } finally {
            $this->lock->release($jobId, $lockToken);
        }
    }

    private function recordFailure(
        string $jobId,
        string $lockToken,
        JobState $state,
        int $itemsProcessed,
        Throwable $failure
    ): JobRunResult {
        $failedAt = $this->clock->now();

        if (! $this->lock->isHeldBy($jobId, $lockToken, $failedAt)) {
            return new JobRunResult(JobRunStatus::LOCK_LOST, $itemsProcessed, $failure->getMessage());
        }

        $state = $state->withRunFailed($failedAt, $itemsProcessed, $failure->getMessage());
        $this->stateStore->save($jobId, $state);

        return new JobRunResult(JobRunStatus::FAILED, $itemsProcessed, $failure->getMessage());
    }

    private function elapsedSeconds(DateTimeImmutable $startedAt): int
    {
        return max(0, $this->clock->now()->getTimestamp() - $startedAt->getTimestamp());
    }

    private function assertJobId(string $jobId): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $jobId) !== 1) {
            throw new InvalidArgumentException('Job IDs must be lowercase letters, digits, and underscores.');
        }
    }
}
