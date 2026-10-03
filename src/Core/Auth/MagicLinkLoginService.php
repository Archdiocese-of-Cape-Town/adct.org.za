<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Auth;

use ADCT\ParishIntake\Core\Ports\ActionTokenLoginDeliveryInterface;
use ADCT\ParishIntake\Core\Ports\ActionTokenLoginSubjectResolverInterface;

/**
 * Mints and delivers an ADR 0007 magic link for a front-end approval queue.
 *
 * The service is deliberately ignorant of who the caller is: it asks the
 * injected resolver whether the address maps to an account entitled to the
 * front-end queue, and answers the same thing either way. That is what keeps
 * the request form from confirming which addresses exist.
 *
 * The rate limiter runs before the resolver on purpose — a request for an
 * unknown address costs the same as one for a known address, so the limiter's
 * counters cannot be used to probe for real parish contacts.
 */
final class MagicLinkLoginService
{
    public function __construct(
        private ActionTokenService $tokens,
        private ActionTokenRateLimiter $rateLimiter,
        private ActionTokenLoginSubjectResolverInterface $resolver,
        private ActionTokenLoginDeliveryInterface $delivery
    )
    {
    }

    /**
     * @return MagicLinkLoginStatus
     */
    public function request(string $email, string $remoteAddress): MagicLinkLoginStatus
    {
        $normalized = ActionTokenBinding::normalizeEmailAddress($email);

        if (! $this->rateLimiter->allowRequest($normalized, $remoteAddress)) {
            return MagicLinkLoginStatus::RATE_LIMITED;
        }

        $binding = $this->resolver->bindingFor($normalized);

        if ($binding === null) {
            // The request still counts against the limiter above, so an address
            // that exists and one that does not are indistinguishable from the
            // outside.
            return MagicLinkLoginStatus::SENT;
        }

        $this->delivery->deliver($binding, $this->tokens->issue($binding));

        return MagicLinkLoginStatus::SENT;
    }
}