<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Support;

use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Matching\EventNoticeMatcher;
use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\Core\Publishing\Publication;
use DateTimeZone;
use RuntimeException;

final class EmailFixtureResult
{
    public static function actual(array $expected, ParseOutcome $outcome): array
    {
        $candidateLevel = $outcome->getPrimaryResult()->toArray();
        $actual = array_merge($candidateLevel, $outcome->toArray());

        // `notes` stays the outcome's list, which is what it has always meant here,
        // and what the existing fixtures pin. The candidate carries its own notes --
        // `unparsed_date_candidate: 32 October` and its siblings -- and merging the
        // two arrays let the outcome's key win outright. When it was empty, which it
        // is for any clean parse, a fixture saw no notes at all and could assert
        // nothing about them: that is how an unreadable date was invisible to this
        // corpus when #167 asked for it to be visible. Rather than fold two lists
        // that genuinely mean different things into one, the candidate's notes are
        // exposed under their own key.
        $actual['candidate_notes'] = $candidateLevel['notes'];

            if (! array_key_exists('existing_event', $expected)) {
            if (array_key_exists('match_kind', $expected) || array_key_exists('event_status', $expected)) {
                throw new RuntimeException('Match and event status assertions require existing_event context.');
            }

            return $actual;
        }

        $prior = $expected['existing_event'];
        if (
            ! is_array($prior)
            || ! is_int($prior['id'] ?? null)
            || $prior['id'] < 1
            || ! is_int($prior['parish_id'] ?? null)
            || $prior['parish_id'] < 1
            || ! is_array($prior['fields'] ?? null)
            || (isset($prior['recurrence']) && ! is_array($prior['recurrence']))
            || count($outcome->getCandidates()) !== 1
        ) {
            throw new RuntimeException('An existing_event fixture needs an ID, parish ID, fields and one candidate.');
        }

        $candidate = $outcome->getCandidates()[0];
        $fields = $candidate->fields();
        $fields['source_snippet'] = $candidate->getSourceSnippet();
        $match = (new EventNoticeMatcher())->match([
            'parish_id' => $fields['parish_id'] ?? null,
            'fields' => $fields,
            'recurrence' => $candidate->getRecurrence(),
        ], [[
            'id' => $prior['id'],
            'event_id' => $prior['id'],
            'parish_id' => $prior['parish_id'],
            'fields' => $prior['fields'],
            'recurrence' => $prior['recurrence'] ?? [],
        ]]);
        $actual['match_kind'] = $match['kind'];
        $actual['event_status'] = null;

        if ($match['event_id'] !== null && $match['note'] === ''
            && in_array($match['kind'], ['update', 'cancellation', 'postponement'], true)) {
            $store = new FixturePublicationStore([
                'id' => 1,
                'status' => 'approved',
                'approved_via' => 'reviewer',
                'approved_by' => 'reviewer@example.test',
                'approved_at' => '2026-10-01 09:00:00',
                'match_kind' => $match['kind'],
                'match_event_id' => $match['event_id'],
                'parish_id' => $fields['parish_id'] ?? null,
                'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                'recurrence' => $candidate->getRecurrence() === []
                    ? '{}' : json_encode($candidate->getRecurrence(), JSON_THROW_ON_ERROR),
            ]);
            (new CandidatePublisher(
                $store,
                new EventValidator(new DateTimeZone('Africa/Johannesburg'))
            ))->publish(1);
            $actual['event_status'] = $store->publication?->details->statusFlag;
        }

        return $actual;
    }
}

final class FixturePublicationStore implements PublicationStoreInterface
{
    public ?Publication $publication = null;

    public function __construct(private array $candidate)
    {
    }

    public function publish(int $candidateId, callable $prepare): int
    {
        $this->publication = $prepare($this->candidate);

        return $this->publication->eventId
            ?? throw new RuntimeException('A change fixture must target a published event.');
    }
}
