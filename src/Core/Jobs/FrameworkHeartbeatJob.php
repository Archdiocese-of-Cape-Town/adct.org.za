<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

final class FrameworkHeartbeatJob extends AbstractJob
{
    public function __construct()
    {
        parent::__construct('framework_heartbeat', 'Framework heartbeat (no work configured)', 600);
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        return null;
    }
}
