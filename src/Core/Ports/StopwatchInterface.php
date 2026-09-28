<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

/**
 * Elapsed time, for enforcing a budget.
 *
 * Separate from {@see ClockInterface} because that returns a wall-clock
 * datetime, which can jump when the host adjusts its time. A time budget needs
 * a monotonic reading, and a test needs to be able to fake one instead of
 * sleeping.
 */
interface StopwatchInterface
{
    /**
     * Seconds elapsed since this stopwatch was started.
     */
    public function elapsedSeconds(): float;
}
