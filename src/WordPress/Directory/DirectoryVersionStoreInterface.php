<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Directory;

interface DirectoryVersionStoreInterface
{
    public function current(): int;

    public function bump(): int;
}
