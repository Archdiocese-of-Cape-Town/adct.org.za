<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Architecture;

use ADCT\ParishIntake\Tests\Support\StubSurface;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WordPress surface the shared stub files provide, so a future feature
 * cannot widen it by accident.
 *
 * The failure this exists to prevent (#216): the shared stubs are a mutable
 * surface with no owner, and a new stub that answers permissively — a
 * `current_user_can()` that returns true, an `is_email()` that accepts
 * everything — shapes the behaviour of every unrelated suite that happens to
 * load the same file, and does so silently. Nothing in a normal test run
 * notices, because the tests that would notice are the ones the stub was never
 * meant to serve.
 *
 * So the surface itself is a test subject. Each stub file's own declarations
 * are listed below and asserted exactly:
 *
 *  - Adding a function is a visible diff against this list. That is the point:
 *    it forces the author to state what the new stub answers and why.
 *  - Removing one is a visible diff too, so a stub cannot quietly disappear and
 *    leave a test passing for the wrong reason.
 *  - Renaming or re-namespacing one is a visible diff, which is what makes the
 *    later surface-by-surface split reviewable one move at a time.
 *
 * The lists are read per file rather than as one union, because a shared file's
 * union is exactly the thing that grew unchecked: if the whole surface were
 * pinned as one list, adding to any file would look like an unrelated edit
 * somewhere far away. Per file, the diff says which surface gained the stub.
 *
 * `StubSurface` tokenises the file rather than loading it, because every test
 * file is included into one process before any test runs; asking what is
 * defined at runtime would report the union of load order, not this file's
 * contribution.
 */
final class WordPressStubSurfaceTest extends TestCase
{
    /**
     * The files under tests/Support. A new file here has to be listed, so the
     * shared directory cannot grow by silence.
     *
     * @var list<string>
     */
    private const STUB_FILES = [
        'AdminWordPressStubs.php',
        'EmailFixtureLoader.php',
        'EmailFixtureResult.php',
        'FixtureComparator.php',
        'FixtureDirectorySnapshotLoader.php',
        'NotifyModeFakes.php',
        'StubSurface.php',
        'WordPressAuthDoubles.php',
        'WordPressOptionsStubs.php',
        'WordPressStubs.php',
    ];

    /**
     * The free-function WordPress surface each file declares.
     *
     * Namespace-qualified names are what production code actually resolves at a
     * call site: `ADCT\ParishIntake\WordPress\Approval\current_user_can()` is a
     * different declaration from the Admin-namespace one, and a test in one
     * namespace cannot be shaped by the other. Global names are bare, because
     * production calling unqualified `esc_url()` from a namespace resolves
     * through the file's `use` statements to the global one.
     *
     * @var array<string, list<string>>
     */
    private const FUNCTION_SURFACE = [
        'AdminWordPressStubs.php' => [
            'ADCT\ParishIntake\WordPress\Admin\absint',
            'ADCT\ParishIntake\WordPress\Admin\add_query_arg',
            'ADCT\ParishIntake\WordPress\Admin\admin_url',
            'ADCT\ParishIntake\WordPress\Admin\error_log',
            'ADCT\ParishIntake\WordPress\Admin\esc_attr',
            'ADCT\ParishIntake\WordPress\Admin\esc_html',
            'ADCT\ParishIntake\WordPress\Admin\esc_html__',
            'ADCT\ParishIntake\WordPress\Admin\esc_url',
            'ADCT\ParishIntake\WordPress\Admin\esc_url_raw',
            'ADCT\ParishIntake\WordPress\Admin\sanitize_text_field',
            'ADCT\ParishIntake\WordPress\Admin\selected',
            'ADCT\ParishIntake\WordPress\Admin\wp_unslash',
        ],
        'EmailFixtureLoader.php' => [],
        'EmailFixtureResult.php' => [],
        'FixtureComparator.php' => [],
        'FixtureDirectorySnapshotLoader.php' => [],
        'NotifyModeFakes.php' => [],
        'StubSurface.php' => [],
        'WordPressAuthDoubles.php' => [
            'ADCT\ParishIntake\WordPress\Auth\__',
            'ADCT\ParishIntake\WordPress\Auth\esc_attr',
            'ADCT\ParishIntake\WordPress\Auth\esc_html',
            'ADCT\ParishIntake\WordPress\Auth\esc_html__',
            'ADCT\ParishIntake\WordPress\Auth\esc_url',
            'ADCT\ParishIntake\WordPress\Auth\get_user_by',
            'ADCT\ParishIntake\WordPress\Auth\is_email',
            'ADCT\ParishIntake\WordPress\Auth\sanitize_email',
            'ADCT\ParishIntake\WordPress\Auth\sanitize_key',
            'ADCT\ParishIntake\WordPress\Auth\sanitize_text_field',
            'ADCT\ParishIntake\WordPress\Auth\user_can',
            'ADCT\ParishIntake\WordPress\Auth\wp_set_auth_cookie',
            'ADCT\ParishIntake\WordPress\Auth\wp_unslash',
            'register_block_type',
        ],
        'WordPressOptionsStubs.php' => [
            'ADCT\ParishIntake\WordPress\Jobs\add_option',
            'ADCT\ParishIntake\WordPress\Jobs\delete_option',
            'ADCT\ParishIntake\WordPress\Jobs\get_option',
            'ADCT\ParishIntake\WordPress\Jobs\update_option',
            'new_options_database',
        ],
        'WordPressStubs.php' => [
            'ADCT\ParishIntake\WordPress\Admin\__',
            'ADCT\ParishIntake\WordPress\Admin\add_action',
            'ADCT\ParishIntake\WordPress\Admin\add_query_arg',
            'ADCT\ParishIntake\WordPress\Admin\admin_url',
            'ADCT\ParishIntake\WordPress\Admin\check_admin_referer',
            'ADCT\ParishIntake\WordPress\Admin\current_user_can',
            'ADCT\ParishIntake\WordPress\Admin\esc_html__',
            'ADCT\ParishIntake\WordPress\Admin\get_current_screen',
            'ADCT\ParishIntake\WordPress\Admin\is_admin',
            'ADCT\ParishIntake\WordPress\Admin\wp_die',
            'ADCT\ParishIntake\WordPress\Admin\wp_unslash',
            'ADCT\ParishIntake\WordPress\Approval\__',
            'ADCT\ParishIntake\WordPress\Approval\add_action',
            'ADCT\ParishIntake\WordPress\Approval\checked',
            'ADCT\ParishIntake\WordPress\Approval\current_user_can',
            'ADCT\ParishIntake\WordPress\Approval\esc_html',
            'ADCT\ParishIntake\WordPress\Approval\esc_html__',
            'ADCT\ParishIntake\WordPress\Approval\get_user_meta',
            'ADCT\ParishIntake\WordPress\Approval\get_userdata',
            'ADCT\ParishIntake\WordPress\Approval\get_users',
            'ADCT\ParishIntake\WordPress\Approval\selected',
            'ADCT\ParishIntake\WordPress\Approval\update_user_meta',
            'ADCT\ParishIntake\WordPress\Approval\user_can',
            'ADCT\ParishIntake\WordPress\Approval\wp_die',
            'ADCT\ParishIntake\WordPress\Approval\wp_nonce_field',
            'ADCT\ParishIntake\WordPress\Approval\wp_unslash',
            'ADCT\ParishIntake\WordPress\Approval\wp_verify_nonce',
            'ADCT\ParishIntake\WordPress\Auth\add_query_arg',
            'ADCT\ParishIntake\WordPress\Auth\home_url',
            'absint',
            'esc_attr',
            'esc_html',
            'esc_url',
            'plugins_url',
            'sanitize_text_field',
            'wp_enqueue_script',
            'wp_enqueue_style',
            'wp_get_current_user',
            'wp_nonce_field',
            'wp_safe_redirect',
        ],
    ];

    /**
     * The fake classes each file declares.
     *
     * Pinned by name because `WP_User` is what the approval guards type-check
     * against, and a test asserting on a different user object proves nothing.
     *
     * @var array<string, list<string>>
     */
    private const CLASS_SURFACE = [
        'AdminWordPressStubs.php' => [],
        'EmailFixtureLoader.php' => ['ADCT\ParishIntake\Tests\Support\EmailFixtureLoader'],
        'EmailFixtureResult.php' => [
            'ADCT\ParishIntake\Tests\Support\EmailFixtureResult',
            'ADCT\ParishIntake\Tests\Support\FixturePublicationStore',
        ],
        'FixtureComparator.php' => ['ADCT\ParishIntake\Tests\Support\FixtureComparator'],
        'FixtureDirectorySnapshotLoader.php' => [
            'ADCT\ParishIntake\Tests\Support\FixtureDirectorySnapshotLoader',
            'ADCT\ParishIntake\Tests\Support\FixtureDirectorySnapshotProvider',
        ],
        'NotifyModeFakes.php' => [
            'ADCT\ParishIntake\Tests\Support\NotifyModeClock',
            'ADCT\ParishIntake\Tests\Support\NotifyModeDatabase',
        ],
        'StubSurface.php' => ['ADCT\ParishIntake\Tests\Support\StubSurface'],
        'WordPressAuthDoubles.php' => ['WP_User'],
        'WordPressOptionsStubs.php' => ['FakeWordPressOptionsDatabase'],
        'WordPressStubs.php' => [
            'AdctTestNonceRefused',
            'AdctTestRedirect',
            'AdctTestWpDie',
            'WP_Screen',
            'WP_User',
        ],
    ];

    /**
     * The shared stub files this test has an opinion about.
     *
     * The fixture helpers are listed above because a new *support* file should
     * also be deliberate, but they declare no WordPress surface: they are
     * ordinary test doubles, guarded by their own tests. The WordPress stand-ins
     * are the shared mutable surface #216 is about.
     *
     * @return array<string, array{0: string}>
     */
    public static function stubFiles(): array
    {
        $cases = [];

        foreach (self::STUB_FILES as $file) {
            $cases[$file] = [$file];
        }

        return $cases;
    }

    /**
     * The shared stub files as they exist on disk, matched against the list
     * above. A file that appears without being listed fails here, which is the
     * cheapest moment to make someone state what it stubs.
     */
    public function testEverySupportFileIsAccountedFor(): void
    {
        $this->assertSame(self::STUB_FILES, StubSurface::stubFiles());
    }

    /**
     * @param string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stubFiles')]
    public function testTheWordPressFunctionSurfaceIsPinned(string $file): void
    {
        $this->assertSame(
            self::FUNCTION_SURFACE[$file],
            StubSurface::declaredFunctions($this->path($file)),
            sprintf(
                '%s declares a different WordPress function surface than this test pins. '
                . 'Adding a stub is allowed and often right — but it widens a surface every '
                . 'suite in that namespace shares, so do it deliberately: update the list here '
                . 'in the same commit and say in the commit body what the new stub answers.',
                $file
            )
        );
    }

    /**
     * @param string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stubFiles')]
    public function testTheFakeClassSurfaceIsPinned(string $file): void
    {
        $this->assertSame(
            self::CLASS_SURFACE[$file],
            StubSurface::declaredClasses($this->path($file)),
            $file . ' declares a different fake class surface than this test pins.'
        );
    }

    /**
     * A stub that grants a capability by default is the specific accident that
     * makes a guard test pass for the wrong reason, so it is refused as a
     * standing rule rather than left to review.
     *
     * The rule is about *default* behaviour, not about the function existing:
     * `current_user_can()` legitimately reads a per-test global, because a test
     * has to be able to say who holds a capability. What it must not do is answer
     * `true` when nobody has said so. So the check is that the capability stub's
     * answer is derived from a test-set global rather than a literal.
     */
    public function testNoCapabilityStubAnswersTrueByDefault(): void
    {
        foreach (self::FUNCTION_SURFACE as $file => $functions) {
            foreach ($functions as $function) {
                if (! str_ends_with($function, 'current_user_can') && ! str_ends_with($function, 'user_can')) {
                    continue;
                }

                $source = (string) file_get_contents($this->path($file));

                $this->assertDoesNotMatchRegularExpression(
                    '/function\s+' . preg_quote(basename(str_replace('\\', '/', $function)), '/') . '\s*\([^)]*\)\s*:\s*bool\s*\{\s*return\s+true\s*;/',
                    $source,
                    sprintf(
                        '%s declares %s() returning true unconditionally. A guard test would then '
                        . 'pass because every request was authorised, not because the guard ran.',
                        $file,
                        $function
                    )
                );
            }
        }
    }

    private function path(string $file): string
    {
        return dirname(__DIR__, 2) . '/Support/' . $file;
    }
}
