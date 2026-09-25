<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Ai;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\WordPress\Ai\OpenRouterProvider;
use PHPUnit\Framework\TestCase;

final class OpenRouterProviderTest extends TestCase
{
    public function testPostsTheMessageAndReturnsDecodedFields(): void
    {
        $client = new RecordingHttpClient(json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode(['title' => 'Parish feast']),
                    ],
                ],
            ],
        ]));
        $provider = new OpenRouterProvider('test-key', 'test/model', $client);

        $fields = $provider->enrich(
            new Message('email', 'test-id', 'events@example.test', 'Example Parish', 'Feast', 'Details'),
            new ParseResult()
        );

        self::assertSame(['title' => 'Parish feast'], $fields);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $client->url);
        self::assertSame([
            'Authorization' => 'Bearer test-key',
            'Content-Type' => 'application/json',
        ], $client->headers);
        self::assertSame(20, $client->timeout);

        $request = json_decode((string) $client->body, true);
        self::assertSame('test/model', $request['model']);
        self::assertSame(['type' => 'json_object'], $request['response_format']);
        self::assertSame('Feast' . "\n\n" . 'Details', $request['messages'][1]['content']);
    }

    public function testEmptyApiKeyOrFailedRequestReturnsNoFields(): void
    {
        $client = new RecordingHttpClient(null);
        $message = new Message('email', 'test-id', '', '', '', 'Details');

        $unconfiguredProvider = new OpenRouterProvider('', 'test/model', $client);
        self::assertFalse($unconfiguredProvider->isAvailable());
        self::assertSame([], $unconfiguredProvider->enrich($message, new ParseResult()));
        self::assertNull($client->url);

        $provider = new OpenRouterProvider('test-key', 'test/model', $client);
        self::assertSame([], $provider->enrich($message, new ParseResult()));

        $unavailableClient = new RecordingHttpClient(null, false);
        $unavailableProvider = new OpenRouterProvider('test-key', 'test/model', $unavailableClient);
        self::assertFalse($unavailableProvider->isAvailable());
        self::assertSame([], $unavailableProvider->enrich($message, new ParseResult()));
        self::assertNull($unavailableClient->url);
    }

    public function testSubstitutesInvalidUtf8BeforeSendingRequest(): void
    {
        $client = new RecordingHttpClient(null);
        $message = new Message(
            'email',
            'invalid-utf8-test',
            '',
            '',
            '',
            'Parish notice ' . "\xFF" . ' continued'
        );
        $provider = new OpenRouterProvider('test-key', 'test/model', $client);

        $provider->enrich($message, new ParseResult());

        self::assertIsString($client->body);
        self::assertSame(1, preg_match('//u', $client->body));
        $request = json_decode($client->body, true);
        self::assertIsArray($request);
        self::assertSame(
            'Parish notice ' . "\xEF\xBF\xBD" . ' continued',
            $request['messages'][1]['content']
        );
    }

    public function testApiKeyIsExcludedFromDebugOutput(): void
    {
        $apiKey = 'sk-test-DO-NOT-ECHO-123';
        $provider = new OpenRouterProvider($apiKey, 'test/model', new RecordingHttpClient(null));

        ob_start();
        var_dump($provider);
        $debugOutput = (string) ob_get_clean();

        self::assertStringNotContainsString($apiKey, $debugOutput);
        self::assertStringNotContainsString($apiKey, print_r($provider, true));
    }
}

final class RecordingHttpClient implements HttpClientInterface
{
    public ?string $url = null;
    public array $headers = [];
    public ?string $body = null;
    public ?int $timeout = null;

    public function __construct(private ?string $responseBody, private bool $available = true)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function post(string $url, array $headers, string $body, int $timeout): ?string
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->timeout = $timeout;

        return $this->responseBody;
    }
}
