<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Attachments;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\Core\Ports\SourceMaterialCopierInterface;
use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
use RuntimeException;

/**
 * A copier that copies in memory, recording what it was asked to do.
 *
 * It also refuses on demand, so the "a failed promotion changes nothing" rule
 * can be exercised without touching a filesystem.
 */
final class RecordingSourceMaterialCopier implements SourceMaterialCopierInterface
{
    /** @var list<array{event: int, storage: string, original: string, role: string}> */
    public array $copied = [];

    /** @var list<array{event: int, attachment: int}> */
    public array $detached = [];

    /** @var list<array{event: int, attachment: int}> */
    public array $featured = [];

    public int $nextAttachmentId = 1000;

    public ?RuntimeException $copyFailure = null;

    public bool $detachReturnsTrue = true;

    public bool $featuredReturnsTrue = true;

    public function copyIntoMediaLibrary(
        int $eventId,
        string $storageName,
        string $originalName,
        string $role
    ): int {
        if ($this->copyFailure !== null) {
            throw $this->copyFailure;
        }

        $this->copied[] = [
            'event' => $eventId,
            'storage' => $storageName,
            'original' => $originalName,
            'role' => $role,
        ];

        return $this->nextAttachmentId++;
    }

    public function detachFromEvent(int $eventId, int $attachmentId): bool
    {
        $this->detached[] = ['event' => $eventId, 'attachment' => $attachmentId];

        return $this->detachReturnsTrue;
    }

    public function setFeaturedImage(int $eventId, int $attachmentId): bool
    {
        $this->featured[] = ['event' => $eventId, 'attachment' => $attachmentId];

        return $this->featuredReturnsTrue;
    }
}

/**
 * A promotion store held in memory.
 */
final class InMemorySourceMaterialStore implements SourceMaterialStoreInterface
{
    /** @var array<int, list<SourceMaterialReference>> */
    public array $stored = [];

    public int $replaceCalls = 0;

    public function forEvent(int $eventId): array
    {
        return $this->stored[$eventId] ?? [];
    }

    public function replaceForEvent(int $eventId, array $references): void
    {
        $this->replaceCalls++;
        $this->stored[$eventId] = array_values($references);
    }
}