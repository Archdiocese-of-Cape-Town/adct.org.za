<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Jobs\JobState;

interface JobStateStoreInterface
{
    public function load(string $jobId): JobState;

    public function save(string $jobId, JobState $state): void;
}
