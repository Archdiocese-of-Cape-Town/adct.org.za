<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';

    // The copier require_once's three wp-admin includes. This test does not
    // exercise WordPress's own file handling — the stubs in
    // tests/Support/WordPressStubs.php stand in for that — so the includes only
    // have to resolve to files that exist. An empty directory with three empty
    // files is enough, and it keeps the test from depending on a WordPress
    // checkout being present.
    if (! defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/adct-test-abspath/');
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Attachments {

    use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
    use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
    use ADCT\ParishIntake\WordPress\Attachments\WordPressSourceMaterialCopier;
    use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    /**
     * Copying a stored intake attachment into the media library (issue #172).
     *
     * This is the one class in the feature that touches a real filesystem, so
     * these tests use real temporary directories and the real
     * ProtectedInboundMailStorage rather than a fake: the properties being pinned
     * are properties about files — "nothing partial is left behind", "the
     * parish's filename never reaches the path" — and a fake storage that
     * returned a string would assert nothing about either.
     *
     * The media-library functions are the shared stubs, so what is asserted here
     * is this copier's decisions: which extension it derives, which name it
     * generates, what it passes to `wp_insert_attachment()`, and what it removes
     * when a step fails.
     */
    final class WordPressSourceMaterialCopierTest extends TestCase
    {
        private const EVENT_ID = 42;

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
                        // wp_update_post() appends to a global shared with every other test in
                        // this process, so it is seeded here rather than read later. Without
                        // the seed the array is undefined and `?? []` would quietly turn "the
                        // stub recorded an update" into "nothing was recorded".
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
                                $GLOBALS['adct_test_post_updates']
                            );
        }

        public function testAPromotedPdfLandsInTheUploadsDirectoryAsAnAttachmentUnderTheEvent(): void
        {
            $copier = $this->copier();
            $storageName = $this->store('pdf', "%PDF-1.4 bulletin");

            $attachmentId = $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $storageName,
                'Parish bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );

            self::assertSame(900, $attachmentId);

            $files = $this->filesInUploads();
            self::assertCount(1, $files, 'Exactly one file must land in the uploads directory.');
                        self::assertSame('pdf', pathinfo($files[0], PATHINFO_EXTENSION));
            self::assertSame('%PDF-1.4 bulletin', (string) file_get_contents($files[0]));
        }

        public function testTheStoredFileNameIsGeneratedAndCarriesNoPartOfTheParishsFilename(): void
        {
            // The parish's filename is attacker-controlled and the uploads
            // directory is world-readable, so not one character of it may reach
            // the path. This is the assertion that would fail if the copier ever
            // "helpfully" reused the name.
            $copier = $this->copier();
            $storageName = $this->store('pdf', 'content');

            $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $storageName,
                '../../evil.php?x=1.png bulletin',
                SourceMaterialRole::BULLETIN
            );

            $files = $this->filesInUploads();
            $generated = basename($files[0]);

            self::assertMatchesRegularExpression(
                '/\A[a-f0-9]{32}\.pdf\z/D',
                $generated,
                'The stored name must be a random hex token plus the intake extension, nothing else.'
            );
            self::assertStringNotContainsString('evil', $generated);
            self::assertStringNotContainsString('bulletin', $generated);
        }

        public function testTwoPromotionsGetTwoDifferentFileNames(): void
        {
            // If the name were derived from the event or a counter, a second
            // promotion in the same request would overwrite the first file.
            $copier = $this->copier();

            $copier->copyIntoMediaLibrary(self::EVENT_ID, $this->store('pdf', 'one'), 'a.pdf', SourceMaterialRole::BULLETIN);
            $copier->copyIntoMediaLibrary(self::EVENT_ID, $this->store('pdf', 'two'), 'b.pdf', SourceMaterialRole::BULLETIN);

            $files = $this->filesInUploads();

            self::assertCount(2, $files);
            self::assertNotSame(basename($files[0]), basename($files[1]));
        }

        public function testTheAttachmentIsParentedToTheEvent(): void
        {
            // post_parent is what puts the copy under "Uploaded to: <event>" in
            // the media library, and what the event's media picker narrows by.
            $copier = $this->copier();

            $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('pdf', 'content'),
                'bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );

            self::assertCount(1, $GLOBALS['adct_test_attachment_inserts']);
            $insert = $GLOBALS['adct_test_attachment_inserts'][0];

            self::assertSame(self::EVENT_ID, $insert['args']['post_parent']);
            self::assertSame(self::EVENT_ID, $insert['parent']);
            self::assertSame('inherit', $insert['args']['post_status']);
            self::assertTrue($insert['wp_error'], 'A failed insert must be requested as a WP_Error.');
        }

        public function testTheSideloadIsGivenTheEventAsItsParentToo(): void
        {
            $copier = $this->copier();

            $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('pdf', 'content'),
                'bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );

            self::assertSame(self::EVENT_ID, $GLOBALS['adct_test_sideloads'][0]['overrides']['post_parent']);
        }

        public function testTheParishsFilenameIsKeptAsMetaAndSanitisedOnTheWayIn(): void
        {
            $copier = $this->copier();

            $attachmentId = $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('pdf', 'content'),
                '  <b>June</b> bulletin.pdf  ',
                SourceMaterialRole::BULLETIN
            );

            self::assertSame(
                'June bulletin.pdf',
                $GLOBALS['adct_test_media_meta'][$attachmentId]['adct_pi_source_name'] ?? null
            );
        }

        public function testTheFilenameIsStoredAsMetaRatherThanInsideTheAttachmentMetadata(): void
        {
            // wp_update_attachment_metadata() replaces the whole array, so a name
            // kept in there would vanish on the next image resize. A meta row
            // survives.
            $copier = $this->copier();

            $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('jpg', 'image-bytes'),
                'poster.jpg',
                SourceMaterialRole::POSTER
            );

            $attachmentId = 900;

            self::assertArrayHasKey('adct_pi_source_name', $GLOBALS['adct_test_media_meta'][$attachmentId]);
            self::assertArrayNotHasKey(
                'adct_pi_source_name',
                $GLOBALS['adct_test_metadata_written'][$attachmentId]
            );
        }

        public function testTheMediaLibraryLabelIsTheParishsBasenameWithoutItsExtension(): void
        {
            $copier = $this->copier();

            $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('pdf', 'content'),
                'C:\\Users\\parish\\Documents\\June bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );

            self::assertSame('June bulletin', $GLOBALS['adct_test_attachment_inserts'][0]['args']['post_title']);
        }

        public function testAPosterGetsSizeMetadataAndABulletinDoesNot(): void
        {
            // Generating metadata for a 2 MB PDF would spend PHP time from a
            // 90 s budget for nothing.
            $copier = $this->copier();

            $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('jpg', 'image-bytes'),
                'poster.jpg',
                SourceMaterialRole::POSTER
            );
            self::assertSame([900], $GLOBALS['adct_test_metadata_generated']);

            $GLOBALS['adct_test_metadata_generated'] = [];

            $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('pdf', 'content'),
                'bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );
            self::assertSame([], $GLOBALS['adct_test_metadata_generated']);
        }

        public function testAHeicPosterIsRefusedBecauseTheOutOfScopeListNamesIt(): void
        {
            // HEIC/HEIF pass the *intake* allowlist and are explicitly out of
            // scope for promotion, so they must be refused here rather than
            // published as a dead link.
            $copier = $this->copier();
            $storageName = $this->store('heic', 'heic-bytes');

            $this->expectException(RuntimeException::class);

            $copier->copyIntoMediaLibrary(self::EVENT_ID, $storageName, 'poster.heic', SourceMaterialRole::POSTER);
        }

        public function testARefusedPromotionLeavesNothingInTheUploadsDirectory(): void
        {
            $copier = $this->copier();

            try {
                $copier->copyIntoMediaLibrary(
                    self::EVENT_ID,
                    $this->store('heic', 'heic-bytes'),
                    'poster.heic',
                    SourceMaterialRole::POSTER
                );
            } catch (RuntimeException) {
                // expected
            }

            self::assertSame([], $this->filesInUploads());
        }

        public function testAPdfCannotBePromotedAsAPoster(): void
        {
            // The role decides what the public page will *say* the file is. A
            // bulletin published as a poster would render a PDF inside an <img>.
            $copier = $this->copier();

            $this->expectException(RuntimeException::class);

            $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('pdf', 'content'),
                'bulletin.pdf',
                SourceMaterialRole::POSTER
            );
        }

        public function testTheStoredExtensionIsTakenFromTheIntakeNameNotTheParishsFilename(): void
        {
            // A parish calling a JPEG "poster.pdf" changes nothing: the allowlist
                        // is checked against what the intake store actually wrote, and the
                        // media library is told the stored type, not the one the name claims.
                        $copier = $this->copier();
                        $storageName = $this->store('jpg', 'image-bytes');

                        $attachmentId = $copier->copyIntoMediaLibrary(
                            self::EVENT_ID,
                            $storageName,
                            'poster.pdf',
                            SourceMaterialRole::POSTER
                        );

                        $files = $this->filesInUploads();

                        self::assertSame(900, $attachmentId);
                        self::assertSame('jpg', pathinfo($files[0], PATHINFO_EXTENSION));
                        self::assertSame('image/jpeg', $GLOBALS['adct_test_attachment_inserts'][0]['args']['post_mime_type']);
        }

        public function testAMissingStoredFileIsReportedRatherThanPublishingAnEmptyAttachment(): void
        {
            $copier = $this->copier();
            $storageName = $this->store('pdf', 'content');
            unlink($this->privateDirectory . DIRECTORY_SEPARATOR . $storageName);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The stored source file is no longer available.');

            $copier->copyIntoMediaLibrary(self::EVENT_ID, $storageName, 'bulletin.pdf', SourceMaterialRole::BULLETIN);
        }

        public function testAFileNameThatIsNotAStoredAttachmentIsRefused(): void
        {
            // The copier must not be a way to read an arbitrary path, even if the
                    // storage implementation ever stopped pattern-checking. The refusal
                    // comes from the allowlist, which runs before the file is even looked
                    // for — `../../wp-config.php` has no promotable extension.
                    $copier = $this->copier();

                    $this->expectException(RuntimeException::class);
                    $this->expectExceptionMessage('cannot be published');

                    $copier->copyIntoMediaLibrary(
                        self::EVENT_ID,
                        '../../wp-config.php',
                        'config.php',
                        SourceMaterialRole::DOCUMENT
                    );
                }

                public function testAStoredNameThatHasBeenSwappedForALinkIsRefused(): void
                {
                    // Path escape is prevented by the storage's own resolve: it refuses a
                    // symlink outright, so a name that pointed out of the private
                    // directory cannot be copied. Driven through the real storage, so this
                    // is the real check rather than a restatement of it.
                    $copier = $this->copier();
                    $storageName = $this->store('pdf', 'content');
                    $path = $this->privateDirectory . DIRECTORY_SEPARATOR . $storageName;

                    unlink($path);
                    symlink($this->store('pdf', 'somewhere else'), $path);

                    try {
                        $copier->copyIntoMediaLibrary(
                            self::EVENT_ID,
                            $storageName,
                            'bulletin.pdf',
                            SourceMaterialRole::BULLETIN
                        );
                        self::fail('Expected the storage to refuse a symlinked attachment.');
                    } catch (RuntimeException $failure) {
                        self::assertSame('The stored source file is no longer available.', $failure->getMessage());
                    }

                    self::assertSame([], $this->filesInUploads());
                }

                public function testAStoredNameOutsideTheFiftyFourCharacterPatternIsRefused(): void
                {
                    $copier = $this->copier();
                    $escaping = $this->privateDirectory . DIRECTORY_SEPARATOR . 'bulletin.pdf';
                    file_put_contents($escaping, 'content');

                    try {
                        $copier->copyIntoMediaLibrary(
                            self::EVENT_ID,
                            'bulletin.pdf',
                            'bulletin.pdf',
                            SourceMaterialRole::BULLETIN
                        );
                        self::fail('Expected the storage to refuse an unstored file name.');
                    } catch (RuntimeException $failure) {
                        self::assertSame('The stored source file is no longer available.', $failure->getMessage());
                    }

                    self::assertFileExists($escaping, 'The copy must be refused without touching the other file.');
                    self::assertSame([], $this->filesInUploads());
                }

        public function testAnEventIdOfZeroIsRefusedBeforeAnyFileIsTouched(): void
        {
            $copier = $this->copier();

            try {
                $copier->copyIntoMediaLibrary(0, $this->store('pdf', 'content'), 'a.pdf', SourceMaterialRole::BULLETIN);
                self::fail('Expected the copier to refuse an event id of zero.');
            } catch (RuntimeException $failure) {
                self::assertStringContainsString('event', $failure->getMessage());
            }

            self::assertSame([], $this->filesInUploads());
        }

        public function testAFailedSideloadLeavesNoFileBehind(): void
        {
            $copier = $this->copier();
            $GLOBALS['adct_test_media']['sideload_error'] = 'Specified file failed upload test.';

            try {
                $copier->copyIntoMediaLibrary(
                    self::EVENT_ID,
                    $this->store('pdf', 'content'),
                    'bulletin.pdf',
                    SourceMaterialRole::BULLETIN
                );
                self::fail('Expected the copier to refuse a failed sideload.');
            } catch (RuntimeException) {
                // expected
            }

            self::assertSame([], $this->filesInUploads());
        }

        public function testAFailedAttachmentInsertRemovesTheFileSideloadAlreadyMoved(): void
        {
            // The rollback that matters most: sideload() has already put the file
            // in the world-readable uploads directory by this point, and an
            // insert that then fails must not leave it there.
            $copier = $this->copier();
            $GLOBALS['adct_test_media']['insert'] = 0;

            try {
                $copier->copyIntoMediaLibrary(
                    self::EVENT_ID,
                    $this->store('pdf', 'content'),
                    'bulletin.pdf',
                    SourceMaterialRole::BULLETIN
                );
                self::fail('Expected the copier to refuse an attachment id of zero.');
            } catch (RuntimeException) {
                // expected
            }

            self::assertSame([], $this->filesInUploads(), 'No file may survive a failed insert.');
        }

        public function testAFailedAttachmentInsertThatMovedTheFileUnderANewNameStillCleansUp(): void
        {
            // Real sideload() renames on collision, so the file on disk is not
            // the one that was offered. Rolling back only the offered path would
            // leave a file nobody chose to publish.
            $copier = $this->copier();
            $GLOBALS['adct_test_media']['insert'] = 0;
            $GLOBALS['adct_test_media']['sideload_name'] = 'collided-' . bin2hex(random_bytes(4)) . '.pdf';

            try {
                $copier->copyIntoMediaLibrary(
                    self::EVENT_ID,
                    $this->store('pdf', 'content'),
                    'bulletin.pdf',
                    SourceMaterialRole::BULLETIN
                );
                self::fail('Expected the copier to refuse an attachment id of zero.');
            } catch (RuntimeException) {
                // expected
            }

            self::assertSame([], $this->filesInUploads());
        }

        public function testAFailureAfterTheRowExistsDeletesBothTheRowAndItsFile(): void
        {
            // wp_generate_attachment_metadata() throwing is the realistic way to
            // fail this late. The attachment row exists, so the rollback has to
            // use wp_delete_attachment(…, true) rather than only unlinking.
            $copier = $this->copier();
            $GLOBALS['adct_test_media']['metadata_throws'] = true;

            try {
                $copier->copyIntoMediaLibrary(
                    self::EVENT_ID,
                    $this->store('jpg', 'image-bytes'),
                    'poster.jpg',
                    SourceMaterialRole::POSTER
                );
                self::fail('Expected the copier to report the metadata failure.');
            } catch (RuntimeException) {
                // expected
            }

            self::assertSame([], $this->filesInUploads(), 'The moved file must not survive.');
            self::assertSame([[900, true]], $GLOBALS['adct_test_attachment_deletes']);
            self::assertSame([], $GLOBALS['adct_test_media_posts'], 'The partial row must not survive.');
        }

        public function testAFailureIsReportedAsAPlainRuntimeExceptionRatherThanLeakingTheOriginal(): void
        {
            // The caller is an admin screen. A raw "Image resizing failed." from
                    // deep inside WordPress would be shown to a parish secretary. The
                    // plugin's own refusals are shown as written — they say something
                    // useful — so this pins the wrapping to the WordPress failure alone.
                    $copier = $this->copier();
                    $GLOBALS['adct_test_media']['metadata_throws'] = true;

                    try {
                        $copier->copyIntoMediaLibrary(
                            self::EVENT_ID,
                            $this->store('jpg', 'image-bytes'),
                            'poster.jpg',
                            SourceMaterialRole::POSTER
                        );
                        self::fail('Expected a RuntimeException.');
                    } catch (RuntimeException $failure) {
                        self::assertSame(
                            'The source material could not be published.',
                            $failure->getMessage(),
                            'The screen must never be shown a WordPress-internal message.'
                        );
                        self::assertSame('Image resizing failed.', $failure->getPrevious()?->getMessage());
                    }
                }

                public function testAPromotionsOwnRefusalIsShownAsWritten(): void
                {
                    // The opposite case: "That file cannot be published as source
                    // material." tells the secretary exactly what to fix, so wrapping it
                    // in a generic message would lose the only useful sentence there is.
                    $copier = $this->copier();

                    try {
                        $copier->copyIntoMediaLibrary(
                            self::EVENT_ID,
                            $this->store('heic', 'heic-bytes'),
                            'poster.heic',
                            SourceMaterialRole::POSTER
                        );
                        self::fail('Expected a RuntimeException.');
                    } catch (RuntimeException $failure) {
                        self::assertSame('That file cannot be published as source material.', $failure->getMessage());
                        self::assertNull($failure->getPrevious());
                    }
                }

        public function testThePrivateOriginalIsNeverMovedOrDeletedByAPromotion(): void
        {
            // The raw file is the evidence the retention settings own. Promoting
            // copies it; it must still be there afterwards.
            $copier = $this->copier();
            $storageName = $this->store('pdf', 'the original bytes');

            $copier->copyIntoMediaLibrary(self::EVENT_ID, $storageName, 'bulletin.pdf', SourceMaterialRole::BULLETIN);

            self::assertFileExists($this->privateDirectory . DIRECTORY_SEPARATOR . $storageName);
            self::assertSame(
                'the original bytes',
                (string) file_get_contents($this->privateDirectory . DIRECTORY_SEPARATOR . $storageName)
            );
        }

        public function testAnUnwritableUploadsDirectoryIsReportedBeforeAnythingIsRead(): void
        {
            $copier = $this->copier();
            $GLOBALS['adct_test_media_uploads'] = ['error' => 'Unable to create directory.'];

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The uploads directory is not writable.');

            $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('pdf', 'content'),
                'bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );
        }

        public function testDetachingClearsTheParentAndLeavesTheFileAndTheRowAlone(): void
        {
            // Removal is a visibility change, not a deletion: the file and the
            // row stay, because the raw file is evidence and the retention
            // settings own it.
            $copier = $this->copier();
            $storageName = $this->store('pdf', 'content');
            $attachmentId = $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $storageName,
                'bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );

            self::assertTrue($copier->detachFromEvent(self::EVENT_ID, $attachmentId));

            self::assertSame(0, $GLOBALS['adct_test_media_posts'][$attachmentId]->post_parent);
            self::assertCount(1, $this->filesInUploads());
            self::assertSame([], $GLOBALS['adct_test_attachment_deletes'], 'Detaching must not delete anything.');
        }

        public function testDetachingReleasesTheFeaturedImageOnlyWhenItIsThisAttachment(): void
        {
            // Removing an unrelated bulletin must not strip the poster.
                        //
                        // Two distinct attachments, both parented to the event: 77 is the
                        // bulletin being removed, 88 is the poster that is the featured
                        // image and must survive. Both used to be 77, which made the
                        // assertion pass for the wrong reason - releasing the thumbnail
                        // would then have been correct, not a bug.
                        //
                        // The ownership check matters too. At first attachment 77 had no
                        // parent, so `detachFromEvent()` returned false at the guard and the
                        // conditional on the release was never reached; the mutation probe
                        // showed it by replacing the equality check with `if (true)` and
                        // watching every test stay green.
                        $copier = $this->copier();

                        $GLOBALS['adct_test_media_posts'][77] = (object) [
                            'ID' => 77,
                            'post_parent' => self::EVENT_ID,
                        ];
                        $GLOBALS['adct_test_media_posts'][88] = (object) [
                            'ID' => 88,
                            'post_parent' => self::EVENT_ID,
                        ];
                        $GLOBALS['adct_test_media_thumbnails'][self::EVENT_ID] = 88;

                        self::assertTrue($copier->detachFromEvent(self::EVENT_ID, 77));
                        self::assertSame(0, $GLOBALS['adct_test_media_posts'][77]->post_parent);
                        self::assertSame(88, $GLOBALS['adct_test_media_thumbnails'][self::EVENT_ID]);
                    }

        public function testDetachingAnAttachmentThatIsTheFeaturedImageReleasesIt(): void
        {
            $copier = $this->copier();
            $attachmentId = $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('jpg', 'image-bytes'),
                'poster.jpg',
                SourceMaterialRole::POSTER
            );
            $GLOBALS['adct_test_media_thumbnails'][self::EVENT_ID] = $attachmentId;

            self::assertTrue($copier->detachFromEvent(self::EVENT_ID, $attachmentId));
            self::assertArrayNotHasKey(self::EVENT_ID, $GLOBALS['adct_test_media_thumbnails']);
        }

        public function testDetachingAnAttachmentParentedToADifferentEventFails(): void
        {
            // Guards the case where an id from the request names a file that is
            // someone else's.
            $copier = $this->copier();
            $attachmentId = $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('pdf', 'content'),
                'bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );

            self::assertFalse($copier->detachFromEvent(self::EVENT_ID + 1, $attachmentId));
            self::assertSame(self::EVENT_ID, $GLOBALS['adct_test_media_posts'][$attachmentId]->post_parent);
        }

        public function testDetachingAnUnknownAttachmentFailsRatherThanEditingSomethingElse(): void
        {
            $copier = $this->copier();

            self::assertFalse($copier->detachFromEvent(self::EVENT_ID, 12345));
                    self::assertSame([], $GLOBALS['adct_test_post_updates'], 'No row may be edited.');
        }

        public function testADetachRejectedByWordPressIsReportedAsAFailure(): void
        {
            $copier = $this->copier();
            $attachmentId = $copier->copyIntoMediaLibrary(
                self::EVENT_ID,
                $this->store('pdf', 'content'),
                'bulletin.pdf',
                SourceMaterialRole::BULLETIN
            );

            $GLOBALS['adct_test_media']['update_error'] = true;

            self::assertFalse($copier->detachFromEvent(self::EVENT_ID, $attachmentId));
        }

        public function testDetachingIdsOfZeroFailsWithoutTouchingWordPress(): void
        {
            $copier = $this->copier();

            self::assertFalse($copier->detachFromEvent(0, 1));
            self::assertFalse($copier->detachFromEvent(self::EVENT_ID, 0));
        }

        public function testSettingTheFeaturedImageToZeroClearsIt(): void
        {
            // How a role change from "poster" to something else stops the file
            // being the event's image, without deleting anything.
            $copier = $this->copier();
            $GLOBALS['adct_test_media_thumbnails'][self::EVENT_ID] = 5;

            self::assertTrue($copier->setFeaturedImage(self::EVENT_ID, 0));
            self::assertArrayNotHasKey(self::EVENT_ID, $GLOBALS['adct_test_media_thumbnails']);
        }

        public function testSettingTheFeaturedImageStoresTheAttachmentId(): void
        {
            $copier = $this->copier();

            self::assertTrue($copier->setFeaturedImage(self::EVENT_ID, 7));
            self::assertSame(7, $GLOBALS['adct_test_media_thumbnails'][self::EVENT_ID]);
        }

        public function testAnEmptyFileIsRefusedRatherThanPublishedAsAZeroByteAttachment(): void
        {
            $copier = $this->copier();
            $storageName = $this->store('pdf', '');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The source material file could not be read.');

            $copier->copyIntoMediaLibrary(self::EVENT_ID, $storageName, 'bulletin.pdf', SourceMaterialRole::BULLETIN);
        }

        private function copier(): WordPressSourceMaterialCopier
        {
            return new WordPressSourceMaterialCopier(
                new ProtectedInboundMailStorage($this->privateDirectory)
            );
        }

        /**
         * Writes a real file through the real storage, so the name the copier is
         * given is one the plugin itself would have generated.
         */
        private function store(string $extension, string $contents): string
        {
            $storage = new ProtectedInboundMailStorage($this->privateDirectory);

            return $storage->storeAttachment($contents, $extension);
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

        /**
                 * The private storage directory also holds the protection files it writes
                 * (.htaccess, index.html), and sideload collisions leave sub-folders, so
                 * the cleanup has to be recursive.
         */
                private function removeTree(string $directory): void
                {
                    if ($directory === '' || ! is_dir($directory)) {
                        return;
                    }

                    foreach ((array) glob($directory . '/{,.}[!.,..]*', GLOB_BRACE) as $entry) {
                        if (! is_string($entry) || is_dir($entry)) {
                            if (is_string($entry) && is_dir($entry)) {
                                $this->removeTree($entry);
                            }

                            continue;
                        }

                        @unlink($entry);
                    }

                    @rmdir($directory);
                }

                /**
                 * The copier's `require_once ABSPATH . 'wp-admin/includes/…'` lines must
                 * resolve. The stubs stand in for the functions those files would
                 * declare, so empty files are enough.
                 */
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
}