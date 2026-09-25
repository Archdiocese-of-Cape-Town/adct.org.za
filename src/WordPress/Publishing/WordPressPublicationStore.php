<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Publishing;

use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\PublicationStoreInterface;
use ADCT\ParishIntake\Core\Publishing\Publication;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use ADCT\ParishIntake\WordPress\Events\EventOccurrenceHooks;
use ADCT\ParishIntake\WordPress\Events\EventListingGeneration;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use ADCT\ParishIntake\WordPress\Events\WordPressEventOccurrenceMaintenance;
use DateTimeZone;
use DomainException;
use RuntimeException;
use Throwable;

final class WordPressPublicationStore implements PublicationStoreInterface
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private EventCandidateRepository $candidates,
        private WordPressEventOccurrenceMaintenance $occurrences,
        private EventListingGeneration $listingGeneration,
        private ClockInterface $clock,
        private DateTimeZone $timezone
    ) {
    }

    public function publish(int $candidateId, callable $prepare): int
    {
        $this->execute('START TRANSACTION');
        $eventId = null;
        $committed = false;

        try {
            $table = $this->database->prefix() . 'adct_pi_event_candidates';
            $row = $this->row($this->database->prepare(
                "SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
                $candidateId
            ));
            if ($row === null) {
                throw new DomainException('The candidate does not exist.');
            }

            $publication = $prepare($row);
            if (! $publication instanceof Publication || $publication->candidateId !== $candidateId) {
                throw new RuntimeException('The publication policy returned a different candidate.');
            }
            $eventId = $publication->eventId;
            if ($eventId !== null) {
                $posts = $this->database->prefix() . 'posts';
                $lockedEvent = $this->row($this->database->prepare(
                    "SELECT ID FROM {$posts} WHERE ID = %d FOR UPDATE",
                    $eventId
                ));
                if ($lockedEvent === null) {
                    throw new DomainException('The matched event no longer exists.');
                }
                clean_post_cache($eventId);
            }

            if ($row['status'] === 'published') {
                if ($eventId !== null && (int) get_post_meta($eventId, 'source_candidate_id', true) === $candidateId) {
                    $this->execute('COMMIT');
                    $committed = true;
                    return $this->afterCommit($eventId);
                }
                throw new DomainException('The candidate was already published to another event.');
            }

            $before = null;
            $previousCandidateId = null;
            if ($eventId !== null) {
                $post = get_post($eventId);
                if (! $post instanceof \WP_Post
                    || $post->post_type !== EventPostType::POST_TYPE
                    || $post->post_status !== 'publish') {
                    throw new DomainException('The matched event is not a published adct_event.');
                }
                $before = $this->snapshot($eventId);
                $previousCandidateId = (int) ($before['meta']['source_candidate_id'] ?? 0);
                if ($previousCandidateId > $candidateId) {
                    throw new DomainException('A newer candidate has already updated this event.');
                }
            }

            EventOccurrenceHooks::setPublishingCandidate(true);
            try {
                $postData = [
                    'post_type' => EventPostType::POST_TYPE,
                    'post_status' => 'publish',
                    'post_title' => $publication->title,
                    'post_content' => $publication->description,
                ];
                if ($eventId !== null) {
                    $postData['ID'] = $eventId;
                }
                $saved = wp_insert_post($postData, true);
                if (is_wp_error($saved) || ! is_int($saved) || $saved < 1) {
                    throw new RuntimeException('The event post could not be saved: '
                        . (is_wp_error($saved) ? $saved->get_error_message() : 'invalid post ID'));
                }
                $eventId = $saved;

                if ($publication->eventType !== null) {
                    $term = get_term_by('slug', $publication->eventType, EventPostType::TAXONOMY);
                    if (! $term instanceof \WP_Term) {
                        $term = get_term_by('name', $publication->eventType, EventPostType::TAXONOMY);
                    }
                    if (! $term instanceof \WP_Term) {
                        throw new DomainException('The candidate event type does not exist.');
                    }
                    $assigned = wp_set_object_terms($eventId, [$term->term_id], EventPostType::TAXONOMY);
                    if (is_wp_error($assigned) || ! is_array($assigned)) {
                        throw new RuntimeException('The event type could not be assigned'
                            . (is_wp_error($assigned) ? ': ' . $assigned->get_error_message() : '.'));
                    }
                }

                $details = $publication->details;
                $meta = [
                    'parish_id' => $details->parishId ?? 0,
                    'venue_id' => $details->venueId ?? 0,
                    'start_local' => $details->startLocal,
                    'end_local' => $details->endLocal ?? '',
                    'all_day' => $details->allDay,
                    'rrule' => $details->rrule ?? '',
                    'exdates' => $details->exdates,
                    'rdates' => $details->rdates,
                    'featured' => $details->featured,
                    'status_flag' => $details->statusFlag,
                    'source_candidate_id' => $candidateId,
                    'contact' => $details->contact,
                ];
                foreach ($meta as $key => $value) {
                    update_post_meta($eventId, $key, $value);
                    if (get_post_meta($eventId, $key, true) != $value) {
                        throw new RuntimeException('The event ' . $key . ' metadata could not be saved.');
                    }
                }

                $this->occurrences->rebuildEvent(
                    $eventId,
                    OccurrenceWindow::rollingTwelveMonths($this->clock->now(), $this->timezone),
                    false
                );
            } finally {
                EventOccurrenceHooks::setPublishingCandidate(false);
            }

            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            if ($before !== null) {
                $changes = $this->database->prefix() . 'adct_pi_event_changes';
                $after = $this->snapshot($eventId);
                $kind = match ($publication->kind) {
                    'cancellation' => 'cancel',
                    'postponement' => 'postpone',
                    default => 'update',
                };
                $this->execute($this->database->prepare(
                    "INSERT INTO {$changes} "
                    . '(event_id,candidate_id,actor,kind,before_payload,after_payload,created_at,updated_at) '
                    . 'VALUES (%d,%d,%s,%s,%s,%s,%s,%s)',
                    $eventId,
                    $candidateId,
                    $publication->actor,
                    $kind,
                    $this->encode($before),
                    $this->encode($after),
                    $now,
                    $now
                ));
            }

            if ($this->candidates->update($candidateId, [
                'status' => 'published',
                'match_event_id' => $eventId,
                'updated_at' => $now,
            ]) !== 1) {
                throw new RuntimeException('The candidate publication state could not be saved.');
            }
            if ($previousCandidateId !== null && $previousCandidateId > 0 && $previousCandidateId !== $candidateId) {
                $this->execute($this->database->prepare(
                    "UPDATE {$table} SET status = %s, updated_at = %s "
                    . 'WHERE id = %d AND status = %s AND match_event_id = %d',
                    'superseded',
                    $now,
                    $previousCandidateId,
                    'published',
                    $eventId
                ));
            }
            $this->execute('COMMIT');
            $committed = true;
            return $this->afterCommit($eventId);
        } catch (Throwable $failure) {
            if ($committed) {
                throw new RuntimeException(
                    'Event ' . $eventId . ' was committed, but its listing cache could not be refreshed. '
                    . 'Retry publishing candidate ' . $candidateId . ' to repair the cache generation.',
                    0,
                    $failure
                );
            }
            try {
                $this->execute('ROLLBACK');
            } catch (Throwable $rollbackFailure) {
                throw new RuntimeException(
                    'Publication failed and its transaction could not be rolled back: '
                    . $rollbackFailure->getMessage(),
                    0,
                    $failure
                );
            } finally {
                if ($eventId !== null) {
                    clean_post_cache($eventId);
                }
            }
            throw $failure;
        }
    }

    private function afterCommit(int $eventId): int
    {
        clean_post_cache($eventId);
        $this->listingGeneration->bump();
        return $eventId;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $eventId): array
    {
        $post = get_post($eventId);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('The event could not be read for change history.');
        }
        $meta = [];
        foreach ([
            'parish_id', 'venue_id', 'start_local', 'end_local', 'all_day', 'rrule',
            'exdates', 'rdates', 'featured', 'status_flag', 'source_candidate_id', 'contact',
        ] as $key) {
            $meta[$key] = get_post_meta($eventId, $key, true);
        }
        $terms = wp_get_object_terms($eventId, EventPostType::TAXONOMY, ['fields' => 'ids']);
        if (is_wp_error($terms) || ! is_array($terms)) {
            throw new RuntimeException('The event type could not be read for change history: '
                . (is_wp_error($terms) ? $terms->get_error_message() : 'invalid result'));
        }
        return [
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'status' => $post->post_status,
            'event_type_term_ids' => array_map('intval', $terms),
            'featured_image_id' => get_post_thumbnail_id($eventId),
            'meta' => $meta,
        ];
    }

    private function encode(array $snapshot): string
    {
        return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $sql): ?array
    {
        $this->database->clearLastError();
        $row = $this->database->getRow($sql);
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The publication database read failed: ' . $this->database->lastError());
        }
        return $row;
    }

    private function execute(string $sql): int
    {
        $this->database->clearLastError();
        $result = $this->database->query($sql);
        if ($result === false) {
            throw new RuntimeException('The publication database write failed: ' . $this->database->lastError());
        }
        return $result;
    }
}
