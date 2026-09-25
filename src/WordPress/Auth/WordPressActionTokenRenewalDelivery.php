<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenDeliveryException;
use ADCT\ParishIntake\Core\Auth\IssuedActionToken;
use ADCT\ParishIntake\Core\Mail\MailPriority;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ActionTokenRenewalDeliveryInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;

final class WordPressActionTokenRenewalDelivery implements ActionTokenRenewalDeliveryInterface
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public function deliver(ActionTokenBinding $binding, IssuedActionToken $issuedToken): void
    {
        $url = ActionTokenEndpoint::urlForToken($issuedToken->token());
        $intro = esc_html__(
            'You asked for a new secure link. It can be used once and expires automatically.',
            'adct-parish-intake'
        );
        $linkText = esc_html__('Open your secure link', 'adct-parish-intake');
        $html = '<p>' . $intro . '</p><p><a href="' . esc_url($url) . '">' . $linkText . '</a></p>';
        $text = __(
            'You asked for a new secure link. It can be used once and expires automatically.',
            'adct-parish-intake'
        ) . "\n\n" . $url;
        $message = new OutboundEmail(
            $binding->email,
            __('Your new secure action link', 'adct-parish-intake'),
            $html,
            $text,
            MailPriority::LOGIN_OR_CONFIRMATION,
            'action-token-renewal:' . hash('sha256', $issuedToken->token())
        );
        $result = $this->mailer->enqueue($message);

        if (! in_array($result->status, [
            MailQueueStatus::QUEUED,
            MailQueueStatus::SENDING,
            MailQueueStatus::SENT,
        ], true)) {
            throw new ActionTokenDeliveryException('The new action link could not be queued.');
        }
    }
}
