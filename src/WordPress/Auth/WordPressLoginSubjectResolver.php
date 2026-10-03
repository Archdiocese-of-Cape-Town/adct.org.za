<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Ports\ActionTokenLoginSubjectResolverInterface;

/**
 * Resolves an emailed address to the LOGIN binding it may be sent.
 *
 * The live account is read here, not from a list built earlier: an account
 * that has been deactivated, or an approver whose capability has been stripped,
 * gets no link. That is the same rule LoginHandler applies again at act time,
 * so the two ends of the link cannot disagree.
 */
final class WordPressLoginSubjectResolver implements ActionTokenLoginSubjectResolverInterface
{
    public function bindingFor(string $email): ?ActionTokenBinding
    {
        $user = get_user_by('email', $email);

        if (! $user instanceof \WP_User) {
            return null;
        }

        if ((int) $user->ID < 1 || (int) $user->user_status !== 0) {
            return null;
        }

        $address = (string) $user->user_email;

        if (strtolower(trim($address)) !== strtolower(trim($email))) {
            return null;
        }

        if (! user_can($user, Capabilities::APPROVE_DEANERY)
            && ! user_can($user, Capabilities::REVIEW)
        ) {
            return null;
        }

        return new ActionTokenBinding(ActionTokenPurpose::LOGIN, 'user', (int) $user->ID, $address);
    }
}