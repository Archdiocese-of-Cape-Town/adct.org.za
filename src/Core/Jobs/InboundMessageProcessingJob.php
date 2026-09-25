<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use ADCT\ParishIntake\Core\Directory\DirectoryLookup;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageProcessingFailure;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageProcessingRecord;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ingestion\MimeMessageParser;
use ADCT\ParishIntake\Core\Parsing\Pipeline;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use ADCT\ParishIntake\Core\Ports\EventCandidateStoreInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\InboundMessageProcessingFailureLoggerInterface;
use ADCT\ParishIntake\Core\Ports\InboundMessageProcessingStoreInterface;
use Closure;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class InboundMessageProcessingJob extends AbstractJob implements JobRunLifecycleInterface
{
    public const DEFAULT_INTERVAL_SECONDS = 600;
    public const MAX_REPROCESS_BATCH = InboundMessageProcessingStoreInterface::MAX_REPROCESS_BATCH;

    /** @var Closure(): Pipeline */
    private Closure $pipelineFactory;

    private DirectoryLookup $directoryLookup;

    /** @var list<int> */
    private array $prioritizedMessageIds = [];

    private int $nextPrioritizedMessage = 0;

    /**
     * @param callable(): Pipeline $pipelineFactory
     */
    public function __construct(
        private InboundMessageProcessingStoreInterface $messages,
        private InboundMailStorageReaderInterface $storage,
        private MimeMessageParser $mimeParser,
        callable $pipelineFactory,
        private EventCandidateStoreInterface $candidates,
        private InboundMessageProcessingFailureLoggerInterface $failureLogger,
        DirectorySnapshotProviderInterface $directorySnapshots,
        private ClockInterface $clock
    ) {
        parent::__construct(
            'process_inbound_messages',
            'Process inbound messages',
            self::DEFAULT_INTERVAL_SECONDS
        );
        $this->pipelineFactory = Closure::fromCallable($pipelineFactory);
        $this->directoryLookup = new DirectoryLookup($directorySnapshots);
    }

    /**
     * Prioritize a bounded set for an administrator-triggered reprocess run.
     *
     * @param list<int> $messageIds
     */
    public function prioritizeMessageIds(array $messageIds): void
    {
        if (count($messageIds) > self::MAX_REPROCESS_BATCH) {
            throw new InvalidArgumentException('The reprocess batch exceeds its maximum size.');
        }

        $uniqueIds = [];

        foreach ($messageIds as $messageId) {
            if (! is_int($messageId) || $messageId < 1) {
                throw new InvalidArgumentException('A reprocess batch contains an invalid message ID.');
            }

            $uniqueIds[$messageId] = $messageId;
        }

        $this->prioritizedMessageIds = array_values($uniqueIds);
        $this->nextPrioritizedMessage = 0;
    }

    public function clearPrioritizedMessageIds(): void
    {
        $this->prioritizedMessageIds = [];
        $this->nextPrioritizedMessage = 0;
    }

    public function beginRun(): void
    {
        $this->nextPrioritizedMessage = 0;
    }

    public function processNext(?string $checkpoint): ?JobStepResult
    {
        $message = $this->nextMessage();

        if ($message === null) {
            return null;
        }

        try {
            $senderIsBlocked = $this->senderIsBlocked($message);
        } catch (Throwable $failure) {
            $this->logFailure(
                $message->id,
                InboundMessageProcessingFailure::CONTEXT_SENDER_LOOKUP,
                $failure
            );
            $this->markFailed(
                $message,
                'The sender could not be checked safely. Contact the website administrator before reprocessing it.'
            );

            return JobStepResult::continueAt($checkpoint);
        }

        if ($senderIsBlocked) {
            try {
                $this->candidates->discardDraftCandidatesForMessage($message->id, $this->timestamp());
                $this->requireTransition(
                    $this->messages->markIgnored(
                        $message->id,
                        'This message was ignored because its sender is blocked.',
                        $this->timestamp()
                    ),
                    InboundMessageRecord::STATUS_IGNORED
                );

                return JobStepResult::continueAt($checkpoint);
            } catch (Throwable $failure) {
                $this->logFailure(
                    $message->id,
                    InboundMessageProcessingFailure::CONTEXT_BLOCKED_MESSAGE_TRANSITION,
                    $failure
                );
                $this->markFailed(
                    $message,
                    'The blocked message could not be safely ignored. Contact the website administrator before reprocessing it.'
                );

                return JobStepResult::continueAt($checkpoint);
            }
        }

        if (! $this->messages->markExtracting($message->id, $this->timestamp())) {
            return JobStepResult::continueAt($checkpoint);
        }

        try {
            $rawMessage = $this->readRawMessage($message);

            try {
                $parsedMessage = $this->mimeParser->parse(
                    $rawMessage,
                    $message->externalId
                );
            } catch (Throwable $failure) {
                throw new InboundMessageProcessingFailure(
                    'The saved email could not be decoded. Check the original message format, then reprocess it.',
                    InboundMessageProcessingFailure::CONTEXT_MIME_DECODE,
                    $failure
                );
            }

            if ($message->receivedAt === null) {
                throw new InboundMessageProcessingFailure(
                    'The stored receive date is missing or invalid. Check the message record before reprocessing it.',
                    InboundMessageProcessingFailure::CONTEXT_MESSAGE_METADATA
                );
            }

            $parsedMessage = $parsedMessage->withReceivedAt($message->receivedAt);
            $pipeline = ($this->pipelineFactory)();

            if (! $pipeline instanceof Pipeline) {
                throw new RuntimeException('The parser pipeline factory returned an invalid pipeline.');
            }

            try {
                $outcome = $pipeline->parseAll($parsedMessage);
            } catch (Throwable $failure) {
                throw new InboundMessageProcessingFailure(
                    'The email could not be parsed. Check the parser settings, then reprocess it.',
                    InboundMessageProcessingFailure::CONTEXT_PIPELINE_PARSE,
                    $failure
                );
            }

            try {
                $this->candidates->replaceDraftCandidatesForMessage(
                    $message->id,
                    $outcome,
                    $this->timestamp()
                );
            } catch (Throwable $failure) {
                throw new InboundMessageProcessingFailure(
                    'The parsed event details could not be saved. Reprocess the message, and contact support if this continues.',
                    InboundMessageProcessingFailure::CONTEXT_CANDIDATE_STORAGE,
                    $failure
                );
            }

            if ($message->isAutoReply && $outcome->getCandidates() === []) {
                $this->requireTransition(
                    $this->messages->markIgnored(
                        $message->id,
                        'This automated or mailing-list message did not contain an event to review.',
                        $this->timestamp()
                    ),
                    InboundMessageRecord::STATUS_IGNORED
                );
            } else {
                $this->requireTransition(
                    $this->messages->markParsed(
                        $message->id,
                        $parsedMessage->getBody(),
                        $this->timestamp()
                    ),
                    InboundMessageRecord::STATUS_PARSED
                );
            }
        } catch (InboundMessageProcessingFailure $failure) {
            $this->logFailure(
                $message->id,
                $failure->diagnosticContext,
                $failure->getPrevious() ?? $failure
            );
            $this->markFailed($message, $failure->operatorMessage);
        } catch (Throwable $failure) {
            $this->logFailure(
                $message->id,
                InboundMessageProcessingFailure::CONTEXT_PROCESSING,
                $failure
            );
            $this->markFailed(
                $message,
                'An unexpected processing error occurred. Reprocess the message, and contact support if this continues.'
            );
        }

        return JobStepResult::continueAt($checkpoint);
    }

    private function nextMessage(): ?InboundMessageProcessingRecord
    {
        if ($this->prioritizedMessageIds === []) {
            return $this->messages->findNextForProcessing();
        }

        while ($this->nextPrioritizedMessage < count($this->prioritizedMessageIds)) {
            $messageId = $this->prioritizedMessageIds[$this->nextPrioritizedMessage];
            ++$this->nextPrioritizedMessage;
            $message = $this->messages->findForProcessingById($messageId);

            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }

    private function senderIsBlocked(InboundMessageProcessingRecord $message): bool
    {
        $email = trim((string) $message->senderEmail);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return $this->directoryLookup->lookupSender($email)->trust === SenderTrust::BLOCKED;
    }

    private function readRawMessage(InboundMessageProcessingRecord $message): string
    {
        if ($message->rawPath === null || trim($message->rawPath) === '') {
            throw new InboundMessageProcessingFailure(
                'The saved email file is missing. Restore the original message, then reprocess it.',
                InboundMessageProcessingFailure::CONTEXT_RAW_MESSAGE_STORAGE
            );
        }

        try {
            $rawMessage = $this->storage->readRawMessage($message->rawPath);
        } catch (Throwable $failure) {
            throw new InboundMessageProcessingFailure(
                'The saved email file is missing or cannot be read. Restore it, then reprocess the message.',
                InboundMessageProcessingFailure::CONTEXT_RAW_MESSAGE_STORAGE,
                $failure
            );
        }

        if (trim($rawMessage) === '') {
            throw new InboundMessageProcessingFailure(
                'The saved email is empty. Restore the original message, then reprocess it.',
                InboundMessageProcessingFailure::CONTEXT_RAW_MESSAGE_STORAGE
            );
        }

        return $rawMessage;
    }

    private function logFailure(int $messageId, string $context, Throwable $failure): void
    {
        $this->failureLogger->logFailure($messageId, $context, get_class($failure));
    }

    private function markFailed(InboundMessageProcessingRecord $message, string $reason): void
    {
        $this->requireTransition(
            $this->messages->markFailed($message->id, $reason, $this->timestamp()),
            InboundMessageRecord::STATUS_FAILED
        );
    }

    private function requireTransition(bool $changed, string $status): void
    {
        if (! $changed) {
            throw new RuntimeException('The inbound message could not be moved to the ' . $status . ' state.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
