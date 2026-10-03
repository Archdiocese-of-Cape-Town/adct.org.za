<?php

declare(strict_types=1);

/**
 * Stand-ins for the WordPress functions and classes the plugin's admin and
 * approval layers touch, shared by every test that builds approver emails,
 * resolves recipients, or drives an admin page handler without a WordPress
 * runtime.
 *
 * These used to live inside ApprovalNoticeJobTest, which made any other test
 * that needed them depend on that file's load order: run on its own, it saw
 * "WP_User not found". They are declared here, once, behind guards, and
 * required by the tests that use them.
 *
 * The guards matter because PHP treats a second declaration of a function or
 * class in the same namespace as a fatal, not a shadow — see the note in
 * RevertChangeHandlerTest, which drives this same copy through the globals
 * below rather than declaring its own. `phpunit.xml.dist` has no bootstrap, so
 * every test file is included before any test runs and the first declaration to
 * load is the one every other file gets. A stub that returns a constant is
 * therefore not safe to add here if any consumer needs it to vary; those go
 * behind a global instead, which is what `is_admin()` and `current_user_can()`
 * do.
 *
 * Escaping is real, not identity, so a regression that drops escaping from an
 * approver email shows up as a failing expectation instead of passing silently.
 */

namespace ADCT\ParishIntake\WordPress\Auth {

    /**
     * Stand-ins so ActionTokenEndpoint::urlForToken() can build links in a
     * test without a WordPress runtime.
     */
    if (! function_exists('ADCT\ParishIntake\WordPress\Auth\home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://adct.example.test' . $path;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Auth\add_query_arg')) {
        function add_query_arg(string $key, string $value, string $url): string
        {
            return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . rawurlencode($value);
        }
    }
}

namespace {

    if (! class_exists('WP_User', false)) {
        /**
         * Stand-in for the WordPress user class ApprovalRecipients type-checks.
         */
        class WP_User
        {
            public int $ID = 0;
            public string $user_email = '';
            public int $user_status = 0;

            public function __construct(int $id, string $email, int $userStatus = 0)
            {
                $this->ID = $id;
                $this->user_email = $email;
                $this->user_status = $userStatus;
            }
        }
    }

    if (! class_exists('WP_Screen', false)) {
        /**
         * Stand-in for the WordPress screen object, in the global namespace
         * because that is where the real one lives and where WordPressHelp's
         * type hint and instanceof check look for it.
         *
         * add_help_tab() stores the tab instead of rendering it, exactly as the
         * real class does. WordPressHelpTest relies on that: the tab is read
         * back to prove it was registered, and WordPress renders it later.
         *
         * `base` mirrors the real class: the menu slug a submenu hangs off,
         * which is how an asset enqueue decides whether the screen it is on is
         * the one its assets were written for.
         */
        class WP_Screen
        {
            public string $id = '';
            public string $base = '';
            /** @var list<array{id: string, title: string, content: string}> */
            public array $help_tabs = [];

            public function add_help_tab(array $args): void
            {
                $this->help_tabs[] = [
                    'id' => (string) ($args['id'] ?? ''),
                    'title' => (string) ($args['title'] ?? ''),
                    'content' => (string) ($args['content'] ?? ''),
                ];
            }
        }
    }

    if (! class_exists('AdctTestRedirect', false)) {
        /**
         * Stands in for the request ending at `exit`, which a unit test cannot
         * survive. Throwing lets a test assert the redirect target and then
         * carry on with the next assertion.
         */
        class AdctTestRedirect extends \RuntimeException
        {
        }
    }

    if (! class_exists('AdctTestWpDie', false)) {
        /**
         * Carries the response code a guard chose, so a test can tell a 403 from
         * a 404 rather than only seeing that the request stopped.
         */
        class AdctTestWpDie extends \RuntimeException
        {
            public function __construct(string $message, public readonly int $status)
            {
                parent::__construct($message);
            }
        }
    }

    if (! class_exists('AdctTestNonceRefused', false)) {
        /**
         * Stands in for WordPress rejecting a bad or missing nonce. Separate from
         * AdctTestWpDie because the real function ends the request through a
         * different path and takes no status from the caller.
         */
        class AdctTestNonceRefused extends \RuntimeException
        {
        }
    }

    if (! function_exists('absint')) {
        /**
         * WordPress's absint() on an arbitrary request value.
         *
         * A handler that casts a POST value itself can raise a TypeError on a
         * crafted array; this cannot, which is the whole reason the production
         * code goes through it.
         */
        function absint(mixed $value): int
        {
            return abs((int) (is_scalar($value) ? (int) $value : 0));
        }
    }

    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field(mixed $value): string
        {
            return is_string($value) ? trim(strip_tags($value)) : '';
        }
    }

    if (! function_exists('esc_attr')) {
        function esc_attr(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('esc_url')) {
        /**
         * WordPress's esc_url percent-encodes the characters that would let a
         * value break out of an href. The approval and reminder emails rely on
         * that: a regression that stops encoding them shows up here as a
         * changed expectation rather than passing silently.
         */
        function esc_url(string $url): string
        {
            return str_replace(['"', "'", '<', '>'], ['%22', '%27', '%3C', '%3E'], $url);
        }
    }

    if (! function_exists('wp_nonce_field')) {
        /**
         * Emits the hidden nonce input the production markup pairs with every
         * POST form, and records the action it was minted for, so a test can read
         * back which form guards which action rather than only asserting a
         * constant. A mismatch between the two is invisible in production until a
         * press is silently refused, which is exactly the bug this catches.
         */
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true): void
        {
            $GLOBALS['adct_test_nonce_fields'][] = ['action' => $action, 'name' => $name];
            echo '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '" />';
        }
    }

    if (! function_exists('plugins_url')) {
        function plugins_url(string $path = '', string $pluginFile = ''): string
        {
            return 'https://adct.example.test/wp-content/plugins/adct-parish-intake/' . $path;
        }
    }

    if (! function_exists('wp_enqueue_style')) {
        function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string $version = ''): void
        {
            $GLOBALS['adct_test_styles'][] = compact('handle', 'src', 'deps', 'version');
        }
    }

    if (! function_exists('wp_enqueue_script')) {
        function wp_enqueue_script(
            string $handle,
            string $src = '',
            array $deps = [],
            string $version = '',
            bool $inFooter = false
        ): void {
            $GLOBALS['adct_test_scripts'][] = compact('handle', 'src', 'deps', 'version', 'inFooter');
        }
    }

    if (! function_exists('wp_safe_redirect')) {
        /**
         * Records the target and throws, so a test can prove where a handler
         * would have sent the reviewer without the request ending for real.
         */
        function wp_safe_redirect(string $location, int $status = 302): bool
        {
            $GLOBALS['adct_test_redirect'] = $location;

            throw new \AdctTestRedirect($location);
        }
    }

    if (! function_exists('wp_get_current_user')) {
        function wp_get_current_user(): \WP_User
        {
            return $GLOBALS['adct_test_current_user'] ?? new \WP_User(0, '');
        }
    }
}

namespace ADCT\ParishIntake\WordPress\Admin {

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\get_current_screen')) {
        function get_current_screen(): ?\WP_Screen
        {
            return $GLOBALS['adct_test_wp_screen'] ?? null;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\current_user_can')) {
        /**
         * Driven through a global, so a test can decide exactly what the user
         * holds. ParserPageTest declares its own copy that always returned true;
         * whichever file PHPUnit happened to include first won, which made any
         * capability gate in this namespace untestable. This one is declared
         * once, here, and every test in the namespace drives it.
         */
        function current_user_can(string $capability): bool
        {
            return in_array($capability, $GLOBALS['adct_test_wp_caps'] ?? [], true);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\add_action')) {
        /**
         * Records the hook rather than firing it, so a test can assert that
         * registration happened, exactly once, on the right hook.
         */
        function add_action(string $hook, callable $callback, int $priority = 10): bool
        {
            $GLOBALS['adct_test_wp_actions'][$hook][] = $callback;

            return true;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://adct.example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\add_query_arg')) {
        /**
         * The array form, as WordPress takes it. The Auth namespace above takes
         * the (key, value, url) form instead, matching what each caller uses.
         */
        function add_query_arg(array $args, string $url): string
        {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\is_admin')) {
        /**
         * Driven through a global so a test can assert that an asset enqueue
         * declines to run outside wp-admin. Defaulted to false, so a test that
         * has not said otherwise is not silently treated as an admin request.
         */
        function is_admin(): bool
        {
            return (bool) ($GLOBALS['adct_test_is_admin'] ?? false);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\wp_die')) {
        /**
         * Ends the request by throwing instead, so a test can assert the status
         * code a guard chose. Every caller in this plugin passes a response
         * argument, which is what the thrown exception carries.
         */
        function wp_die(string $message = '', $title = '', array $arguments = []): never
        {
            throw new \AdctTestWpDie($message, (int) ($arguments['response'] ?? 500));
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return is_string($value) ? stripslashes($value) : $value;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Admin\check_admin_referer')) {
        /**
         * Records the action and nonce name a handler demanded, and refuses only
         * when a test says it should.
         *
         * The recorded pair is the point. A handler that checked a different
         * action than the one its form was minted for passes every other
         * assertion in a test, so the pair itself has to be asserted.
         */
        function check_admin_referer(string $action = '-1', string $name = '_wpnonce'): void
        {
            $GLOBALS['adct_test_nonce_checks'][] = ['action' => $action, 'name' => $name];
            if (($GLOBALS['adct_test_nonce_should_fail'] ?? false) === true) {
                throw new \AdctTestNonceRefused($action);
            }
        }
    }
}

namespace ADCT\ParishIntake\WordPress\Approval {

    /**
     * Minimal stand-ins for the WordPress user helpers ApprovalRecipients
     * calls. Each test drives them through the globals below and restores
     * them in tearDown.
     */
    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\get_users')) {
        function get_users(array $args = []): array
        {
            return $GLOBALS['adct_test_wp_users'] ?? [];
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\get_userdata')) {
        function get_userdata(int $userId): ?\WP_User
        {
            return ($GLOBALS['adct_test_wp_users'] ?? [])[$userId] ?? null;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\user_can')) {
        function user_can(\WP_User $user, string $capability): bool
        {
            return in_array($capability, $GLOBALS['adct_test_wp_caps'][$user->ID] ?? [], true);
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\get_user_meta')) {
        function get_user_meta(int $userId, string $key, bool $single = false): string
        {
            return $GLOBALS['adct_test_wp_meta'][$userId][$key] ?? '';
        }
    }
}
