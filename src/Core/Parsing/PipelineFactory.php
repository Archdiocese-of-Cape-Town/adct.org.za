<?php

namespace ADCT\ParishIntake\Core\Parsing;

use ADCT\ParishIntake\Core\Directory\DirectoryLookup;
use ADCT\ParishIntake\Core\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Core\Parsing\Confidence\CandidateScorer;
use ADCT\ParishIntake\Core\Parsing\Stages\AiEnrichmentStage;
use ADCT\ParishIntake\Core\Parsing\Stages\ConfidenceScoringStage;
use ADCT\ParishIntake\Core\Parsing\Stages\DirectoryLookupStage;
use ADCT\ParishIntake\Core\Parsing\Stages\EventTypeClassificationStage;
use ADCT\ParishIntake\Core\Parsing\Stages\FeaturedSuggestionStage;
use ADCT\ParishIntake\Core\Parsing\Stages\RecurrenceDetectionStage;
use ADCT\ParishIntake\Core\Parsing\Stages\RuleBasedExtractionStage;
use ADCT\ParishIntake\Core\Parsing\Stages\SourceNormalizationStage;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use ADCT\ParishIntake\Core\Ports\EventTypeKeywordProviderInterface;
use ADCT\ParishIntake\Core\Support\SystemClock;

final class PipelineFactory
{
    private ClockInterface $clock;
    private ?DirectorySnapshotProviderInterface $directorySnapshots;
    private ?EventTypeKeywordProviderInterface $eventTypeKeywords;

    public function __construct(
        ?ClockInterface $clock = null,
        ?DirectorySnapshotProviderInterface $directorySnapshots = null,
        ?EventTypeKeywordProviderInterface $eventTypeKeywords = null
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->directorySnapshots = $directorySnapshots;
        $this->eventTypeKeywords = $eventTypeKeywords;
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
        $stages = [
            new SourceNormalizationStage(),
            new RuleBasedExtractionStage($this->clock),
        ];

        if ($this->directorySnapshots !== null) {
            $stages[] = new DirectoryLookupStage(new DirectoryLookup($this->directorySnapshots));
        }

        $stages[] = new RecurrenceDetectionStage($this->clock);
        $stages[] = new FeaturedSuggestionStage();

                $reviewThreshold = self::thresholdOption($options, 'confidence_threshold');
                        $fieldThreshold = self::thresholdOption($options, 'field_confidence_threshold');

                        // First pass: score what the rule-based parser found. AI enrichment reads this score, so
                        // the ai_threshold keeps the meaning it already had.
                        $stages[] = new ConfidenceScoringStage(new CandidateScorer(), $reviewThreshold, $fieldThreshold);
                        $stages[] = new AiEnrichmentStage($provider);
                        $stages[] = new EventTypeClassificationStage(new EventTypeClassifier(
                            $this->eventTypeKeywords?->keywordLists() ?? EventTypeClassifier::DEFAULT_KEYWORDS
                        ));

                        // Second pass: AI-supplied fields and the classified event type both arrive after the
                        // first pass, and the overall score is derived from the per-field scores, so the
                        // candidate has to be scored again once they exist.
                        $stages[] = new ConfidenceScoringStage(new CandidateScorer(), $reviewThreshold, $fieldThreshold);

        return new Pipeline($stages, $context, new BulletinBlockSplitter(null, new SectionSkipper($sectionKeywords)));
    }

    /**
     * Review thresholds are configured in 0..1. Anything missing or unusable falls back to the
     * stage default rather than silently disabling review.
     */
    private static function thresholdOption(array $options, string $key): ?float
    {
        if (! array_key_exists($key, $options) || ! is_numeric($options[$key])) {
            return null;
        }

        return (float) $options[$key];
    }
}
