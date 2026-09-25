<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

final class AutomatedMailDetector
{
    private const LIST_HEADERS = [
        'List-Post',
        'List-Unsubscribe',
        'List-Subscribe',
        'List-Help',
        'List-Archive',
        'List-Owner',
        'Mailing-List',
        'X-BeenThere',
    ];

    public function __construct(private EmailAddressSafety $addressSafety = new EmailAddressSafety())
    {
    }

    /**
     * @param array<string, list<string>> $headers
     * @param list<string> $contentTypes
     */
    public function inspect(array $headers, ?string $senderEmail, array $contentTypes): AutomatedMailAssessment
    {
        $signals = [];
        $declared = false;

        foreach ($headers['Auto-Submitted'] ?? [] as $value) {
            $token = $this->firstToken($value);

            if ($token === 'no') {
                continue;
            }

            $this->addSignal($signals, 'auto_submitted');
            $declared = $declared || $token !== '';
        }

        foreach ([
            'X-Autoreply' => 'x_autoreply',
            'X-Autorespond' => 'x_autorespond',
        ] as $header => $signal) {
            foreach ($headers[$header] ?? [] as $value) {
                $token = $this->firstToken($value);

                if ($token === 'no') {
                    continue;
                }

                $this->addSignal($signals, $signal);
                $declared = $declared || $token !== '';
            }
        }

        foreach ($headers['Precedence'] ?? [] as $value) {
            $tokens = preg_split('/[\s,;]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach (['bulk', 'list', 'auto_reply', 'auto-reply'] as $precedence) {
                if (in_array($precedence, $tokens, true)) {
                    $signal = $precedence === 'auto-reply'
                        ? 'precedence_auto_reply'
                        : 'precedence_' . $precedence;
                    $this->addSignal($signals, $signal);
                }
            }
        }

        if ($this->hasNonEmptyValue($headers['List-Id'] ?? [])) {
            $this->addSignal($signals, 'list_id');
        }

        foreach (self::LIST_HEADERS as $header) {
            if ($this->hasNonEmptyValue($headers[$header] ?? [])) {
                $this->addSignal($signals, 'list_header');
                break;
            }
        }

        foreach ($headers['Return-Path'] ?? [] as $value) {
            if (trim($value, " \t\n\r\0\x0B<>\"'") === '') {
                $this->addSignal($signals, 'empty_return_path');
                break;
            }
        }

        if ($this->isDeliveryStatusNotification($contentTypes)) {
            $this->addSignal($signals, 'delivery_status');
            $declared = true;
        }

        foreach ($headers['X-Auto-Response-Suppress'] ?? [] as $value) {
            $tokens = preg_split('/[\s,;]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($tokens !== [] && array_diff($tokens, ['none', 'no']) !== []) {
                $this->addSignal($signals, 'x_auto_response_suppress');
                break;
            }
        }

        if ($this->hasNonEmptyValue($headers['X-Loop'] ?? [])) {
            $this->addSignal($signals, 'x_loop');
        }

        if ($this->addressSafety->isNoReplyAddress($senderEmail)) {
            $this->addSignal($signals, 'no_reply_sender');
        }

        $classification = $signals === []
            ? AutomatedMailAssessment::CLASSIFICATION_NONE
            : ($declared
                ? AutomatedMailAssessment::CLASSIFICATION_DECLARED
                : AutomatedMailAssessment::CLASSIFICATION_LIKELY);

        return new AutomatedMailAssessment($classification, $signals);
    }

    private function firstToken(string $value): string
    {
        if (preg_match('/\A\s*([a-z0-9_-]+)/i', $value, $matches) !== 1) {
            return '';
        }

        return strtolower($matches[1]);
    }

    /**
     * @param list<string> $values
     */
    private function hasNonEmptyValue(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $signals
     */
    private function addSignal(array &$signals, string $signal): void
    {
        if (! in_array($signal, $signals, true)) {
            $signals[] = $signal;
        }
    }

    /**
     * @param list<string> $contentTypes
     */
    private function isDeliveryStatusNotification(array $contentTypes): bool
    {
        foreach ($contentTypes as $contentType) {
            if (
                preg_match('/\bmessage\/delivery-status\b/i', $contentType) === 1
                || (
                    preg_match('/\bmultipart\/report\b/i', $contentType) === 1
                    && preg_match('/\breport-type\s*=\s*"?delivery-status"?\b/i', $contentType) === 1
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
