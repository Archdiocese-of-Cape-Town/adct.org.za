<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs;

use ADCT\ParishIntake\Core\Ports\InboundMessageProcessingFailureLoggerInterface;

final class WordPressInboundMessageProcessingFailureLogger implements InboundMessageProcessingFailureLoggerInterface
{
    public function logFailure(int $messageId, string $context, string $failureClass): void
    {
        error_log(sprintf(
            '[ADCT Parish Intake] Inbound message processing failed (message_id=%d context=%s exception=%s).',
            $messageId,
            $context,
            $failureClass
        ));
    }
}
