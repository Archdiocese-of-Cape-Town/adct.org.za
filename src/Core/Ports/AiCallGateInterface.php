<?php

namespace ADCT\ParishIntake\Core\Ports;

interface AiCallGateInterface
{
    public function reserve(): bool;

    public function backOff(int $seconds): void;
}
