<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Attachments;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialPromotion;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\Core\Ports\SourceMaterialCopierInterface;
use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/RecordingSourceMaterialPorts.php';

final class SourceMaterialPromotionTest extends \PHPUnit\Framework\TestCase
{
    private RecordingSourceMaterialCopier $copier;

    private InMemorySourceMaterialStore $store;

    private SourceMaterialPromotion $promotion;

    protected function setUp(): void
    {
        $this->copier = new RecordingSourceMaterialCopier();
        $this->store = new InMemorySourceMaterialStore();
        $this->promotion = new SourceMaterialPromotion($this->copier, $this->store);
    }

    public function testAPromotionCopiesTheFileAndRecordsTheReference(): void
    {
        $reference = $this->promotion->promote(
            7,
            'abc123.jpg',
            'Poster.jpg',
            'image/jpeg',
            SourceMaterialRole::POSTER
        );

        self::assertSame(1000, $reference->attachmentId);
        self::assertSame('poster', $reference->role);
        self::assertSame('Poster.jpg', $reference->originalName);
        self::assertCount(1, $this->store->forEvent(7));
        self::assertSame(1000, $this->store->forEvent(7)[0]->attachmentId);
    }

    /**
     * The parish's name is attacker-controlled. It must reach the copier only
     * as metadata, never as the stored name that becomes a path.
     */
    public function testTheStoredNameIsTheIntakeNameAndTheParishNameIsMetadataOnly(): void
    {
        $this->promotion->promote(
            7,
            'abc123.jpg',
            '../../../etc/passwd.php',
            'image/jpeg',
            SourceMaterialRole::DOCUMENT
        );

        self::assertSame('abc123.jpg', $this->copier->copied[0]['storage']);
        self::assertSame('../../../etc/passwd.php', $this->copier->copied[0]['original']);
    }

    public function testAPosterIsSetAsTheFeaturedImage(): void
    {
        $this->promotion->promote(7, 'a.jpg', 'Poster.jpg', 'image/jpeg', SourceMaterialRole::POSTER);

        self::assertSame([['event' => 7, 'attachment' => 1000]], $this->copier->featured);
    }

    public function testADocumentOrBulletinIsNotSetAsTheFeaturedImage(): void
    {
        $this->promotion->promote(7, 'a.pdf', 'Bulletin.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);

        self::assertSame([], $this->copier->featured);
    }

    /**
     * The store's read/write is the only path that decides the public list.
     * If a promotion skipped it, nothing would appear on the page.
     */
    public function testAPromotionWritesThroughThePromotionStore(): void
    {
        $this->promotion->promote(7, 'a.pdf', 'Bulletin.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);

        self::assertSame(1, $this->store->replaceCalls);
    }

    public function testAnEventWithNoPromotionHasNoSourceMaterial(): void
    {
        self::assertSame([], $this->promotion->forEvent(7));
    }

    public function testNothingIsPromotedWithoutAnExplicitCall(): void
    {
        // Constructing the service and reading an event must not copy, store,
        // feature or detach anything at all.
        $this->promotion->forEvent(7);

        self::assertSame([], $this->copier->copied);
        self::assertSame([], $this->copier->featured);
        self::assertSame([], $this->copier->detached);
        self::assertSame(0, $this->store->replaceCalls);
    }

    public function testTheStoredOrderIsPreservedAcrossPromotions(): void
    {
        $this->promotion->promote(7, 'p.jpg', 'P.jpg', 'image/jpeg', SourceMaterialRole::POSTER);
        $this->promotion->promote(7, 'b.pdf', 'B.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);
        $this->promotion->promote(7, 'd.pdf', 'D.pdf', 'application/pdf', SourceMaterialRole::DOCUMENT);

        self::assertSame(
            [1000, 1001, 1002],
            array_map(
                static fn (SourceMaterialReference $r): int => $r->attachmentId,
                $this->promotion->forEvent(7)
            )
        );
    }

    /**
     * Two featured images would fight. The newcomer takes the poster role and
     * the featured image; the previous poster keeps its file and its slot, but
     * as a plain document.
     */
    public function testASecondPosterReplacesTheFirstPostsRoleAndGoesFirst(): void
    {
        $this->promotion->promote(7, 'p.jpg', 'P.jpg', 'image/jpeg', SourceMaterialRole::POSTER);
        $this->promotion->promote(7, 'p2.jpg', 'P2.jpg', 'image/png', SourceMaterialRole::POSTER);

        $references = $this->promotion->forEvent(7);

        self::assertSame(1001, $references[0]->attachmentId);
        self::assertSame('poster', $references[0]->role);
        self::assertSame(1000, $references[1]->attachmentId);
        self::assertSame('document', $references[1]->role);
    }

    public function testAnExistingListIsNotReshuffledByANewDocument(): void
    {
        $this->promotion->promote(7, 'p.jpg', 'P.jpg', 'image/jpeg', SourceMaterialRole::POSTER);
        $this->promotion->promote(7, 'b.pdf', 'B.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);
        $this->promotion->promote(7, 'd.pdf', 'D.pdf', 'application/pdf', SourceMaterialRole::DOCUMENT);

        self::assertSame(
            ['poster', 'bulletin', 'document'],
            array_map(
                static fn (SourceMaterialReference $r): string => $r->role,
                $this->promotion->forEvent(7)
            )
        );
    }

    /**
     * A failed copy must leave the previous list intact: nothing is written to
     * the store until the copy has succeeded.
     */
    public function testAFailedPromotionAddsNoPromotionRecord(): void
    {
        $this->promotion->promote(7, 'p.jpg', 'P.jpg', 'image/jpeg', SourceMaterialRole::POSTER);
        $this->store->replaceCalls = 0;

        $this->copier->copyFailure = new RuntimeException('The uploads directory is not writable.');

        try {
            $this->promotion->promote(7, 'b.pdf', 'B.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);
            self::fail('A failed copy must not be swallowed.');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(0, $this->store->replaceCalls);
        self::assertCount(1, $this->promotion->forEvent(7));
        self::assertSame(1000, $this->promotion->forEvent(7)[0]->attachmentId);
    }

    /**
     * A copier that reports success with a non-positive id is a broken
     * adapter, and must not be recorded as a promotion.
     */
    public function testACopierReturningNoAttachmentIdIsTreatedAsAFailure(): void
    {
        $brokenCopier = new class implements SourceMaterialCopierInterface {
            public function copyIntoMediaLibrary(int $e, string $s, string $o, string $r): int
            {
                return 0;
            }

            public function detachFromEvent(int $e, int $a): bool
            {
                return true;
            }

            public function setFeaturedImage(int $e, int $a): bool
            {
                return true;
            }
        };

        $promotion = new SourceMaterialPromotion($brokenCopier, $this->store);

        $this->expectException(RuntimeException::class);

        try {
            $promotion->promote(7, 'p.jpg', 'P.jpg', 'image/jpeg', SourceMaterialRole::POSTER);
        } finally {
            self::assertSame(0, $this->store->replaceCalls);
        }
    }

    public function testAPromotionRefusesAMimeTypeTheRoleDoesNotAccept(): void
    {
        try {
            $this->promotion->promote(7, 'p.pdf', 'Bulletin.pdf', 'application/pdf', SourceMaterialRole::POSTER);
            self::fail('A PDF is not a poster.');
        } catch (RuntimeException $thrown) {
            self::assertStringContainsString('cannot be promoted', $thrown->getMessage());
        }

        self::assertSame([], $this->copier->copied);
        self::assertSame(0, $this->store->replaceCalls);
    }

    public function testAPromotionRefusesAnUnrenderableImageType(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->promotion->promote(7, 'p.heic', 'Poster.heic', 'image/heic', SourceMaterialRole::POSTER);
        } finally {
            self::assertSame([], $this->copier->copied);
        }
    }

    public function testAPromotionRefusesAnUnknownRole(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->promotion->promote(7, 'p.jpg', 'Poster.jpg', 'image/jpeg', 'thumbnail');
        } finally {
            self::assertSame([], $this->copier->copied);
        }
    }

    public function testAPromotionRefusesANonPositiveEventId(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->promotion->promote(0, 'p.jpg', 'Poster.jpg', 'image/jpeg', SourceMaterialRole::POSTER);
        } finally {
            self::assertSame([], $this->copier->copied);
        }
    }

    public function testReadingSourceMaterialRefusesANonPositiveEventId(): void
    {
        $this->expectException(RuntimeException::class);

        $this->promotion->forEvent(0);
    }

    public function testRemovalDropsTheReferenceAndDetachesTheAttachment(): void
    {
        $this->promotion->promote(7, 'p.jpg', 'P.jpg', 'image/jpeg', SourceMaterialRole::POSTER);
        $this->promotion->promote(7, 'b.pdf', 'B.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);

        $this->promotion->remove(7, 1000);

        self::assertCount(1, $this->promotion->forEvent(7));
        self::assertSame(1001, $this->promotion->forEvent(7)[0]->attachmentId);
        self::assertSame([['event' => 7, 'attachment' => 1000]], $this->copier->detached);
    }

    /**
     * Removal is a visibility change, not a deletion. Nothing in the
     * promotion path is allowed to delete a file, so the port it calls has no
     * method that could.
     */
    public function testThePromotionPortExposesNoDeletion(): void
    {
        self::assertSame(
            ['copyIntoMediaLibrary', 'detachFromEvent', 'setFeaturedImage'],
            array_map(
                static fn (\ReflectionMethod $m): string => $m->getName(),
                (new \ReflectionClass(SourceMaterialCopierInterface::class))->getMethods()
            )
        );
    }

    public function testRemovingAnUnknownAttachmentLeavesTheListUnchanged(): void
    {
        $this->promotion->promote(7, 'p.jpg', 'P.jpg', 'image/jpeg', SourceMaterialRole::POSTER);

        $this->promotion->remove(7, 9999);

        self::assertCount(1, $this->promotion->forEvent(7));
    }

    public function testRemovingEveryItemLeavesAnEmptyList(): void
    {
        $this->promotion->promote(7, 'p.jpg', 'P.jpg', 'image/jpeg', SourceMaterialRole::POSTER);
        $this->promotion->promote(7, 'b.pdf', 'B.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);

        $this->promotion->remove(7, 1000);
        $this->promotion->remove(7, 1001);

        self::assertSame([], $this->promotion->forEvent(7));
    }

    public function testRePromotingAfterRemovalRestoresTheSourceMaterial(): void
    {
        $this->promotion->promote(7, 'p.jpg', 'P.jpg', 'image/jpeg', SourceMaterialRole::POSTER);
        $this->promotion->remove(7, 1000);

        // Re-promoting the same intake file makes a *fresh* media-library copy,
        // because removal never destroyed the original.
        $restored = $this->promotion->promote(
            7,
            'p.jpg',
            'P.jpg',
            'image/jpeg',
            SourceMaterialRole::POSTER
        );

        self::assertCount(1, $this->promotion->forEvent(7));
        self::assertSame($restored->attachmentId, $this->promotion->forEvent(7)[0]->attachmentId);
        self::assertCount(2, $this->copier->copied);
    }

    public function testRemovalRefusesNonPositiveIdentifiers(): void
    {
        $this->expectException(RuntimeException::class);

        $this->promotion->remove(0, 1000);
    }

    public function testRemovingAnAttachmentWithoutAnEventIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->promotion->remove(7, 0);
    }

    /**
     * A store that throws must not leave a half-applied promotion: the copy
     * happened, but the reference was never recorded, so the public side is
     * unchanged and the admin sees a failure.
     */
    public function testAFailingStorePropagates(): void
    {
        $this->store->stored[7] = [new SourceMaterialReference(1, SourceMaterialRole::POSTER)];

        $throwing = new class implements SourceMaterialStoreInterface {
            public function forEvent(int $eventId): array
            {
                return [new SourceMaterialReference(1, SourceMaterialRole::POSTER)];
            }

            public function replaceForEvent(int $eventId, array $references): void
            {
                throw new RuntimeException('The promotion meta could not be saved.');
            }
        };

        $promotion = new SourceMaterialPromotion($this->copier, $throwing);

        $this->expectException(RuntimeException::class);

        $promotion->promote(7, 'b.pdf', 'B.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);
    }

    /**
     * The copier must not be handed a role it cannot honour: the role is part
     * of the copy's contract, so it must arrive intact.
     */
    public function testTheChosenRoleReachesTheCopier(): void
    {
        $this->promotion->promote(7, 'b.pdf', 'B.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);
        $this->promotion->promote(7, 'd.jpg', 'D.jpg', 'image/webp', SourceMaterialRole::DOCUMENT);

        self::assertSame('bulletin', $this->copier->copied[0]['role']);
        self::assertSame('document', $this->copier->copied[1]['role']);
    }

    /**
     * The promotion service itself must never touch WordPress: the whole point
     * of the ports is that the decision is testable without it.
     */
    public function testTheServiceIsFreeOfWordPressCalls(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Core/Attachments/SourceMaterialPromotion.php'
        );

        foreach ([
            'get_post_meta',
            'update_post_meta',
            'delete_post_meta',
            'set_post_thumbnail',
            'wp_insert_attachment',
            'wp_handle_sideload',
            'wp_upload_dir',
            'current_user_can',
        ] as $function) {
            self::assertStringNotContainsString($function . '(', $source);
        }
    }

    /**
     * The service must survive any Throwable from the copier rather than
     * letting a filesystem error escape as something unhandleable. Actually it
     * must NOT swallow: the caller needs to report the failure. This test pins
     * that the RuntimeException contract the callers rely on is intact.
     */
    public function testTheFailureContractIsARuntimeException(): void
    {
        $this->copier->copyFailure = new RuntimeException('The source file is missing.');

        $caught = null;

        try {
            $this->promotion->promote(7, 'gone.pdf', 'B.pdf', 'application/pdf', SourceMaterialRole::BULLETIN);
        } catch (Throwable $thrown) {
            $caught = $thrown;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertStringContainsString('missing', $caught->getMessage());
    }
}