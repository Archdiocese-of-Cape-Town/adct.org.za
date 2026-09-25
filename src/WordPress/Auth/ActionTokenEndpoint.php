<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use ADCT\ParishIntake\Core\Auth\ActionTokenInspection;
use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenDeliveryException;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenStatus;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Ports\ActionTokenHandlerRegistryInterface;
use ADCT\ParishIntake\Core\Ports\AtomicActionTokenHandlerInterface;
use DomainException;
use RuntimeException;

final class ActionTokenEndpoint
{
    public const QUERY_VAR = 'adct_action_token';
    public const TOKEN_PARAM = 'adct_token';
    public const ACTION_FIELD = 'adct_token_action';
    public const NONCE_FIELD = 'adct_token_nonce';
    public const REASON_FIELD = 'adct_denial_reason';

    private const ROUTE_VALUE = '1';

    public function __construct(
        private ActionTokenService $tokens,
        private ActionTokenHandlerRegistryInterface $handlers,
        private ActionTokenRenewalService $renewals
    ) {
    }

    /**
     * @param string[] $queryVars
     * @return string[]
     */
    public function registerQueryVars(array $queryVars): array
    {
        $queryVars[] = self::QUERY_VAR;
        $queryVars[] = self::TOKEN_PARAM;

        return array_values(array_unique($queryVars));
    }

    public function handleRequest(): void
    {
        if (get_query_var(self::QUERY_VAR) !== self::ROUTE_VALUE) {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $queryToken = $this->stringInput(get_query_var(self::TOKEN_PARAM));
        $postToken = $this->stringInput($_POST[self::TOKEN_PARAM] ?? null);
        $postAction = $this->stringInput($_POST[self::ACTION_FIELD] ?? null);
        $nonce = $this->stringInput($_POST[self::NONCE_FIELD] ?? null);
        $reason = $this->stringInput($_POST[self::REASON_FIELD] ?? null);
        $remoteAddress = $this->stringInput($_SERVER['REMOTE_ADDR'] ?? null);

        $response = $this->respond(
            $method,
            $queryToken,
            $postToken,
            $postAction,
            $nonce,
            $remoteAddress,
            $reason
        );

        $this->send($response);
    }

    public function respond(
        string $method,
        string $queryToken,
        string $postToken,
        string $postAction,
        string $nonce,
        string $remoteAddress,
        string $reason = ''
    ): ActionTokenHttpResponse {
        $method = strtoupper($method);

        if ($method === 'GET') {
            return $this->respondToGet($queryToken);
        }

        if ($method !== 'POST') {
            return new ActionTokenHttpResponse(
                405,
                $this->renderPage(
                    __('Request not allowed', 'adct-parish-intake'),
                    __('This link can only be opened or submitted from its confirmation page.', 'adct-parish-intake')
                ),
                ['Allow' => 'GET, POST']
            );
        }

        if (! in_array($postAction, ['perform', 'renew'], true)) {
            return new ActionTokenHttpResponse(
                400,
                $this->renderPage(
                    __('Request not recognised', 'adct-parish-intake'),
                    __('Please reopen the link from the original email.', 'adct-parish-intake')
                )
            );
        }

        if (! $this->verifyNonce($postAction, $postToken, $nonce)) {
            return new ActionTokenHttpResponse(
                403,
                $this->renderPage(
                    __('Request could not be verified', 'adct-parish-intake'),
                    __('Please reopen the link from the original email and try again.', 'adct-parish-intake')
                )
            );
        }

        if ($postAction === 'renew') {
            return $this->respondToRenewal($postToken, $remoteAddress);
        }

        return $this->respondToAction($postToken, $reason);
    }

    public static function urlForToken(string $token): string
    {
        return add_query_arg(
            self::TOKEN_PARAM,
            $token,
            add_query_arg(self::QUERY_VAR, self::ROUTE_VALUE, home_url('/'))
        );
    }

    private function respondToGet(string $token): ActionTokenHttpResponse
    {
        $inspection = $this->tokens->inspect($token);

        if ($inspection->status !== ActionTokenStatus::VALID || $inspection->binding === null) {
            return $this->statusResponse($inspection, $token);
        }

        $handler = $this->handlers->forPurpose($inspection->binding->purpose);

        if ($handler === null) {
            return $this->unavailableResponse();
        }

        $preview = $handler->preview($inspection->binding);

        if ($preview === null) {
            return $this->invalidResponse();
        }

        return new ActionTokenHttpResponse(200, $this->renderPreview($preview, $token, $inspection->binding->purpose));
    }

    private function respondToAction(string $token, string $reason): ActionTokenHttpResponse
    {
        $inspection = $this->tokens->inspect($token);

        if (
            ! in_array($inspection->status, [ActionTokenStatus::VALID, ActionTokenStatus::USED], true)
            || $inspection->binding === null
        ) {
            return $this->statusResponse($inspection, $token);
        }

        $handler = $this->handlers->forPurpose($inspection->binding->purpose);

        if ($handler === null) {
            return $this->unavailableResponse();
        }

        if ($inspection->status === ActionTokenStatus::VALID && $handler->preview($inspection->binding) === null) {
            return $this->invalidResponse();
        }

        if ($handler instanceof AtomicActionTokenHandlerInterface) {
            if (strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
                return new ActionTokenHttpResponse(400, $this->renderPage(
                    __('Reason is too long or invalid', 'adct-parish-intake'),
                    __('Please shorten your reason and try again.', 'adct-parish-intake')
                ));
            }
            try {
                $outcome = $inspection->status === ActionTokenStatus::USED
                    ? $handler->recover($inspection->binding)
                    : $handler->performAtomic($inspection->binding, $token, $this->tokens, trim($reason));
            } catch (DomainException) {
                return new ActionTokenHttpResponse(409, $this->renderPage(
                    __('Already decided or unavailable', 'adct-parish-intake'),
                    __('This event cannot be changed using this link.', 'adct-parish-intake')
                ));
            } catch (RuntimeException $failure) {
                error_log('[ADCT Parish Intake] Confirmation action failed (' . get_class($failure) . ').');
                return new ActionTokenHttpResponse(503, $this->renderPage(
                    __('The event could not be completed', 'adct-parish-intake'),
                    __('Please try this button again later; no new decision will be recorded.', 'adct-parish-intake')
                ));
            }
        } else {
            if ($inspection->status !== ActionTokenStatus::VALID) {
                return $this->statusResponse($inspection, $token);
            }
            $consumption = $this->tokens->consume($token, $inspection->binding);

            if ($consumption->status !== ActionTokenStatus::CONSUMED) {
                return $this->statusResponse($consumption, $token);
            }
            $outcome = $handler->perform($inspection->binding);
        }

        return new ActionTokenHttpResponse(
            200,
            $this->renderPage(
                __('Response recorded', 'adct-parish-intake'),
                $outcome->message,
                implode('', array_map(
                    static fn (string $url): string => '<p><a href="' . esc_url($url) . '">'
                        . esc_html__('View published event', 'adct-parish-intake') . '</a></p>',
                    $outcome->eventUrls
                ))
            )
        );
    }

    private function respondToRenewal(string $token, string $remoteAddress): ActionTokenHttpResponse
    {
        $inspection = $this->tokens->inspect($token);

        if (
            ! in_array($inspection->status, [ActionTokenStatus::EXPIRED, ActionTokenStatus::USED], true)
            || $inspection->binding === null
            || $this->handlers->forPurpose($inspection->binding->purpose) === null
        ) {
            return $this->statusResponse($inspection, $token);
        }

        try {
            $this->renewals->request($token, $remoteAddress);
        } catch (ActionTokenDeliveryException) {
            return new ActionTokenHttpResponse(
                503,
                $this->renderPage(
                    __('A new link could not be sent', 'adct-parish-intake'),
                    __('Please wait a little and try again, or contact the person who sent the email.', 'adct-parish-intake')
                )
            );
        }

        return new ActionTokenHttpResponse(
            200,
            $this->renderPage(
                __('Link request received', 'adct-parish-intake'),
                __('If another link can be sent, it will be delivered to the original address.', 'adct-parish-intake')
            )
        );
    }

    private function statusResponse(
        ActionTokenInspection $inspection,
        string $token
    ): ActionTokenHttpResponse {
        if ($inspection->status === ActionTokenStatus::EXPIRED) {
            return new ActionTokenHttpResponse(
                200,
                $this->renderPage(
                    __('This link has expired', 'adct-parish-intake'),
                    __('You can ask for a new link below. The expired link has not changed anything.', 'adct-parish-intake'),
                    $this->renderRenewalForm($token, $inspection->binding)
                )
            );
        }

        if ($inspection->status === ActionTokenStatus::USED) {
            return new ActionTokenHttpResponse(
                200,
                $this->renderPage(
                    __('This link has already been used', 'adct-parish-intake'),
                    __('If you need to take this action again, you can ask for a new link below.', 'adct-parish-intake'),
                    $this->renderRenewalForm($token, $inspection->binding)
                )
            );
        }

        return $this->invalidResponse();
    }

    private function renderPreview(ActionTokenPreview $preview, string $token, ActionTokenPurpose $purpose): string
    {
        $details = '';

        if ($preview->details !== []) {
            $details .= '<ul>';

            foreach ($preview->details as $detail) {
                $details .= '<li>' . esc_html($detail) . '</li>';
            }

            $details .= '</ul>';
        }

        $form = '<form method="post" action="' . esc_url($this->endpointUrl()) . '">'
            . $this->hiddenField(self::TOKEN_PARAM, $token)
            . $this->hiddenField(self::ACTION_FIELD, 'perform')
            . $this->nonceField('perform', $token)
            . ($purpose === ActionTokenPurpose::DENY
                ? '<label>' . esc_html__('Reason (optional)', 'adct-parish-intake')
                    . ' <textarea name="' . esc_attr(self::REASON_FIELD) . '" maxlength="1000"></textarea></label>'
                : '')
            . '<button type="submit">' . esc_html($preview->submitLabel) . '</button>'
            . '</form>';

        return $this->renderPage(
            $preview->title,
            $preview->summary,
            $details . $form
        );
    }

    private function renderRenewalForm(string $token, ?ActionTokenBinding $binding): string
    {
        if ($binding === null || $this->handlers->forPurpose($binding->purpose) === null) {
            return '<p>' . esc_html__(
                'Please contact the person who sent the email to request a new link.',
                'adct-parish-intake'
            ) . '</p>';
        }

        return '<form method="post" action="' . esc_url($this->endpointUrl()) . '">'
            . $this->hiddenField(self::TOKEN_PARAM, $token)
            . $this->hiddenField(self::ACTION_FIELD, 'renew')
            . $this->nonceField('renew', $token)
            . '<button type="submit">' . esc_html__('Send me a new link', 'adct-parish-intake') . '</button>'
            . '</form>';
    }

    private function nonceField(string $action, string $token): string
    {
        return $this->hiddenField(
            self::NONCE_FIELD,
            wp_create_nonce($this->nonceAction($action, $token))
        );
    }

    private function verifyNonce(string $action, string $token, string $nonce): bool
    {
        if ($token === '' || $nonce === '') {
            return false;
        }

        return wp_verify_nonce($nonce, $this->nonceAction($action, $token)) !== false;
    }

    private function nonceAction(string $action, string $token): string
    {
        return 'adct_pi_action_token_' . $action . '_' . hash('sha256', $token);
    }

    private function hiddenField(string $name, string $value): string
    {
        return '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    }

    private function endpointUrl(): string
    {
        return add_query_arg(self::QUERY_VAR, self::ROUTE_VALUE, home_url('/'));
    }

    private function renderPage(string $title, string $message, string $content = ''): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="referrer" content="no-referrer"><title>' . esc_html($title)
            . '</title></head><body><main><h1>' . esc_html($title) . '</h1><p>'
            . esc_html($message) . '</p>' . $content . '</main></body></html>';
    }

    private function invalidResponse(): ActionTokenHttpResponse
    {
        return new ActionTokenHttpResponse(
            200,
            $this->renderPage(
                __('This link is not valid', 'adct-parish-intake'),
                __('The link is invalid or unavailable. No action has been taken.', 'adct-parish-intake')
            )
        );
    }

    private function unavailableResponse(): ActionTokenHttpResponse
    {
        return new ActionTokenHttpResponse(
            200,
            $this->renderPage(
                __('This link is not available', 'adct-parish-intake'),
                __('This action is not available yet. The link has not been used.', 'adct-parish-intake')
            )
        );
    }

    private function send(ActionTokenHttpResponse $response): void
    {
        if (! headers_sent()) {
            status_header($response->statusCode);

            foreach ($response->headers() as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $response->body;
        exit;
    }

    private function stringInput(mixed $value): string
    {
        return is_string($value) ? wp_unslash($value) : '';
    }
}
