<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Publishing;

require_once __DIR__ . '/../../../Support/WordPressMediaStubs.php';

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditWriter;
use ADCT\ParishIntake\Core\Ports\IntakeAttachmentReaderInterface;
use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use ADCT\ParishIntake\WordPress\Audit\ActorResolver;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\WordPressRetentionStore;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use ADCT\ParishIntake\WordPress\Publishing\WordPressEventMeta;
use ADCT\ParishIntake\WordPress\Publishing\WordPressMediaLibraryGateway;
use ADCT\ParishIntake\WordPress\Publishing\WordPressSourceMaterialStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #172, trap three: a media-library copy is exempt from intake retention.
 *
 * The retention window exists because an inbox is ephemeral. A promoted copy is
 * not: the moment a human published it, it became an asset of the site, shown on
 * a public page. If retention could still reach it, cleaning up a parish's email
 * would silently break or unpublish the source material a reviewer had already
 * approved -- with no error at all, because the copy's own post row would still
 * be there serving a broken image.
 *
 * So this test does not assert on SQL. It puts real files in real directories,
 * promotes one for real, runs the real retention store over the same message,
 * and then asks the question a visitor would be asking that morning: is the
 * public file still there, and does the event still point at it?
 *
 * The exemption turns out to be structural rather than a flag somebody has to
 * remember to set. `WordPressRetentionStore` can only ever delete through
 * `ProtectedInboundMailStorage`, which accepts exactly one shape of name --
 * 64 hex characters plus a known extension. A promoted copy is named
 * `adct-source-...`, and it lives in the uploads directory rather than the
 * private inbound one, so retention has no route to it even by accident.
 */
final class PromotedMediaRetentionTest extends TestCase
{
    private const EVENT_ID = 42;
    private const MESSAGE_ID = 3;
    private const INTAKE_ATTACHMENT_ID = 7;
    private const MEDIA_ID = 901;

        /**
         * Every global the media stubs read. They are process-wide, and the suite
         * runs in random order, so each test both resets them and clears them
         * afterwards rather than trusting the next test to have done it.
         */
        private const MEDIA_GLOBALS = [
            'adct_test_sideloads',
            'adct_test_sideload_file',
            'adct_test_sideload_error',
            'adct_test_sideload_type',
            'adct_test_empty_file',
            'adct_test_uploads_dir',
            'adct_test_media_posts',
            'adct_test_media_meta',
            'adct_test_media_deleted',
            'adct_test_media_next_id',
            'adct_test_generated_metadata',
            'adct_test_removed_files',
            'adct_test_delete_file_refused',
            'adct_test_children_queries',
            'adct_publishing_meta',
            'adct_publishing_featured',
        ];

        private string $originalFilename = 'parish-poster.jpg';
        private string $intakeRelativePath = '';
        private string $intakeDir = '';
        private string $uploadsDir = '';
        private WordPressSourceMaterialStore $store;

        protected function setUp(): void
        {
            $this->clearMediaGlobals();

            $base = sys_get_temp_dir() . '/adct-pi-retention-' . bin2hex(random_bytes(6));
        $this->intakeDir = $base . '/intake';
        $this->uploadsDir = $base . '/uploads';
        mkdir($this->intakeDir, 0777, true);
        mkdir($this->uploadsDir, 0777, true);

        $this->intakeRelativePath = str_repeat('a', 64) . '.jpg';
        file_put_contents(
            $this->intakeDir . '/' . $this->intakeRelativePath,
            'adct-test-poster-bytes'
        );

                $GLOBALS['adct_test_uploads_dir'] = $this->uploadsDir;
                $GLOBALS['adct_test_sideload_error'] = '';
                $GLOBALS['adct_test_sideload_type'] = 'image/jpeg';
                $GLOBALS['adct_test_generated_metadata'] = ['width' => 600, 'height' => 800];
                $GLOBALS['adct_test_media_next_id'] = self::MEDIA_ID;
                $GLOBALS['adct_test_sideloads'] = [];
                $GLOBALS['adct_test_media_posts'] = [];
                $GLOBALS['adct_test_media_meta'] = [];
                $GLOBALS['adct_test_media_deleted'] = [];
                $GLOBALS['adct_test_removed_files'] = [];
                $GLOBALS['adct_test_children_queries'] = [];
                $GLOBALS['adct_publishing_meta'] = [];
                $GLOBALS['adct_publishing_featured'] = [];

        $reader = new RetentionTestAttachmentReader([
            self::INTAKE_ATTACHMENT_ID => [
                'id' => self::INTAKE_ATTACHMENT_ID,
                'message_id' => self::MESSAGE_ID,
                'filename' => $this->originalFilename,
                'mime_type' => 'image/jpeg',
                'size_bytes' => 5120,
                'storage_path' => $this->intakeRelativePath,
                'status' => 'stored',
            ],
        ]);

        $this->store = new WordPressSourceMaterialStore(
            $reader,
            new ProtectedInboundMailStorage($this->intakeDir),
            new WordPressMediaLibraryGateway(),
            new WordPressEventMeta(),
            new RetentionTestActorResolver(),
            new RetentionTestAuditWriter()
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->intakeDir);
        $this->removeDirectory($this->uploadsDir);

        if ($this->intakeDir !== '' && is_dir(dirname($this->intakeDir))) {
            rmdir(dirname($this->intakeDir));
        }

            $this->clearMediaGlobals();
        }

        private function clearMediaGlobals(): void
        {
            foreach (self::MEDIA_GLOBALS as $global) {
                unset($GLOBALS[$global]);
            }
        }

    public function testDeletingThePrivateOriginalLeavesThePublicCopyInPlaceAndTheEventStillPointingAtIt(): void
    {
        $promoted = $this->store->promote(
            self::EVENT_ID,
            self::INTAKE_ATTACHMENT_ID,
            SourceAttachment::ROLE_POSTER
        );

        self::assertSame(self::MEDIA_ID, $promoted->mediaId);

        $publicFile = $this->promotedFilePath();
        self::assertFileExists($publicFile, 'the promotion really copied a file');
        self::assertFileExists(
            $this->intakeDir . '/' . $this->intakeRelativePath,
            'and it left the private original alone, because promotion copies'
        );

        // The retention window elapses and the inbox is cleaned up.
        $database = $this->runRetentionCleanup(self::MESSAGE_ID, '2026-01-01 00:00:00');

        self::assertFileDoesNotExist(
            $this->intakeDir . '/' . $this->intakeRelativePath,
            'the private original is gone, which is the whole point of the retention window'
        );
        self::assertFileExists(
            $publicFile,
            'the promoted copy is a site asset now, so retention may not take it'
        );

        // And the event is untouched: still listed, still the poster, still featured.
        $sources = $this->store->forEvent(self::EVENT_ID);
        self::assertCount(1, $sources, 'the event still offers its source material');
        self::assertSame(self::MEDIA_ID, $sources[0]->mediaId);
        self::assertSame($this->originalFilename, $sources[0]->originalFilename);
        self::assertSame(SourceAttachment::ROLE_POSTER, $sources[0]->role);
        self::assertSame(
            self::MEDIA_ID,
            $GLOBALS['adct_publishing_featured'][self::EVENT_ID] ?? 0,
            'the poster is still the featured image'
        );

        self::assertSame(
            [],
            $GLOBALS['adct_test_media_deleted'] ?? [],
            'retention never reached the media library at all'
        );
        self::assertSame(
            [],
            $GLOBALS['adct_test_removed_files'] ?? [],
            'and never asked the uploads directory to remove a promoted file'
        );
        self::assertNotContains(
            'wp_posts',
            $database->queriesSeen(),
            'retention has no business reading or writing the posts table, where a media-library copy lives'
        );
    }

    public function testRetentionCannotEvenNameAPromotedCopyBecauseNoStoragePathItAcceptsLooksLikeOne(): void
    {
        $promoted = $this->store->promote(
            self::EVENT_ID,
            self::INTAKE_ATTACHMENT_ID,
            SourceAttachment::ROLE_POSTER
        );

        // If this assertion ever starts failing, the exemption stopped being a
        // guarantee and became a convention.
        $storage = new ProtectedInboundMailStorage($this->intakeDir);
        $storedName = $this->storedCopyName($promoted->mediaId);

        self::assertNotSame('', $storedName, 'the copy does have a name');
        self::assertStringStartsNotWith(
            '/',
            $storedName,
            'and it is a bare filename, not a path that could escape anywhere'
        );

        try {
            $storage->delete($storedName);
            self::fail('A promoted name must not be resolvable as a private storage path.');
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString('invalid', $refusal->getMessage());
        }
    }

    public function testARemovedItemSurvivesRetentionBecauseTheFileIsNeverDeletedInTheFirstPlace(): void
    {
        $promoted = $this->store->promote(
            self::EVENT_ID,
            self::INTAKE_ATTACHMENT_ID,
            SourceAttachment::ROLE_POSTER
        );
        $publicFile = $this->promotedFilePath();

        // Removal is a visibility change, not a deletion, so the bytes are still
        // on disk even though nothing lists them any more.
        $this->store->remove(self::EVENT_ID, $promoted->mediaId);
        self::assertSame([], $this->store->forEvent(self::EVENT_ID));
        self::assertFileExists($publicFile, 'removal detaches; it does not delete');

        $this->runRetentionCleanup(self::MESSAGE_ID, '2026-01-01 00:00:00');

        self::assertFileExists(
            $publicFile,
            'and retention does not delete it either, because it is still a site asset'
        );
        self::assertFileDoesNotExist(
            $this->intakeDir . '/' . $this->intakeRelativePath,
            'while the private inbox copy is still swept away'
        );
    }

    /**
     * Run the real retention store over one message id.
     *
     * The database double answers only the handful of statements this code path
     * issues. Anything else returns no rows, which is the safe direction for a
     * "find something expired" query and would make this test fail rather than
     * pass quietly.
     */
    private function runRetentionCleanup(int $messageId, string $cutoff): RetentionTestDatabase
    {
        $database = new RetentionTestDatabase(
            $messageId,
            str_repeat('b', 64) . '.eml',
            $this->intakeRelativePath
        );

        (new WordPressRetentionStore(
            $database,
            new ProtectedInboundMailStorage($this->intakeDir)
        ))->removeExpiredMessageFiles($messageId, $cutoff);

        self::assertContains(
            'UPDATE-INBOUND-MESSAGE',
            $database->queryLog(),
            'the retention path really ran, so the assertions after it are not vacuous'
        );
        self::assertContains(
                    'UPDATE-ATTACHMENTS',
                    $database->queryLog(),
                    'and it really reached the attachment rows, not just the message row'
                );

                return $database;
    }

    private function promotedFilePath(): string
    {
        return (string) ($GLOBALS['adct_test_media_posts'][self::MEDIA_ID]['file'] ?? '');
    }

    /**
     * The generated name is the one WordPress would show in the library. No path
     * component of the copy is derived from the uploaded filename.
     */
    private function storedCopyName(int $mediaId): string
    {
        return (string) ($GLOBALS['adct_test_media_posts'][$mediaId]['post_title'] ?? '');
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        foreach ((array) glob($directory . '/*') as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if (is_dir($entry)) {
                $this->removeDirectory($entry);
                continue;
            }

            unlink($entry);
        }

        rmdir($directory);
    }
}

final class RetentionTestAttachmentReader implements IntakeAttachmentReaderInterface
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function findStoredById(int $attachmentId): ?array
    {
        if ($attachmentId < 1) {
            throw new InvalidArgumentException('Attachment id must be positive.');
        }

        return $this->rows[$attachmentId] ?? null;
    }

        /**
         * Retention has nothing to do with what is on offer for promotion, so this
         * double answers nothing. If the cleanup ever started promoting, it would
         * have nothing to promote and the test would fail rather than quietly
         * passing against a populated fixture.
         *
         * @return list<array{id: int, filename: string, mime_type: string, size_bytes: int, storage_path: string}>
         */
        public function findPromotableForMessage(int $messageId): array
        {
            return [];
        }
    }

final class RetentionTestActorResolver implements ActorResolver
{
    public function actor(): string
    {
        return 'dean@example.test';
    }
}

final class RetentionTestAuditWriter implements AuditWriter
{
    /**
     * @param array<string, mixed> $details
     */
    public function write(
        string $actor,
        AuditAction $action,
        string $subjectType,
        int $subjectId,
        array $details
    ): int {
        return 1;
    }
}

/**
 * A database that answers only the retention statements, and records every one
 * of them -- reads and writes alike -- so the test can prove the cleanup
 * actually happened and did not stray into the media library.
 */
final class RetentionTestDatabase implements DatabaseConnectionInterface
{
    private const TABLE_PREFIX = 'wp_adct_pi_';

    /**
     * @var list<string>
     */
    private array $log = [];

    private string $lastError = '';

    public function __construct(
        private readonly int $messageId,
        private readonly string $rawPath,
        private readonly string $attachmentPath
    ) {
    }

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        return $query;
    }

    public function query(string $query): int|false
    {
        $this->lastError = '';

        if (str_contains($query, 'UPDATE ' . self::TABLE_PREFIX . 'inbound_messages')) {
            $this->log[] = 'UPDATE-INBOUND-MESSAGE';

            return 1;
        }

        if (str_contains($query, 'UPDATE ' . self::TABLE_PREFIX . 'attachments')) {
            $this->log[] = 'UPDATE-ATTACHMENTS';

            return 1;
        }

        $this->log[] = $query;

        return 1;
    }

    public function getRow(string $query): ?array
    {
        $this->log[] = $query;

        if (str_contains($query, 'SELECT raw_path FROM ' . self::TABLE_PREFIX . 'inbound_messages')) {
            return ['raw_path' => $this->rawPath];
        }

        return null;
    }

    public function getResults(string $query): array
    {
        $this->log[] = $query;

        if (str_contains($query, 'SELECT id, storage_path FROM ' . self::TABLE_PREFIX . 'attachments')) {
            return [[
                'id' => 7,
                'storage_path' => $this->attachmentPath,
            ]];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function queryLog(): array
    {
        return $this->log;
    }

    /**
         * Every statement this path issued, in order.
         *
         * @return list<string>
         */
        public function queriesSeen(): array
        {
            return $this->log;
        }

    public function escapeLike(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function insertId(): int
    {
        return 1;
    }

    public function charsetCollate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
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