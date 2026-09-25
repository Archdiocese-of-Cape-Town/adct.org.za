<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

final class StreamTransport implements TransportInterface
{
    private const MAX_RESPONSE_LINE_BYTES = 1048576;

    /** @var resource|null */
    private $stream = null;

    public function connect(MailboxConnectionConfig $config): void
    {
        if (is_resource($this->stream)) {
            throw new ConnectionFailed();
        }

        $scheme = $config->encryption === MailboxEncryption::SSL ? 'ssl' : 'tcp';
        $host = $this->formatHost($config->host);
        $address = sprintf('%s://%s:%d', $scheme, $host, $config->port);
        $sslOptions = [
            'verify_peer' => $config->verifyPeer,
            'verify_peer_name' => $config->verifyPeer,
            'allow_self_signed' => ! $config->verifyPeer,
            'peer_name' => trim($config->host, '[]'),
            'SNI_enabled' => true,
        ];
        $context = stream_context_create(['ssl' => $sslOptions]);
        $errorNumber = 0;
        $errorMessage = '';
        $stream = @stream_socket_client(
            $address,
            $errorNumber,
            $errorMessage,
            $config->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! is_resource($stream)) {
            if (
                $config->encryption === MailboxEncryption::SSL
                && preg_match('/ssl|tls|certificate|crypto|handshake/i', $errorMessage) === 1
            ) {
                throw new TlsFailed();
            }

            throw new ConnectionFailed();
        }

        $this->stream = $stream;

        if (! @stream_set_timeout($this->stream, $config->readTimeout)) {
            $this->close();
            throw new ConnectionFailed();
        }
    }

    public function enableTls(): void
    {
        $this->requireStream();
        $result = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($result !== true) {
            $metadata = stream_get_meta_data($this->stream);

            if ($metadata['timed_out'] ?? false) {
                throw new Timeout();
            }

            throw new TlsFailed();
        }
    }

    public function write(string $data): void
    {
        $this->requireStream();
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = @fwrite($this->stream, substr($data, $offset));

            if ($written === false || $written === 0) {
                $metadata = stream_get_meta_data($this->stream);

                if ($metadata['timed_out'] ?? false) {
                    throw new Timeout();
                }

                throw new ConnectionFailed();
            }

            $offset += $written;
        }
    }

    public function readLine(): string
    {
        $this->requireStream();
        $line = '';

        do {
            $chunk = @fgets($this->stream, 8192);

            if ($chunk === false) {
                $this->throwReadFailure();
            }

            $line .= $chunk;

            if (strlen($line) > self::MAX_RESPONSE_LINE_BYTES) {
                throw new ProtocolError('The mail server sent an oversized response line.');
            }
        } while (! str_ends_with($chunk, "\n"));

        return $line;
    }

    public function readBytes(int $length): string
    {
        $this->requireStream();

        if ($length < 0) {
            throw new ProtocolError('The mail server requested an invalid literal length.');
        }

        $bytes = '';
        $offset = 0;

        while ($offset < $length) {
            $remaining = $length - $offset;
            $chunk = @fread($this->stream, min(8192, $remaining));

            if ($chunk === false || $chunk === '') {
                $this->throwReadFailure();
            }

            $bytes .= $chunk;
            $offset += strlen($chunk);
        }

        return $bytes;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    private function requireStream(): void
    {
        if (! is_resource($this->stream)) {
            throw new ConnectionFailed();
        }
    }

    private function throwReadFailure(): never
    {
        if (! is_resource($this->stream)) {
            throw new ConnectionFailed();
        }

        $metadata = stream_get_meta_data($this->stream);

        if ($metadata['timed_out'] ?? false) {
            throw new Timeout();
        }

        if (feof($this->stream)) {
            throw new ProtocolError('The mail server closed the connection unexpectedly.');
        }

        throw new ConnectionFailed();
    }

    private function formatHost(string $host): string
    {
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }

        return $host;
    }
}
