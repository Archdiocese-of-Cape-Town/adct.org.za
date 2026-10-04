<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Publishing;

use ADCT\ParishIntake\Core\Directory\SenderLookupResult;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use ADCT\ParishIntake\Core\Ports\PublicationAuthorityInterface;

/**
 * The permissive answer to #200: a verified contact's own change to its own
 * parish's event publishes at once, with the change notice as the record.
 *
 * Not wired by default. It exists so the owner decision can be exercised — by a
 * test today, by a construction argument the day it is answered — without the
 * conservative default ever being weakened in the meantime.
 *
 * Three conditions, all of which must hold:
 *
 *  1. The sender's trust, as re-read from the directory at publication time, is
 *     VERIFIED. Not "was verified when the message arrived": the whole point of
 *     resolving trust here is that a verification withdrawn in the intervening
 *     minutes stops the change.
 *  2. The candidate names a parish. A change to a parish-less candidate has no
 *     owner to be verified against, so there is nobody to check.
 *  3. That parish is one the sender is linked to. Trust alone is not a licence to
 *     change another parish's events.
 *
 * The `contact_change` label on the row is a claim recorded by the router, not a
 * fact this policy is entitled to. It is not read here, and nothing downstream may
 * treat it as one.
 */
final class VerifiedContactPublicationAuthority implements PublicationAuthorityInterface
{
    /**
     * How the sender is found. Whether the policy is willing is one question; who
     * the sender is is another, and the answer to the first is no use without the
     * second, so every implementation of the first needs the second.
     */
    private ContactSenderResolver $resolver;

    public function __construct(?ContactSenderResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new ContactSenderResolver();
    }

    public function allowsContactChange(array $row, SenderLookupResult $sender): bool
    {
        if ($sender->trust !== SenderTrust::VERIFIED) {
            return false;
        }

        $parishId = self::parishId($row);

        if ($parishId === null) {
            return false;
        }

        return in_array($parishId, $sender->parishIds, true);
    }

    public function resolveSender(array $row, ParishContactStoreInterface $contacts): SenderLookupResult
    {
        return $this->resolver->resolveSender($row, $contacts);
    }

    /**
     * A candidate with no parish, or a parish id that is not a positive integer, is
     * not a parish this policy can reason about.
     */
    private static function parishId(array $row): ?int
    {
        $value = $row['parish_id'] ?? null;

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}