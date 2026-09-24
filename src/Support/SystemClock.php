<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Support;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg'));
    }
}
