<?php

declare(strict_types=1);

/**
 * Stand-ins for the WordPress calls the public event page makes (issue #172).
 *
 * They exist for one reason: to let `PublicEventPage::viewForPost()` be
 * exercised end to end without WordPress, so the assertions can be about the
 * array the real template consumes rather than about a private method.
 *
 * Several carry the weight of the design and are therefore driven by their own
 * globals rather than answering with a constant:
 *
 *  - `get_children()` records every call in `adct_test_children_queries`. This
 *    is the trap the front end has to avoid: a promoted file is a child of the
 *    event because WordPress records the parent on the copy, so a broad "show
 *    me this event's attachments" query would find a file nobody decided to
 *    publish and put it on a public page. A stub that answered `[]` would let
 *    that bug pass; one that records the call and returns matching children
 *    will not.
 *  - `has_post_thumbnail()` and `wp_get_attachment_image_src()` read the
 *    thumbnail global, so a test can pose "promoted a poster" against the real
 *    code path that decides whether the poster figure renders.
 *  - `wp_strip_all_tags()` strips rather than escapes, exactly as WordPress
 *    does. A stub that escaped would make the escaping tests pass for the wrong
 *    reason.
 */
namespace {

    if (! defined('OBJECT')) {
        // WordPress's object-constant. The default parameter of `get_children()`
        // names it, so the stub set has to define it before the function is even
        // declared -- without it the file fatals at include time.
        define('OBJECT', 'OBJECT');
    }

    if (! function_exists('get_children')) {
        /**
         * The broad query #172 forbids on the front end. It answers with the
         * children the test described, so a caller that uses it gets plausible
         * data back and the assertion that it did not use it is what fails.
         */
        function get_children(array $args = [], string $output = OBJECT): array
        {
            $GLOBALS['adct_test_children_queries'][] = $args;

            $parentId = (int) ($args['post_parent'] ?? 0);
            if ($parentId < 1) {
                return [];
            }

            $children = [];
            foreach ($GLOBALS['adct_test_media_posts'] ?? [] as $id => $described) {
                if ((int) ($described['post_parent'] ?? 0) !== $parentId) {
                    continue;
                }

                $children[] = (object) ['ID' => (int) $id];
            }

            return $children;
        }
    }

    if (! function_exists('has_post_thumbnail')) {
        function has_post_thumbnail($post = null): bool
        {
            $postId = $post instanceof WP_Post ? $post->ID : (int) $post;

            return (int) ($GLOBALS['adct_publishing_featured'][(int) $postId] ?? 0) > 0;
        }
    }

    if (! function_exists('wp_get_attachment_image_src')) {
        /**
         * Answered from `adct_test_media_posts` so a test can pose a thumbnail
         * whose file is not where WordPress would put it.
         */
        function wp_get_attachment_image_src(int $attachmentId, string $size = 'thumbnail', bool $icon = false): array|false
        {
            $described = $GLOBALS['adct_test_media_posts'][$attachmentId] ?? [];
            $url = (string) ($described['url'] ?? ('https://adct.org.za/wp-content/uploads/site-' . $attachmentId . '.jpg'));
            $width = (int) ($described['width'] ?? 1200);
            $height = (int) ($described['height'] ?? 1600);

            return [$url, $width, $height, false];
        }
    }

    if (! function_exists('wp_strip_all_tags')) {
        /**
         * Strips, not escapes. This is the difference the escaping assertions
         * depend on: `wp_strip_all_tags()` must remove markup before the
         * description is escaped again on render.
         */
        function wp_strip_all_tags(string $text, bool $removeBreaks = false): string
        {
            $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
            $text = strip_tags((string) $text);

            if ($removeBreaks) {
                $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
            }

            return trim($text);
        }
    }

    if (! function_exists('wp_kses_post')) {
        function wp_kses_post(string $text): string
        {
            return $text;
        }
    }

    if (! function_exists('get_permalink')) {
        /**
         * WordPress accepts a post object here as well as an id, and
         * `PublicEventPage::jsonLd()` passes one. Two stubs in the suite declared
         * an `int`-only signature; this one is the union WordPress actually has,
         * so the real call site works whichever file happened to load first.
         */
        function get_permalink($post = 0, bool $leavename = false): string|false
        {
            $postId = $post instanceof WP_Post ? $post->ID : (int) $post;

            return 'https://adct.org.za/events/event-' . $postId . '/';
        }
    }

    if (! function_exists('sanitize_email')) {
        function sanitize_email(string $email): string
        {
            $clean = filter_var(trim($email), FILTER_SANITIZE_EMAIL);

            return is_string($clean) ? $clean : '';
        }
    }
}

namespace ADCT\ParishIntake\WordPress\Events {

    /**
     * `sanitize_text_field()` is what `PublicEventPage` resolves: production code
     * namespaced into the Events namespace imports the function from there.
     */
    if (! function_exists('ADCT\ParishIntake\WordPress\Events\sanitize_text_field')) {
        function sanitize_text_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }

    /**
     * Only ever called by `PublicIcsFeed::url()` with an array of arguments, which
     * is the signature WordPress prefers. The generated URL is not what these
     * tests are about, but it does have to be a real URL for the template's
     * `esc_url()` assertions to mean anything.
     */
    if (! function_exists('ADCT\ParishIntake\WordPress\Events\home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://adct.org.za' . $path;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\add_query_arg')) {
        function add_query_arg(string|array $key, string|int|array|null $value = null): string
        {
            $base = 'https://adct.org.za/';
            $arguments = [];

            if (is_string($key) && $key !== '') {
                $arguments = [$key => is_array($value) ? '' : (string) $value];
            } elseif (is_array($key)) {
                $arguments = $key;
            }

            return $base . '?' . http_build_query($arguments);
        }
    }
}