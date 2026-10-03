<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenDeliveryException;
use ADCT\ParishIntake\Core\Auth\IssuedActionToken;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ActionTokenLoginDeliveryInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;

/**
 * Queues the ADR 0007 magic link.
 *
 * All mail goes through the plugin's queue (ADR 0011), so this never calls
 * wp_mail(). The dedupe key is derived from the token, which is what makes a
 * retried request for the same token collapse into one message rather than
 * mailing a dean twice.
 */
final class WordPressMagicLinkDelivery implements ActionTokenLoginDeliveryInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private string $queueUrl = ''
    )
    {
    }

    public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void
    {
        $url = $this->queueUrl !== ''
            ? $this->queueUrl
            : ActionTokenEndpoint::urlForToken($issuedToken->token());

        $html = '<p>' . esc_html__(
            'Somebody asked to sign in to the parish event approval queue with this address. '
            . 'If that was you, use the button below. The link works once and expires in 30 minutes.',
            'adct-parish-intake'
        ) . '</p><p><a href="' . esc_url($url) . '">'
            . esc_html__('Sign in to the approval queue', 'adct-parish-intake') . '</a></p>';
        $text = __(
            'Somebody asked to sign in to the parish event approval queue with this address. '
            . 'If that was you, open the link below. The link works once and expires in 30 minutes.',
            'adct-parish-intake'
        ) . "\n\n" . $url;

        $result = $this->mailer->enqueue(new OutboundEmail(
            $binding->email,
            __('Your sign-in link for the parish approval queue', 'adct-parish-intake'),
            $html,
            $text,
            MailPriority::LOGIN_OR_CONFIRMATION,
            'magic-link-login:' . hash('sha256', $issuedToken->token())
        ));

        if (! in_array($result->status, [
            MailQueueStatus::QUEUED,
            MailQueueStatus::SENDING,
            MailQueueStatus::SENT,
        ], true)) {
            throw new ActionTokenDeliveryException('The sign-in link could not be queued.');
        }
    }
}