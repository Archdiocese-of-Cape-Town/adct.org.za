<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface InboundMessageProcessingFailureLoggerInterface
{
    public function logFailure(int $messageId, string $context, string $failureClass): void;
}
