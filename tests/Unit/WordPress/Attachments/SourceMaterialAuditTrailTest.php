<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Attachments {

use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Audit\AuditWriter;
use ADCT\ParishIntake\WordPress\Attachments\SourceMaterialAuditTrail;
use ADCT\ParishIntake\WordPress\Audit\ActorResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SourceMaterialAuditTrailTest extends TestCase
{
    public function testAPromotionNamesWhoMadeAFilePublicAndWhichFile(): void
    {
        $writer = new RecordingTrailWriter();
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $trail->recordPromotion(
            412,
            new SourceMaterialReference(901, SourceMaterialRole::POSTER, 'parish-poster.jpg'),
            55
        );

        self::assertSame([[
            'actor' => 'dean@example.test',
            'action' => AuditAction::SOURCE_MATERIAL_PROMOTED,
            'subjectType' => AuditSubjectType::EVENT,
            'subjectId' => 412,
            'details' => [
                'attachment_id' => 901,
                'role' => SourceMaterialRole::POSTER,
                'role_label' => 'Poster',
                'original_name' => 'parish-poster.jpg',
                'intake_attachment_id' => 55,
            ],
        ]], $writer->writes);
    }

    public function testTheRowIsWrittenAgainstTheEventNotTheAttachment(): void
    {
        $writer = new RecordingTrailWriter();
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $trail->recordPromotion(412, new SourceMaterialReference(901, SourceMaterialRole::DOCUMENT));

        // The subject is the event, so every promotion for one event is one
        // query, and the audit log's own subject vocabulary is not widened with
        // an "attachment" type that nothing else uses.
        self::assertSame(AuditSubjectType::EVENT, $writer->writes[0]['subjectType']);
        self::assertSame(412, $writer->writes[0]['subjectId']);
    }

    /**
     * The intake row id is the only thing that ties the public copy back to the
     * raw file the parish emailed. When there is one it must be recorded.
     */
    public function testTheIntakeRowIsRecordedSoTheRawFileCanBeTracedBack(): void
    {
        $writer = new RecordingTrailWriter();
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $trail->recordPromotion(412, new SourceMaterialReference(901, SourceMaterialRole::BULLETIN), 7);

        self::assertSame(7, $writer->writes[0]['details']['intake_attachment_id']);
                self::assertSame('Parish bulletin', $writer->writes[0]['details']['role_label']);
    }

    public function testARemovalIsRecordedAgainstTheEventAndNamesTheRoleItHeld(): void
    {
        $writer = new RecordingTrailWriter();
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $trail->recordRemoval(412, new SourceMaterialReference(901, SourceMaterialRole::POSTER, 'poster.jpg'));

        self::assertSame(AuditAction::SOURCE_MATERIAL_REMOVED, $writer->writes[0]['action']);
        self::assertSame(412, $writer->writes[0]['subjectId']);
        self::assertSame('poster', $writer->writes[0]['details']['role']);
        self::assertSame('Poster', $writer->writes[0]['details']['role_label']);
    }

    /**
     * A removal detaches a media-library file; there is no intake row involved,
     * and a row claiming one would point a POPIA enquiry at the wrong file.
     */
    public function testARemovalRecordsNoIntakeRow(): void
    {
        $writer = new RecordingTrailWriter();
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $trail->recordRemoval(412, new SourceMaterialReference(901, SourceMaterialRole::POSTER));

        self::assertArrayNotHasKey('intake_attachment_id', $writer->writes[0]['details']);
        self::assertArrayNotHasKey('original_name', $writer->writes[0]['details']);
    }

    public function testAPromotionWithoutAParishFilenameRecordsNoName(): void
    {
        $writer = new RecordingTrailWriter();
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $trail->recordPromotion(412, new SourceMaterialReference(901, SourceMaterialRole::DOCUMENT));

        self::assertArrayNotHasKey('original_name', $writer->writes[0]['details']);
    }

    /**
     * A zero or negative intake id means "no row", not "row zero", so it is
     * left out rather than recorded as a number that resolves to nothing.
     */
    public function testAnUnusableIntakeRowIdIsLeftOutRatherThanRecordedAsZero(): void
    {
        $writer = new RecordingTrailWriter();
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $trail->recordPromotion(412, new SourceMaterialReference(901, SourceMaterialRole::DOCUMENT), 0);
        $trail->recordPromotion(412, new SourceMaterialReference(902, SourceMaterialRole::DOCUMENT), -3);

        self::assertArrayNotHasKey('intake_attachment_id', $writer->writes[0]['details']);
        self::assertArrayNotHasKey('intake_attachment_id', $writer->writes[1]['details']);
    }

    /**
     * The actor is read per row, not cached, because these rows are written on
     * a later request than the one that built the plugin object graph.
     */
    public function testTheActorIsReadForEachRowRatherThanCachedAtConstruction(): void
    {
        $writer = new RecordingTrailWriter();
        $seen = [];
        $resolver = new class ($seen) implements ActorResolver {
            /** @var list<string> */
            private array $seen;

            public function __construct(array &$seen)
            {
                $this->seen = &$seen;
            }

            public function actor(): string
            {
                $this->seen[] = 'read';

                return $this->seen === [] ? 'never@example.test' : 'dean@example.test';
            }
        };
        $trail = new SourceMaterialAuditTrail($writer, $resolver);

        $trail->recordPromotion(1, new SourceMaterialReference(901, SourceMaterialRole::DOCUMENT));
        $trail->recordRemoval(1, new SourceMaterialReference(901, SourceMaterialRole::DOCUMENT));

        self::assertSame(['read', 'read'], $seen);
        self::assertSame('dean@example.test', $writer->writes[1]['actor']);
    }

    /**
     * By the time this row is written the file is already public, so a failure
     * is propagated: swallowing it would leave a published bulletin that the
     * record says nobody published. This is the deliberate opposite of
     * ContactAuditRecorder, which logs and continues.
     */
    public function testAFailedWriteIsNotSwallowedBecauseThePromotionAlreadyHappened(): void
    {
        $writer = new RecordingTrailWriter(failing: true);
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $this->expectException(RuntimeException::class);

        $trail->recordPromotion(412, new SourceMaterialReference(901, SourceMaterialRole::POSTER));
    }

    public function testARowWithNoEventIsRefusedRatherThanWrittenAgainstNothing(): void
    {
        $writer = new RecordingTrailWriter();
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $this->expectException(InvalidArgumentException::class);

        try {
            $trail->recordPromotion(0, new SourceMaterialReference(901, SourceMaterialRole::POSTER));
        } finally {
            self::assertSame([], $writer->writes, 'Nothing may be written without an event.');
        }
    }

    public function testTheNewAuditRowIdIsReturnedToTheCaller(): void
    {
        $writer = new RecordingTrailWriter(nextId: 4711);
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        self::assertSame(4711, $trail->recordPromotion(412, new SourceMaterialReference(901, SourceMaterialRole::POSTER)));
        self::assertSame(4711, $trail->recordRemoval(412, new SourceMaterialReference(901, SourceMaterialRole::POSTER)));
    }

    /**
     * No row may carry the raw storage path. It is a filesystem detail of the
     * private intake store, and the audit log outlives that store.
     */
    public function testNoRowCarriesAStoragePath(): void
    {
        $writer = new RecordingTrailWriter();
        $trail = new SourceMaterialAuditTrail($writer, new FixedActorResolver('dean@example.test'));

        $trail->recordPromotion(412, new SourceMaterialReference(901, SourceMaterialRole::BULLETIN, 'notice.pdf'), 7);

        $encoded = (string) json_encode($writer->writes[0]['details']);

                self::assertSame(
                    ['attachment_id', 'role', 'role_label', 'original_name', 'intake_attachment_id'],
                    array_keys($writer->writes[0]['details']),
                    'The row carries only these five keys, so no path can be added later without a test noticing.'
                );
                self::assertStringNotContainsString('uploads', $encoded);
    }
}

final class FixedActorResolver implements ActorResolver
{
    public function __construct(private string $actor)
    {
    }

    public function actor(): string
    {
        return $this->actor;
    }
}

final class RecordingTrailWriter implements AuditWriter
{
    /**
     * @var list<array{actor: string, action: AuditAction, subjectType: string, subjectId: int, details: array<string, mixed>}>
     */
    public array $writes = [];

    public function __construct(private bool $failing = false, private int $nextId = 1)
    {
    }

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
        if ($this->failing) {
            throw new RuntimeException('The audit record could not be saved.');
        }

        $this->writes[] = [
            'actor' => $actor,
            'action' => $action,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'details' => $details,
        ];

        return $this->nextId;
    }
}
}