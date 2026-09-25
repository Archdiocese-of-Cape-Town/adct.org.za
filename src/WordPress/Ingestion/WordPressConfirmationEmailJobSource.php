<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Ingestion;

use ADCT\ParishIntake\Core\Directory\SenderLookup;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ingestion\InboundHeaderBlockParser;
use ADCT\ParishIntake\Core\Ingestion\InvalidInboundHeaderBlockException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailBatch;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailCandidate;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailHeaderUnavailableException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResult;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailReason;
use ADCT\ParishIntake\Core\Ingestion\PermanentInboundHeaderReadException;
use ADCT\ParishIntake\Core\Ports\ConfirmationEmailJobSourceInterface;
use ADCT\ParishIntake\Core\Ports\InboundHeaderStorageInterface;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class WordPressConfirmationEmailJobSource implements ConfirmationEmailJobSourceInterface
{
    private const CANDIDATE_STATUS_DRAFT = 'draft';
    private const MESSAGE_STATUS_PARSED = 'parsed';

    private DateTimeZone $timezone;

    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly ParishContactStoreInterface $contacts,
        private readonly InboundHeaderStorageInterface $headerStorage,
        private readonly InboundHeaderBlockParser $headerParser = new InboundHeaderBlockParser(),
        ?DateTimeZone $timezone = null
    ) {
        $this->timezone = $timezone ?? new DateTimeZone('Africa/Johannesburg');
    }

    public function nextPending(): ?ConfirmationEmailBatch
    {
        $messagesTable = $this->tableName('adct_pi_inbound_messages');
        $candidatesTable = $this->tableName('adct_pi_event_candidates');
        $this->database->clearLastError();
        $message = $this->database->getRow($this->database->prepare(
            "SELECT m.id, m.source_id, m.sender_email, m.sender_name, m.subject,"
            . " m.received_at, m.raw_path, m.is_auto_reply"
            . " FROM {$messagesTable} m"
            . " WHERE m.status = %s AND m.confirmation_status IS NULL"
            . " AND EXISTS (SELECT 1 FROM {$candidatesTable} c"
            . " WHERE c.message_id = m.id AND c.status IN (%s, %s))"
            . ' ORDER BY m.id ASC LIMIT 1',
            self::MESSAGE_STATUS_PARSED,
            self::CANDIDATE_STATUS_DRAFT,
            'duplicate'
        ));

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('A pending event confirmation preview could not be selected.');
        }

        if ($message === null) {
            return null;
        }

        $messageId = $this->integerValue($message['id'] ?? null, 'inbound message ID');
        $sourceId = $this->integerValue($message['source_id'] ?? null, 'inbound source ID');
        $senderEmail = $this->nullableString($message['sender_email'] ?? null, 'sender email');
        $senderName = $this->nullableString($message['sender_name'] ?? null, 'sender name');
        $subject = $this->nullableString($message['subject'] ?? null, 'subject') ?? '';
        $rawPath = $this->nullableString($message['raw_path'] ?? null, 'raw message path');
        $receivedAt = $this->dateTime($message['received_at'] ?? null, 'received timestamp');
        $isAutoReply = $this->booleanValue($message['is_auto_reply'] ?? null, 'automated message marker');
        $candidateRows = $this->database->getResults($this->database->prepare(
            "SELECT id, fields, recurrence, confidence, notes, match_kind, match_event_id"
            . " FROM {$candidatesTable} WHERE message_id = %d AND status = %s"
            . ' ORDER BY block_index ASC, id ASC',
            $messageId,
            self::CANDIDATE_STATUS_DRAFT
        ));

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The event candidates for a confirmation preview could not be read.');
        }

        if ($candidateRows === []) {
            $duplicateRows = $this->database->getResults($this->database->prepare(
                "SELECT id FROM {$candidatesTable} WHERE message_id = %d AND status = %s LIMIT 1",
                $messageId,
                'duplicate'
            ));
            if ($this->database->lastError() !== '' || $duplicateRows === []) {
                throw new RuntimeException('A pending confirmation message no longer has reviewable candidates.');
            }
            return new ConfirmationEmailBatch(
                $messageId,
                $sourceId,
                $senderEmail,
                $senderName,
                $subject,
                $receivedAt,
                null,
                SenderTrust::UNKNOWN,
                SenderTrust::UNKNOWN,
                $isAutoReply,
                null,
                [],
                ConfirmationEmailReason::DUPLICATE
            );
        }

        $candidates = [];

        foreach ($candidateRows as $row) {
            $fields = $this->jsonArray($row['fields'] ?? null, 'candidate fields');
            $recurrence = $this->jsonArray($row['recurrence'] ?? null, 'candidate recurrence');
            $notes = $this->jsonArray($row['notes'] ?? null, 'candidate notes');

            if (! array_is_list($notes)) {
                throw new RuntimeException('A stored event candidate has invalid review notes.');
            }

            $candidates[] = new ConfirmationEmailCandidate(
                $this->integerValue($row['id'] ?? null, 'event candidate ID'),
                $fields,
                $recurrence,
                $this->floatValue($row['confidence'] ?? null, 'candidate confidence'),
                $notes,
                (string) ($row['match_kind'] ?? 'new'),
                $this->matchedTitle($row['match_event_id'] ?? null)
            );
        }

        if ($rawPath === null) {
            throw new ConfirmationEmailHeaderUnavailableException(
                $messageId,
                new PermanentInboundHeaderReadException('The inbound message has no stored raw file path.')
            );
        }

        try {
            $headers = $this->headerParser->parse(
                $this->headerStorage->readHeaderBlock($rawPath),
                $senderEmail
            );
        } catch (PermanentInboundHeaderReadException | InvalidInboundHeaderBlockException $failure) {
            throw new ConfirmationEmailHeaderUnavailableException($messageId, $failure);
        }

        $replyToEmail = $headers?->replyToEmail;
        $senderTrust = $this->trustFor($senderEmail);
        $replyToTrust = $this->trustFor($replyToEmail);

        return new ConfirmationEmailBatch(
            $messageId,
            $sourceId,
            $senderEmail,
            $senderName,
            $subject,
            $receivedAt,
            $replyToEmail,
            $senderTrust,
            $replyToTrust,
            $isAutoReply || ($headers?->automatedAssessment->blocksConfirmation() ?? false),
            $headers?->messageId,
            $candidates
        );
    }

    private function matchedTitle(mixed $eventId): ?string
    {
        if ($eventId === null) {
            return null;
        }
        $id = $this->integerValue($eventId, 'matched event ID');
        $posts = $this->tableName('posts');
        $row = $this->database->getRow($this->database->prepare(
            "SELECT post_title FROM {$posts} WHERE ID = %d AND post_type = %s AND post_status = %s",
            $id, 'adct_event', 'publish'
        ));
        if ($this->database->lastError() !== '' || ! is_string($row['post_title'] ?? null)) {
            throw new RuntimeException('The matched event title could not be read for the preview.');
        }
        return $row['post_title'];
    }

    public function recordResult(
        int $messageId,
        ConfirmationEmailResult $result,
        DateTimeImmutable $recordedAt
    ): void {
        if ($messageId < 1) {
            throw new RuntimeException('A confirmation preview result needs a valid inbound message ID.');
        }

        $messagesTable = $this->tableName('adct_pi_inbound_messages');
        $status = $result->outcome->value;
        $reason = $result->reason?->value;
        $timestamp = $recordedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $reasonSql = $reason === null ? 'NULL' : '%s';
        $query = "UPDATE {$messagesTable}"
            . ' SET confirmation_status = %s, confirmation_reason = ' . $reasonSql . ', updated_at = %s'
            . ' WHERE id = %d AND status = %s AND confirmation_status IS NULL';
        $arguments = [$status];

        if ($reason !== null) {
            $arguments[] = $reason;
        }

        array_push(
            $arguments,
            $timestamp,
            $messageId,
            self::MESSAGE_STATUS_PARSED
        );
        $this->database->clearLastError();
        $updated = $this->database->query($this->database->prepare($query, ...$arguments));
        $error = $this->database->lastError();

        if ($updated === false || $error !== '') {
            throw new RuntimeException('The confirmation preview outcome could not be recorded.');
        }

        if ($updated === 1) {
            return;
        }

        $current = $this->database->getRow($this->database->prepare(
            "SELECT confirmation_status, confirmation_reason FROM {$messagesTable}"
            . ' WHERE id = %d LIMIT 1',
            $messageId
        ));

        if (
            $this->database->lastError() !== ''
            || ! is_array($current)
            || ($current['confirmation_status'] ?? null) !== $status
            || ($current['confirmation_reason'] ?? null) !== $reason
        ) {
            throw new RuntimeException('The confirmation preview outcome changed before it could be recorded.');
        }
    }

    private function trustFor(?string $email): string
    {
        if ($email === null) {
            return SenderTrust::UNKNOWN;
        }

        $normalized = strtolower(trim($email));
        $lookup = SenderLookup::fromRows($normalized, $this->contacts->findByEmail($normalized));

        return $lookup->trust;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonArray(mixed $value, string $fieldName): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_string($value)) {
            throw new RuntimeException('A stored event candidate has invalid ' . $fieldName . '.');
        }

        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException(
                'A stored event candidate has invalid ' . $fieldName . '.',
                0,
                $failure
            );
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('A stored event candidate has invalid ' . $fieldName . '.');
        }

        return $decoded;
    }

    private function integerValue(mixed $value, string $fieldName): int
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/\A\d+\z/D', $value) !== 1)) {
            throw new RuntimeException('A stored confirmation preview has an invalid ' . $fieldName . '.');
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (! is_int($integer)) {
            throw new RuntimeException('A stored confirmation preview has an invalid ' . $fieldName . '.');
        }

        return $integer;
    }

    private function floatValue(mixed $value, string $fieldName): float
    {
        if (! is_numeric($value)) {
            throw new RuntimeException('A stored confirmation preview has an invalid ' . $fieldName . '.');
        }

        $float = (float) $value;

        if (! is_finite($float) || $float < 0 || $float > 1) {
            throw new RuntimeException('A stored confirmation preview has an invalid ' . $fieldName . '.');
        }

        return $float;
    }

    private function booleanValue(mixed $value, string $fieldName): bool
    {
        if ($value === 0 || $value === '0' || $value === false) {
            return false;
        }

        if ($value === 1 || $value === '1' || $value === true) {
            return true;
        }

        throw new RuntimeException('A stored confirmation preview has an invalid ' . $fieldName . '.');
    }

    private function nullableString(mixed $value, string $fieldName): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException('A stored confirmation preview has an invalid ' . $fieldName . '.');
        }

        return $value;
    }

    private function dateTime(mixed $value, string $fieldName): DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('A stored confirmation preview has an invalid ' . $fieldName . '.');
        }

        try {
            return new DateTimeImmutable($value, $this->timezone);
        } catch (\Exception $failure) {
            throw new RuntimeException('A stored confirmation preview has an invalid ' . $fieldName . '.', 0, $failure);
        }
    }

    private function tableName(string $suffix): string
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/', $prefix) !== 1) {
            throw new RuntimeException('The WordPress database prefix cannot be used for confirmation preview queries.');
        }

        return '`' . $prefix . $suffix . '`';
    }
}
