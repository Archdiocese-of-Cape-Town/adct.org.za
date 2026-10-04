<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Publishing;

use ADCT\ParishIntake\Core\Ports\MediaLibraryGatewayInterface;
use RuntimeException;

/**
 * The only place in the plugin that copies a file into the media library
 * (#172, ADR 0025).
 *
 * `wp_handle_sideload()` is the right call for a file that is already on this
 * server: it needs no HTTP upload, and it runs the same type, size and
 * extension checks a browser upload would, which a bare `copy()` would not.
 *
 * It is also the only sane way to get an attachment post created consistently,
 * because it is the function the media library itself uses to register a newly
 * arrived file. Writing the bytes with `wp_upload_bits()` and the post with
 * `wp_insert_post()` by hand would mean reimplementing the uniqueness check, the
 * extension check and the year/month directory split, and would drift from
 * WordPress the moment any of them changed.
 *
 * Nothing here is transactional, deliberately. `WordPressPublicationStore`
 * wraps publication in a transaction, and a rollback cannot undo a file write,
 * so the copy is confined to this adapter and the caller is expected to handle a
 * failure as "the event is published but has no source material" rather than as
 * "roll the publication back". The one thing folded back inside is the
 * `post_parent` re-parenting, which `WordPressEventMeta` does and which is
 * cheap to undo.
 */
final class WordPressMediaLibraryGateway implements MediaLibraryGatewayInterface
{
    public function sideload(array $file, array $overrides): array
    {
        $name = (string) ($file['name'] ?? '');
        $source = (string) ($file['tmp_name'] ?? '');
        if ($name === '' || $source === '') {
            throw new RuntimeException('The promotion cannot name the file it is copying.');
        }

        // `wp_handle_sideload()` declares its first parameter by reference
                // (`&$file`), because it writes back the stored name it settled on. An
                // array literal cannot be passed by reference, so this is a local
                // variable and not an inline array: passing one is a fatal `Error`, not
                // a warning. The stub in `tests/Support/WordPressMediaStubs.php`
                // carries the same signature on purpose, because a by-value stub
                // accepts the literal happily and the mistake becomes invisible.
                $upload = [
                    'name' => $name,
                    'tmp_name' => $source,
                    'size' => (int) ($file['size'] ?? 0),
                    'type' => (string) ($overrides['test_type'] ?? ''),
                    'error' => 0,
                ];

                $result = wp_handle_sideload($upload, $overrides);

        if (isset($result['error'])) {
            throw new RuntimeException('The media library refused the copy: ' . (string) $result['error']);
        }

        $storedFile = (string) ($result['file'] ?? '');
        if ($storedFile === '' || ! is_file($storedFile)) {
            throw new RuntimeException('The copy did not produce a file.');
        }

        $mediaId = wp_insert_attachment(
            [
                'post_mime_type' => (string) ($overrides['test_type'] ?? ''),
                'post_title' => $name,
                'post_content' => '',
                'post_status' => 'inherit',
                // Left at zero on purpose. `post_parent` is the one piece of the
                // promotion that is cheap to undo, so the store re-parents it
                // afterwards through the event meta rather than being handed an
                // id here.
                'post_parent' => 0,
                // Recorded so a promotion that fails immediately afterwards can
                // find the file it just wrote. WordPress ignores the key; the
                // real attachment path is held in its own meta.
                'file' => $storedFile,
            ],
            $storedFile,
            0,
            true
        );

        if (! is_int($mediaId) || $mediaId < 1) {
            // The bytes are on disk and nothing points at them. Leaving them
            // there would be an orphan in the uploads directory that nobody can
            // account for, so they go back off disk.
            if (is_file($storedFile)) {
                wp_delete_file($storedFile);
            }

            throw new RuntimeException('The media library did not accept the copied file.');
        }

        // WordPress derives sizes and thumbnails from attachment metadata, and
        // `wp_insert_attachment()` does not create any: without this the library
        // has no dimensions to render a poster with. A PDF has no image metadata
        // and returns an empty array, which is a success -- the bulletin is
        // still promoted and still linked to the event.
        $metadata = wp_generate_attachment_metadata($mediaId, $storedFile);

        if (is_array($metadata) && $metadata !== []) {
            wp_update_attachment_metadata($mediaId, $metadata);
        }

        return [
            'id' => $mediaId,
            'file' => $storedFile,
            'url' => (string) ($result['url'] ?? ''),
            'type' => (string) ($result['type'] ?? ''),
        ];
    }
}