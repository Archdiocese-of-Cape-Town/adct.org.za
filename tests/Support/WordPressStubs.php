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
         *
         * Returns the markup *and* echoes it, because that is what WordPress
         * does: the real function is `string wp_nonce_field(..., $display = true)`
         * and it echoes before returning. `FrontEndApprovalQueue.php:404`
         * concatenates the return value, so a stub that echoed but returned void
         * would hand that form no nonce field and let the assertions pass.
         */
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true): string
        {
            $GLOBALS['adct_test_nonce_fields'][] = ['action' => $action, 'name' => $name];
            $markup = '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '" />';
            echo $markup;

            return $markup;
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

        /*
         * ---------------------------------------------------------------------
         * The media library, for issue #172's promotion copier.
         *
         * Every stub below is driven by `$GLOBALS['adct_test_media']` rather than
         * returning a constant, because the copier's behaviour is entirely in what
         * it *does* to the filesystem and to the attachment rows, and a
         * constant-returning stub would let the copier's "undo everything on
         * failure" path pass without anything ever having been created.
         *
         * What each one answers, so the surface test's list is reviewable:
         *
         *  - wp_upload_dir          the uploads path, writable or not
         *  - wp_handle_sideload     "moves" tmp_name to uploads, records the call,
         *                           can report an error, and can move to a *different*
         *                           final name than the one offered — which is what
         *                           real collision handling does and what the
         *                           copier's unlink-the-moved-path logic needs to be
         *                           tested against
         *  - wp_insert_attachment   a new attachment row, or a WP_Error, or 0
         *  - wp_generate_attachment_metadata  may throw, to test the rollback
         *  - wp_update_attachment_metadata   records the array, or can throw
         *  - wp_delete_attachment   removes the row *and* the file, as WordPress does
         *                           with $force = true
         *  - get_post               reads back a row created above
         *  - wp_update_post         changes a row's post_parent, or returns a WP_Error
         *  - get_post_meta/update_post_meta/delete_post_meta  the attachment/event
         *                           meta rows. NOT declared here: get_post_meta,
         *                           update_post_meta, delete_post_meta, get_post and
         *                           is_wp_error already exist in
         *                           ADCT\ParishIntake\WordPress\Auth (RevertChangeHandlerTest
         *                           and WordPressStubs' own Auth block) and a second
         *                           declaration in the global namespace is not a
         *                           conflict — but the *global* ones are separate, and
         *                           WordPressSourceMaterialStore calls the global ones
         *                           unqualified, so they are declared here. RevertChangeHandlerTest
         *                           drives its own copies through $GLOBALS['revert_meta'],
         *                           so the two never share state.
         */

        if (! function_exists('get_post_meta')) {
            /**
             * The single-value form, as the promotion store and the copier's
             * "remember the parish's filename" call both use. A missing key is an
             * empty string, which is WordPress's answer and which
             * SourceMaterialReference::listFromStored() has to treat as "nothing
             * promoted".
             */
            function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
            {
                return $GLOBALS['adct_test_media_meta'][(int) $postId][$key] ?? '';
            }
        }

        if (! function_exists('update_post_meta')) {
            function update_post_meta(int $postId, string $key, mixed $value): bool
            {
                $GLOBALS['adct_test_media_meta'][(int) $postId][$key] = $value;
                $GLOBALS['adct_test_meta_writes'][] = [(int) $postId, $key, $value];

                return true;
            }
        }

        if (! function_exists('delete_post_meta')) {
            /**
             * Records the deletion so a test can prove the promotion store *deletes*
             * the key rather than writing an empty array. That distinction is the
             * whole reason a removed promotion leaves no stored value behind.
             */
            function delete_post_meta(int $postId, string $key, bool $deleteAll = false): bool
            {
                $existed = array_key_exists($key, $GLOBALS['adct_test_media_meta'][(int) $postId] ?? []);
                unset($GLOBALS['adct_test_media_meta'][(int) $postId][$key]);
                $GLOBALS['adct_test_meta_deletes'][] = [(int) $postId, $key, $existed];

                return $existed;
            }
        }

        if (! function_exists('get_post')) {
            function get_post(mixed $post = null): mixed
            {
                return $GLOBALS['adct_test_media_posts'][(int) $post] ?? null;
            }
        }

        if (! function_exists('wp_upload_dir')) {
            /**
             * Answers the shape WordPress does, including 'error', because the
             * copier's refusal path depends on an error being distinguishable from
             * a missing path.
             *
             * @return array<string, mixed>
             */
            function wp_upload_dir(string $time = null, bool $createDir = true): array
            {
                $state = $GLOBALS['adct_test_media_uploads'] ?? [];

                if (isset($state['error'])) {
                    return ['error' => $state['error'], 'path' => '', 'url' => '', 'subdir' => ''];
                }

                $path = $state['path'] ?? (sys_get_temp_dir() . '/adct-test-uploads');

                return [
                    'path' => $path,
                    'url' => 'https://adct.example.test/wp-content/uploads/' . basename($path),
                    'subdir' => '',
                    'error' => false,
                ];
            }
        }

        if (! function_exists('wp_handle_sideload')) {
            /**
             * "Moves" the offered file the way WordPress does: rename it into the
             * uploads directory under `$GLOBALS['adct_test_media']['sideload_name']`
             * when set (collision handling), and the offered name otherwise.
             *
             * @return array<string, mixed>
             */
            function wp_handle_sideload(
                array &$file,
                int $postId = 0,
                            $deprecated = false,
                array $overrides = []
            ): array {
                $state = $GLOBALS['adct_test_media'] ?? [];

                $GLOBALS['adct_test_sideloads'][] = [
                    'file' => $file,
                    'post_id' => $postId,
                    'overrides' => $overrides,
                ];

                if (isset($state['sideload_error'])) {
                    return ['error' => $state['sideload_error']];
                }

                $source = (string) ($file['tmp_name'] ?? '');
                $directory = (string) ($GLOBALS['adct_test_media_uploads']['path'] ?? sys_get_temp_dir());
                $name = (string) ($state['sideload_name'] ?? basename($source));
                $moved = rtrim($directory, '/\\') . '/' . $name;

                if ($source !== '' && is_file($source)) {
                    @copy($source, $moved);
                    @unlink($source);
                } elseif (! isset($state['sideload_no_file'])) {
                    // Mirror WordPress: no source file means nothing was moved.
                    return ['error' => 'Specified file failed upload test.'];
                }

                return [
                    'file' => $moved,
                    'url' => 'https://adct.example.test/wp-content/uploads/' . $name,
                    'type' => $state['sideload_type'] ?? 'application/octet-stream',
                    'name' => $name,
                ];
            }
        }

        if (! function_exists('wp_insert_attachment')) {
            /**
             * Creates an attachment row with a fresh id, or fails in whichever way
             * `$GLOBALS['adct_test_media']['insert']` asks for: 'wp_error', 0, or
             * nothing at all (the success case).
             *
             * @param array<string, mixed> $args
             * @param string                $file
             * @return int|\WP_Error
             */
            function wp_insert_attachment(array $args, string $file = '', int $parentPostId = 0, bool $wpError = false)
            {
                $state = $GLOBALS['adct_test_media'] ?? [];

                $GLOBALS['adct_test_attachment_inserts'][] = [
                    'args' => $args,
                    'file' => $file,
                    'parent' => $parentPostId,
                    'wp_error' => $wpError,
                ];

                if (($state['insert'] ?? null) === 'wp_error') {
                    return new \WP_Error('insert_error', 'Could not insert attachment.');
                }

                if (($state['insert'] ?? null) === 0) {
                    return 0;
                }

                $id = $GLOBALS['adct_test_media_next_id'] ?? 900;
                $GLOBALS['adct_test_media_next_id'] = $id + 1;

                $row = new \stdClass();
                $row->ID = $id;
                $row->post_parent = (int) ($args['post_parent'] ?? 0);
                $row->post_mime_type = (string) ($args['post_mime_type'] ?? '');
                $row->post_title = (string) ($args['post_title'] ?? '');
                $row->post_status = 'inherit';
                $row->guid = $GLOBALS['adct_test_media']['guid'] ?? ('https://adct.example.test/wp-content/uploads/' . basename($file));
                $row->post_type = 'attachment';

                $GLOBALS['adct_test_media_posts'][$id] = $row;
                $GLOBALS['adct_test_media_files'][$id] = $file;

                return $id;
            }
        }

        if (! function_exists('wp_generate_attachment_metadata')) {
            /**
             * May throw when asked to, so the copier's rollback of a file that has
             * already been moved into the uploads directory can be exercised.
             *
             * @return array<string, mixed>
             */
            function wp_generate_attachment_metadata(int $attachmentId, string $file): array
            {
                $GLOBALS['adct_test_metadata_generated'][] = $attachmentId;

                if (($GLOBALS['adct_test_media']['metadata_throws'] ?? false) === true) {
                    throw new \RuntimeException('Image resizing failed.');
                }

                return ['file' => basename($file), 'sizes' => []];
            }
        }

        if (! function_exists('wp_update_attachment_metadata')) {
            function wp_update_attachment_metadata(int $attachmentId, array $metadata): bool
            {
                $GLOBALS['adct_test_metadata_written'][$attachmentId] = $metadata;

                return true;
            }
        }

        if (! function_exists('wp_get_attachment_metadata')) {
            function wp_get_attachment_metadata(int $attachmentId = 0, bool $unfiltered = false): mixed
            {
                return $GLOBALS['adct_test_metadata_written'][(int) $attachmentId] ?? false;
            }
        }

        if (! function_exists('wp_delete_attachment')) {
            /**
             * WordPress with $force = true removes the row *and* unlinks the file it
             * names. The copier's rollback depends on that, so the stub does the same
             * rather than only forgetting the row — a stub that just unset the row
             * would make the "no orphan file after a failure" test pass for the wrong
             * reason.
             */
            function wp_delete_attachment(int $attachmentId, bool $forceDelete = false): mixed
            {
                $GLOBALS['adct_test_attachment_deletes'][] = [$attachmentId, $forceDelete];

                $file = $GLOBALS['adct_test_media_files'][(int) $attachmentId] ?? '';

                if ($forceDelete && $file !== '' && is_file($file)) {
                    @unlink($file);
                }

                unset(
                    $GLOBALS['adct_test_media_posts'][(int) $attachmentId],
                    $GLOBALS['adct_test_media_files'][(int) $attachmentId],
                    $GLOBALS['adct_test_metadata_written'][(int) $attachmentId]
                );

                return true;
            }
        }

        if (! function_exists('wp_update_post')) {
            /**
             * Only the fields the copier sets are honoured: the promotion path
             * changes `post_parent` to detach a file from an event. Anything else is
             * accepted and ignored rather than rejected, so an unrelated caller in
             * the suite is unaffected.
             *
             * @param array<string, mixed> $data
             * @return int|\WP_Error
             */
            function wp_update_post(array $data = [], bool $wpError = false): mixed
            {
                $id = (int) ($data['ID'] ?? 0);
                $GLOBALS['adct_test_post_updates'][] = $data;

                if (($GLOBALS['adct_test_media']['update_error'] ?? false) === true) {
                    return new \WP_Error('update_error', 'Could not update post.');
                }

                $row = $GLOBALS['adct_test_media_posts'][$id] ?? null;

                if ($row === null) {
                    return $wpError ? new \WP_Error('invalid_post', 'Invalid post ID.') : 0;
                }

                foreach (['post_parent', 'post_title', 'post_status', 'post_content', 'post_excerpt'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $row->{$field} = $field === 'post_parent' ? (int) $data[$field] : (string) $data[$field];
                    }
                }

                return $id;
            }
        }

        if (! function_exists('get_post_thumbnail_id')) {
            /**
             * Reads both `$GLOBALS['adct_test_media_thumbnails']` (this file's) and
             * `$GLOBALS['revert_meta'][...]['_thumbnail_id']` (RevertChangeHandlerTest's),
             * because phpunit.xml.dist has no bootstrap and every test file is included
             * before any test runs. Whichever of the two files PHPUnit happens to load
             * first wins the `function_exists` guard, and a stub that only knew its own
             * global would silently answer "no thumbnail" for the other file's tests.
             * Reading both makes the guard order irrelevant.
             */
            function get_post_thumbnail_id(int $postId = 0): int
            {
                $media = (int) ($GLOBALS['adct_test_media_thumbnails'][(int) $postId] ?? 0);
                $revert = (int) ($GLOBALS['revert_meta'][(int) $postId]['_thumbnail_id'] ?? 0);

                return $media !== 0 ? $media : $revert;
            }
        }

        if (! function_exists('set_post_thumbnail')) {
            function set_post_thumbnail(int $postId, int $thumbnailId): bool
            {
                $GLOBALS['adct_test_media_thumbnails'][(int) $postId] = (int) $thumbnailId;
                $GLOBALS['revert_meta'][(int) $postId]['_thumbnail_id'] = (int) $thumbnailId;

                return true;
            }
        }

        if (! function_exists('delete_post_thumbnail')) {
            function delete_post_thumbnail(int $postId): bool
            {
                unset(
                    $GLOBALS['adct_test_media_thumbnails'][(int) $postId],
                    $GLOBALS['revert_meta'][(int) $postId]['_thumbnail_id']
                );

                return true;
            }
        }

        if (! function_exists('is_wp_error')) {
            function is_wp_error(mixed $thing): bool
            {
                return $thing instanceof \WP_Error;
            }
        }

        if (! class_exists('WP_Error', false)) {
            /**
             * Only as much of WP_Error as the media stubs need: the copier checks
             * `is_wp_error()` on the results of wp_insert_attachment() and
             * wp_update_post(), and reads the message. The properties are public so a
             * test can assert which failure came back.
             */
            class WP_Error
            {
                /**
                 * @param array<string, mixed> $data
                 */
                public function __construct(
                    public readonly string $code = '',
                    public readonly string $message = '',
                    public readonly array $data = []
                ) {
                }

                public function get_error_message(): string
                {
                    return $this->message;
                }

                public function get_error_code(): string
                {
                    return $this->code;
                }
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
        /**
         * Both documented forms, because the queue listing calls the 3-argument one:
         * `add_query_arg( 'candidate', $id, $url )`.
         *
         * A union rather than two overloads, so that mixing the shapes up fails
         * loudly at the call site instead of silently dropping the key.
         */
        function add_query_arg(string|array $args, string|int|array|null $url = null, string|int|null $third = null): string
        {
            if (is_array($args)) {
                $target = (string) $url;
                $pairs = $args;
            } else {
                $target = (string) $third;
                $pairs = [$args => $url];
            }
            return $target . (str_contains($target, '?') ? '&' : '?') . http_build_query($pairs);
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
     * Stand-ins for the WordPress functions the approval surfaces call.
     *
     * Two of them are load-order sensitive and therefore declared here rather
     * than in a test file. current_user_can() and wp_die() already have
     * namespace-specific doubles in other test files — an Approval-namespace
     * current_user_can() in FrontEndApprovalQueueTest.php and an
     * Admin-namespace wp_die() in AuditLogPageTest.php — and whichever is
     * loaded first wins, which would silently give one test another's
     * behaviour. Declaring them once here, against the fully qualified name,
     * settles that race for every test in the suite.
     *
     * Each test drives these through the globals named below and clears them
     * again in tearDown().
     */

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\wp_die')) {
        /**
         * Real wp_die() ends the request, which no assertion could observe.
         * Throwing \AdctTestWpDie instead carries the response status a guard
         * chose, so a test can tell a refused permission from a refused value
         * rather than only seeing that the request stopped.
         *
         * @param mixed[] $arguments
         */
        function wp_die(string $message = '', $title = '', array $arguments = []): never
        {
            throw new \AdctTestWpDie($message, (int) ($arguments['response'] ?? 500));
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\selected')) {
        function selected(mixed $current, mixed $value, bool $echo = true): string
        {
            $markup = (string) $current === (string) $value ? " selected='selected'" : '';

            if ($echo) {
                echo $markup;
            }

            return $markup;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\checked')) {
        function checked(mixed $current, mixed $value, bool $echo = true): string
        {
            $markup = (string) $current === (string) $value ? " checked='checked'" : '';

            if ($echo) {
                echo $markup;
            }

            return $markup;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\add_action')) {
        /**
         * Records the hook rather than firing it, so a test can assert that
         * registration happened on the right hook with the right callable.
         */
        function add_action(string $hook, callable $callback, int $priority = 10): bool
        {
            $GLOBALS['adct_test_wp_actions'][$hook][] = $callback;

            return true;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\esc_html__')) {
        function esc_html__(string $text, string $domain = 'default'): string
        {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\esc_html')) {
        function esc_html(mixed $value): string
        {
            return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return is_string($value) ? stripslashes($value) : $value;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\wp_nonce_field')) {
        /**
         * Same contract as the global copy above, declared again in this
         * namespace because FrontEndApprovalQueueTest already declared one that
         * only returned markup and never recorded what it minted. Under
         * executionOrder="random" that copy won some runs and not others, so the
         * same assertion passed or failed on the order PHPUnit happened to pick
         * files up in. One declaration, shared by both, settles it.
         */
        function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true): string
        {
            $GLOBALS['adct_test_nonce_fields'][] = ['action' => $action, 'name' => $name];
            $markup = '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '" />';
            echo $markup;

            return $markup;
        }
    }

    if (! function_exists('ADCT\ParishIntake\WordPress\Approval\wp_verify_nonce')) {
        /**
         * Answers only for the nonce minted for exactly this action, so a
         * handler that verifies the wrong action — here the one carrying the
         * user ID — fails its own test instead of passing on any truthy value.
         */
        function wp_verify_nonce(string $nonce, string $action): bool
        {
            return $nonce === 'nonce-for-' . $action;
        }
    }
}
