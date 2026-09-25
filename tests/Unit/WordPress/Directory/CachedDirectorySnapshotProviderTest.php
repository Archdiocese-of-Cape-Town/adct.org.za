<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Directory;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use ADCT\ParishIntake\WordPress\Directory\CachedDirectorySnapshotProvider;
use ADCT\ParishIntake\WordPress\Directory\DirectorySnapshotCacheInterface;
use ADCT\ParishIntake\WordPress\Directory\DirectorySnapshotLoaderInterface;
use ADCT\ParishIntake\WordPress\Directory\DirectoryVersionStoreInterface;
use PHPUnit\Framework\TestCase;

final class CachedDirectorySnapshotProviderTest extends TestCase
{
    public function testSnapshotIsCachedByVersionAndRebuiltAfterInvalidation(): void
    {
        $versions = new FakeDirectoryVersionStore();
        $cache = new FakeDirectorySnapshotCache();
        $loader = new FakeDirectorySnapshotLoader();
        $provider = new CachedDirectorySnapshotProvider($versions, $cache, $loader);

        $first = $provider->getSnapshot();

        self::assertSame($first, $provider->getSnapshot());
        self::assertSame(1, $loader->loads);
        self::assertSame(1, $first->version);

        $secondProvider = new CachedDirectorySnapshotProvider($versions, $cache, $loader);
        self::assertSame($first, $secondProvider->getSnapshot());
        self::assertSame(1, $loader->loads);

        $versions->bump();
        $second = $provider->getSnapshot();

        self::assertNotSame($first, $second);
        self::assertSame(2, $second->version);
        self::assertSame(2, $loader->loads);
        self::assertArrayHasKey(1, $cache->snapshots);
        self::assertArrayHasKey(2, $cache->snapshots);
    }
}

final class FakeDirectoryVersionStore implements DirectoryVersionStoreInterface
{
    private int $version = 1;

    public function current(): int
    {
        return $this->version;
    }

    public function bump(): int
    {
        return ++$this->version;
    }
}

final class FakeDirectorySnapshotCache implements DirectorySnapshotCacheInterface
{
    /** @var array<int, DirectorySnapshot> */
    public array $snapshots = [];

    public function get(int $version): ?DirectorySnapshot
    {
        return $this->snapshots[$version] ?? null;
    }

    public function put(DirectorySnapshot $snapshot): void
    {
        $this->snapshots[$snapshot->version] = $snapshot;
    }
}

final class FakeDirectorySnapshotLoader implements DirectorySnapshotLoaderInterface
{
    public int $loads = 0;

    public function load(): DirectorySnapshot
    {
        ++$this->loads;

        return new DirectorySnapshot([], [], []);
    }
}
