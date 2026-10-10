<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use InvalidArgumentException;

/**
 * The one intake-attachment question SourceMaterialStoreInterface asks.
 *
 * The WordPress implementation is a concrete repository over a plugin table,
 * and a promotion needs three fields from one row by id: the parish's filename,
 * the declared MIME type and the private storage path. Anything beyond that
 * -- the extracted text, the OCR status, the content hash -- is not needed to
 * decide whether a file may be copied, and every extra method would be another
 * thing a promotion could reach for by mistake.
 *
 * A narrow port also keeps the adapter testable without standing up a database
 * double, which matters here because the promotion path is filesystem work and
 * the filesystem is what those tests are really about.
 */
interface IntakeAttachmentReaderInterface
{
    /**
     * One stored attachment by id, or null when there is no such row.
     *
     * @return array{
     *     id: int,
     *     filename: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     storage_path: string
     * }|null
     * @throws InvalidArgumentException when the id is not positive.
     */
    public function findStoredById(int $attachmentId): ?array;

    /**
     * The promotable files that arrived with one message, in the order they
     * arrived.
     *
     * This exists for the *admin* side of #172 -- the two screens that offer a
     * promotion -- and is the only way they learn what is on offer. It is
     * deliberately not reachable from `SourceMaterialStoreInterface::forEvent()`,
     * which is the front end's read, because that one must only ever return what
     * has already been promoted. Two different questions, two different methods:
     * folding them together is exactly how unpromoted material becomes reachable.
     *
     * Files that cannot be promoted are included, with their declared MIME type,
     * so a screen can say *why* a file is not on offer. The allowlist lives in
     * {@see \ADCT\ParishIntake\Core\Publishing\SourceAttachment::isPromotableMimeType()}
     * and is applied there, not here.
     *
     * @return list<array{id: int, filename: string, mime_type: string, size_bytes: int, storage_path: string}>
     */
    public function findPromotableForMessage(int $messageId): array;
}
