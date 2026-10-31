<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Support;

use RuntimeException;

final class PdfFixtureIntegrity
{
    public static function verify(string $directory): void
    {
        $manifestPath = $directory . '/sha256.json';
        if (! is_readable($manifestPath)) {
            throw new RuntimeException('PDF fixture checksum manifest is missing or unreadable.');
        }
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || $manifest === []) {
            throw new RuntimeException('PDF fixture checksum manifest must be a non-empty map.');
        }
        $names = array_keys($manifest);
        $paths = glob($directory . '/*.pdf');
        if ($paths === false) {
            throw new RuntimeException('Cannot list PDF fixtures.');
        }
        $actualNames = array_map('basename', $paths);
        sort($names);
        sort($actualNames);
        if ($names !== $actualNames) {
            throw new RuntimeException('PDF fixture list differs from the checksum manifest; check missing or unlisted files.');
        }
        foreach ($manifest as $name => $expected) {
            if (! is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
                throw new RuntimeException('Invalid PDF fixture checksum: ' . $name);
            }
            $path = $directory . '/' . $name;
            if (! is_readable($path) || is_link($path) || hash_file('sha256', $path) !== $expected) {
                throw new RuntimeException(
                    'PDF fixture integrity failed: ' . $name . '. Bytes differ from the reviewed checksum. '
                    . 'Git status may still be clean after Windows CRLF conversion. '
                    . 'Run node scripts/check-pdf-fixtures.mjs on the host; '
                    . 'use --export NEW_DIRECTORY for verified copies. No files were changed.'
                );
            }
        }
    }
}
