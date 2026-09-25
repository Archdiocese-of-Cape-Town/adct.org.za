<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\AuthenticationResultsParser;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResults;
use ADCT\ParishIntake\Core\Ingestion\RawMessageInspector;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RawMessageInspectorTest extends TestCase
{
    public static function inboundMailFixtures(): array
    {
        $directory = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'inbound-mail';
        $paths = glob($directory . DIRECTORY_SEPARATOR . '*.eml') ?: [];
        sort($paths, SORT_STRING);

        if ($paths === []) {
            throw new RuntimeException('No inbound-mail fixtures were found in ' . $directory);
        }

        $fixtures = [];

        foreach ($paths as $path) {
            $expectedPath = substr($path, 0, -4) . '.expected.json';

            if (! is_file($expectedPath)) {
                throw new RuntimeException('Missing expected JSON for inbound-mail fixture: ' . basename($path));
            }

            $fixtures[basename($path, '.eml')] = [$path, $expectedPath];
        }

        return $fixtures;
    }

    public function testInboundMailFixturesCoverTheRequiredMessageKinds(): void
    {
        $fixtures = self::inboundMailFixtures();

        foreach ([
            'delivery-status-bounce',
            'list-message',
            'no-reply-sender',
            'ordinary-parish',
            'out-of-office',
            'spoofed-auth-results',
        ] as $fixtureName) {
            self::assertArrayHasKey($fixtureName, $fixtures);
        }
    }

    #[DataProvider('inboundMailFixtures')]
    public function testSyntheticFixturesProduceExpectedFlagsAndStructuredAuthenticationResults(
        string $emailPath,
        string $expectedPath
    ): void {
        $rawMessage = file_get_contents($emailPath);
        $expectedJson = file_get_contents($expectedPath);

        if (! is_string($rawMessage) || ! is_string($expectedJson)) {
            self::fail('Unable to read inbound-mail fixture ' . basename($emailPath));
        }

        $expected = json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);
        $inspected = (new RawMessageInspector())->inspect(
            $rawMessage,
            new DateTimeImmutable('2026-09-25T00:00:00+00:00')
        );

        self::assertSame($expected['classification'], $inspected->automationAssessment->classification);
        self::assertSame($expected['signals'], $inspected->automationAssessment->signals);
        self::assertSame($expected['blocks_confirmation'], $inspected->automationAssessment->blocksConfirmation());
        self::assertSame($expected['auth_results'], $inspected->authenticationResults->toArray());
    }

    public function testOnlyAnExplicitlyTrustedAuthservIdCanMarkVerdictsAsTrusted(): void
    {
        $rawMessage = $this->rawMessage('events@example.test', [
            'Authentication-Results: MX.Inbound.Example.Test; spf=pass; dkim=pass; dmarc=pass',
        ]);
        $fallback = new DateTimeImmutable('2026-09-25T00:00:00+00:00');
        $untrusted = (new RawMessageInspector())->inspect($rawMessage, $fallback)->authenticationResults;
        $trusted = (new RawMessageInspector(
            new AuthenticationResultsParser(['mx.inbound.example.test'])
        ))->inspect($rawMessage, $fallback)->authenticationResults;

        self::assertFalse($untrusted->hasTrustedPass());
        self::assertTrue($trusted->hasTrustedPass('spf'));
        self::assertTrue($trusted->hasTrustedPass('dkim'));
        self::assertTrue($trusted->hasTrustedPass('dmarc'));
    }

    public function testStoredAuthenticationResultsRejectVerdictsMissingTheirAuthservIdField(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthenticationResults::fromJson(
            '{"version":1,"spf":[{"result":"pass","trusted":false}],"dkim":[],"dmarc":[]}'
        );
    }

    #[DataProvider('automationSignalHeaders')]
    public function testAdditionalStandardAutomationSignalsBlockConfirmation(
        array $headers,
        string $expectedSignal
    ): void {
        $inspected = (new RawMessageInspector())->inspect(
            $this->rawMessage('office@example.test', $headers),
            new DateTimeImmutable('2026-09-25T00:00:00+00:00')
        );

        self::assertTrue($inspected->automationAssessment->blocksConfirmation());
        self::assertContains($expectedSignal, $inspected->automationAssessment->signals);
    }

    public static function automationSignalHeaders(): array
    {
        return [
            'precedence bulk' => [['Precedence: bulk'], 'precedence_bulk'],
            'precedence auto reply' => [['Precedence: auto_reply'], 'precedence_auto_reply'],
            'x autoresponder' => [['X-Autorespond: yes'], 'x_autorespond'],
            'empty return path' => [['Return-Path: <>'], 'empty_return_path'],
            'blank return path' => [['Return-Path:'], 'empty_return_path'],
            'automatic response suppression' => [
                ['X-Auto-Response-Suppress: All'],
                'x_auto_response_suppress',
            ],
            'list post header' => [['List-Post: <mailto:list@example.test>'], 'list_header'],
        ];
    }

    /**
     * @param list<string> $headers
     */
    private function rawMessage(string $senderEmail, array $headers): string
    {
        return implode("\r\n", array_merge([
            'From: Example Parish Office <' . $senderEmail . '>',
            'To: intake@example.test',
            'Date: Fri, 25 Sep 2026 04:00:00 +0000',
            'Message-ID: <inspector-test@example.test>',
            'Subject: Example parish notice',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ], $headers, [
            '',
            'A synthetic parish event notice.',
            '',
        ]));
    }
}
