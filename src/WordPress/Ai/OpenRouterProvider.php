<?php

namespace ADCT\ParishIntake\WordPress\Ai;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;

final class OpenRouterProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $model;
    private HttpClientInterface $httpClient;

    public function __construct(string $apiKey, string $model, HttpClientInterface $httpClient)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->httpClient = $httpClient;
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function __debugInfo(): array
    {
        return [
            'name' => $this->name(),
            'model' => $this->model,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '' && $this->httpClient->isAvailable();
    }

    public function enrich(Message $message, ParseResult $result): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $payload = [
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
        ];
        $requestBody = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($requestBody === false) {
            return [];
        }

        $responseBody = $this->httpClient->post(
            'https://openrouter.ai/api/v1/chat/completions',
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            $requestBody,
            20
        );

        if ($responseBody === null) {
            return [];
        }

        $body = json_decode($responseBody, true);
        $content = $body['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode((string) $content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
