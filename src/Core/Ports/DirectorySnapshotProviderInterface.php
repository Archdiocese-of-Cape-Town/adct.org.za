<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;

interface DirectorySnapshotProviderInterface
{
    public function getSnapshot(): DirectorySnapshot;
}
