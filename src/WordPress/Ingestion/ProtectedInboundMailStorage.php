<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Ingestion;

use ADCT\ParishIntake\Core\Ports\InboundMailStorageInterface;
use ADCT\ParishIntake\Core\Ports\InboundHeaderStorageInterface;
use ADCT\ParishIntake\Core\Ingestion\InboundHeaderBlockParser;
use ADCT\ParishIntake\Core\Ingestion\PermanentInboundHeaderReadException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ProtectedInboundMailStorage implements InboundMailStorageInterface, InboundHeaderStorageInterface
{
    private const DIRECTORY_NAME = 'adct-parish-intake';
    private const PRIVATE_SUBDIRECTORY = 'private';
    private const PROTECTION_MARKER = '# ADCT Parish Intake private files';

    private ?string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory;
    }

    public function storeRawMessage(string $rawMessage): string
    {
        return $this->store($rawMessage, 'eml');
    }

    public function storeAttachment(string $content, string $extension): string
    {
        if (! in_array($extension, ['pdf', 'jpg', 'png', 'webp', 'heic', 'heif'], true)) {
            throw new InvalidArgumentException('The attachment extension is not permitted.');
        }

        return $this->store($content, $extension);
    }

    public function delete(string $relativePath): void
    {
        if (preg_match('/\A[a-f0-9]{64}\.(?:eml|pdf|jpg|png|webp|heic|heif)\z/', $relativePath) !== 1) {
            throw new InvalidArgumentException('The private inbound file path is invalid.');
        }

        $path = $this->directoryPath() . DIRECTORY_SEPARATOR . $relativePath;

        if (! is_file($path)) {
            return;
        }

        if (! unlink($path)) {
            throw new RuntimeException('A private inbound file could not be removed.');
        }
    }

    public function readHeaderBlock(string $relativePath): string
    {
        if (preg_match('/\A[a-f0-9]{64}\.eml\z/', $relativePath) !== 1) {
            throw new PermanentInboundHeaderReadException('The private inbound header path is invalid.');
        }

        $directory = $this->directoryPath();

        if (! is_dir($directory)) {
            throw new RuntimeException('The private inbound storage directory is unavailable.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . $relativePath;

        if (! is_file($path)) {
            throw new PermanentInboundHeaderReadException('The private inbound email file is missing.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('A private inbound email header could not be opened.');
        }

        $headerBlock = '';
        $bytesRead = 0;

        try {
            while (! feof($handle)) {
                $line = fgets($handle, 8193);

                if ($line === false) {
                    if (! feof($handle)) {
                        throw new RuntimeException('The private inbound email header could not be read completely.');
                    }

                    break;
                }

                $bytesRead += strlen($line);

                if ($bytesRead > InboundHeaderBlockParser::MAX_HEADER_BYTES) {
                    throw new PermanentInboundHeaderReadException(
                        'The private inbound email header exceeds its size limit.'
                    );
                }

                if ($line === "\r\n" || $line === "\n" || $line === "\r") {
                    break;
                }

                $headerBlock .= $line;
            }
        } finally {
            fclose($handle);
        }

        return $headerBlock;
    }

    private function store(string $content, string $extension): string
    {
        $directory = $this->directoryPath();
        $this->ensureProtectedDirectory($directory);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $relativePath = bin2hex(random_bytes(32)) . '.' . $extension;
            $absolutePath = $directory . DIRECTORY_SEPARATOR . $relativePath;
            $handle = fopen($absolutePath, 'xb');

            if ($handle === false) {
                if (file_exists($absolutePath)) {
                    continue;
                }

                throw new RuntimeException('A private inbound file could not be created.');
            }

            try {
                $this->writeAll($handle, $content);

                if (! fflush($handle)) {
                    throw new RuntimeException('A private inbound file could not be flushed to disk.');
                }
            } catch (Throwable $failure) {
                fclose($handle);
                if (is_file($absolutePath) && ! unlink($absolutePath)) {
                    throw new RuntimeException(
                        'A partial private inbound file could not be removed.',
                        0,
                        $failure
                    );
                }
                throw $failure;
            }

            fclose($handle);

            return $relativePath;
        }

        throw new RuntimeException('A unique private inbound file name could not be allocated.');
    }

    /**
     * @param resource $handle
     */
    private function writeAll($handle, string $content): void
    {
        $length = strlen($content);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('A private inbound file could not be written completely.');
            }

            $offset += $written;
        }
    }

    private function ensureProtectedDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            $created = function_exists('wp_mkdir_p')
                ? wp_mkdir_p($directory)
                : mkdir($directory, 0700, true);

            if (! $created && ! is_dir($directory)) {
                throw new RuntimeException('The private inbound storage directory could not be created.');
            }
        }

        if (! is_dir($directory)) {
            throw new RuntimeException('The private inbound storage path is not a directory.');
        }

        $this->ensureDenyRules($directory);
        $this->ensureIndexFile($directory);
    }

    private function ensureDenyRules(string $directory): void
    {
        $path = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        $rules = self::PROTECTION_MARKER . "\n"
            . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";

        if (is_file($path)) {
            $existing = file_get_contents($path);

            if (! is_string($existing)) {
                throw new RuntimeException('The inbound storage access rules could not be read.');
            }

            if (
                preg_match(
                    '/^[\t ]*(?:Require[\t ]+all[\t ]+denied|Deny[\t ]+from[\t ]+all)[\t ]*$/mi',
                    $existing
                ) === 1
            ) {
                return;
            }

            $rules = rtrim($existing) . "\n\n" . $rules;
        }

        if (file_put_contents($path, $rules, LOCK_EX) === false) {
            throw new RuntimeException('The inbound storage access rules could not be written.');
        }
    }

    private function ensureIndexFile(string $directory): void
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'index.php';

        if (is_file($path)) {
            return;
        }

        if (file_put_contents($path, "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX) === false) {
            throw new RuntimeException('The inbound storage index file could not be written.');
        }
    }

    private function directoryPath(): string
    {
        if ($this->directory !== null) {
            return rtrim($this->directory, DIRECTORY_SEPARATOR);
        }

        if (! function_exists('wp_upload_dir')) {
            throw new RuntimeException('WordPress upload storage is not available.');
        }

        $uploads = wp_upload_dir();

        if (! is_array($uploads) || ! empty($uploads['error']) || ! isset($uploads['basedir'])) {
            throw new RuntimeException('The WordPress upload directory is not available.');
        }

        $basedir = $uploads['basedir'];

        if (! is_string($basedir) || trim($basedir) === '') {
            throw new RuntimeException('The WordPress upload directory is invalid.');
        }

        $this->directory = rtrim($basedir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME
            . DIRECTORY_SEPARATOR . self::PRIVATE_SUBDIRECTORY;

        return $this->directory;
    }
}
