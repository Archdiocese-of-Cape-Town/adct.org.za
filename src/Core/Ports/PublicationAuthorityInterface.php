<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Directory\SenderLookupResult;

/**
 * Decides whether a candidate a parish contact submitted may publish itself.
 *
 * ADR 0008 point 4 promises that a change from a verified parish contact
 * publishes immediately, and a change from an unknown sender is treated like a
 * new event and needs approval. Whether that promise is the archdiocese's
 * standing policy is still an open owner decision (issue #200), so the answer
 * is injected here rather than written into CandidatePublisher: flipping it
 * must be a one-line construction change, not a rewrite of the trust boundary.
 *
 * Implementations must answer the question from the candidate row and a live
 * trust lookup, never from the persisted approved_via label. docs/data-model.md
 * is explicit that "contact_change" on its own is not proof of a verified
 * contact: the label is written by whichever code path set the candidate, and
 * it stays behind if the verification is later withdrawn.
 */
interface PublicationAuthorityInterface
{
    /**
     * Whether a candidate labelled approved_via = contact_change may publish
     * without a dean, reviewer or submitter approval.
     *
     * @param array<string, mixed> $row the locked candidate row
     */
    public function allowsContactChange(array $row, SenderLookupResult $sender): bool;

    /**
     * The sender named by approved_by, looked up in the parish directory now.
     *
     * A contact-change row records who sent it in approved_by, and that address is
     * the only thing that can be checked against a live trust decision, so this
     * re-resolves it at act time rather than trusting a column that was written when
     * the candidate was created.
     *
     * A blank, oversized or unparseable address resolves to UNKNOWN with no parishes
     * rather than raising: a candidate nobody can be shown to be authorised for has
     * no verified contact behind it, and refusing it is the answer the caller wants.
     *
     * @param array<string, mixed> $row the locked candidate row
     */
    public function resolveSender(array $row, ParishContactStoreInterface $contacts): SenderLookupResult;
}
