<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Database\WordPressEventCandidateStore;

final class RepeatMatchingCheck
{
    public static function run(callable $fail, int $sourceId, int $parishId): void
    {
        global $wpdb;
        $messages = $wpdb->prefix . 'adct_pi_inbound_messages';
        $candidates = $wpdb->prefix . 'adct_pi_event_candidates';
        $queue = $wpdb->prefix . 'adct_pi_mail_queue';
        $tokens = $wpdb->prefix . 'adct_pi_action_tokens';
        $stored = new WordPressEventCandidateStore(
            new EventCandidateRepository(new WordPressDatabaseConnection())
        );
        $fixtureContents = file_get_contents(
            WP_CONTENT_DIR . '/test-harness-fixtures/matching/notices.json'
        );
        if ($fixtureContents === false) {
            $fail('The anonymised repeat-matching fixtures could not be loaded.');
        }
        $fixture = json_decode($fixtureContents, true, 32, JSON_THROW_ON_ERROR);
        $messageIds = [];
        $eventId = null;
        $now = gmdate('Y-m-d H:i:s');
        $save = static function (array $fields, array $recurrence) use (
            $wpdb, $messages, $candidates, $stored, $sourceId, $parishId, $now, &$messageIds, $fail
        ): array {
            if ($wpdb->insert($messages, [
                'source_id' => $sourceId,
                'sender_email' => 'fixture@example.test',
                'body_text' => 'Synthetic event-notice audit body.',
                'raw_path' => 'synthetic-repeat-matching.eml',
                'received_at' => $now,
                'status' => 'parsed',
                'created_at' => $now,
                'updated_at' => $now,
            ]) !== 1) {
                $fail('The repeat notice could not be stored.');
            }
            $messageId = (int) $wpdb->insert_id;
            $messageIds[] = $messageId;
            $candidate = new ParseResult();
            foreach ($fields as $key => $value) {
                $candidate->setField($key, $value);
            }
            $candidate->setField('parish_id', $parishId);
            $candidate->setRecurrence($recurrence);
            $candidate->setConfidence(0.95);
            $candidate->setBlockMetadata(0, 'Fictional parish event notice.');
            $stored->replaceDraftCandidatesForMessage(
                $messageId,
                new ParseOutcome([$candidate], [], [], [], $candidate),
                $now
            );
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$candidates} WHERE message_id = %d", $messageId
            ), ARRAY_A);
            if ($row === null) {
                $fail('The repeated notice has no auditable candidate.');
            }
            return $row;
        };

        try {
            $first = $fixture[1];
            $rule = ['rrule' => $first['rrule']];
            $original = $save($first['original'], $rule);
            $wpdb->update($messages, ['confirmation_status' => 'sent'], [
                'id' => (int) $original['message_id'],
            ]);
            $repeat = $save($first['repeat'], $rule);
            if ($repeat['status'] !== 'duplicate' || $repeat['match_kind'] !== 'duplicate'
                || (int) (json_decode($repeat['fields'], true)['matched_candidate_id'] ?? 0)
                    !== (int) $original['id']) {
                $fail('Consecutive first-Friday bulletins created another reviewable event.');
            }
            if (
                str_contains((string) $repeat['fields'], 'Synthetic event-notice audit body.')
                || str_contains((string) $repeat['fields'], 'fixture@example.test')
            ) {
                $fail('Private inbound message data leaked into candidate fields.');
            }

            $repeatGroupKey = 'confirmation:' . (int) $repeat['message_id'];
            delete_option('adct_pi_job_state_queue_confirmation_previews');
            $unexpectedMailAttempts = 0;
            $mailGuard = static function ($preempt, $attributes) use (&$unexpectedMailAttempts) {
                $unexpectedMailAttempts++;
                return true;
            };
            add_filter('pre_wp_mail', $mailGuard, 10, 2);
            try {
                do_action('adct_pi_job_queue_confirmation_previews');
            } finally {
                remove_filter('pre_wp_mail', $mailGuard, 10);
            }
            $outcome = $wpdb->get_row($wpdb->prepare(
                "SELECT confirmation_status, confirmation_reason FROM {$messages} WHERE id = %d",
                (int) $repeat['message_id']
            ), ARRAY_A);
            $queuedForRepeat = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue} WHERE group_key = %s",
                $repeatGroupKey
            ));
            $repeatTokens = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tokens} WHERE (subject_type = %s AND subject_id = %d)"
                . ' OR (subject_type = %s AND subject_id = %d)',
                'inbound_message',
                (int) $repeat['message_id'],
                'event_candidate',
                (int) $repeat['id']
            ));
            $audit = $wpdb->get_row($wpdb->prepare(
                "SELECT body_text, raw_path FROM {$messages} WHERE id = %d",
                (int) $repeat['message_id']
            ), ARRAY_A);
            if (($outcome['confirmation_status'] ?? '') !== 'suppressed'
                || ($outcome['confirmation_reason'] ?? '') !== 'duplicate'
                || $queuedForRepeat !== 0
                || $repeatTokens !== 0
                || ($audit['body_text'] ?? null) !== 'Synthetic event-notice audit body.'
                || ($audit['raw_path'] ?? null) !== 'synthetic-repeat-matching.eml'
                || $unexpectedMailAttempts !== 0) {
                $fail(sprintf(
                    'Duplicate-only integration outcome mismatch (status=%s, reason=%s, queue=%d, tokens=%d, mail=%d, body_preserved=%s, raw_path_preserved=%s).',
                    (string) ($outcome['confirmation_status'] ?? 'missing'),
                    (string) ($outcome['confirmation_reason'] ?? 'missing'),
                    $queuedForRepeat,
                    $repeatTokens,
                    $unexpectedMailAttempts,
                    ($audit['body_text'] ?? null) === 'Synthetic event-notice audit body.' ? 'yes' : 'no',
                    ($audit['raw_path'] ?? null) === 'synthetic-repeat-matching.eml' ? 'yes' : 'no'
                ));
            }

            $eventId = wp_insert_post([
                'post_type' => 'adct_event',
                'post_status' => 'publish',
                'post_title' => $first['original']['title'],
                'post_content' => $first['original']['description'],
            ], true);
            if (is_wp_error($eventId) || ! is_int($eventId) || $eventId < 1) {
                $fail('The test event could not be created.');
            }
            update_post_meta($eventId, 'source_candidate_id', (int) $original['id']);
            update_post_meta($eventId, 'parish_id', $parishId);
            update_post_meta($eventId, 'start_local', '2026-10-02T18:00');
            update_post_meta($eventId, 'rrule', $first['rrule']);
            $wpdb->update($candidates, [
                'status' => 'published', 'match_event_id' => $eventId,
            ], ['id' => (int) $original['id']]);

            foreach ([$first, $fixture[2], $fixture[3], $fixture[4]] as $pair) {
                $recurrence = isset($pair['rrule']) ? ['rrule' => $pair['rrule']] : [];
                if ($pair !== $first) {
                    $eventFields = $pair['original'];
                    $currentId = wp_insert_post([
                        'post_type' => 'adct_event', 'post_status' => 'publish',
                        'post_title' => $eventFields['title'],
                        'post_content' => $eventFields['description'] ?? '',
                    ], true);
                    if (is_wp_error($currentId)) {
                        $fail('The change fixture event could not be created.');
                    }
                    $source = $save($eventFields, $recurrence);
                    update_post_meta($currentId, 'source_candidate_id', (int) $source['id']);
                    update_post_meta($currentId, 'parish_id', $parishId);
                    update_post_meta($currentId, 'start_local', $eventFields['event_date'] . 'T' . $eventFields['event_time']);
                    update_post_meta($currentId, 'rrule', $pair['rrule'] ?? '');
                    $wpdb->update($candidates, [
                        'status' => 'published', 'match_event_id' => $currentId,
                    ], ['id' => (int) $source['id']]);
                } else {
                    $currentId = $eventId;
                }
                $changed = $save($pair['repeat'], $recurrence);
                if ($changed['match_kind'] !== $pair['expected']
                    || ($pair['expected'] === 'duplicate' ? $changed['status'] !== 'duplicate'
                        : $changed['status'] !== 'draft')
                    || (int) $changed['match_event_id'] !== $currentId) {
                    $fail('A change fixture did not target its published event safely: ' . $pair['name']);
                }
                if ($pair !== $first) {
                    wp_delete_post($currentId, true);
                }
            }

            $staleEventUpdate = wp_update_post([
                'ID' => $eventId,
                'post_title' => 'Operator-edited event title',
            ], true);
            if (is_wp_error($staleEventUpdate) || $staleEventUpdate < 1) {
                $fail('The stale published-event protection fixture could not be prepared.');
            }
            $staleTitleRepeat = $save($first['repeat'], $rule);
            if (
                $staleTitleRepeat['status'] !== 'draft'
                || $staleTitleRepeat['match_kind'] !== 'new'
                || (int) $staleTitleRepeat['match_event_id'] !== 0
            ) {
                $fail('A stale published-event title was silently treated as a duplicate.');
            }
        } finally {
            foreach ($messageIds as $id) {
                $wpdb->delete($candidates, ['message_id' => $id]);
                $wpdb->delete($messages, ['id' => $id]);
            }
            if (is_int($eventId)) {
                wp_delete_post($eventId, true);
            }
        }
    }
}
