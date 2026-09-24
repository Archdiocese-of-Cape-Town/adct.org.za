<?php

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use Throwable;

final class AiEnrichmentStage implements StageInterface
{
    private AiProviderInterface $provider;

    public function __construct(AiProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $enabled = (bool) $context->getOption('ai_enabled', false);
        $threshold = (float) $context->getOption('ai_threshold', 0.55);

        if (! $enabled || $result->getConfidence() >= $threshold || ! $this->provider->isAvailable()) {
            return $result;
        }

        try {
            $fields = $this->provider->enrich($message, $result);

            if ($fields !== []) {
                $result->mergeFields($fields, true);
                $result->markAiUsed($this->provider->name());
                $result->addStrategy('ai_enrichment');
                $context->addNote('AI enrichment applied as a low-confidence fallback.');
            }
        } catch (Throwable $exception) {
            $context->addError('AI enrichment failed: ' . $exception->getMessage());
        }

        return $result;
    }
}
