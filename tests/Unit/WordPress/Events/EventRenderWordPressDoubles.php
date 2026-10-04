<?php

declare(strict_types=1);

/**
 * WordPress doubles for the event renderers, shared by
 * PublicEventPageSourceMaterialTest and SingleEventTemplateSourceMaterialTest.
 *
 * Kept in one file because they must be declared exactly once for the whole
 * PHPUnit process and the two tests load in no guaranteed order. Every one is
 * guarded with function_exists(), so whichever test file is loaded first wins
 * and the other reuses it.
 *
 * Probed rather than assumed: `tests/Support/WordPressStubs.php` already
 * answers get_post_thumbnail_id, get_post_meta, esc_url, esc_html and
 * sanitize_text_field, and does not answer any of the ten below. Declaring a
 * function the shared file already has would shadow it for this namespace and
 * break every test that expects the shared behaviour, so the two lists have to
 * stay in step.
 *
 * @see tests/Unit/Architecture/WordPressStubSurfaceTest.php
 */
namespace ADCT\ParishIntake\WordPress\Events {

    /*
     * The renderer calls these unqualified, so they must exist in this
     * namespace or in the global one. Probing `tests/Support/WordPressStubs.php`
     * directly (rather than assuming, which was wrong on the first attempt)
     * showed which it already answers:
     *
     *   present globally: get_post_thumbnail_id, get_post_meta, esc_url, esc_html,
     *                     sanitize_text_field
     *   absent:           add_query_arg, home_url, wp_kses_post, wp_strip_all_tags,
     *                     sanitize_email, get_permalink, wp_get_attachment_image,
     *                     wp_get_attachment_image_src, wp_get_attachment_url
     *
     * Declaring an absent one *globally* here would collide with the shared
     * file's declarations for the whole suite, so they are declared here.
     * Each answers the one question the renderer asks of it, which is why none
     * of them reaches into WordPress behaviour beyond that.
     */

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\home_url')) {
        /** The site root, which the ICS feed URL is built from. */
        function home_url(string $path = ''): string
        {
            return 'https://adct.example.test' . $path;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\add_query_arg')) {
        /**
         * Query arguments on a URL. Real enough to keep the ICS URL
         * recognisable in a test failure, because `PublicIcsFeed::url()` calls
         * it on the way to every view build whether or not this test cares.
         */
        function add_query_arg(array|string $arguments, ?string $url = null): string
        {
            $url ??= home_url('/');
            $pairs = is_array($arguments) ? $arguments : [$arguments => ''];

            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($pairs);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_kses_post')) {
        /** Post-content filtering; these tests do not exercise the allow list. */
        function wp_kses_post(string $content): string
        {
            return $content;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_strip_all_tags')) {
        function wp_strip_all_tags(string $content): string
        {
            return strip_tags($content);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\sanitize_email')) {
        function sanitize_email(string $email): string
        {
            return $email;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\get_permalink')) {
        /** The event's own address, which the JSON-LD block names as its URL. */
        function get_permalink(mixed $post): string
        {
            return 'https://adct.example.test/events/' . ((int) $post->ID);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\has_post_thumbnail')) {
        /**
         * Backed by a global rather than hard-coded false, because AC5 turns on
         * this answer: a promoted poster calls `set_post_thumbnail()` elsewhere
         * and the figure must follow that, not this test's convenience.
         *
         * Reads `$GLOBALS['adct_test_has_thumbnail']` when that global names
         * the post, and otherwise falls back to "a thumbnail id is registered".
         * The indirection exists so a test can make the two WordPress functions
         * disagree: `has_post_thumbnail()` false while a thumbnail id is set is
         * a real state, and deriving one answer from the other would make that
         * state inexpressible, and so untested.
         */
        function has_post_thumbnail(mixed $post = null): bool
        {
            $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
            if ($id < 1) {
                return false;
            }

            if (array_key_exists($id, $GLOBALS['adct_test_has_thumbnail'] ?? [])) {
                return (bool) $GLOBALS['adct_test_has_thumbnail'][$id];
            }

            return (int) ($GLOBALS['adct_test_media_thumbnails'][$id] ?? 0) > 0;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_get_attachment_image_src')) {
        /**
         * Backed by the same `adct_test_attachment_urls` global as
         * `wp_get_attachment_url()`, because one attachment has one file: a
         * promoted poster the URL stub can resolve is one the image stub can
         * resolve too. Deriving both from one global keeps a test from
         * asserting "the poster renders" against a stub holding no file.
         *
         * @return array<int, mixed>|false
         */
        function wp_get_attachment_image_src(int $attachmentId, string $size = 'thumbnail'): array|false
        {
            $url = $GLOBALS['adct_test_attachment_urls'][(int) $attachmentId] ?? false;
            if (! is_string($url) || $url === '') {
                return false;
            }

            return [$url, 640, 480, false];
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_get_attachment_url')) {
        /**
         * The URL of an attachment's file: the only thing that turns a promoted
         * id into something a visitor can fetch.
         *
         * Backed by a global because the answer varies per test and because the
         * falsy answers matter as much as the real one. WordPress returns
         * `false` when the file is gone, and the page must read that as
         * "cannot be shown" rather than rendering a link with an empty href.
         */
        function wp_get_attachment_url(int $attachmentId): string|false
        {
            $url = $GLOBALS['adct_test_attachment_urls'][(int) $attachmentId] ?? false;

            return is_string($url) && $url !== '' ? $url : false;
        }
    }
}
