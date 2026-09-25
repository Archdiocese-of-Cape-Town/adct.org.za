<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenRenewalDeliveryInterface;

final class ActionTokenRenewalService
{
    public function __construct(
        private ActionTokenService $tokens,
        private ActionTokenRateLimiter $rateLimiter,
        private ActionTokenRenewalDeliveryInterface $delivery
    ) {
    }

    public function request(string $existingToken, string $remoteAddress): ActionTokenRenewalStatus
    {
        $inspection = $this->tokens->inspect($existingToken);

        if (
            ! in_array($inspection->status, [ActionTokenStatus::EXPIRED, ActionTokenStatus::USED], true)
            || $inspection->binding === null
        ) {
            return ActionTokenRenewalStatus::NOT_ELIGIBLE;
        }

        if (! $this->rateLimiter->allowRequest($inspection->binding->email, $remoteAddress)) {
            return ActionTokenRenewalStatus::RATE_LIMITED;
        }

        $issued = $this->tokens->issue($inspection->binding);
        $this->delivery->deliver($inspection->binding, $issued);

        return ActionTokenRenewalStatus::REQUESTED;
    }
}
