<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Support;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
