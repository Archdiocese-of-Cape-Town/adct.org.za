<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Directory\DirectoryLookup;
use ADCT\ParishIntake\Core\Directory\ParishMatch;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Directory\VenueMatch;
use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;

final class DirectoryLookupStage implements StageInterface
{
    public function __construct(private DirectoryLookup $directory)
    {
    }

    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $sender = null;
        $senderEmail = trim($message->getSenderEmail());

        if ($senderEmail !== '' && filter_var($senderEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $sender = $this->directory->lookupSender($senderEmail);
        }

        if ($sender !== null && $sender->trust === SenderTrust::BLOCKED) {
            return $result;
        }

        $parishMatch = null;
        $parishSource = null;
        $parishConfidence = null;
        $verifiedSingleParishId = null;

        if ($sender !== null && $sender->trust === SenderTrust::VERIFIED && count($sender->parishIds) === 1) {
            $verifiedSingleParishId = $sender->parishIds[0];
            $parishMatch = $this->directory->parishById($verifiedSingleParishId);

            if ($parishMatch !== null) {
                $parishSource = 'sender';
                $parishConfidence = 1.0;
            } else {
                $result->addNote('The verified sender parish was not present in the directory snapshot.');
            }
        }

        if ($parishMatch === null) {
            $eligibleParishIds = $sender !== null
                && $sender->trust === SenderTrust::VERIFIED
                && count($sender->parishIds) > 1
                    ? $sender->parishIds
                    : null;
            [$parishMatch, $parishSource, $parishConfidence, $lookupNotes] = $this->textParishMatch(
                $result,
                $context,
                $eligibleParishIds
            );

            foreach ($lookupNotes as $note) {
                $result->addNote($note);
            }

            if ($parishMatch === null && $eligibleParishIds !== null) {
                $result->addNote(
                    'The verified sender is linked to multiple parishes, but the message did not identify one; parish was left unresolved.'
                );
            }
        }

        if (
            $parishMatch !== null
            && $parishSource === 'text'
            && $parishMatch->churchNameOnly
        ) {
            $fullTextMatch = $this->directory
                ->matchParish($result->getNormalizedText(), $eligibleParishIds)
                ->match;

            if (
                $fullTextMatch !== null
                && $fullTextMatch->parishId === $parishMatch->parishId
                && ! $fullTextMatch->churchNameOnly
            ) {
                $parishMatch = $fullTextMatch;
                $parishConfidence = $fullTextMatch->confidence;
            }
        }

        if (
            $parishMatch !== null
            && $parishSource === 'text'
            && $parishMatch->churchNameOnly
            && ! (
                $sender !== null
                && $sender->trust === SenderTrust::VERIFIED
                && in_array($parishMatch->parishId, $sender->parishIds, true)
            )
        ) {
            $parishConfidence = 0.6;
        }

        if ($parishMatch !== null && $parishSource !== null && $parishConfidence !== null) {
            $this->applyParishMatch($result, $parishMatch, $parishSource, $parishConfidence);
        } elseif ($parishMatch === null) {
            $result->addNote('No directory parish match was found; extracted parish text was left unchanged.');
        }

        $parishId = $parishMatch?->parishId;
        $venueSource = $context->getRuntimeValue('venue_source');
        $venueSource = is_string($venueSource) ? $venueSource : 'text';
        $venueText = $result->getField('venue');
        $venueText = is_string($venueText) && trim($venueText) !== '' ? trim($venueText) : null;
        $venueWasLabelled = in_array($venueSource, ['label', 'context'], true);

        if ($venueText !== null) {
            $venueResult = $this->directory->lookupVenue($venueText, $parishId);

            if ($venueResult->match !== null) {
                $this->applyVenueMatch(
                    $result,
                    $venueResult->match,
                    $venueWasLabelled ? 'label' : 'text',
                    $venueWasLabelled ? 0.98 : 0.9,
                    false
                );
                $this->addVenueLookupNotes($result, $venueResult->notes);

                return $result;
            }

            $this->addVenueLookupNotes($result, $venueResult->notes);

            if ($venueWasLabelled) {
                $result->addNote('The labelled venue did not match an active directory venue; the supplied venue text was retained.');

                return $result;
            }
        }

        $textVenueResult = $this->directory->lookupVenue($result->getNormalizedText(), $parishId);

        if ($textVenueResult->match !== null) {
            $this->applyVenueMatch(
                $result,
                $textVenueResult->match,
                'text',
                0.9,
                $venueText === null
            );
            $this->addVenueLookupNotes($result, $textVenueResult->notes);
        } elseif (
            $verifiedSingleParishId !== null
            && $parishMatch !== null
            && $parishSource === 'sender'
        ) {
            $defaultVenue = $this->directory->defaultVenueFor($verifiedSingleParishId);

            if ($defaultVenue !== null) {
                if ($venueText !== null) {
                    $result->setField('venue_text', $venueText);
                }

                $result->setField('venue', $defaultVenue->name);
                $this->applyVenueMatch($result, $defaultVenue, 'default', 1.0, true);
            } else {
                $result->addNote('No active default venue was found for the verified sender parish.');
            }
        } elseif ($venueText === null) {
            $result->addNote('No directory venue match was found; venue was left unresolved.');
        }

        return $result;
    }

    /**
     * @param list<int>|null $eligibleParishIds
     * @return array{0: ?ParishMatch, 1: ?string, 2: ?float, 3: list<string>}
     */
    private function textParishMatch(
        ParseResult $result,
        ParseContext $context,
        ?array $eligibleParishIds
    ): array {
        $blockContext = $context->getRuntimeValue('block_context', []);
        $blockContext = is_array($blockContext) ? $blockContext : [];
        $attempts = [];
        $contextParish = $blockContext['parish_name'] ?? null;

        if (is_string($contextParish) && trim($contextParish) !== '') {
            $attempts[] = [trim($contextParish), 'context'];
        }

        $fieldParish = $result->getField('parish_name');

        if (
            is_string($fieldParish)
            && trim($fieldParish) !== ''
            && (! is_string($contextParish) || trim($contextParish) !== trim($fieldParish))
        ) {
            $attempts[] = [trim($fieldParish), 'text'];
        }

        $normalizedText = $result->getNormalizedText();

        if ($normalizedText !== '') {
            $attempts[] = [$normalizedText, 'text'];
        }

        $notes = [];

        foreach ($attempts as [$text, $source]) {
            $lookup = $this->directory->matchParish($text, $eligibleParishIds);

            if ($lookup->match !== null) {
                return [
                    $lookup->match,
                    $source,
                    $source === 'context' ? 0.95 : $lookup->match->confidence,
                    [],
                ];
            }

            foreach ($lookup->notes as $note) {
                $notes[] = $note;
            }
        }

        return [null, null, null, array_values(array_unique($notes))];
    }

    private function applyParishMatch(
        ParseResult $result,
        ParishMatch $match,
        string $source,
        float $confidence
    ): void {
        $result->setField('parish_name', $match->name);
        $result->setField('parish_id', $match->parishId);
        $result->setField('parish_match', [
            'source' => $source,
            'confidence' => $confidence,
            'parish_id' => $match->parishId,
        ]);
        $result->addNote(sprintf(
            'parish_match: %s (confidence %.2f; parish_id %d).',
            $source,
            $confidence,
            $match->parishId
        ));
    }

    private function applyVenueMatch(
        ParseResult $result,
        VenueMatch $match,
        string $source,
        float $confidence,
        bool $setVenueName
    ): void {
        if ($setVenueName) {
            $result->setField('venue', $match->name);
        }

        $result->setField('venue_id', $match->venueId);
        $result->setField('venue_latitude', $match->latitude);
        $result->setField('venue_longitude', $match->longitude);
        $result->setField('venue_address', $match->address);
        $result->setField('venue_suburb', $match->suburb);
        $result->setField('venue_match', [
            'source' => $source,
            'confidence' => $confidence,
            'venue_id' => $match->venueId,
            'parish_id' => $match->parishId,
        ]);
        $result->addNote(sprintf(
            'venue_match: %s (confidence %.2f; venue_id %d).',
            $source,
            $confidence,
            $match->venueId
        ));
    }

    /**
     * @param list<string> $notes
     */
    private function addVenueLookupNotes(ParseResult $result, array $notes): void
    {
        foreach ($notes as $note) {
            $result->addNote($note);
        }
    }
}
