<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Approval;

use ADCT\ParishIntake\Core\Ports\ApprovalRouteRepositoryInterface;
use InvalidArgumentException;
use OutOfBoundsException;

final class ApprovalRouteResolver
{
    public function __construct(private ApprovalRouteRepositoryInterface $repository)
    {
    }

    public function forParish(int $parishId): ApprovalRoute
    {
        if ($parishId < 1) {
            throw new InvalidArgumentException('A parish ID must be positive.');
        }

        $snapshot = $this->repository->findForParish($parishId);

        if ($snapshot === null) {
            throw new OutOfBoundsException('The parish approval route could not be found.');
        }

        if ($snapshot->deaneryId === null) {
            return new ApprovalRoute([], true, ApprovalRoute::REASON_NO_DEANERY);
        }

        if (! $snapshot->deaneryActive) {
            return new ApprovalRoute([], true, ApprovalRoute::REASON_DEANERY_INACTIVE);
        }

        $activeApprovers = array_values(array_filter(
            $snapshot->approvers,
            static fn (Approver $approver): bool => $approver->active
        ));

        if ($activeApprovers === []) {
            return new ApprovalRoute([], true, ApprovalRoute::REASON_NO_ACTIVE_APPROVER);
        }

        return new ApprovalRoute($activeApprovers, false, ApprovalRoute::REASON_OK);
    }
}
