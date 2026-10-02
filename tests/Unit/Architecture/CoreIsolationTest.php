<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Architecture;

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Attachments\CandidateSourceImageResolver;
use ADCT\ParishIntake\Core\Attachments\PreviewableImage;
use ADCT\ParishIntake\Core\Ingestion\Imap\ImapMailbox;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\Imap\TransportInterface;
use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\RawMailMessage;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use ADCT\ParishIntake\Core\Ports\CandidateSourceMessageInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use ADCT\ParishIntake\Core\Ports\EventRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\OcrProviderInterface;
use ADCT\ParishIntake\Core\Ports\PreviewableImageRepositoryInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use PHPUnit\Framework\TestCase;
use Throwable;

final class CoreIsolationTest extends TestCase
{
    private const WORDPRESS_FUNCTIONS = [
        '__',
        '_e',
        '_ex',
        '_n',
        '_n_noop',
        '_nx',
        '_nx_noop',
        '_x',
        'add_action',
        'add_filter',
        'add_option',
        'add_shortcode',
        'apply_filters',
        'check_admin_referer',
        'check_ajax_referer',
        'checked',
        'current_user_can',
        'current_time',
        'dbdelta',
        'delete_option',
        'did_action',
        'do_action',
        'get_option',
        'get_post_meta',
        'get_user_meta',
        'has_action',
        'has_filter',
        'is_admin',
        'is_email',
        'maybe_serialize',
        'maybe_unserialize',
        'plugin_basename',
        'plugin_dir_path',
        'plugin_dir_url',
        'plugins_url',
        'register_activation_hook',
        'register_deactivation_hook',
        'remove_action',
        'remove_filter',
        'sanitize_email',
        'sanitize_key',
        'sanitize_text_field',
        'sanitize_textarea_field',
        'sanitize_title',
        'selected',
        'trailingslashit',
        'translate',
        'update_option',
    ];

    private const WORDPRESS_CONSTANTS = [
        'ABSPATH',
        'ARRAY_A',
        'OBJECT',
        'OBJECT_K',
    ];

    public function testCorePortsAndPipelineAreLoadedByComposerPsr4(): void
    {
        foreach ([
            ClockInterface::class,
            ApprovalRouteRepositoryInterface::class,
            CandidateSourceMessageInterface::class,
            PreviewableImageRepositoryInterface::class,
            DirectorySnapshotProviderInterface::class,
            MailboxInterface::class,
            MailerInterface::class,
            EventRepositoryInterface::class,
            AiProviderInterface::class,
            OcrProviderInterface::class,
            HttpClientInterface::class,
            TransportInterface::class,
        ] as $interface) {
            self::assertTrue(interface_exists($interface), $interface);
        }

        self::assertTrue(class_exists(MailboxConnectionConfig::class));
        self::assertTrue(class_exists(MailboxSearchCriteria::class));
        self::assertTrue(class_exists(RawMailMessage::class));
        self::assertTrue(class_exists(ImapMailbox::class));
        self::assertTrue(class_exists(PipelineFactory::class));
        self::assertTrue(class_exists(ApprovalRouteResolver::class));
        self::assertTrue(class_exists(PreviewableImage::class));
        self::assertTrue(class_exists(CandidateSourceImageResolver::class));
    }

    public function testCoreContainsNoWordPressFunctionsClassesOrGlobals(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $coreDirectory = $repositoryRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($coreDirectory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $violations = [];

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertNotFalse($source, 'Unable to read ' . $file->getPathname());
            $relativePath = substr(
                $file->getPathname(),
                strlen($repositoryRoot) + strlen(DIRECTORY_SEPARATOR)
            );
            $violations = array_merge(
                $violations,
                self::findWordPressDependencies($source, str_replace('\\', '/', $relativePath))
            );
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function testWordPressMailIsCalledOnlyByTheQueueDeliveryAdapter(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $sourcePaths = [
            $repositoryRoot . DIRECTORY_SEPARATOR . 'src',
            $repositoryRoot . DIRECTORY_SEPARATOR . 'parish-intake.php',
            $repositoryRoot . DIRECTORY_SEPARATOR . 'uninstall.php',
        ];
        $callSites = [];

        foreach ($sourcePaths as $sourcePath) {
            if (is_dir($sourcePath)) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS)
                );
            } elseif (is_file($sourcePath)) {
                $files = [new SplFileInfo($sourcePath)];
            } else {
                continue;
            }

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                self::assertNotFalse($source, 'Unable to read ' . $file->getPathname());
                $tokens = token_get_all($source, TOKEN_PARSE);

                foreach ($tokens as $index => $token) {
                    if (! is_array($token) || ! self::isNameToken($token[0])) {
                        continue;
                    }

                    $name = ltrim($token[1], '\\');
                    $basename = substr($name, strrpos('\\' . $name, '\\'));
                    $basename = ltrim($basename, '\\');

                    if (strcasecmp($basename, 'wp_mail') !== 0) {
                        continue;
                    }

                    $openParenthesisIndex = self::nextSignificantTokenIndex($tokens, $index + 1);

                    if ($openParenthesisIndex === null || $tokens[$openParenthesisIndex] !== '(') {
                        continue;
                    }

                    $relativePath = substr(
                        $file->getPathname(),
                        strlen($repositoryRoot) + strlen(DIRECTORY_SEPARATOR)
                    );
                    $callSites[] = str_replace('\\', '/', $relativePath);
                }
            }
        }

        sort($callSites, SORT_STRING);
        self::assertSame(
            ['src/WordPress/Mail/WordPressMailDeliveryAdapter.php'],
            $callSites,
            'All plugin wp_mail() calls must use the queue delivery adapter.'
        );
    }

    public function testPluginConstructorRunsWithoutWordPressLoaded(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);

        $probe = <<<'PHP'
            <?php
            // Mirrors scripts/check-release-bootstrap.php, minus the release-only
            // vendor-prefixed autoloader, which does not exist in a dev checkout.
            define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
            require_once __DIR__ . '/vendor/autoload.php';
            require_once __DIR__ . '/src/WordPress/Autoloader.php';
            ADCT\ParishIntake\WordPress\Autoloader::register();
            ADCT\ParishIntake\WordPress\Plugin::boot(__DIR__ . '/adct-parish-intake.php');
            echo "constructed\n";
            PHP;

        $probePath = $repositoryRoot . DIRECTORY_SEPARATOR . 'bootstrap-probe.php';
        file_put_contents($probePath, $probe);

        try {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                [PHP_BINARY, $probePath],
                $descriptors,
                $pipes,
                $repositoryRoot
            );

            self::assertIsResource($process, 'Unable to start the bootstrap probe.');

            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            @unlink($probePath);
        }

        self::assertSame(
            0,
            $exitCode,
            "The plugin constructor called a WordPress function before WordPress was loaded."
                . PHP_EOL . $output . $errors
        );
        self::assertStringContainsString('constructed', $output);
    }

    public function testConstructorScannerIgnoresCallsOutsideConstructors(): void
    {
        $violations = self::findWordPressCallsInsideConstructors(
            '<?php final class Fixture { public function render(): string { return esc_html(plugins_url(\'a.js\')); } }',
            'fixture.php'
        );

        self::assertSame([], $violations);
    }

    public function testConstructorScannerReportsCallsInsideConstructors(): void
    {
        $violations = self::findWordPressCallsInsideConstructors(
            "<?php final class Fixture { public function __construct(string \$f) { \$this->u = plugins_url('a.js', \$f); } }",
            'fixture.php'
        );

        self::assertSame(
            ['fixture.php:1 calls WordPress function plugins_url from a constructor'],
            $violations
        );
    }

    public function testScannerRecognizesWordPressFunctionChecksAndGlobals(): void
    {
        $violations = self::findWordPressDependencies(
            "<?php function_exists('wp_remote_post'); get_option('setting'); \$wpdb->get_results(''); \$GLOBALS['wpdb']; new \\WP_Query(); __();",
            'fixture.php'
        );

        self::assertCount(6, $violations);
    }

    public function testScannerIgnoresCommentsStringsAndNativeFunctionChecks(): void
    {
        $violations = self::findWordPressDependencies(
            "<?php // get_option() is only mentioned\n\$text = 'wp_remote_post'; function_exists('mb_strtolower');",
            'fixture.php'
        );

        self::assertSame([], $violations);
    }

    /**
     * @return string[]
     */
    private static function findWordPressDependencies(string $source, string $file): array
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (Throwable $exception) {
            return [$file . ' could not be tokenized: ' . $exception->getMessage()];
        }

        $violations = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            [$type, $text, $line] = $token;

            if ($type === T_VARIABLE && self::isWordPressGlobal($text)) {
                $violations[] = sprintf('%s:%d uses WordPress global %s', $file, $line, $text);
            }

            if ($type === T_VARIABLE && strcasecmp($text, '$GLOBALS') === 0) {
                $globalNameIndex = self::nextSignificantTokenIndex($tokens, $index + 1);
                $globalNameIndex = $globalNameIndex === null
                    ? null
                    : self::nextSignificantTokenIndex($tokens, $globalNameIndex + 1);

                if (
                    $globalNameIndex !== null
                    && is_array($tokens[$globalNameIndex])
                    && $tokens[$globalNameIndex][0] === T_CONSTANT_ENCAPSED_STRING
                    && strcasecmp(trim($tokens[$globalNameIndex][1], '\'"'), 'wpdb') === 0
                ) {
                    $violations[] = sprintf('%s:%d uses WordPress global $wpdb', $file, $line);
                }
            }

            if (! self::isNameToken($type)) {
                continue;
            }

            $name = ltrim($text, '\\');
            $basename = substr($name, strrpos('\\' . $name, '\\'));
            $basename = ltrim($basename, '\\');
            $lowerName = strtolower($basename);

            if (preg_match('/^wp_[a-z0-9_]+$/i', $basename)) {
                $violations[] = sprintf('%s:%d references WordPress symbol %s', $file, $line, $basename);
                continue;
            }

            if (preg_match('/^WP_[A-Za-z0-9_]+$/', $basename)) {
                $violations[] = sprintf('%s:%d references WordPress class %s', $file, $line, $basename);
                continue;
            }

            if (in_array(strtoupper($basename), self::WORDPRESS_CONSTANTS, true)) {
                $violations[] = sprintf('%s:%d references WordPress constant %s', $file, $line, $basename);
                continue;
            }

            if (self::isWordPressFunction($lowerName)) {
                $violations[] = sprintf('%s:%d calls WordPress function %s', $file, $line, $basename);
                continue;
            }

            if ($lowerName === 'function_exists') {
                $openParenthesisIndex = self::nextSignificantTokenIndex($tokens, $index + 1);

                if ($openParenthesisIndex === null || $tokens[$openParenthesisIndex] !== '(') {
                    continue;
                }

                $argumentIndex = self::nextSignificantTokenIndex($tokens, $openParenthesisIndex + 1);

                if (
                    $argumentIndex !== null
                    && is_array($tokens[$argumentIndex])
                    && $tokens[$argumentIndex][0] === T_CONSTANT_ENCAPSED_STRING
                ) {
                    $checkedFunction = trim($tokens[$argumentIndex][1], '\'"');

                    if (self::isWordPressFunction(strtolower($checkedFunction))) {
                        $violations[] = sprintf(
                            "%s:%d checks for WordPress function %s",
                            $file,
                            $line,
                            $checkedFunction
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Reports WordPress functions used directly inside a `__construct()` body.
     *
     * `scripts/check-release-bootstrap.php` loads the plugin bootstrap under plain
     * PHP, with no WordPress present, and doing so runs the plugin constructor.
     * A WordPress call there is a fatal error, not a test-only failure, so the
     * constructor must stay plain PHP. Anything that genuinely needs WordPress
     * is resolved later, on first use.
     *
     * @return string[]
     */
    private static function findWordPressCallsInsideConstructors(string $source, string $file): array
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (Throwable $exception) {
            return [$file . ' could not be tokenized: ' . $exception->getMessage()];
        }

        $violations = [];
        $depth = 0;
        $index = 0;
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if ($token === '{' || $token === '}' || $token === '(' || $token === ')') {
                $depth += ($token === '{' || $token === '(') ? 1 : -1;
                ++$index;
                continue;
            }

            if (! is_array($token)) {
                ++$index;
                continue;
            }

            if ($token[0] !== T_FUNCTION) {
                ++$index;
                continue;
            }

            $nameIndex = self::nextSignificantTokenIndex($tokens, $index + 1);
            $name = $nameIndex === null ? null : self::functionName($tokens[$nameIndex]);
            $bodyStart = self::constructorBodyStart($tokens, (int) $nameIndex);

            if ($bodyStart !== null && $name !== null && strcasecmp($name, '__construct') === 0) {
                $violations = array_merge(
                    $violations,
                    self::scanWordPressCallsInBody($tokens, $bodyStart, $file)
                );
                $index = self::endOfBracedBody($tokens, $bodyStart);
                continue;
            }

            ++$index;
        }

        return $violations;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private static function functionName(mixed $token): ?string
    {
        if (! is_array($token) || ! self::isNameToken($token[0])) {
            return null;
        }

        $name = ltrim($token[1], '\\');

        return ltrim(substr($name, strrpos('\\' . $name, '\\')), '\\');
    }

    /**
     * Returns the index of the `{` opening the method body, if there is one.
     *
     * @param  array<int, mixed>  $tokens
     */
    private static function constructorBodyStart(array $tokens, int $afterName): ?int
    {
        $index = self::nextSignificantTokenIndex($tokens, $afterName + 1);

        if ($index === null) {
            return null;
        }

        // Skip the parameter list, then the return type, if any.
        $parenthesisDepth = 0;
        $count = count($tokens);

        for (; $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token === '(') {
                ++$parenthesisDepth;
            } elseif ($token === ')') {
                --$parenthesisDepth;
            } elseif ($parenthesisDepth === 0 && $token === '{') {
                return $index;
            } elseif ($parenthesisDepth === 0 && $token === ';') {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @return string[]
     */
    private static function scanWordPressCallsInBody(array $tokens, int $bodyStart, string $file): array
    {
        $violations = [];
        $depth = 0;
        $count = count($tokens);

        for ($index = $bodyStart; $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token === '{') {
                ++$depth;
                continue;
            }

            if ($token === '}') {
                --$depth;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            if (! is_array($token) || ! self::isNameToken($token[0]) || $token[0] === T_FUNCTION) {
                continue;
            }

            $name = ltrim($token[1], '\\');
            $basename = ltrim(substr($name, strrpos('\\' . $name, '\\')), '\\');
            $nextIndex = self::nextSignificantTokenIndex($tokens, $index + 1);

            if ($nextIndex === null || $tokens[$nextIndex] !== '(') {
                continue;
            }

            if (self::isWordPressFunction(strtolower($basename))) {
                $violations[] = sprintf(
                    '%s:%d calls WordPress function %s from a constructor',
                    $file,
                    $token[2],
                    $basename
                );
            }
        }

        return $violations;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private static function endOfBracedBody(array $tokens, int $bodyStart): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($index = $bodyStart; $index < $count; ++$index) {
            if ($tokens[$index] === '{') {
                ++$depth;
            } elseif ($tokens[$index] === '}') {
                --$depth;

                if ($depth === 0) {
                    return $index + 1;
                }
            }
        }

        return $count;
    }

    private static function isNameToken(int $type): bool
    {
        return in_array($type, [
            T_STRING,
            T_NAME_QUALIFIED,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE,
        ], true);
    }

    private static function nextSignificantTokenIndex(array $tokens, int $index): ?int
    {
        for ($count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];

            if (
                is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }

            return $index;
        }

        return null;
    }

    private static function isWordPressGlobal(string $variable): bool
    {
        return preg_match('/^\$(?:wpdb|wp(?:_[A-Za-z0-9_]+)?)$/i', $variable) === 1;
    }

    private static function isWordPressFunction(string $name): bool
    {
        return str_starts_with($name, 'wp_')
            || str_starts_with($name, 'esc_')
            || str_starts_with($name, 'sanitize_')
            || in_array($name, self::WORDPRESS_FUNCTIONS, true);
    }
}
