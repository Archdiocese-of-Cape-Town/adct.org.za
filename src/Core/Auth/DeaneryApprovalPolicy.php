<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

final class DeaneryApprovalPolicy
{
    /**
     * @param int[] $userDeaneryIds
     * @param string[] $userCaps
     */
    public function canApprove(array $userDeaneryIds, ?int $parishDeaneryId, array $userCaps): bool
    {
        if (in_array(Capabilities::REVIEW, $userCaps, true)) {
            return true;
        }

        if (
            $parishDeaneryId === null
            || ! in_array(Capabilities::APPROVE_DEANERY, $userCaps, true)
        ) {
            return false;
        }

        foreach ($userDeaneryIds as $userDeaneryId) {
            if ((int) $userDeaneryId === $parishDeaneryId) {
                return true;
            }
        }

        return false;
    }
}
