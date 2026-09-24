<?php

namespace ADCT\ParishIntake\Core\Ports;

interface HttpClientInterface
{
    public function isAvailable(): bool;

    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, array $headers, string $body, int $timeout): ?string;
}
