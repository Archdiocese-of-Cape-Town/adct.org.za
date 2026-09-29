<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use InvalidArgumentException;
use RuntimeException;

interface InboundMailStorageReaderInterface extends InboundMailStorageInterface
{
    public function readRawMessage(string $relativePath): string;

    /**
     * Resolve a stored attachment's relative path to the absolute path on disk.
     *
     * Callers that need to hand a file to a parser cannot do this themselves:
     * the private directory is only known to the storage adapter, and the
     * relative name on the attachment row is deliberately not a usable path.
     *
     * @throws InvalidArgumentException if the path is not a stored attachment name
     * @throws RuntimeException if the stored file is missing
     */
    public function resolveAttachmentPath(string $relativePath): string;
}
