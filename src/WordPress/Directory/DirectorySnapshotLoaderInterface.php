<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Directory;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;

interface DirectorySnapshotLoaderInterface
{
    public function load(): DirectorySnapshot;
}
