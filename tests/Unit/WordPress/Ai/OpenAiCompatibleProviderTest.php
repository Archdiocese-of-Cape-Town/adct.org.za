<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Ai;

use ADCT\ParishIntake\Core\Parsing\Ai\AiRequestFailure;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Parsing\Stages\AiEnrichmentStage;
use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\HttpResponse;
use ADCT\ParishIntake\WordPress\Ai\OpenAiCompatibleProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpenAiCompatibleProviderTest extends TestCase
{
    private function message(string $body = 'Details'): Message
    {
        return new Message('email', 'test-id', 'events@example.test', 'Example Parish', 'Feast', $body);
    }

    private function response(array $fields): HttpResponse
    {
        return new HttpResponse(200, json_encode(['choices' => [
            ['message' => ['content' => json_encode($fields)]],
        ]]));
    }

    private function provider(FakeAiHttp $http, FakeAiGate $gate, string $key = 'test-key'): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(
            OpenAiCompatibleProvider::DEFAULT_URL,
            OpenAiCompatibleProvider::FREE_MODEL,
            $key,
            'openrouter',
            $http,
            $gate
        );
    }

    public function testSuccessfulRequestIsBoundedAndRecordsOnlyFieldsActuallyFilled(): void
    {
        $http = new FakeAiHttp($this->response(['title' => 'Changed', 'venue' => 'Hall', 'publish_status' => 'publish']));
        $gate = new FakeAiGate();
        $provider = $this->provider($http, $gate);
        $result = new ParseResult();
        $result->setField('title', 'Original');
        $stage = new AiEnrichmentStage($provider);
        $stage->process($this->message(), $result, new ParseContext(['ai_enabled' => true]));

        self::assertSame('Original', $result->getField('title'));
        self::assertSame('Hall', $result->getField('venue'));
        self::assertNull($result->getField('publish_status'));
        self::assertSame(['venue'], $result->getAiFieldsFilled());
        self::assertSame('openrouter', $result->getAiProvider());
        self::assertSame(OpenAiCompatibleProvider::FREE_MODEL, $result->getAiModel());
        self::assertSame(1, $gate->calls);
        self::assertSame(20, $http->timeout);
        self::assertSame(OpenAiCompatibleProvider::DEFAULT_URL . '/chat/completions', $http->url);
        self::assertSame('Bearer test-key', $http->headers['Authorization']);
        $payload = json_decode($http->body, true);
        self::assertSame(['type' => 'json_object'], $payload['response_format']);
        self::assertSame(OpenAiCompatibleProvider::FREE_MODEL, $payload['model']);
        self::assertSame(
            ['untrusted_email_text' => "Feast\n\nDetails"],
            json_decode($payload['messages'][1]['content'], true)
        );
        self::assertStringContainsString('untrusted email data', $payload['messages'][0]['content']);
    }

    public function testInvalidJsonAndBadFieldsLeaveDeterministicResultIntact(): void
    {
        foreach ([
            new HttpResponse(200, '{"choices":[{"message":{"content":"oops"}}]}'),
            $this->response(['event_date' => '2026-02-30', 'event_time' => '25:44', 'venue' => ['array'], 'status' => 'publish']),
            $this->response(['title' => str_repeat('X', 201)]),
        ] as $response) {
            $result = new ParseResult();
            $result->setField('title', 'Rules title');
            $context = new ParseContext(['ai_enabled' => true]);
            (new AiEnrichmentStage($this->provider(new FakeAiHttp($response), new FakeAiGate())))
                ->process($this->message(), $result, $context);
            self::assertSame(['title' => 'Rules title'], $result->fields());
            self::assertFalse($result->usedAi());
        }
    }

    public function testTimeoutAndRateLimitFallBackAndBackOffWithoutLeakingErrors(): void
    {
        $gate = new FakeAiGate();
        $timeout = new FakeAiHttp(new RuntimeException('Authorization: Bearer secret-do-not-print'));
        $result = new ParseResult();
        $result->setField('title', 'Rules title');
        $context = new ParseContext(['ai_enabled' => true]);
        (new AiEnrichmentStage($this->provider($timeout, $gate)))->process($this->message(), $result, $context);
        self::assertSame('Rules title', $result->getField('title'));
        self::assertStringNotContainsString('secret-do-not-print', implode(' ', $context->errors()));
        self::assertSame(20, $timeout->timeout);

        $http = new FakeAiHttp(new HttpResponse(429, '{"error":"secret"}'));
        $context = new ParseContext(['ai_enabled' => true]);
        (new AiEnrichmentStage($this->provider($http, $gate)))->process($this->message(), $result, $context);
        self::assertSame([600], $gate->backoffs);
        self::assertStringContainsString('HTTP 429', implode(' ', $context->errors()));
        self::assertSame('Rules title', $result->getField('title'));
    }

    public function testDailyCapPreventsHttpCallAndFailedRequestsConsumeReservations(): void
    {
        $gate = new FakeAiGate();
        $gate->available = false;
        $http = new FakeAiHttp($this->response(['title' => 'AI']));
        $result = new ParseResult();
        $context = new ParseContext(['ai_enabled' => true]);
        (new AiEnrichmentStage($this->provider($http, $gate)))->process($this->message(), $result, $context);
        self::assertNull($http->url);
        self::assertSame(1, $gate->calls);
        self::assertStringContainsString('daily call cap', implode(' ', $context->errors()));
    }

    public function testMissingKeyAndUnavailableTransportAreNotCalled(): void
    {
        $http = new FakeAiHttp(new HttpResponse(200, '{"choices":[{"message":{"content":"{}"}}]}'));
        self::assertFalse($this->provider($http, new FakeAiGate(), '')->isAvailable());
        $http->available = false;
        self::assertFalse($this->provider($http, new FakeAiGate())->isAvailable());
        self::assertNull($http->url);
    }

    public function testInvalidUtf8IsSubstitutedAndSecretsAreExcludedFromDebugOutput(): void
    {
        $http = new FakeAiHttp(new HttpResponse(200, '{"choices":[{"message":{"content":"{}"}}]}'));
        $provider = $this->provider($http, new FakeAiGate(), 'sk-test-DO-NOT-ECHO-123');
        $provider->enrich($this->message("Parish notice \xFF continued"), new ParseResult());
        self::assertSame(1, preg_match('//u', $http->body));
        $request = json_decode($http->body, true);
        $email = json_decode($request['messages'][1]['content'], true);
        self::assertSame("Feast\n\nParish notice " . "\xEF\xBF\xBD" . ' continued', $email['untrusted_email_text']);
        ob_start();
        var_dump($provider);
        $debug = (string) ob_get_clean();
        self::assertStringNotContainsString('sk-test-DO-NOT-ECHO-123', $debug);
        self::assertStringNotContainsString('sk-test-DO-NOT-ECHO-123', print_r($provider, true));
    }

    public function testLocalOllamaSupportsEmptyKeyButExternalHttpIsRejected(): void
    {
        $provider = new OpenAiCompatibleProvider('http://localhost:11434/v1', 'llama3', '', 'ollama', new FakeAiHttp($this->response([])), new FakeAiGate());
        self::assertTrue($provider->isAvailable());
        $this->expectException(\InvalidArgumentException::class);
        new OpenAiCompatibleProvider('http://example.test/v1', 'llama3', '', 'custom', new FakeAiHttp($this->response([])), new FakeAiGate());
    }
}

final class FakeAiHttp implements HttpClientInterface
{
    public ?string $url = null;
    public array $headers = [];
    public ?string $body = null;
    public ?int $timeout = null;
    public bool $available = true;

    public function __construct(private HttpResponse|RuntimeException $response)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function post(string $url, array $headers, string $body, int $timeout): HttpResponse
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->timeout = $timeout;
        if ($this->response instanceof RuntimeException) {
            throw $this->response;
        }
        return $this->response;
    }
}

final class FakeAiGate implements AiCallGateInterface
{
    public bool $available = true;
    public int $calls = 0;
    public array $backoffs = [];

    public function reserve(): bool
    {
        ++$this->calls;
        return $this->available;
    }

    public function backOff(int $seconds): void
    {
        $this->backoffs[] = $seconds;
    }
}
