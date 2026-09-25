<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use RuntimeException;
use Throwable;

final class InboundMessageProcessingFailure extends RuntimeException
{
    public const CONTEXT_SENDER_LOOKUP = 'sender_lookup';
    public const CONTEXT_BLOCKED_MESSAGE_TRANSITION = 'blocked_message_transition';
    public const CONTEXT_RAW_MESSAGE_STORAGE = 'raw_message_storage';
    public const CONTEXT_MIME_DECODE = 'mime_decode';
    public const CONTEXT_MESSAGE_METADATA = 'message_metadata';
    public const CONTEXT_PIPELINE_PARSE = 'pipeline_parse';
    public const CONTEXT_CANDIDATE_STORAGE = 'candidate_storage';
    public const CONTEXT_PROCESSING = 'processing';

    public function __construct(
        public readonly string $operatorMessage,
        public readonly string $diagnosticContext,
        ?Throwable $previous = null
    ) {
        parent::__construct($operatorMessage, 0, $previous);
    }
}
