<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Publishing;

require_once __DIR__ . '/../../../Support/WordPressMediaStubs.php';

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Audit\AuditWriter;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\IntakeAttachmentReaderInterface;
use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use ADCT\ParishIntake\WordPress\Audit\ActorResolver;
use ADCT\ParishIntake\WordPress\Publishing\WordPressEventMeta;
use ADCT\ParishIntake\WordPress\Publishing\WordPressMediaLibraryGateway;
use ADCT\ParishIntake\WordPress\Publishing\WordPressSourceMaterialStore;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The WordPress adapter behind SourceMaterialStoreInterface (#172, ADR 0024).
 *
 * Four of these tests are the ones the design turns on, and each is written so
 * that a plausible-looking shortcut breaks it rather than merely changing an
 * expectation:
 *
 *  - the stored file name is generated, never derived from the parish's own name,
 *    because that name is attacker-controlled and lands in the uploads directory;
 *  - `forEvent()` answers only from the event's ordered meta, so an attachment
 *    parented to the event but never promoted stays unreachable;
 *  - a failed copy throws and writes nothing, because the event must stay
 *    published and the copy must not be left half-written;
 *  - the poster role is the one that reaches `set_post_thumbnail()`.
 *
 * The stub globals are driven per test rather than fixed, so "what did the
 * adapter ask WordPress to do" is answerable after the fact.
 */
final class WordPressSourceMaterialStoreTest extends TestCase
    {
    /**
     * @var list<string>
     */
    private const MEDIA_GLOBALS = [
    'adct_test_sideloads',
    'adct_test_sideload_file',
    'adct_test_media_posts',
    'adct_test_media_meta',
    'adct_test_media_deleted',
    'adct_test_removed_files',
    'adct_test_delete_file_refused',
    'adct_test_sideload_error',
    'adct_test_empty_file',
    'adct_test_uploads_dir',
    'adct_test_media_next_id',
    'adct_test_sideload_type',
    'adct_test_generated_metadata',
    'adct_test_children_queries',
    'adct_publishing_meta',
    'adct_publishing_featured',
    ];

    /**
     * A real directory for the copy to land in. The gateway refuses a "copy"
     * that produced no file, so a fake uploads path would make every
     * promotion fail for the wrong reason.
     */
    private string $uploadsDir = '';

    /**
     * Where the intake attachments live, also for real: `resolveAttachmentPath()`
     * throws when the file is not there, and that refusal is one of the cases
     * under test.
     */
    private string $intakeDir = '';

    protected function setUp(): void
    {
        foreach (self::MEDIA_GLOBALS as $global) {
            unset($GLOBALS[$global]);
            }

        $base = sys_get_temp_dir() . '/adct-pi-source-' . bin2hex(random_bytes(6));
        $this->uploadsDir = $base . '/uploads';
        $this->intakeDir = $base . '/intake';
        mkdir($this->uploadsDir, 0777, true);
        mkdir($this->intakeDir, 0777, true);

        $GLOBALS['adct_test_sideload_error'] = '';
        $GLOBALS['adct_test_sideload_type'] = 'image/jpeg';
        $GLOBALS['adct_test_generated_metadata'] = ['width' => 600, 'height' => 800];
        $GLOBALS['adct_test_uploads_dir'] = $this->uploadsDir;
        $GLOBALS['adct_test_media_next_id'] = 901;
        $GLOBALS['adct_test_sideloads'] = [];
        $GLOBALS['adct_test_media_posts'] = [];
        $GLOBALS['adct_test_media_meta'] = [];
        $GLOBALS['adct_test_media_deleted'] = [];
        $GLOBALS['adct_test_removed_files'] = [];
        $GLOBALS['adct_test_children_queries'] = [];
        $GLOBALS['adct_publishing_meta'] = [];
        $GLOBALS['adct_publishing_featured'] = [];
        }

    protected function tearDown(): void
    {
        foreach (self::MEDIA_GLOBALS as $global) {
            unset($GLOBALS[$global]);
            }

        foreach ([$this->uploadsDir, $this->intakeDir] as $dir) {
            if ($dir === '' || ! is_dir($dir)) {
                continue;
            }

            foreach ((array) glob($dir . '/*') as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($dir);
            }

        rmdir(dirname($this->uploadsDir));
        }

    public function testPromotingAPosterCopiesItAndRecordsItAgainstTheEvent(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'parish-poster.jpg', 'image/jpeg')]);

        $promoted = $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        self::assertSame(901, $promoted->mediaId);
        self::assertSame(7, $promoted->attachmentId);
        self::assertSame(SourceAttachment::ROLE_POSTER, $promoted->role);
        self::assertSame('parish-poster.jpg', $promoted->originalFilename);

        $post = $GLOBALS['adct_test_media_posts'][901] ?? [];
        self::assertSame(42, (int) ($post['post_parent'] ?? 0), 'the copy is parented to the event');
        self::assertSame('image/jpeg', (string) ($post['post_mime_type'] ?? ''));
        }

    public function testTheStoredFileNameIsGeneratedAndCarriesNoPartOfTheParishName(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'parish-poster.jpg', 'image/jpeg')]);

        $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        $name = $this->sideloadedName(0);

        self::assertStringStartsWith('adct-source-42-7-', $name);
        self::assertStringNotContainsString('parish', $name);
        self::assertStringNotContainsString('poster', $name);
        self::assertMatchesRegularExpression('/\Aadct-source-42-7-[0-9a-f]{16}\.jpg\z/', $name);
        }

    /**
     * The names a parish can actually submit, each hostile in a different way:
     * the first escapes the uploads directory, the second would execute if the
     * uploads directory were served by PHP, and the third breaks anything that
     * assumes a stored name is ASCII.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hostileFilenames(): array
    {
        return [
        'path traversal' => ['../../../../wp-config.php'],
        'php extension' => ['bulletin.php'],
        'non ascii' => ['parish-bulletin-\u{1F4E5}-poster.jpg'],
        'null byte' => ["poster.jpg\0.php"],
        'traversal with a valid extension' => ['a/b/../../../evil.jpg'],
        'dotfile' => ['.htaccess'],
        'quoted filename' => ['parish"onload="alert(1).jpg'],
        ];
        }

    #[DataProvider('hostileFilenames')]
    public function testNoPathComponentEverDerivesFromTheSubmittedFilename(string $filename): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, $filename, 'image/jpeg')]);

        $promoted = $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        $name = $this->sideloadedName(0);

        foreach (['..', '/', '\\', "\0", "\n", "\r", "\t"] as $never) {
            self::assertStringNotContainsString(
            $never,
            $name,
            sprintf('%s in a stored name', json_encode($never))
            );
            }

        self::assertStringNotContainsString('evil', $name);
        self::assertStringNotContainsString('bulletin', $name);
        self::assertStringNotContainsString('htaccess', $name);
        self::assertStringNotContainsString('onload', $name);
        self::assertStringNotContainsString('alert', $name);
        self::assertStringEndsNotWith('.php', $name);
        self::assertSame(1, preg_match('/\A[\x21-\x7E]+\z/', $name), 'a stored name must be plain ASCII');
        self::assertSame(1, preg_match(
        '/\Aadct-source-42-7-[0-9a-f]{16}\.jpg\z/',
        $name
        ), 'a stored name must be exactly the plugin-generated pattern');

        // The parish's own name survives only as escaped display text.
        self::assertSame($filename, $promoted->originalFilename);
        }

    public function testTheExtensionComesFromTheDeclaredTypeRatherThanTheParishName(): void
    {
        $store = $this->store(attachments: [
        7 => $this->row(7, 'whatever.jpg', 'application/pdf'),
        8 => $this->row(8, 'whatever.exe', 'image/png'),
        9 => $this->row(9, 'bulletin.pdf', 'image/webp'),
        ]);

        $store->promote(42, 7, SourceAttachment::ROLE_BULLETIN);
        $store->promote(42, 8, SourceAttachment::ROLE_POSTER);
        $store->promote(42, 9, SourceAttachment::ROLE_POSTER);

        self::assertStringEndsWith('.pdf', $this->sideloadedName(0));
        self::assertStringEndsWith('.png', $this->sideloadedName(1));
        self::assertStringEndsWith('.webp', $this->sideloadedName(2));
        }

    public function testAnExplicitTestTypeIsPassedSoWordPressDoesNotSniffTheParishName(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/webp')]);

        $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        $overrides = $GLOBALS['adct_test_sideloads'][0]['overrides'] ?? [];
        self::assertSame('image/webp', (string) ($overrides['test_type'] ?? ''));
        self::assertFalse((bool) ($overrides['test_form'] ?? true));
        self::assertArrayHasKey('test_size', $overrides, 'the storage size cap still applies at promotion');
        }

    public function testAnUploadAboveTheCapIsRefusedByWordPressAndNotSilentlyStored(): void
    {
        $GLOBALS['adct_test_sideload_error'] = 'wp_handle_upload_error';
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')]);

        try {
            $store->promote(42, 7, SourceAttachment::ROLE_POSTER);
            self::fail('A refused sideload must raise a DomainException.');
            } catch (\DomainException) {
            self::assertSame([], $GLOBALS['adct_test_media_posts']);
            }
        }

    public function testAPromotionWritesAttachmentMetadataSoTheLibraryCanShowASize(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')]);

        $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        self::assertSame(
        ['width' => 600, 'height' => 800],
        $GLOBALS['adct_test_media_meta'][901] ?? [],
        'the generated metadata is what the library renders from'
        );
        }

    public function testAPdfPromotesWithoutImageDimensions(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'bulletin.pdf', 'application/pdf')]);

        $store->promote(42, 7, SourceAttachment::ROLE_BULLETIN);

        self::assertArrayHasKey(901, $GLOBALS['adct_test_media_meta']);
        self::assertSame('application/pdf', (string) ($GLOBALS['adct_test_media_posts'][901]['post_mime_type'] ?? ''));
        }

    public function testAPosterBecomesTheEventsFeaturedImage(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')]);

        $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        self::assertSame([42 => 901], $GLOBALS['adct_publishing_featured']);
        }

    public function testABulletinNeverBecomesTheEventsFeaturedImage(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'bulletin.pdf', 'application/pdf')]);

        $store->promote(42, 7, SourceAttachment::ROLE_BULLETIN);

        self::assertSame([], $GLOBALS['adct_publishing_featured'], 'role, not type, decides the poster');
        }

    public function testPromotingIsTheOnlyThingThatEverCreatesAMediaLibraryCopy(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $GLOBALS['adct_test_media_posts'][901] = ['post_parent' => 42, 'post_mime_type' => 'image/jpeg'];
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')]);

            // Reading an event's material and taking it back off the event are both
            // ordinary operations that must never reach the filesystem.
        $store->forEvent(42);
        $store->remove(42, 901);

        self::assertSame([], $GLOBALS['adct_test_sideloads'], 'nothing is copied by reading or removing');
        self::assertSame(
        [901],
        array_keys($GLOBALS['adct_test_media_posts']),
        'the existing attachment is left in the library'
        );
        }

    public function testAnUnpromotableMimeTypeIsRefusedBeforeAnythingIsCopied(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.heic', 'image/heic')]);

        try {
            $store->promote(42, 7, SourceAttachment::ROLE_POSTER);
            self::fail('HEIC is storable but unrenderable, so it is not promotable.');
            } catch (\DomainException $exception) {
            self::assertStringContainsString('image/heic', $exception->getMessage());
            }

        self::assertSame([], $GLOBALS['adct_test_sideloads']);
        self::assertSame([], $GLOBALS['adct_test_media_posts']);
        }

    public function testAnUnknownRoleIsRefusedBeforeAnythingIsCopied(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')]);

        $this->expectException(\DomainException::class);

        try {
            $store->promote(42, 7, 'thumbnail');
            } finally {
            self::assertSame([], $GLOBALS['adct_test_sideloads']);
            }
        }

    public function testAnUnknownAttachmentIsRefused(): void
    {
        $store = $this->store(attachments: []);

        $this->expectException(\DomainException::class);

        $store->promote(42, 404, SourceAttachment::ROLE_POSTER);
        }

    public function testAnAttachmentWithNoStoredFileIsRefused(): void
    {
        $row = $this->row(7, 'poster.jpg', 'image/jpeg');
        $row['storage_path'] = '';
        $store = $this->store(attachments: [7 => $row]);

        try {
            $store->promote(42, 7, SourceAttachment::ROLE_POSTER);
            self::fail('An attachment with no stored file has nothing to copy.');
            } catch (\DomainException) {
            self::assertSame([], $GLOBALS['adct_test_sideloads']);
            }
        }

    public function testAFailedSideloadLeavesNothingBehindAndSaysSo(): void
    {
        $GLOBALS['adct_test_sideload_error'] = 'wp_handle_upload_error';
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')]);

        try {
            $store->promote(42, 7, SourceAttachment::ROLE_POSTER);
            self::fail('A failed copy must raise a DomainException so the caller can report it.');
            } catch (\DomainException $exception) {
            self::assertStringContainsString('poster.jpg', $exception->getMessage());
            }

        self::assertSame([], $GLOBALS['adct_test_media_posts'], 'no attachment row may be created');
        self::assertSame([], $GLOBALS['adct_test_media_meta']);
        self::assertSame([], $GLOBALS['adct_publishing_featured']);
        self::assertSame([], $GLOBALS['adct_publishing_meta'], 'the event meta must be untouched');
        }

    public function testASideloadWithNoDestinationFileIsTreatedAsAFailureRatherThanAPromotion(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')], emptyFile: true);

        try {
            $store->promote(42, 7, SourceAttachment::ROLE_POSTER);
            self::fail('A sideload with no file is not a copy.');
            } catch (\DomainException) {
            self::assertSame([], $GLOBALS['adct_test_media_posts']);
            self::assertSame([], $GLOBALS['adct_publishing_meta']);
            }
        }

    public function testAFailedAttachmentInsertRollsTheCopiedFileBackOff(): void
    {
        $GLOBALS['adct_test_media_next_id'] = 0;
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')]);

        try {
            $store->promote(42, 7, SourceAttachment::ROLE_POSTER);
            self::fail('An attachment insert that returns no id is a failure.');
            } catch (\DomainException) {
            self::assertSame(
            [$this->sideloadedPath(0)],
            $GLOBALS['adct_test_removed_files'] ?? [],
            'the file already written must be deleted, not left half-promoted'
            );
            }

        self::assertSame([], $GLOBALS['adct_publishing_meta']);
        self::assertSame([], $GLOBALS['adct_publishing_featured']);
        }

    public function testAMissingIntakeFileIsReportedAsAFailureAndNotAsASuccess(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')], fileExists: false);

        try {
            $store->promote(42, 7, SourceAttachment::ROLE_POSTER);
            self::fail('A missing intake file must not read as a promotion.');
            } catch (\DomainException) {
            self::assertSame([], $GLOBALS['adct_test_sideloads']);
            self::assertSame([], $GLOBALS['adct_test_media_posts']);
            }
        }

    public function testACopyThatCannotResolveItsPathIsRefusedRatherThanGuessed(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')], badPath: true);

        try {
            $store->promote(42, 7, SourceAttachment::ROLE_POSTER);
            self::fail('A storage path the adapter cannot resolve must not be copied.');
            } catch (\DomainException) {
            self::assertSame([], $GLOBALS['adct_test_sideloads']);
            }
        }

    public function testTheFileThatWasCopiedIsTheOnlyFileTheAttachmentPointsAt(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')]);

        $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        $file = (string) ($GLOBALS['adct_test_media_posts'][901]['file'] ?? '');
        self::assertStringStartsWith($this->uploadsDir, $file, 'the copy lands in the uploads directory');
        self::assertStringEndsWith($this->sideloadedName(0), $file);
        self::assertFileExists($file);
        }

    public function testForEventAnswersOnlyFromTheOrderedMeta(): void
    {
        $store = $this->store(attachments: []);

        self::assertSame([], $store->forEvent(42), 'no ordered meta means no source material, full stop');
        }

    public function testForEventNeverAsksWordPressWhatTheEventsAttachmentsAre(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $store = $this->store(attachments: []);

        $sources = $store->forEvent(42);

        self::assertCount(1, $sources);
        self::assertSame([], $GLOBALS['adct_test_children_queries'], 'a broad query over the event is forbidden');
        }

    public function testAnAttachmentParentedToTheEventButNeverPromotedStaysUnreachable(): void
    {
        // WordPress itself records the parent on the copy, so a naive
        // get_children()-style read would find it. Nothing may.
        $GLOBALS['adct_test_media_posts'][901] = [
        'post_parent' => 42,
        'post_mime_type' => 'image/jpeg',
        'post_title' => 'parish-poster',
        ];
        $store = $this->store(attachments: []);

        self::assertSame([], $store->forEvent(42));
        }

    public function testForEventReturnsItemsInTheOrderAPublisherArrangedThem(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 902, 'role' => SourceAttachment::ROLE_BULLETIN, 'original_filename' => 'b.pdf', 'attachment_id' => 8],
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $store = $this->store(attachments: []);

        $ids = array_map(static fn (SourceAttachment $s): int => $s->mediaId, $store->forEvent(42));

        self::assertSame([902, 901], $ids);
        }

    public function testForEventRebuildsTheRecordSoTheAuditTrailCanStillAnswerForIt(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $store = $this->store(attachments: []);

        $sources = $store->forEvent(42);

        self::assertSame(901, $sources[0]->mediaId);
        self::assertSame(7, $sources[0]->attachmentId);
        self::assertSame('a.jpg', $sources[0]->originalFilename);
        }

    /**
     * A meta array edited by hand, or written by an older version, must not be
     * able to make the front end crash or invent a record.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function malformedMeta(): array
    {
        return [
        'not an array' => ['nonsense'],
        'not a list' => [['role' => SourceAttachment::ROLE_POSTER]],
        'media id is zero' => [[['media_id' => 0, 'role' => 'poster', 'original_filename' => 'a.jpg', 'attachment_id' => 7]]],
        'media id is not a number' => [[['media_id' => 'nine', 'role' => 'poster', 'original_filename' => 'a.jpg', 'attachment_id' => 7]]],
        'role is unknown' => [[['media_id' => 901, 'role' => 'gallery', 'original_filename' => 'a.jpg', 'attachment_id' => 7]]],
        'no intake attachment' => [[['media_id' => 901, 'role' => 'poster', 'original_filename' => 'a.jpg', 'attachment_id' => 0]]],
        'filename is an array' => [[['media_id' => 901, 'role' => 'poster', 'original_filename' => ['a'], 'attachment_id' => 7]]],
        ];
        }

    #[DataProvider('malformedMeta')]
    public function testForEventSkipsAnEntryItCannotTrust(mixed $stored): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = $stored;
        $store = $this->store(attachments: []);

        self::assertSame([], $store->forEvent(42));
        }

    public function testForEventSkipsOnlyTheBadEntryAndKeepsTheRest(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 0, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $store = $this->store(attachments: []);

        self::assertCount(1, $store->forEvent(42));
        }

    public function testForEventTruncatesAnAbsurdlyLongFilenameRatherThanRenderingIt(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        [
        'media_id' => 901,
        'role' => SourceAttachment::ROLE_POSTER,
        'original_filename' => str_repeat('a', 5000),
        'attachment_id' => 7,
        ],
        ];
        $store = $this->store(attachments: []);

        self::assertLessThanOrEqual(255, strlen($store->forEvent(42)[0]->originalFilename));
        }

    public function testAnImpossibleEventIdIsRejectedBeforeAnythingIsCopied(): void
    {
        $store = $this->store(attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')]);

        $this->expectException(InvalidArgumentException::class);

        try {
            $store->promote(0, 7, SourceAttachment::ROLE_POSTER);
            } finally {
            self::assertSame([], $GLOBALS['adct_test_sideloads']);
            }
        }

    public function testAnImpossibleAttachmentIdIsRejected(): void
    {
        $store = $this->store(attachments: []);

        $this->expectException(InvalidArgumentException::class);

        $store->promote(42, 0, SourceAttachment::ROLE_POSTER);
        }

    public function testRemovingStopsTheItemAppearingWithoutDeletingTheStoredFile(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $store = $this->store(attachments: []);

        $store->remove(42, 901);

        self::assertSame([], $store->forEvent(42));
        self::assertSame([], $GLOBALS['adct_test_media_deleted'] ?? [], 'the stored copy stays on disk');
        }

        /**
         * The same promise, asserted against the filesystem rather than against a
         * stub's record of what it was asked.
         *
         * `testRemovingStopsTheItemAppearingWithoutDeletingTheStoredFile` above
         * checks that neither `wp_delete_attachment()` nor `wp_delete_file()` was
         * called. That would still pass if the adapter unlinked the file itself
         * behind the gateway's back, so this one promotes for real, notes the path
         * the copy actually landed on, removes it, and asks whether the bytes are
         * still readable.
         *
         * Removal is reversible by re-promoting, and it is only reversible while
         * the bytes are there: a deleted copy can only be recovered from the parish
         * by asking them to send the poster again.
         */
        public function testTheBytesOnDiskAreStillReadableAfterTheItemIsRemoved(): void
        {
            $store = $this->store(attachments: [7 => $this->row(7, 'parish-poster.jpg', 'image/jpeg')]);

            $promoted = $store->promote(42, 7, SourceAttachment::ROLE_POSTER);
            $copied = (string) ($GLOBALS['adct_test_media_posts'][$promoted->mediaId]['file'] ?? '');

            self::assertNotSame('', $copied, 'the promotion recorded where the copy landed');
            self::assertFileExists($copied);

            $store->remove(42, $promoted->mediaId);

            self::assertSame([], $store->forEvent(42), 'and it is no longer offered to anyone');
            self::assertFileExists(
                $copied,
                'but the file itself is untouched, so the decision can be taken back'
            );
            self::assertSame(
                'adct-test-intake',
                (string) file_get_contents($copied),
                'with the original bytes, not merely a file of some kind'
            );
        }

    public function testRemovingClearsTheFeaturedImageOnlyWhenThePosterIsTheOneRemoved(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $GLOBALS['adct_publishing_featured'][42] = 901;
        $store = $this->store(attachments: []);

        $store->remove(42, 901);

        self::assertArrayNotHasKey(42, $GLOBALS['adct_publishing_featured']);
        }

    public function testRemovingABulletinLeavesTheFeaturedImageAlone(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_BULLETIN, 'original_filename' => 'a.pdf', 'attachment_id' => 7],
        ];
        $GLOBALS['adct_publishing_featured'][42] = 902;
        $store = $this->store(attachments: []);

        $store->remove(42, 901);

        self::assertSame(902, $GLOBALS['adct_publishing_featured'][42] ?? 0);
        }

    public function testRemovingKeepsTheOtherItemsAndTheirOrder(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ['media_id' => 902, 'role' => SourceAttachment::ROLE_BULLETIN, 'original_filename' => 'b.pdf', 'attachment_id' => 8],
        ];
        $store = $this->store(attachments: []);

        $store->remove(42, 901);

        $ids = array_map(static fn (SourceAttachment $s): int => $s->mediaId, $store->forEvent(42));
        self::assertSame([902], $ids);
        }

    public function testRemovingSomethingWithNoMediaIdIsRejected(): void
    {
        $store = $this->store(attachments: []);

        $this->expectException(InvalidArgumentException::class);

        $store->remove(42, 0);
        }

    public function testRemovingSomethingNotOnThatEventIsRejected(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $store = $this->store(attachments: []);

        $this->expectException(\DomainException::class);

        $store->remove(42, 902);
        }

    public function testAPromotionIsRecordedAgainstTheEventWithTheActingUser(): void
    {
        $store = $this->store(
        attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')],
        audit: $audit = new RecordingAuditWriter(),
        );

            // No separate recording call: promote() writes the row itself, so a
            // screen cannot forget to and cannot write it twice.
        $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        self::assertCount(1, $audit->rows);
        self::assertSame(AuditAction::SOURCE_MATERIAL_PROMOTED, $audit->rows[0]['action']);
        self::assertSame(AuditSubjectType::EVENT, $audit->rows[0]['subjectType']);
        self::assertSame(42, $audit->rows[0]['subjectId'], 'the event is the subject, not the attachment');
        self::assertSame('dean@example.test', $audit->rows[0]['actor']);
        }

    public function testTheActorIsResolvedRatherThanPassedInSoNoScreenCanAttributeAPromotionToSomebodyElse(): void
    {
        $store = $this->store(
        attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')],
        audit: $audit = new RecordingAuditWriter(),
        actor: 'parish-officer@example.test',
        );

            // There is no actor parameter to get wrong, and no way for a screen to
            // post somebody else's address into the audit trail.
        $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        self::assertSame('parish-officer@example.test', $audit->rows[0]['actor']);
        }

    public function testARemovalIsRecordedAsItsOwnVerbSoTheLastStateIsUnambiguous(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $store = $this->store(attachments: [], audit: $audit = new RecordingAuditWriter());

        $store->remove(42, 901);

        self::assertCount(1, $audit->rows);
        self::assertSame(AuditAction::SOURCE_MATERIAL_REMOVED, $audit->rows[0]['action']);
        self::assertSame(42, $audit->rows[0]['subjectId']);
        }

    public function testARemovalRowStillNamesTheAttachmentItTookBack(): void
    {
        $GLOBALS['adct_publishing_meta'][42]['source_attachment_ids'] = [
        ['media_id' => 901, 'role' => SourceAttachment::ROLE_POSTER, 'original_filename' => 'a.jpg', 'attachment_id' => 7],
        ];
        $store = $this->store(attachments: [], audit: $audit = new RecordingAuditWriter());

        $store->remove(42, 901);

        self::assertSame(7, $audit->rows[0]['details']['attachment_id']);
        self::assertSame(901, $audit->rows[0]['details']['media_id']);
        }

    public function testTheAuditRowNamesTheIntakeAttachmentSoAPopiaEnquiryCanBeAnswered(): void
    {
        $store = $this->store(
        attachments: [7 => $this->row(7, 'poster.jpg', 'image/jpeg')],
        audit: $audit = new RecordingAuditWriter(),
        );

        $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        $details = $audit->rows[0]['details'];

        self::assertSame(7, $details['attachment_id'], 'the intake row is what identifies the parish material');
        self::assertSame(901, $details['media_id']);
        self::assertSame(SourceAttachment::ROLE_POSTER, $details['role']);
        self::assertSame('poster.jpg', $details['original_filename'], 'kept as text, escaped on render');
        }

    public function testTheAuditRowCarriesTheGeneratedNameRatherThanAnyPath(): void
    {
        $store = $this->store(
        attachments: [7 => $this->row(7, 'Church Notice.jpg', 'image/jpeg')],
        audit: $audit = new RecordingAuditWriter(),
        );

        $store->promote(42, 7, SourceAttachment::ROLE_POSTER);

        $details = $audit->rows[0]['details'];

        self::assertArrayHasKey('stored_name', $details);
        self::assertStringNotContainsString('/', (string) $details['stored_name'], 'never a path');
        self::assertStringNotContainsString('Church', (string) $details['stored_name']);
        self::assertStringStartsWith('adct-source-42-7-', (string) $details['stored_name']);
        }

    /**
     * Reading the implementation rather than exercising it, so that a future
     * change to `forEvent()` cannot quietly reopen the door the whole design
     * depends on: unpromoted material must have no route to a public page.
     */
    public function testTheImplementationNeverAnswersForEventByQueryingAttachments(): void
    {
        $source = (string) file_get_contents(
        __DIR__ . '/../../../../src/WordPress/Publishing/WordPressSourceMaterialStore.php'
        );

        foreach (['get_children', 'get_posts', 'WP_Query', 'get_attached_file', 'get_intermediate_image_sizes'] as $query) {
            self::assertStringNotContainsString($query, $source);
            }
        }

    /**
     * The port has no publish method and the publisher never receives a source
     * material store, so publishing an event cannot reach the code that copies a
     * bulletin. Both halves are asserted because either one alone would allow an
     * accidental promotion.
     */
    public function testPublishingACandidateCannotReachTheCopyAtAll(): void
    {
        $publisher = (string) file_get_contents(
        __DIR__ . '/../../../../src/Core/Publishing/CandidatePublisher.php'
        );
        $store = (string) file_get_contents(
        __DIR__ . '/../../../../src/Core/Ports/SourceMaterialStoreInterface.php'
        );

        self::assertStringNotContainsString('SourceMaterialStoreInterface', $publisher);
                self::assertStringNotContainsString('SourceAttachment', $publisher);
                self::assertStringNotContainsString('function publish', $store);
                self::assertStringNotContainsString('function publishCandidate', $store);
                }

    /**
     * The WordPress half of the same guarantee.
     *
     * The test above proves the Core cannot promote. It says nothing about
     * `WordPressPublicationStore::publish()`, which is where an auto-promotion
     * would actually be written: it is the one place that knows the event id at
     * the moment an event first exists, it already writes post meta, and
     * `set_post_thumbnail()` would be a one-line addition right beside it.
     *
     * So the publication store is read directly. The call list is the whole
     * vocabulary a promotion would need -- the copy, the featured image, the
     * media query, the role meta, and the name of this feature -- and none of
     * them may appear. `WordPressPublicationStore` is not constructed with a
     * source material store, which `testThePublicationStoreIsNotEvenWiredToOne`
     * covers separately by checking the constructor.
     *
     * This was added after mutation: adding an auto-promotion to `publish()`
     * turned the rest of this file green, so the guarantee was asserted in one
     * layer and silently absent in the other.
     */
    public function testThePublicationStoreNeverPromotesAnAttachment(): void
    {
        $source = (string) file_get_contents(
        __DIR__ . '/../../../../src/WordPress/Publishing/WordPressPublicationStore.php'
        );

        foreach ([
            'wp_handle_sideload',
            'wp_insert_attachment',
            'set_post_thumbnail',
            'has_post_thumbnail',
            'source_attachment_ids',
            'SourceMaterial',
            'promote',
            'sideload',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                sprintf(
                    'WordPressPublicationStore::publish() may not contain "%s": promotion is an '
                    . 'explicit human decision (ADR 0025), and publish() is where an automatic one '
                    . 'would be written by accident.',
                    $forbidden
                )
            );
        }
    }

    /**
     * The structural half of the guarantee above: the publication store is not
     * even handed a collaborator that could copy a file.
     */
    public function testThePublicationStoreIsNotEvenWiredToOne(): void
    {
        $source = (string) file_get_contents(
        __DIR__ . '/../../../../src/WordPress/Publishing/WordPressPublicationStore.php'
        );
        $signature = $this->constructorSignature($source);

        self::assertNotNull($signature, 'the constructor should be readable from the source');

        foreach ([
            'SourceMaterialStoreInterface',
            'MediaLibraryGatewayInterface',
            'IntakeAttachmentReaderInterface',
            'WordPressMediaLibraryGateway',
            'WordPressSourceMaterialStore',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $signature,
                sprintf('the publication store must not accept a %s', $forbidden)
            );
        }
    }

    /**
     * The constructor's parameter list, as source text.
     */
    private function constructorSignature(string $source): ?string
    {
        if (preg_match('/__construct\s*\((.*?)\)\s*\{/s', $source, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * A promotion is filesystem work. `WordPressPublicationStore::publish()`
     * wraps its work in a transaction, and a rollback cannot undo a file, so the
     * adapter is never constructed with a database handle that it could join a
     * transaction through.
     */
    public function testTheAdapterHoldsNoDatabaseHandleToJoinATransactionThrough(): void
    {
        $source = (string) file_get_contents(
        __DIR__ . '/../../../../src/WordPress/Publishing/WordPressSourceMaterialStore.php'
        );

        foreach (['START TRANSACTION', 'BEGIN', 'COMMIT', 'ROLLBACK', 'wpdb', 'DatabaseConnectionInterface'] as $transaction) {
            self::assertStringNotContainsString($transaction, $source);
            }
        }

    private function sideloadedName(int $index): string
    {
        return basename($this->sideloadedPath($index));
        }

    /**
         * Where the copy handed to the sideload actually landed on disk.
         *
         * The stub reports it as `<uploads dir>/<name>`, so this is the same path
         * `wp_delete_file()` is given when a promotion is rolled back, and the test
         * can assert on the same file by name rather than by pattern.
         */
    private function sideloadedPath(int $index): string
    {
        $sideload = $GLOBALS['adct_test_sideloads'][$index] ?? null;

        self::assertIsArray($sideload, sprintf('sideload %d was never attempted', $index));

        return rtrim($this->uploadsDir, '/') . '/' . (string) ($sideload['file']['name'] ?? '');
        }

    /**
     * @param array<int, array<string, mixed>> $attachments
     */
    private function store(
    array $attachments,
    ?RecordingAuditWriter $audit = null,
    string $actor = 'dean@example.test',
    bool $fileExists = true,
    bool $emptyFile = false,
    bool $badPath = false
    ): WordPressSourceMaterialStore {
        $GLOBALS['adct_test_empty_file'] = $emptyFile;

                // The intake file really exists, because a promotion really copies
                // it. A fake that only answered a path would make every happy-path
                // test fail for the wrong reason, and the assertions about what was
                // written would be worthless.
        if ($fileExists) {
            foreach ($attachments as $row) {
                        $stored = (string) ($row['storage_path'] ?? '');
                        if ($stored !== '') {
                            file_put_contents($this->intakeDir . '/' . $stored, 'adct-test-intake');
                        }
                    }
            }

        return new WordPressSourceMaterialStore(
        new RecordingAttachmentRepository($attachments),
        new FakeInboundMailStorage($this->intakeDir, $fileExists, $badPath),
        new WordPressMediaLibraryGateway(),
        new WordPressEventMeta(),
        new FakeActorResolver($actor),
        $audit ?? new RecordingAuditWriter(),
        );
        }

        /**
         * @return array<string, mixed>
         */
    private function row(int $id, string $filename, string $mimeType, string $extension = 'jpg'): array
    {
        $storedName = str_repeat('a', 64) . '.' . $extension;

        return [
            'id' => $id,
            'message_id' => 3,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => 5120,
            'storage_path' => $storedName,
            'status' => 'stored',
        ];
    }
}

final class RecordingAuditWriter implements AuditWriter
{
    /**
     * @var list<array{actor: string, action: AuditAction, subjectType: string, subjectId: int, details: array<string, mixed>}>
     */
public array $rows = [];

public function write(
string $actor,
AuditAction $action,
string $subjectType,
int $subjectId,
array $details
): int {
    $this->rows[] = [
    'actor' => $actor,
    'action' => $action,
    'subjectType' => $subjectType,
    'subjectId' => $subjectId,
    'details' => $details,
    ];

    return count($this->rows);
    }
}

final class FakeActorResolver implements ActorResolver
{
public function __construct(private readonly string $actor)
{
    }

public function actor(): string
{
    return $this->actor;
    }
}

final class RecordingAttachmentRepository implements IntakeAttachmentReaderInterface
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
         * Answers the question the *admin* screens ask, not the one the store asks.
         * The store must never look at what is on offer, so this double serves the
         * rows it was given: if a promotion ever started consulting it, the tests
         * below would see the difference.
         *
         * @return list<array{id: int, filename: string, mime_type: string, size_bytes: int, storage_path: string}>
         */
        public function findPromotableForMessage(int $messageId): array
        {
            $found = [];
            foreach ($this->rows as $row) {
                if ((int) ($row['message_id'] ?? 0) !== $messageId) {
                    continue;
                }

                $found[] = [
                    'id' => (int) $row['id'],
                    'filename' => (string) $row['filename'],
                    'mime_type' => (string) $row['mime_type'],
                    'size_bytes' => (int) $row['size_bytes'],
                    'storage_path' => (string) $row['storage_path'],
                ];
            }

            return $found;
        }
    }

/**
 * The intake attachment store, for real. The bytes have to exist, because
 * "the stored file is gone" is one of the failures the adapter has to survive,
 * and a stub that always answers would take that case away.
 */
final class FakeInboundMailStorage implements InboundMailStorageReaderInterface
{
private const NAME_PATTERN = '/\A[a-f0-9]{64}\.[a-z0-9]+\z/';

public function __construct(
private readonly string $intakeDir,
private readonly bool $fileExists = true,
private readonly bool $badPath = false
) {
    }

public function storeRawMessage(string $content): string
{
    return str_repeat('b', 64) . '.eml';
    }

public function storeAttachment(string $content, string $extension): string
{
    return str_repeat('c', 64) . '.' . $extension;
    }

public function delete(string $relativePath): void
{
    }

public function readRawMessage(string $relativePath): string
{
    return '';
    }

public function resolveAttachmentPath(string $relativePath): string
{
    if (preg_match(self::NAME_PATTERN, $relativePath) !== 1) {
        throw new InvalidArgumentException('Refusing to resolve ' . $relativePath . '.');
        }

    $path = $this->intakeDir . '/' . $relativePath;

    if (! $this->fileExists || ! is_file($path)) {
        throw new RuntimeException('No stored attachment at ' . $relativePath . '.');
        }

    if ($this->badPath) {
        return $path . '/not-a-file';
        }

    return $path;
    }
}
