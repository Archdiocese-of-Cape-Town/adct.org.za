<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Publishing;

use ADCT\ParishIntake\Core\Directory\SenderLookupResult;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use ADCT\ParishIntake\Core\Ports\PublicationAuthorityInterface;

/**
 * The conservative answer, and the one the plugin ships with.
 *
 * A verified contact may send a change and may see it published by somebody else,
 * but the change itself waits for a dean or a reviewer like anything else. This is
 * the default because #200 — "may a verified contact publish a change, or must it
 * still be reviewed?" — is still an open owner decision, and the safe answer to an
 * unanswered question about who may publish to the public is "nobody extra".
 *
 * Swapping in VerifiedContactPublicationAuthority is a one-argument change at the
 * construction site in Plugin::publisher(); nothing else in the system has to know
 * which policy is in force, and no fixture records which one was used.
 */
final class ReviewRequiredPublicationAuthority implements PublicationAuthorityInterface
{
    private ContactSenderResolver $resolver;

    public function __construct(?ContactSenderResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new ContactSenderResolver();
    }

    /**
     * Refuses every contact change, without exception and without consulting the
     * sender. The sender argument is deliberately unused: a policy that has already
     * decided is one that must not be tempted by the answer.
     */
    public function allowsContactChange(array $row, SenderLookupResult $sender): bool
    {
        return false;
    }

    /**
     * Still resolves who sent it. It costs nothing while the answer is always no,
     * and it means the day the owner flips the decision the sender has been arriving
     * correctly all along — one line changes and nothing else has to be rewritten.
     */
    public function resolveSender(array $row, ParishContactStoreInterface $contacts): SenderLookupResult
    {
        return $this->resolver->resolveSender($row, $contacts);
    }
}