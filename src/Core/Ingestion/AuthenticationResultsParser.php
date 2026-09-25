<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;

final class AuthenticationResultsParser
{
    /** @var array<string, true> */
    private array $trustedAuthservIds = [];

    /**
     * @param list<string> $trustedAuthservIds
     */
    public function __construct(array $trustedAuthservIds = [])
    {
        if (! array_is_list($trustedAuthservIds)) {
            throw new InvalidArgumentException('Trusted authserv-ids must be provided as a list.');
        }

        foreach ($trustedAuthservIds as $authservId) {
            if (! is_string($authservId)) {
                throw new InvalidArgumentException('Every trusted authserv-id must be a string.');
            }

            $normalized = $this->normalizeAuthservId($authservId);

            if ($normalized === null) {
                throw new InvalidArgumentException('A trusted authserv-id is invalid.');
            }

            $this->trustedAuthservIds[$normalized] = true;
        }
    }

    /**
     * @param list<string> $headers
     */
    public function parse(array $headers): AuthenticationResults
    {
        $results = [];

        foreach ($headers as $header) {
            if (! is_string($header)) {
                throw new InvalidArgumentException('Authentication-Results headers must be strings.');
            }

            $segments = $this->splitSegments($header);

            if ($segments === []) {
                continue;
            }

            $authservId = $this->authservId($segments[0]);
            $trusted = $authservId !== null && isset($this->trustedAuthservIds[$authservId]);

            foreach (array_slice($segments, 1) as $segment) {
                if (
                    preg_match(
                        '/\A\s*(spf|dkim|dmarc)\s*=\s*([a-z][a-z0-9_-]*)\b/i',
                        $segment,
                        $matches
                    ) !== 1
                ) {
                    continue;
                }

                $method = strtolower($matches[1]);
                $verdict = strtolower($matches[2]);

                if (! in_array($verdict, AuthenticationResult::VERDICTS, true)) {
                    continue;
                }

                $results[] = new AuthenticationResult($method, $verdict, $authservId, $trusted);
            }
        }

        return new AuthenticationResults($results);
    }

    private function authservId(string $segment): ?string
    {
        if (preg_match('/\A\s*([^\s;()]+)/', $segment, $matches) !== 1) {
            return null;
        }

        return $this->normalizeAuthservId($matches[1]);
    }

    private function normalizeAuthservId(string $authservId): ?string
    {
        $authservId = strtolower(trim($authservId));

        if (
            $authservId === ''
            || strlen($authservId) > 253
            || preg_match('/\A[a-z0-9][a-z0-9._:-]*(?:\/[a-z0-9._-]+)?\z/D', $authservId) !== 1
        ) {
            return null;
        }

        return $authservId;
    }

    /**
     * @return list<string>
     */
    private function splitSegments(string $header): array
    {
        $segments = [];
        $segment = '';
        $quoted = false;
        $escaped = false;
        $commentDepth = 0;

        for ($index = 0, $length = strlen($header); $index < $length; ++$index) {
            $character = $header[$index];

            if ($escaped) {
                $segment .= $character;
                $escaped = false;

                continue;
            }

            if (($quoted || $commentDepth > 0) && $character === '\\') {
                $segment .= $character;
                $escaped = true;

                continue;
            }

            if ($commentDepth === 0 && $character === '"') {
                $quoted = ! $quoted;
                $segment .= $character;

                continue;
            }

            if (! $quoted && $character === '(') {
                ++$commentDepth;
            } elseif (! $quoted && $character === ')' && $commentDepth > 0) {
                --$commentDepth;
            } elseif (! $quoted && $commentDepth === 0 && $character === ';') {
                $segments[] = trim($segment);
                $segment = '';

                continue;
            }

            $segment .= $character;
        }

        $segments[] = trim($segment);

        return $segments;
    }
}
