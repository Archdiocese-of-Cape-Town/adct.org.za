<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

final readonly class InboundHeaderBlock
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public array $headers,
        public ?string $replyToEmail,
        public ?string $messageId,
        public AutomatedMailAssessment $automatedAssessment
    ) {
    }
}
