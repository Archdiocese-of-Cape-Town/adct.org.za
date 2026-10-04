<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * scripts/check-release-bootstrap.php had no coverage at all, so its guard could
 * be reworded, moved or dropped without a single test failing. This covers both
 * of its branches: the guard that stops a package whose dependencies were never
 * prefixed, and the assertions that only run once they were.
 *
 * Those assertions are the script's purpose. Showing that the prefixing step ran
 * is not the whole point; the script exists to catch a package that ships a
 * dependency reachable under its original namespace, so the data provider below
 * pins that half too. The guard must never be fixed by weakening them.
 *
 * vendor-prefixed/ is a composer strauss build artefact and does not exist in a
 * checkout. Strauss is a downloaded phar and the unit job has no network, so the
 * fixture builds the same shape into a temp directory: the production packages
 * copied with their dependency namespace roots rewritten under the prefix from
 * composer.json's extra.strauss.namespace_prefix, behind a plain PSR-4 and
 * classmap autoloader. That is a deliberately small reimplementation of one
 * Strauss behaviour and nothing more - it is not a release packaging tool, and
 * scripts/build-release.sh still runs the real thing.
 */
final class ReleaseBootstrapCheckTest extends TestCase
{
    private const DEPENDENCIES_PREFIX = 'ADCT\\ParishIntake\\Dependencies\\';

    /** @var array<string, array<string, string>> Package name => namespace root => PSR-4 directory. */
    private array $packages = [];

    /** @var list<string> Every production namespace root, which is what the prefixer rewrites. */
    private array $namespaceRoots = [];

    /** @var list<string> Package-relative paths required before anything else, e.g. php-di's functions.php. */
    private array $files = [];

    /**
     * @var array<string, string> Declared class name => package-relative path, for the classmap autoload.
     *                           A classmap entry leaks an unprefixed name exactly as an unrewritten PSR-4
     *                           path would, which is why Strauss' classmap output is prefixed too.
     */
    private array $classmap = [];

    private static ?string $repositoryRoot = null;

    /** @var list<string> */
    private static array $temporaryDirectories = [];

    public static function setUpBeforeClass(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);

        if (! is_file($repositoryRoot . '/vendor/composer/installed.php')) {
            throw new RuntimeException('Run composer install before the unit suite.');
        }

        self::$repositoryRoot = $repositoryRoot;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$temporaryDirectories as $directory) {
            self::removeTree($directory);
        }

        self::$temporaryDirectories = [];
        self::$repositoryRoot = null;
    }

    protected function setUp(): void
    {
        if ($this->packages === []) {
            $this->readProductionPackages(self::$repositoryRoot ?? dirname(__DIR__, 3));
        }
    }

    public function testItFailsNamingThePrefixingStepWhenPrefixedDependenciesAreAbsent(): void
    {
        $packageDirectory = $this->createFixtureDirectory('unprefixed');
        $this->copyPluginBootstrap($packageDirectory);

        self::assertFileDoesNotExist(
            $packageDirectory . '/vendor-prefixed/autoload.php',
            'The fixture must have no vendor-prefixed/autoload.php, or it cannot exercise the guard.'
        );

        $result = $this->runCheck($packageDirectory);

        self::assertSame(1, $result['exit'], 'A checkout without the prefixing step must fail the release check.');

        // The regression this pins. The old message was "Prefixed dependency
        // autoloader is missing.", which names the file the step emits and sends
        // someone hunting for autoload.php in the wrong place.
        self::assertStringNotContainsString(
            'Prefixed dependency autoloader is missing.',
            $result['stderr'],
            'The guard must name the prefixing step, not only the file that step emits.'
        );
        self::assertStringContainsString(
            'prefixing step',
            $result['stderr'],
            'The guard message must name the prefixing step that produces vendor-prefixed/.'
        );
        self::assertStringContainsString(
            'scripts/build-release.sh',
            $result['stderr'],
            'The guard message must name the build that runs the prefixing step.'
        );
        self::assertStringNotContainsString(
            'Unprefixed dependency class loaded',
            $result['stderr'],
            'The guard short-circuits, because the leak assertions need the prefixed autoloader to run at all.'
        );
    }

    public function testItSucceedsOnAPackageBuiltByThePrefixingStep(): void
    {
        $result = $this->runCheck($this->createPrefixedFixture('prefixed'));

        self::assertSame(
            0,
            $result['exit'],
            "A package whose dependencies have been through the prefixing step must pass.\n"
            . 'stdout: ' . $result['stdout'] . "\nstderr: " . $result['stderr']
        );
        self::assertStringContainsString('Plugin bootstrap loaded under plain PHP.', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    /**
     * The unprefixed-leak assertions are what the script is for. Each of the
     * three classes it names is exposed under its unprefixed name here, and each
     * must still fail the check.
     */
    #[DataProvider('unprefixedDependencyClasses')]
    public function testItStillFailsWhenADependencyIsLeftUnprefixed(string $unprefixedClass, string $prefixedClass): void
    {
        $fixture = $this->createPrefixedFixture();
        $this->exposeClassUnprefixed($fixture, $unprefixedClass, $prefixedClass);

        $result = $this->runCheck($fixture);

        self::assertSame(
            1,
            $result['exit'],
            "Leaving {$unprefixedClass} reachable under its unprefixed name must fail the release check.\n"
            . 'stdout: ' . $result['stdout'] . "\nstderr: " . $result['stderr']
        );
        self::assertStringContainsString(
            "Unprefixed dependency class loaded from the release: {$unprefixedClass}",
            $result['stderr'],
            'The unprefixed-leak assertions are the script\'s purpose and must keep biting.'
        );
        self::assertStringNotContainsString(
            "Prefixed dependency class did not load from the release: {$prefixedClass}",
            $result['stderr'],
            "The leak assertion for {$unprefixedClass} runs first, so its prefixed counterpart is never reported."
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unprefixedDependencyClasses(): array
    {
        return [
            'php-di container' => ['DI\\Container', self::DEPENDENCIES_PREFIX . 'DI\\Container'],
            'laravel serializable closure' => [
                'Laravel\\SerializableClosure\\SerializableClosure',
                self::DEPENDENCIES_PREFIX . 'Laravel\\SerializableClosure\\SerializableClosure',
            ],
            'guzzle psr7 request' => [
                'GuzzleHttp\\Psr7\\Request',
                self::DEPENDENCIES_PREFIX . 'GuzzleHttp\\Psr7\\Request',
            ],
        ];
    }

    /**
         * A classmap entry under an unprefixed name is the same leak as an unrewritten
         * PSR-4 path, reached through a different route: the class exists only under
         * its unprefixed name, and Composer loads it by that name.
         */
        public function testItStillFailsWhenAPackageRegistersAnUnprefixedClassmapEntry(): void
        {
                    $fixture = $this->createPrefixedFixture();
                    $autoloader = $fixture . '/vendor-prefixed/autoload.php';

                    // A file standing in for a classmap entry Strauss failed to prefix: it
                    // declares the class under its unprefixed name, which is what makes the
                    // entry a leak rather than an ordinary mapping.
                    $leak = $fixture . '/vendor-prefixed/leaked/Container.php';
                    $directory = dirname($leak);

                    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                        self::fail("Could not create the classmap leak fixture directory {$directory}.");
                    }

                    self::assertNotFalse(file_put_contents($leak, "<?php\n\nnamespace DI;\n\nclass Container {}\n"));

                    $contents = (string) file_get_contents($autoloader);
                    $anchor = "\$classmap = [\n";

                    self::assertStringContainsString($anchor, $contents, 'The fixture must register a classmap.');

                    // Composer maps one class name to one file, so registering the unprefixed
                    // name is enough for the class to answer to it, which is exactly the leak
                    // the script's unprefixed-class assertion looks for.
                    $mutated = str_replace(
                        $anchor,
                        $anchor . '    ' . var_export('DI\\Container', true) . ' => ' . var_export($leak, true) . ",\n",
                        $contents
                    );

                    self::assertNotSame($contents, $mutated, 'The classmap mutation must change the autoloader.');

                    file_put_contents($autoloader, $mutated);

                    $result = $this->runCheck($fixture);

                    self::assertSame(
                        1,
                        $result['exit'],
                        "An unprefixed classmap entry must fail the release check.\nstderr: " . $result['stderr']
                    );
                    self::assertStringContainsString(
                        'Unprefixed dependency class loaded from the release: DI\\Container',
                        $result['stderr'],
                        'The leak assertion must fire for a class reached through a classmap entry.'
                    );
        }

                public function testItRejectsADirectoryWithNoPluginBootstrap(): void
    {
        $result = $this->runCheck($this->createFixtureDirectory());

        self::assertSame(1, $result['exit']);
        self::assertSame("Plugin bootstrap is missing.\n", $result['stderr']);
    }

    public function testItRejectsBeingCalledWithoutAPackageDirectory(): void
    {
        $result = $this->runCheck(null);

        self::assertSame(
            2,
            $result['exit'],
            'With no argument the script must exit 2 with a usage message rather than fall through.'
        );
        self::assertStringContainsString('Usage:', $result['stderr']);
        self::assertStringContainsString('check-release-bootstrap.php', $result['stderr']);
    }

    // ------------------------------------------------------------------
    // Fixture construction
    // ------------------------------------------------------------------

    private function createFixtureDirectory(string $label = 'package'): string
    {
        // Not a PID: PHP in a container starts at 1, so sibling fixtures would
        // collide across runs and a stale tree would quietly satisfy a lookup.
        $directory = sys_get_temp_dir() . '/adct-relcheck-' . $label . '-' . bin2hex(random_bytes(6));

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the fixture directory {$directory}.");
        }

        self::$temporaryDirectories[] = $directory;

        return $directory;
    }

    /**
     * A package as the release build leaves it: the bootstrap, the plugin
     * source, and a vendor-prefixed/ tree whose dependency namespaces have been
     * rewritten under the namespace prefix.
     */
    private function createPrefixedFixture(string $label = 'prefixed'): string
    {
        $repositoryRoot = self::$repositoryRoot ?? dirname(__DIR__, 3);
        $packageDirectory = $this->createFixtureDirectory($label);

        $this->copyPluginBootstrap($packageDirectory);

        self::assertGreaterThan(
            0,
            $this->copyTree($repositoryRoot . '/src', $packageDirectory . '/src', null),
            'The plugin source must be copied into the fixture.'
        );

        foreach ($this->packages as $package => $roots) {
                    $this->copyTree(
                        $repositoryRoot . '/vendor/' . $package,
                        $packageDirectory . '/vendor-prefixed/' . $package,
                        $this->namespaceRoots
                    );
                }

        $this->writeAutoloader($packageDirectory . '/vendor-prefixed/autoload.php');

        return $packageDirectory;
    }

    private function copyPluginBootstrap(string $packageDirectory): void
    {
        $repositoryRoot = self::$repositoryRoot ?? dirname(__DIR__, 3);

        if (! copy($repositoryRoot . '/adct-parish-intake.php', $packageDirectory . '/adct-parish-intake.php')) {
            throw new RuntimeException('Could not copy adct-parish-intake.php into the fixture.');
        }
    }

    /**
     * Reads the production (non-dev) packages, their namespace roots, their
     * `files` autoloads and their classmap autoloads.
     *
     * Each package's own composer.json is read rather than the merged
     * autoload_*.php files: Composer rewrites the root package's autoload-dev
     * PSR-4 entry into those files, which would point the fixture at tests/
     * and at the dev-only packages PHPUnit is itself running from.
     */
    private function readProductionPackages(string $repositoryRoot): void
    {
        $installed = require $repositoryRoot . '/vendor/composer/installed.php';

        // Virtual packages are provided by other packages and have no directory
        // of their own to copy.
        $virtualPackages = [
            'psr/container-implementation',
            'psr/http-factory-implementation',
            'psr/http-message-implementation',
        ];

        foreach ($installed['versions'] as $name => $info) {
            if (($info['dev_requirement'] ?? false) || $name === 'adct/parish-intake' || in_array($name, $virtualPackages, true)) {
                continue;
            }

            $composerJson = $repositoryRoot . '/vendor/' . $name . '/composer.json';

            if (! is_file($composerJson)) {
                continue;
            }

            $composer = json_decode((string) file_get_contents($composerJson), true);

            if (! is_array($composer)) {
                continue;
            }

            foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $namespace => $directories) {
                // The autoload-dev block of a dependency adds a second namespace for
                // its own test suite, which the release never ships.
                if (preg_match('/Tests?\\\\/', (string) $namespace) === 1) {
                    continue;
                }

                $root = trim((string) $namespace, '\\') . '\\';
                $first = (array) $directories;
                                $this->namespaceRoots[] = $root;
                                $this->packages[$name][$root] = trim((string) reset($first), '/');
            }

            foreach ((array) ($composer['autoload']['files'] ?? []) as $file) {
                $this->files[] = $name . '/' . trim((string) $file, '/');
            }

                            foreach ((array) ($composer['autoload']['classmap'] ?? []) as $classmapDirectory) {
                                $classmapDirectory = trim((string) $classmapDirectory, '/');

                                $this->collectClassmap(
                                    $name,
                                    $repositoryRoot . '/vendor/' . $name . '/' . $classmapDirectory,
                                    $name . '/' . $classmapDirectory
                                );
                            }
                        }

        $this->namespaceRoots = array_values(array_unique($this->namespaceRoots));
        $this->files = array_values(array_unique($this->files));
    }

    /**
     * @param string $absoluteDirectory Directory to scan, resolved against the repository.
     * @param string $relativeDirectory The same directory relative to the package, so the
     *                                  recorded paths are package-relative.
     */
    private function collectClassmap(string $package, string $absoluteDirectory, string $relativeDirectory): void
    {
        if (! is_dir($absoluteDirectory)) {
            return;
        }

        foreach ($this->filesIn($absoluteDirectory) as $file) {
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $class = $this->declaredClassName($file);

            // The polyfill stub files are classmap'd because the classes they declare
            // are global and guarded on the PHP version, so they are the only
            // classmap autoload the production set has. Their names stay unprefixed
            // in the generated autoloader for the same reason.
            if ($class !== null) {
                $this->classmap[$class] = $package . '/' . $this->relativeTo($relativeDirectory, $file->getPathname());
            }
        }
    }

    /**
     * The namespace plus the single class, interface, trait or enum a file
     * declares, or null when it declares none. A stub guards its declaration
     * inside an if on the PHP version, so the guard is deliberately not read.
     */
    private function declaredClassName(SplFileInfo $file): ?string
    {
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $count = count($tokens);
        $namespace = '';

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->namespaceAfter($tokens, $index);
            }

            if (! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            $previous = $this->previousMeaningful($tokens, $index);

            if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                continue;
            }

            $name = $this->nextClassName($tokens, $index + 1);

            if ($name !== null) {
                return $namespace . $name;
            }
        }

        return null;
    }

    /**
     * The name a `namespace` statement declares. It is a single segment and so
     * arrives as T_STRING rather than as a qualified name; `namespace DI;` is
     * exactly the kind that the leak assertions are about.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function namespaceAfter(array $tokens, int $from): string
    {
        $count = count($tokens);
        $namespace = '';

        for ($index = $from + 1; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token === ';' || $token === '{') {
                break;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $namespace = trim($token[1], '\\') . '\\';
            }
        }

        return $namespace;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{int, string, int}|string|null
     */
    private function previousMeaningful(array $tokens, int $from): array|string|null
    {
        for ($index = $from - 1; $index >= 0; $index--) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * The identifier that follows a class keyword, ignoring whitespace, comments
     * and the `extends`/`implements` a later declaration would bring.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function nextClassName(array $tokens, int $from): ?string
    {
        $count = count($tokens);

        for ($index = $from; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }

            return null;
        }

        return null;
    }

    /**
     * Writes every file under $from into $to. When $roots is given, dependency
     * namespace roots are rewritten under the prefix in each PHP file;
     * otherwise files are copied byte for byte.
     *
     * @param list<string>|null $roots
     */
    private function copyTree(string $from, string $to, ?array $roots): int
    {
        $from = rtrim(str_replace('\\', '/', $from), '/');
        $to = rtrim(str_replace('\\', '/', $to), '/');

        if (! is_dir($to) && ! mkdir($to, 0777, true) && ! is_dir($to)) {
            throw new RuntimeException("Could not create {$to}.");
        }

        $copied = 0;

        foreach ($this->filesIn($from) as $file) {
            $target = $to . '/' . $this->relativeTo($from, $file->getPathname());
            $directory = dirname($target);

            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new RuntimeException("Could not create {$directory}.");
            }

            $contents = (string) file_get_contents($file->getPathname());

            if ($roots !== null && strtolower($file->getExtension()) === 'php') {
                $contents = $this->prefixNames($contents, $roots);
            }

            if (file_put_contents($target, $contents) === false) {
                throw new RuntimeException("Could not write {$target}.");
            }

            $copied++;
        }

        return $copied;
    }

    /**
     * Rewrites dependency namespace roots in the qualified name tokens of a file,
     * plus the single-segment namespace statement those tokens never cover.
     *
     * The whole root has to be matched, not just its first segment: a root such
     * as Laravel\SerializableClosure does not begin with its own first segment,
     * so a first-segment-only comparison matches nothing and leaves every
     * namespace in the copy unprefixed. The fixture then fails for the wrong
     * reason - half the classes are unprefixed rather than none - and the
     * success test would pass on a leak.
     *
     * @param list<string> $roots
     */
    private function prefixNames(string $code, array $roots): string
    {
        $tokens = token_get_all($code);
        $output = '';
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $prefixed = $this->prefixDeclaration($tokens, $index, $roots);

                if ($prefixed !== null) {
                                    $output .= $prefixed['declaration'];
                    $index = $prefixed['next'] - 1;

                    continue;
                }
            }

            if (is_array($token) && ($token[0] === T_NAME_QUALIFIED || $token[0] === T_NAME_FULLY_QUALIFIED)) {
                $fullyQualified = $token[0] === T_NAME_FULLY_QUALIFIED;
                $name = ltrim($token[1], '\\');
                $prefixed = $this->prefixedName($name, $roots);

                if ($prefixed !== null) {
                    $output .= ($fullyQualified ? '\\' : '') . $prefixed;

                    continue;
                }
            }

            $output .= is_array($token) ? $token[1] : $token;
        }

        return $output;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @param list<string> $roots
     *
     * @return array{declaration: string, next: int}|null Null when the statement declares no dependency namespace.
     */
    private function prefixDeclaration(array $tokens, int $from, array $roots): ?array
    {
        $count = count($tokens);
        $namespace = '';

        for ($index = $from + 1; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token === ';' || $token === '{') {
                $prefixed = $this->prefixedName($namespace, $roots);

                            // A braced or multi-segment namespace is already a qualified name
                            // token and is handled by prefixNames(); only a single-segment
                            // declaration such as "namespace DI;" arrives here as T_STRING.
                            return $prefixed === null
                                ? null
                                : ['declaration' => $tokens[$from][1] . ' ' . $prefixed . ' ', 'next' => $index];
                        }

                        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                            $namespace = trim($token[1], '\\');
                        }
                    }

                    return null;
                }

    /**
     * @param list<string> $roots
     */
    private function prefixedName(string $name, array $roots): ?string
    {
        foreach ($roots as $root) {
            $trimmed = rtrim($root, '\\');

            if ($name === $trimmed || str_starts_with($name, $trimmed . '\\')) {
                return self::DEPENDENCIES_PREFIX . $name;
            }
        }

        return null;
    }

    /**
     * Writes a PSR-4 and classmap autoloader over the copied packages, mirroring
     * the shape Strauss emits: dependency roots get the namespace prefix, the
     * classmap is registered, and the `files` autoloads are required.
     */
    private function writeAutoloader(string $path): void
    {
        $psr4 = [];
        $classmap = [];

        foreach ($this->packages as $package => $roots) {
            foreach ($roots as $namespace => $relativeDirectory) {
                                // Composer's own PSR-4 entries carry a trailing slash, which is
                                // what makes the plain directory . relative-path concatenation in
                                // the generated loader below correct.
                                $psr4[self::DEPENDENCIES_PREFIX . $namespace][] =
                                    $this->within($path, $package . '/' . $relativeDirectory) . '/';
                            }
                        }

        foreach ($this->classmap as $class => $relative) {
            // A polyfill stub declares a global interface guarded on the PHP
            // version, so its classmap name is left as it is.
            $classmap[str_contains($class, '\\') ? self::DEPENDENCIES_PREFIX . $class : $class] =
                $this->within($path, $relative);
        }

        $lines = [
            '<?php',
            '',
            '// Generated by ' . basename(__FILE__) . ' in place of the Strauss-prefixed autoloader a',
            '// release package carries. The prefix has to match composer.json\'s',
            '// extra.strauss.namespace_prefix, which is what scripts/check-release-bootstrap.php',
            '// asserts against, so a change on either side has to break this fixture.',
            '',
            '$prefixed = ' . $this->exportPsr4($psr4) . ';',
            '',
                        '$classmap = ' . $this->exportClassmap($classmap) . ';',
            '',
            'spl_autoload_register(static function (string $class) use ($prefixed, $classmap): void {',
            '    if (isset($classmap[$class])) {',
            '        require_once $classmap[$class];',
            '',
            '        return;',
            '    }',
            '',
            '    foreach ($prefixed as $prefix => $directories) {',
            '        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {',
            '            continue;',
            '        }',
            '',
            "        \$relative = str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';",
            '',
            '        foreach ($directories as $directory) {',
            '            if (is_file($file = $directory . $relative)) {',
            '                require_once $file;',
            '',
            '                return;',
            '            }',
            '        }',
            '    }',
            '});',
            '',
        ];

        foreach ($this->files as $file) {
            $lines[] = 'require_once ' . var_export($this->within($path, $file), true) . ';';
        }

        $lines[] = '';

        if (file_put_contents($path, implode("\n", $lines)) === false) {
            throw new RuntimeException("Could not write {$path}.");
        }
    }

    /**
     * Makes a class answer to its unprefixed name as well, which is what a
     * package whose prefixing did not cover this dependency would do. The
     * prefixed file is reused as the alias target, so the mutation stays to one
     * added block and no dependency source is touched.
     */
    private function exposeClassUnprefixed(string $fixture, string $unprefixedClass, string $prefixedClass): void
    {
        $autoloader = $fixture . '/vendor-prefixed/autoload.php';
        $source = $this->within($autoloader, $this->packageFor($unprefixedClass));

        if (! is_file($source)) {
            throw new RuntimeException("The fixture does not contain {$source}.");
        }

        $mutation = implode("\n", [
            '',
            '// Test mutation: expose the class under its unprefixed name as well, which is what a',
            '// package whose prefixing step did not cover this dependency would do.',
            '$aliasSource = ' . var_export($source, true) . ';',
            '',
            'spl_autoload_register(static function (string $requested) use ($aliasSource): void {',
            '    if ($requested !== ' . var_export($unprefixedClass, true) . ' || ! is_file($aliasSource)) {',
            '        return;',
            '    }',
            '',
            '    require_once $aliasSource;',
            '    class_alias(' . var_export($prefixedClass, true) . ', $requested);',
            '});',
            '',
        ]);

        $contents = (string) file_get_contents($autoloader);

        if (file_put_contents($autoloader, $contents . $mutation) === false) {
            throw new RuntimeException("Could not write the mutation to {$autoloader}.");
        }
    }

    /**
         * The package-relative file that holds $class, resolved through the package's
         * own PSR-4 root, so the mutation can alias the prefixed file the class is.
         */
        private function packageFor(string $class): string
        {
            foreach ($this->packages as $package => $roots) {
                foreach ($roots as $root => $relativeDirectory) {
                    if (str_starts_with($class, $root)) {
                        $relative = str_replace('\\', '/', substr($class, strlen($root)));

                        return $package . '/' . $relativeDirectory . '/' . $relative . '.php';
                    }
                }
            }

            throw new RuntimeException("No production package declares the namespace of {$class}.");
        }

    // ------------------------------------------------------------------
    // Running the script
    // ------------------------------------------------------------------

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCheck(?string $packageDirectory): array
    {
        $script = (self::$repositoryRoot ?? dirname(__DIR__, 3)) . '/scripts/check-release-bootstrap.php';

        if (! is_file($script)) {
            throw new RuntimeException('scripts/check-release-bootstrap.php is missing.');
        }

        $command = [PHP_BINARY, $script];

        if ($packageDirectory !== null) {
            $command[] = $packageDirectory;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, self::$repositoryRoot);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the release bootstrap check.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /**
     * @return list<SplFileInfo>
     */
    private function filesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * The path of $pathname below $from, with forward slashes. Both are
     * normalised first: a root ending in a separator would otherwise make
     * substr() cut one byte too few and drop the first path segment.
     */
    private function relativeTo(string $from, string $pathname): string
    {
        return ltrim(substr(
            str_replace('\\', '/', $pathname),
            strlen(rtrim(str_replace('\\', '/', $from), '/'))
        ), '/');
    }

    /**
     * A path relative to the directory holding $autoloader, with this
     * platform's separator, so the generated autoloader works on Windows and on
     * CI alike. An empty $autoloader means the repository root.
     */
    private function within(string $autoloader, string $relative): string
    {
        $base = $autoloader === ''
            ? (self::$repositoryRoot ?? dirname(__DIR__, 3))
            : dirname($autoloader);

        return $base . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
         * Renders the PSR-4 map. Each value stays a list, because one namespace root
         * can legitimately map to several directories.
         *
         * @param array<string, list<string>> $values
         */
    private function exportPsr4(array $values): string
    {
        if ($values === []) {
            return '[]';
        }

        $lines = [];

        foreach ($values as $key => $directories) {
            $paths = array_map(static fn (string $path): string => var_export($path, true), $directories);
            $lines[] = '    ' . var_export($key, true) . ' => [' . implode(', ', $paths) . '],';
        }

        return "[\n" . implode("\n", $lines) . "\n]";
    }

    /**
         * @param array<string, string> $values Class => file.
         */
    private function exportClassmap(array $values): string
    {
        if ($values === []) {
            return '[]';
        }

        $lines = [];

        foreach ($values as $key => $path) {
            $lines[] = '    ' . var_export($key, true) . ' => ' . var_export($path, true) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n]";
    }

    private static function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getPathname());

                continue;
            }

            @unlink($file->getPathname());
        }

        @rmdir($directory);
    }
}
