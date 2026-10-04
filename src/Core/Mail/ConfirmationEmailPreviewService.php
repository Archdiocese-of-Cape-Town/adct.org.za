<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ingestion\EmailAddressSafety;
use ADCT\ParishIntake\Core\Ingestion\InboundMailPolicy;
use ADCT\ParishIntake\Core\Ports\ConfirmationActionLinkProviderInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use Throwable;

/**
 * Queues the confirmation preview a freshly parsed message earns, exactly once.
 *
 * The email itself is built by {@see ConfirmationEmailComposer}, which the
 * reviewer-driven resend (issue #176) shares. What stays here is what makes *this*
 * route idempotent: a stable group key per inbound message, and the reconciliation
 * of an enqueue that raced another worker. A resend deliberately wants none of
 * that, which is why the two are separate classes rather than one with a flag.
 */
final class ConfirmationEmailPreviewService
{
    private readonly ConfirmationEmailComposer $composer;

    public function __construct(
        ActionTokenService $tokens,
        ConfirmationActionLinkProviderInterface $actionLinks,
        private readonly MailerInterface $mailer,
        private readonly MailQueueRepositoryInterface $queue,
        ConfirmationEmailRenderer $renderer,
        ?InboundMailPolicy $inboundMailPolicy = null,
        ?EmailAddressSafety $addressSafety = null
    ) {
        $this->composer = new ConfirmationEmailComposer(
            $tokens,
            $actionLinks,
            $renderer,
            $inboundMailPolicy,
            $addressSafety
        );
    }

    public function enqueuePreview(ConfirmationEmailBatch $batch): ConfirmationEmailResult
    {
        $groupKey = 'confirmation:' . $batch->messageId;
        $existing = $this->findConfirmationQueueRecord($groupKey);

        if ($existing !== null) {
            return $this->resultFromExistingBatch($batch, $existing);
        }

        if ($batch->candidates === []) {
            return new ConfirmationEmailResult(
                ConfirmationEmailOutcome::SUPPRESSED,
                $batch->emptyReason ?? ConfirmationEmailReason::NO_CANDIDATES
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

        $recipient = $this->composer->recipient($batch);

        if ($recipient === null) {
            return new ConfirmationEmailResult(
                ConfirmationEmailOutcome::SUPPRESSED,
                ConfirmationEmailReason::NO_SAFE_RECIPIENT
            );
        }

        $recipient = ActionTokenBinding::normalizeEmailAddress($recipient);
        $existing = $this->findConfirmationQueueRecord($groupKey);

        if ($existing !== null) {
            return $this->resultFromExistingBatch($batch, $existing);
        }

        $threadHeaders = $this->composer->threadHeaders($batch);
        $payloadFingerprint = $this->composer->payloadFingerprint($batch, $recipient, $threadHeaders);
        $content = $this->composer->render($batch, $recipient);
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
                $existing = $this->findConfirmationQueueRecord($groupKey);
            } catch (ConfirmationEmailQueueConflictException $lookupConflict) {
                throw $lookupConflict;
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

            return $this->resultFromExistingBatch($batch, $existing);
        }

        if ($result->duplicate) {
            $existing = $this->findConfirmationQueueRecord($groupKey);

            if ($existing === null) {
                throw new RuntimeException('The duplicate confirmation queue item could not be read.');
            }

            return $this->resultFromExistingBatch($batch, $existing);
        }

        $existing = $this->findConfirmationQueueRecord($groupKey);

        if ($existing === null || $existing->id !== $result->id) {
            throw new RuntimeException('The newly queued confirmation email could not be verified.');
        }

        return $this->resultFromExistingBatch($batch, $existing);
    }

    private function findConfirmationQueueRecord(string $groupKey): ?MailQueueRecord
    {
        $records = $this->queue->findAllByGroupKey($groupKey);

        if (count($records) > 1) {
            throw new ConfirmationEmailQueueConflictException(
                'More than one confirmation queue item uses this inbound message key.'
            );
        }

        return $records[0] ?? null;
    }

    private function resultFromExistingBatch(
        ConfirmationEmailBatch $batch,
        MailQueueRecord $existing
    ): ConfirmationEmailResult {
        $recipient = $this->composer->potentialRecipient($batch);

        if ($recipient === null) {
            throw new ConfirmationEmailQueueConflictException(
                'The current safe confirmation recipient cannot be resolved for the existing queue item.'
            );
        }

        $recipient = ActionTokenBinding::normalizeEmailAddress($recipient);

        if ($existing->email->recipient !== $recipient) {
            throw new ConfirmationEmailQueueConflictException(
                'The confirmation queue key is already bound to a different recipient.'
            );
        }

        $threadHeaders = $this->composer->threadHeaders($batch);

        return $this->resultFromExisting(
            $existing,
            $this->composer->payloadFingerprint($batch, $recipient, $threadHeaders)
        );
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

    }
