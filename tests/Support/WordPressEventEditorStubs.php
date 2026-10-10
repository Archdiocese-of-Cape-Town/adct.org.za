<?php

declare(strict_types=1);

/**
 * Stand-ins for the two WordPress calls the event editor makes that nothing else
 * in the suite needs: registering a meta box, and the post object WordPress hands
 * a box's render callback.
 *
 * They are here rather than in WordPressStubs.php or WordPressCapabilityStubs.php
 * because they answer neither "how is this string rendered" nor "is this user
 * allowed to do this". registerMetaBox() asks *what did the editor register*, and
 * the post-capability stub asks WordPress's own question — "may this user edit
 * this post" — which is a separate surface from the actor-level capability list
 * in WordPressCapabilityStubs.php and has to be driveable on its own, because the
 * only guard the audit box carries is that one.
 *
 * Drive them through these globals:
 *
 *     $GLOBALS['adct_test_meta_boxes']   array<string, array{title: string, screen: string}>
 *     $GLOBALS['adct_test_post_caps']    bool    may the acting user edit this post?
 */
namespace {

    if (! class_exists('WP_Post', false)) {
        /**
         * Only the identity and the handful of columns a caller may assign
         * matter here: the render callbacks type-check against this class and
         * read $post->ID, and the revert tests write back a before-snapshot's
         * title, content, excerpt, status and type. The properties are declared
         * rather than added on assignment because creating one dynamically is
         * deprecated on PHP 8.2 and would make every such test emit a
         * deprecation the suite is meant to be free of.
         */
        class WP_Post
        {
            public int $ID = 0;
            public string $post_title = '';
            public string $post_content = '';
            public string $post_excerpt = '';
            public string $post_status = 'publish';
            public string $post_type = 'adct_event';

            public function __construct(int $id = 0)
            {
                $this->ID = $id;
            }
        }
    }

        if (! class_exists('WP_Error', false)) {
        /**
         * The error object the REST guard returns.
         *
         * Only the three accessors the guard's own tests read are modelled.
         * `get_error_data()['status']` matters here: it is the HTTP status the
         * REST server will send, so a test asserting on it is asserting on what
         * a client actually receives rather than on a private field.
         */
        class WP_Error
        {
            /** @var array<int, array{code: string, message: string, data: mixed}> */
            private array $errors = [];

            public function __construct(string $code = '', string $message = '', mixed $data = '')
            {
                if ($code !== '') {
                    $this->errors[] = ['code' => $code, 'message' => $message, 'data' => $data];
                }
            }

            public function get_error_code(): string
            {
                return $this->errors[0]['code'] ?? '';
            }

            public function get_error_message(): string
            {
                return $this->errors[0]['message'] ?? '';
            }

            public function get_error_data(): mixed
            {
                return $this->errors[0]['data'] ?? null;
            }
        }
    }

    if (! class_exists('WP_REST_Request', false)) {
            /**
             * Just enough of a request for the editor's REST guard to be exercised.
             *
             * The guard reads one parameter and hands it back untouched, so a fake
             * that stores params in an array answers that question honestly. It is
             * declared here rather than pulled from a WordPress test library because
             * the suite runs on plain PHP with no WordPress, and this file already
             * stands in for the editor's own WordPress surface.
             */
            class WP_REST_Request
            {
                /** @var array<string, mixed> */
                private array $params = [];

                public string $method = 'GET';
                public string $route = '';

                public function __construct(string $method = 'GET', string $route = '')
                {
                    $this->method = $method;
                    $this->route = $route;
                }

                public function set_param(string $key, mixed $value): void
                {
                    $this->params[$key] = $value;
                }

                public function get_param(string $key): mixed
                {
                    return $this->params[$key] ?? null;
                }
            }
        }

    }

    namespace ADCT\ParishIntake\WordPress\Events {

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\add_meta_box')) {
            /**
             * Records what was registered instead of drawing it, so a test can ask
             * whether a box exists at all — which is the whole question the audit
             * box turns on: registered unconditionally it is an empty box on an
             * editor that was built without a trail to show, and never registered
             * it is a trail nobody can reach.
             */
        function add_meta_box(
            string $id,
                string $title,
                mixed $callback,
                string $screen,
                string $context = 'advanced',
                string $priority = 'default',
                mixed $callbackArgs = null
        ): void {
            $GLOBALS['adct_test_meta_boxes'][$id] = [
                'title' => $title,
                    'screen' => $screen,
                    'context' => $context,
                    'priority' => $priority,
                ];
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\remove_meta_box')) {
        function remove_meta_box(string $id, string $screen, string $context): void
        {
            $GLOBALS['adct_test_meta_boxes_removed'][] = $id;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\current_user_can')) {
            /**
             * WordPress answers 'edit_post' per post: the same reviewer may edit
             * their own draft and not someone else's published event. This stub
             * takes the same variadic object-id argument for that reason, and
             * answers it separately from the actor-level capability list, because
             * the two must be able to disagree for the guard to mean anything.
             */
        function current_user_can(string $capability, int|string ...$arguments): bool
        {
            return (bool) ($GLOBALS['adct_test_post_caps'] ?? false);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\check_admin_referer')) {
        /**
         * Answers WordPress's real question: is the nonce *for this action*?
         *
         * The comparison is against `$GLOBALS['adct_test_nonce_expected_action']`
         * rather than against the posted value, because the posted value is
         * attacker-supplied and a stub that trusted it would accept any nonce at
         * all -- which is the exact mistake trap #2 in issue #172 is about. A
         * nonce minted for the remove control is therefore refused by the add
         * handler, as it must be in WordPress.
         */
        function check_admin_referer(string $action = '-1', string $queryArg = '_wpnonce'): void
        {
            if (isset($GLOBALS['adct_test_nonce_should_fail'])) {
                throw new \AdctTestNonceRefused('The link you followed has expired.');
            }

            $expected = $GLOBALS['adct_test_nonce_expected_action'] ?? null;

            if ($expected === null || $expected !== $action || ! isset($_POST[$queryArg])) {
                throw new \AdctTestNonceRefused(
                    'The link you followed has expired.'
                );
            }

            $GLOBALS['adct_test_nonce_checks'][] = $action;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_nonce_field')) {
        /**
         * Records which nonce fields a screen rendered, so a test can assert that
         * add and remove are protected by two different fields rather than one
         * shared one.
         */
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
        {
            $GLOBALS['adct_test_nonce_fields'][$name] = $action;
            $field = '<input type="hidden" name="' . $name . '" value="' . $action . '" />';

            if ($display) {
                echo $field; // phpcs:ignore WordPress.Security.EscapeOutput
            }

            return $field;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\get_post_type')) {
        /**
         * Driveable per id so a test can prove that an event id pointing at some
         * other post type is refused before the capability check runs.
         */
        function get_post_type(int|string|object $post = 0): string|false
        {
            if ($post instanceof \WP_Post) {
                return $post->post_type;
            }

            return $GLOBALS['adct_test_post_types'][(int) $post] ?? 'adct_event';
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\admin_url')) {
        function admin_url(string $path = '', string $scheme = 'admin'): string
        {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_die')) {
        function wp_die(string $message = '', string $title = '', array $args = []): never
        {
            throw new \AdctTestWpDie($message, (int) ($args['response'] ?? 500));
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\wp_safe_redirect')) {
        function wp_safe_redirect(string $location, int $status = 302, string $xRedirectBy = 'WordPress'): bool
        {
            throw new \AdctTestRedirect($location);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return esc_html($text);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\esc_html_e')) {
        function esc_html_e(string $text, string $domain = 'default'): void
        {
            echo esc_html($text);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\selected')) {
        function selected(mixed $selected, mixed $current = true, bool $display = true): string
        {
            $attribute = ((string) $selected === (string) $current) ? ' selected="selected"' : '';

            if ($display) {
                echo $attribute; // phpcs:ignore WordPress.Security.EscapeOutput
            }

            return $attribute;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\esc_html')) {
        function esc_html(mixed $text): string
        {
            return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\esc_attr')) {
        function esc_attr(mixed $text): string
        {
            return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\esc_url')) {
        function esc_url(mixed $url): string
        {
            return htmlspecialchars((string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\absint')) {
        function absint(mixed $value): int
        {
            return abs((int) $value);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\error_log')) {
        /**
         * The editor logs an infrastructure failure server-side before showing the
         * publisher a generic 500. A stub that recorded nothing would let a test
         * prove the publisher was told nothing about the failure -- which is the
         * requirement -- without also pinning what was logged for the operator.
         * So it records, and `$GLOBALS['adct_test_error_log']` can be asserted on.
         */
        function error_log(string $message): bool
        {
            $GLOBALS['adct_test_error_log'][] = $message;

            return true;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Events\get_post_meta')) {
        function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
        {
            return $GLOBALS['adct_publishing_meta'][$postId][$key] ?? '';
        }
    }

}