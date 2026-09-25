<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Directory;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;

interface DirectorySnapshotCacheInterface
{
    public function get(int $version): ?DirectorySnapshot;

    public function put(DirectorySnapshot $snapshot): void;
}
