<?php

use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use ADCT\ParishIntake\WordPress\Audit\WordPressActorResolver;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Audit\AuditLogRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressRetentionStore;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use ADCT\ParishIntake\WordPress\Publishing\WordPressEventMeta;
use ADCT\ParishIntake\WordPress\Publishing\WordPressMediaLibraryGateway;
use ADCT\ParishIntake\WordPress\Publishing\WordPressSourceMaterialStore;

/**
 * Promotion against a real WordPress runtime (#172).
 *
 * The unit suite proves the decisions: that nothing publishes an attachment, that
 * the stored name carries no part of the parish's, that the front end reads only
 * the ordered meta. Every one of those runs against stubs, so they prove what
 * this code asks WordPress to do, not what WordPress does with the request. This
 * check is for the difference.
 *
 * Specifically it is the only evidence for:
 *
 *  - `wp_handle_sideload()` accepting a generated name, writing where WordPress
 *    actually writes (in a year/month directory, not the flat path a fake
 *    assumes), and generating attachment metadata for a real image;
 *  - `set_post_thumbnail()` accepting an attachment whose parent is the event,
 *    and `has_post_thumbnail()` then reading it back -- the poster path on the
 *    single-event page, which the unit suite only asserts as a call;
 *  - and, most importantly, that the copy survives a real retention sweep of the
 *    private intake directory.
 *
 * Nothing here drives the plugin's screens: it calls the store the way the three
 * handlers do. The screens have their own unit coverage.
 */
final class SourceMaterialCheck
{
    public static function run(callable $fail, int $parishId, string $occurrenceType, string $occurrenceVenue): void
    {
        global $wpdb;

        $uploads = wp_upload_dir();

        if (! empty($uploads['error'])) {
            $fail('The uploads directory is unavailable, so promotion cannot be checked: ' . $uploads['error']);

            return;
        }

        $storageDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adct-source-material-' . bin2hex(random_bytes(8));
        $storage = new ProtectedInboundMailStorage($storageDirectory);
        $eventId = 0;
        $mediaIds = [];
        $attachmentIds = [];

        try {
            $eventId = self::createEvent($fail, $parishId, $occurrenceType, $occurrenceVenue);

            if ($eventId < 1) {
                return;
            }

            // Two attachments: a real 1x1 PNG promoted as the poster, and a
            // "bulletin" whose parish filename is a traversal. The hostile name
            // is the point: if any part of it reached a path, WordPress would
            // either write outside the uploads directory or refuse the file, and
            // both are detectable below.
            $posterName = 'parish poster.png';
            $bulletinName = '../../../../wp-config.php';
            $posterBytes = (string) base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
            );

            // `storeAttachment()` takes the bytes, not a parish filename: the
            // parish's own name lives in the database row and never on disk in
            // the private folder, which is exactly why promotion has to read it
            // back rather than trust a path.
            $posterPath = $storage->storeAttachment($posterBytes, 'png');
            $bulletinPath = $storage->storeAttachment("%PDF-1.4\n% synthetic\n", 'pdf');

            $attachmentIds['poster'] = self::insertAttachment(
                $wpdb,
                $fail,
                $posterPath,
                $posterName,
                'image/png',
                strlen($posterBytes)
            );
            $attachmentIds['bulletin'] = self::insertAttachment(
                $wpdb,
                $fail,
                $bulletinPath,
                $bulletinName,
                'application/pdf',
                24
            );

            if ($attachmentIds['poster'] < 1 || $attachmentIds['bulletin'] < 1) {
                return;
            }

            $connection = new WordPressDatabaseConnection($wpdb);
            $store = new WordPressSourceMaterialStore(
                new AttachmentRepository($connection),
                $storage,
                new WordPressMediaLibraryGateway(),
                new WordPressEventMeta(),
                new WordPressActorResolver(),
                new AuditLogRepository($connection, new SystemClock(), new WordPressActorResolver())
            );

            // Nothing is promoted before anyone asks. The unit stubs can be told
            // what to return; this is the state of the database and the disk
            // after a publication that copied nothing.
            self::assertNothingPromoted($fail, $eventId, (string) $uploads['basedir'], $posterPath);

            $poster = $store->promote($eventId, $attachmentIds['poster'], SourceAttachment::ROLE_POSTER);
            $mediaIds[] = $poster->mediaId;

            $media = get_post($poster->mediaId);

            if (! $media instanceof WP_Post || $media->post_type !== 'attachment') {
                $fail('Promotion did not create a WordPress attachment post.');
            } elseif ((int) $media->post_parent !== $eventId) {
                $fail('The promoted attachment was not parented to the event.');
            } elseif (get_post_thumbnail_id($eventId) !== $poster->mediaId) {
                $fail('The promoted poster was not set as the event featured image.');
            } elseif (! has_post_thumbnail($eventId)) {
                $fail('The event reports no thumbnail after promoting a poster.');
            }

            // A real image copy produces real metadata, which is what gives the
            // media library dimensions to render. A PDF legitimately has none.
            if (! is_array(wp_get_attachment_metadata($poster->mediaId))) {
                $fail('The promoted image got no attachment metadata from WordPress.');
            }

            self::assertStoredNameIsGenerated(
                $fail,
                $poster->mediaId,
                $eventId,
                $attachmentIds['poster'],
                $posterName,
                'png'
            );

            // The second attachment, whose parish filename is a traversal, is
            // promoted as a bulletin. Its stored name must be generated too.
            $bulletin = $store->promote($eventId, $attachmentIds['bulletin'], SourceAttachment::ROLE_BULLETIN);
            $mediaIds[] = $bulletin->mediaId;

            self::assertStoredNameIsGenerated(
                $fail,
                $bulletin->mediaId,
                $eventId,
                $attachmentIds['bulletin'],
                $bulletinName,
                'pdf'
            );

            // Promoting a bulletin must not steal the poster role.
            if (get_post_thumbnail_id($eventId) !== $poster->mediaId) {
                $fail('Promoting a bulletin replaced the event featured image.');
            }

            // The ordered meta is the whole of the front end's knowledge, so the
            // order the two were promoted in must survive the round trip.
            $ordered = array_map(
                static fn (SourceAttachment $source): string => $source->role,
                $store->forEvent($eventId)
            );

            if ($ordered !== [SourceAttachment::ROLE_POSTER, SourceAttachment::ROLE_BULLETIN]) {
                $fail('The event did not keep its source material in promotion order: ' . implode(',', $ordered));
            }

            if (! self::everyCopyIsInsideUploads($fail, $mediaIds, (string) $uploads['basedir'])) {
                return;
            }

            // An audit row per promotion, naming both ids, because "who made this
            // bulletin public" has to be answerable without asking anyone.
            foreach ([$poster, $bulletin] as $source) {
                if (! self::hasAuditRow($wpdb, 'source_material_promoted', $eventId, $source->attachmentId, $source->mediaId)) {
                    $fail('Promotion wrote no audit row naming the event, the intake attachment and the copy.');
                }
            }

            // Removing is a visibility change: the bytes and the attachment post
            // both stay, and the poster role goes with it.
            $store->remove($eventId, $poster->mediaId);

            if (get_post_thumbnail_id($eventId) === $poster->mediaId) {
                $fail('Removing the promoted poster left it as the event featured image.');
            }

            if (! self::copyIsStillReadable($fail, $poster->mediaId)) {
                return;
            }

            if (! self::hasAuditRow($wpdb, 'source_material_removed', $eventId, $poster->attachmentId, $poster->mediaId)) {
                $fail('Removal wrote no audit row.');
            }

            if (count($store->forEvent($eventId)) !== 1) {
                $fail('Removal did not drop exactly the removed item from the event.');
            }

            // The published bulletin, and then a real retention sweep of the
            // private directory behind it. The ordering matters: retention
            // reclaims the parish's copy of the file, and the public one must be
            // untouched by it and still renderable afterwards.
            $bulletinFile = get_attached_file($bulletin->mediaId);

            self::runRetentionSweep($wpdb, $storage);

            if (! is_file((string) $bulletinFile)) {
                $fail('Retention deleted the promoted copy in the media library.');
            }

            // The private original is gone. `resolveAttachmentPath()` now throws
            // for it, which is the evidence the sweep actually reclaimed it.
            $reclaimed = false;

            try {
                $storage->resolveAttachmentPath($bulletinPath);
            } catch (Throwable) {
                $reclaimed = true;
            }

            if (! $reclaimed) {
                $fail('Retention did not reclaim the private original it should have.');
            }

            if (! self::copyIsStillReadable($fail, $bulletin->mediaId)) {
                return;
            }

            if ((int) get_post($eventId)->post_parent === 0) {
                $fail('Retention detached the event whose source material survives.');
            }
        } finally {
            foreach ($mediaIds as $mediaId) {
                $file = get_attached_file($mediaId);

                if (is_string($file) && is_file($file)) {
                    wp_delete_file($file);
                }

                wp_delete_attachment($mediaId, true);
            }

            foreach ($attachmentIds as $attachmentId) {
                $wpdb->delete($wpdb->prefix . 'adct_pi_attachments', ['id' => $attachmentId]);
            }

            if ($eventId > 0) {
                wp_delete_post($eventId, true);
            }

            foreach (scandir($storageDirectory) ?: [] as $file) {
                if ($file !== '.' && $file !== '..') {
                    $path = $storageDirectory . DIRECTORY_SEPARATOR . $file;

                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }

            if (is_dir($storageDirectory)) {
                rmdir($storageDirectory);
            }
        }
    }

    private static function createEvent(callable $fail, int $parishId, string $occurrenceType, string $occurrenceVenue): int
    {
        $eventId = wp_insert_post([
            'post_type' => 'adct_event',
            'post_status' => 'publish',
            'post_title' => 'Source material integration check',
        ], true);

        if (is_wp_error($eventId) || (int) $eventId < 1) {
            $fail('Could not create the event the source material check needs.');

            return 0;
        }

        $eventId = (int) $eventId;
        update_post_meta($eventId, '_adct_parish_id', (string) $parishId);
        update_post_meta($eventId, '_adct_occurrence_type', $occurrenceType);
        update_post_meta($eventId, '_adct_occurrence_venue', $occurrenceVenue);

        return $eventId;
    }

    private static function insertAttachment(
        $wpdb,
        callable $fail,
        string $storagePath,
        string $filename,
        string $mimeType,
        int $size
    ): int {
        $now = '2026-09-25 12:00:00';
        $messages = $wpdb->prefix . 'adct_pi_inbound_messages';
        $attachments = $wpdb->prefix . 'adct_pi_attachments';

        if ($wpdb->insert($messages, [
            'source_id' => 1,
            'external_id' => 'source-material-integration-' . bin2hex(random_bytes(6)),
            'received_at' => '2025-09-01 00:00:00',
            'raw_path' => null,
            'status' => 'parsed',
            'retention_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]) !== 1) {
            $fail('Could not create the intake message the source material check needs.');

            return 0;
        }

        $messageId = (int) $wpdb->insert_id;

        if ($wpdb->insert($attachments, [
            'message_id' => $messageId,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'storage_path' => $storagePath,
            'size_bytes' => $size,
            'created_at' => $now,
            'updated_at' => $now,
        ]) !== 1) {
            $wpdb->delete($messages, ['id' => $messageId]);
            $fail('Could not create the intake attachment the source material check needs.');

            return 0;
        }

        $attachmentId = (int) $wpdb->insert_id;
        $wpdb->delete($messages, ['id' => $messageId]);

        return $attachmentId;
    }

    /**
     * Nothing promoted, nothing public, and no copy anywhere on disk: the state
     * an ordinary publication leaves behind.
     */
    private static function assertNothingPromoted(
        callable $fail,
        int $eventId,
        string $uploadsBaseDir,
        string $posterPath
    ): void {
        $meta = new WordPressEventMeta();

        if ($meta->readSourceAttachmentIds($eventId) !== []) {
            $fail('A published event recorded source material nobody promoted.');
        }

        if (has_post_thumbnail($eventId)) {
            $fail('A published event has a featured image nobody promoted.');
        }

        $children = get_children([
            'post_parent' => $eventId,
            'post_type' => 'attachment',
            'post_status' => 'inherit',
        ]);

        if (! empty($children)) {
            $fail('A published event has attachments parented to it before any promotion.');
        }

        // Only this plugin writes `adct-source-` files, and nothing has been
        // promoted yet, so a glob is the bluntest possible "no copy exists".
        $matches = glob(rtrim($uploadsBaseDir, '/\\') . '/adct-source-*') ?: [];

        if ($matches !== []) {
            $fail('Promoted source material exists on disk before anything was promoted.');
        }

        if (! is_file($posterPath)) {
            $fail('The private intake file went missing before any promotion.');
        }
    }

    /**
     * The stored file is named entirely by this plugin, lives under the uploads
     * directory, and carries no part of the parish's own name -- including the
     * traversal inside it.
     */
    private static function assertStoredNameIsGenerated(
        callable $fail,
        int $mediaId,
        int $eventId,
        int $attachmentId,
        string $parishFilename,
        string $extension
    ): void {
        $file = get_attached_file($mediaId);

        if (! is_string($file) || $file === '' || ! is_file($file)) {
            $fail('The promoted copy is not a file on disk.');

            return;
        }

        $basename = basename($file);
        $prefix = sprintf('adct-source-%d-%d-', $eventId, $attachmentId);

        if (strpos($basename, $prefix) !== 0) {
            $fail('The promoted file is not named by the plugin from the event and attachment ids: ' . $basename);
        }

        if (pathinfo($basename, PATHINFO_EXTENSION) !== $extension) {
            $fail('The promoted file did not take its extension from the declared type: ' . $basename);
        }

        // The decisive one for the hostile name: not one character of it may
        // appear in the path that was actually written.
        foreach (self::nameFragments($parishFilename) as $fragment) {
            if (strpos(str_replace('\\', '/', $file), $fragment) !== false) {
                $fail('Part of the parish filename reached the stored path: ' . $fragment);
            }
        }

        $uploads = wp_upload_dir();
        $expected = str_replace('\\', '/', rtrim((string) $uploads['basedir'], '/\\')) . '/';

        if (strpos(str_replace('\\', '/', $file), $expected) !== 0) {
            $fail('The promoted copy was written outside the uploads directory: ' . $file);
        }
    }

    /**
     * The pieces of a parish filename that must never appear in a stored path:
     * its stem, and its extension, so `poster.png` cannot become
     * `poster.png.php` and `wp-config.php` cannot survive at all.
     *
     * @return list<string>
     */
    private static function nameFragments(string $filename): array
    {
        $fragments = [];

        foreach ([pathinfo($filename, PATHINFO_FILENAME), pathinfo($filename, PATHINFO_EXTENSION), $filename] as $candidate) {
            $candidate = trim($candidate);

            // Bare format words are excluded: the plugin legitimately writes
            // `.pdf` and `.png`, so their presence proves nothing about the
            // parish's own name.
            if (strlen($candidate) >= 3 && ! in_array($candidate, ['png', 'pdf', 'jpg', 'jpeg', 'webp'], true)) {
                $fragments[] = $candidate;
            }
        }

        return $fragments;
    }

    /**
     * @param list<int> $mediaIds
     * @return bool false when the check has already failed and cannot continue
     */
    private static function everyCopyIsInsideUploads(callable $fail, array $mediaIds, string $basedir): bool
    {
        $expected = str_replace('\\', '/', rtrim($basedir, '/\\')) . '/';

        foreach ($mediaIds as $mediaId) {
            $file = get_attached_file($mediaId);

            if (! is_string($file) || strpos(str_replace('\\', '/', $file), $expected) !== 0) {
                $fail('A promoted copy is not under the uploads directory: ' . (string) $file);

                return false;
            }
        }

        return true;
    }

    private static function copyIsStillReadable(callable $fail, int $mediaId): bool
    {
        $file = get_attached_file($mediaId);

        if (! is_string($file) || ! is_file($file) || (int) filesize($file) < 1) {
            $fail('A promoted copy is no longer a readable file.');

            return false;
        }

        return true;
    }

    private static function hasAuditRow($wpdb, string $action, int $eventId, int $attachmentId, int $mediaId): bool
    {
        $table = $wpdb->prefix . 'adct_pi_audit_log';
        $details = $wpdb->get_var($wpdb->prepare(
            "SELECT details FROM {$table} WHERE action = %s AND subject_type = %s AND subject_id = %d ORDER BY id DESC LIMIT 1",
            $action,
            'event',
            $eventId
        ));

        if (! is_string($details) || $details === '') {
            return false;
        }

        $decoded = json_decode($details, true);

        return is_array($decoded)
            && (int) ($decoded['attachment_id'] ?? 0) === $attachmentId
            && (int) ($decoded['media_id'] ?? 0) === $mediaId;
    }

    /**
     * A real retention sweep, through the real store, over the real directory.
     *
     * The clock is injected, as everywhere else, and set past the message's
     * `retention_until`: that is the condition the cron job looks for. The
     * promoted copies are in the media library and are not candidates, which is
     * precisely what lets the private original be reclaimed without breaking the
     * public event.
     */
    private static function runRetentionSweep($wpdb, ProtectedInboundMailStorage $storage): void
    {
        $messages = $wpdb->prefix . 'adct_pi_inbound_messages';
        $attachments = $wpdb->prefix . 'adct_pi_attachments';
        $now = '2026-10-25 12:00:00';

        $wpdb->insert($messages, [
            'source_id' => 1,
            'external_id' => 'source-material-retention-' . bin2hex(random_bytes(6)),
            'received_at' => '2025-09-01 00:00:00',
            'raw_path' => $storage->storeRawMessage("From: example@example.test\r\n\r\nExpired"),
            'status' => 'parsed',
            // Two months before the sweep, so the message is unambiguously due.
            'retention_until' => '2026-09-25 12:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $messageId = (int) $wpdb->insert_id;

        $path = $storage->storeAttachment('%PDF-1.4 expired', 'pdf');
        $wpdb->insert($attachments, [
            'message_id' => $messageId,
            'filename' => 'expired bulletin.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => $path,
            'size_bytes' => 16,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $expiredAttachmentId = (int) $wpdb->insert_id;

        $store = new WordPressRetentionStore(new WordPressDatabaseConnection($wpdb), $storage);

        // The same two calls the retention job makes, then a repeat to show a
        // second sweep is safe.
        $store->removeExpiredMessageFiles($messageId, $now);
        $store->removeExpiredMessageFiles($messageId, $now);

        $wpdb->delete($attachments, ['id' => $expiredAttachmentId]);
        $wpdb->delete($messages, ['id' => $messageId]);
    }
}