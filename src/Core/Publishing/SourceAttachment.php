<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Publishing;

use DomainException;

/**
 * One promoted piece of an event's source material.
 *
 * This is the whole of #172's promotion state: an event post, an ordered list of
 * these, and nothing else. There is deliberately no "pending promotion" state and
 * no path, because a media-library copy exists only once a person has asked for
 * one (ADR 0024). Anything that has not been promoted has no object here and no
 * route to a public page.
 *
 * `originalFilename` is parish-supplied and therefore untrusted. It is carried
 * because a reviewer has to recognise the file they sent, and it is rendered
 * only through `esc_html()`. It is not a path and must never become one: no
 * method on this object derives a file name, a directory or a URL, and the copy
 * that does generate a stored name is a WordPress adapter, not this class.
 */
final class SourceAttachment
{
    public const ROLE_POSTER = 'poster';
    public const ROLE_BULLETIN = 'bulletin';
    public const ROLE_DOCUMENT = 'document';

    /**
     * @var list<string>
     */
    public const ROLES = [self::ROLE_POSTER, self::ROLE_BULLETIN, self::ROLE_DOCUMENT];

    /**
     * The MIME types a person may promote, and the role each one defaults to.
     *
     * This is narrower than `AttachmentStoragePolicy::ALLOWED_MIME_TYPES` on
     * purpose. HEIC and HEIF pass storage because an operator may need to open
     * the file to read it, but no browser renders them: publishing one would
     * produce a source-material link that is broken for every visitor who
     * clicks it. They are therefore refused here, where the refusal is one
     * tested method, rather than at each of the three places that offer a
     * promote button.
     *
     * @var array<string, string>
     */
    public const PROMOTABLE_MIME_TYPES = [
        'application/pdf' => self::ROLE_BULLETIN,
        'image/jpeg' => self::ROLE_POSTER,
        'image/png' => self::ROLE_POSTER,
        'image/webp' => self::ROLE_POSTER,
    ];

    /**
     * @param int $attachmentId the `adct_pi_attachments` row this came from, so a
     *        POPIA enquiry can be answered from the audit trail alone.
     * @param int $mediaId the WordPress attachment created for the event.
     * @param string $role one of self::ROLES. A closed set, so a posted string
     *        cannot become an arbitrary meta value every reader then has to
     *        interpret.
     * @param string $originalFilename the parish's own name, kept as text and
     *        escaped on render. Never a path.
     */
    public function __construct(
        public readonly int $attachmentId,
        public readonly int $mediaId,
        public readonly string $role,
        public readonly string $originalFilename
    ) {
        if ($attachmentId < 1) {
            throw new DomainException('A source attachment needs an intake attachment id.');
        }

        if ($mediaId < 1) {
            throw new DomainException('A source attachment needs a media library id.');
        }

        if (! in_array($role, self::ROLES, true)) {
            throw new DomainException('A source attachment needs a known role.');
        }
    }

    public function isPoster(): bool
    {
        return $this->role === self::ROLE_POSTER;
    }

    public static function isPromotableMimeType(string $mimeType): bool
    {
        return isset(self::PROMOTABLE_MIME_TYPES[strtolower(trim($mimeType))]);
    }

    /**
     * The role an offer to promote this type should default to.
     *
     * A poster is the default for images and a bulletin for a PDF because that
     * is what each one is nearly always; a person changing a default is
     * changing nothing about whether promotion happens.
     */
    public static function defaultRoleFor(string $mimeType): string
    {
        return self::PROMOTABLE_MIME_TYPES[strtolower(trim($mimeType))] ?? self::ROLE_DOCUMENT;
    }

    /**
     * The exact entry stored in the event's ordered `source_attachment_ids`
     * meta. Held here so the writer and the reader cannot disagree about it.
     *
     * `original_filename` is included for display only. It is re-escaped on
     * every render and never used to build a path.
     *
     * @return array{media_id: int, role: string, original_filename: string, attachment_id: int}
     */
    public function toMetaEntry(): array
    {
        return [
            'media_id' => $this->mediaId,
            'role' => $this->role,
            'original_filename' => $this->originalFilename,
            'attachment_id' => $this->attachmentId,
        ];
    }
}