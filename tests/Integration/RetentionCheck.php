<?php

use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Database\WordPressRetentionStore;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;

final class RetentionCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adct-retention-' . bin2hex(random_bytes(8));
        $storage = new ProtectedInboundMailStorage($directory);
        $old = $storage->storeRawMessage("From: example@example.test\r\n\r\nOld");
        $new = $storage->storeRawMessage("From: example@example.test\r\n\r\nNew");
        $attachment = $storage->storeAttachment('example poster', 'pdf');
        $messages = $wpdb->prefix . 'adct_pi_inbound_messages';
        $attachments = $wpdb->prefix . 'adct_pi_attachments';
        $ids = [];
        $now = '2026-09-25 12:00:00';
        $before = '2026-09-25 11:59:59';

        try {
            foreach ([
                ['old', $old, $before, 'parsed'],
                ['new', $new, '2026-09-26 00:00:00', 'parsed'],
                ['busy', null, $before, 'extracting'],
            ] as [$name, $path, $until, $status]) {
                if ($wpdb->insert($messages, [
                    'source_id' => 1, 'external_id' => 'retention-integration-' . $name,
                    'received_at' => '2025-09-01 00:00:00', 'raw_path' => $path,
                    'status' => $status, 'retention_until' => $until,
                    'created_at' => $now, 'updated_at' => $now,
                ]) !== 1) {
                    $fail('Could not create isolated retention message.');
                }
                $ids[$name] = (int) $wpdb->insert_id;
            }
            if ($wpdb->insert($attachments, [
                'message_id' => $ids['old'], 'filename' => 'example.pdf',
                'storage_path' => $attachment, 'size_bytes' => 14,
                'created_at' => $now, 'updated_at' => $now,
            ]) !== 1) {
                $fail('Could not create isolated retention attachment.');
            }
            $attachmentId = (int) $wpdb->insert_id;
            $eventsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'adct_event' AND post_status = 'publish'");
            $store = new WordPressRetentionStore(new WordPressDatabaseConnection($wpdb), $storage);
            if ($store->nextExpiredMessageId(0, $now) !== $ids['old']) {
                $fail('Retention did not select only the expired terminal message.');
            }
            $store->removeExpiredMessageFiles($ids['old'], $now);
            $store->removeExpiredMessageFiles($ids['old'], $now);
            if (is_file($directory . DIRECTORY_SEPARATOR . $old)
                || is_file($directory . DIRECTORY_SEPARATOR . $attachment)
                || ! is_file($directory . DIRECTORY_SEPARATOR . $new)
                || $store->nextExpiredMessageId(0, $now) !== null) {
                $fail('Retention did not keep new files or safely remove expired files.');
            }
            $saved = $wpdb->get_row($wpdb->prepare("SELECT raw_path FROM {$messages} WHERE id = %d", $ids['old']));
            $savedAttachment = $wpdb->get_row($wpdb->prepare("SELECT storage_path FROM {$attachments} WHERE id = %d", $attachmentId));
            if ($saved->raw_path !== null || $savedAttachment->storage_path !== '') {
                $fail('Retention did not clear deleted file references.');
            }
            if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'adct_event' AND post_status = 'publish'") !== $eventsBefore) {
                $fail('Retention changed published events.');
            }
        } finally {
            foreach ($ids as $id) {
                $wpdb->delete($attachments, ['message_id' => $id]);
                $wpdb->delete($messages, ['id' => $id]);
            }
            foreach (scandir($directory) ?: [] as $file) {
                if ($file !== '.' && $file !== '..') {
                    unlink($directory . DIRECTORY_SEPARATOR . $file);
                }
            }
            rmdir($directory);
        }
    }
}
