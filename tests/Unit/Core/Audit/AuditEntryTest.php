<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Audit;

use ADCT\ParishIntake\Core\Audit\AuditEntry;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    public function testReadsTheStoredAction(): void
    {
        self::assertSame('Approver approved', $this->entry(action: 'approver_approved')->actionLabel());
    }

    /**
     * A row written before an action was added to the catalogue still has to
     * read as something, because hiding it would be hiding history.
     */
    public function testFallsBackToTheStoredActionValue(): void
    {
        self::assertSame('ancient_ritual', $this->entry(action: 'ancient_ritual')->actionLabel());
    }

    public function testReadsTheStoredSubjectType(): void
    {
        self::assertSame('Event candidate', $this->entry()->subjectLabel());
    }

    public function testFallsBackToTheStoredSubjectType(): void
    {
        self::assertSame('mystery', $this->entry(subjectType: 'mystery')->subjectLabel());
    }

    public function testNamesTheSubjectWithItsId(): void
    {
        self::assertSame('Event candidate #42', $this->entry(subjectId: 42)->subjectLabelWithId());
    }

    #[DataProvider('idsWithoutASubject')]
        public function testASubjectWithoutAnIdIsNamedByTypeAlone(int $subjectId): void
    {
        self::assertSame('Plugin settings', $this->entry(subjectType: 'settings', subjectId: $subjectId)->subjectLabelWithId());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function idsWithoutASubject(): array
    {
        return [
            'unset' => [0],
            'negative' => [-1],
        ];
    }

    public function testDecodesJsonDetails(): void
    {
        $entry = $this->entry(details: '{"title":"Alpha"}');

        self::assertSame(['title' => 'Alpha'], $entry->decoded());
    }

    /**
         * @param list<string|null> $details
     */
        #[DataProvider('undecodableDetails')]
        public function testDetailsThatAreNotAnObjectDecodeToNull(?string $details): void
    {
        self::assertNull($this->entry(details: $details)->decoded());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function undecodableDetails(): array
    {
        return [
            'unset' => [null],
            'empty' => [''],
            'whitespace' => ["  \n "],
            'a bare scalar' => ['"just a string"'],
            'truncated json' => ['{"title":'],
            'prose' => ['not json at all'],
        ];
    }

    private function entry(
        string $action = 'approver_approved',
        string $subjectType = 'event_candidate',
        int $subjectId = 42,
        ?string $details = null
    ): AuditEntry {
        return new AuditEntry(
            1,
            'dean@example.test',
            $action,
            $subjectType,
            $subjectId,
            $details,
            new DateTimeImmutable('2026-10-12 07:00:00', new DateTimeZone('UTC'))
        );
    }
}