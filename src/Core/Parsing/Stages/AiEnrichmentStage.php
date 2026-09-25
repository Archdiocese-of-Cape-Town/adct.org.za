<?php

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Ai\AiRequestFailure;
use ADCT\ParishIntake\Core\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use ADCT\ParishIntake\Core\Ports\AiProvenanceInterface;
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

        if (! $enabled || $result->getConfidence() >= $threshold) {
            return $result;
        }
        if (! $this->provider->isAvailable()) {
            if (! $this->provider instanceof NullAiProvider) {
                $context->addError('AI provider is unavailable; local parsing retained.');
            }
            return $result;
        }

        try {
            $fields = $this->provider->enrich($message, $result);

            if ($fields !== []) {
                $filled = array_keys(array_filter(
                    $fields,
                    static fn (mixed $value, string $key): bool => ($result->getField($key) === null || $result->getField($key) === '') && is_string($value) && trim($value) !== '',
                    ARRAY_FILTER_USE_BOTH
                ));
                $result->mergeFields($fields, true);
                if ($filled !== []) {
                    $result->markAiUsed(
                        $this->provider->name(),
                        $this->provider instanceof AiProvenanceInterface ? $this->provider->model() : null,
                        $filled
                    );
                    $result->addStrategy('ai_enrichment');
                    $context->addNote('AI enrichment applied as a low-confidence fallback.');
                }
            }
        } catch (Throwable $exception) {
            $context->addError('AI enrichment failed: ' . ($exception instanceof AiRequestFailure
                ? $exception->getMessage()
                : 'provider request error (' . get_class($exception) . '); local parsing retained.'));
        }

        return $result;
    }
}
