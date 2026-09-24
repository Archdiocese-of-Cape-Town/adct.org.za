<?php

namespace ADCT\ParishIntake\Parsing\Ai;

use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\ParseResult;

final class OpenRouterProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '' && function_exists('wp_remote_post');
    }

    public function enrich(Message $message, ParseResult $result): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 20,
            'body' => wp_json_encode([
                'model' => $this->model,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Extract parish event fields from church communications. Return JSON with optional keys: title, parish_name, event_date, event_time, venue, contact, summary. Do not decide publishability.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $message->fullText(),
                    ],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $content = $body['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode((string) $content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
