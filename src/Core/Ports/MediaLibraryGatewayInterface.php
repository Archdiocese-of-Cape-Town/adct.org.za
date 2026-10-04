<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use RuntimeException;

/**
 * The media library calls a promotion needs, and nothing else.
 *
 * Wrapping these four WordPress functions in a port does two jobs. It keeps
 * `src/Core` free of WordPress, as ADR 0011's layering requires, and it draws a
 * line around the part of this feature that performs I/O: a promotion writes a
 * file and creates a post, so it must never be reached from inside a database
 * transaction, and the fact that it cannot join one is visible here rather than
 * buried in an implementation.
 *
 * There is no update, no delete and no listing method. A removal is a change of
 * visibility, handled by StoredAttachmentEventMetaInterface; a copy that
 * somebody genuinely regrets is removed from the media library by a person
 * through the library itself, which keeps its own audit trail.
 */
interface MediaLibraryGatewayInterface
{
    /**
     * Copy a file already on this server into the uploads directory and create
     * the attachment that points at it.
     *
     * The caller supplies `$file['name']` as the name the copy must be stored
     * under. An implementation must never substitute the original filename: the
     * original is attacker-controlled, it is what `wp_unique_filename()` would
     * otherwise derive the stored name from, and a stored name is a path.
     *
     * Implementations must not leave a file behind when they return a failure,
     * and must not return an attachment id below 1.
     *
     * @param array<string, mixed> $file    the source file, with 'name' already set to the stored name
     * @param array<string, mixed> $overrides WordPress upload overrides; 'test_type' must be supplied
     * @return array{id: int, file: string, url: string, type: string}
     * @throws RuntimeException when the copy cannot be made. The implementation
     *         is responsible for removing any partial file first.
     */
    public function sideload(array $file, array $overrides): array;
}
