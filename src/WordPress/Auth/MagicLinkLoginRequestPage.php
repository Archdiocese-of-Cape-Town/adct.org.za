<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\MagicLinkLoginService;
use ADCT\ParishIntake\Core\Auth\MagicLinkLoginStatus;

/**
 * The shortcode that asks a dean for their email address (#72).
 *
 * ADR 0007 requires the answer to be identical whether or not the address
 * belongs to an account, so `render()` never branches on the status except for
 * the rate-limit notice, and never echoes back whether a mail was queued. The
 * form is a POST for the same reason action links are: a GET must not act.
 */
final class MagicLinkLoginRequestPage
{
    public const SHORTCODE = 'adct_approval_queue_login';
    public const BLOCK = 'adct/approval-queue-login';
    public const ACTION = 'adct_pi_request_magic_link';
    public const NONCE = 'adct_pi_request_magic_link';

    public function __construct(
        private MagicLinkLoginService $service
    )
    {
    }

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render']);
        if (function_exists('register_block_type')) {
            register_block_type(self::BLOCK, [
                'api_version' => 2,
                'title' => __('Parish approval queue sign-in', 'adct-parish-intake'),
                'category' => 'widgets',
                'attributes' => [],
                'render_callback' => function (): string {
                    return $this->render();
                },
            ]);
        }
    }

    /**
     * Handle the request POST. A GET shows the form; it never sends mail.
     */
    public function handleRequest(): void
    {
        if (! isset($_POST['adct_pi_action']) || sanitize_key(
            wp_unslash((string) $_POST['adct_pi_action'])
        ) !== self::ACTION) {
            return;
        }

        if (! isset($_POST['_wpnonce'])
            || ! wp_verify_nonce(
                sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])),
                self::NONCE
            )
        ) {
            wp_die(
                esc_html__('That sign-in request has expired. Please try again.', 'adct-parish-intake'),
                esc_html__('Sign-in request failed', 'adct-parish-intake'),
                ['response' => 403]
            );
        }

        $email = isset($_POST['adct_pi_email'])
            ? sanitize_email((string) wp_unslash((string) $_POST['adct_pi_email']))
            : '';

        if ($email === '' || ! is_email($email)) {
            $this->respond(MagicLinkLoginStatus::SENT);

            return;
        }

        $remoteAddress = isset($_SERVER['REMOTE_ADDR'])
            ? (string) wp_unslash((string) $_SERVER['REMOTE_ADDR'])
            : '';

        $this->respond($this->service->request($email, $remoteAddress));
    }

    private function respond(MagicLinkLoginStatus $status): void
    {
        if ($status === MagicLinkLoginStatus::RATE_LIMITED) {
            wp_die(
                esc_html__(
                    'Too many sign-in links have been requested from this device. '
                    . 'Please wait an hour and try again.',
                    'adct-parish-intake'
                ),
                esc_html__('Please wait', 'adct-parish-intake'),
                ['response' => 429]
            );
        }

        // One page for both outcomes: the sender learns nothing about whether
        // the address is a known approver (ADR 0007).
        wp_die(
            esc_html__(
                'If that address belongs to a dean or archdiocese reviewer, '
                . 'a sign-in link is on its way. It works once and expires in 30 minutes.',
                'adct-parish-intake'
            ),
            esc_html__('Check your email', 'adct-parish-intake'),
            ['response' => 200]
        );
    }

    public function render(): string
    {
        $error = isset($_GET['adct_pi_login_error']) ? sanitize_key(
            wp_unslash((string) $_GET['adct_pi_login_error'])
        ) : '';
        $notice = '';
        if ($error === 'expired') {
            $notice = '<p class="adct-notice adct-notice-error">'
                . esc_html__('That sign-in link has expired. Please request a new one.', 'adct-parish-intake')
                . '</p>';
        } elseif ($error === 'used') {
            $notice = '<p class="adct-notice adct-notice-error">'
                . esc_html__('That sign-in link has already been used. Please request a new one.', 'adct-parish-intake')
                . '</p>';
        }

        return '<div class="adct-approval-queue-login">'
            . $notice
            . '<p>' . esc_html__(
                'Enter the email address you use for parish notices and we will send you a sign-in link '
                . 'for the approval queue.',
                'adct-parish-intake'
            ) . '</p>'
            . '<form method="post" action="">'
            . '<p><label for="adct_pi_email">' . esc_html__('Email address', 'adct-parish-intake') . '</label>'
            . '<input type="email" id="adct_pi_email" name="adct_pi_email" required /></p>'
            . '<input type="hidden" name="adct_pi_action" value="' . esc_attr(self::ACTION) . '" />'
            . wp_nonce_field(self::NONCE, '_wpnonce', false)
            . '<p><button type="submit" class="wp-button button">'
            . esc_html__('Send me a sign-in link', 'adct-parish-intake')
            . '</button></p></form></div>';
    }
}