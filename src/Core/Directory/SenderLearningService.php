<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use ADCT\ParishIntake\Core\Parsing\ParseOutcome;

final class SenderLearningService
{
    public function __construct(
        private ContactService $contacts
    ) {
    }

    public function learnUnknownSender(
        string $email,
        ?int $sourceParishId,
        ParseOutcome $outcome
    ): ?SenderLookupResult {
        $lookup = $this->contacts->lookup($email);

        if ($lookup->trust !== SenderTrust::UNKNOWN || $lookup->parishIds !== []) {
            return null;
        }

        $parishId = $this->guessParishId($sourceParishId, $outcome);

        if ($parishId === null) {
            return null;
        }

        return $this->contacts->linkPending($parishId, $email);
    }

    private function guessParishId(?int $sourceParishId, ParseOutcome $outcome): ?int
    {
        $parishIds = [];

        foreach ($outcome->getCandidates() as $candidate) {
            $parishId = (int) ($candidate->getField('parish_id') ?? 0);

            if ($parishId > 0) {
                $parishIds[$parishId] = true;
            }
        }

        $candidateParishIds = array_keys($parishIds);
        sort($candidateParishIds, SORT_NUMERIC);

        if ($sourceParishId !== null) {
            if ($candidateParishIds === [] || $candidateParishIds === [$sourceParishId]) {
                return $sourceParishId;
            }

            return null;
        }

        if (count($candidateParishIds) === 1) {
            return $candidateParishIds[0];
        }

        return null;
    }
}
