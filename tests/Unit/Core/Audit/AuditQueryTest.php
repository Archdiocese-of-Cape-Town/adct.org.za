<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Audit;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditQuery;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditQueryTest extends TestCase
{
    private const NOW = '2026-10-12 09:00:00';

    public function testKeepsTheFiltersItIsGiven(): void
    {
        $query = new AuditQuery(
            AuditAction::APPROVER_APPROVED->value,
            'event_candidate',
            42,
            'dean@example.test',
            $this->moment(),
            null,
            25,
            50
        );

        self::assertSame('approver_approved', $query->action);
        self::assertSame('event_candidate', $query->subjectType);
        self::assertSame(42, $query->subjectId);
        self::assertSame('dean@example.test', $query->actor);
        self::assertSame(25, $query->limit);
        self::assertSame(50, $query->offset);
    }

    public function testDefaultsToAnUnfilteredWindow(): void
    {
        $query = new AuditQuery(since: $this->moment());

        self::assertSame('', $query->action);
        self::assertSame('', $query->subjectType);
        self::assertSame(0, $query->subjectId);
        self::assertSame('', $query->actor);
        self::assertSame(50, $query->limit);
        self::assertSame(0, $query->offset);
    }

    public function testRejectsAnUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditQuery(action: 'approver_deleted', since: $this->moment());
    }

    public function testRejectsAnUnknownSubjectType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditQuery(subjectType: 'invoice', since: $this->moment());
    }

    public function testRejectsANegativeSubjectId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditQuery(subjectId: -1, since: $this->moment());
    }

    public function testRejectsAQueryWithoutAWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditQuery();
    }

    public function testRejectsAWindowThatEndsBeforeItStarts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditQuery(
            since: $this->moment(),
            until: $this->moment()->modify('-1 day')
        );
    }

    public function testAcceptsAWindowOfExactlyTheRetentionPeriod(): void
    {
        $query = new AuditQuery(
            since: $this->moment(),
            until: $this->moment()->modify('+24 months')
        );

        self::assertSame(24, AuditQuery::MAXIMUM_WINDOW_MONTHS);
        self::assertNotNull($query->until);
    }

    public function testRejectsAWindowLongerThanTheRetentionPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditQuery(
            since: $this->moment(),
            until: $this->moment()->modify('+' . (AuditQuery::MAXIMUM_WINDOW_MONTHS + 1) . ' months')
        );
    }

    public function testAnOpenEndedWindowIsAlwaysAllowed(): void
    {
        $query = new AuditQuery(
            since: $this->moment()->modify('-' . (AuditQuery::MAXIMUM_WINDOW_MONTHS * 5) . ' months'),
            until: null
        );

        self::assertNull($query->until);
    }

    #[DataProvider('pageSizes')]
        public function testRejectsAPageSizeOutOfRange(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditQuery(since: $this->moment(), limit: $limit);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function pageSizes(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'beyond the hard ceiling' => [AuditQuery::MAXIMUM_LIMIT + 1],
        ];
    }

    public function testAcceptsTheLargestAllowedPageSize(): void
    {
        $query = new AuditQuery(since: $this->moment(), limit: AuditQuery::MAXIMUM_LIMIT);

        self::assertSame(AuditQuery::MAXIMUM_LIMIT, $query->limit);
    }

    public function testRejectsANegativeOffset(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditQuery(since: $this->moment(), offset: -1);
    }

    public function testWithPagingKeepsTheFiltersAndAddsThePage(): void
    {
        $query = new AuditQuery(
            action: AuditAction::APPROVER_APPROVED->value,
            subjectType: \ADCT\ParishIntake\Core\Audit\AuditSubjectType::EVENT_CANDIDATE,
            subjectId: 7,
            since: $this->moment()
        );

        $paged = $query->withPaging(25, 50);

        self::assertSame('approver_approved', $paged->action);
        self::assertSame('event_candidate', $paged->subjectType);
        self::assertSame(7, $paged->subjectId);
        self::assertEquals($query->since, $paged->since);
        self::assertSame(25, $paged->limit);
        self::assertSame(50, $paged->offset);
    }

    public function testWithPagingLeavesTheQueryItWasCalledOnUntouched(): void
    {
        $query = new AuditQuery(since: $this->moment());

        $query->withPaging(25, 50);

        self::assertSame(50, $query->limit);
        self::assertSame(0, $query->offset);
    }

    public function testWithPagingGoesThroughTheSameValidationAsTheConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AuditQuery(since: $this->moment()))->withPaging(0, 0);
    }

    public function testRecentReachesBackTheRequestedNumberOfMonths(): void
    {
        $query = AuditQuery::recent($this->moment(), 6);

        self::assertSame('2026-04-12 09:00:00', $query->since->format('Y-m-d H:i:s'));
        self::assertNull($query->until);
    }

    public function testRecentCarriesEveryFilter(): void
    {
        $query = AuditQuery::recent(
            $this->moment(),
            1,
            25,
            75,
            AuditAction::EVENT_PUBLISHED->value,
            'event',
            9,
            'admin@example.test'
        );

        self::assertSame('event_published', $query->action);
        self::assertSame('event', $query->subjectType);
        self::assertSame(9, $query->subjectId);
        self::assertSame('admin@example.test', $query->actor);
        self::assertSame(25, $query->limit);
        self::assertSame(75, $query->offset);
    }

    /**
         * A window from a hand-edited query string is pulled into the range the
         * screen offers rather than refused: a visitor still gets a log to read.
         */
             #[DataProvider('nonsenseWindows')]
             public function testRecentClampsANonsenseWindow(int $months, int $expectedMonths): void
        {
            $query = AuditQuery::recent($this->moment(), $months);

            self::assertSame(
                $this->moment()->modify('-' . $expectedMonths . ' months')->format('Y-m-d H:i:s'),
                $query->since->format('Y-m-d H:i:s')
            );
        }

        /**
         * @return array<string, array{int, int}>
         */
    public static function nonsenseWindows(): array
        {
            return [
                'zero' => [0, 1],
                'negative' => [-3, 1],
                'beyond retention' => [AuditQuery::MAXIMUM_WINDOW_MONTHS + 1, AuditQuery::MAXIMUM_WINDOW_MONTHS],
            ];
        }

    private function moment(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }
}
