<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database;

use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Ports\EventCandidateStoreInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use InvalidArgumentException;
use JsonException;

final class WordPressEventCandidateStore implements EventCandidateStoreInterface
{
    public function __construct(private EventCandidateRepository $candidates)
    {
    }

    public function replaceDraftCandidatesForMessage(
        int $messageId,
        ParseOutcome $outcome,
        string $timestamp
    ): void {
        $candidates = [];

        foreach ($outcome->getCandidates() as $candidate) {
            $blockIndex = $candidate->getBlockIndex();

            if ($blockIndex === null) {
                throw new InvalidArgumentException('An event candidate has no source block index.');
            }

            $fields = $candidate->fields();
            $fields['source_snippet'] = $candidate->getSourceSnippet();
            $fields['reprocess_needed'] = $candidate->needsReprocess();
            $recurrence = $candidate->getRecurrence();

            $candidates[] = [
                'block_index' => $blockIndex,
                'parish_id' => $this->nullablePositiveId($candidate->getField('parish_id')),
                'fields' => $this->encode($fields),
                'recurrence' => $recurrence === [] ? null : $this->encode($recurrence),
                'confidence' => $candidate->getConfidence(),
                'parser_version' => $candidate->getParserVersion(),
                'strategies' => $this->encode($candidate->getStrategies()),
                'notes' => $this->encode($candidate->getNotes()),
                'ai_used' => $candidate->usedAi() ? 1 : 0,
                'ai_provider' => $candidate->getAiProvider(),
                'ai_model' => null,
            ];
        }

        $this->candidates->replaceDraftCandidatesForMessage($messageId, $candidates, $timestamp);
    }

    public function discardDraftCandidatesForMessage(int $messageId, string $timestamp): void
    {
        $this->candidates->replaceDraftCandidatesForMessage($messageId, [], $timestamp);
    }

    /**
     * @throws JsonException
     */
    private function encode(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            throw new InvalidArgumentException('An event candidate parish ID must be positive.');
        }

        return $id;
    }
}
