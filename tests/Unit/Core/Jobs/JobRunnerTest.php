<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Jobs;

use ADCT\ParishIntake\Core\Jobs\AbstractJob;
use ADCT\ParishIntake\Core\Jobs\JobRunLifecycleInterface;
use ADCT\ParishIntake\Core\Jobs\JobRunStatus;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\JobLockInterface;
use ADCT\ParishIntake\Core\Ports\JobStateStoreInterface;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JobRunnerTest extends TestCase
{
    public function testStopsAtTimeBudgetAndResumesFromTheSavedCheckpoint(): void
    {
        $clock = new FakeJobClock(new DateTimeImmutable('2026-09-25T00:00:00+02:00'));
        $lock = new FakeJobLock();
        $stateStore = new FakeJobStateStore();
        $runner = new JobRunner($lock, $stateStore, $clock, 2, 10, 10);
        $job = new TestJob($clock, ['first', 'second', 'third'], 1);

        $firstRun = $runner->run($job, true);

        self::assertSame(JobRunStatus::TIME_BUDGET_REACHED, $firstRun->status);
        self::assertSame(2, $firstRun->itemsProcessed);
        self::assertSame('2', $stateStore->load($job->id())->checkpoint);
        self::assertSame(['first', 'second'], $job->processedItems);

        $savedCheckpoints = array_map(
            static fn (JobState $state): ?string => $state->checkpoint,
            $stateStore->savedStates
        );
        self::assertSame([null, '1', '2', '2'], $savedCheckpoints);

        $secondRun = $runner->run($job, true);

        self::assertSame(JobRunStatus::COMPLETED, $secondRun->status);
        self::assertSame(1, $secondRun->itemsProcessed);
        self::assertSame('3', $stateStore->load($job->id())->checkpoint);
        self::assertSame(['first', 'second', 'third'], $job->processedItems);
    }

    public function testStopsAtTheItemBudgetAndSavesEachCheckpoint(): void
    {
        $clock = new FakeJobClock(new DateTimeImmutable('2026-09-25T00:00:00+02:00'));
        $stateStore = new FakeJobStateStore();
        $runner = new JobRunner(new FakeJobLock(), $stateStore, $clock, 60, 2, 180);
        $job = new TestJob($clock, ['first', 'second', 'third']);

        $result = $runner->run($job, true);

        self::assertSame(JobRunStatus::ITEM_BUDGET_REACHED, $result->status);
        self::assertSame(2, $result->itemsProcessed);
        self::assertSame('2', $stateStore->load($job->id())->checkpoint);
        self::assertSame(['first', 'second'], $job->processedItems);
    }

    public function testOverlappingRunIsPrevented(): void
    {
        $clock = new FakeJobClock(new DateTimeImmutable('2026-09-25T00:00:00+02:00'));
        $lock = new FakeJobLock();
        $stateStore = new FakeJobStateStore();
        $runner = new JobRunner($lock, $stateStore, $clock, 60, 10, 180);
        $job = new TestJob($clock, ['one']);
        $firstToken = $lock->acquire($job->id(), $clock->now(), 180);

        self::assertNotNull($firstToken);
        $result = $runner->run($job, true);

        self::assertSame(JobRunStatus::LOCKED, $result->status);
        self::assertSame([], $job->processedItems);
        self::assertSame([], $stateStore->savedStates);
    }

    public function testExpiredLockIsRecovered(): void
    {
        $clock = new FakeJobClock(new DateTimeImmutable('2026-09-25T00:00:00+02:00'));
        $lock = new FakeJobLock();
        $stateStore = new FakeJobStateStore();
        $runner = new JobRunner($lock, $stateStore, $clock, 60, 10, 180);
        $job = new TestJob($clock, ['one']);
        $expiredToken = $lock->acquire($job->id(), $clock->now(), 1);

        $clock->advance(2);
        $result = $runner->run($job, true);

        self::assertNotNull($expiredToken);
        self::assertSame(JobRunStatus::COMPLETED, $result->status);
        self::assertSame(['one'], $job->processedItems);
        self::assertFalse($lock->isHeldBy($job->id(), $expiredToken, $clock->now()));
    }

    public function testStaleHolderCannotReleaseANewerLock(): void
    {
        $clock = new FakeJobClock(new DateTimeImmutable('2026-09-25T00:00:00+02:00'));
        $lock = new FakeJobLock();
        $oldToken = $lock->acquire('test_job', $clock->now(), 1);

        $clock->advance(2);
        $newToken = $lock->acquire('test_job', $clock->now(), 30);

        self::assertNotNull($oldToken);
        self::assertNotNull($newToken);
        self::assertNotSame($oldToken, $newToken);
        self::assertFalse($lock->release('test_job', $oldToken));
        self::assertTrue($lock->isHeldBy('test_job', $newToken, $clock->now()));
    }

    public function testExceptionReleasesTheLockAndRecordsLastError(): void
    {
        $clock = new FakeJobClock(new DateTimeImmutable('2026-09-25T00:00:00+02:00'));
        $lock = new FakeJobLock();
        $stateStore = new FakeJobStateStore();
        $runner = new JobRunner($lock, $stateStore, $clock, 60, 10, 180);
        $job = new TestJob($clock, ['one', 'two'], 0, 0, failureMessage: 'item failed');

        $result = $runner->run($job, true);
        $state = $stateStore->load($job->id());

        self::assertSame(JobRunStatus::FAILED, $result->status);
        self::assertSame('item failed', $state->lastErrorMessage);
        self::assertSame($clock->now(), $state->lastErrorAt);
        self::assertNull($state->lastSuccessAt);
        self::assertFalse($lock->isHeldBy($job->id(), 'token-1', $clock->now()));
    }

    public function testLastSuccessChangesOnlyAfterASuccessfulRun(): void
    {
        $clock = new FakeJobClock(new DateTimeImmutable('2026-09-25T00:00:00+02:00'));
        $stateStore = new FakeJobStateStore();
        $runner = new JobRunner(new FakeJobLock(), $stateStore, $clock, 60, 10, 180);
        $successfulJob = new TestJob($clock, ['one']);

        self::assertSame(JobRunStatus::COMPLETED, $runner->run($successfulJob, true)->status);
        $successfulAt = $stateStore->load($successfulJob->id())->lastSuccessAt;

        $clock->advance(1);
        $failingJob = new TestJob($clock, ['placeholder', 'two'], 0, 1, failureMessage: 'next run failed');
        $failedResult = $runner->run($failingJob, true);
        $stateAfterFailure = $stateStore->load($failingJob->id());

        self::assertSame(JobRunStatus::FAILED, $failedResult->status);
        self::assertSame($successfulAt, $stateAfterFailure->lastSuccessAt);
        self::assertSame('next run failed', $stateAfterFailure->lastErrorMessage);

        $clock->advance(1);
        $recoveredJob = new TestJob($clock, ['placeholder', 'three']);
        self::assertSame(JobRunStatus::COMPLETED, $runner->run($recoveredJob, true)->status);
        $stateAfterRecovery = $stateStore->load($recoveredJob->id());

        self::assertSame($clock->now(), $stateAfterRecovery->lastSuccessAt);
        self::assertSame('next run failed', $stateAfterRecovery->lastErrorMessage);
        self::assertSame(['three'], $recoveredJob->processedItems);
    }

    public function testJobIsSkippedUntilDueUnlessForced(): void
    {
        $clock = new FakeJobClock(new DateTimeImmutable('2026-09-25T00:00:00+02:00'));
        $stateStore = new FakeJobStateStore();
        $runner = new JobRunner(new FakeJobLock(), $stateStore, $clock, 60, 10, 180);
        $job = new TestJob($clock, ['one'], 0, null, null, 600);

        self::assertSame(JobRunStatus::COMPLETED, $runner->run($job, true)->status);
        self::assertSame(JobRunStatus::NOT_DUE, $runner->run($job)->status);
        self::assertSame(1, $job->processCalls);
        self::assertSame(['one'], $job->processedItems);

        $clock->advance(600);
        self::assertSame(JobRunStatus::COMPLETED, $runner->run($job)->status);
        self::assertSame(2, $job->processCalls);
        self::assertSame(['one'], $job->processedItems);
    }

    public function testRunLifecycleBeginsForEachAcquiredJobRun(): void
    {
        $clock = new FakeJobClock(new DateTimeImmutable('2026-09-25T00:00:00+02:00'));
        $runner = new JobRunner(new FakeJobLock(), new FakeJobStateStore(), $clock, 60, 10, 180);
        $job = new LifecycleAwareTestJob();

        self::assertSame(JobRunStatus::COMPLETED, $runner->run($job, true)->status);
        self::assertSame(JobRunStatus::COMPLETED, $runner->run($job, true)->status);
        self::assertSame(2, $job->beginRunCalls);
    }
}

final class FakeJobClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $currentTime)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->currentTime;
    }

    public function advance(int $seconds): void
    {
        $this->currentTime = $this->currentTime->modify('+' . $seconds . ' seconds');
    }
}

final class FakeJobLock implements JobLockInterface
{
    /**
     * @var array<string, array{token: string, expires_at: int}>
     */
    private array $locks = [];

    private int $nextTokenNumber = 1;

    public function acquire(string $jobId, DateTimeImmutable $now, int $expiresInSeconds): ?string
    {
        if (isset($this->locks[$jobId]) && $this->locks[$jobId]['expires_at'] > $now->getTimestamp()) {
            return null;
        }

        $token = 'token-' . $this->nextTokenNumber++;
        $this->locks[$jobId] = [
            'token' => $token,
            'expires_at' => $now->getTimestamp() + $expiresInSeconds,
        ];

        return $token;
    }

    public function isHeldBy(string $jobId, string $token, DateTimeImmutable $now): bool
    {
        return isset($this->locks[$jobId])
            && $this->locks[$jobId]['expires_at'] > $now->getTimestamp()
            && hash_equals($this->locks[$jobId]['token'], $token);
    }

    public function release(string $jobId, string $token): bool
    {
        if (! isset($this->locks[$jobId]) || ! hash_equals($this->locks[$jobId]['token'], $token)) {
            return false;
        }

        unset($this->locks[$jobId]);

        return true;
    }
}

final class FakeJobStateStore implements JobStateStoreInterface
{
    /**
     * @var array<string, JobState>
     */
    private array $states = [];

    /**
     * @var JobState[]
     */
    public array $savedStates = [];

    public function load(string $jobId): JobState
    {
        return $this->states[$jobId] ?? JobState::empty();
    }

    public function save(string $jobId, JobState $state): void
    {
        $this->states[$jobId] = $state;
        $this->savedStates[] = $state;
    }
}

final class TestJob extends AbstractJob
{
    /**
     * @var mixed[]
     */
    private array $items;

    private FakeJobClock $clock;
    private int $secondsPerItem;
    private ?int $failAt;
    private string $failureMessage;

    private ?Closure $beforeProcess;

    /**
     * @var mixed[]
     */
    public array $processedItems = [];

    public int $processCalls = 0;

    /**
     * @param mixed[] $items
     */
    public function __construct(
        FakeJobClock $clock,
        array $items,
        int $secondsPerItem = 0,
        ?int $failAt = null,
        ?callable $beforeProcess = null,
        int $dueIntervalSeconds = 60,
        string $failureMessage = 'test failure'
    ) {
        parent::__construct('test_job', 'Test job', $dueIntervalSeconds);
        $this->clock = $clock;
        $this->items = $items;
        $this->secondsPerItem = $secondsPerItem;
        $this->failAt = $failAt;
        $this->beforeProcess = $beforeProcess === null ? null : Closure::fromCallable($beforeProcess);
        $this->failureMessage = $failureMessage;
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        ++$this->processCalls;
        $position = $checkpoint === null ? 0 : (int) $checkpoint;

        if ($this->beforeProcess !== null) {
            ($this->beforeProcess)($position, $this);
        }

        if ($this->failAt === $position) {
            throw new RuntimeException($this->failureMessage);
        }

        if (! array_key_exists($position, $this->items)) {
            return null;
        }

        $this->processedItems[] = $this->items[$position];
        $this->clock->advance($this->secondsPerItem);
        $nextPosition = (string) ($position + 1);

        return $position + 1 >= count($this->items)
            ? JobStepResult::completeAt($nextPosition)
            : JobStepResult::continueAt($nextPosition);
    }
}

final class LifecycleAwareTestJob extends AbstractJob implements JobRunLifecycleInterface
{
    public int $beginRunCalls = 0;

    public function __construct()
    {
        parent::__construct('lifecycle_job', 'Lifecycle job', 60);
    }

    public function beginRun(): void
    {
        ++$this->beginRunCalls;
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        return JobStepResult::completeAt((string) ($this->beginRunCalls));
    }
}
