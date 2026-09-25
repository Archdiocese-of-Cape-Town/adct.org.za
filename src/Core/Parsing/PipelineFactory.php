<?php

namespace ADCT\ParishIntake\Core\Parsing;

use ADCT\ParishIntake\Core\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Core\Parsing\Stages\AiEnrichmentStage;
use ADCT\ParishIntake\Core\Parsing\Stages\ConfidenceScoringStage;
use ADCT\ParishIntake\Core\Parsing\Stages\RecurrenceDetectionStage;
use ADCT\ParishIntake\Core\Parsing\Stages\RuleBasedExtractionStage;
use ADCT\ParishIntake\Core\Parsing\Stages\SourceNormalizationStage;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Support\SystemClock;

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
        $sectionKeywords = $options['section_keywords'] ?? null;

        if (! $provider instanceof AiProviderInterface) {
            $provider = new NullAiProvider();
        }

        if (! is_array($sectionKeywords)) {
            $sectionKeywords = null;
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
        ], $context, new BulletinBlockSplitter(null, new SectionSkipper($sectionKeywords)));
    }
}
