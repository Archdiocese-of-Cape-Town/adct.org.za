<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Approval\ApprovalRouteSnapshot;

interface ApprovalRouteRepositoryInterface
{
    public function findForParish(int $parishId): ?ApprovalRouteSnapshot;
}
