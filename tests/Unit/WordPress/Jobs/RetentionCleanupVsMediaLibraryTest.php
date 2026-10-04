<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';

    // The copier require_once's three wp-admin includes; see
    // WordPressSourceMaterialCopierTest for why empty files are enough.
    if (! defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/adct-test-abspath/');
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Jobs {

    use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
    use ADCT\ParishIntake\Core\Ingestion\MailboxMoveReceipt;
    use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
    use ADCT\ParishIntake\Core\Ingestion\RawMailMessage;
    use ADCT\ParishIntake\Core\Jobs\JobStepResult;
    use ADCT\ParishIntake\Core\Ports\ClockInterface;
    use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
    use ADCT\ParishIntake\Core\Ports\MailboxInterface;
    use ADCT\ParishIntake\Core\Ports\MailboxSettingsStoreInterface;
    use ADCT\ParishIntake\Core\Ports\ProcessedMailboxMessageStoreInterface;
    use ADCT\ParishIntake\WordPress\Attachments\WordPressSourceMaterialCopier;
    use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
    use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
    use ADCT\ParishIntake\WordPress\Jobs\RetentionCleanupJob;
    use ADCT\ParishIntake\WordPress\Jobs\RetentionSettings;
    use DateTimeImmutable;
    use DateTimeZone;
    use PHPUnit\Framework\TestCase;

    /**
     * Retention cleanup must not delete published source material (issue #172,
     * AC12).
     *
     * The worry this pins down is specific. Retention cleanup removes the raw
     * `.eml` and the inbound attachment files of a message once its retention
     * period expires, and #172 puts *copies of those very files* into the
     * public uploads directory, under new generated names. If the cleanup's
     * idea of "which files belong to this message" could also name a
     * media-library copy, then deleting a stale intake message would silently
     * delete a poster that is being served on the live events page.
     *
     * So nothing here is asserted from a reading of the regex. Both halves of
     * the claim are *run*, over a real directory tree, with the real code on
     * both sides:
     *
     *  - the producer is the real {@see WordPressSourceMaterialCopier} and the
     *    real {@see ProtectedInboundMailStorage}, so the files on disk are
     *    files the plugin itself created, under names it itself generated;
     *  - the consumer is the real {@see RetentionCleanupJob}, driven through
     *    its public entry point, so the deletion decision comes from the job's
     *    own SQL rather than from a copy of its predicate pasted into the test.
     *
     * The one substitution is the database. `REGEXP` is MySQL/MariaDB syntax
     * with no SQLite equivalent, so {@see MediaLibraryRetentionDatabase}
     * evaluates the job's *own* pattern string through `preg_match()` — the
     * same stand-in the existing RetentionCleanupJobTest uses. The pattern
     * therefore comes from production code and can only change by changing
     * production code, and the "which file names exist on disk" half of every
     * assertion is the filesystem answering, not an array the fake edited.
     *
     * Two directions are asserted, and both matter. The published copies must
     * survive (the AC12 claim), *and* the inbound original and raw message must
     * actually be deleted (otherwise the survival assertion would hold for the
     * trivial reason that the cleanup did nothing at all).
     */
    final class RetentionCleanupVsMediaLibraryTest extends TestCase
    {
        private const EVENT_ID = 42;
        private const MESSAGE_ID = 7;

        private string $privateDirectory = '';

        private string $uploadsDirectory = '';

        protected function setUp(): void
        {
            $this->ensureAbspathIncludes();

            $this->privateDirectory = sys_get_temp_dir() . '/adct-private-' . bin2hex(random_bytes(6));
            $this->uploadsDirectory = sys_get_temp_dir() . '/adct-uploads-' . bin2hex(random_bytes(6));

            mkdir($this->privateDirectory, 0700, true);
            mkdir($this->uploadsDirectory, 0700, true);

            $GLOBALS['adct_test_media'] = [];
            $GLOBALS['adct_test_media_meta'] = [];
            $GLOBALS['adct_test_media_posts'] = [];
            $GLOBALS['adct_test_media_files'] = [];
            $GLOBALS['adct_test_media_thumbnails'] = [];
            $GLOBALS['adct_test_media_next_id'] = 900;
            $GLOBALS['adct_test_media_uploads'] = ['path' => $this->uploadsDirectory];
            $GLOBALS['adct_test_sideloads'] = [];
            $GLOBALS['adct_test_attachment_inserts'] = [];
            $GLOBALS['adct_test_attachment_deletes'] = [];
            $GLOBALS['adct_test_metadata_generated'] = [];
            $GLOBALS['adct_test_metadata_written'] = [];
            $GLOBALS['adct_test_meta_writes'] = [];
            $GLOBALS['adct_test_meta_deletes'] = [];
            // wp_update_post() appends to a global shared with every other test in
            // this process, so it is seeded rather than read later.
            $GLOBALS['adct_test_post_updates'] = [];
        }

        protected function tearDown(): void
        {
            foreach ([$this->privateDirectory, $this->uploadsDirectory] as $directory) {
                $this->removeTree($directory);
            }

            unset(
                $GLOBALS['adct_test_media'],
                $GLOBALS['adct_test_media_meta'],
                $GLOBALS['adct_test_media_posts'],
                $GLOBALS['adct_test_media_files'],
                $GLOBALS['adct_test_media_thumbnails'],
                $GLOBALS['adct_test_media_next_id'],
                $GLOBALS['adct_test_media_uploads'],
                $GLOBALS['adct_test_sideloads'],
                $GLOBALS['adct_test_attachment_inserts'],
                $GLOBALS['adct_test_attachment_deletes'],
                $GLOBALS['adct_test_metadata_generated'],
                $GLOBALS['adct_test_metadata_written'],
                $GLOBALS['adct_test_meta_writes'],
                $GLOBALS['adct_test_meta_deletes'],
                $GLOBALS['adct_test_post_updates']
            );
        }

        /**
         * The AC12 claim, end to end.
         *
         * A bulletin and a poster are promoted into the media library, then the
         * message that carried them expires and the real cleanup job runs. Every
         * file the promotion put in the public uploads directory is still there
         * afterwards, while the raw `.eml` and the stored inbound original the
         * same run was supposed to remove are gone.
         */
        public function testRetentionCleanupLeavesPublishedMediaLibraryCopiesOnDisk(): void
        {
            $scenario = $this->expiredMessageWithPromotedSourceMaterial();

            $publishedBefore = $scenario['published_names'];
            self::assertNotSame([], $publishedBefore, 'The promotion was supposed to create files.');

            foreach ($publishedBefore as $name) {
                self::assertFileExists(
                    $this->uploadsDirectory . '/' . $name,
                    'A promoted copy should be on disk before cleanup runs.'
                );
            }

            $this->runCleanupOnce($scenario['database']);

            // The published poster and bulletin survive the cleanup. Named
            // explicitly so a failure says which file went missing.
            foreach ($publishedBefore as $name) {
                self::assertFileExists(
                    $this->uploadsDirectory . '/' . $name,
                    'Retention cleanup deleted a published media-library copy.'
                );
            }

            self::assertSame(
                $scenario['published_names'],
                $this->namesInUploads(),
                'Retention cleanup left an unexpected set of files in the uploads directory.'
            );

            // The negative control. Without these deletions the survival
            // assertions above would be satisfied by a cleanup that did nothing
            // at all, so they are what make the test mean something.
            self::assertFileDoesNotExist(
                $scenario['raw_absolute'],
                'Retention cleanup did not delete the expired raw message.'
            );

            foreach ($scenario['intake_names'] as $intakeName) {
                self::assertFileDoesNotExist(
                    $this->privateDirectory . '/' . $intakeName,
                    'Retention cleanup did not delete an expired inbound attachment.'
                );
            }

            self::assertSame(
                [$scenario['raw_name'], ...$scenario['intake_names']],
                $scenario['database']->deleted,
                'Cleanup should delete exactly the raw message and its inbound attachments.'
            );
        }

        /**
         * The same run, seen through the attachment query rather than the disk.
         *
         * The file-level assertion above would still pass if the copies had been
         * deleted and something else recreated them; this one asks the job's own
         * SQL what it selected, and expects the published copies never to appear
         * in the answer. It is the same production pattern — read out of the
         * prepared statement the job built — rather than a second copy of it.
         */
        public function testTheAttachmentQueryTheCleanupRunsNeverSelectsAMediaLibraryName(): void
        {
            $scenario = $this->expiredMessageWithPromotedSourceMaterial();

            $this->runCleanupOnce($scenario['database']);

            $attachmentQuery = null;

            foreach ($scenario['database']->prepared as $prepared) {
                if (str_contains($prepared['query'], 'FROM `wp_adct_pi_attachments`')) {
                    $attachmentQuery = $prepared;
                }
            }

            self::assertIsArray(
                $attachmentQuery,
                'The cleanup job did not run its attachment query at all.'
            );

            /** @var list<mixed> $arguments */
            $arguments = $attachmentQuery['arguments'];
            $pattern = $arguments[1] ?? '';

            self::assertIsString($pattern);
            self::assertSame(self::MESSAGE_ID, (int) ($arguments[0] ?? 0));

            foreach ($scenario['published_names'] as $name) {
                self::assertSame(
                    0,
                    preg_match('/' . $pattern . '/', $name),
                    'The cleanup pattern matches a published media-library file name.'
                );
            }

            // And the positive control on the same pattern: it does match the
            // intake name, which is the entire reason the query exists.
            self::assertSame(
                1,
                preg_match('/' . $pattern . '/', $scenario['intake_name']),
                'The cleanup pattern no longer matches the intake file it is meant to delete.'
            );
        }

        /**
         * Pins the reason the two sets of names cannot collide.
         *
         * This is the load-bearing difference, and it is stated as an assertion
         * about the names on disk rather than as prose: intake storage names are
         * 64 hex characters, the promotion's generated name is 32, and the
         * cleanup's pattern is anchored at 64. If someone "tidies" one of those
         * two generators to match the other, this fails immediately — which is
         * the point. Nothing about the extensions is relied upon: both sides use
         * the same allowlist.
         */
        public function testTheTwoNameFormatsDifferByHexLengthAndOnlyIntakeNamesMatchTheCleanupPattern(): void
        {
            $scenario = $this->expiredMessageWithPromotedSourceMaterial();

            $this->runCleanupOnce($scenario['database']);

            $intakeBasename = $scenario['intake_name'];
            $publishedBasenames = $scenario['promoted_names'];
            $pattern = $this->cleanupAttachmentPattern($scenario['database']);

            self::assertCount(2, $publishedBasenames, 'Both promotions should be represented.');

            foreach ($publishedBasenames as $basename) {
                self::assertSame(
                    32,
                    strlen((string) pathinfo($basename, PATHINFO_FILENAME)),
                    'A promoted media-library name is no longer a 32-character hex token.'
                );
                self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\./', $basename);

                self::assertSame(
                    0,
                    preg_match('/' . $pattern . '/', $basename),
                    'The cleanup pattern matches a promoted media-library file name.'
                );
            }

            self::assertSame(
                64,
                strlen((string) pathinfo($intakeBasename, PATHINFO_FILENAME)),
                'A stored intake name is no longer a 64-character hex token.'
            );
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\./', $intakeBasename);

            self::assertSame(
                1,
                preg_match('/' . $pattern . '/', $intakeBasename),
                'The cleanup pattern no longer matches the intake name it exists to delete.'
            );
        }

        /**
         * The strongest form of the question, with the safety net removed.
         *
         * In the other tests the published copies survive partly because the
         * cleanup never gets a row that names them — the promotion writes no
         * `storage_path` back, so the query has nothing to select. That is a
         * real protection, but on its own it would also keep a file safe whose
         * *name* the cleanup would happily match, which is not the property
         * AC12 needs.
         *
         * So here the row is forced into existence: the attachments table is
         * seeded with the promoted media-library names alongside the genuine
         * intake ones. The cleanup query now returns both sets, and the pattern
         * is the only thing distinguishing a file it must delete from a poster
         * it must leave alone.
         */
        public function testAPromotedCopyListedInTheAttachmentsTableIsStillSparedByThePatternAlone(): void
        {
            $scenario = $this->expiredMessageWithPromotedSourceMaterial();

            $database = $scenario['database'];
            $promotedNames = $scenario['promoted_names'];

            // The rows the promotion never writes. This is the hostile case.
            $database->attachments[self::MESSAGE_ID] = array_values(array_merge(
                $database->attachments[self::MESSAGE_ID],
                $promotedNames,
                array_map(
                    static fn (string $name): string => $name . '-768x1024.jpg',
                    $promotedNames
                )
            ));

            self::assertCount(
                2 + 2 * count($promotedNames),
                $database->attachments[self::MESSAGE_ID],
                'Both intake rows and every published name should be selectable.'
            );

            $this->runCleanupOnce($database);

            // The pattern is doing the work: the rows exist, the query sees
            // them, and the names still do not match.
            foreach ($promotedNames as $promotedName) {
                self::assertFileExists(
                    $this->uploadsDirectory . '/' . $promotedName,
                    'A promoted copy was deleted even though its row was selectable.'
                );
            }

            // And the negative control still holds with the extra rows present,
            // so the pattern is discriminating rather than refusing everything.
            self::assertFileDoesNotExist(
                $scenario['raw_absolute'],
                'Cleanup stopped deleting the raw message once extra rows appeared.'
            );

            self::assertSame(
                [$scenario['raw_name'], ...$scenario['intake_names']],
                $database->deleted,
                'Cleanup deleted something other than the raw message and its inbound originals.'
            );
        }

        /**
         * A second, independent route to the same deletion, and the same
         * question asked of it.
         *
         * `WordPressRetentionStore::removeExpiredMessageFiles()` is the other
         * code path that clears a message's files, and unlike the cleanup job it
         * applies no SQL pattern of its own — it passes every non-empty
         * `storage_path` straight to the storage. So the media-library copies are
         * protected here by the storage's own guard rather than by the query, and
         * that is worth running too: a promotion file that somehow reached the
         * attachments table would be refused by
         * {@see ProtectedInboundMailStorage::delete()} rather than unlinked.
         */
        public function testTheStoreThatClearsAStoragePathRefusesAMediaLibraryNameOutright(): void
        {
            $scenario = $this->expiredMessageWithPromotedSourceMaterial();

            $storage = new ProtectedInboundMailStorage($this->privateDirectory);

            $promotedName = $scenario['promoted_names'][0];

            // A real file in the public uploads directory. If the protection
            // here came from the query pattern alone, deleting it by name would
            // succeed; the storage has to refuse on its own account.
            self::assertFileExists($this->uploadsDirectory . '/' . $promotedName);

            $this->expectException(\InvalidArgumentException::class);

            $storage->delete($promotedName);
        }

        /**
         * Builds the whole situation the concern describes, with real files.
         *
         * @return array{
         *     database: MediaLibraryRetentionDatabase,
         *     published_names: list<string>,
         *     promoted_names: list<string>,
         *     intake_name: string,
         *     intake_absolute: string,
         *     intake_names: list<string>,
         *     raw_name: string,
         *     raw_absolute: string
         * }
         */
        private function expiredMessageWithPromotedSourceMaterial(): array
        {
            $storage = new ProtectedInboundMailStorage($this->privateDirectory);
            $copier = new WordPressSourceMaterialCopier($storage);

            // The message as intake stored it: one raw .eml and two attachments,
            // all under the private storage's own generated 64-hex names.
            $rawName = $storage->storeRawMessage("From: parish@example.invalid\r\nSubject: Mass\r\n\r\nbody\r\n");
            $bulletinName = $storage->storeAttachment("%PDF-1.4 parish bulletin", 'pdf');
            $posterName = $storage->storeAttachment("\x89PNG\r\n\x1a\n poster bytes", 'png');

            // A person promotes both, which is what puts copies into the public
            // uploads directory under new generated names.
            $bulletinId = $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $bulletinName,
                'October bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );
            $posterId = $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $posterName,
                'Retreat poster.png',
                SourceMaterialRole::POSTER
            );

            $published = $this->filesInUploads();

            self::assertCount(2, $published, 'Both promotions should have landed in uploads.');

            // Real WordPress writes intermediate-size sidecars next to a poster
            // (wp_generate_attachment_metadata). The shared stub creates none, so
            // they are written here by hand: without them the "survives" claim
            // would be weaker on a real site than it is here, and AC12 is about
            // the live site.
            $posterBase = (string) basename((string) $GLOBALS['adct_test_media_files'][$posterId]);
            $sidecars = [];
            foreach (['-150x150', '-300x200', '-768x1024'] as $suffix) {
                $sidecarName = $posterBase . $suffix . '.jpg';
                file_put_contents($this->uploadsDirectory . '/' . $sidecarName, 'thumbnail bytes');
                $sidecars[] = $sidecarName;
            }
            // The database as intake recorded it: only the three private files.
            // Nothing about the promotion is written back here, which is the
            // point — media-library copies are not attachment rows, so the
            // cleanup has no row to name them by.
            $database = new MediaLibraryRetentionDatabase();
            $database->messages = [
                self::MESSAGE_ID => [
                    'id' => self::MESSAGE_ID,
                    'status' => 'parsed',
                    'raw_path' => $rawName,
                    'body_text' => 'body text',
                    // Expired: both cutoffs are in the past for the clock below.
                    'retention_until' => '2026-09-24 23:00:00',
                    'received_at' => '2025-09-24 23:00:00',
                    'updated_at' => '2025-09-24 23:00:00',
                ],
            ];
            $database->attachments = [
                self::MESSAGE_ID => [$bulletinName, $posterName],
            ];

            $publishedBasenames = array_map('basename', $published);
            sort($publishedBasenames);

            // Everything the promotion put in uploads, globally sorted, so it can
            // be compared byte-for-byte against a fresh listing of the
            // directory. Sorting both sides matters: a partial sort would let a
            // deleted file hide behind a reordering.
            $everyName = array_values(array_merge($publishedBasenames, $sidecars));
            sort($everyName);

            return [
                'database' => $database,
                'published_names' => $everyName,
                // The two promoted originals only, without the sidecars: the
                // generated-name format is a property of the promotion, and
                // WordPress derives its thumbnail names from that name.
                'promoted_names' => $publishedBasenames,
                'intake_name' => $bulletinName,
                'intake_absolute' => $storage->resolveAttachmentPath($bulletinName),
                'intake_names' => [$bulletinName, $posterName],
                'raw_name' => $rawName,
                'raw_absolute' => $this->privateDirectory . '/' . $rawName,
            ];
        }

        /**
         * One real cleanup step, starting at the raw-message phase so the run is
         * short and does not need a mailbox. Processed-mailbox cleanup is off,
         * so the job completes after the raw phase instead of asking for IMAP.
         */
        private function runCleanupOnce(MediaLibraryRetentionDatabase $database): void
        {
            $job = new RetentionCleanupJob(
                $database,
                new MediaLibraryNoProcessedMessages(),
                new MediaLibraryRecordingStorage(
                    new ProtectedInboundMailStorage($this->privateDirectory),
                    $database
                ),
                new MediaLibraryNoMailboxes(),
                static fn (): RetentionSettings => RetentionSettings::fromValues('1', '30', '0', '30'),
                static fn (MailboxSettings $mailbox): string => 'unused',
                static fn (MailboxSettings $mailbox, string $password): MailboxInterface => new MediaLibraryUnusedMailbox(),
                new MediaLibraryFixedClock(),
                25
            );

            $result = $job->processNext(json_encode([
                'phase' => 'raw',
                'tokens_last_id' => 0,
                'audit_last_id' => 0,
                'raw_last_id' => 0,
                'processed' => [
                    'source_id' => 0,
                    'last_uid' => 0,
                ],
            ], JSON_THROW_ON_ERROR));

            self::assertInstanceOf(JobStepResult::class, $result);
            self::assertTrue($result->isComplete(), 'The raw cleanup pass was expected to finish the job.');
        }

        /**
         * The attachment predicate the job actually handed to the database.
         *
         * Read out of the prepared statement the job built, so it is production
         * code's pattern and not one transcribed into the test.
         */
        private function cleanupAttachmentPattern(MediaLibraryRetentionDatabase $database): string
        {
            foreach ($database->prepared as $prepared) {
                if (! str_contains($prepared['query'], 'FROM `wp_adct_pi_attachments`')) {
                    continue;
                }

                $pattern = $prepared['arguments'][1] ?? null;

                self::assertIsString($pattern);

                return $pattern;
            }

            self::fail('The cleanup job never ran its attachment query.');
        }

        /**
         * @return list<string>
         */
        private function namesInUploads(): array
        {
            $names = array_map('basename', $this->filesInUploads());
            sort($names);

            return $names;
        }

        /**
         * @return list<string>
         */
        private function filesInUploads(): array
        {
            $files = array_values(array_filter(
                (array) glob($this->uploadsDirectory . '/*'),
                static fn ($file): bool => is_string($file) && is_file($file)
            ));

            sort($files);

            return array_values($files);
        }

        private function removeTree(string $directory): void
        {
            if ($directory === '' || ! is_dir($directory)) {
                return;
            }

            foreach ((array) glob($directory . '/{,.}[!.,..]*', GLOB_BRACE) as $entry) {
                if (! is_string($entry)) {
                    continue;
                }

                if (is_dir($entry)) {
                    $this->removeTree($entry);
                    continue;
                }

                @unlink($entry);
            }

            @rmdir($directory);
        }

        private function ensureAbspathIncludes(): void
        {
            $admin = ABSPATH . 'wp-admin/includes/';

            foreach (['', 'wp-admin/includes/'] as $relative) {
                $directory = ABSPATH . $relative;

                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
            }

            foreach (['file.php', 'media.php', 'image.php'] as $file) {
                $path = $admin . $file;

                if (! is_file($path)) {
                    file_put_contents($path, "<?php\n// Intentionally empty: the plugin's stubs stand in for these.\n");
                }
            }
        }
    }

    /**
     * A database that answers the two queries the cleanup job's raw phase runs.
     *
     * `REGEXP` is MySQL/MariaDB syntax, so the job's own pattern string is
     * evaluated with `preg_match()` — the same substitution
     * RetentionCleanupJobTest makes. Everything else is recorded rather than
     * faked: the prepared statements are kept so a test can ask what the job
     * selected, and `deleted` is written by the storage, not here.
     */
    final class MediaLibraryRetentionDatabase implements DatabaseConnectionInterface
    {
            /**
             * Every statement the job prepared, in order, never consumed.
             *
             * This is the log a test reads the job's real patterns out of. It is
             * deliberately separate from the queue below: `$wpdb` consumes each
             * prepared statement once, and a log that lost entries the moment they
             * were executed could not be used to ask what the job asked for.
             *
             * @var list<array{query: string, arguments: array<int, mixed>}>
             */
            public array $prepared = [];

            /** @var array<int, array<string, mixed>> */
            public array $messages = [];

            /** @var array<int, list<string>> */
            public array $attachments = [];

            /**
             * Candidates per message id. Empty means "no candidate at all", which
             * is the state that lets the raw phase pick the message up.
             *
             * @var array<int, list<string>>
             */
            public array $candidates = [];

            /** @var list<string> */
            public array $deleted = [];

            /** @var list<array{query: string, arguments: array<int, mixed>}> */
            private array $pending = [];

            private string $lastError = '';

            public function prefix(): string
        {
            return 'wp_';
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            $statement = ['query' => $query, 'arguments' => $arguments];

            $this->prepared[] = $statement;
            $this->pending[] = $statement;

            return $query;
        }

        public function query(string $query): int|false
        {
            $prepared = array_shift($this->pending);

            if (! is_array($prepared)) {
                return 1;
            }

            if (str_contains($prepared['query'], 'SET raw_path = NULL, body_text = NULL')) {
                $messageId = (int) ($prepared['arguments'][1] ?? 0);

                if (! isset($this->messages[$messageId])) {
                    return 0;
                }

                $this->messages[$messageId]['raw_path'] = null;
                $this->messages[$messageId]['body_text'] = null;

                return 1;
            }

            return 1;
        }

        public function getRow(string $query): ?array
        {
            return null;
        }

        public function getResults(string $query): array
        {
            $prepared = array_shift($this->pending);

            if (! is_array($prepared)) {
                return [];
            }

            $sql = $prepared['query'];
            $arguments = $prepared['arguments'];

            if (str_contains($sql, 'FROM `wp_adct_pi_inbound_messages`')) {
                [
                    $retentionCutoff,
                    $receivedCutoff,
                    $pattern,
                    $statusA,
                    $statusB,
                    $statusC,
                    $lastId,
                    $candA,
                    $candB,
                    $candC,
                    $candD,
                    $limit,
                ] = $arguments;

                $rows = [];

                foreach ($this->messages as $messageId => $row) {
                    if ((int) $messageId <= (int) $lastId) {
                        continue;
                    }

                    if ((string) ($row['retention_until'] ?? '') > (string) $retentionCutoff) {
                        continue;
                    }

                    if ((string) ($row['received_at'] ?? '') > (string) $receivedCutoff) {
                        continue;
                    }

                    $rawPath = $row['raw_path'] ?? null;

                    if (! is_string($rawPath) || preg_match('/' . $pattern . '/', $rawPath) !== 1) {
                        continue;
                    }

                    if (! in_array($row['status'] ?? null, [$statusA, $statusB, $statusC], true)) {
                        continue;
                    }

                    if (($this->candidates[$messageId] ?? []) !== []) {
                        continue;
                    }

                    $rows[] = ['id' => (int) $messageId, 'raw_path' => $rawPath];
                }

                usort($rows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

                return array_slice($rows, 0, (int) $limit);
            }

            if (str_contains($sql, 'FROM `wp_adct_pi_attachments`')) {
                [$messageId, $pattern] = $arguments;
                $rows = [];

                foreach ($this->attachments[(int) $messageId] ?? [] as $path) {
                    if (preg_match('/' . $pattern . '/', $path) !== 1) {
                        continue;
                    }

                    $rows[] = ['storage_path' => $path];
                }

                return $rows;
            }

            return [];
        }

        public function escapeLike(string $text): string
        {
            return $text;
        }

        public function insertId(): int
        {
            return 1;
        }

        public function charsetCollate(): string
        {
            return '';
        }

        public function clearLastError(): void
        {
            $this->lastError = '';
        }

        public function lastError(): string
        {
            return $this->lastError;
        }
    }

    /**
     * The real private storage, with a record of what the job asked it to delete.
     *
     * The deletions themselves are the storage's own `unlink()` calls on real
     * files; only the list of requested paths is recorded, so a test can state
     * what cleanup targeted, not merely what it managed to remove.
     */
    final class MediaLibraryRecordingStorage implements InboundMailStorageReaderInterface
    {
        public function __construct(
            private readonly ProtectedInboundMailStorage $inner,
            private readonly MediaLibraryRetentionDatabase $database
        ) {
        }

        public function storeRawMessage(string $rawMessage): string
        {
            return $this->inner->storeRawMessage($rawMessage);
        }

        public function storeAttachment(string $content, string $extension): string
        {
            return $this->inner->storeAttachment($content, $extension);
        }

        public function readRawMessage(string $relativePath): string
        {
            return $this->inner->readRawMessage($relativePath);
        }

        public function resolveAttachmentPath(string $relativePath): string
        {
            return $this->inner->resolveAttachmentPath($relativePath);
        }

        public function delete(string $relativePath): void
        {
            $this->database->deleted[] = $relativePath;
            $this->inner->delete($relativePath);
        }
    }

    /**
     * Stand-ins for the collaborators the raw cleanup phase never touches.
     *
     * They exist so the job can be constructed with its real constructor. Every
     * method that would be reached throws, which is itself the assertion: if a
     * future change made the raw phase depend on the processed folder, this test
     * fails loudly instead of quietly exercising a different path.
     */
    final class MediaLibraryNoProcessedMessages implements ProcessedMailboxMessageStoreInterface
    {
        public function recordMoved(
            MailboxSettings $settings,
            MailboxMoveReceipt $receipt,
            DateTimeImmutable $internalDate,
            DateTimeImmutable $recordedAt
        ): void {
            throw new \LogicException('Processed-mailbox bookkeeping is not reached by the raw cleanup phase.');
        }

        public function findExpired(
            MailboxSettings $settings,
            int $uidValidity,
            DateTimeImmutable $cutoff,
            int $limit
        ): array {
            throw new \LogicException('Processed-mailbox cleanup is not reached by the raw cleanup phase.');
        }

        public function discardStale(MailboxSettings $settings, int $currentUidValidity): void
        {
            throw new \LogicException('Processed-mailbox cleanup is not reached by the raw cleanup phase.');
        }

        public function deleteOwned(MailboxSettings $settings, int $uidValidity, int $uid): void
        {
            throw new \LogicException('Processed-mailbox cleanup is not reached by the raw cleanup phase.');
        }
    }

    final class MediaLibraryNoMailboxes implements MailboxSettingsStoreInterface
    {
        public function findActiveMailboxes(): array
        {
            throw new \LogicException('Mailboxes are not read by the raw cleanup phase.');
        }
    }

    final class MediaLibraryUnusedMailbox implements MailboxInterface
    {
        public function listFolders(): array
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function ensureFolder(string $folder): void
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function uidValidity(?string $folder = null): int
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function uidNext(string $folder): int
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function search(\ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria $criteria): array
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function searchFolder(
            \ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria $criteria,
            string $folder
        ): array {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function fetch(int $uid): RawMailMessage
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function move(int $uid, string $folder): void
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function delete(int $uid, string $folder): void
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function markSeen(int $uid): void
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }

        public function close(): void
        {
            throw new \LogicException('No mailbox is opened by the raw cleanup phase.');
        }
    }

    /**
     * A fixed instant in Africa/Johannesburg, so the expiry arithmetic is the
     * same on every run and on every machine.
     */
    final class MediaLibraryFixedClock implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-09-25T01:00:00+02:00', new DateTimeZone('Africa/Johannesburg'));
        }
    }
}
