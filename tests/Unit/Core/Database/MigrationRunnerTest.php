<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Database;

use ADCT\ParishIntake\Core\Database\MigrationRunner;
use ADCT\ParishIntake\Core\Ports\MigrationLoggerInterface;
use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use ADCT\ParishIntake\Core\Ports\MigrationVersionStoreInterface;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class MigrationRunnerTest extends TestCase
{
    public function testMigrationsAreSortedByVersionBeforeExecution(): void
    {
        $executionOrder = [];
        $store = new FakeMigrationVersionStore();
        $logger = new FakeMigrationLogger();
        $runner = new MigrationRunner([
            new RecordingMigrationStep(3, static function () use (&$executionOrder): void {
                $executionOrder[] = 3;
            }),
            new RecordingMigrationStep(1, static function () use (&$executionOrder): void {
                $executionOrder[] = 1;
            }),
            new RecordingMigrationStep(2, static function () use (&$executionOrder): void {
                $executionOrder[] = 2;
            }),
        ], $store, $logger);

        $result = $runner->run();

        self::assertSame([1, 2, 3], $executionOrder);
        self::assertSame([1, 2, 3], $store->writtenVersions);
        self::assertSame([1, 2, 3], $result->appliedVersions());
        self::assertSame(3, $runner->latestVersion());
        self::assertTrue($result->succeeded());
    }

    public function testStoredVersionSkipsAppliedMigrationsAndRerunsAreIdempotent(): void
    {
        $stepOne = new RecordingMigrationStep(1);
        $stepTwo = new RecordingMigrationStep(2);
        $stepThree = new RecordingMigrationStep(3);
        $store = new FakeMigrationVersionStore(2);
        $runner = new MigrationRunner(
            [$stepOne, $stepTwo, $stepThree],
            $store,
            new FakeMigrationLogger()
        );

        $firstResult = $runner->run();
        $secondResult = $runner->run();

        self::assertSame(0, $stepOne->runCount());
        self::assertSame(0, $stepTwo->runCount());
        self::assertSame(1, $stepThree->runCount());
        self::assertSame([3], $store->writtenVersions);
        self::assertSame([3], $firstResult->appliedVersions());
        self::assertSame([], $secondResult->appliedVersions());
        self::assertSame(3, $store->getVersion());
    }

    public function testVersionIsWrittenOnlyAfterItsMigrationSucceeds(): void
    {
        $store = new FakeMigrationVersionStore();
        $step = new RecordingMigrationStep(1, static function () use ($store): void {
            self::assertSame(0, $store->getVersion());
        });
        $runner = new MigrationRunner([$step], $store, new FakeMigrationLogger());

        $result = $runner->run();

        self::assertTrue($result->succeeded());
        self::assertSame(1, $store->getVersion());
        self::assertSame([1], $store->writtenVersions);
    }

    public function testFailureIsLoggedAndStopsLaterMigrationsWithoutAdvancingVersion(): void
    {
        $failure = new RuntimeException('DDL failed');
        $store = new FakeMigrationVersionStore();
        $logger = new FakeMigrationLogger();
        $stepOne = new RecordingMigrationStep(1);
        $stepTwo = new RecordingMigrationStep(2, static function () use ($failure): void {
            throw $failure;
        });
        $stepThree = new RecordingMigrationStep(3);
        $runner = new MigrationRunner([$stepOne, $stepTwo, $stepThree], $store, $logger);

        $result = $runner->run();

        self::assertFalse($result->succeeded());
        self::assertSame(1, $result->currentVersion());
        self::assertSame(2, $result->failedVersion());
        self::assertSame('DDL failed', $result->error());
        self::assertSame(1, $store->getVersion());
        self::assertSame([1], $store->writtenVersions);
        self::assertSame(0, $stepThree->runCount());
        self::assertSame([2], array_column($logger->failures, 'version'));
        self::assertSame($failure, $logger->failures[0]['failure']);
    }

    public function testFailedVersionWriteStopsTheMigrationSequence(): void
    {
        $store = new FakeMigrationVersionStore();
        $store->allowWrites = false;
        $logger = new FakeMigrationLogger();
        $stepOne = new RecordingMigrationStep(1);
        $stepTwo = new RecordingMigrationStep(2);
        $runner = new MigrationRunner([$stepOne, $stepTwo], $store, $logger);

        $result = $runner->run();

        self::assertFalse($result->succeeded());
        self::assertSame(1, $result->failedVersion());
        self::assertSame(0, $result->currentVersion());
        self::assertSame(0, $stepTwo->runCount());
        self::assertSame(1, count($logger->failures));
    }

    public function testDuplicateMigrationVersionsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MigrationRunner(
            [new RecordingMigrationStep(1), new RecordingMigrationStep(1)],
            new FakeMigrationVersionStore(),
            new FakeMigrationLogger()
        );
    }
}

final class RecordingMigrationStep implements MigrationStepInterface
{
    private int $runs = 0;

    private ?Closure $handler;

    public function __construct(private int $migrationVersion, ?callable $handler = null)
    {
        $this->handler = $handler === null ? null : Closure::fromCallable($handler);
    }

    public function version(): int
    {
        return $this->migrationVersion;
    }

    public function apply(): void
    {
        ++$this->runs;

        if ($this->handler !== null) {
            ($this->handler)();
        }
    }

    public function runCount(): int
    {
        return $this->runs;
    }
}

final class FakeMigrationVersionStore implements MigrationVersionStoreInterface
{
    /**
     * @var int[]
     */
    public array $writtenVersions = [];

    public bool $allowWrites = true;

    public function __construct(private int $version = 0)
    {
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): bool
    {
        $this->writtenVersions[] = $version;

        if (! $this->allowWrites) {
            return false;
        }

        $this->version = $version;

        return true;
    }
}

final class FakeMigrationLogger implements MigrationLoggerInterface
{
    /**
     * @var array<int, array{version: int, failure: Throwable}>
     */
    public array $failures = [];

    /**
     * @var int[]
     */
    public array $successfulVersions = [];

    public function migrationFailed(int $version, Throwable $failure): void
    {
        $this->failures[] = ['version' => $version, 'failure' => $failure];
    }

    public function migrationsSucceeded(int $version): void
    {
        $this->successfulVersions[] = $version;
    }
}
