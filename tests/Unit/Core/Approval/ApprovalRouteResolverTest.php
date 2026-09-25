<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Approval;

use ADCT\ParishIntake\Core\Approval\ApprovalRoute;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;
use ADCT\ParishIntake\Core\Approval\Approver;
use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApprovalRouteResolverTest extends TestCase
{
    public function testActiveDeaneryRouteIncludesBothActiveApprovers(): void
    {
        $repository = new FakeApprovalRouteRepository([
            7 => new ApprovalRouteSnapshot(12, true, [
                $this->approver(1, 101),
                $this->approver(2, 102),
            ]),
        ]);

        $route = (new ApprovalRouteResolver($repository))->forParish(7);

        self::assertFalse($route->reviewersOnly);
        self::assertSame(ApprovalRoute::REASON_OK, $route->reason);
        self::assertCount(2, $route->approvers);
        self::assertSame([101, 102], array_map(
            static fn (Approver $approver): int => $approver->wpUserId,
            $route->approvers
        ));
        self::assertSame([7], $repository->requestedParishIds);
    }

    public function testParishWithoutDeaneryRoutesToReviewersOnly(): void
    {
        $route = (new ApprovalRouteResolver(new FakeApprovalRouteRepository([
            7 => new ApprovalRouteSnapshot(null, false, []),
        ])))->forParish(7);

        self::assertTrue($route->reviewersOnly);
        self::assertSame(ApprovalRoute::REASON_NO_DEANERY, $route->reason);
        self::assertSame([], $route->approvers);
    }

    public function testDeaneryWithoutActiveApproverRoutesToReviewersOnly(): void
    {
        $route = (new ApprovalRouteResolver(new FakeApprovalRouteRepository([
            7 => new ApprovalRouteSnapshot(12, true, []),
        ])))->forParish(7);

        self::assertTrue($route->reviewersOnly);
        self::assertSame(ApprovalRoute::REASON_NO_ACTIVE_APPROVER, $route->reason);
        self::assertSame([], $route->approvers);
    }

    public function testInactiveDeaneryRoutesToReviewersOnlyEvenWhenItHasActiveApprovers(): void
    {
        $route = (new ApprovalRouteResolver(new FakeApprovalRouteRepository([
            7 => new ApprovalRouteSnapshot(12, false, [$this->approver(1, 101)]),
        ])))->forParish(7);

        self::assertTrue($route->reviewersOnly);
        self::assertSame(ApprovalRoute::REASON_DEANERY_INACTIVE, $route->reason);
        self::assertSame([], $route->approvers);
    }

    public function testInactiveApproverDoesNotJoinTheRoute(): void
    {
        $route = (new ApprovalRouteResolver(new FakeApprovalRouteRepository([
            7 => new ApprovalRouteSnapshot(12, true, [
                $this->approver(1, 101, false),
            ]),
        ])))->forParish(7);

        self::assertTrue($route->reviewersOnly);
        self::assertSame(ApprovalRoute::REASON_NO_ACTIVE_APPROVER, $route->reason);
        self::assertSame([], $route->approvers);
    }

    public function testInvalidParishIdIsRejectedBeforeReadingTheRepository(): void
    {
        $repository = new FakeApprovalRouteRepository([]);

        $this->expectException(InvalidArgumentException::class);

        (new ApprovalRouteResolver($repository))->forParish(0);
    }

    private function approver(int $assignmentId, int $wpUserId, bool $active = true): Approver
    {
        return new Approver(
            $assignmentId,
            $wpUserId,
            'approver' . $wpUserId . '@example.test',
            'Sample approver',
            Approver::NOTIFY_EACH,
            true,
            $active
        );
    }
}

final class FakeApprovalRouteRepository implements ApprovalRouteRepositoryInterface
{
    /**
     * @var list<int>
     */
    public array $requestedParishIds = [];

    /**
     * @param array<int, ApprovalRouteSnapshot|null> $routesByParish
     */
    public function __construct(private array $routesByParish)
    {
    }

    public function findForParish(int $parishId): ?ApprovalRouteSnapshot
    {
        $this->requestedParishIds[] = $parishId;

        return $this->routesByParish[$parishId] ?? null;
    }
}
