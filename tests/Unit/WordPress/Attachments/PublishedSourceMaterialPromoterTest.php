<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Attachments;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialPromotion;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditWriter;
use ADCT\ParishIntake\WordPress\Attachments\PublishedSourceMaterialPromoter;
use ADCT\ParishIntake\WordPress\Attachments\SourceMaterialAuditTrail;
use ADCT\ParishIntake\WordPress\Audit\ActorResolver;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Publishing\WordPressPublicationStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Publishing an event publishes the source material that arrived with it.
 *
 * This is the rule the project owner settled for issue #172, overruling the
 * promotion gate the issue originally asked for: *"I just want it simple,
 * published or not, the source of the events are public, by sending an email
 * they assuming it's public information as a whole."* Publication is therefore
 * the disclosure switch, and copying the file into the media library is a
 * consequence of it rather than a second decision somebody has to remember.
 *
 * That inverts issue #172's AC2 ("nothing is promoted unless an administrator
 * explicitly selects it"). AC2 is superseded at the owner's direction, the
 * reversal is recorded in docs/decisions, and these tests pin the replacement.
 *
 * What is pinned here:
 *
 *  - **Publication is the only switch.** Every eligible file on the message is
 *    copied. There is no selection, no default list and no setting.
 *  - **The role is derived, never chosen.** It comes from
 *    {@see SourceMaterialRole::rolesFor()}, so an image cannot be filed as a
 *    bulletin, a PDF cannot be filed as a poster, and a type that no role
 *    accepts is skipped rather than given a fallback.
 *  - **One unreadable file does not cost the parish the others.** The batch
 *    continues past a failure, and the fault is logged rather than thrown.
 *  - **Nothing escapes.** See {@see testAFaultNeverReachesThePublicationStore()}
 *    for why that matters more than it looks.
 */
final class PublishedSourceMaterialPromoterTest extends TestCase
{
    private const EVENT_ID = 42;

    private const MESSAGE_ID = 7;

    /** @var list<array<string, mixed>> */
    private array $promoted = [];

    /** @var list<array<string, mixed>> */
    private array $audited = [];

    /** Makes the *next* copy throw, so a batch can be seen to survive one. */
    private ?RuntimeException $nextCopyFailure = null;

    private RecordingPromoterCopier $copier;

    private InMemoryPromoterStore $store;

    protected function setUp(): void
    {
        $this->promoted = [];
        $this->audited = [];
        $this->nextCopyFailure = null;
        $this->copier = new RecordingPromoterCopier($this->promoted, $this->nextCopyFailure);
        $this->store = new InMemoryPromoterStore();
    }

    public function testPublishingAnEventCopiesTheSourceMaterialThatCameWithIt(): void
    {
        $this->promoter([
            $this->row(1, 'poster.jpg', 'image/jpeg'),
            $this->row(2, 'bulletin.pdf', 'application/pdf'),
        ])->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertCount(2, $this->promoted, 'both eligible files are copied by publishing alone');
        self::assertSame('poster.jpg', $this->promoted[0]['originalName']);
        self::assertSame('bulletin.pdf', $this->promoted[1]['originalName']);
        self::assertSame(self::EVENT_ID, $this->promoted[0]['eventId']);
    }

    /**
     * The central reversal, asserted directly rather than left implicit.
     *
     * Before this change a publish copied nothing and a reviewer had to tick a
     * box on the candidate. If a future change reintroduces a selection, this
     * is the test that fails: the method takes only the event and the message it
     * came from, so there is nowhere to pass one.
     */
    public function testThereIsNoSelectionBecausePublicationIsTheSwitch(): void
    {
        $parameters = (new \ReflectionMethod(
            PublishedSourceMaterialPromoter::class,
            'promoteForPublishedEvent'
        ))->getParameters();

        self::assertSame(
            ['eventId', 'messageId'],
            array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $parameters),
            'a selection parameter here would mean the gate the owner overruled has crept back'
        );

        $this->promoter([$this->row(1, 'poster.jpg', 'image/jpeg')])
            ->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertCount(1, $this->promoted, 'and the file is copied without anyone choosing it');
    }

    public function testTheRoleComesFromTheTypeRatherThanFromAnyoneChoosingIt(): void
    {
        $this->promoter([
            $this->row(1, 'poster.jpg', 'image/jpeg'),
            $this->row(2, 'bulletin.pdf', 'application/pdf'),
        ])->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertSame(SourceMaterialRole::POSTER, $this->promoted[0]['role']);
        self::assertSame(SourceMaterialRole::BULLETIN, $this->promoted[1]['role']);
    }

    /**
     * The promotion has to be attributable afterwards, so the intake row the
     * copy came from is recorded rather than left to a filename match.
     */
    public function testEveryPromotionIsRecordedAgainstTheIntakeRowItCameFrom(): void
    {
        $this->promoter([$this->row(9, 'bulletin.pdf', 'application/pdf')])
            ->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertCount(1, $this->audited);
        self::assertSame(9, $this->audited[0]['intakeAttachmentId']);
        self::assertSame(self::EVENT_ID, $this->audited[0]['eventId']);
    }

    public function testTheParishFilenameTravelsButTheStoredNameIsWhatGetsCopied(): void
    {
        $this->promoter([$this->row(1, 'parish-poster.jpg', 'image/jpeg', '3f2a9c.jpg')])
            ->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertSame(
            '3f2a9c.jpg',
            $this->promoted[0]['storageName'],
            'the copy is made from the intake store own name, never from the parish one'
        );
        self::assertSame('parish-poster.jpg', $this->promoted[0]['originalName']);
    }

    public function testAMessageWithNothingPromotableIsNotAnError(): void
    {
        $this->promoter([])->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertSame([], $this->promoted);
        self::assertSame([], $this->audited);
    }

    /**
     * A type no role accepts is skipped, not given a fallback role.
     *
     * The allowlist is enforced twice over on purpose: the repository query
     * already excludes it, and {@see SourceMaterialRole::rolesFor()} refuses it
     * again. The second refusal is what survives a repository change that later
     * widens the query.
     */
    public function testATypeNoRoleAcceptsIsSkippedRatherThanGivenADefaultRole(): void
    {
        $this->promoter([$this->row(1, 'clip.mov', 'video/quicktime')])
            ->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertSame([], $this->promoted);
    }

    /**
     * The batch rule: one unreadable file must not cost the parish the others.
     *
     * Real bulletins arrive as one email with a poster *and* a PDF, so failing
     * the whole batch would publish neither.
     */
    public function testOneFileThatCannotBeCopiedDoesNotCostTheEventTheRest(): void
    {
        $this->nextCopyFailure = new RuntimeException('The source file is no longer in the intake store.');

        $this->promoter([
            $this->row(1, 'poster.jpg', 'image/jpeg'),
            $this->row(2, 'bulletin.pdf', 'application/pdf'),
        ])->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertCount(1, $this->promoted, 'the bulletin still publishes');
        self::assertSame('bulletin.pdf', $this->promoted[0]['originalName']);
    }

    /**
     * The fault must not escape, because of what it would be reported as.
     *
     * {@see WordPressPublicationStore} calls promotion from afterCommit(), and
     * its catch block reports anything thrown after COMMIT as "the listing
     * cache could not be refreshed, retry publishing candidate N". An operator
     * who believed that would retry a publish that had already committed, which
     * re-copies every file and demotes the first poster to a document through
     * insertAfterPoster(). So the promoter logs and swallows instead.
     *
     * The assertion that matters is that the call *returns*: that is the property
     * which keeps the store out of its misleading catch branch.
     */
    public function testAFaultNeverReachesThePublicationStore(): void
    {
        $this->nextCopyFailure = new RuntimeException('The uploads directory is not writable.');

        $promoter = $this->promoter([$this->row(1, 'poster.jpg', 'image/jpeg')]);
        $promoter->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertSame(
            [],
            $this->promoted,
            'a fault leaves the event published, which is the outcome issue #172 requires'
        );
    }

    /**
     * A file that copied but whose audit row failed must not undo the copy.
     *
     * {@see SourceMaterialAuditTrail} deliberately does not swallow a failed
     * write, so the promoter has to. Rolling back is impossible once the file is
     * in the library, and an un-recorded publication is the lesser fault because
     * the file is still listed on the event where an operator can remove it.
     */
    public function testAFailedAuditRowDoesNotUndoTheCopyThatAlreadyHappened(): void
    {
        $promoter = new PublishedSourceMaterialPromoter(
            new AttachmentRepository($this->database([$this->row(1, 'poster.jpg', 'image/jpeg')])),
            new SourceMaterialPromotion($this->copier, $this->store),
            new SourceMaterialAuditTrail(new FailingPromoterAuditWriter(), new FixedPromoterActor())
        );

        $promoter->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        self::assertCount(1, $this->promoted, 'the file is public even though the trail could not be written');
    }

    public function testAnImpossibleEventIsRefusedRatherThanPublishingNothingQuietly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->promoter([])->promoteForPublishedEvent(0, self::MESSAGE_ID);
    }

    public function testAnImpossibleMessageIsRefusedRatherThanReadingSomebodyElsesMail(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->promoter([])->promoteForPublishedEvent(self::EVENT_ID, 0);
    }

    /**
     * The ordered promotion meta is rewritten through the real service, not
     * bypassed, so the front end sees the file.
     */
    public function testThePromotionMetaIsWrittenSoTheFrontEndCanSeeTheFile(): void
    {
        $this->promoter([$this->row(1, 'poster.jpg', 'image/jpeg')])
            ->promoteForPublishedEvent(self::EVENT_ID, self::MESSAGE_ID);

        $references = $this->store->forEvent(self::EVENT_ID);

        self::assertCount(1, $references);
        self::assertSame(SourceMaterialRole::POSTER, $references[0]->role);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function promoter(array $rows): PublishedSourceMaterialPromoter
    {
        return new PublishedSourceMaterialPromoter(
            new AttachmentRepository($this->database($rows)),
            new SourceMaterialPromotion($this->copier, $this->store),
            new SourceMaterialAuditTrail($this->recordingAuditWriter(), new FixedPromoterActor())
        );
    }

    /**
     * A database double in the shape {@see AttachmentRepository} expects.
     *
     * It enforces the placeholder count, because real wpdb::prepare() does and a
     * silently mismatched query would let a broken SELECT pass unnoticed.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function database(array $rows): DatabaseConnectionInterface
    {
        return new class ($rows) implements DatabaseConnectionInterface {
            /**
             * @param list<array<string, mixed>> $rows
             */
            public function __construct(private array $rows)
            {
            }

            public function prefix(): string
            {
                return 'wp_';
            }

            public function prepare(string $query, mixed ...$arguments): string
            {
                $expected = substr_count($query, '%s') + substr_count($query, '%d');

                if ($expected !== count($arguments)) {
                    throw new InvalidArgumentException(sprintf(
                        'wpdb::prepare() was called incorrectly: %d placeholders for %d arguments',
                        $expected,
                        count($arguments)
                    ));
                }

                return $query;
            }

            public function query(string $query): int|false
            {
                return 1;
            }

            public function getRow(string $query): ?array
            {
                return $this->rows[0] ?? null;
            }

            public function getResults(string $query): array
            {
                return $this->rows;
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
                return '';
            }

            public function clearLastError(): void
            {
            }

            public function lastError(): string
            {
                return '';
            }
        };
    }

    private function recordingAuditWriter(): AuditWriter
    {
        $audited = &$this->audited;

        return new class ($audited) implements AuditWriter {
            private int $nextId = 1;

            /** @param list<array<string, mixed>> $audited */
            public function __construct(private array &$audited)
            {
            }

            public function write(
                string $actor,
                AuditAction $action,
                string $subjectType,
                int $subjectId,
                array $details
            ): int {
                $this->audited[] = [
                    'actor' => $actor,
                                    'action' => $action,
                    'eventId' => $subjectId,
                    'intakeAttachmentId' => $details['intake_attachment_id'] ?? null,
                ];

                return $this->nextId++;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $filename, string $mimeType, ?string $storageName = null): array
    {
        return [
            'id' => $id,
            'message_id' => self::MESSAGE_ID,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => 2048,
            'storage_path' => $storageName ?? $id . '-' . $filename,
            'status' => 'stored',
        ];
    }
}

final class FixedPromoterActor implements ActorResolver
{
    public function actor(): string
    {
        return 'system@adct.test';
    }
}

/**
 * A copier that copies in memory, so promotion is exercised without a
 * filesystem, and which can be told to fail the next copy so the batch rule
 * can be observed.
 *
 * The failure is a property rather than a hard-coded throw so the tests that
 * need a healthy copier and the one that needs a broken one can share it.
 */
final class RecordingPromoterCopier implements \ADCT\ParishIntake\Core\Ports\SourceMaterialCopierInterface
{
    public int $nextAttachmentId = 1000;

    /**
     * @param list<array<string, mixed>> $promoted
     */
    public function __construct(
        private array &$promoted,
        private ?RuntimeException &$nextCopyFailure
    ) {
    }

    public function copyIntoMediaLibrary(
        int $eventId,
        string $storageName,
        string $originalName,
        string $role
    ): int {
        if ($this->nextCopyFailure !== null) {
                // Cleared as it is thrown, so a batch can be seen to survive
                // exactly one bad file. A permanent fault here would fail every
                // copy and make the batch test pass for the wrong reason.
                $failure = $this->nextCopyFailure;
                $this->nextCopyFailure = null;

                throw $failure;
            }

        $this->promoted[] = [
            'eventId' => $eventId,
            'storageName' => $storageName,
            'originalName' => $originalName,
            'role' => $role,
        ];

        return $this->nextAttachmentId++;
    }

    public function detachFromEvent(int $eventId, int $attachmentId): bool
    {
        return true;
    }

    public function setFeaturedImage(int $eventId, int $attachmentId): bool
    {
        return true;
    }
}

/**
 * The ordered promotion meta, held in memory.
 */
final class InMemoryPromoterStore implements \ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface
{
    /** @var array<int, list<\ADCT\ParishIntake\Core\Attachments\SourceMaterialReference>> */
    private array $stored = [];

    /**
     * @return list<\ADCT\ParishIntake\Core\Attachments\SourceMaterialReference>
     */
    public function forEvent(int $eventId): array
    {
        return $this->stored[$eventId] ?? [];
    }

    public function replaceForEvent(int $eventId, array $references): void
    {
        $this->stored[$eventId] = array_values($references);
    }
}

final class FailingPromoterAuditWriter implements AuditWriter
{
    public function write(
        string $actor,
        AuditAction $action,
        string $subjectType,
        int $subjectId,
        array $details
    ): int {
        throw new RuntimeException('The audit table is unavailable.');
    }
}