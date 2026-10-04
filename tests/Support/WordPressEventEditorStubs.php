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

}