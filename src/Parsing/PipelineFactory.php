<?php

namespace ADCT\ParishIntake\Parsing;

use ADCT\ParishIntake\Parsing\Ai\AiProviderInterface;
use ADCT\ParishIntake\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Parsing\Stages\AiEnrichmentStage;
use ADCT\ParishIntake\Parsing\Stages\ConfidenceScoringStage;
use ADCT\ParishIntake\Parsing\Stages\RecurrenceDetectionStage;
use ADCT\ParishIntake\Parsing\Stages\RuleBasedExtractionStage;
use ADCT\ParishIntake\Parsing\Stages\SourceNormalizationStage;
use ADCT\ParishIntake\Support\ClockInterface;
use ADCT\ParishIntake\Support\SystemClock;

final class PipelineFactory
{
    private ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function create(array $options = []): Pipeline
    {
        $provider = $options['ai_provider'] ?? new NullAiProvider();

        if (! $provider instanceof AiProviderInterface) {
            $provider = new NullAiProvider();
        }

        $context = new ParseContext([
            'parser_version' => $options['parser_version'] ?? '0.1.0',
            'ai_threshold' => (float) ($options['ai_threshold'] ?? 0.55),
            'ai_enabled' => (bool) ($options['ai_enabled'] ?? false),
        ]);

        return new Pipeline([
            new SourceNormalizationStage(),
            new RuleBasedExtractionStage($this->clock),
            new RecurrenceDetectionStage(),
            new ConfidenceScoringStage(),
            new AiEnrichmentStage($provider),
        ], $context);
    }
}
