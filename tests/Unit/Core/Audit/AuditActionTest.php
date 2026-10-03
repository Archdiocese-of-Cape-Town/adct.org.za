<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Audit;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use PHPUnit\Framework\TestCase;

/**
 * The `action` and `subject_type` columns are free-text varchar, so nothing in
 * the schema stops a write site logging an action the admin screen does not
 * offer as a filter. These tests read the write sites and check that whatever
 * they write is in a catalogue, which is what makes the screen's filter
 * dropdown complete.
 */
final class AuditActionTest extends TestCase
{
    public function testEveryActionHasALabel(): void
    {
        foreach (AuditAction::labels() as $value => $label) {
            self::assertNotSame('', $label, sprintf('Action "%s" needs a label.', $value));
        }
    }

    public function testLabelsCoverEveryCaseExactlyOnce(): void
    {
        self::assertSame(AuditAction::values(), array_keys(AuditAction::labels()));
        self::assertSame(
            array_values(AuditAction::labels()),
            array_unique(array_values(AuditAction::labels()))
        );
    }

    public function testActionsAreNamedAfterWhatHappenedNotWhoDidIt(): void
    {
        foreach (AuditAction::values() as $value) {
            self::assertMatchesRegularExpression(
                '/\A[a-z][a-z0-9_]*\z/',
                $value,
                sprintf('Action "%s" should be a lower snake case value.', $value)
            );
        }
    }

    public function testTheCatalogueCoversEveryActionTheWriteSitesUse(): void
    {
        $known = array_merge(AuditAction::values(), AuditSubjectType::values());

        foreach ($this->writeSites() as $site) {
                    foreach ($site['actions'] as $action) {
                self::assertContains(
                            $action,
                    $known,
                    sprintf(
                                'The audit write in %s records the action "%s", which is not in '
                                . 'AuditAction. The screen cannot offer it as a filter until it is.',
                        $site['file'],
                                $action
                    )
                );
            }
        }
    }

    public function testEveryActionInTheCatalogueIsActuallyWrittenSomewhere(): void
    {
        $written = array_merge(...array_map(
                    static fn (array $site): array => $site['actions'],
            $this->writeSites()
        ));
        $recorded = array_values(array_intersect(AuditAction::values(), $written));

        sort($recorded);
        $expected = AuditAction::values();
        sort($expected);

        self::assertSame(
            $expected,
            $recorded,
            'Every action in the catalogue must be written by a real write site, '
            . 'or the filter offers a choice that can never match a row.'
        );
    }

    /**
     * The audit writes in src, with the actions that reach the `action` column.
     *
     * The scan is token based rather than a regular expression because the SQL is
     * assembled by concatenating several literals, and a pattern has to survive
     * knowing where the statement begins. Reading the argument list of the call
     * that performs the write gets the position right instead of guessing it.
     *
     * Two shapes are recognised:
     *
     * - `AuditAction::CASE`, which is how every write site added since the
     *   catalogue exists names its action. Resolving the reference through the
     *   enum keeps the test honest: it checks the reference, not a copy of the
     *   string.
     * - the inline INSERTs the existing code shares with the change they describe,
          *   where the action is a literal or a ternary over literals.
     *
     * Only the action argument is read. Scanning a whole statement for string
     * literals would pick up `$action === 'approve'` � the comparison that
     * chooses between the two audited actions rather than being one � and then
     * demand `approve` be filterable, which it is not.
     *
     * The catalogue files themselves are skipped: AuditAction.php names every case
     * by definition, so counting it would let the test pass without a single row
     * ever being written.
     *
     * @return list<array{file: string, actions: list<string>}>
     */
    private function writeSites(): array
    {
        $sites = [];
        $root = dirname(__DIR__, 4) . '/src';
        $catalogues = [
            'Core/Audit/AuditAction.php',
            'Core/Audit/AuditSubjectType.php',
            'Core/Audit/AuditQuery.php',
        ];

        foreach ($this->phpFiles($root) as $file) {
            $source = (string) file_get_contents($file);
            $short = str_replace('\\', '/', substr($file, strlen($root) + 1));

            if (in_array($short, $catalogues, true)) {
                continue;
            }

            $actions = $this->caseReferences($source);
                        $tokens = token_get_all($source);

                        foreach ($tokens as $position => $token) {
                            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'prepare') {
                                continue;
                            }

                            $statement = $this->statement($this->argumentsAfter($tokens, $position));

                            if ($statement === null || $this->isAuditInsert($statement['sql']) === false) {
                                continue;
                            }

                            $actions = array_merge($actions, $this->insertActions($statement));
                        }

            foreach ($this->auditCalls($source) as $argument) {
                $actions = array_merge($actions, $this->stringLiterals($argument));
            }

            if ($actions !== []) {
                $sites[] = ['file' => $short, 'actions' => array_values(array_unique($actions))];
            }
        }

        self::assertNotSame([], $sites, 'No audit write sites were found, so this test proves nothing.');

        return $sites;
    }

    /**
     * The values named by `AuditAction::CASE`.
     *
     * `AuditAction` is an enum, so a case name differs from the value it stores.
     * A name matching no case is a reference to something else entirely,
     * `AuditAction::class` say, and is skipped rather than resolved, because
     * resolving it would throw.
     *
     * @return list<string>
     */
    private function caseReferences(string $source): array
    {
        preg_match_all('/\bAuditAction::([A-Z][A-Z0-9_]*)\b/', $source, $references);

        $cases = [];

        foreach (AuditAction::cases() as $case) {
            $cases[$case->name] = $case->value;
        }

        $values = [];

        foreach (array_unique($references[1] ?? []) as $name) {
            if (isset($cases[$name])) {
                $values[] = $cases[$name];
            }
        }

        return $values;
    }

    /**
     * The second argument of every `->audit(...)` call in a source.
     *
     * @return list<string>
     */
    private function auditCalls(string $source): array
    {
        $arguments = [];
        $tokens = token_get_all($source);

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (!is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;

            if (!is_array($next) || $next[0] !== T_STRING || $next[1] !== 'audit') {
                continue;
            }

            $arguments[] = $this->argumentAfter($tokens, $index, 2);
        }

        return array_values(array_filter($arguments, static fn (string $a): bool => $a !== ''));
    }

    /**
     * The actions of an audit INSERT.
     *
     * Every audit INSERT names the same columns, `actor, action, subject_type,
     * subject_id, details, created_at, updated_at`, so the action is the second
     * bound value. Reading it positionally is what keeps `'approve'` and
     * `'event_candidate'` from being mistaken for an action when they are
     * neither: `$action === 'approve'` is the comparison that chooses between
     * two audited actions, not an action of its own.
     *
     * @param array{sql: string, bindings: list<string>} $statement
     *
     * @return list<string>
     */
    private function insertActions(array $statement): array
        {
            $bindings = $statement['bindings'];

            if (count($bindings) < 3) {
                self::fail(sprintf(
                    'The audit INSERT binds fewer arguments than its column list names: %s',
                    $statement['sql']
                ));
            }

            // The actor is bound first and the action second in every write site.
            $actor = trim($bindings[0]);
            $action = trim($bindings[1]);

            if ($actor === '') {
                self::fail(sprintf(
                    'The audit INSERT no longer binds an actor: %s',
                    $statement['sql']
                ));
            }

            return $this->stringLiterals($action);
        }

        /**
             * The source text of each top level argument of the call after an offset.
         *
         * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
         *
         * @return list<string>
         */
        private function argumentsAfter(array $tokens, int $offset): array
        {
            return $this->argumentList($tokens, $offset + 1);
        }

        /**
         * Whether a reconstructed statement inserts into the audit table.
         *
         * The table is named by a literal in some write sites and by a variable in
         * others, so the table name cannot be the test. What every audit INSERT has
         * in common is the column list: `actor` and `action` together identify the
         * audit table, and no other table in the plugin has both. The retention job
         * selects from the same table but inserts nowhere, so it does not match.
         */
            private function isAuditInsert(string $sql): bool
            {
            return preg_match('/\bINSERT\s+INTO\b/i', $sql) === 1
                && preg_match('/\bactor\b/i', $sql) === 1
                && preg_match('/\baction\b/i', $sql) === 1;
            }

            /**
         * The SQL and bound values of an INSERT, read from the arguments of one call.
         *
         * Every audit INSERT is written as a single `prepare()` call whose arguments
         * are the concatenated SQL fragments followed by the values they bind. The
         * format string is the last argument that still holds a placeholder, so
         * everything after it is a binding. Reading the arguments rather than
         * re-parsing reconstructed source is what keeps a bound `$this->actor` or
         * `$binding->email` from being confused with a column name.
         *
         * @param list<string> $arguments
         *
         * @return array{sql: string, bindings: list<string>}|null
         */
        private function statement(array $arguments): ?array
        {
            $format = null;

            foreach ($arguments as $index => $argument) {
                if (preg_match('/%[sd]/', $argument) === 1) {
                    $format = $index;
                }
            }

            if ($format === null) {
                return null;
            }

            return [
                'sql' => implode('', array_slice($arguments, 0, $format + 1)),
                'bindings' => array_values(array_slice($arguments, $format + 1)),
            ];
        }

    /**
     * The top level arguments of a call whose argument list starts after an offset.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function argumentAfter(array $tokens, int $offset, int $position): string
    {
        $arguments = $this->argumentList($tokens, $offset + 1);

        return $arguments[$position - 1] ?? '';
    }

    /**
     * The top level arguments of the first call opened at or after an offset.
     *
     * An argument is returned as source text, so a literal keeps its quotes and a
     * ternary keeps both of the literals it chooses between.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>
     */
    private function argumentList(array $tokens, ?int $from = null): array
    {
        $count = count($tokens);
        $index = $from ?? 0;
        $depth = 0;
        $current = '';
        $arguments = [];
        $started = false;

        for (; $index < $count; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;

            if (!$started) {
                if ($text === '(') {
                    $started = true;
                    $depth = 1;
                }

                continue;
            }

            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;

                if ($depth === 0) {
                    if (trim($current) !== '') {
                        $arguments[] = $current;
                    }

                    return $arguments;
                }
            } elseif ($text === ',' && $depth === 1) {
                $arguments[] = $current;
                $current = '';
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if (trim($current) === '') {
                    continue;
                }

                $current .= ' ';
                continue;
            }

            $current .= $text;
        }

        return $arguments;
    }

    /**
         * The literals of a fragment, ignoring any it compares against.
     *
         * A literal reached on the right of a comparison is the input to a decision,
         * not an outcome: `$action === 'approve' ? 'approver_approved' : ...` chooses
         * between two audited actions, and `approve` is neither of them. Reading the
         * argument tokens rather than the text is what tells the two apart, because a
         * comparison's literal follows `===` while a value's follows a comma, `?` or
         * `:`.
         *
         * @return list<string>
         */
        private function stringLiterals(string $region): array
        {
            $literals = [];
            $tokens = token_get_all('<?php f(' . $region . ');');

            for ($index = 0; $index < count($tokens); $index++) {
                $token = $tokens[$index];

                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $before = $this->previousSignificant($tokens, $index);

                if (in_array($before, ['===', '!==', '==', '!=', '<', '>', '<=', '>='], true)) {
                    continue;
                }

                $value = trim($token[1], '\'"');

                if (preg_match('/\A[a-z][a-z0-9_]*\z/', $value) === 1) {
                    $literals[] = $value;
                }
            }

            return array_values(array_unique($literals));
        }

        /**
         * The nearest meaningful token before an offset, ignoring whitespace.
         *
         * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
         */
        private function previousSignificant(array $tokens, int $offset): string
        {
            for ($index = $offset - 1; $index >= 0; $index--) {
                $token = $tokens[$index];
                $text = is_array($token) ? $token[1] : $token;

                if (trim($text) === '') {
                    continue;
                }

                return $text;
            }

            return '';
        }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];

        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($entries as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getPathname(), '.php')) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
