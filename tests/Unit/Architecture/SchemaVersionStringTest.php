<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Architecture;

use ADCT\ParishIntake\Core\Database\CreateSchemaMigration;
use ADCT\ParishIntake\Core\Database\MailboxSchemaMigration;
use ADCT\ParishIntake\Core\Database\MigrationRunner;
use ADCT\ParishIntake\Core\Database\ProcessedMailboxOwnershipSchemaMigration;
use ADCT\ParishIntake\Core\Database\VenueSchemaMigration;
use ADCT\ParishIntake\Core\Ports\MigrationLoggerInterface;
use ADCT\ParishIntake\Core\Ports\MigrationStepInterface;
use ADCT\ParishIntake\Core\Ports\MigrationVersionStoreInterface;
use ADCT\ParishIntake\WordPress\Database\ActionTokenRateLimitSchemaMigration;
use ADCT\ParishIntake\WordPress\Database\ApprovalNoticesMigration;
use ADCT\ParishIntake\WordPress\Database\ConfirmationEmailPreviewSchemaMigration;
use ADCT\ParishIntake\WordPress\Database\DbDeltaSchemaInstaller;
use ADCT\ParishIntake\WordPress\Database\FollowUpParishNullableMigration;
use ADCT\ParishIntake\WordPress\Database\MailQueueGroupKeyMigration;
use ADCT\ParishIntake\WordPress\Database\OccurrenceParishNullableMigration;
use ADCT\ParishIntake\WordPress\Database\SenderSuggestionMigration;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Guards the schema version numbers written into reader-visible text.
 *
 * No assertion anywhere checks message text, so a stale version inside a message
 * cannot fail a suite: it simply misinforms whoever reads the failing output, and
 * stays wrong until someone happens to notice. Issue #211 was four such strings.
 * This test makes that class of drift loud instead.
 *
 * The current schema version is derived from the real migration ladder rather than
 * from a literal, so it cannot drift from the migrations themselves. Four rules
 * then cover the ways a version can appear:
 *
 *   1. A phrase that names the live schema -- `schema vN`, `schema is vN`,
 *      `a fresh vN install` -- must use the current number. This is the rule that
 *      catches the bug this issue describes.
 *   2. Nothing may name a version *newer* than the current one, because no state
 *      the migrations can reach can justify it.
 *   3. A version referenced by an upgrade path or a `pre-vN` label must be a
 *      version the ladder actually implements, so a typo cannot invent one.
 *   4. `adct_pi_db_version` may only be asserted against the current version, or
 *      against the one documented pre-migration fixture value.
 *
 * Migration paths legitimately name historical versions -- `MigrationRunner::run()`
 * resumes from the stored version -- and the docs narrate the whole v1..v11 history,
 * so those references are masked before rule 1 runs rather than being forbidden.
 * That is why the rules are scoped: rule 1 to `tests/`, where a version in a
 * message is always a claim about the live schema, and rule 4 to the one assertion
 * a version bump must update by hand.
 */
final class SchemaVersionStringTest extends TestCase
{
    private const VERIFY_PLUGIN = 'tests/Integration/verify-plugin.php';
    private const DATA_MODEL = 'docs/data-model.md';

    /**
     * The one version `verify-plugin.php` asserts mid-run on purpose: after seeding
     * a pre-migration fixture and before driving the step under test. See
     * MailQueueGroupKeyMigration's duplicate-preservation check.
     */
    private const FIXTURE_VERSIONS = [4];

    /** Upgrade-path label: `v9-to-v10`, `v3-to-v11`. */
    private const UPGRADE_PATH_PATTERN = '~(?<![A-Za-z0-9_/])v(\d{1,2})\s*-to-v(\d{1,2})(?![0-9])~i';

    /** Historical state label: `pre-v10`, `since schema v11`. */
    private const HISTORICAL_PATTERN = '~(?:\bpre-v(\d{1,2})(?![0-9])|\bsince\s+schema\s+v(\d{1,2})(?![0-9]))~i';

    /**
     * A bare `vN` reference anywhere in reader-visible text.
     *
     * The negative lookbehind is what keeps REST route segments such as
     * `/wp/v2/adct_event` and `https://openrouter.ai/api/v1` out of the results.
     */
    private const CLAIM_PATTERN = '~(?<![A-Za-z0-9_/])v(\d{1,2})(?![0-9])~i';

    /**
     * Phrasings that state the live schema version. A version in one of these must
     * be the current one; a version in any other wording is historical by
     * definition, which is how `v10 sender suggestions` and `pre-v10` stay legal.
     */
    private const CURRENT_VERSION_PHRASES = [
        'schema v{n}' => '~\bschema\s+v(\d{1,2})(?![0-9])~i',
        'schema is v{n}' => '~\bschema\s+is\s+v(\d{1,2})(?![0-9])~i',
        // No leading article required: assertSenderSuggestionColumns('fresh v11 install')
        // passes the label bare, and that label names the live schema too.
        'fresh v{n} install' => '~\bfresh\s+v(\d{1,2})\s+install~i',
    ];

    /** Rule 1: version text inside a PHP string literal under `tests/`. */
    private const TEST_TREE = 'tests';

    /** Rule 2: reader-visible text across both trees. */
    private const SCANNED_DIRECTORIES = ['tests', 'docs'];
    private const SCANNED_EXTENSIONS = ['php', 'md'];

    /**
     * This file is skipped by the scanners below, because its own self-tests quote
     * the stale strings they are meant to catch. Every other file under the scan is
     * checked, so weakening a guard here is a visible edit to the only file that
     * carries no check of its own.
     */
    private const SELF_PATH = 'tests/Unit/Architecture/SchemaVersionStringTest.php';

    /**
     * The current schema version, derived from the same migration ladder
     * `Plugin::createMigrationRunner()` builds.
     */
    public function testCurrentSchemaVersionIsDerivedFromTheMigrationLadder(): void
    {
        $versions = self::implementedVersions();

        self::assertSame(range(1, count($versions)), $versions, 'The ladder must stay contiguous from v1.');
        self::assertSame(11, $versions[count($versions) - 1]);
        self::assertSame(11, self::currentSchemaVersion());
    }

    /**
     * Rule 1: a message in the test tree that names the live schema must use the
     * current version. This is the assertion that would have caught issue #211.
     */
    public function testSchemaVersionPhrasesInTestsUseTheCurrentSchemaVersion(): void
    {
        $current = self::currentSchemaVersion();
        $offenders = [];

        foreach (self::chunksInDirectory([self::TEST_TREE]) as $chunk) {
            foreach (self::stalePhrasesIn($chunk['text'], $current) as $phrase) {
                $offenders[] = sprintf('%s:%d  [%s] %s', $chunk['file'], $chunk['line'], $phrase, trim($chunk['text']));
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "Found %d message(s) naming a superseded schema version as if it were current, but the schema is at %d.\n\n"
                . "A message may call the live schema only \"v%d\". An older version must instead be labelled as an\n"
                . "upgrade path (vA-to-v%d), a historical state (pre-vN, since schema vN), or the version that\n"
                . "introduced something, in the past tense. Fix the strings below; do not weaken this assertion.\n\n%s",
                count($offenders),
                $current,
                $current,
                $current,
                implode("\n", $offenders)
            )
        );
    }

    /** Rule 2: nothing may claim a version the ladder cannot reach. */
    public function testNoClaimNamesAVersionNewerThanTheCurrentSchemaVersion(): void
    {
        $current = self::currentSchemaVersion();
        $offenders = [];

        foreach (self::chunksInDirectory(self::SCANNED_DIRECTORIES) as $chunk) {
            foreach (self::claimsIn($chunk['text']) as $claim) {
                if ($claim['version'] <= $current) {
                    continue;
                }
                $offenders[] = sprintf('%s:%d  [%s] %s', $chunk['file'], $chunk['line'], $claim['matched'], trim($chunk['text']));
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "Found %d reference(s) to a schema version above the current version %d.\n"
                . "Only versions 1 to %d exist; anything higher is a typo or an unimplemented migration.\n\n%s",
                count($offenders),
                $current,
                $current,
                implode("\n", $offenders)
            )
        );
    }

    /** Rule 3: a historical reference must name a version that actually exists. */
    public function testHistoricalVersionReferencesNameAnImplementedVersion(): void
    {
        $implemented = self::implementedVersions();
        $offenders = [];

        foreach (self::chunksInDirectory(self::SCANNED_DIRECTORIES) as $chunk) {
            foreach ([self::UPGRADE_PATH_PATTERN, self::HISTORICAL_PATTERN] as $pattern) {
                $found = preg_match_all($pattern, $chunk['text'], $matches, PREG_OFFSET_CAPTURE);
                if ($found === false || $found === 0) {
                    continue;
                }
                foreach ([1, 2] as $group) {
                    foreach ($matches[$group] as $match) {
                        if ($match[0] === '') {
                            continue;
                        }
                        if (in_array((int) $match[0], $implemented, true)) {
                            continue;
                        }
                        $offenders[] = sprintf(
                            '%s:%d  [%s] %s',
                            $chunk['file'],
                            $chunk['line'],
                            $match[0],
                            trim($chunk['text'])
                        );
                    }
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "Found %d historical reference(s) to a schema version no migration implements. Implemented: %s.\n\n%s",
                count($offenders),
                implode(', ', $implemented),
                implode("\n", $offenders)
            )
        );
    }

    /**
     * Rule 4: the stored-version option is the one thing a version bump must
     * update in the harness by hand, so its assertions are pinned here.
     */
    public function testStoredVersionAssertionsUseTheCurrentSchemaVersion(): void
    {
        $current = self::currentSchemaVersion();
        $asserted = array_values(array_unique(array_column(self::storedVersionAssertions(), 'version')));
        sort($asserted);

        $allowed = array_values(array_unique(array_merge([$current], self::FIXTURE_VERSIONS)));
        sort($allowed);

        self::assertSame(
            [],
            array_values(array_diff($asserted, $allowed)),
            sprintf(
                "verify-plugin.php asserts adct_pi_db_version against [%s]. Allowed: [%s] -- the current version and the"
                . " documented pre-migration fixture versions.\nA version bump that does not update this assertion fails"
                . " here instead of quietly misinforming a reader.",
                implode(', ', $asserted),
                implode(', ', $allowed)
            )
        );
    }

    /** The data model must announce the live version, and it must be right. */
    public function testDataModelAnnouncesTheCurrentSchemaVersion(): void
    {
        $line = self::findLine(self::DATA_MODEL, '~^\s*\*\*Current schema version:\s*(\d+)~');

        self::assertNotNull($line, self::DATA_MODEL . ' must state "Current schema version: N".');
        self::assertSame(self::currentSchemaVersion(), $line['version'], $line['text']);
    }

    /**
     * Self-tests for the scanner, so a broken mask cannot quietly turn the guards
     * above into no-ops. Each names the failure it is meant to catch.
     */
    public function testPhraseScannerFlagsAStaleCurrentVersionClaim(): void
    {
        self::assertSame(
            ['schema v10'],
            self::stalePhrasesIn('The schema v10 integration fixture must enumerate all 19 plugin tables.', 11),
            'A "schema vN" message naming a superseded version is exactly the drift to catch.'
        );

        self::assertSame(
            ['schema v10'],
            self::stalePhrasesIn('Schema v10 tables differ. Missing: [%s]; unexpected: [%s].', 11)
        );

        self::assertSame(
            [],
            self::stalePhrasesIn("assertSenderSuggestionColumns('fresh v11 install');", 11),
            'The current version must never be reported as stale.'
        );
    }

    public function testPhraseScannerIgnoresUpgradePathsAndRestRoutes(): void
    {
        self::assertSame(
            [],
            self::stalePhrasesIn("'/wp/v2/adct_event', the v9-to-v11 path and pre-v10 since schema v9", 11),
            'REST route segments and historical labels must not be read as live-schema claims.'
        );
    }

    public function testClaimScannerFindsVersionsButNotRestRoutes(): void
    {
        self::assertSame(
            ['v10'],
            self::matchedVersionsIn('The schema v10 integration fixture must enumerate all 19 plugin tables.'),
            'The claim scanner must report the bare reference a stale message contains.'
        );

        self::assertSame(
            [],
            self::matchedVersionsIn("rest_get_server_index() on '/wp/v2/adct_event' and https://openrouter.ai/api/v1"),
            'A REST route segment must never be read as a schema version.'
        );

        self::assertSame(
            ['v12'],
            self::matchedVersionsIn('Schema v12 tables differ.'),
            'A version above the ladder must be visible to the above-current guard.'
        );
    }

    /**
     * @return list<int>
     */
    private static function currentSchemaVersion(): int
    {
        $runner = new MigrationRunner(
            self::migrationSteps(),
            new InMemoryMigrationVersionStore(),
            new NullMigrationLogger()
        );

        return $runner->latestVersion();
    }

    /**
     * Every version the ladder implements, ascending.
     *
     * @return list<int>
     */
    private static function implementedVersions(): array
    {
        $versions = [];
        foreach (self::migrationSteps() as $step) {
            $versions[] = $step->version();
        }

        sort($versions);

        return $versions;
    }

    /**
     * The same steps `Plugin::createMigrationRunner()` registers. No migration
     * constructor calls a WordPress function, so these can be built in the unit
     * suite; only `version()` is read, never a migration body.
     *
     * @return list<MigrationStepInterface>
     */
    private static function migrationSteps(): array
    {
        $database = new WordPressDatabaseConnection();

        $steps = [
            new CreateSchemaMigration(new DbDeltaSchemaInstaller($database)),
            new VenueSchemaMigration(new DbDeltaSchemaInstaller($database)),
            new MailboxSchemaMigration(new DbDeltaSchemaInstaller($database)),
            new OccurrenceParishNullableMigration($database),
            new MailQueueGroupKeyMigration($database),
            new ActionTokenRateLimitSchemaMigration(new DbDeltaSchemaInstaller($database)),
            new ConfirmationEmailPreviewSchemaMigration($database),
            new ApprovalNoticesMigration(new DbDeltaSchemaInstaller($database)),
            new ProcessedMailboxOwnershipSchemaMigration(new DbDeltaSchemaInstaller($database)),
            new SenderSuggestionMigration(new DbDeltaSchemaInstaller($database)),
            new FollowUpParishNullableMigration($database),
        ];

        foreach ($steps as $step) {
            if (! $step instanceof MigrationStepInterface) {
                self::fail('Every registered migration must implement MigrationStepInterface.');
            }
        }

        return $steps;
    }

    /**
     * The current-version phrasings that do not use the current number.
     *
     * @return list<string>
     */
    private static function stalePhrasesIn(string $text, int $current): array
    {
        $masked = self::maskHistoricalPhrases($text);
        $stale = [];

        foreach (self::CURRENT_VERSION_PHRASES as $pattern) {
            $found = preg_match_all($pattern, $masked, $matches, PREG_OFFSET_CAPTURE);
            if ($found === false || $found === 0) {
                continue;
            }
            foreach ($matches[0] as $index => $match) {
                if ((int) $matches[1][$index][0] === $current) {
                    continue;
                }
                $stale[] = strtolower(preg_replace('/\s+/', ' ', $match[0]));
            }
        }

        return $stale;
    }

    /**
     * Blank out text a live-schema claim is allowed to appear in: upgrade paths and
     * historical states. Both are legitimate by construction -- `MigrationRunner`
     * resumes from the stored version, so a path climbs to the current schema no
     * matter which version it starts from, and a `pre-vN` label names where it starts.
     */
    private static function maskHistoricalPhrases(string $text): string
    {
        foreach ([self::UPGRADE_PATH_PATTERN, self::HISTORICAL_PATTERN] as $pattern) {
            $text = (string) preg_replace($pattern, ' ', $text);
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private static function matchedVersionsIn(string $text): array
    {
        return array_column(self::claimsIn($text), 'matched');
    }

    /**
     * @return list<array{version: int, matched: string}>
     */
    private static function claimsIn(string $text): array
    {
        $found = preg_match_all(self::CLAIM_PATTERN, $text, $matches, PREG_SET_ORDER);
        if ($found === false || $found === 0) {
            return [];
        }

        $claims = [];
        foreach ($matches as $match) {
            $claims[] = ['version' => (int) $match[1], 'matched' => $match[0]];
        }

        return $claims;
    }

    /**
     * Every string literal in the scanned PHP files, plus every line of the
     * Markdown docs. Comments and identifiers are skipped deliberately: only
     * literals and prose are text a reader can be misled by.
     *
     * @param list<string> $directories
     * @return list<array{file: string, line: int, text: string}>
     */
    private static function chunksInDirectory(array $directories): array
    {
        $root = dirname(__DIR__, 3);
        $chunks = [];

        foreach ($directories as $directory) {
            $path = $root . '/' . $directory;
            if (! is_dir($path)) {
                continue;
            }

            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $entry */
            foreach ($entries as $entry) {
                if (! $entry->isFile()) {
                    continue;
                }
                if (! in_array(strtolower($entry->getExtension()), self::SCANNED_EXTENSIONS, true)) {
                    continue;
                }
                $file = str_replace('\\', '/', ltrim(str_replace($root, '', $entry->getPathname()), '/'));
                if ($file === self::SELF_PATH) {
                    continue;
                }
                foreach (self::chunksInFile($root, $entry->getPathname()) as $chunk) {
                    $chunks[] = $chunk;
                }
            }
        }

        return $chunks;
    }

    /**
     * @return list<array{file: string, line: int, text: string}>
     */
    private static function chunksInFile(string $root, string $path): array
    {
        $file = str_replace('\\', '/', ltrim(str_replace($root, '', $path), '/'));
        $chunks = [];

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            $tokens = @token_get_all((string) file_get_contents($path), TOKEN_PARSE);
            if (! is_array($tokens)) {
                return [];
            }
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $chunks[] = ['file' => $file, 'line' => $token[2], 'text' => $token[1]];
                }
            }

            return $chunks;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        foreach ($lines === false ? [] : $lines as $index => $line) {
            $chunks[] = ['file' => $file, 'line' => $index + 1, 'text' => $line];
        }

        return $chunks;
    }

    /**
     * @return list<array{version: int}>
     */
    private static function storedVersionAssertions(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::VERIFY_PLUGIN);

        $found = preg_match_all(
            '~adct_pi_db_version[^\n]{0,200}?(?:!==|===)\s*(\d+)~',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        if ($found === false || $found === 0) {
            return [];
        }

        $assertions = [];
        foreach ($matches as $match) {
            $assertions[] = ['version' => (int) $match[1]];
        }

        return $assertions;
    }

    /**
     * @return array{version: int, text: string}|null
     */
    private static function findLine(string $relative, string $pattern): ?array
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $index => $line) {
            $found = preg_match($pattern, $line, $matches);
            if ($found === 1) {
                return ['version' => (int) $matches[1], 'text' => trim($line)];
            }
        }

        return null;
    }
}

final class InMemoryMigrationVersionStore implements MigrationVersionStoreInterface
{
    private int $version = 0;

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): bool
    {
        $this->version = $version;

        return true;
    }
}

final class NullMigrationLogger implements MigrationLoggerInterface
{
    public function migrationFailed(int $version, Throwable $failure): void
    {
    }

    public function migrationsSucceeded(int $version): void
    {
    }
}