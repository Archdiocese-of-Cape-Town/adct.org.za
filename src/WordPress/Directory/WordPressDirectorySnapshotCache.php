<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Directory;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use InvalidArgumentException;
use RuntimeException;

final class WordPressDirectorySnapshotCache implements DirectorySnapshotCacheInterface
{
    private const TRANSIENT_PREFIX = 'adct_pi_directory_snapshot_v';
    private const TTL_SECONDS = 43200;

    public function get(int $version): ?DirectorySnapshot
    {
        $this->assertVersion($version);
        $cached = get_transient(self::TRANSIENT_PREFIX . $version);

        if ($cached === false) {
            return null;
        }

        if (! is_array($cached)) {
            throw new RuntimeException('The cached directory snapshot is not an array.');
        }

        $snapshot = DirectorySnapshot::fromArray($cached);

        if ($snapshot->version !== $version) {
            throw new RuntimeException('The cached directory snapshot has an unexpected version.');
        }

        return $snapshot;
    }

    public function put(DirectorySnapshot $snapshot): void
    {
        $this->assertVersion($snapshot->version);

        $stored = set_transient(
            self::TRANSIENT_PREFIX . $snapshot->version,
            $snapshot->toArray(),
            self::TTL_SECONDS
        );

        if (! $stored) {
            $existing = $this->get($snapshot->version);

            if ($existing === null || $existing->toArray() !== $snapshot->toArray()) {
                throw new RuntimeException('The directory snapshot could not be cached.');
            }
        }
    }

    private function assertVersion(int $version): void
    {
        if ($version < 0) {
            throw new InvalidArgumentException('A directory snapshot version cannot be negative.');
        }
    }
}
