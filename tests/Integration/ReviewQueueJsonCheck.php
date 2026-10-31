<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;

final class ReviewQueueJsonCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'adct_pi_';
        $suffix = 'json-queue-' . bin2hex(random_bytes(6));
        $stamp = gmdate('Y-m-d H:i:s');
        $queue = new ReviewQueueRepository(new WordPressDatabaseConnection(), new SystemClock());
        $rows = [];
        $check = static function (bool $ok, string $message) use ($fail): void {
            if (! $ok) {
                $fail('Review queue JSON: ' . $message);
            }
        };
        $insert = static function (string $table, array $data) use ($wpdb, $prefix, &$rows, $fail, $stamp): int {
            if ($wpdb->insert($prefix . $table, $data + ['created_at' => $stamp, 'updated_at' => $stamp]) !== 1) {
                $fail('Could not insert synthetic queue JSON fixture: ' . $wpdb->last_error);
            }
            $id = (int) $wpdb->insert_id;
            $rows[] = [$prefix . $table, $id];
            return $id;
        };
        try {
            $check(array_sum($queue->counts(1, 'admin@example.test', true, $suffix)) === 0, 'empty counts');
            $parish = $insert('parishes', ['name' => $suffix, 'slug' => $suffix]);
            $verified = $suffix . '@example.test';
            $pending = 'pending-' . $verified;
            // MariaDB compares this escaped JSON string literally; MySQL decodes it.
            // Preserve each native engine rather than changing production behavior.
            $escapedCategory = stripos($wpdb->db_server_info(), 'mariadb') !== false
                ? 'approval' : 'unknown_senders';
            foreach ([[$verified, 'verified'], [$pending, 'pending']] as [$email, $trust]) {
                $insert('parish_contacts', ['parish_id' => $parish, 'email' => $email, 'trust' => $trust]);
            }
            $cases = [
                ['[]', $verified, 'approval'],
                ['["unknown_sender"]', $verified, 'unknown_senders'],
                ['[["unknown_sender"]]', $verified, 'unknown_senders'],
                ['["\\u0075nknown_sender"]', $verified, $escapedCategory],
                ['"unknown_sender"', $verified, 'unknown_senders'],
                ['{"note":"unknown_sender"}', $verified, 'approval'],
                ['[{"note":"unknown_sender"}]', $verified, 'approval'],
                ['["unknown_sender_extra"]', $verified, 'approval'],
                ['invalid JSON', $verified, 'approval'],
                ['[]', $pending, 'unknown_senders'],
                ['[]', 'unknown-' . $verified, 'unknown_senders'],
            ];
            $expectedUnknown = [];
            foreach ($cases as $index => [$notes, $email, $category]) {
                $message = $insert('inbound_messages', [
                    'source_id' => 1, 'external_id' => $suffix . '-' . $index,
                    'sender_email' => $email, 'received_at' => $stamp, 'status' => 'parsed',
                ]);
                $id = $insert('event_candidates', [
                    'message_id' => $message, 'parish_id' => $parish, 'status' => 'awaiting_approval',
                    'confidence' => 0.9, 'match_kind' => 'new', 'notes' => $notes,
                    'fields' => json_encode(['title' => 'A "quoted" ' . $suffix], JSON_THROW_ON_ERROR),
                ]);
                if ($category === 'unknown_senders') {
                    $expectedUnknown[] = $id;
                }
            }
            $counts = $queue->counts(1, 'admin@example.test', true, $suffix);
            $check($counts['awaiting_approval'] === count($cases)
                && $counts['unknown_senders'] === count($expectedUnknown)
                && $counts['primary_approval'] === count($cases) - count($expectedUnknown),
                'scalar/array/object/escaped/invalid notes and verified/pending/unknown sender counts');
            $actualUnknown = array_map('intval', array_column(
                $queue->find('unknown_senders', 1, 'admin@example.test', true, $suffix, 50, 0), 'id'
            ));
            sort($actualUnknown);
            sort($expectedUnknown);
            $check($actualUnknown === $expectedUnknown, 'tab membership must match the counts');
            $check($queue->counts(1, 'admin@example.test', true, 'A "quoted" ' . $suffix)['awaiting_approval']
                === count($cases), 'JSON title search must unquote once, without losing embedded quotes');
            $check(array_sum($queue->counts(999999, 'unassigned@example.test', false, $suffix)) === 0,
                'unassigned deans must not gain queue access');

            foreach ([true, 'true', false, 1, 'false'] as $index => $flag) {
                $message = $insert('inbound_messages', [
                    'source_id' => 1, 'external_id' => $suffix . '-flag-' . $index,
                    'sender_email' => $verified, 'received_at' => $stamp, 'status' => 'parsed',
                ]);
                $id = $insert('event_candidates', [
                    'message_id' => $message, 'parish_id' => $parish, 'status' => 'duplicate',
                    'confidence' => 0.9, 'match_kind' => 'new', 'notes' => '[]',
                    'fields' => json_encode(['title' => $suffix, 'match_review_required' => $flag], JSON_THROW_ON_ERROR),
                ]);
                $visible = $queue->findScoped($id, 1, 'admin@example.test', true) !== null;
                $check($visible === ($flag === true || $flag === 'true'), 'JSON boolean/string flag semantics');
            }
            $message = $insert('inbound_messages', [
                'source_id' => 1, 'external_id' => $suffix . '-invalid-fields',
                'sender_email' => $verified, 'received_at' => $stamp, 'status' => 'parsed',
            ]);
            $id = $insert('event_candidates', [
                'message_id' => $message, 'parish_id' => $parish, 'status' => 'duplicate',
                'confidence' => 0.9, 'match_kind' => 'new', 'notes' => '[]', 'fields' => 'invalid JSON',
            ]);
            $check($queue->findScoped($id, 1, 'admin@example.test', true) === null, 'invalid fields are guarded');
            $check($wpdb->last_error === '', 'no genuine database errors may be hidden');
        } finally {
            foreach (array_reverse($rows) as [$table, $id]) {
                if ($wpdb->delete($table, ['id' => $id]) === false) {
                    $fail('Could not remove synthetic queue JSON fixture: ' . $wpdb->last_error);
                }
            }
        }
    }
}
