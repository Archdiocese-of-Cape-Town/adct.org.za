<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Attachments;

use ADCT\ParishIntake\Core\Ports\SourceMaterialCopierInterface;
use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
use RuntimeException;

/**
 * Promotes, demotes and reads an event's source material (issue #172).
 *
 * The rules that matter are all here rather than in a screen handler, because
 * two very different surfaces (the review queue and the event editor) have to
 * obey the same ones:
 *
 * - **Nothing is promoted without a person saying so.** There is no default
 *   list, no "promote the first attachment" behaviour and no configuration
 *   switch that promotes anything. A call with no candidate role is a refusal.
 * - **Promotion is a copy into the media library, at that moment.** There is no
 *   "stored but not yet public" state, so the public renderer cannot show a
 *   file nobody approved.
 * - **At most one poster.** Two featured images would fight, so a second poster
 *   replaces the first and the first keeps its own slot as a document.
 * - **Removal never deletes.** It detaches, clears the featured image and drops
 *   the reference, leaving the file and the intake row alone.
 * - **A failed promotion changes nothing.** The copy is attempted before the
 *   store is touched, so a failed promotion leaves the previous list intact.
 */
final class SourceMaterialPromotion
{
    public function __construct(
        private readonly SourceMaterialCopierInterface $copier,
        private readonly SourceMaterialStoreInterface $store
    ) {
    }

    /**
     * The event's promoted source material, in the stored order.
     *
     * @return list<SourceMaterialReference>
     */
    public function forEvent(int $eventId): array
    {
        if ($eventId < 1) {
            throw new RuntimeException('An event id must be positive.');
        }

        return $this->store->forEvent($eventId);
    }

    /**
     * Copy a stored intake attachment into the media library and record it.
     *
     * @param string $storageName the intake store's own file name
     * @param string $originalName the parish's filename, metadata only
     * @param string $mimeType the stored attachment's declared type
     * @param string $role the role the reviewer chose
     * @return SourceMaterialReference the new, recorded reference
     * @throws RuntimeException when the promotion could not be completed
     */
    public function promote(
        int $eventId,
        string $storageName,
        string $originalName,
        string $mimeType,
        string $role
    ): SourceMaterialReference {
        if ($eventId < 1) {
            throw new RuntimeException('An event id must be positive.');
        }

        if (! SourceMaterialRole::allows($role, $mimeType)) {
            throw new RuntimeException('That file cannot be promoted in that role.');
        }

        // Copied before anything is recorded, so a failed copy leaves the
        // event's existing list exactly as it was.
        $attachmentId = $this->copier->copyIntoMediaLibrary(
            $eventId,
            $storageName,
            $originalName,
            $role
        );

        if ($attachmentId < 1) {
            throw new RuntimeException('The source material could not be copied.');
        }

        $reference = new SourceMaterialReference($attachmentId, $role, $originalName);

        $this->store->replaceForEvent(
            $eventId,
            $this->insertAfterPoster($this->store->forEvent($eventId), $reference, $role)
        );

        if ($role === SourceMaterialRole::POSTER) {
            $this->copier->setFeaturedImage($eventId, $attachmentId);
        }

        return $reference;
    }

    /**
     * Drop one promoted item from the public side.
     *
     * The file and the attachment row stay: the raw file is evidence and the
     * retention settings own it, so removal here is a visibility change that
     * re-promoting can undo.
     *
     * @throws RuntimeException when the removal could not be completed
     */
    public function remove(int $eventId, int $attachmentId): void
    {
        if ($eventId < 1 || $attachmentId < 1) {
            throw new RuntimeException('A source material removal needs an event and an attachment.');
        }

        $remaining = array_values(array_filter(
            $this->store->forEvent($eventId),
            static fn (SourceMaterialReference $reference): bool => $reference->attachmentId !== $attachmentId
        ));

        $this->store->replaceForEvent($eventId, $remaining);

        // The copier releases the featured image itself, and only if this
        // attachment was the event's thumbnail. Asking it here would mean
        // teaching the port a second question about WordPress state that only
        // WordPress can answer.
        $this->copier->detachFromEvent($eventId, $attachmentId);
    }

    /**
     * The remaining references with `$reference` added.
     *
     * A new poster goes first, because the front end shows the poster and a
     * page whose poster is the second item in the list is wrong. A new
     * document or bulletin goes after every poster, so the list a reviewer
     * already arranged is not reshuffled by an unrelated addition.
     *
     * @param list<SourceMaterialReference> $existing
     * @return list<SourceMaterialReference>
     */
    private function insertAfterPoster(
        array $existing,
        SourceMaterialReference $reference,
        string $role
    ): array {
        if ($role !== SourceMaterialRole::POSTER) {
            return [...$existing, $reference];
        }

        // A second poster would fight the first for the featured image, so the
        // previous poster keeps its file but loses the role.
        $demoted = array_map(
            static function (SourceMaterialReference $current): SourceMaterialReference {
                return $current->isPoster()
                    ? new SourceMaterialReference(
                        $current->attachmentId,
                        SourceMaterialRole::DOCUMENT,
                        $current->originalName
                    )
                    : $current;
            },
            $existing
        );

        return [$reference, ...$demoted];
    }
}