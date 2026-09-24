<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Architecture;

use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\EventRepositoryInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\OcrProviderInterface;
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
            MailboxInterface::class,
            MailerInterface::class,
            EventRepositoryInterface::class,
            AiProviderInterface::class,
            OcrProviderInterface::class,
            HttpClientInterface::class,
        ] as $interface) {
            self::assertTrue(interface_exists($interface), $interface);
        }

        self::assertTrue(class_exists(PipelineFactory::class));
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
