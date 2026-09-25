<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion\Imap;

use ADCT\ParishIntake\Core\Ingestion\MailboxSearchCriteria;
use ADCT\ParishIntake\Core\Ingestion\RawMailMessage;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use DateTimeImmutable;
use InvalidArgumentException;

final class ImapMailbox implements MailboxInterface
{
    private ImapProtocolClient $client;

    private ?string $selectedFolder = null;

    public function __construct(
        private readonly MailboxConnectionConfig $config,
        ?TransportInterface $transport = null,
    ) {
        $this->client = new ImapProtocolClient($transport ?? new StreamTransport());
        $this->client->connect($config);
    }

    public function listFolders(): array
    {
        $result = $this->client->execute('LIST "" "*"');
        $this->client->requireOkay($result, 'The mailbox folders could not be listed.');
        $folders = [];

        foreach ($result->responses as $response) {
            $line = rtrim($response, "\r\n");

            if (preg_match('/^\*\s+LIST\s+\([^)]*\)\s*(.*)$/i', $line, $matches) !== 1) {
                continue;
            }

            $offset = 0;
            $this->readImapValue($matches[1], $offset);
            $folder = $this->readImapValue($matches[1], $offset);

            if ($folder !== null) {
                $folders[] = $folder;
            }
        }

        return array_values(array_unique($folders));
    }

    public function ensureFolder(string $folder): void
    {
        $quotedFolder = $this->quoteFolder($folder);

        if (in_array($folder, $this->listFolders(), true)) {
            return;
        }

        $result = $this->client->execute('CREATE ' . $quotedFolder);
        $this->client->requireOkay($result, 'The requested mailbox folder could not be created.');
    }

    public function search(MailboxSearchCriteria $criteria): array
    {
        $this->selectFolder($this->inboxFolder());
        $searchKeys = [];

        if ($criteria->unseen) {
            $searchKeys[] = 'UNSEEN';
        }

        if ($criteria->since !== null) {
            $searchKeys[] = 'SINCE ' . $criteria->since->format('d-M-Y');
        }

        if ($searchKeys === []) {
            $searchKeys[] = 'ALL';
        }

        $result = $this->client->execute('UID SEARCH ' . implode(' ', $searchKeys));
        $this->client->requireOkay($result, 'The mailbox messages could not be searched.');
        $uids = [];

        foreach ($result->responses as $response) {
            $line = rtrim($response, "\r\n");

            if (preg_match('/^\*\s+SEARCH(?:\s+(.*))?$/i', $line, $matches) !== 1) {
                continue;
            }

            $values = [];

            if (isset($matches[1]) && trim($matches[1]) !== '') {
                $values = preg_split('/\s+/', trim($matches[1])) ?: [];
            }

            foreach ($values as $value) {
                $uid = filter_var($value, FILTER_VALIDATE_INT);

                if ($uid === false || $uid < 1) {
                    throw new ProtocolError('The mailbox server returned an invalid message identifier.');
                }

                $uids[] = $uid;
            }
        }

        return array_values(array_unique($uids));
    }

    public function fetch(int $uid): RawMailMessage
    {
        $this->validateUid($uid);
        $this->selectFolder($this->inboxFolder());

        $metadataResult = $this->client->execute(
            sprintf('UID FETCH %d (UID RFC822.SIZE INTERNALDATE FLAGS)', $uid)
        );
        $this->client->requireOkay($metadataResult, 'The message details could not be fetched.');
        $metadata = $this->parseFetchResponse($metadataResult->responses, $uid, false);

        if ($metadata['size'] > $this->config->maxMessageSizeBytes) {
            throw new MessageTooLarge($metadata['size'], $this->config->maxMessageSizeBytes);
        }

        $bodyResult = $this->client->execute(
            sprintf('UID FETCH %d (UID RFC822.SIZE INTERNALDATE FLAGS BODY.PEEK[])', $uid)
        );
        $this->client->requireOkay($bodyResult, 'The raw email could not be fetched.');
        $message = $this->parseFetchResponse($bodyResult->responses, $uid, true);

        if ($message['size'] > $this->config->maxMessageSizeBytes) {
            throw new MessageTooLarge($message['size'], $this->config->maxMessageSizeBytes);
        }

        $raw = $message['raw'];

        if ($raw === null) {
            throw new ProtocolError('The mail server did not return the raw email body.');
        }

        $actualSize = strlen($raw);

        if ($actualSize > $this->config->maxMessageSizeBytes) {
            throw new MessageTooLarge($actualSize, $this->config->maxMessageSizeBytes);
        }

        if ($actualSize !== $message['size']) {
            throw new ProtocolError('The mail server returned an inconsistent email size.');
        }

        return new RawMailMessage(
            uid: $uid,
            raw: $raw,
            size: $actualSize,
            internalDate: $message['internalDate'],
            flags: $message['flags']
        );
    }

    public function move(int $uid, string $folder): void
    {
        $this->validateUid($uid);
        $this->selectFolder($this->inboxFolder());
        $destination = $this->quoteFolder($folder);

        if ($this->client->supports('MOVE')) {
            $result = $this->client->execute(sprintf('UID MOVE %d %s', $uid, $destination));
            $this->client->requireOkay($result, 'The message could not be moved to the requested folder.');

            return;
        }

        $copyResult = $this->client->execute(sprintf('UID COPY %d %s', $uid, $destination));
        $this->client->requireOkay($copyResult, 'The message could not be copied to the requested folder.');

        $storeResult = $this->client->execute(sprintf('UID STORE %d +FLAGS.SILENT (\\Deleted)', $uid));
        $this->client->requireOkay($storeResult, 'The source message could not be marked for removal.');

        $expunge = $this->client->supports('UIDPLUS')
            ? sprintf('UID EXPUNGE %d', $uid)
            : 'EXPUNGE';
        $expungeResult = $this->client->execute($expunge);
        $this->client->requireOkay($expungeResult, 'The source message could not be removed after copying.');
    }

    public function markSeen(int $uid): void
    {
        $this->validateUid($uid);
        $this->selectFolder($this->inboxFolder());
        $result = $this->client->execute(sprintf('UID STORE %d +FLAGS.SILENT (\\Seen)', $uid));
        $this->client->requireOkay($result, 'The message could not be marked as read.');
    }

    public function close(): void
    {
        $this->selectedFolder = null;
        $this->client->logout();
    }

    /**
     * @param list<string> $responses
     * @return array{uid: int, size: int, internalDate: DateTimeImmutable, flags: list<string>, raw: ?string}
     */
    private function parseFetchResponse(array $responses, int $requestedUid, bool $expectBody): array
    {
        foreach ($responses as $response) {
            if (preg_match('/^\*\s+\d+\s+FETCH\b/i', $response) !== 1) {
                continue;
            }

            $metadataResponse = $response;
            $raw = null;
            $bodyMarker = [];

            if (preg_match('/\bBODY(?:\.PEEK)?\[\]\s+\{(\d+)\}\r\n/i', $response, $bodyMarker, PREG_OFFSET_CAPTURE) === 1) {
                $literalLength = filter_var($bodyMarker[1][0], FILTER_VALIDATE_INT);
                $literalStart = $bodyMarker[0][1] + strlen($bodyMarker[0][0]);

                if ($literalLength === false || $literalLength < 0) {
                    throw new ProtocolError('The mail server returned an invalid message-body length.');
                }

                $raw = substr($response, $literalStart, $literalLength);

                if (strlen($raw) !== $literalLength) {
                    throw new ProtocolError('The mail server returned an incomplete raw email body.');
                }

                $metadataResponse = substr($response, 0, $bodyMarker[0][1])
                    . substr($response, $literalStart + $literalLength);
            } elseif ($expectBody) {
                continue;
            }

            if (preg_match('/\bUID\s+(\d+)\b/i', $metadataResponse, $uidMatch) !== 1) {
                throw new ProtocolError('The mail server did not identify the fetched message.');
            }

            $responseUid = filter_var($uidMatch[1], FILTER_VALIDATE_INT);

            if ($responseUid !== $requestedUid) {
                continue;
            }

            if (preg_match('/\bRFC822\.SIZE\s+(\d+)\b/i', $metadataResponse, $sizeMatch) !== 1) {
                throw new ProtocolError('The mail server did not return the message size.');
            }

            $size = filter_var($sizeMatch[1], FILTER_VALIDATE_INT);

            if ($size === false || $size < 0) {
                throw new ProtocolError('The mail server returned an invalid message size.');
            }

            if (preg_match('/\bINTERNALDATE\s+"([^"]+)"/i', $metadataResponse, $dateMatch) !== 1) {
                throw new ProtocolError('The mail server did not return the message date.');
            }

            $internalDate = DateTimeImmutable::createFromFormat('!d-M-Y H:i:s O', $dateMatch[1]);
            $dateErrors = DateTimeImmutable::getLastErrors();

            if (
                $internalDate === false
                || (
                    $dateErrors !== false
                    && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)
                )
            ) {
                throw new ProtocolError('The mail server returned an invalid message date.');
            }

            if (preg_match('/\bFLAGS\s+\(([^)]*)\)/i', $metadataResponse, $flagsMatch) !== 1) {
                throw new ProtocolError('The mail server did not return the message flags.');
            }

            $flags = [];

            if (trim($flagsMatch[1]) !== '') {
                $flags = preg_split('/\s+/', trim($flagsMatch[1])) ?: [];
            }

            return [
                'uid' => $responseUid,
                'size' => $size,
                'internalDate' => $internalDate,
                'flags' => array_values($flags),
                'raw' => $raw,
            ];
        }

        throw new ProtocolError('The requested message is no longer available in this mailbox.');
    }

    private function selectFolder(string $folder): void
    {
        if ($this->selectedFolder === $folder) {
            return;
        }

        $result = $this->client->execute('SELECT ' . ImapProtocolClient::quoteString($folder));
        $this->client->requireOkay($result, 'The requested mailbox folder could not be opened.');
        $this->selectedFolder = $folder;
    }

    private function inboxFolder(): string
    {
        return $this->config->folders['inbox'];
    }

    private function validateUid(int $uid): void
    {
        if ($uid < 1) {
            throw new InvalidArgumentException('An IMAP message UID must be a positive integer.');
        }
    }

    private function quoteFolder(string $folder): string
    {
        if (trim($folder) === '') {
            throw new InvalidArgumentException('Enter a mailbox folder name.');
        }

        return ImapProtocolClient::quoteString($folder);
    }

    private function readImapValue(string $input, int &$offset): ?string
    {
        $length = strlen($input);

        while (
            $offset < $length
            && preg_match('/[ \t\r\n\f\v]/', $input[$offset]) === 1
        ) {
            ++$offset;
        }

        if ($offset >= $length) {
            return null;
        }

        if ($input[$offset] === '"') {
            ++$offset;
            $value = '';

            while ($offset < $length) {
                $character = $input[$offset];

                if ($character === '\\') {
                    if ($offset + 1 >= $length) {
                        return null;
                    }

                    $value .= $input[$offset + 1];
                    $offset += 2;
                    continue;
                }

                ++$offset;

                if ($character === '"') {
                    return $value;
                }

                $value .= $character;
            }

            return null;
        }

        $start = $offset;

        while (
            $offset < $length
            && preg_match('/[ \t\r\n\f\v]/', $input[$offset]) !== 1
        ) {
            ++$offset;
        }

        $value = substr($input, $start, $offset - $start);

        return strtoupper($value) === 'NIL' ? null : $value;
    }
}
