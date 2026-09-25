<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use DomainException;
use InvalidArgumentException;

final class SenderTrustStateMachine
{
    public function transition(
        string $current,
        string $next,
        bool $explicitAdminUnblock = false
    ): string {
        if (! SenderTrust::isValid($current) || ! SenderTrust::isValid($next)) {
            throw new InvalidArgumentException('The sender trust state is not valid.');
        }

        if ($current === $next || $next === SenderTrust::BLOCKED) {
            return $next;
        }

        if ($current === SenderTrust::UNKNOWN && $next === SenderTrust::PENDING) {
            return $next;
        }

        if ($current === SenderTrust::PENDING && $next === SenderTrust::VERIFIED) {
            return $next;
        }

        if (
            $current === SenderTrust::BLOCKED
            && $next === SenderTrust::UNKNOWN
            && $explicitAdminUnblock
        ) {
            return $next;
        }

        throw new DomainException(sprintf(
            'The sender trust transition from %s to %s is not allowed.',
            $current,
            $next
        ));
    }
}
