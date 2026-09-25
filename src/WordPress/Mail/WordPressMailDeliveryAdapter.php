<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Mail;

use ADCT\ParishIntake\Core\Mail\MailDeliveryResult;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\MailDeliveryInterface;
use RuntimeException;
use Throwable;

final class WordPressMailDeliveryAdapter implements MailDeliveryInterface
{
    public function deliver(OutboundEmail $email): MailDeliveryResult
    {
        $hasHtml = $email->htmlBody !== '';
        $body = $hasHtml ? $email->htmlBody : $email->textBody;
        $contentType = $hasHtml ? 'text/html' : 'text/plain';
        $headers = ['Content-Type: ' . $contentType . '; charset=UTF-8'];
        $setAlternativeBody = static function ($mailer) use ($email, $hasHtml): void {
            if (! $hasHtml) {
                return;
            }

            if (! is_object($mailer) || ! property_exists($mailer, 'AltBody')) {
                throw new RuntimeException('The WordPress mailer does not support a text alternative.');
            }

            $mailer->AltBody = $email->textBody;
        };

        try {
            add_action('phpmailer_init', $setAlternativeBody, 10, 1);
            $sent = wp_mail($email->recipient, $email->subject, $body, $headers, []);

            return $sent
                ? MailDeliveryResult::sent()
                : MailDeliveryResult::failed('wp_mail_returned_false');
        } catch (Throwable) {
            return MailDeliveryResult::unknown();
        } finally {
            remove_action('phpmailer_init', $setAlternativeBody, 10);
        }
    }
}
