<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Attachments;

use InvalidArgumentException;

/**
 * One entry of an event's ordered promotion meta: a media-library attachment
 * id and the role it plays (issue #172).
 *
 * The id is a WordPress attachment id, never an intake-store row id, so the
 * public side can only ever name a file that was deliberately promoted. That is
 * the whole point of promoting into the media library at the moment of the
 * decision rather than copying at intake time: there is no "copied but not yet
 * public" state to get wrong.
 */
final class SourceMaterialReference
{
    public function __construct(
        public readonly int $attachmentId,
        public readonly string $role,
        /** The parish's own filename, kept as metadata and escaped on render. */
        public readonly string $originalName = ''
    ) {
        if ($attachmentId < 1) {
            throw new InvalidArgumentException('A source material attachment id must be positive.');
        }

        if (! in_array($role, SourceMaterialRole::values(), true)) {
            throw new InvalidArgumentException('The source material role is not known.');
        }
    }

    public function isPoster(): bool
    {
        return $this->role === SourceMaterialRole::POSTER;
    }

    /**
     * The entry as it is stored in the ordered promotion meta.
     *
     * Stored as an array of single-key objects rather than a bare list of ids,
     * because a bare list cannot carry the role and parentage cannot either.
     *
     * @return array{attachment_id: int, role: string, name: string}
     */
    public function toArray(): array
    {
        return [
            'attachment_id' => $this->attachmentId,
            'role' => $this->role,
            'name' => $this->originalName,
        ];
    }

    /**
     * Decodes the stored promotion meta into references, dropping anything
     * unusable rather than refusing the whole list.
     *
     * A single corrupt entry must not be able to hide every other source file
     * on the page, so each entry is validated on its own and skipped if it does
     * not hold up. Duplicated ids collapse to their first occurrence, so a
     * hand-edited meta cannot list the same file twice.
     *
     * @param mixed $stored the raw post meta value
     * @return list<self>
     */
    public static function listFromStored(mixed $stored): array
    {
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($stored) || ! array_is_list($stored)) {
            return [];
        }

        $references = [];
        $seen = [];

        foreach ($stored as $entry) {
            if (is_int($entry)) {
                $entry = ['attachment_id' => $entry, 'role' => SourceMaterialRole::DOCUMENT];
            }

            if (! is_array($entry)) {
                continue;
            }

            $attachmentId = $entry['attachment_id'] ?? $entry['id'] ?? null;
            $role = SourceMaterialRole::fromInput($entry['role'] ?? null);
            $name = $entry['name'] ?? $entry['original_name'] ?? '';

            if (! is_numeric($attachmentId) || $role === null) {
                continue;
            }

            $id = (int) $attachmentId;

            if ($id < 1 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $references[] = new self($id, $role, is_scalar($name) ? (string) $name : '');
        }

        return $references;
    }

    /**
     * Re-encodes references for `update_post_meta()`.
     *
     * @param list<self> $references
     * @return list<array{attachment_id: int, role: string, name: string}>
     */
    public static function encodeStored(array $references): array
    {
        return array_values(array_map(
            static fn (self $reference): array => $reference->toArray(),
            $references
        ));
    }
}