<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Parsing;

use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Tests\Support\EmailFixtureLoader;
use ADCT\ParishIntake\Tests\Support\FixtureComparator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EmailFixtureCorpusTest extends TestCase
{
    public static function fixturePaths(): array
    {
        $directory = dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'emails';
        $paths = glob($directory . DIRECTORY_SEPARATOR . '*.eml') ?: [];
        $expectedPaths = glob($directory . DIRECTORY_SEPARATOR . '*.expected.json') ?: [];
        sort($paths, SORT_STRING);

        if ($paths === []) {
            throw new RuntimeException('No parser email fixtures were found in ' . $directory);
        }

        if (count($paths) !== count($expectedPaths)) {
            throw new RuntimeException('Every parser email fixture must have exactly one expected JSON file.');
        }

        $fixtures = [];

        foreach ($paths as $path) {
            $expectedPath = substr($path, 0, -4) . '.expected.json';

            if (! is_file($expectedPath)) {
                throw new RuntimeException('Missing expected JSON for parser fixture: ' . basename($path));
            }

            $fixtures[basename($path, '.eml')] = [$path];
        }

        return $fixtures;
    }

    public function testCorpusContainsAtLeastTenFixturePairs(): void
    {
        self::assertGreaterThanOrEqual(10, count(self::fixturePaths()));
    }

    #[DataProvider('fixturePaths')]
    public function testFixtureMatchesItsExpectedFields(string $emailPath): void
    {
        $expectedPath = substr($emailPath, 0, -4) . '.expected.json';
        $expectedJson = file_get_contents($expectedPath);

        if (! is_string($expectedJson)) {
            self::fail('Unable to read expected JSON for ' . basename($emailPath));
        }

        $expected = json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($expected)) {
            self::fail('Expected JSON must be an object: ' . basename($expectedPath));
        }

        $knownFailures = $expected['known_failures'] ?? [];

        if (! is_array($knownFailures)) {
            self::fail('known_failures must be an object: ' . basename($expectedPath));
        }

        foreach ($knownFailures as $field => $issue) {
            if (! is_string($issue) || ! preg_match('/^#\d+$/', $issue)) {
                self::fail('Invalid known failure for ' . $field . ' in ' . basename($expectedPath));
            }
        }

        $message = (new EmailFixtureLoader())->load($emailPath);

        self::assertInstanceOf(DateTimeImmutable::class, $message->getReceivedAt());

        $actual = (new PipelineFactory())->create()->parse($message)->toArray();
        $checks = FixtureComparator::compare($expected, $actual);
        $mismatches = array_filter($checks, static fn (array $check): bool => ! $check['matches']);
        $unknownMismatches = [];

        foreach ($mismatches as $path => $check) {
            if (FixtureComparator::knownIssueForPath((string) $path, $knownFailures) === null) {
                $unknownMismatches[$path] = $check;
            }
        }

        if ($unknownMismatches !== []) {
            self::fail($this->formatDiff(basename($emailPath), $mismatches, $knownFailures));
        }

        if ($mismatches !== []) {
            $issues = [];
            $details = [];

            foreach ($mismatches as $path => $check) {
                $issue = FixtureComparator::knownIssueForPath((string) $path, $knownFailures);
                $issues[$issue] = true;
                $details[] = sprintf(
                    '%s (expected %s, got %s) - %s',
                    $path,
                    FixtureComparator::describeValue($check['expected']),
                    FixtureComparator::describeValue($check['actual']),
                    $this->issueUrl($issue)
                );
            }

            self::markTestIncomplete(sprintf(
                "Known parser difference in %s; see %s.\n%s",
                basename($emailPath),
                implode(', ', array_keys($issues)),
                implode("\n", $details)
            ));
        }
    }

    private function formatDiff(string $fixtureName, array $mismatches, array $knownFailures): string
    {
        $lines = ['Parser fixture mismatch: ' . $fixtureName];

        foreach ($mismatches as $path => $check) {
            $issue = FixtureComparator::knownIssueForPath((string) $path, $knownFailures);
            $tracking = $issue === null ? 'untracked' : $this->issueUrl($issue);
            $lines[] = sprintf(
                '  %s: expected %s; got %s (%s)',
                $path,
                FixtureComparator::describeValue($check['expected']),
                FixtureComparator::describeValue($check['actual']),
                $tracking
            );
        }

        return implode("\n", $lines);
    }

    private function issueUrl(string $issue): string
    {
        return $issue . ' (https://github.com/Archdiocese-of-Cape-Town/adct.org.za/issues/' . substr($issue, 1) . ')';
    }
}
