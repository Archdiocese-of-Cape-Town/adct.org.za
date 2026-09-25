<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;
use JsonException;

final readonly class AuthenticationResults
{
    public const FORMAT_VERSION = 1;

    /**
     * @param list<AuthenticationResult> $results
     */
    public function __construct(public array $results = [])
    {
        if (! array_is_list($results)) {
            throw new InvalidArgumentException('Authentication results must be an ordered list.');
        }

        foreach ($results as $result) {
            if (! $result instanceof AuthenticationResult) {
                throw new InvalidArgumentException('Authentication results must contain verdict records.');
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    /**
     * @return list<AuthenticationResult>
     */
    public function checksFor(string $method): array
    {
        if (! in_array($method, AuthenticationResult::METHODS, true)) {
            throw new InvalidArgumentException('The requested authentication method is unsupported.');
        }

        return array_values(array_filter(
            $this->results,
            static fn (AuthenticationResult $result): bool => $result->method === $method
        ));
    }

    public function hasReportedDmarcFailure(): bool
    {
        foreach ($this->checksFor('dmarc') as $result) {
            if ($result->result === 'fail') {
                return true;
            }
        }

        return false;
    }

    public function hasTrustedDmarcFailure(): bool
    {
        foreach ($this->checksFor('dmarc') as $result) {
            if ($result->result === 'fail' && $result->trusted) {
                return true;
            }
        }

        return false;
    }

    public function hasTrustedPass(?string $method = null): bool
    {
        $results = $method === null
            ? $this->results
            : $this->checksFor($method);

        foreach ($results as $result) {
            if ($result->result === 'pass' && $result->trusted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     version: int,
     *     spf: list<array{authserv_id: string|null, result: string, trusted: bool}>,
     *     dkim: list<array{authserv_id: string|null, result: string, trusted: bool}>,
     *     dmarc: list<array{authserv_id: string|null, result: string, trusted: bool}>
     * }
     */
    public function toArray(): array
    {
        $grouped = [
            'spf' => [],
            'dkim' => [],
            'dmarc' => [],
        ];

        foreach ($this->results as $result) {
            $grouped[$result->method][] = $result->toArray();
        }

        return [
            'version' => self::FORMAT_VERSION,
            'spf' => $grouped['spf'],
            'dkim' => $grouped['dkim'],
            'dmarc' => $grouped['dmarc'],
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Stored authentication results have an unsupported format.');
        }

        $results = [];

        foreach (AuthenticationResult::METHODS as $method) {
            $methodResults = $decoded[$method] ?? null;

            if (! is_array($methodResults) || ! array_is_list($methodResults)) {
                throw new InvalidArgumentException('Stored authentication results contain an invalid method list.');
            }

            foreach ($methodResults as $methodResult) {
                $results[] = AuthenticationResult::fromArray($method, $methodResult);
            }
        }

        return new self($results);
    }
}
