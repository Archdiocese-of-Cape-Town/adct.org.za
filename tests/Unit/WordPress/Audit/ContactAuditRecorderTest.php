<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Audit {
    function error_log(string $message): bool
    {
        $GLOBALS['contact_audit_recorder_log'][] = $message;

        return true;
    }
}

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Audit {

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Audit\AuditWriter;
use ADCT\ParishIntake\WordPress\Audit\ActorResolver;
use ADCT\ParishIntake\WordPress\Audit\ContactAuditRecorder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContactAuditRecorderTest extends TestCase
{
    public function testRecordsTheAddressTrustAndParishAgainstTheContactRow(): void
    {
        $writer = $this->writer();
        $recorder = new ContactAuditRecorder($writer, $this->resolver('dean@example.test'));

        $recorder->record(AuditAction::CONTACT_BLOCKED, 'someone@example.test', 12, 34, 'blocked');

        self::assertSame([[
            'actor' => 'dean@example.test',
            'action' => AuditAction::CONTACT_BLOCKED,
            'subjectType' => AuditSubjectType::PARISH_CONTACT,
            'subjectId' => 34,
            'details' => [
                'email' => 'someone@example.test',
                'parish_id' => 12,
                'trust' => 'blocked',
            ],
        ]], $writer->writes);
    }

    /**
     * An address can be blocked before it is linked to any parish, so there may
     * be no contact row at all. The address then goes in the details and the
     * subject id stays unset rather than pointing at some other row.
     */
    public function testRecordsAnAddressWithNoContactRowYet(): void
    {
        $writer = $this->writer();
        $recorder = new ContactAuditRecorder($writer, $this->resolver('dean@example.test'));

        $recorder->record(AuditAction::CONTACT_BLOCKED, 'someone@example.test', null, null, 'blocked');

        self::assertSame(0, $writer->writes[0]['subjectId']);
        self::assertNull($writer->writes[0]['details']['parish_id']);
    }

    /**
     * The actor is read per row rather than once at construction, because these
     * screens take the action on a later request than the one that built the
     * plugin object graph.
     */
    public function testReadsTheActorForEachRow(): void
    {
        $writer = $this->writer();
        $calls = 0;
        $resolver = new class ($calls) implements ActorResolver {
            /** @var int */
            private $calls;

            public function __construct(int &$calls)
            {
                $this->calls = &$calls;
            }

            public function actor(): string
            {
                $this->calls++;

                return $this->calls === 1 ? 'first@example.test' : 'second@example.test';
            }
        };
        $recorder = new ContactAuditRecorder($writer, $resolver);

        $recorder->record(AuditAction::CONTACT_VERIFIED, 'a@example.test', 1, 1, 'verified');
        $recorder->record(AuditAction::CONTACT_BLOCKED, 'b@example.test', 1, 2, 'blocked');

        self::assertSame('first@example.test', $writer->writes[0]['actor']);
        self::assertSame('second@example.test', $writer->writes[1]['actor']);
    }

    /**
     * By the time the row is written the trust change has already committed.
     * Failing the request now would lose an edit that succeeded and alarm the
     * operator about the wrong thing, so the failure is recorded and swallowed.
     */
    public function testAWriteFailureDoesNotBreakTheScreenThatMadeTheChange(): void
    {
        $GLOBALS['contact_audit_recorder_log'] = [];
        $recorder = new ContactAuditRecorder(
            $this->writer(failing: true),
            $this->resolver('dean@example.test')
        );

        $recorder->record(AuditAction::CONTACT_BLOCKED, 'someone@example.test', 1, 1, 'blocked');

        self::assertNotSame([], $GLOBALS['contact_audit_recorder_log']);
        self::assertStringContainsString(
            'The contact audit row could not be written',
            implode("\n", $GLOBALS['contact_audit_recorder_log'])
        );

        unset($GLOBALS['contact_audit_recorder_log']);
    }

    private function writer(bool $failing = false): RecordingAuditWriter
    {
        return new RecordingAuditWriter($failing);
    }

    private function resolver(string $actor): ActorResolver
    {
        return new class ($actor) implements ActorResolver {
            public function __construct(private string $actor)
            {
            }

            public function actor(): string
            {
                return $this->actor;
            }
        };
    }
}

final class RecordingAuditWriter implements AuditWriter
{
    /**
     * @var list<array{actor: string, action: AuditAction, subjectType: string, subjectId: int, details: array<string, mixed>}>
     */
    public array $writes = [];

    public function __construct(private bool $failing = false)
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

        return count($this->writes);
    }
}
        }