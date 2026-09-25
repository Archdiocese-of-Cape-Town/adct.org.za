<?php

namespace ADCT\ParishIntake\WordPress\Http;

use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\HttpResponse;
use RuntimeException;

final class WordPressHttpClient implements HttpClientInterface
{
    public function isAvailable(): bool
    {
        return function_exists('wp_remote_post');
    }

    public function post(string $url, array $headers, string $body, int $timeout): HttpResponse
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('AI HTTP transport is unavailable.');
        }

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'timeout' => $timeout,
            'body' => $body,
            'redirection' => 0,
            'limit_response_size' => 65536,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException('AI HTTP request failed or timed out.');
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response)
        );
    }
}
