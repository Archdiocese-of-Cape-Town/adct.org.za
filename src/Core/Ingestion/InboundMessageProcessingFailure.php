<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use RuntimeException;
use Throwable;

final class InboundMessageProcessingFailure extends RuntimeException
{
    public function __construct(
        public readonly string $operatorMessage,
        ?Throwable $previous = null
    ) {
        parent::__construct($operatorMessage, 0, $previous);
    }
}
