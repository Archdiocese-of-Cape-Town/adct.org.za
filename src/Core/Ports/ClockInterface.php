<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
