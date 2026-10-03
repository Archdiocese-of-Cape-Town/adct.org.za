<?php

declare(strict_types=1);

/**
 * Stand-ins for the WordPress functions and classes the approval pipeline
 * touches, shared by every test that builds approver emails or resolves
 * recipients without a WordPress runtime.
 *
 * These used to live inside ApprovalNoticeJobTest, which made any other test
 * that needed them depend on that file's load order: run on its own, it saw
 * "WP_User not found". They are declared here, once, behind guards, and
 * required by the tests that use them.
 *
 * The guards matter because PHP treats a second declaration of a function or
 * class in the same namespace as a fatal, not a shadow — see the note in
 * RevertChangeHandlerTest, which drives this same copy through the globals
 * below rather than declaring its own.
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
         */
        class WP_Screen
        {
            public string $id = '';
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
         * holds. ParserPageTest used to declare its own copy that always
         * returned true; whichever file PHPUnit happened to include first won,
         * which made any capability gate in this namespace untestable. This
         * one is declared once, here, and every test in the namespace shares
         * it.
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