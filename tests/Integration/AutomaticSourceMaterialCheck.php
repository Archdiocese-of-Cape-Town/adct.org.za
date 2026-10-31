<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Audit\AuditLogRepository;
use ADCT\ParishIntake\WordPress\Audit\WordPressActorResolver;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Events\EventListingGeneration;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use ADCT\ParishIntake\WordPress\Plugin;
use ADCT\ParishIntake\WordPress\Publishing\WordPressEventMeta;
use ADCT\ParishIntake\WordPress\Publishing\WordPressMediaLibraryGateway;
use ADCT\ParishIntake\WordPress\Publishing\WordPressSourceMaterialStore;

final class AutomaticSourceMaterialCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;
        $connection = new WordPressDatabaseConnection($wpdb);
        $candidates = new EventCandidateRepository($connection);
        $storage = new ProtectedInboundMailStorage();
        $store = new WordPressSourceMaterialStore(
            new AttachmentRepository($connection), $storage,
            new WordPressMediaLibraryGateway(), new WordPressEventMeta(),
            new WordPressActorResolver(), new AuditLogRepository($connection, new SystemClock())
        );
        $now = gmdate('Y-m-d H:i:s');
        $date = (new DateTimeImmutable('tomorrow', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
        $messages = $wpdb->prefix . 'adct_pi_inbound_messages';
        $attachments = $wpdb->prefix . 'adct_pi_attachments';
        $wpdb->insert($messages, [
            'source_id' => 1, 'external_id' => 'automatic-source-' . bin2hex(random_bytes(8)),
            'received_at' => $now, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $messageId = (int) $wpdb->insert_id;
        if ($messageId < 1) {
            $fail('Automatic source check could not create a synthetic message.');
        }
        $paths = [];
        $candidateIds = [];
        $eventIds = [];
        $mediaIds = [];
        $make = static function (string $via, string $kind = 'new', ?int $eventId = null) use (
            $candidates, $messageId, $now, $date, &$candidateIds
        ): int {
            $id = $candidates->insert([
                'message_id' => $messageId, 'block_index' => count($candidateIds),
                'fields' => wp_json_encode([
                    'title' => 'Synthetic source event', 'description' => 'Synthetic details.',
                    'event_date' => $date, 'event_time' => '09:00',
                ]),
                'recurrence' => '{}', 'match_kind' => $kind, 'match_event_id' => $eventId,
                'status' => 'awaiting_approval', 'approved_by' => $via . '@example.test',
                'approved_via' => $via, 'approved_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $candidateIds[] = $id;
            return $id;
        };
        try {
            foreach ([
                ['poster.png', 'image/png', 'png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')],
                ['<script>bulletin</script>.pdf', 'application/pdf', 'pdf', "%PDF-1.4\n% synthetic\n"],
                ['phone.heic', 'image/heic', 'heic', 'synthetic unsupported file'],
            ] as [$filename, $mime, $extension, $bytes]) {
                $path = $storage->storeAttachment($bytes, $extension);
                $paths[] = $path;
                if ($wpdb->insert($attachments, [
                    'message_id' => $messageId, 'filename' => $filename, 'mime_type' => $mime,
                    'size_bytes' => strlen($bytes), 'storage_path' => $path,
                    'created_at' => $now, 'updated_at' => $now,
                ]) !== 1) {
                    $fail('Automatic source check could not create a synthetic attachment.');
                }
            }
            foreach (['dean', 'reviewer', 'self'] as $via) {
                $candidateId = $make($via);
                $eventId = Plugin::candidatePublisher()->publish($candidateId);
                $eventIds[] = $eventId;
                $sources = $store->forEvent($eventId);
                $mediaIds = array_merge($mediaIds, array_map(static fn ($source): int => $source->mediaId, $sources));
                $view = Plugin::publicEventPage()->viewForPost(get_post($eventId));
                if (count($sources) !== 2 || $view['poster'] === null
                    || count($view['source_material']) !== 1
                    || empty($view['json_ld']['image'])) {
                    $fail('The ' . $via . ' publication route did not expose its poster and PDF publicly.');
                }
                foreach ($sources as $source) {
                    if (! is_file(get_attached_file($source->mediaId))
                        || (int) get_post($source->mediaId)->post_parent !== $eventId) {
                        $fail('Automatic publication has no readable, parented media-library copy.');
                    }
                    $audit = $wpdb->get_row($wpdb->prepare(
                        "SELECT actor,details FROM {$wpdb->prefix}adct_pi_audit_log"
                        . " WHERE action = %s AND subject_id = %d ORDER BY id DESC LIMIT 1",
                        'source_material_promoted', $eventId
                    ), ARRAY_A);
                    $details = json_decode($audit['details'] ?? '{}', true);
                    if (($audit['actor'] ?? '') !== $via . '@example.test'
                        || ($details['candidate_id'] ?? 0) !== $candidateId
                        || ($details['automatic'] ?? false) !== true) {
                        $fail('Automatic source publication lost the recorded approval attribution.');
                    }
                }
                $ids = array_map(static fn ($source): int => $source->mediaId, $sources);
                Plugin::candidatePublisher()->publish($candidateId);
                if ($ids !== array_map(static fn ($source): int => $source->mediaId, $store->forEvent($eventId))) {
                    $fail('A publication retry duplicated source media.');
                }
                foreach (['update', 'cancellation', 'postponement'] as $kind) {
                    Plugin::candidatePublisher()->publish($make($via, $kind, $eventId));
                    if ($ids !== array_map(static fn ($source): int => $source->mediaId, $store->forEvent($eventId))) {
                        $fail('The ' . $kind . ' publication path duplicated source media.');
                    }
                }
                $latest = (int) get_post_meta($eventId, 'source_candidate_id', true);
                $store->remove($eventId, $sources[0]->mediaId);
                Plugin::candidatePublisher()->publish($latest);
                if (count($store->forEvent($eventId)) !== 1 || has_post_thumbnail($eventId)) {
                    $fail('Retry restored a manually removed source poster.');
                }
            }
            foreach ($paths as $path) {
                if (! is_file($storage->resolveAttachmentPath($path))) {
                    $fail('Sideload moved the private original needed by another event.');
                }
            }

            $candidateId = $make('reviewer');
            $refusePdf = static function (array $file): array {
                if (str_ends_with($file['name'], '.pdf')) {
                    $file['error'] = 'Synthetic copy refusal';
                }
                return $file;
            };
            add_filter('wp_handle_sideload_prefilter', $refusePdf);
            try {
                $eventId = Plugin::candidatePublisher()->publish($candidateId);
                $eventIds[] = $eventId;
            } finally {
                remove_filter('wp_handle_sideload_prefilter', $refusePdf);
            }
            $sources = $store->forEvent($eventId);
            $mediaIds = array_merge($mediaIds, array_map(static fn ($source): int => $source->mediaId, $sources));
            if (get_post_status($eventId) !== 'publish' || count($sources) !== 1
                || ! get_post_meta($eventId, WordPressSourceMaterialStore::ERROR_META, true)) {
                $fail('A copy failure rolled back publication, lost other files, or was not visible.');
            }
            Plugin::candidatePublisher()->publish($candidateId);
            $repaired = $store->forEvent($eventId);
            $mediaIds = array_merge($mediaIds, array_map(static fn ($source): int => $source->mediaId, $repaired));
            if (count($repaired) !== 2 || $repaired[0]->mediaId !== $sources[0]->mediaId
                || get_post_meta($eventId, WordPressSourceMaterialStore::ERROR_META, true) !== []) {
                $fail('Retry did not repair only the failed source copy.');
            }

            $generation = new EventListingGeneration();
            $before = $generation->current();
            $candidateId = $make('reviewer', 'update', $eventId);
            $blockingDatabase = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $lock = substr('adct-source-' . hash('sha256', $wpdb->prefix . ':' . $eventId), 0, 64);
            if ((int) $blockingDatabase->get_var($blockingDatabase->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
                $fail('The source publication contention fixture could not acquire its lock.');
            }
            $failure = null;
            try {
                Plugin::candidatePublisher()->publish($candidateId);
            } catch (RuntimeException $error) {
                $failure = $error;
            } finally {
                $blockingDatabase->get_var($blockingDatabase->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
            if ($failure === null || ! str_contains($failure->getMessage(), 'is published, but source material')) {
                $fail('Source lock contention did not report the committed event and recovery action.');
            }
            if (get_post_status($eventId) !== 'publish'
                || (int) get_post_meta($eventId, 'source_candidate_id', true) !== $candidateId
                || $generation->current() === $before) {
                $fail('A committed publication remained hidden behind a stale listing cache after source contention.');
            }
            Plugin::candidatePublisher()->publish($candidateId);
            if (array_map(static fn ($source): int => $source->mediaId, $repaired)
                !== array_map(static fn ($source): int => $source->mediaId, $store->forEvent($eventId))) {
                $fail('Retry after source lock contention duplicated existing copies.');
            }
        } finally {
            foreach (array_unique($mediaIds) as $id) {
                wp_delete_attachment($id, true);
            }
            foreach ($eventIds as $id) {
                wp_delete_post($id, true);
                $wpdb->delete($wpdb->prefix . 'adct_pi_occurrences', ['event_id' => $id]);
                $wpdb->delete($wpdb->prefix . 'adct_pi_event_changes', ['event_id' => $id]);
                $wpdb->delete($wpdb->prefix . 'adct_pi_audit_log', ['subject_type' => 'event', 'subject_id' => $id]);
            }
            foreach ($candidateIds as $id) {
                $wpdb->delete($wpdb->prefix . 'adct_pi_event_candidates', ['id' => $id]);
            }
            $wpdb->delete($attachments, ['message_id' => $messageId]);
            $wpdb->delete($messages, ['id' => $messageId]);
            foreach ($paths as $path) {
                $storage->delete($path);
            }
        }
    }
}
