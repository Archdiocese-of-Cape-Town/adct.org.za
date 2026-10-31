<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Publishing;

use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DomainException;
use RuntimeException;

final class SourceMaterialLock
{
    private array $active = [];

    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function run(int $eventId, callable $mutation): mixed
    {
        $this->acquire($eventId);
        try {
            return $mutation();
        } finally {
            $this->release($eventId);
        }
    }

    public function acquire(int $eventId): void
    {
        $name = substr('adct-source-' . hash('sha256', $this->database->prefix() . ':' . $eventId), 0, 64);
        if (isset($this->active[$name])) {
            throw new DomainException('Source material changes are already running for this event. Try again in a moment.');
        }
        $this->database->clearLastError();
        $owner = $this->database->getRow($this->database->prepare(
            'SELECT IS_USED_LOCK(%s) AS owner, CONNECTION_ID() AS connection_id', $name
        ));
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The source material lock owner could not be checked.');
        }
        if ($owner !== null && $owner['owner'] !== null
            && (int) $owner['owner'] === (int) $owner['connection_id']) {
            throw new DomainException('Source material changes are already running for this event. Try again in a moment.');
        }
        $result = $this->database->getRow($this->database->prepare('SELECT GET_LOCK(%s, 0) AS acquired', $name));
        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The source material lock could not be acquired.');
        }
        if ((int) ($result['acquired'] ?? 0) !== 1) {
            throw new DomainException('Source material changes are already running for this event. Try again in a moment.');
        }
        $this->active[$name] = true;
        try {
            clean_post_cache($eventId);
        } catch (\Throwable $failure) {
            $this->release($eventId);
            throw $failure;
        }
    }

    public function release(int $eventId): void
    {
        $name = substr('adct-source-' . hash('sha256', $this->database->prefix() . ':' . $eventId), 0, 64);
        unset($this->active[$name]);
        $this->database->clearLastError();
        $result = $this->database->getRow($this->database->prepare('SELECT RELEASE_LOCK(%s) AS released', $name));
        if ($this->database->lastError() !== '' || (int) ($result['released'] ?? 0) !== 1) {
            throw new RuntimeException('The source material lock could not be released.');
        }
        clean_post_cache($eventId);
    }
}
