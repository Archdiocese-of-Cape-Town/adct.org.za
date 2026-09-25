<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion\Imap;

use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\Imap\ProtocolError;
use ADCT\ParishIntake\Core\Ingestion\Imap\Timeout;
use ADCT\ParishIntake\Core\Ingestion\Imap\TransportInterface;
use Throwable;

final class ScriptedTransport implements TransportInterface
{
    /** @var list<string> */
    public array $writes = [];

    public bool $tlsEnabled = false;

    public bool $closed = false;

    public ?MailboxConnectionConfig $config = null;

    private int $offset = 0;

    private bool $failureThrown = false;

    public function __construct(
        private string $incoming,
        private ?Throwable $readFailure = null,
        private ?Throwable $connectFailure = null,
    ) {
    }

    public function connect(MailboxConnectionConfig $config): void
    {
        if ($this->connectFailure !== null) {
            throw $this->connectFailure;
        }

        $this->config = $config;
    }

    public function enableTls(): void
    {
        $this->tlsEnabled = true;
    }

    public function write(string $data): void
    {
        $this->writes[] = $data;
    }

    public function readLine(): string
    {
        if ($this->readFailure !== null && ! $this->failureThrown) {
            $this->failureThrown = true;
            throw $this->readFailure;
        }

        $newline = strpos($this->incoming, "\n", $this->offset);

        if ($newline === false) {
            throw new ProtocolError('The scripted server ran out of response data.');
        }

        $line = substr($this->incoming, $this->offset, $newline - $this->offset + 1);
        $this->offset = $newline + 1;

        return $line;
    }

    public function readBytes(int $length): string
    {
        $bytes = substr($this->incoming, $this->offset, $length);

        if (strlen($bytes) !== $length) {
            throw new ProtocolError('The scripted server returned an incomplete literal.');
        }

        $this->offset += $length;

        return $bytes;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
