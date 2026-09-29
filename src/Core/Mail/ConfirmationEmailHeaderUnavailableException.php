<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ConfirmationEmailHeaderUnavailableException extends RuntimeException
{
    public readonly int $messageId;

    public function __construct(int $messageId, Throwable $previous)
    {
        if ($messageId < 1) {
            throw new InvalidArgumentException('A confirmation header failure needs a valid inbound message ID.');
        }

        $this->messageId = $messageId;

        parent::__construct(
            'The stored inbound message headers are permanently unavailable for confirmation processing.',
            0,
            $previous
        );
    }
}
