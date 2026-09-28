<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Support;

use ADCT\ParishIntake\Core\Ports\StopwatchInterface;

final class SystemStopwatch implements StopwatchInterface
{
    private readonly float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function elapsedSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }
}
