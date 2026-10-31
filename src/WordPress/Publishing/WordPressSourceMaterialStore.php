<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Publishing;

use ADCT\ParishIntake\Core\Audit\AuditAction;
use ADCT\ParishIntake\Core\Audit\AuditSubjectType;
use ADCT\ParishIntake\Core\Audit\AuditWriter;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\IntakeAttachmentReaderInterface;
use ADCT\ParishIntake\Core\Ports\MediaLibraryGatewayInterface;
use ADCT\ParishIntake\Core\Ports\SourceMaterialStoreInterface;
use ADCT\ParishIntake\Core\Ports\StoredAttachmentEventMetaInterface;
use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use ADCT\ParishIntake\WordPress\Audit\ActorResolver;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * The WordPress adapter behind SourceMaterialStoreInterface (issue #172,
 * ADR 0025).
 *
 * Three properties of this class are the feature, and each is asserted by a
 * test that a plausible shortcut would break:
 *
 *  - Publication reaches this adapter only after commit (issue #252). Manual
 *    recovery and removal keep their separate capability and nonce checks.
 *  - The stored name is generated here and nowhere else. Parish filenames are
 *    attacker-controlled and a stored name is a path component, so the name
 *    handed to the media library is
 *    `adct-source-<event>-<attachment>-<random>.<ext>` with the extension taken
 *    from the *declared* MIME type rather than from the name. The parish's own
 *    name survives only as escaped display text.
 *  - `forEvent()` reads the event's ordered meta and nothing else. Unpromoted
 *    material has no object and therefore no route to a public page, even
 *    though WordPress records the copy's parent for us.
 *
 * There is no database handle in the constructor, on purpose.
 * `WordPressPublicationStore::publish()` opens a transaction, and a rollback
 * cannot undo a file that has already been written, so this class is never
 * constructed with a way to join one. A promotion that fails raises a
 * DomainException; the event stays published with no source material and the
 * caller reports the failure.
 */
final class WordPressSourceMaterialStore implements SourceMaterialStoreInterface
{
    public const ERROR_META = '_adct_pi_source_material_errors';
    public const RECOVERY_META = '_adct_pi_source_material_recovery';
    private const HISTORY_PREFIX = '_adct_pi_source_material_attachment_';

    /**
     * The stored name's shape, recorded as one string rather than assembled at
     * the call site so a reviewer can check the whole format in one place.
     */
    private const STORED_NAME_PREFIX = 'adct-source-';

    /**
     * Characters of randomness in a stored name. Sixteen hex characters is
     * enough that two promotions of the same attachment cannot collide.
     */
    private const STORED_NAME_RANDOM_BYTES = 8;

    /**
     * The extension per MIME type, taken from the declared type and never from
     * the parish's filename. Written out rather than derived so that adding a
     * type is a reviewable edit, and so that no MIME type can reach a path
     * component by accident.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * The storage size cap applied at promotion. `AttachmentStoragePolicy`
     * caps intake storage; the same cap is restated where the copy happens,
     * because a copy that exceeded it would be a file the uploads directory was
     * never sized for.
     */
    private const MAX_UPLOAD_BYTES = 20971520;

    public function __construct(
        private readonly IntakeAttachmentReaderInterface $attachments,
        private readonly InboundMailStorageReaderInterface $storage,
        private readonly MediaLibraryGatewayInterface $media,
        private readonly StoredAttachmentEventMetaInterface $eventMeta,
        private readonly ActorResolver $actorResolver,
        private readonly AuditWriter $audit,
        private readonly SourceMaterialLock $lock
    ) {
    }

    public function promote(int $eventId, int $attachmentId, string $role): SourceAttachment
    {
        $this->assertPositive($eventId, 'An event id must be positive.');
        return $this->lock->run($eventId, function () use ($eventId, $attachmentId, $role): SourceAttachment {
            foreach ($this->forEvent($eventId) as $source) {
                if ($source->attachmentId === $attachmentId) {
                    $failures = self::failureRecords($eventId);
                    if (isset($failures[$attachmentId])) {
                        unset($failures[$attachmentId]);
                        $this->saveFailureRecords($eventId, $failures);
                        return $source;
                    }
                    throw new DomainException('That file is already published with this event.');
                }
            }
            $source = $this->promoteWithActor($eventId, $attachmentId, $role, $this->actorResolver->actor());
            $failures = self::failureRecords($eventId);
            if (isset($failures[$attachmentId])) {
                unset($failures[$attachmentId]);
                $this->saveFailureRecords($eventId, $failures);
            }
            return $source;
        });
    }

    public function publishForEvent(int $eventId, int $messageId, string $actor, int $candidateId): void
    {
        $this->assertPositive($eventId, 'An event id must be positive.');
        $this->assertPositive($messageId, 'A message id must be positive.');
        $this->assertPositive($candidateId, 'A candidate id must be positive.');
        if (trim($actor) === '') {
            throw new InvalidArgumentException('Automatic source publication needs the recorded approver.');
        }

        $this->lock->run($eventId, function () use ($eventId, $messageId, $actor, $candidateId): void {
            if (get_post_status($eventId) !== 'publish'
                || (int) get_post_meta($eventId, 'source_candidate_id', true) !== $candidateId) {
                return;
            }
            $this->publishUnlocked($eventId, $messageId, $actor, $candidateId);
        });
    }

    private function publishUnlocked(int $eventId, int $messageId, string $actor, int $candidateId): void
    {
        $failures = self::failureRecords($eventId);
        foreach ($this->attachments->findPromotableForMessage($messageId) as $row) {
            $id = (int) $row['id'];
            $mime = (string) $row['mime_type'];
            if (! SourceAttachment::isPromotableMimeType($mime)) {
                continue;
            }
            // History survives removal: a cache repair must not undo a human's
            // decision, even when the same message supplied several candidates.
            if (get_post_meta($eventId, self::HISTORY_PREFIX . $id, true) !== '') {
                unset($failures[$id]);
                continue;
            }
            $sources = $this->forEvent($eventId);
            if (array_filter($sources, static fn (SourceAttachment $source): bool => $source->attachmentId === $id)) {
                unset($failures[$id]);
                continue;
            }
            $role = SourceAttachment::defaultRoleFor($mime);
            if ($role === SourceAttachment::ROLE_POSTER && get_post_thumbnail_id($eventId) > 0) {
                $role = SourceAttachment::ROLE_DOCUMENT;
            }
            try {
                $this->promoteWithActor($eventId, $id, $role, $actor, $candidateId);
                unset($failures[$id]);
            } catch (Throwable $failure) {
                $failures[$id] = [
                    'warning' => 'Source file ' . $id . ' could not be published. Retry from the event editor.',
                    'message_id' => $messageId,
                    'candidate_id' => $candidateId,
                ];
                error_log(sprintf(
                    'ADCT Parish Intake: source publication failed for event %d, candidate %d, attachment %d (%s).',
                    $eventId, $candidateId, $id, $failure::class
                ));
            }
        }
        $this->saveFailureRecords($eventId, $failures);
    }

    /**
     * @return array<int, array{warning: string, message_id: int, candidate_id: int}>
     */
    public static function failureRecords(int $eventId): array
    {
        $stored = get_post_meta($eventId, self::ERROR_META, true);
        $legacy = get_post_meta($eventId, self::RECOVERY_META, true);
        $records = [];
        foreach (is_array($stored) ? $stored : [] as $id => $record) {
            // Read the former split layout without discarding persistent failures.
            if (is_string($record)) {
                $record = ['warning' => $record] + (is_array($legacy[$id] ?? null) ? $legacy[$id] : []);
            }
            if ((int) $id > 0 && is_array($record) && is_string($record['warning'] ?? null)) {
                $records[(int) $id] = [
                    'warning' => $record['warning'],
                    'message_id' => (int) ($record['message_id'] ?? 0),
                    'candidate_id' => (int) ($record['candidate_id'] ?? 0),
                ];
            }
        }
        return $records;
    }

    private function saveFailureRecords(int $eventId, array $records): void
    {
        update_post_meta($eventId, self::ERROR_META, wp_slash($records));
        if (get_post_meta($eventId, self::ERROR_META, true) !== $records) {
            throw new \RuntimeException(
                'The event source recovery record could not be saved. Publication is unchanged; retry the operation.'
            );
        }
    }

    private function promoteWithActor(
        int $eventId,
        int $attachmentId,
        string $role,
        string $actor,
        ?int $candidateId = null
    ): SourceAttachment
    {
        $this->assertPositive($eventId, 'An event id must be positive.');
        $this->assertPositive($attachmentId, 'An attachment id must be positive.');

        if (! in_array($role, SourceAttachment::ROLES, true)) {
            throw new DomainException('A source attachment needs a known role.');
        }

        $row = $this->attachments->findStoredById($attachmentId);
        if ($row === null) {
            throw new DomainException('That intake attachment does not exist.');
        }

        $mimeType = strtolower(trim((string) ($row['mime_type'] ?? '')));
        if (! isset(self::EXTENSIONS[$mimeType])) {
            throw new DomainException(sprintf(
                '%s cannot be published as source material.',
                $mimeType === '' ? 'An attachment of unknown type' : $mimeType
            ));
        }

        $storedPath = trim((string) ($row['storage_path'] ?? ''));
        if ($storedPath === '') {
            throw new DomainException('That intake attachment has no stored file to copy.');
        }

        $existing = $this->eventMeta->readSourceAttachmentIds($eventId);
        $previousThumbnail = get_post_thumbnail_id($eventId);
        $historyKey = self::HISTORY_PREFIX . $attachmentId;
        $previousHistory = get_post_meta($eventId, $historyKey, true);
        $storedName = $this->storedName($eventId, $attachmentId, $mimeType);
        $filename = (string) ($row['filename'] ?? '');

        // The copy, then the record, and nothing else between them: if the copy
        // fails there is no record, and if the record cannot be written the copy
        // is taken back off disk below.
        $copy = $this->copy(
            $storedName,
            $mimeType,
            $storedPath,
            (int) ($row['size_bytes'] ?? 0),
            $filename
        );

        try {
            $source = new SourceAttachment($attachmentId, $copy['id'], $role, $filename);
            $this->attach($eventId, $source, $existing, $storedName, $actor, $candidateId);
        } catch (Throwable $failure) {
            // The bytes are on disk with nothing pointing at them, which is the
            // orphan the design forbids. Undo the copy so a retry starts clean.
            try {
                $this->eventMeta->writeSourceAttachmentIds($eventId, $existing);
                if ($previousThumbnail > 0) {
                    $this->eventMeta->setFeaturedImage($eventId, $previousThumbnail);
                } else {
                    $this->eventMeta->clearFeaturedImage($eventId, $copy['id']);
                }
                if ($previousHistory === '') {
                    delete_post_meta($eventId, $historyKey);
                } else {
                    update_post_meta($eventId, $historyKey, $previousHistory);
                }
            } finally {
                wp_delete_attachment($copy['id'], true);
                $this->discard($copy['file']);
            }

            throw $failure;
        }

        return $source;
    }

    public function remove(int $eventId, int $mediaId): void
    {
        $this->assertPositive($eventId, 'An event id must be positive.');
        $this->assertPositive($mediaId, 'A media library id must be positive.');
        $this->lock->run($eventId, fn () => $this->removeUnlocked($eventId, $mediaId));
    }

    private function removeUnlocked(int $eventId, int $mediaId): void
    {
        $entries = $this->eventMeta->readSourceAttachmentIds($eventId);
        $kept = [];
        $removed = null;

        foreach ($entries as $entry) {
            if ((int) $entry['media_id'] === $mediaId) {
                $removed = $entry;
                continue;
            }

            $kept[] = $entry;
        }

        if ($removed === null) {
            throw new DomainException('That item is not published with this event.');
        }

        $historyKey = self::HISTORY_PREFIX . (int) $removed['attachment_id'];
        update_post_meta($eventId, $historyKey, $mediaId);
        if ((int) get_post_meta($eventId, $historyKey, true) !== $mediaId) {
            throw new \RuntimeException('Source removal history could not be saved. The file remains published; retry removal.');
        }

        $previousThumbnail = get_post_thumbnail_id($eventId);
        try {
            if ((string) $removed['role'] === SourceAttachment::ROLE_POSTER) {
                $this->eventMeta->clearFeaturedImage($eventId, $mediaId);
            }
            $this->eventMeta->writeSourceAttachmentIds($eventId, $kept);

            // Removal changes visibility, not the retained public media bytes.
            $this->writeAuditRow(
                $eventId,
                new SourceAttachment(
                    (int) $removed['attachment_id'],
                    $mediaId,
                    (string) $removed['role'],
                    (string) $removed['original_filename']
                ),
                false,
                null
            );
        } catch (Throwable $failure) {
            try {
                $this->eventMeta->writeSourceAttachmentIds($eventId, $entries);
            } finally {
                if ($previousThumbnail > 0) {
                    $this->eventMeta->setFeaturedImage($eventId, $previousThumbnail);
                }
            }
            throw $failure;
        }
    }

    public function forEvent(int $eventId): array
    {
        $this->assertPositive($eventId, 'An event id must be positive.');

        $sources = [];
        foreach ($this->eventMeta->readSourceAttachmentIds($eventId) as $entry) {
            try {
                $sources[] = new SourceAttachment(
                    (int) $entry['attachment_id'],
                    (int) $entry['media_id'],
                    (string) $entry['role'],
                    (string) $entry['original_filename']
                );
            } catch (DomainException) {
                // The reader has already rejected anything untrustworthy, so
                // this is belt and braces: one bad entry must not stop the rest
                // of an event's source material from rendering.
                continue;
            }
        }

        return $sources;
    }

    /**
     * Write the ordered record, the parent, the featured image and the audit row.
     *
     * `post_parent` is the one part of a promotion that is cheap to undo, so it
     * is set here and nothing else touches the attachment post.
     *
     * @param list<array<string, mixed>> $existing
     */
    private function attach(
        int $eventId,
        SourceAttachment $source,
        array $existing,
        string $storedName,
        string $actor,
        ?int $candidateId
    ): void
    {
        $entries = [];
        foreach ($existing as $entry) {
            if ((int) $entry['media_id'] === $source->mediaId) {
                continue;
            }

            $entries[] = $entry;
        }

        // One poster at a time: promoting a second poster releases the first,
        // because two featured images is not a state the event page can render.
        if ($source->isPoster()) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => (string) $entry['role'] !== SourceAttachment::ROLE_POSTER
            ));
        }

        $entries[] = $source->toMetaEntry();

        $this->eventMeta->writeSourceAttachmentIds($eventId, $entries);
        $parented = wp_update_post(['ID' => $source->mediaId, 'post_parent' => $eventId], true);
        if (! is_int($parented) || $parented !== $source->mediaId) {
            throw new \RuntimeException('The source material could not be parented to the event.');
        }

        if ($source->isPoster()) {
            $this->eventMeta->setFeaturedImage($eventId, $source->mediaId);
        }

        $historyKey = self::HISTORY_PREFIX . $source->attachmentId;
        update_post_meta($eventId, $historyKey, $source->mediaId);
        if ((int) get_post_meta($eventId, $historyKey, true) !== $source->mediaId) {
            throw new \RuntimeException('The source publication history could not be saved.');
        }
        $this->writeAuditRow($eventId, $source, true, $storedName, $actor, $candidateId);
    }

    /**
     * The copy itself.
     *
     * @return array{id: int, file: string, url: string, type: string}
     * @throws DomainException when no copy was made. Nothing is left behind.
     */
    private function copy(
        string $storedName,
        string $mimeType,
        string $storedPath,
        int $size,
        string $parishFilename
    ): array {
        try {
            $sourceFile = $this->storage->resolveAttachmentPath($storedPath);
        } catch (Throwable $failure) {
            throw new DomainException(
                sprintf('The stored intake file for this attachment is unavailable (%s).', $failure->getMessage()),
                0,
                $failure
            );
        }

        if (! is_file($sourceFile)) {
            throw new DomainException('The stored intake file for this attachment is unavailable.');
        }
        $actualSize = filesize($sourceFile);
        if ($actualSize === false || $actualSize < 1 || $actualSize > self::MAX_UPLOAD_BYTES) {
            throw new DomainException('The stored source file is empty or exceeds the publication size limit.');
        }

        try {
            return $this->media->sideload(
                [
                    // The parish's filename is never passed on. WordPress
                    // derives the stored name from this key, and a stored name is
                    // a path component, so it is replaced with one this plugin
                    // generated from ids and the declared type.
                    'name' => $storedName,
                    'tmp_name' => $sourceFile,
                    'size' => $actualSize,
                    'type' => $mimeType,
                    'error' => 0,
                ],
                [
                    // An explicit type, so WordPress does not sniff the parish's
                    // name, and no form, because this is never a browser upload.
                    'test_type' => $mimeType,
                    'test_form' => false,
                    'test_size' => self::MAX_UPLOAD_BYTES,
                ]
            );
        } catch (Throwable $failure) {
            throw new DomainException(
                sprintf(
                    'The source material could not be copied into the media library (%s).',
                    $this->copyFailureReason($failure, $parishFilename)
                ),
                0,
                $failure
            );
        }
    }

    /**
     * WordPress's own refusal names the stored file, which is a generated name
     * the reviewer has never seen. The parish's own name is added so the notice
     * on screen identifies the file they recognise, and is escaped there.
     */
    private function copyFailureReason(Throwable $failure, string $parishFilename): string
    {
        $reason = $failure->getMessage();

        if ($parishFilename === '') {
            return $reason;
        }

        return $reason . ' (' . $parishFilename . ')';
    }

    /**
     * `adct-source-<eventId>-<attachmentId>-<16 hex>.<ext>`
     *
     * Every component is a positive integer this plugin already holds, or
     * randomness it generated. No part comes from the upload, which makes the
     * sanitisation property structural rather than a matter of escaping the
     * right characters.
     */
    private function storedName(int $eventId, int $attachmentId, string $mimeType): string
    {
        return sprintf(
            '%s%d-%d-%s.%s',
            self::STORED_NAME_PREFIX,
            $eventId,
            $attachmentId,
            bin2hex(random_bytes(self::STORED_NAME_RANDOM_BYTES)),
            self::EXTENSIONS[$mimeType]
        );
    }

    /**
     * Take a copied file back off disk after a promotion that could not be
     * recorded. `wp_delete_file()` is WordPress's wrapper around unlink(), so a
     * filter gets its say, and a refusal is logged rather than raised: the
     * caller is already handling an exception and must not be handed a second
     * one from the cleanup.
     */
    private function discard(string $file): void
    {
        if ($file === '' || ! is_file($file)) {
            return;
        }

        wp_delete_file($file);
        if (is_file($file)) {
            error_log(sprintf('ADCT Parish Intake: could not remove the half-promoted copy at %s.', $file));
        }
    }

    private function writeAuditRow(
        int $eventId,
        SourceAttachment $source,
        bool $promoted,
        ?string $storedName,
        ?string $actor = null,
        ?int $candidateId = null
    ): void {
        $details = [
            'attachment_id' => $source->attachmentId,
            'media_id' => $source->mediaId,
            'role' => $source->role,
            'original_filename' => $source->originalFilename,
        ];

        if ($storedName !== null) {
            $details['stored_name'] = $storedName;
        }
        if ($candidateId !== null) {
            $details['candidate_id'] = $candidateId;
            $details['automatic'] = true;
        }

        $this->audit->write(
            $actor ?? $this->actorResolver->actor(),
            $promoted ? AuditAction::SOURCE_MATERIAL_PROMOTED : AuditAction::SOURCE_MATERIAL_REMOVED,
            AuditSubjectType::EVENT,
            $eventId,
            $details
        );
    }

    private function assertPositive(int $value, string $message): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException($message);
        }
    }
}
