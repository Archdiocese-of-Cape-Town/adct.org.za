<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use DomainException;

/**
 * Handles the LOGIN purpose (ADR 0007 magic-link login for the front-end
 * approval queue, issue #72).
 *
 * The handler is deliberately non-atomic: `perform()` does not need a
 * transaction because logging in sets a cookie and returns. The important
 * property is that the *live user* is re-resolved on every call — a link minted
 * for a user who has since been deactivated, or had the approver capability
 * stripped, must not work. Both `preview()` and `perform()` call `resolve()`
 * uncached so a token cannot be used after the account is deactivated or the
 * capability is revoked between the GET and the POST.
 */
final class LoginHandler implements ActionTokenActionHandlerInterface
{
    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::LOGIN;
    }

    /**
     * Show the "Log in" confirmation page, or null when the recipient is no
     * longer entitled to the link.
     */
    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        $user = $this->resolve($binding);

        if ($user === null) {
            return null;
        }

        return new ActionTokenPreview(
            title: __('Log in to the approval queue', 'adct-parish-intake'),
            summary: __(
                'Press the button below to sign in to the parish intake approval queue. '
                . 'This link can be used once and expires after 30 minutes.',
                'adct-parish-intake'
            ),
            submitLabel: __('Log in', 'adct-parish-intake')
        );
    }

    /**
     * Set the WordPress auth cookie and return the redirect destination.
     *
     * The user is re-resolved here, not reused from `preview()`, because the
     * endpoint calls both on the POST and the account may have changed between
     * the GET that rendered the form and the POST that submits it.
     */
    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        $user = $this->resolve($binding);

        if ($user === null) {
            throw new DomainException(
                'This login link is no longer valid. The account may have been deactivated.'
            );
        }

        wp_set_auth_cookie($user->ID, true);

        return new ActionTokenOutcome(
            __('You are now signed in. Use the approval queue link in the navigation to review events.', 'adct-parish-intake')
        );
    }

    /**
     * The live user for this binding, or null when they may not log in.
     *
     * Re-resolves uncached on every call: the account must exist, be active
     * (user_status === 0), and still hold an approval capability. The email in
     * the binding is verified against the current account email so a token
     * minted for an old address cannot be used after the address changes.
     *
     * @return \WP_User|null
     */
    private function resolve(ActionTokenBinding $binding): ?\WP_User
    {
        $user = get_user_by('email', $binding->email);

        if (! $user instanceof \WP_User) {
            return null;
        }

        if ((int) $user->ID !== $binding->subjectId) {
            return null;
        }

        if ((int) $user->user_status !== 0) {
            return null;
        }

        if (strtolower(trim((string) $user->user_email)) !== strtolower(trim($binding->email))) {
            return null;
        }

        if (! user_can($user, Capabilities::APPROVE_DEANERY)
            && ! user_can($user, Capabilities::REVIEW)
        ) {
            return null;
        }

        return $user;
    }
}