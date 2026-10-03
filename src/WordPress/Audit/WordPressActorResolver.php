<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Audit;

use Closure;
use InvalidArgumentException;

/**
 * Resolves the signed-in user's address for the audit trail's actor column.
 *
 * The column holds an email address at every write site, including the older
 * ones that insert their own rows inside a transaction. One shape of value in
 * one column is what makes the screen's "who did this" filter work at all, so
 * this is the only place that decides the answer.
 */
final class WordPressActorResolver implements ActorResolver
{
    /**
     * @param Closure(): (string|null)|null $reader Reads the signed-in user.
     *        Injected so this can be built without WordPress loaded and so the
     *        fallback is testable; null uses WordPress itself.
     */
    public function __construct(private readonly ?Closure $reader = null)
    {
    }

    /**
     * A signed-in user with no address on record is recorded as the system
     * rather than as an empty string, so the row is still attributable: an
     * audit trail cannot answer "who" with a blank.
     */
    public function actor(): string
    {
        $email = $this->reader === null ? $this->currentUserEmail() : ($this->reader)();

        if (! is_string($email) || $email === '') {
            return AuditLogRepository::SYSTEM_ACTOR;
        }

        if (preg_match('/\A[^\\s@]+@[^\\s@]+\\.[^\\s@]+\\z/D', $email) !== 1) {
            throw new InvalidArgumentException('The audit actor is not an email address.');
        }

        return $email;
    }

    private function currentUserEmail(): ?string
    {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;

        if (! is_object($user) || ! isset($user->user_email) || ! is_string($user->user_email)) {
            return null;
        }

        return $user->user_email;
    }
}