<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Sources;

use ADCT\ParishIntake\Core\Jobs\AbstractJob;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\JobLockInterface;
use ADCT\ParishIntake\Core\Ports\JobStateStoreInterface;
use ADCT\ParishIntake\Core\Ports\SourceHealthStoreInterface;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use ADCT\ParishIntake\Core\Sources\SourceHealthState;
use ADCT\ParishIntake\Core\Sources\SourceStatus;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SourceHealthRecorderTest extends TestCase
{
    public function testSuccessResetsFailuresAndKeepsTheLastItemWhenNoneIsReported(): void
    {
        $store = new InMemorySourceHealthStore(new SourceHealthState(
            SourceStatus::UNRELIABLE,
            '2026-09-24 20:00:00',
            '2026-09-24 19:00:00',
            '2026-09-24 18:00:00',
            4,
            'previous failure'
        ));
        $recorder = new SourceHealthRecorder($store, new SourceHealthTestClock());

        $recorder->recordSuccess(17);

        self::assertNotNull($store->state);
        self::assertSame(SourceStatus::UNRELIABLE, $store->state->status);
        self::assertSame('2026-09-25 00:03:04', $store->state->lastCheckedAt);
        self::assertSame('2026-09-25 00:03:04', $store->state->lastSuccessAt);
        self::assertSame('2026-09-24 18:00:00', $store->state->lastItemAt);
        self::assertSame(0, $store->state->consecutiveFailures);
        self::assertNull($store->state->lastError);
    }

    public function testSuccessStoresItemTimeInUtcWhenAnItemIsReported(): void
    {
        $store = new InMemorySourceHealthStore(new SourceHealthState(
            SourceStatus::ACTIVE,
            null,
            null,
            null,
            1,
            'temporary failure'
        ));
        $recorder = new SourceHealthRecorder($store, new SourceHealthTestClock());

        $recorder->recordSuccess(17, new DateTimeImmutable('2026-09-25T02:01:00+02:00'));

        self::assertNotNull($store->state);
        self::assertSame('2026-09-25 00:01:00', $store->state->lastItemAt);
    }

    public function testFailureIncrementsCountAndMarksUnreliableAtThreshold(): void
    {
        $store = new InMemorySourceHealthStore(new SourceHealthState(
            SourceStatus::ACTIVE,
            null,
            null,
            null,
            3,
            null
        ));
        $recorder = new SourceHealthRecorder($store, new SourceHealthTestClock());

        $recorder->recordFailure(17, 'HTTP check failed');

        self::assertNotNull($store->state);
        self::assertSame(4, $store->state->consecutiveFailures);
        self::assertSame(SourceStatus::ACTIVE, $store->state->status);
        self::assertSame('HTTP check failed', $store->state->lastError);
        self::assertSame('2026-09-25 00:03:04', $store->state->lastCheckedAt);

        $recorder->recordFailure(17, 'HTTP check failed again');

        self::assertSame(5, $store->state->consecutiveFailures);
        self::assertSame(SourceStatus::UNRELIABLE, $store->state->status);
    }

    public function testFailuresDoNotOverridePausedOrDisabledStatus(): void
    {
        foreach ([SourceStatus::PAUSED, SourceStatus::DISABLED] as $status) {
            $store = new InMemorySourceHealthStore(new SourceHealthState(
                $status,
                null,
                null,
                null,
                4,
                null
            ));
            $recorder = new SourceHealthRecorder($store, new SourceHealthTestClock());

            $recorder->recordFailure(17, 'Source check failed');

            self::assertNotNull($store->state);
            self::assertSame($status, $store->state->status);
            self::assertSame(5, $store->state->consecutiveFailures);
        }
    }

    public function testCheckedUpdatesOnlyTheCheckTime(): void
    {
        $initial = new SourceHealthState(
            SourceStatus::ACTIVE,
            null,
            '2026-09-24 19:00:00',
            '2026-09-24 18:00:00',
            2,
            'previous failure'
        );
        $store = new InMemorySourceHealthStore($initial);
        $recorder = new SourceHealthRecorder($store, new SourceHealthTestClock());

        $recorder->recordChecked(17);

        self::assertNotNull($store->state);
        self::assertSame('2026-09-25 00:03:04', $store->state->lastCheckedAt);
        self::assertSame($initial->lastSuccessAt, $store->state->lastSuccessAt);
        self::assertSame($initial->lastItemAt, $store->state->lastItemAt);
        self::assertSame($initial->consecutiveFailures, $store->state->consecutiveFailures);
        self::assertSame($initial->lastError, $store->state->lastError);
    }

    public function testErrorIsRedactedAndTruncated(): void
    {
        $store = new InMemorySourceHealthStore(new SourceHealthState(
            SourceStatus::ACTIVE,
            null,
            null,
            null,
            0,
            null
        ));
        $recorder = new SourceHealthRecorder($store, new SourceHealthTestClock());

        $recorder->recordFailure(17, str_repeat('x', 600));
        self::assertNotNull($store->state);
        self::assertSame(SourceHealthRecorder::MAXIMUM_ERROR_LENGTH, strlen((string) $store->state->lastError));

        $recorder->recordFailure(17, 'Connection rejected for office@example.test');
        self::assertSame('Connection rejected for [redacted email]', $store->state->lastError);

        $recorder->recordFailure(
            17,
            'Connection to https://example.test/feed?token=secret failed for +27 11 234 5678'
        );
        self::assertSame(
            'Connection to [redacted URL] failed for [redacted number]',
            $store->state->lastError
        );
    }

    public function testEmptyErrorsAndUnknownSourcesAreRejected(): void
    {
        $recorder = new SourceHealthRecorder(
            new InMemorySourceHealthStore(null),
            new SourceHealthTestClock()
        );

        $this->expectException(InvalidArgumentException::class);
        $recorder->recordFailure(17, '  ');
    }

    public function testUnknownSourceCannotReceiveAHealthUpdate(): void
    {
        $recorder = new SourceHealthRecorder(
            new InMemorySourceHealthStore(null),
            new SourceHealthTestClock()
        );

        $this->expectException(DomainException::class);
        $recorder->recordChecked(17);
    }

    public function testAJobCanUseTheRecorderThroughTheJobRunner(): void
    {
        $store = new InMemorySourceHealthStore(new SourceHealthState(
            SourceStatus::ACTIVE,
            null,
            null,
            null,
            0,
            null
        ));
        $clock = new SourceHealthTestClock();
        $recorder = new SourceHealthRecorder($store, $clock);
        $job = new class($recorder) extends AbstractJob {
            public function __construct(private SourceHealthRecorder $recorder)
            {
                parent::__construct('source_health_demo', 'Source health demonstration', 600);
            }

            public function processNext(?string $checkpoint): ?JobStepResult
            {
                $this->recorder->recordSuccess(17);

                return null;
            }
        };
        $runner = new JobRunner(
            new SourceHealthTestJobLock(),
            new SourceHealthTestJobStateStore(),
            $clock,
            60,
            10,
            180
        );

        $result = $runner->run($job, true);

        self::assertTrue($result->succeeded());
        self::assertNotNull($store->state);
        self::assertSame('2026-09-25 00:03:04', $store->state->lastSuccessAt);
    }
}

final class InMemorySourceHealthStore implements SourceHealthStoreInterface
{
    public function __construct(public ?SourceHealthState $state)
    {
    }

    public function findHealth(int $sourceId): ?SourceHealthState
    {
        return $sourceId === 17 ? $this->state : null;
    }

    public function saveHealthIfUnchanged(
        int $sourceId,
        SourceHealthState $expected,
        SourceHealthState $replacement,
        string $updatedAt
    ): bool {
        if ($sourceId !== 17 || $this->state === null || ! $this->state->equals($expected)) {
            return false;
        }

        $this->state = $replacement;

        return true;
    }
}

final class SourceHealthTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-25 02:03:04', new DateTimeZone('Africa/Johannesburg'));
    }
}

final class SourceHealthTestJobLock implements JobLockInterface
{
    public function acquire(string $jobId, DateTimeImmutable $now, int $expiresInSeconds): ?string
    {
        return 'source-health-test-token';
    }

    public function isHeldBy(string $jobId, string $token, DateTimeImmutable $now): bool
    {
        return $token === 'source-health-test-token';
    }

    public function release(string $jobId, string $token): bool
    {
        return $token === 'source-health-test-token';
    }
}

final class SourceHealthTestJobStateStore implements JobStateStoreInterface
{
    /**
     * @var array<string, JobState>
     */
    private array $states = [];

    public function load(string $jobId): JobState
    {
        return $this->states[$jobId] ?? JobState::empty();
    }

    public function save(string $jobId, JobState $state): void
    {
        $this->states[$jobId] = $state;
    }
}
