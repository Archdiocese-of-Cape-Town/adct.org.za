<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Attachments;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Ports\CandidateSourceMessageInterface;
use ADCT\ParishIntake\Core\Ports\PreviewableImageRepositoryInterface;

/**
 * Finds the poster image behind an emailed action-token page (ADR 0017).
 *
 * The action-token pages are public, so there is no capability to check and the
 * live token is the only authorisation. Resolving strictly through the token's
 * own candidate means a token can only ever reach the images of the message it
 * was issued for, which is what stops the image URL being used to enumerate the
 * private store.
 */
final class CandidateSourceImageResolver
{
    private const SUBJECT_TYPE_CANDIDATE = 'event_candidate';

    public function __construct(
        private readonly PreviewableImageRepositoryInterface $images,
        private readonly CandidateSourceMessageInterface $candidates
    ) {
    }

    /**
     * The first browser-readable image attached to the message this token's
     * candidate came from, or null when there is none.
     *
     * A candidate with no source message (a manual or portal entry) has no
     * image, and so has no OCR button.
     */
    public function forBinding(ActionTokenBinding $binding): ?PreviewableImage
    {
        if ($binding->subjectType !== self::SUBJECT_TYPE_CANDIDATE) {
            return null;
        }

        $messageId = $this->candidates->sourceMessageIdForCandidate($binding->subjectId);

        if ($messageId === null) {
            return null;
        }

        foreach ($this->images->forMessage($messageId) as $image) {
            return $image;
        }

        return null;
    }
}
