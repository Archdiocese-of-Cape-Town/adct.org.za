<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ingestion\EmailAddressSafety;
use ADCT\ParishIntake\Core\Ingestion\InboundMailPolicy;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ports\ConfirmationActionLinkProviderInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use JsonException;
use RuntimeException;
use Throwable;

final class ConfirmationEmailPreviewService
{
    private const TEMPLATE_FINGERPRINT_VERSION = 'e4.2-confirmation-preview-v1';

    private EmailAddressSafety $addressSafety;
    private InboundMailPolicy $inboundMailPolicy;

    public function __construct(
        private readonly ActionTokenService $tokens,
        private readonly ConfirmationActionLinkProviderInterface $actionLinks,
        private readonly MailerInterface $mailer,
        private readonly MailQueueRepositoryInterface $queue,
        private readonly ConfirmationEmailRenderer $renderer,
        ?InboundMailPolicy $inboundMailPolicy = null,
        ?EmailAddressSafety $addressSafety = null
    ) {
        $this->inboundMailPolicy = $inboundMailPolicy ?? new InboundMailPolicy();
        $this->addressSafety = $addressSafety ?? new EmailAddressSafety();
    }

    public function enqueuePreview(ConfirmationEmailBatch $batch): ConfirmationEmailResult
    {
        if ($batch->candidates === []) {
            return new ConfirmationEmailResult(
                ConfirmationEmailOutcome::SUPPRESSED,
                ConfirmationEmailReason::NO_CANDIDATES
            );
        }

        if ($batch->senderTrust === SenderTrust::BLOCKED) {
            return new ConfirmationEmailResult(
                ConfirmationEmailOutcome::SUPPRESSED,
                ConfirmationEmailReason::BLOCKED_SENDER
            );
        }

        if ($batch->automatedOrList) {
            return new ConfirmationEmailResult(
                ConfirmationEmailOutcome::SUPPRESSED,
                ConfirmationEmailReason::AUTOMATED_OR_LIST
            );
        }

        $recipient = $this->confirmationRecipient($batch);

        if ($recipient === null) {
            return new ConfirmationEmailResult(
                ConfirmationEmailOutcome::SUPPRESSED,
                ConfirmationEmailReason::NO_SAFE_RECIPIENT
            );
        }

        $recipient = ActionTokenBinding::normalizeEmailAddress($recipient);
        $threadHeaders = EmailThreadHeaders::fromOriginalMessageId($batch->originalMessageId);
        $groupKey = 'confirmation:' . $batch->messageId;
        $payloadFingerprint = $this->payloadFingerprint($batch, $recipient, $threadHeaders);
        $existing = $this->queue->findByRecipientAndGroupKey($recipient, $groupKey);

        if ($existing !== null) {
            return $this->resultFromExisting($existing, $payloadFingerprint);
        }

        $links = $this->createActionLinks($batch, $recipient);
        $content = $this->renderer->render($batch, $links);
        $email = new OutboundEmail(
            $recipient,
            $content->subject,
            $content->html,
            $content->text,
            MailPriority::LOGIN_OR_CONFIRMATION,
            $groupKey,
            $threadHeaders,
            $payloadFingerprint
        );

        try {
            $result = $this->mailer->enqueue($email);
        } catch (Throwable $enqueueFailure) {
            try {
                $existing = $this->queue->findByRecipientAndGroupKey($recipient, $groupKey);
            } catch (Throwable $lookupFailure) {
                throw new RuntimeException(
                    'The confirmation email queue outcome could not be recovered; the job will retry.',
                    0,
                    $lookupFailure
                );
            }

            if ($existing === null) {
                throw $enqueueFailure;
            }

            return $this->resultFromExisting($existing, $payloadFingerprint);
        }

        if ($result->duplicate) {
            $existing = $this->queue->findByRecipientAndGroupKey($recipient, $groupKey);

            if ($existing === null) {
                throw new RuntimeException('The duplicate confirmation queue item could not be read.');
            }

            return $this->resultFromExisting($existing, $payloadFingerprint);
        }

        $existing = $this->queue->findByRecipientAndGroupKey($recipient, $groupKey);

        if ($existing === null || $existing->id !== $result->id) {
            throw new RuntimeException('The newly queued confirmation email could not be verified.');
        }

        return $this->resultFromExisting($existing, $payloadFingerprint);
    }

    private function confirmationRecipient(ConfirmationEmailBatch $batch): ?string
    {
        $message = new InboundMessageRecord(
            $batch->sourceId,
            'confirmation:' . $batch->messageId,
            null,
            $batch->senderEmail,
            $batch->senderName,
            $batch->subject,
            $batch->receivedAt,
            null,
            isAutoReply: $batch->automatedOrList
        );

        if (! $this->inboundMailPolicy->canSendConfirmation($message)) {
            return null;
        }

        $sender = ActionTokenBinding::normalizeEmailAddress((string) $batch->senderEmail);
        $replyTo = $batch->replyToEmail;

        if (
            $replyTo !== null
            && strcasecmp(trim($replyTo), $sender) !== 0
            && $batch->replyToTrust === SenderTrust::VERIFIED
            && $this->addressSafety->isSafeConfirmationAddress($replyTo)
        ) {
            return $replyTo;
        }

        return $sender;
    }

    private function createActionLinks(
        ConfirmationEmailBatch $batch,
        string $recipient
    ): ConfirmationEmailActionLinks {
        $candidateLinks = [];

        foreach ($batch->candidates as $candidate) {
            $candidateLinks[$candidate->id] = [
                'approve' => $this->issueUrl(
                    ActionTokenPurpose::CONFIRM,
                    'event_candidate',
                    $candidate->id,
                    $recipient
                ),
                'deny' => $this->issueUrl(
                    ActionTokenPurpose::DENY,
                    'event_candidate',
                    $candidate->id,
                    $recipient
                ),
                'edit' => $this->issueUrl(
                    ActionTokenPurpose::EDIT,
                    'event_candidate',
                    $candidate->id,
                    $recipient
                ),
            ];
        }

        return new ConfirmationEmailActionLinks(
            $this->issueUrl(
                ActionTokenPurpose::CONFIRM,
                'inbound_message',
                $batch->messageId,
                $recipient
            ),
            $candidateLinks
        );
    }

    private function issueUrl(
        ActionTokenPurpose $purpose,
        string $subjectType,
        int $subjectId,
        string $recipient
    ): string {
        $issued = $this->tokens->issue(new ActionTokenBinding(
            $purpose,
            $subjectType,
            $subjectId,
            $recipient
        ));

        return $this->actionLinks->urlForToken($issued->token());
    }

    private function resultFromExisting(
        MailQueueRecord $existing,
        string $expectedFingerprint
    ): ConfirmationEmailResult {
        $storedFingerprint = $existing->email->payloadFingerprint;

        if (
            $storedFingerprint === null
            || ! hash_equals($expectedFingerprint, $storedFingerprint)
        ) {
            throw new ConfirmationEmailQueueConflictException(
                'A confirmation email group key has a missing or different payload fingerprint; no replacement email was queued.'
            );
        }

        return $this->resultFromQueueStatus(
            $existing->id,
            $existing->status
        );
    }

    private function resultFromQueueStatus(
        int $queueId,
        MailQueueStatus $status
    ): ConfirmationEmailResult {
        return match ($status) {
            MailQueueStatus::QUEUED,
            MailQueueStatus::SENDING => new ConfirmationEmailResult(
                ConfirmationEmailOutcome::QUEUED,
                null,
                $queueId
            ),
            MailQueueStatus::SENT => new ConfirmationEmailResult(
                ConfirmationEmailOutcome::SENT,
                null,
                $queueId
            ),
            MailQueueStatus::SUPPRESSED => new ConfirmationEmailResult(
                ConfirmationEmailOutcome::SUPPRESSED,
                ConfirmationEmailReason::TEST_MODE,
                $queueId
            ),
            MailQueueStatus::FAILED => new ConfirmationEmailResult(
                ConfirmationEmailOutcome::FAILED,
                ConfirmationEmailReason::DELIVERY_FAILED,
                $queueId
            ),
        };
    }

    private function payloadFingerprint(
        ConfirmationEmailBatch $batch,
        string $recipient,
        ?EmailThreadHeaders $threadHeaders
    ): string {
        $candidates = [];

        foreach ($batch->candidates as $candidate) {
            $candidates[] = [
                'id' => $candidate->id,
                'fields' => $this->canonicalize($candidate->fields),
                'recurrence' => $this->canonicalize($candidate->recurrence),
                'confidence' => number_format($candidate->confidence, 3, '.', ''),
                'notes' => $candidate->notes,
            ];
        }

        try {
            $payload = json_encode([
                'version' => self::TEMPLATE_FINGERPRINT_VERSION,
                'message_id' => $batch->messageId,
                'source_id' => $batch->sourceId,
                'recipient' => $recipient,
                'thread_headers' => $threadHeaders?->toJson(),
                'candidates' => $candidates,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The confirmation preview payload could not be fingerprinted.', 0, $failure);
        }

        return hash('sha256', $payload);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (
                $value === null
                || is_string($value)
                || is_int($value)
                || is_float($value)
                || is_bool($value)
            ) {
                return $value;
            }

            throw new RuntimeException('A confirmation preview field contains an unsupported value.');
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        $keys = array_keys($value);
        usort($keys, static fn (int|string $first, int|string $second): int =>
            strcmp((string) $first, (string) $second)
        );
        $canonical = [];

        foreach ($keys as $key) {
            $canonical[$key] = $this->canonicalize($value[$key]);
        }

        return $canonical;
    }
}
