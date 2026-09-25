<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Support;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use RuntimeException;

final class FixtureDirectorySnapshotLoader
{
    /**
     * @param array<string, mixed> $expected
     */
    public function providerFor(array $expected, string $emailPath): ?DirectorySnapshotProviderInterface
    {
        $fileName = $expected['directory_snapshot'] ?? null;

        if ($fileName === null) {
            return null;
        }

        if (! is_string($fileName) || $fileName === '' || basename($fileName) !== $fileName) {
            throw new RuntimeException('directory_snapshot must name a JSON file in tests/fixtures.');
        }

        $path = dirname(dirname($emailPath)) . DIRECTORY_SEPARATOR . $fileName;
        $json = file_get_contents($path);

        if (! is_string($json)) {
            throw new RuntimeException('Unable to read directory snapshot fixture: ' . $fileName);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('A directory snapshot fixture must contain a JSON object.');
        }

        return new FixtureDirectorySnapshotProvider(DirectorySnapshot::fromArray($data));
    }
}

final class FixtureDirectorySnapshotProvider implements DirectorySnapshotProviderInterface
{
    public function __construct(private DirectorySnapshot $snapshot)
    {
    }

    public function getSnapshot(): DirectorySnapshot
    {
        return $this->snapshot;
    }
}
