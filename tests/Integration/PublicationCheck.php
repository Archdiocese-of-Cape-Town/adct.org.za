<?php

declare(strict_types=1);

use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Plugin;

final class PublicationCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;

        $candidates = new EventCandidateRepository(new WordPressDatabaseConnection());
        $publisher = Plugin::candidatePublisher();
        $date = (new DateTimeImmutable('tomorrow', new DateTimeZone('Africa/Johannesburg')))
            ->format('Y-m-d');
        $now = gmdate('Y-m-d H:i:s');
        $candidateIds = [];
        $eventId = null;
        $make = static function (
            string $kind,
            ?int $match,
            ?string $via = 'reviewer',
            string $title = 'Sample parish event',
            ?string $eventType = 'social'
        ) use (
            $candidates, $date, $now, &$candidateIds
        ): int {
            $fields = [
                'title' => $title,
                'description' => 'Synthetic details only.',
                'event_date' => $date,
                'event_time' => '09:00',
                'event_end_time' => '10:00',
            ];
            if ($eventType !== null) {
                $fields['event_type'] = $eventType;
            }
            $id = $candidates->insert([
                'block_index' => 0,
                'fields' => wp_json_encode($fields),
                'recurrence' => '{}',
                'match_kind' => $kind,
                'match_event_id' => $match,
                'status' => 'awaiting_approval',
                'approved_by' => $via === null ? null : 'reviewer@example.test',
                'approved_at' => $via === null ? null : $now,
                'approved_via' => $via,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $candidateIds[] = $id;
            return $id;
        };
        $changes = $wpdb->prefix . 'adct_pi_event_changes';
        $occurrences = $wpdb->prefix . 'adct_pi_occurrences';
        $count = static fn (string $table, int $id): int => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $id
        ));

        try {
            $unapproved = $make('new', null, null);
            try {
                $publisher->publish($unapproved);
                $fail('A confirmed but unapproved candidate was published.');
            } catch (DomainException $expected) {
                // The trust boundary is intentionally closed.
            }
            $contact = $make('update', 12345, 'contact_change');
            try {
                $publisher->publish($contact);
                $fail('A caller-set contact_change label bypassed verification.');
            } catch (DomainException $expected) {
            }

            $create = $make('new', null);
            $stale = $make('update', null);
            $eventId = $publisher->publish($create);
            $candidates->update($stale, ['match_event_id' => $eventId]);
            $newer = $make('update', $eventId);
            if (
                get_post($eventId)?->post_status !== 'publish'
                || get_post_meta($eventId, 'source_candidate_id', true) != $create
                || $count($occurrences, $eventId) !== 1
                || ! has_term('social', 'adct_event_type', $eventId)
                || $publisher->publish($create) !== $eventId
                || $count($changes, $eventId) !== 0
            ) {
                $fail('Creation did not publish exactly once with a linked candidate and occurrence.');
            }
            $publisher->publish($newer);
            try {
                $publisher->publish($stale);
                $fail('An older approved candidate overwrote a newer publication.');
            } catch (DomainException $expected) {
            }
            if (get_post_meta($eventId, 'source_candidate_id', true) != $newer) {
                $fail('A stale candidate changed the current event source.');
            }
            $withoutType = $make('update', $eventId, 'reviewer', 'No new event type', null);
            $publisher->publish($withoutType);
            if (! has_term('social', 'adct_event_type', $eventId)) {
                $fail('An update without an event_type removed the existing event type.');
            }

            foreach ([
                'update' => ['scheduled', 'update'],
                'cancellation' => ['cancelled', 'cancel'],
                'postponement' => ['postponed', 'postpone'],
            ] as $kind => [$status, $changeKind]) {
                $oldSource = (int) get_post_meta($eventId, 'source_candidate_id', true);
                $candidate = $make($kind, $eventId);
                if ($publisher->publish($candidate) !== $eventId
                    || get_post_meta($eventId, 'status_flag', true) !== $status
                    || get_post_meta($eventId, 'source_candidate_id', true) != $candidate
                    || $candidates->findById($oldSource)['status'] !== 'superseded'
                    || $count($occurrences, $eventId) !== 1
                    || (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT is_cancelled FROM {$occurrences} WHERE event_id = %d LIMIT 1",
                        $eventId
                    )) !== (int) ($status === 'cancelled')
                    || $publisher->publish($candidate) !== $eventId
                ) {
                    $fail('Publishing ' . $kind . ' did not preserve one linked event and refreshed occurrence.');
                }
                $change = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$changes} WHERE candidate_id = %d LIMIT 1", $candidate
                ), ARRAY_A);
                $before = json_decode($change['before_payload'] ?? '', true);
                $after = json_decode($change['after_payload'] ?? '', true);
                if (
                    $change === null || $change['kind'] !== $changeKind
                    || ($before['meta']['source_candidate_id'] ?? null) != $oldSource
                    || ($after['meta']['source_candidate_id'] ?? null) != $candidate
                    || ($after['meta']['status_flag'] ?? null) !== $status
                ) {
                    $fail('The ' . $kind . ' change has no complete before/after revision.');
                }
            }

            $previousSource = (int) get_post_meta($eventId, 'source_candidate_id', true);
            $previousChanges = $count($changes, $eventId);
            $failed = $make('update', $eventId, 'reviewer', 'Rollback occurrence candidate', 'spiritual');
            $inject = static function (string $query) use ($occurrences): string {
                return str_starts_with($query, "INSERT INTO {$occurrences} ")
                    ? 'INSERT INTO adct_publication_intentional_failure VALUES (1)'
                    : $query;
            };
            add_filter('query', $inject);
            $wpdb->suppress_errors(true);
            try {
                $publisher->publish($failed);
                $fail('A failed occurrence insert was not reported.');
            } catch (RuntimeException $expected) {
            } finally {
                remove_filter('query', $inject);
                $wpdb->suppress_errors(false);
            }
            if (
                $candidates->findById($failed)['status'] !== 'awaiting_approval'
                || $candidates->findById($previousSource)['status'] !== 'published'
                || get_post_meta($eventId, 'source_candidate_id', true) != $previousSource
                || get_post($eventId)?->post_title !== 'Sample parish event'
                || ! has_term('social', 'adct_event_type', $eventId)
                || has_term('spiritual', 'adct_event_type', $eventId)
                || $count($changes, $eventId) !== $previousChanges
                || $count($occurrences, $eventId) !== 1
            ) {
                $fail('A failed occurrence refresh left a partially published event or candidate.');
            }
            if ($publisher->publish($failed) !== $eventId
                || get_post($eventId)?->post_title !== 'Rollback occurrence candidate'
                || ! has_term('spiritual', 'adct_event_type', $eventId)) {
                $fail('A failed publication could not be retried.');
            }

            $previousSource = $failed;
            $previousChanges = $count($changes, $eventId);
            $failedHistory = $make('cancellation', $eventId, 'reviewer', 'Rollback history candidate', 'social');
            $breakHistory = static function (string $query) use ($changes): string {
                return str_starts_with($query, "INSERT INTO {$changes} ")
                    ? 'INSERT INTO adct_publication_intentional_failure VALUES (1)'
                    : $query;
            };
            add_filter('query', $breakHistory);
            $wpdb->suppress_errors(true);
            try {
                $publisher->publish($failedHistory);
                $fail('A failed revision insert was not reported.');
            } catch (RuntimeException $expected) {
            } finally {
                remove_filter('query', $breakHistory);
                $wpdb->suppress_errors(false);
            }
            if (
                $candidates->findById($failedHistory)['status'] !== 'awaiting_approval'
                || $candidates->findById($previousSource)['status'] !== 'published'
                || get_post_meta($eventId, 'source_candidate_id', true) != $previousSource
                || get_post($eventId)?->post_title !== 'Rollback occurrence candidate'
                || ! has_term('spiritual', 'adct_event_type', $eventId)
                || has_term('social', 'adct_event_type', $eventId)
                || get_post_meta($eventId, 'status_flag', true) !== 'scheduled'
                || $count($changes, $eventId) !== $previousChanges
                || $count($occurrences, $eventId) !== 1
            ) {
                $fail('A failed revision insert left a partial cancellation.');
            }
        } finally {
            if ($eventId !== null) {
                wp_delete_post($eventId, true);
                $wpdb->delete($occurrences, ['event_id' => $eventId], ['%d']);
                $wpdb->delete($changes, ['event_id' => $eventId], ['%d']);
            }
            foreach ($candidateIds as $id) {
                $candidates->delete($id);
            }
        }
    }
}
