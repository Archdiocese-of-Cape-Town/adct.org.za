<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Events\EventDetails;
use ADCT\ParishIntake\Core\Events\OccurrenceExpander;
use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\OccurrenceMaintenanceInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\OccurrenceRepository;
use RuntimeException;

final class WordPressEventOccurrenceMaintenance implements OccurrenceMaintenanceInterface
{
    public function __construct(
        private OccurrenceRepository $occurrences,
        private OccurrenceExpander $expander,
        private ClockInterface $clock,
        private ?\Closure $invalidateListing = null
    ) {
    }

    public function nextEventIdAfter(int $eventId): ?int
    {
        return $this->occurrences->nextPublishedEventId($eventId);
    }

    public function rebuildEvent(int $eventId, OccurrenceWindow $window, bool $transactional = true): void
    {
        if ($eventId < 1) {
            throw new \InvalidArgumentException('An event ID must be positive.');
        }

        $post = get_post($eventId);

        if (! $post instanceof \WP_Post || $post->post_type !== EventPostType::POST_TYPE) {
            $this->deleteEventOccurrences($eventId);

            return;
        }

        if ($post->post_status !== 'publish') {
            $this->deleteEventOccurrences($eventId);

            return;
        }

        $rawStart = get_post_meta($eventId, 'start_local', true);

        if ($rawStart === '' || $rawStart === null) {
            $this->deleteEventOccurrences($eventId);

            return;
        }

        if (! is_string($rawStart)) {
            throw new RuntimeException('The event start metadata is not text.');
        }

        $details = $this->detailsFromPost($eventId, $rawStart);
        $expanded = $this->expander->expand($details, $window);
        $eventTypeTermId = $this->eventTypeTermId($eventId);
        $coordinates = $this->occurrences->locationForEvent($details->parishId, $details->venueId);

        $this->occurrences->replaceForEvent(
            $eventId,
            $expanded,
            $details->parishId,
            $eventTypeTermId,
            $coordinates['latitude'],
            $coordinates['longitude'],
            $details->statusFlag === 'cancelled',
            $this->clock->now(),
            $transactional
        );
        if ($transactional && $this->invalidateListing !== null) {
            ($this->invalidateListing)();
        }
    }

    public function deleteEventOccurrences(int $eventId): void
    {
        $this->occurrences->deleteForEvent($eventId);
        ($this->invalidateListing) && ($this->invalidateListing)();
    }

    private function detailsFromPost(int $postId, string $startLocal): EventDetails
    {
        $endLocal = $this->textMeta($postId, 'end_local');
        $rrule = $this->textMeta($postId, 'rrule');
        $statusFlag = $this->textMeta($postId, 'status_flag');

        return new EventDetails(
            $this->nullableIdMeta($postId, 'parish_id'),
            $this->nullableIdMeta($postId, 'venue_id'),
            $startLocal,
            $endLocal === '' ? null : $endLocal,
            $this->booleanMeta($postId, 'all_day'),
            $rrule === '' ? null : $rrule,
            $this->dateListMeta($postId, 'exdates'),
            $this->dateListMeta($postId, 'rdates'),
            false,
            $statusFlag === '' ? 'scheduled' : $statusFlag,
            null,
            []
        );
    }

    private function nullableIdMeta(int $postId, string $metaKey): ?int
    {
        $value = get_post_meta($postId, $metaKey, true);

        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_int($value)) {
            if ($value < 0) {
                throw new RuntimeException('The event ' . $metaKey . ' metadata is invalid.');
            }

            return $value === 0 ? null : $value;
        }

        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new RuntimeException('The event ' . $metaKey . ' metadata is invalid.');
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 0,
            ],
        ]);

        if (! is_int($id)) {
            throw new RuntimeException('The event ' . $metaKey . ' metadata is invalid.');
        }

        return $id === 0 ? null : $id;
    }

    private function textMeta(int $postId, string $metaKey): string
    {
        $value = get_post_meta($postId, $metaKey, true);

        if ($value === null || $value === '') {
            return '';
        }

        if (! is_string($value)) {
            throw new RuntimeException('The event ' . $metaKey . ' metadata is not text.');
        }

        return trim($value);
    }

    private function booleanMeta(int $postId, string $metaKey): bool
    {
        $value = get_post_meta($postId, $metaKey, true);

        if (in_array($value, [true, 1, '1'], true)) {
            return true;
        }

        if (in_array($value, [false, 0, '0', '', null], true)) {
            return false;
        }

        throw new RuntimeException('The event ' . $metaKey . ' metadata is not a valid boolean.');
    }

    /**
     * @return list<string>
     */
    private function dateListMeta(int $postId, string $metaKey): array
    {
        $value = get_post_meta($postId, $metaKey, true);

        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('The event ' . $metaKey . ' metadata is not a list.');
        }

        foreach ($value as $date) {
            if (! is_string($date)) {
                throw new RuntimeException('The event ' . $metaKey . ' metadata contains a non-text value.');
            }
        }

        return $value;
    }

    private function eventTypeTermId(int $postId): ?int
    {
        $termIds = wp_get_object_terms($postId, EventPostType::TAXONOMY, [
            'fields' => 'ids',
        ]);

        if (is_wp_error($termIds)) {
            throw new RuntimeException(
                'The event type could not be read: ' . $termIds->get_error_message()
            );
        }

        if (! is_array($termIds)) {
            throw new RuntimeException('The event type query returned an invalid result.');
        }

        $normalizedIds = [];

        foreach ($termIds as $termId) {
            $id = filter_var($termId, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                ],
            ]);

            if (! is_int($id)) {
                throw new RuntimeException('The event type query returned an invalid term ID.');
            }

            $normalizedIds[] = $id;
        }

        if ($normalizedIds === []) {
            return null;
        }

        sort($normalizedIds, SORT_NUMERIC);

        return $normalizedIds[0];
    }
}
