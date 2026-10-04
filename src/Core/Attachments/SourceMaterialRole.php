<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Attachments;

use InvalidArgumentException;

/**
 * What a promoted source file is *for* on the public event page.
 *
 * Parentage alone cannot express this: an event can have a poster and the
 * bulletin it was read from, and both hang off the same event post. The role
 * travels with the attachment id in the ordered promotion meta, so the front
 * end knows which one to embed and which one to offer as a link.
 *
 * The list is closed on purpose. HEIC/HEIF are deliberately absent: they pass
 * the intake storage allowlist but render in no browser, so a promoted copy of
 * one would be a dead link on the public page (issue #172).
 */
final class SourceMaterialRole
{
    /** The event's image, shown on the listing card and the event page. */
    public const POSTER = 'poster';

    /** The parish bulletin the event was read from, offered as a download. */
    public const BULLETIN = 'bulletin';

    /** Any other promoted source file: a timetable, a permissions slip. */
    public const DOCUMENT = 'document';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::POSTER, self::BULLETIN, self::DOCUMENT];
    }

    /**
     * Whether the role can be granted to a stored attachment of this MIME type.
     *
     * A poster is an image or nothing. A bulletin is a PDF or nothing. A
     * document is anything on the promotion allowlist. Deciding it here, from
     * the declared type alone, is what keeps the admin screens, the REST route
     * and the editor from disagreeing about what may be promoted.
     */
    public static function allows(string $role, string $mimeType): bool
    {
        $mime = strtolower(trim($mimeType));

        return match ($role) {
            self::POSTER => in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true),
            self::BULLETIN => $mime === 'application/pdf',
            self::DOCUMENT => in_array($mime, [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ], true),
            default => false,
        };
    }

/**
     * The roles this MIME type could actually be given, in the order a form
     * should offer them.
     *
     * Drives the reviewer-facing select directly from {@see allows()}, so a
     * form can never offer a role the handler would refuse — the two cannot
     * drift apart, because there is only one list.
     *
     * @return list<string>
     */
    public static function rolesFor(string $mimeType): array
    {
        // No normalisation here on purpose: allows() already does it, so doing it
        // twice would be a second place for the rule to be wrong.
        return array_values(array_filter(
            self::values(),
            static fn (string $role): bool => self::allows($role, $mimeType)
        ));
    }

    /**
     * The word a reviewer and a visitor see for this role.
     */
    public static function label(string $role): string
{
    return match ($role) {
        self::POSTER => 'Poster',
        self::BULLETIN => 'Parish bulletin',
        self::DOCUMENT => 'Document',
        default => throw new InvalidArgumentException('The source material role is not known.'),
    };
}

    /**
     * Normalises submitted input to a known role, or null.
     *
     * Nothing here throws, because the caller is a form handler: a role that is
     * not recognised is a refused promotion, not a fatal error.
     */
    public static function fromInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = strtolower(trim($value));

        return in_array($candidate, self::values(), true) ? $candidate : null;
    }
}