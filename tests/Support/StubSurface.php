<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads the free-function and class names a stub file under tests/Support
 * declares, by tokenising the source rather than loading it.
 *
 * Why tokenise rather than ask `get_defined_functions()`: every test file is
 * included into one PHPUnit process before any test runs, and the stub files
 * are `function_exists()`-guarded and load-order sensitive. By the time a test
 * could ask "what is defined?", the answer would be the union of whatever
 * happened to load first — not the surface one named file contributes. The
 * pinning test needs each file's *own* declaration surface, so that a new
 * permissive stub is a deliberate, visible diff against that file's list no
 * matter what order PHPUnit picked. Reading the source on disk answers that
 * identically on every run.
 *
 * Class *methods* are deliberately not collected. The surface a feature can
 * silently widen with a permissive default is the free-function WordPress
 * namespace — that is what shadows a production call. A method on the option
 * double or a fake clock is not a free function and cannot shadow anything.
 * The fake *classes* are pinned by name, so none can appear unannounced.
 */
final class StubSurface
{
    /**
     * The fully qualified free-function names a stub file declares, sorted.
     *
     * @return list<string>
     */
    public static function declaredFunctions(string $file): array
    {
        $tokens = self::tokens($file);
        $count = count($tokens);
        $names = [];
        $namespace = '';

        foreach (self::topLevelDeclarations($tokens) as $index => $kind) {
            $token = $tokens[$index];

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::readNamespace($tokens, $index);

                continue;
            }

            if ($kind !== 'function') {
                continue;
            }

            $name = self::readDeclarationName($tokens, $index);

            if ($name === null) {
                // A closure declares no name, so no WordPress surface to pin.
                continue;
            }

            $names[] = $namespace === '' ? $name : $namespace . '\\' . $name;
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * The fully qualified class names a stub file declares, sorted.
     *
     * @return list<string>
     */
    public static function declaredClasses(string $file): array
    {
        $tokens = self::tokens($file);
        $names = [];
        $namespace = '';

        foreach (self::topLevelDeclarations($tokens) as $index => $kind) {
            $token = $tokens[$index];

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::readNamespace($tokens, $index);

                continue;
            }

            if ($kind !== 'class') {
                continue;
            }

            $name = self::readDeclarationName($tokens, $index);

            if ($name === null) {
                // `new class {}` is anonymous and declares no name.
                continue;
            }

            $names[] = $namespace === '' ? $name : $namespace . '\\' . $name;
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Map each scope-level `namespace`, `class` or `function` token index to
     * its kind.
     *
     * "Scope level" means: not inside a class, interface, trait or enum body,
     * and not inside a function body. It deliberately *does* mean inside an
     * `if` block, because that is how every stub file guards its declarations
     * — `if (! function_exists('…')) { function get_option() { … } }` — and a
     * guard block is still a top-level declaration, not a nested one.
     *
     * Every brace is classified by the keyword that introduced it, because a
     * plain brace count gives the wrong answer in both directions:
     *
     *  - A method is declared inside a class body, so a plain scan for
     *    T_FUNCTION reports `WP_User::__construct` and
     *    `WP_Screen::add_help_tab` as free functions. A method cannot shadow a
     *    WordPress call, so pinning it would be noise.
     *  - A braced `namespace Foo { … }` block is a scope, not a nesting level,
     *    so counting its brace as nesting would hide every declaration in the
     *    file and report an empty surface.
     *
     * `::class` also tokenises as T_CLASS, so a class name reference is
     * rejected: it follows `T_DOUBLE_COLON` and is a constant, not a
     * declaration.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<int, string>
     */
    private static function topLevelDeclarations(array $tokens): array
    {
        $count = count($tokens);
        $declarations = [];

        /** @var list<string> $openBraces what introduced each still-open brace */
        $openBraces = [];

        // What the next `{` belongs to, if a keyword is waiting for one.
        $pendingBrace = null;
        $previousSignificant = null;

        foreach ($tokens as $index => $token) {
            if ($token === '{') {
                $openBraces[] = $pendingBrace ?? 'block';
                $pendingBrace = null;
                $previousSignificant = '{';

                continue;
            }

            if ($token === '}') {
                array_pop($openBraces);
                $previousSignificant = '}';

                continue;
            }

            if (! is_array($token)) {
                $previousSignificant = $token;

                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $insideBody = in_array('body', $openBraces, true);

            if ($token[0] === T_NAMESPACE) {
                $pendingBrace = 'namespace';
                $previousSignificant = T_NAMESPACE;

                if (! $insideBody) {
                    $declarations[$index] = 'namespace';
                }

                continue;
            }

            $kind = match ($token[0]) {
                T_CLASS => 'class',
                T_FUNCTION => 'function',
                T_INTERFACE, T_TRAIT => 'class',
                T_ENUM => 'class',
                default => null,
            };

            // `Foo::class` is a constant fetch, not a declaration.
            if ($kind === 'class' && $previousSignificant === T_DOUBLE_COLON) {
                $kind = null;
            }

            if ($kind !== null) {
                $pendingBrace = 'body';

                if (! $insideBody) {
                    $declarations[$index] = $kind;
                }
            }

            $previousSignificant = is_int($token[0]) ? $token[0] : $token[1];
        }

        return $declarations;
    }

    /**
     * Every `.php` file directly under tests/Support, by basename, so a new
     * stub file cannot appear there without the pinning test noticing and
     * being asked to record it deliberately.
     *
     * @return list<string>
     */
    public static function stubFiles(): array
    {
        $names = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $names[] = $entry->getFilename();
        }

        sort($names);

        return $names;
    }

    /**
     * Read the namespace introduced by the `namespace` keyword at $index,
     * stopping at the `;` or braced block that ends it.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function readNamespace(array $tokens, int $index): string
    {
        $namespace = '';
        $count = count($tokens);

        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            // PHP 8 hands a qualified namespace name over as one
            // T_NAME_QUALIFIED token rather than as T_STRING/T_NS_SEPARATOR
            // pairs, so the single-name and multi-part forms both have to be
            // accepted here or the namespace is silently lost.
            if (
                is_array($token)
                && in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            ) {
                $namespace .= $token[1];

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            break;
        }

        return $namespace;
    }

    /**
     * Read the name declared by a `function` or `class` keyword at $index, or
     * null when the declaration is anonymous.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function readDeclarationName(array $tokens, int $index): ?string
    {
        $count = count($tokens);

        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

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
     * @return array<int, array{0: int, 1: string, 2: int}|string>
     */
    private static function tokens(string $file): array
    {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new \RuntimeException('Could not read the stub file ' . $file . '.');
        }

        return token_get_all($source);
    }
}
