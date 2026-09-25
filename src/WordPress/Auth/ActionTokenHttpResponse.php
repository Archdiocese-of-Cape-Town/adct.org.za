<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

use InvalidArgumentException;

final class ActionTokenHttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        private array $additionalHeaders = []
    ) {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('The action token response status is invalid.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'",
        ] + $this->additionalHeaders;
    }
}
