<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface MailQueueImmediateDispatchInterface
{
    public function dispatchImmediately(): void;
}
