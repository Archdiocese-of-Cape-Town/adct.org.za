<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Support;

use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg'));
    }
}
