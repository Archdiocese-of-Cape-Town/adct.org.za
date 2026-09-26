<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface ActionTokenRateLimitKeyProviderInterface
{
    public function getKey(): string;
}
