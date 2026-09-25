<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Directory;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use RuntimeException;

final class CachedDirectorySnapshotProvider implements DirectorySnapshotProviderInterface
{
    private ?int $loadedVersion = null;
    private ?DirectorySnapshot $loadedSnapshot = null;

    public function __construct(
        private DirectoryVersionStoreInterface $versions,
        private DirectorySnapshotCacheInterface $cache,
        private DirectorySnapshotLoaderInterface $loader
    ) {
    }

    public function getSnapshot(): DirectorySnapshot
    {
        $version = $this->versions->current();

        if ($version < 0) {
            throw new RuntimeException('The directory version cannot be negative.');
        }

        if ($this->loadedVersion === $version && $this->loadedSnapshot !== null) {
            return $this->loadedSnapshot;
        }

        $snapshot = $this->cache->get($version);

        if ($snapshot === null) {
            $snapshot = $this->loader->load()->withVersion($version);
            $this->cache->put($snapshot);
        } elseif ($snapshot->version !== $version) {
            throw new RuntimeException('The cached directory snapshot version does not match its cache key.');
        }

        $this->loadedVersion = $version;
        $this->loadedSnapshot = $snapshot;

        return $snapshot;
    }
}
