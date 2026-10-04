<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Ingestion;

use ADCT\ParishIntake\Core\Directory\SenderLookup;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ingestion\InboundHeaderBlock;
use ADCT\ParishIntake\Core\Ingestion\InboundHeaderBlockParser;
use ADCT\ParishIntake\Core\Ingestion\InvalidInboundHeaderBlockException;
use ADCT\ParishIntake\Core\Ingestion\PermanentInboundHeaderReadException;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailBatch;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailCandidate;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendSlot;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailHeaderUnavailableException;
use ADCT\ParishIntake\Core\Ports\ConfirmationEmailResendBookingInterface;
use ADCT\ParishIntake\Core\Ports\InboundHeaderStorageInterface;
use ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

/**
 * Turns a reviewer's resend request into the email to send, and records that it
 * was asked for.
 *
 * The candidate is re-read here, inside the booking, so the email is built from
 * the fields as they are *now* rather than as they were on the screen that
 * carried the button. A reviewer who corrected a date and then resent gets the
 * corrected date.
 *
 * Two things this deliberately does not do:
 *
 * - It does not call {@see WordPressConfirmationEmailJobSource::recordResult()}.
 *   That writes the message's own `confirmation_status`, which describes the
 *   original send. A resend is a later, separate act and must not overwrite it —
 *   doing so would erase the record of what actually happened the first time.
 * - It does not restrict candidates to `draft`. The status that gates the
 *   automatic preview says nothing about a manual resend; what gates this is that
 *   the candidate has saved fields to render at all.
 */
final class WordPressConfirmationEmailResendSource implements ConfirmationEmailResendBookingInterface
{
    public function __construct(
        private readonly DatabaseConnectionInterface $database,
        private readonly ReviewQueueRepository $reviewQueue,
        private readonly ParishContactStoreInterface $contacts,
        private readonly InboundHeaderStorageInterface $headerStorage,
        private readonly InboundHeaderBlockParser $headerParser = new InboundHeaderBlockParser(),
        private readonly DateTimeZone $timezone = new DateTimeZone('Africa/Johannesburg')
    ) {
    }

    public function claimResendSlot(
        int $candidateId,
        string $actor,
        DateTimeImmutable $requestedAt,
        int $cooldownSeconds
    ): ConfirmationEmailResendSlot {
        $lastResentAt = $this->reviewQueue->lastConfirmationResentAt($candidateId);
        $userId = $this->reviewerIdFor($actor);

        // The booking is the authoritative gate: it re-reads the last resend under
        // the row lock and throws if the window has not elapsed. Reading it above
        // as well is only so the exception can report the previous time; if the two
        // disagree the booking's answer wins, because it is the one inside the lock.
        $messageId = $this->reviewQueue->bookConfirmationResend(
            $candidateId,
            $userId,
            $actor,
            true,
            $requestedAt,
            $cooldownSeconds
        );

        return new ConfirmationEmailResendSlot(
            $this->batchFor($candidateId, $messageId),
            $lastResentAt
        );
    }

    public function lastResentAt(int $candidateId): ?DateTimeImmutable
    {
        return $this->reviewQueue->lastConfirmationResentAt($candidateId);
    }

    /**
     * A one-candidate batch built from the candidate's current stored fields.
     *
     * Only this candidate: the reviewer resent *this* event's preview from this
     * screen, and putting a neighbouring candidate in the email would let them
     * confirm an event they were not looking at.
     */
    private function batchFor(int $candidateId, int $messageId): ConfirmationEmailBatch
    {
        $messagesTable = $this->tableName('adct_pi_inbound_messages');
        $candidatesTable = $this->tableName('adct_pi_event_candidates');

        $this->database->clearLastError();
        $row = $this->database->getRow($this->database->prepare(
            "SELECT m.id, m.source_id, m.sender_email, m.sender_name, m.subject,"
            . " m.received_at, m.raw_path, m.is_auto_reply,"
            . " c.id AS candidate_id, c.fields, c.recurrence, c.confidence, c.notes,"
            . ' c.match_kind, c.match_event_id'
            . " FROM {$candidatesTable} c"
            . " INNER JOIN {$messagesTable} m ON m.id = c.message_id"
            . ' WHERE c.id = %d LIMIT 1',
            $candidateId
        ));
        $error = $this->database->lastError();

        if ($error !== '') {
            throw new RuntimeException('The event to resend could not be read: ' . $error);
        }

        if (! is_array($row)) {
            throw new RuntimeException('The event to resend no longer exists.');
        }

        $fields = $this->jsonArray($row['fields'] ?? null, 'fields');

        if ($fields === []) {
            // A candidate with no stored fields has nothing to show a parish. It is
            // the one case where "resend" is meaningless rather than merely early.
            throw new \DomainException('This event has no saved details to send yet.');
        }

        $recurrence = $this->jsonArray($row['recurrence'] ?? null, 'recurrence');
        $notes = $this->jsonArray($row['notes'] ?? null, 'notes');

        if (! array_is_list($notes)) {
            throw new RuntimeException('A stored event candidate has invalid review notes.');
        }

        $senderEmail = $this->nullableString($row['sender_email'] ?? null, 'sender email');
        $rawPath = $this->nullableString($row['raw_path'] ?? null, 'raw message path');
        $receivedAt = $this->dateTime($row['received_at'] ?? null, 'received timestamp');
        $isAutoReply = $this->booleanValue($row['is_auto_reply'] ?? null, 'automated message marker');

        $replyToEmail = null;
        $automated = $isAutoReply;
        $originalMessageId = null;

        if ($rawPath !== null) {
            $headers = $this->headersFor($messageId, $rawPath, $senderEmail);
            $replyToEmail = $headers?->replyToEmail;
            $originalMessageId = $headers?->messageId;
            $automated = $isAutoReply || ($headers?->automatedAssessment->blocksConfirmation() ?? false);
        }

        return new ConfirmationEmailBatch(
            $this->integerValue($row['id'] ?? null, 'inbound message ID'),
            $this->integerValue($row['source_id'] ?? null, 'inbound source ID'),
            $senderEmail,
            $this->nullableString($row['sender_name'] ?? null, 'sender name'),
            $this->nullableString($row['subject'] ?? null, 'subject') ?? '',
            $receivedAt,
            $replyToEmail,
            $this->trustFor($senderEmail),
            $this->trustFor($replyToEmail),
            $automated,
            $originalMessageId,
            [
                new ConfirmationEmailCandidate(
                    $this->integerValue($row['candidate_id'] ?? null, 'event candidate ID'),
                    $fields,
                    $recurrence,
                    $this->floatValue($row['confidence'] ?? null, 'candidate confidence'),
                    $notes,
                    (string) ($row['match_kind'] ?? 'new'),
                    $this->matchedTitle($row['match_event_id'] ?? null),
                    is_array($fields['field_confidence'] ?? null) ? $fields['field_confidence'] : []
                ),
            ]
        );
    }

    private function headersFor(
        int $messageId,
        string $rawPath,
        ?string $senderEmail
    ): ?InboundHeaderBlock {
        try {
            return $this->headerParser->parse(
                $this->headerStorage->readHeaderBlock($rawPath),
                $senderEmail
            );
        } catch (PermanentInboundHeaderReadException | InvalidInboundHeaderBlockException $failure) {
            throw new ConfirmationEmailHeaderUnavailableException($messageId, $failure);
        }
    }

    /**
     * The reviewer's user id, so the booking can apply the same scope check the
     * rest of the review queue uses.
     *
     * A resend is offered only on a candidate the reviewer has already loaded, and
     * that load was scoped. Re-resolving the id here keeps a stale screen from
     * reaching a candidate whose parish has since moved out of their deanery.
     */
    private function reviewerIdFor(string $actor): int
    {
        $users = $this->tableName('users');
        $this->database->clearLastError();
        $row = $this->database->getRow($this->database->prepare(
            "SELECT ID FROM {$users} WHERE LOWER(user_email) = LOWER(%s) LIMIT 1",
            $actor
        ));

        if ($this->database->lastError() !== '') {
            throw new RuntimeException('The reviewer could not be resolved for the confirmation resend.');
        }

        if (! is_array($row) || ! isset($row['ID'])) {
            throw new \DomainException('Your account is no longer available for review.');
        }

        return (int) $row['ID'];
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
            throw new RuntimeException('The matched event title could not be read for the confirmation.');
        }
        return $row['post_title'];
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

    /** @return array<string, mixed> */
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
        if (is_int($value) || (is_string($value) && preg_match('/\A-?\d+\z/D', $value) === 1)) {
            return (int) $value;
        }

        throw new RuntimeException('A stored event candidate has an invalid ' . $fieldName . '.');
    }

    private function floatValue(mixed $value, string $fieldName): float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (float) $value;
            if (is_finite($number)) {
                return $number;
            }
        }

        throw new RuntimeException('A stored event candidate has an invalid ' . $fieldName . '.');
    }

    private function booleanValue(mixed $value, string $fieldName): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '0' || $normalized === '') {
                return false;
            }
            if ($normalized === '1') {
                return true;
            }
        }

        throw new RuntimeException('A stored event candidate has an invalid ' . $fieldName . '.');
    }

    private function nullableString(mixed $value, string $fieldName): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        throw new RuntimeException('A stored event candidate has an invalid ' . $fieldName . '.');
    }

    private function dateTime(mixed $value, string $fieldName): DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('A stored event candidate has an invalid ' . $fieldName . '.');
        }

        try {
            return new DateTimeImmutable($value, $this->timezone);
        } catch (\Exception $failure) {
            throw new RuntimeException(
                'A stored event candidate has an invalid ' . $fieldName . '.',
                0,
                $failure
            );
        }
    }

    private function tableName(string $suffix): string
    {
        $prefix = $this->database->prefix();

        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The database prefix is invalid.');
        }

        return "`{$prefix}{$suffix}`";
    }
}
