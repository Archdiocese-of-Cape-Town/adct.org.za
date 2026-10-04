<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../../Support/WordPressStubs.php';
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Attachments {

    use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
    use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
    use ADCT\ParishIntake\WordPress\Attachments\WordPressSourceMaterialStore;
    use PHPUnit\Framework\TestCase;

    /**
     * The WordPress side of an event's ordered promotion meta (issue #172).
     *
     * Two things are being pinned here, and both are about what the store
     * refuses to do rather than what it does:
     *
     *  - **An event with no stored value reads as nothing promoted.** There is no
     *    default and no fallback to the featured image. A default is how an
     *    unapproved file becomes public by accident, which is the exact failure
     *    the whole issue exists to prevent.
     *  - **Clearing the list deletes the meta key** rather than writing an empty
     *    array, so no later reader can find a stored value and mistake it for
     *    "promoted, but the entry went missing".
     *
     * The stubs are shared (`tests/Support/WordPressStubs.php`), driven through
     * `$GLOBALS['adct_test_media_meta']`, so these tests are checking this class's
     * decisions and not the stub's.
     */
    final class WordPressSourceMaterialStoreTest extends TestCase
    {
        private const EVENT_ID = 42;

        protected function setUp(): void
        {
            $GLOBALS['adct_test_media_meta'] = [];
            $GLOBALS['adct_test_meta_writes'] = [];
            $GLOBALS['adct_test_meta_deletes'] = [];
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['adct_test_media_meta'],
                $GLOBALS['adct_test_meta_writes'],
                $GLOBALS['adct_test_meta_deletes']
            );
        }

        public function testAnEventWithNoStoredValueHasNoSourceMaterial(): void
        {
            $store = new WordPressSourceMaterialStore();

            self::assertSame([], $store->forEvent(self::EVENT_ID));
        }

        public function testAnUnrelatedPostMetaRowIsNotMistakenForSourceMaterial(): void
        {
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID] = ['parish_id' => '7', 'venue_id' => '9'];

            $store = new WordPressSourceMaterialStore();

            self::assertSame([], $store->forEvent(self::EVENT_ID));
        }

        public function testThePromotedListIsReadBackInStoredOrder(): void
        {
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID][WordPressSourceMaterialStore::META_KEY] = [
                ['attachment_id' => 12, 'role' => SourceMaterialRole::POSTER, 'name' => 'poster.jpg'],
                ['attachment_id' => 13, 'role' => SourceMaterialRole::BULLETIN, 'name' => 'bulletin.pdf'],
            ];

            $store = new WordPressSourceMaterialStore();
            $references = $store->forEvent(self::EVENT_ID);

            self::assertCount(2, $references);
            self::assertSame(12, $references[0]->attachmentId);
            self::assertTrue($references[0]->isPoster());
            self::assertSame('poster.jpg', $references[0]->originalName);
            self::assertSame(SourceMaterialRole::BULLETIN, $references[1]->role);
        }

        public function testTheMetaKeyIsTheContractWithTheFrontEnd(): void
        {
            // Pinned literally. PublicEventPage and PublicEventListing read
            // through this key, so a rename here that kept every test green
            // would still blank every published event's source section.
            self::assertSame('source_attachment_ids', WordPressSourceMaterialStore::META_KEY);
        }

        public function testWritingAReferenceStoresTheIdRoleAndParishFilename(): void
        {
            $store = new WordPressSourceMaterialStore();

            $store->replaceForEvent(self::EVENT_ID, [
                new SourceMaterialReference(31, SourceMaterialRole::BULLETIN, 'June bulletin.pdf'),
            ]);

            $writes = $GLOBALS['adct_test_meta_writes'];

            self::assertCount(1, $writes);
            self::assertSame(self::EVENT_ID, $writes[0][0]);
            self::assertSame(WordPressSourceMaterialStore::META_KEY, $writes[0][1]);
            self::assertSame(
                [['attachment_id' => 31, 'role' => SourceMaterialRole::BULLETIN, 'name' => 'June bulletin.pdf']],
                $writes[0][2]
            );
        }

        public function testWhatWasWrittenIsWhatIsReadBack(): void
        {
            $store = new WordPressSourceMaterialStore();

            $store->replaceForEvent(self::EVENT_ID, [
                new SourceMaterialReference(31, SourceMaterialRole::POSTER, 'poster.png'),
                new SourceMaterialReference(32, SourceMaterialRole::BULLETIN, 'bulletin.pdf'),
            ]);

            $readBack = $store->forEvent(self::EVENT_ID);

            self::assertSame(
                [31, 32],
                array_map(static fn (SourceMaterialReference $r): int => $r->attachmentId, $readBack)
            );
            self::assertSame(
                [SourceMaterialRole::POSTER, SourceMaterialRole::BULLETIN],
                array_map(static fn (SourceMaterialReference $r): string => $r->role, $readBack)
            );
        }

        public function testClearingTheListDeletesTheMetaKeyRatherThanStoringAnEmptyOne(): void
        {
            $store = new WordPressSourceMaterialStore();

            $store->replaceForEvent(self::EVENT_ID, []);

            self::assertSame([], $GLOBALS['adct_test_meta_writes'], 'An empty list must not be written at all.');
            self::assertCount(1, $GLOBALS['adct_test_meta_deletes']);
            self::assertSame(self::EVENT_ID, $GLOBALS['adct_test_meta_deletes'][0][0]);
            self::assertSame(WordPressSourceMaterialStore::META_KEY, $GLOBALS['adct_test_meta_deletes'][0][1]);
        }

        public function testAnEmptyStoredValueReadsAsNothingPromoted(): void
        {
            // The state a hand-edited or partially-migrated row can be in. It
            // must read as "nothing promoted", not as an error.
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID][WordPressSourceMaterialStore::META_KEY] = [];

            $store = new WordPressSourceMaterialStore();

            self::assertSame([], $store->forEvent(self::EVENT_ID));
        }

        public function testAnEventIdOfZeroIsRefusedRatherThanReadingSomeOtherPostsMeta(): void
        {
            // Guards the "" and negative ids absint() can produce from a crafted
            // request. WordPress would read post 0 as "no post", but a lookup on a
            // falsy id is never worth making.
            $GLOBALS['adct_test_media_meta'][0][WordPressSourceMaterialStore::META_KEY] = [
                ['attachment_id' => 99, 'role' => SourceMaterialRole::DOCUMENT, 'name' => 'stray.pdf'],
            ];

            $store = new WordPressSourceMaterialStore();

            self::assertSame([], $store->forEvent(0));
        }

        public function testWritingToAnEventIdOfZeroIsSilentlyDropped(): void
        {
            $store = new WordPressSourceMaterialStore();

            $store->replaceForEvent(0, [new SourceMaterialReference(31, SourceMaterialRole::DOCUMENT)]);

            self::assertSame([], $GLOBALS['adct_test_meta_writes']);
            self::assertSame([], $GLOBALS['adct_test_meta_deletes']);
        }

        public function testTwoReadsInOneRequestSeeTheLatestWriteRatherThanACachedFirst(): void
        {
            // The store deliberately keeps no static cache. A cache would make
            // two promotions in one request (the promote-then-promote case the
            // review queue allows) read each other's stale value, and the second
            // write would silently discard the first.
            $store = new WordPressSourceMaterialStore();

            $store->replaceForEvent(self::EVENT_ID, [
                new SourceMaterialReference(31, SourceMaterialRole::BULLETIN, 'first.pdf'),
            ]);
            $first = $store->forEvent(self::EVENT_ID);

            $store->replaceForEvent(self::EVENT_ID, [
                new SourceMaterialReference(31, SourceMaterialRole::BULLETIN, 'first.pdf'),
                new SourceMaterialReference(32, SourceMaterialRole::DOCUMENT, 'second.pdf'),
            ]);
            $second = $store->forEvent(self::EVENT_ID);

            self::assertCount(1, $first);
            self::assertCount(2, $second, 'The second read must see the second write.');
        }

        public function testACorruptEntryDoesNotHideTheUsableOnes(): void
        {
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID][WordPressSourceMaterialStore::META_KEY] = [
                ['attachment_id' => 0, 'role' => SourceMaterialRole::POSTER, 'name' => 'broken.pdf'],
                'not-an-entry',
                ['attachment_id' => 33, 'role' => 'no-such-role', 'name' => 'bad-role.pdf'],
                ['attachment_id' => 34, 'role' => SourceMaterialRole::BULLETIN, 'name' => 'good.pdf'],
            ];

            $store = new WordPressSourceMaterialStore();
            $references = $store->forEvent(self::EVENT_ID);

            self::assertCount(1, $references);
            self::assertSame(34, $references[0]->attachmentId);
        }

        public function testAContainerValueIsNotMistakenForAListOfReferences(): void
        {
            // A map rather than a list. Reading it as one would let a hand-edited
            // row produce references out of nowhere.
            $GLOBALS['adct_test_media_meta'][self::EVENT_ID][WordPressSourceMaterialStore::META_KEY] = [
                'first' => ['attachment_id' => 40, 'role' => SourceMaterialRole::POSTER],
            ];

            $store = new WordPressSourceMaterialStore();

            self::assertSame([], $store->forEvent(self::EVENT_ID));
        }
    }
}