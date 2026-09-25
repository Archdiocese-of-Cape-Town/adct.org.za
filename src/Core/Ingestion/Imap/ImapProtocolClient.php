<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

use InvalidArgumentException;

final class ImapProtocolClient
{
    private int $nextTag = 0;

    /** @var list<string> */
    private array $capabilities = [];

    private bool $connected = false;

    private ?MailboxConnectionConfig $config = null;

    public function __construct(private readonly TransportInterface $transport)
    {
    }

    public function connect(MailboxConnectionConfig $config): void
    {
        $this->config = $config;

        try {
            $this->transport->connect($config);
            $greeting = $this->readResponse();

            if (preg_match('/^\*\s+(OK|PREAUTH)\b/i', $greeting, $greetingMatch) !== 1) {
                throw new ProtocolError('The mail server sent an unexpected greeting.');
            }

            if ($config->encryption === MailboxEncryption::STARTTLS) {
                $capabilityResult = $this->sendCommand('CAPABILITY');
                $this->requireOkay($capabilityResult, 'Could not read the mail server capabilities.');
                $this->capabilities = $this->parseCapabilities($capabilityResult->responses);

                if (! in_array('STARTTLS', $this->capabilities, true)) {
                    throw new TlsFailed();
                }

                $startTlsResult = $this->sendCommand('STARTTLS');

                if ($startTlsResult->status !== 'OK') {
                    throw new TlsFailed();
                }

                $this->transport->enableTls();
            }

            if (strtoupper($greetingMatch[1]) !== 'PREAUTH') {
                $login = 'LOGIN '
                    . self::quoteString($config->username)
                    . ' '
                    . self::quoteString($config->password);
                $loginResult = $this->sendCommand($login);

                if ($loginResult->status !== 'OK') {
                    throw new AuthenticationFailed();
                }
            }

            $capabilityResult = $this->sendCommand('CAPABILITY');
            $this->requireOkay($capabilityResult, 'Could not read the mail server capabilities.');
            $this->capabilities = $this->parseCapabilities($capabilityResult->responses);
            $this->connected = true;
        } catch (MailboxException $exception) {
            $this->transport->close();
            throw $exception;
        }
    }

    public function execute(string $command): ImapCommandResult
    {
        if (! $this->connected) {
            throw new ProtocolError('The mailbox connection is closed.');
        }

        if (preg_match('/[\x00\r\n]/', $command) === 1) {
            throw new InvalidArgumentException('The IMAP command contains an unsupported control character.');
        }

        return $this->sendCommand($command);
    }

    public function requireOkay(ImapCommandResult $result, string $message): void
    {
        if ($result->status !== 'OK') {
            throw new ProtocolError($message);
        }
    }

    public function supports(string $capability): bool
    {
        return in_array(strtoupper($capability), $this->capabilities, true);
    }

    public static function quoteString(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('IMAP quoted strings cannot contain control characters.');
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    public function logout(): void
    {
        if (! $this->connected) {
            $this->transport->close();

            return;
        }

        try {
            $result = $this->sendCommand('LOGOUT');
            $this->requireOkay($result, 'The mail server could not close the mailbox connection.');
        } finally {
            $this->connected = false;
            $this->transport->close();
        }
    }

    private function sendCommand(string $command): ImapCommandResult
    {
        $tag = sprintf('A%04d', ++$this->nextTag);
        $this->transport->write($tag . ' ' . $command . "\r\n");
        $responses = [];

        while (true) {
            $response = $this->readResponse();
            $line = rtrim($response, "\r\n");

            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line, $statusMatch) === 1) {
                return new ImapCommandResult(strtoupper($statusMatch[1]), $responses);
            }

            $responses[] = $response;
        }
    }

    private function readResponse(): string
    {
        $line = $this->transport->readLine();

        if (! str_ends_with($line, "\n")) {
            throw new ProtocolError('The mail server returned an incomplete response line.');
        }

        $response = $line;

        while (preg_match('/\{(\d+)\+?\}\r?\n$/', $line, $literalMatch) === 1) {
            $literalLength = filter_var($literalMatch[1], FILTER_VALIDATE_INT);

            if ($literalLength === false || $literalLength < 0) {
                throw new ProtocolError('The mail server returned an invalid literal length.');
            }

            $maximum = $this->config?->maxMessageSizeBytes ?? MailboxConnectionConfig::DEFAULT_MAX_MESSAGE_SIZE_BYTES;

            if ($literalLength > $maximum) {
                $this->connected = false;
                $this->transport->close();
                throw new MessageTooLarge($literalLength, $maximum);
            }

            $response .= $this->transport->readBytes($literalLength);
            $line = $this->transport->readLine();

            if (! str_ends_with($line, "\n")) {
                throw new ProtocolError('The mail server returned an incomplete literal response.');
            }

            $response .= $line;
        }

        return $response;
    }

    /**
     * @param list<string> $responses
     * @return list<string>
     */
    private function parseCapabilities(array $responses): array
    {
        foreach ($responses as $response) {
            if (preg_match('/^\*\s+CAPABILITY\s+(.+?)\s*$/im', rtrim($response, "\r\n"), $matches) !== 1) {
                continue;
            }

            return array_values(array_unique(array_map('strtoupper', preg_split('/\s+/', trim($matches[1])) ?: [])));
        }

        return [];
    }
}
