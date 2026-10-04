<?php

declare(strict_types=1);

/**
 * Stand-ins for the WordPress capability and user surface: current_user_can(),
 * user_can(), get_users(), get_userdata(), the user-meta pair, and the WP_User
 * object and current-user accessor that guards type-check against.
 *
 * These were in WordPressStubs.php, which mixed them with escaping, admin
 * hooks, nonce helpers and asset URLs. Two capabilities and a user object do
 * not belong in the same file as esc_html() and wp_enqueue_style(): a change
 * to an escaping stub then lands in the same diff as a change to a capability
 * guard, and every merge that touches one has to resolve the other. They are
 * separate here because they answer a different question — not "how is this
 * string rendered" but "is this user allowed to do this".
 *
 * The split is a move, not a rewrite. Every body below is byte-for-byte what
 * WordPressStubs.php declared, and WordPressStubSurfaceTest pins both files, so
 * a later edit has to state which surface it changed.
 *
 * Two of these are load-order sensitive and therefore live here, required by
 * the tests that need them, rather than in a test file. Admin
 * \current_user_can() and Approval \current_user_can() each had a competing
 * copy declared in a test, and whichever file PHPUnit included first won —
 * which silently gave one suite another's behaviour and made a capability gate
 * untestable. One declaration per namespace settles that for every consumer.
 *
 * Drive them through these globals:
 *
 *     $GLOBALS['adct_test_current_user']      \WP_User              acting user
 *     $GLOBALS['adct_test_current_user_id']   int                   acting user id
 *     $GLOBALS['adct_test_wp_caps']           user id => list<string>
 *     $GLOBALS['adct_test_wp_users']          user id => \WP_User
 *     $GLOBALS['adct_test_wp_meta']           user id => key => value
 *     $GLOBALS['adct_test_wp_meta_fails']     keys whose write reports
 *                                             success and stores nothing
 *
 * adct_test_wp_caps is deliberately keyed per user rather than kept as one flat
 * list: the guards worth testing are the ones where an entitlement is granted
 * to one reviewer and withheld from another in the same test, which a flat
 * list cannot express.
 */
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


        if (! function_exists('wp_get_current_user')) {
            function wp_get_current_user(): \WP_User
            {
                return $GLOBALS['adct_test_current_user'] ?? new \WP_User(0, '');
            }
        }

}

namespace ADCT\ParishIntake\WordPress\Admin {

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

}

namespace ADCT\ParishIntake\WordPress\Approval {
        if (! function_exists('ADCT\ParishIntake\WordPress\Approval\current_user_can')) {
            /**
             * The *acting* user's own capability, as WordPress decides it from the
             * current user rather than from a target. Distinct from user_can()
             * below, which answers about a named user, and from the
             * Admin-namespace copy, which answers about capabilities in wp-admin.
             *
             * The variadic second argument exists because production calls this
             * with a user ID for the 'edit_user' meta-capability. This stub has no
             * request context to resolve that ID against — resolving it properly
             * needs the roles WordPress loaded for the current user, which is a
             * WordPress service rather than plugin logic — so it answers from the
             * acting user's own capability list, and each test that relies on the
             * target having to say so explicitly. See
             * ReviewerNotificationPreferenceTest::testTheTargetIsGuardedSeparatelyFromTheActor().
             */
            function current_user_can(string $capability, int|string ...$arguments): bool
            {
                $actorId = (int) ($GLOBALS['adct_test_current_user_id'] ?? 0);

                return in_array($capability, $GLOBALS['adct_test_wp_caps'][$actorId] ?? [], true);
            }
        }


        if (! function_exists('ADCT\ParishIntake\WordPress\Approval\get_users')) {
            /**
             * Answers the 'capability' => REVIEW query ApprovalRecipients opens
             * with. Real get_users() filters on the capability server-side; this
             * applies the same filter, so a test that removes REVIEW from a
             * reviewer's $GLOBALS['adct_test_wp_caps'] row sees them drop out
             * here the way they would in WordPress.
             *
             * Note that ApprovalRecipients::forParish() re-checks the capability
             * with user_can() immediately afterwards, so this filter is not the
             * only thing enforcing the entitlement — a mutation that removes it
             * alone leaves the suite green, because the production guard still
             * refuses the user. The guard the tests actually exercise is
             * user_can(); this function decides who is even offered for checking.
             */
            function get_users(array $args = []): array
            {
                $users = $GLOBALS['adct_test_wp_users'] ?? [];
                $capability = $args['capability'] ?? null;

                if (! is_string($capability)) {
                    return $users;
                }

                return array_filter(
                    $users,
                    static fn (mixed $user): bool => in_array(
                        $capability,
                        $GLOBALS['adct_test_wp_caps'][$user instanceof \WP_User ? (int) $user->ID : (int) $user] ?? [],
                        true
                    )
                );
            }
        }


        if (! function_exists('ADCT\ParishIntake\WordPress\Approval\get_userdata')) {
            function get_userdata(int $userId): ?\WP_User
            {
                return ($GLOBALS['adct_test_wp_users'] ?? [])[$userId] ?? null;
            }
        }


        if (! function_exists('ADCT\ParishIntake\WordPress\Approval\user_can')) {
            /**
             * Accepts a user object or a bare ID, because production does both:
             * ApprovalRecipients and ConfirmationDecisionHandler pass the object
             * they already hold, while ReviewerNotificationPreference::save() has
             * only the ID WordPress hands the profile-update hook. The real
             * WordPress function takes either, so the stub must too.
             *
             * Capabilities are read per user from $GLOBALS['adct_test_wp_caps'],
             * keyed by user ID, not as one flat list, so a test can hold a
             * reviewer's entitlement while denying the same capability to someone
             * else — which is how the "who may this belong to" guards are tested.
             */
            function user_can(\WP_User|int $user, string $capability): bool
            {
                $userId = $user instanceof \WP_User ? $user->ID : $user;

                return in_array($capability, $GLOBALS['adct_test_wp_caps'][$userId] ?? [], true);
            }
        }


        if (! function_exists('ADCT\ParishIntake\WordPress\Approval\get_user_meta')) {
            function get_user_meta(int $userId, string $key, bool $single = false): string
            {
                return (string) ($GLOBALS['adct_test_wp_meta'][$userId][$key] ?? '');
            }
        }


        if (! function_exists('ADCT\ParishIntake\WordPress\Approval\update_user_meta')) {
            /**
             * Writes to the same global get_user_meta() reads, so a test can assert
             * what persisted without a database.
             *
             * Listing a key in $GLOBALS['adct_test_wp_meta_fails'] makes the write
             * report success while storing nothing, which is the only way to reach
             * the read-back branches in save(). On real WordPress a write can be
             * accepted and then not stick — a full object cache, a database that is
             * read-only — and that is precisely the case where telling the user
             * "saved" would be a lie.
             *
             * @param mixed $value
             */
            function update_user_meta(int $userId, string $key, $value): bool
            {
                if (in_array($key, (array) ($GLOBALS['adct_test_wp_meta_fails'] ?? []), true)) {
                    return true;
                }
                $GLOBALS['adct_test_wp_meta'][$userId][$key] = $value;

                return true;
            }
        }

}
