<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

interface JobRunLifecycleInterface
{
    public function beginRun(): void;
}
