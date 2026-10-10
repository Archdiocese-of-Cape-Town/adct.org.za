<?php

declare(strict_types=1);

/**
 * Stand-ins for the media-library and post-meta calls issue #172 promotes
 * source material through.
 *
 * They live in their own file because no other test needs them, and because
 * they answer a question the other stub files do not: "what did the adapter
 * actually try to store, and what did it do afterwards". The rest of the suite
 * warns against stubs that answer from constants; every one of these reads a
 * global, so a test can drive a success, a refusal, a specific stored name, or
 * a directory that is not there, and then ask what happened.
 *
 * Three details carry the weight of the design:
 *
 *  - `wp_handle_sideload()` keeps the `$file` array it was handed verbatim.
 *    WordPress derives the stored name from `$file['name']`, so the
 *    sanitisation tests assert on the name the adapter *tried* to store rather
 *    than on whatever ended up on disk afterwards.
 *  - it really writes the bytes it claims to have written. The gateway refuses
 *    a "copy" that produced no file, so a stub that only reported a path would
 *    make every promotion fail for the wrong reason and the assertion would be
 *    meaningless. The test therefore points `adct_test_uploads_dir` at a real
 *    temporary directory and deletes it afterwards.
 *  - `wp_delete_file()` really unlinks, after recording its argument. That lets
 *    one stub prove both halves of the file lifecycle: the gateway takes a
 *    half-promoted copy back off disk, while a *removal from the event* leaves
 *    the bytes exactly where they were.
 *
 * Drive them through these globals:
 *
 *     $GLOBALS['adct_test_sideloads']          list<array{file: array, overrides: array}>
 *     $GLOBALS['adct_test_sideload_error']     string   make wp_handle_sideload() refuse, with this code
 *     $GLOBALS['adct_test_empty_file']         bool     report a stored path without writing it
 *     $GLOBALS['adct_test_uploads_dir']        string   the real directory the stub writes into
 *     $GLOBALS['adct_test_sideload_file']      string   the path of the last reported copy
 *     $GLOBALS['adct_test_sideload_type']      string   the type the stubs report back
 *     $GLOBALS['adct_test_media_posts']        array<int, array> attachments, by id
 *     $GLOBALS['adct_test_media_meta']         array<int, array> metadata written, by id
 *     $GLOBALS['adct_test_media_deleted']      list<int>         attachments deleted
 *     $GLOBALS['adct_test_media_next_id']      int               next attachment id to hand out
 *     $GLOBALS['adct_test_generated_metadata'] array            wp_generate_attachment_metadata()'s answer
 *     $GLOBALS['adct_test_removed_files']      list<string>      wp_delete_file()'s arguments
 *     $GLOBALS['adct_test_delete_file_refused'] bool             make wp_delete_file() refuse
 *     $GLOBALS['adct_test_children_queries']    array            queries the adapter must never run
 *
 *     $GLOBALS['adct_publishing_meta']         array<int, array> post meta, by post id
 *     $GLOBALS['adct_publishing_featured']     array<int, int>    featured image, by post id
 *
 * A test can also pre-seed `adct_test_media_posts[ID]` to describe an attachment
 * that already exists, which is how the "parented to the event but never
 * promoted" case is posed.
 *
 * The post-meta and thumbnail functions are here for the same reason the media
 * ones are: promotion writes an ordered list of roles onto the event, and
 * reading it back is how "unpromoted material is unreachable" is asserted. They
 * are driven by their own globals rather than sharing `adct_test_media_posts`,
 * so a test that is only about the event's record does not have to pretend to
 * have a media library at all. None of them ever answers with a default value,
 * because a default here is exactly the thing `forEvent()` must not fall back
 * on.
 */
namespace {

    if (! function_exists('wp_handle_sideload')) {
        /**
         * WordPress uses the `name` in `$file` as the client's original filename
         * and derives the stored name from it (ADR 0025). That is exactly why
         * the gateway has to be handed a plugin-generated name, and exactly
         * why this stub records the array unchanged.
                 *
                 * The first parameter is declared **by reference on purpose**, because
                 * WordPress declares it that way: `_wp_handle_upload()` writes the
                 * sanitised name and the resolved path back into `$file`. A by-value
                 * stub silently accepts an array literal at the call site, which real
                 * WordPress refuses with a fatal `Error: cannot be passed by
                 * reference`. Only the real signature catches that class of mistake, so
                 * this stub has to carry it.
                 *
                 * @param array $file
                 */
                function wp_handle_sideload(array &$file, $overrides = false, $time = null): array
                {
                    $GLOBALS['adct_test_sideloads'][] = ['file' => $file, 'overrides' => $overrides];

            $error = (string) ($GLOBALS['adct_test_sideload_error'] ?? '');
            if ($error !== '') {
                return ['error' => $error];
            }

            $name = (string) ($file['name'] ?? '');
            $stored = rtrim((string) ($GLOBALS['adct_test_uploads_dir'] ?? '/tmp'), '/') . '/' . $name;
            $GLOBALS['adct_test_sideload_file'] = $stored;

            // A real copy, so that "did this really write anything?" is a
            // question about the adapter rather than about the stub.
            if (empty($GLOBALS['adct_test_empty_file'])) {
                $source = (string) ($file['tmp_name'] ?? '');
                if ($source !== '' && is_file($source)) {
                    copy($source, $stored);
                } else {
                    file_put_contents($stored, 'adct-test-upload');
                }
            }

            // WordPress writes the settled name and path back into `$file`, so
                        // the stub does too. A test can then assert on what the caller was
                        // left holding, which is the only place a stored name is visible.
                        $file['name'] = $name;
                        $file['file'] = $stored;
                        $file['url'] = 'https://adct.org.za/wp-content/uploads/' . $name;
                        $file['type'] = (string) ($GLOBALS['adct_test_sideload_type'] ?? 'image/jpeg');

                        return [
                            'file' => $stored,
                            'url' => 'https://adct.org.za/wp-content/uploads/' . $name,
                            'type' => (string) ($GLOBALS['adct_test_sideload_type'] ?? 'image/jpeg'),
                        ];
                    }
    }

    if (! function_exists('wp_check_filetype_and_ext')) {
        /**
         * WordPress refuses a sideload whose type does not match the extension.
         * The gateway passes an explicit `test_type`, and this answers from a
         * global so a test can prove the type was supplied rather than left to
         * WordPress to guess from a parish filename.
         */
        function wp_check_filetype_and_ext(string $file, string $filename, array $mimes = null): array
        {
            return [
                'ext' => (string) pathinfo($filename, PATHINFO_EXTENSION),
                'type' => (string) ($GLOBALS['adct_test_sideload_type'] ?? 'image/jpeg'),
                'proper_filename' => false,
            ];
        }
    }

    if (! function_exists('wp_insert_attachment')) {
        /**
         * Hands out ids from a global rather than incrementing its own counter,
         * so a test can make an insert fail by setting the next id to 0. That
         * is the case the gateway has to clean up after itself.
         */
        function wp_insert_attachment(array $args, mixed $file = false, int $parentPostId = 0, bool $wpError = false): int
        {
            $id = (int) ($GLOBALS['adct_test_media_next_id'] ?? 0);
            if ($id < 1) {
                return 0;
            }

            $GLOBALS['adct_test_media_next_id'] = $id + 1;
            $GLOBALS['adct_test_media_posts'][$id] = $args;

            return $id;
        }
    }

    if (! function_exists('wp_update_attachment_metadata')) {
        /**
         * Records only. WordPress returns false when there is nothing worth
         * storing -- a PDF has no dimensions -- and the gateway has to treat
         * that as a success rather than as a failure.
         */
        function wp_update_attachment_metadata(int $attachmentId, array $data): bool
        {
            $GLOBALS['adct_test_media_meta'][$attachmentId] = $data;

            return true;
        }
    }

    if (! function_exists('wp_generate_attachment_metadata')) {
        function wp_generate_attachment_metadata(int $attachmentId, string $file): array
        {
            return (array) ($GLOBALS['adct_test_generated_metadata'] ?? ['width' => 600, 'height' => 800]);
        }
    }

    if (! function_exists('wp_update_post')) {
        function wp_update_post(array $postarr = [], bool $wpError = false): int
        {
            $id = (int) ($postarr['ID'] ?? 0);
            $GLOBALS['adct_test_media_posts'][$id]['post_parent'] = (int) ($postarr['post_parent'] ?? 0);

            return $id;
        }
    }

    if (! function_exists('wp_delete_attachment')) {
        function wp_delete_attachment(int $attachmentId, bool $forceDelete = false): bool
        {
            $GLOBALS['adct_test_media_deleted'][] = $attachmentId;
            unset($GLOBALS['adct_test_media_posts'][$attachmentId]);

            return true;
        }
    }

    if (! function_exists('wp_get_attachment_url')) {
        function wp_get_attachment_url(int $attachmentId): string|false
        {
            return 'https://adct.org.za/wp-content/uploads/attachment-' . $attachmentId . '.jpg';
        }
    }

    if (! function_exists('get_attached_file')) {
        function get_attached_file(int $attachmentId, bool $unfiltered = false): string|false
        {
            $args = (array) ($GLOBALS['adct_test_media_posts'][$attachmentId] ?? []);

            return isset($args['file']) ? (string) $args['file'] : false;
        }
    }

    if (! function_exists('wp_delete_file')) {
        /**
         * WordPress's wrapper around unlink(), used in preference to unlink()
         * itself so that a filter gets its say.
         */
        function wp_delete_file(string $file): bool
        {
            if (isset($GLOBALS['adct_test_delete_file_refused'])) {
                return false;
            }

            $GLOBALS['adct_test_removed_files'][] = $file;

            return is_file($file) ? unlink($file) : true;
        }
    }

    if (! function_exists('get_post_meta')) {
        /**
         * Answers '' for a key that was never written, exactly as WordPress
         * does, so a test cannot mistake an absent record for an empty one.
         */
        function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
        {
            $meta = $GLOBALS['adct_publishing_meta'][(int) $postId] ?? [];

            if ($key === '') {
                return $meta;
            }

            return $meta[$key] ?? '';
        }
    }

    if (! function_exists('update_post_meta')) {
        function update_post_meta(int $postId, string $key, mixed $value, mixed $prevValue = ''): bool
        {
            $GLOBALS['adct_publishing_meta'][(int) $postId][$key] = $value;

            return true;
        }
    }

    if (! function_exists('delete_post_meta')) {
        function delete_post_meta(int $postId, string $key, mixed $value = ''): bool
        {
            unset($GLOBALS['adct_publishing_meta'][(int) $postId][$key]);

            return true;
        }
    }

    if (! function_exists('get_post_thumbnail_id')) {
            // WordPress takes a post object as well as an id, and `PublicEventPage`
            // passes one. This stub was `int`-only, which made a correct call site
            // look like a type error.
            function get_post_thumbnail_id($post = null): int
            {
                $postId = $post instanceof WP_Post ? $post->ID : (int) $post;

                return (int) ($GLOBALS['adct_publishing_featured'][$postId] ?? 0);
            }
        }

    if (! function_exists('set_post_thumbnail')) {
        function set_post_thumbnail(int $postId, int $thumbnailId): bool
        {
            if ($thumbnailId < 1) {
                return false;
            }

            $GLOBALS['adct_publishing_featured'][(int) $postId] = $thumbnailId;

            return true;
        }
    }

    if (! function_exists('delete_post_thumbnail')) {
        function delete_post_thumbnail(int $postId): bool
        {
            unset($GLOBALS['adct_publishing_featured'][(int) $postId]);

            return true;
        }
    }
}