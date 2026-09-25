<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Tests\Support\FixtureDirectorySnapshotLoader;
use ADCT\ParishIntake\Tests\Support\EmailFixtureLoader;
use ADCT\ParishIntake\Tests\Support\FixtureComparator;

require dirname(__DIR__) . '/vendor/autoload.php';

$fixtureDirectory = dirname(__DIR__)
    . DIRECTORY_SEPARATOR . 'tests'
    . DIRECTORY_SEPARATOR . 'fixtures'
    . DIRECTORY_SEPARATOR . 'emails';
$paths = glob($fixtureDirectory . DIRECTORY_SEPARATOR . '*.eml') ?: [];
sort($paths, SORT_STRING);

if ($paths === []) {
    throw new RuntimeException('No parser email fixtures were found in ' . $fixtureDirectory);
}

$loader = new EmailFixtureLoader();
$directorySnapshotLoader = new FixtureDirectorySnapshotLoader();
$totalCorrect = 0;
$totalChecks = 0;

echo "| Fixture | Fields correct | Score |\n";
echo "|---|---:|---:|\n";

foreach ($paths as $path) {
    $expectedPath = substr($path, 0, -4) . '.expected.json';
    $expectedJson = file_get_contents($expectedPath);

    if (! is_string($expectedJson)) {
        throw new RuntimeException('Unable to read expected JSON for ' . basename($path));
    }

    $expected = json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($expected)) {
        throw new RuntimeException('Expected JSON must be an object: ' . basename($expectedPath));
    }

    $directorySnapshots = $directorySnapshotLoader->providerFor($expected, $path);
    $outcome = (new PipelineFactory(null, $directorySnapshots))
        ->create()
        ->parseAll($loader->load($path));
    $actual = array_merge(
        $outcome->getPrimaryResult()->toArray(),
        $outcome->toArray()
    );
    $checks = FixtureComparator::compare($expected, $actual);
    $correct = count(array_filter($checks, static fn (array $check): bool => $check['matches']));
    $count = count($checks);
    $totalCorrect += $correct;
    $totalChecks += $count;

    printf(
        "| %s | %d/%d | %d%% |\n",
        basename($path, '.eml'),
        $correct,
        $count,
        $count > 0 ? (int) round($correct * 100 / $count) : 100
    );
}

printf(
    "| **Overall** | **%d/%d** | **%d%%** |\n",
    $totalCorrect,
    $totalChecks,
    $totalChecks > 0 ? (int) round($totalCorrect * 100 / $totalChecks) : 100
);
