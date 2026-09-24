<?php

namespace ADCT\ParishIntake\WordPress\Http;

use ADCT\ParishIntake\Core\Ports\HttpClientInterface;

final class WordPressHttpClient implements HttpClientInterface
{
    public function isAvailable(): bool
    {
        return function_exists('wp_remote_post');
    }

    public function post(string $url, array $headers, string $body, int $timeout): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'timeout' => $timeout,
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        return (string) wp_remote_retrieve_body($response);
    }
}
