<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Database;

use ADCT\ParishIntake\Core\Ports\MigrationLoggerInterface;
use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use ADCT\ParishIntake\Core\Ports\MigrationVersionStoreInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    /**
     * @var MigrationStepInterface[]
     */
    private array $migrations;

    /**
     * @param MigrationStepInterface[] $migrations
     */
    public function __construct(
        array $migrations,
        private MigrationVersionStoreInterface $versionStore,
        private MigrationLoggerInterface $logger
    ) {
        $versions = [];

        foreach ($migrations as $migration) {
            if (! $migration instanceof MigrationStepInterface) {
                throw new InvalidArgumentException('Every migration must implement MigrationStepInterface.');
            }

            $version = $migration->version();

            if ($version < 1 || isset($versions[$version])) {
                throw new InvalidArgumentException('Migration versions must be unique positive integers.');
            }

            $versions[$version] = $migration;
        }

        ksort($versions, SORT_NUMERIC);
        $this->migrations = array_values($versions);
    }

    public function latestVersion(): int
    {
        if ($this->migrations === []) {
            return 0;
        }

        return $this->migrations[count($this->migrations) - 1]->version();
    }

    public function run(): MigrationRunResult
    {
        try {
            $currentVersion = max(0, $this->versionStore->getVersion());
        } catch (Throwable $failure) {
            $failedVersion = $this->migrations === [] ? 0 : $this->migrations[0]->version();
            $this->logger->migrationFailed($failedVersion, $failure);

            return new MigrationRunResult(false, 0, [], $failedVersion, $failure->getMessage());
        }

        $appliedVersions = [];

        foreach ($this->migrations as $migration) {
            $version = $migration->version();

            if ($version <= $currentVersion) {
                continue;
            }

            try {
                $migration->apply();
                $versionSaved = $this->versionStore->setVersion($version);

                if (! $versionSaved && $this->versionStore->getVersion() < $version) {
                    throw new RuntimeException('Unable to save the completed database schema version.');
                }
            } catch (Throwable $failure) {
                $this->logger->migrationFailed($version, $failure);

                return new MigrationRunResult(
                    false,
                    $currentVersion,
                    $appliedVersions,
                    $version,
                    $failure->getMessage()
                );
            }

            $currentVersion = $version;
            $appliedVersions[] = $version;
        }

        $this->logger->migrationsSucceeded($currentVersion);

        return new MigrationRunResult(true, $currentVersion, $appliedVersions);
    }
}
