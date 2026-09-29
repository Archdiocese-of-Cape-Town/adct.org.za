<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Jobs;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Directory\SenderLearningService;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageProcessingFailure;
use ADCT\ParishIntake\Core\Jobs\InboundMessageProcessingJob;
use ADCT\ParishIntake\Core\Jobs\JobStepResult;
use ADCT\ParishIntake\Core\Ingestion\MimeMessageParser;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageProcessingRecord;
use ADCT\ParishIntake\Core\Parsing\ParseOutcome;
use ADCT\ParishIntake\Core\Parsing\Pipeline;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use ADCT\ParishIntake\Core\Ports\EventCandidateStoreInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\InboundMessageProcessingFailureLoggerInterface;
use ADCT\ParishIntake\Core\Ports\InboundMessageProcessingStoreInterface;
use ADCT\ParishIntake\Core\Ports\SourceStoreInterface;
use ADCT\ParishIntake\Core\Sources\Source;
use ADCT\ParishIntake\Core\Sources\SourceRole;
use ADCT\ParishIntake\Core\Sources\SourceType;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionLimits;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use ADCT\ParishIntake\Core\Pdf\PdfTextEnrichmentService;
use ADCT\ParishIntake\Core\Pdf\StoredPdfAttachment;
use ADCT\ParishIntake\Core\Ports\AttachmentExtractionStoreInterface;
use ADCT\ParishIntake\Core\Ports\PdfTextExtractorInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InboundMessageProcessingJobTest extends TestCase
{
    private const MESSAGE_ID = 41;

    public function testFailedMessageCanBeReprocessedFromTheSameStoredFile(): void
    {
        $fixture = $this->fixture(new DirectorySnapshot([], [], []));
        $message = $this->message();
        $fixture['messages']->add($message);
        $fixture['storage']->files['private-message.eml'] = '';

        $firstStep = $fixture['job']->processNext(null);

        self::assertInstanceOf(JobStepResult::class, $firstStep);
        self::assertSame('failed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertSame(
            'The saved email is empty. Restore the original message, then reprocess it.',
            $fixture['messages']->errors[self::MESSAGE_ID]
        );
        self::assertSame(
            [[
                'message_id' => self::MESSAGE_ID,
                'context' => InboundMessageProcessingFailure::CONTEXT_RAW_MESSAGE_STORAGE,
                'failure_class' => InboundMessageProcessingFailure::class,
            ]],
            $fixture['failureLogger']->failures
        );
        self::assertSame([], $fixture['candidates']->candidates[self::MESSAGE_ID] ?? []);

        self::assertSame(
            [self::MESSAGE_ID],
            $fixture['messages']->requeueFailedMessages([self::MESSAGE_ID], '2026-09-25 04:10:00')
        );
        $fixture['storage']->files['private-message.eml'] = $this->validEmail();

        $secondStep = $fixture['job']->processNext($firstStep->checkpoint());

        self::assertInstanceOf(JobStepResult::class, $secondStep);
        self::assertSame('parsed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertNull($fixture['messages']->errors[self::MESSAGE_ID]);
        self::assertSame(self::MESSAGE_ID, $fixture['messages']->records[self::MESSAGE_ID]->id);
        self::assertStringContainsString(
            'community supper',
            strtolower($fixture['messages']->bodies[self::MESSAGE_ID])
        );
        self::assertCount(1, $fixture['candidates']->candidates[self::MESSAGE_ID]);

        $fixture['messages']->statuses[self::MESSAGE_ID] = 'extracting';
        $fixture['job']->prioritizeMessageIds([self::MESSAGE_ID]);
        $fixture['job']->beginRun();
        $fixture['job']->processNext(null);
        $fixture['job']->clearPrioritizedMessageIds();

        self::assertSame('parsed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertCount(1, $fixture['candidates']->candidates[self::MESSAGE_ID]);
        self::assertSame(2, $fixture['candidates']->replaceCalls);
    }

    public function testSenderLookupFailureLogsOnlySafeDiagnosticDetails(): void
    {
        $fixture = $this->fixture(new DirectorySnapshot([], [], []));
        $fixture['messages']->add($this->message());
        $fixture['directory']->failure = new RuntimeException('Private sender detail notices@example.test');

        $fixture['job']->processNext(null);

        self::assertSame('failed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertSame(
            'The sender could not be checked safely. Contact the website administrator before reprocessing it.',
            $fixture['messages']->errors[self::MESSAGE_ID]
        );
        self::assertSame(
            [[
                'message_id' => self::MESSAGE_ID,
                'context' => InboundMessageProcessingFailure::CONTEXT_SENDER_LOOKUP,
                'failure_class' => RuntimeException::class,
            ]],
            $fixture['failureLogger']->failures
        );
        self::assertStringNotContainsString(
            'Private sender detail',
            json_encode($fixture['failureLogger']->failures, JSON_THROW_ON_ERROR)
        );
    }

    public function testBlockedMessageTransitionFailureIsLoggedBeforeItIsMarkedFailed(): void
    {
        $snapshot = new DirectorySnapshot([], [], [[
            'email' => 'blocked@example.test',
            'trust' => 'blocked',
            'parish_id' => 7,
        ]]);
        $fixture = $this->fixture($snapshot);
        $fixture['messages']->add($this->message(senderEmail: 'blocked@example.test'));
        $fixture['candidates']->discardFailure = new RuntimeException('Private message detail');

        $fixture['job']->processNext(null);

        self::assertSame('failed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertSame(
            'The blocked message could not be safely ignored. Contact the website administrator before reprocessing it.',
            $fixture['messages']->errors[self::MESSAGE_ID]
        );
        self::assertSame(
            [[
                'message_id' => self::MESSAGE_ID,
                'context' => InboundMessageProcessingFailure::CONTEXT_BLOCKED_MESSAGE_TRANSITION,
                'failure_class' => RuntimeException::class,
            ]],
            $fixture['failureLogger']->failures
        );
        self::assertStringNotContainsString(
            'Private message detail',
            json_encode($fixture['failureLogger']->failures, JSON_THROW_ON_ERROR)
        );
    }

    public function testUnknownSendersCreatePendingContactsWhenTheSourceParishIsKnown(): void
    {
        $clock = new ProcessingClock();
        $directory = new ProcessingDirectorySnapshotProvider(new DirectorySnapshot([], [], []));
        $messages = new ProcessingMessageStore();
        $storage = new ProcessingFileStorage();
        $candidates = new ProcessingCandidateStore();
        $failureLogger = new ProcessingFailureLogger();
        $contactStore = new ProcessingLearningContactStore();
        $senderLearning = new SenderLearningService(new ContactService($contactStore, $clock));
        $sourceStore = new ProcessingSourceStore([
            17 => new Source(17, 11, SourceType::EMAIL, 'mailbox@example.test', SourceRole::OFFICIAL),
        ]);
        $job = new InboundMessageProcessingJob(
            $messages,
            $storage,
            new MimeMessageParser(),
            static function () use ($clock, $directory): Pipeline {
                return (new PipelineFactory($clock, $directory))->create();
            },
            $candidates,
            $failureLogger,
            $directory,
            $clock,
            $sourceStore,
            $senderLearning
        );

        $messages->add($this->message(senderEmail: 'sender@example.test'));
        $storage->files['private-message.eml'] = str_replace(
            'notices@example.test',
            'sender@example.test',
            $this->validEmail()
        );

        $job->processNext(null);

        self::assertSame('parsed', $messages->statuses[self::MESSAGE_ID]);
        self::assertSame(SenderTrust::PENDING, $contactStore->rows[1]['trust']);
        self::assertSame(11, $contactStore->rows[1]['parish_id']);
        self::assertSame('sender@example.test', $contactStore->rows[1]['email']);
    }

    public function testUnexpectedProcessingFailureLogsOnlySafeDiagnosticDetails(): void
    {
        $fixture = $this->fixture(new DirectorySnapshot([], [], []), pipelineFactoryThrows: true);
        $fixture['messages']->add($this->message());
        $fixture['storage']->files['private-message.eml'] = $this->validEmail();

        $fixture['job']->processNext(null);

        self::assertSame('failed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertSame(
            'An unexpected processing error occurred. Reprocess the message, and contact support if this continues.',
            $fixture['messages']->errors[self::MESSAGE_ID]
        );
        self::assertSame(
            [[
                'message_id' => self::MESSAGE_ID,
                'context' => InboundMessageProcessingFailure::CONTEXT_PROCESSING,
                'failure_class' => RuntimeException::class,
            ]],
            $fixture['failureLogger']->failures
        );
        self::assertStringNotContainsString(
            'Private pipeline detail',
            json_encode($fixture['failureLogger']->failures, JSON_THROW_ON_ERROR)
        );
    }

    public function testWrappedCandidateStorageFailureLogsCauseClassWithoutItsMessage(): void
    {
        $fixture = $this->fixture(new DirectorySnapshot([], [], []));
        $fixture['messages']->add($this->message());
        $fixture['storage']->files['private-message.eml'] = $this->validEmail();
        $fixture['candidates']->replaceFailure = new RuntimeException('Private candidate detail');

        $fixture['job']->processNext(null);

        self::assertSame('failed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertSame(
            'The parsed event details could not be saved. Reprocess the message, and contact support if this continues.',
            $fixture['messages']->errors[self::MESSAGE_ID]
        );
        self::assertSame(
            [[
                'message_id' => self::MESSAGE_ID,
                'context' => InboundMessageProcessingFailure::CONTEXT_CANDIDATE_STORAGE,
                'failure_class' => RuntimeException::class,
            ]],
            $fixture['failureLogger']->failures
        );
        self::assertStringNotContainsString(
            'Private candidate detail',
            json_encode($fixture['failureLogger']->failures, JSON_THROW_ON_ERROR)
        );
    }

    public function testBlockedSenderIsIgnoredWithoutReadingOrPersistingCandidates(): void
    {
        $snapshot = new DirectorySnapshot([], [], [[
            'email' => 'blocked@example.test',
            'trust' => 'blocked',
            'parish_id' => 7,
        ]]);
        $fixture = $this->fixture($snapshot);
        $fixture['messages']->add($this->message(senderEmail: 'blocked@example.test'));

        $fixture['job']->processNext(null);

        self::assertSame('ignored', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertSame(
            'This message was ignored because its sender is blocked.',
            $fixture['messages']->errors[self::MESSAGE_ID]
        );
        self::assertSame(0, $fixture['storage']->readCount);
        self::assertSame([], $fixture['candidates']->candidates[self::MESSAGE_ID] ?? []);
    }

    public function testAutomatedMailWithAnEventCandidateIsParsedWithoutSendingConfirmation(): void
    {
        $fixture = $this->fixture(new DirectorySnapshot([], [], []));
        $fixture['messages']->add($this->message(isAutoReply: true));
        $fixture['storage']->files['private-message.eml'] = $this->validEmail();

        $fixture['job']->processNext(null);

        self::assertSame('parsed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertNull($fixture['messages']->errors[self::MESSAGE_ID]);
        self::assertCount(1, $fixture['candidates']->candidates[self::MESSAGE_ID]);
    }

    public function testAutomatedMailWithoutAnEventCandidateIsIgnored(): void
    {
        $fixture = $this->fixture(new DirectorySnapshot([], [], []));
        $fixture['messages']->add($this->message(isAutoReply: true));
        $fixture['storage']->files['private-message.eml'] = $this->automatedEmailWithoutEvent();

        $fixture['job']->processNext(null);

        self::assertSame('ignored', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertSame(
            'This automated or mailing-list message did not contain an event to review.',
            $fixture['messages']->errors[self::MESSAGE_ID]
        );
        self::assertSame([], $fixture['candidates']->candidates[self::MESSAGE_ID] ?? []);
    }

    /**
     * @return array{
     *     job: InboundMessageProcessingJob,
     *     messages: ProcessingMessageStore,
     *     storage: ProcessingFileStorage,
     *     candidates: ProcessingCandidateStore,
     *     directory: ProcessingDirectorySnapshotProvider,
     *     failureLogger: ProcessingFailureLogger
     * }
     */
    private function fixture(
        DirectorySnapshot $snapshot,
        bool $pipelineFactoryThrows = false,
        ?PdfTextEnrichmentService $pdfTextEnrichment = null
    ): array {
        $clock = new ProcessingClock();
        $directory = new ProcessingDirectorySnapshotProvider($snapshot);
        $pipelineFactory = new PipelineFactory($clock, $directory);
        $messages = new ProcessingMessageStore();
        $storage = new ProcessingFileStorage();
        $candidates = new ProcessingCandidateStore();
        $failureLogger = new ProcessingFailureLogger();
        $createPipeline = static function () use ($pipelineFactory, $pipelineFactoryThrows): Pipeline {
            if ($pipelineFactoryThrows) {
                throw new RuntimeException('Private pipeline detail');
            }

            return $pipelineFactory->create();
        };
        $job = new InboundMessageProcessingJob(
            $messages,
            $storage,
            new MimeMessageParser(),
            $createPipeline,
            $candidates,
            $failureLogger,
            $directory,
            $clock,
            pdfTextEnrichment: $pdfTextEnrichment
        );

        return [
            'job' => $job,
            'messages' => $messages,
            'storage' => $storage,
            'candidates' => $candidates,
            'directory' => $directory,
            'failureLogger' => $failureLogger,
        ];
    }

    public function testAnEventSentOnlyAsAPdfStillProducesACandidate(): void
    {
        $fixture = $this->fixture(
            new DirectorySnapshot([], [], []),
            pdfTextEnrichment: $this->pdfEnrichment('bulletin.pdf', PdfExtractionResult::extracted(
                "Parish Retreat Day\nSaturday 17 October 2026 from 9am to 3pm at Example Parish Hall.",
                1
            ))
        );
        $fixture['messages']->add($this->message());
        $fixture['storage']->files['private-message.eml'] = $this->emailWithoutAnEvent();

        $fixture['job']->processNext(null);

        self::assertSame('parsed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertNotSame([], $fixture['candidates']->candidates[self::MESSAGE_ID] ?? []);
        self::assertStringContainsString(
            'Parish Retreat Day',
            $fixture['messages']->bodies[self::MESSAGE_ID]
        );
    }

    public function testAnUnreadablePdfDoesNotStopTheEmailBeingParsed(): void
    {
        $fixture = $this->fixture(
            new DirectorySnapshot([], [], []),
            pdfTextEnrichment: $this->pdfEnrichment('scan.pdf', PdfExtractionResult::noTextLayer(1))
        );
        $fixture['messages']->add($this->message());
        $fixture['storage']->files['private-message.eml'] = $this->validEmail();

        $fixture['job']->processNext(null);

        self::assertSame('parsed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertCount(1, $fixture['candidates']->candidates[self::MESSAGE_ID]);
    }

    public function testMessagesAreProcessedWhenPdfEnrichmentIsNotConfigured(): void
    {
        $fixture = $this->fixture(new DirectorySnapshot([], [], []));
        $fixture['messages']->add($this->message());
        $fixture['storage']->files['private-message.eml'] = $this->validEmail();

        $fixture['job']->processNext(null);

        self::assertSame('parsed', $fixture['messages']->statuses[self::MESSAGE_ID]);
        self::assertCount(1, $fixture['candidates']->candidates[self::MESSAGE_ID]);
    }

    private function pdfEnrichment(string $filename, PdfExtractionResult $result): PdfTextEnrichmentService
    {
        return new PdfTextEnrichmentService(
            new ProcessingAttachmentExtractionStore([
                new StoredPdfAttachment(1, $filename, 'a.pdf', 'pending', 'none'),
            ]),
            new ProcessingPdfTextExtractor($result),
            new ProcessingFileStorage(attachmentPaths: ['a.pdf' => '/var/private/a.pdf']),
            PdfExtractionLimits::defaults()
        );
    }

    private function emailWithoutAnEvent(): string
    {
        return implode("\r\n", [
            'From: Example Parish Office <notices@example.test>',
            'To: intake@example.test',
            'Date: Fri, 25 Sep 2026 04:00:00 +0000',
            'Message-ID: <processing-test@example.test>',
            'Subject: Example Parish community supper',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Parish: Example Parish',
            'The programme for the coming month is attached as a PDF.',
            '',
        ]);
    }

    private function message(
        ?string $senderEmail = 'notices@example.test',
        bool $isAutoReply = false
    ): InboundMessageProcessingRecord {
        return new InboundMessageProcessingRecord(
            self::MESSAGE_ID,
            17,
            '<processing-test@example.test>',
            $senderEmail,
            'Example Notices',
            'Example Parish community supper',
            new DateTimeImmutable('2026-09-25T04:00:00+00:00'),
            'private-message.eml',
            $isAutoReply
        );
    }

    private function validEmail(): string
    {
        return implode("\r\n", [
            'From: Example Parish Office <notices@example.test>',
            'To: intake@example.test',
            'Date: Fri, 25 Sep 2026 04:00:00 +0000',
            'Message-ID: <processing-test@example.test>',
            'Subject: Example Parish community supper',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Parish: Example Parish',
            'Please join the community supper on 12 October 2026 at 18:30 at Example Parish Hall.',
            '',
        ]);
    }

    private function automatedEmailWithoutEvent(): string
    {
        return implode("\r\n", [
            'From: Example Parish Office <no-reply@example.test>',
            'To: intake@example.test',
            'Date: Fri, 25 Sep 2026 04:00:00 +0000',
            'Message-ID: <automated-processing-test@example.test>',
            'Subject: Automatic reply',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            '',
        ]);
    }
}

final class ProcessingMessageStore implements InboundMessageProcessingStoreInterface
{
    /** @var array<int, InboundMessageProcessingRecord> */
    public array $records = [];

    /** @var array<int, string> */
    public array $statuses = [];

    /** @var array<int, string|null> */
    public array $errors = [];

    /** @var array<int, string> */
    public array $bodies = [];

    public function add(InboundMessageProcessingRecord $message): void
    {
        $this->records[$message->id] = $message;
        $this->statuses[$message->id] = 'received';
        $this->errors[$message->id] = null;
    }

    public function findNextForProcessing(): ?InboundMessageProcessingRecord
    {
        ksort($this->records);

        foreach ($this->records as $id => $message) {
            if (in_array($this->statuses[$id], ['received', 'extracting'], true)) {
                return $message;
            }
        }

        return null;
    }

    public function findForProcessingById(int $messageId): ?InboundMessageProcessingRecord
    {
        return isset($this->records[$messageId])
            && in_array($this->statuses[$messageId], ['received', 'extracting'], true)
                ? $this->records[$messageId]
                : null;
    }

    public function markExtracting(int $messageId, string $timestamp): bool
    {
        if ($this->findForProcessingById($messageId) === null) {
            return false;
        }

        $this->statuses[$messageId] = 'extracting';
        $this->errors[$messageId] = null;

        return true;
    }

    public function markIgnored(int $messageId, string $reason, string $timestamp): bool
    {
        if ($this->findForProcessingById($messageId) === null) {
            return false;
        }

        $this->statuses[$messageId] = 'ignored';
        $this->errors[$messageId] = $reason;

        return true;
    }

    public function markParsed(int $messageId, string $bodyText, string $timestamp): bool
    {
        if (($this->statuses[$messageId] ?? null) !== 'extracting') {
            return false;
        }

        $this->statuses[$messageId] = 'parsed';
        $this->errors[$messageId] = null;
        $this->bodies[$messageId] = $bodyText;

        return true;
    }

    public function markFailed(int $messageId, string $reason, string $timestamp): bool
    {
        if (! in_array($this->statuses[$messageId] ?? null, ['received', 'extracting'], true)) {
            return false;
        }

        $this->statuses[$messageId] = 'failed';
        $this->errors[$messageId] = $reason;

        return true;
    }

    public function requeueFailedMessages(array $messageIds, string $timestamp): array
    {
        $requeued = [];

        foreach ($messageIds as $messageId) {
            if (($this->statuses[$messageId] ?? null) !== 'failed') {
                continue;
            }

            $this->statuses[$messageId] = 'received';
            $this->errors[$messageId] = null;
            $requeued[] = $messageId;
        }

        return $requeued;
    }
}

final class ProcessingFileStorage implements InboundMailStorageReaderInterface
{
    public int $readCount = 0;

    /**
     * @param array<string, string> $files
     * @param array<string, string>|null $attachmentPaths
     */
    public function __construct(
        public array $files = [],
        public ?array $attachmentPaths = null,
    ) {
    }

    public function readRawMessage(string $relativePath): string
    {
        ++$this->readCount;

        if (! array_key_exists($relativePath, $this->files)) {
            throw new RuntimeException('The scripted raw file is missing.');
        }

        return $this->files[$relativePath];
    }

    public function resolveAttachmentPath(string $relativePath): string
    {
        if ($this->attachmentPaths === null) {
            throw new RuntimeException('The processing job must not read attachments directly.');
        }

        return $this->attachmentPaths[$relativePath]
            ?? throw new RuntimeException('The stored inbound attachment is missing.');
    }

    public function storeRawMessage(string $rawMessage): string
    {
        throw new RuntimeException('The processing job must not store a second raw message.');
    }

    public function storeAttachment(string $content, string $extension): string
    {
        throw new RuntimeException('The processing job must not replace attachments.');
    }

    public function delete(string $relativePath): void
    {
        throw new RuntimeException('The processing job must not delete stored files.');
    }
}

final class ProcessingAttachmentExtractionStore implements AttachmentExtractionStoreInterface
{
    /** @var array<int, PdfExtractionResult> */
    public array $recorded = [];

    /**
     * @param list<StoredPdfAttachment> $attachments
     */
    public function __construct(private readonly array $attachments)
    {
    }

    public function findPendingPdfsForMessage(int $messageId): array
    {
        return $this->attachments;
    }

    public function recordResult(int $attachmentId, PdfExtractionResult $result): void
    {
        $this->recorded[$attachmentId] = $result;
    }
}

final class ProcessingPdfTextExtractor implements PdfTextExtractorInterface
{
    /** @var list<string> */
    public array $requestedPaths = [];

    public function __construct(private readonly PdfExtractionResult $result)
    {
    }

    public function extract(string $absolutePath, PdfExtractionLimits $limits): PdfExtractionResult
    {
        $this->requestedPaths[] = $absolutePath;

        return $this->result;
    }
}

final class ProcessingCandidateStore implements EventCandidateStoreInterface
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $candidates = [];

    public int $replaceCalls = 0;

    public ?RuntimeException $replaceFailure = null;

    public ?RuntimeException $discardFailure = null;

    public function replaceDraftCandidatesForMessage(
        int $messageId,
        ParseOutcome $outcome,
        string $timestamp
    ): void {
        if ($this->replaceFailure !== null) {
            throw $this->replaceFailure;
        }

        ++$this->replaceCalls;
        $this->candidates[$messageId] = [];

        foreach ($outcome->getCandidates() as $candidate) {
            $blockIndex = $candidate->getBlockIndex();

            if ($blockIndex === null) {
                throw new RuntimeException('A candidate has no source block index.');
            }

            $this->candidates[$messageId][$blockIndex] = $candidate->toArray();
        }
    }

    public function discardDraftCandidatesForMessage(int $messageId, string $timestamp): void
    {
        if ($this->discardFailure !== null) {
            throw $this->discardFailure;
        }

        $this->candidates[$messageId] = [];
    }
}

final class ProcessingFailureLogger implements InboundMessageProcessingFailureLoggerInterface
{
    /**
     * @var list<array{message_id: int, context: string, failure_class: string}>
     */
    public array $failures = [];

    public function logFailure(int $messageId, string $context, string $failureClass): void
    {
        $this->failures[] = [
            'message_id' => $messageId,
            'context' => $context,
            'failure_class' => $failureClass,
        ];
    }
}

final class ProcessingDirectorySnapshotProvider implements DirectorySnapshotProviderInterface
{
    public ?RuntimeException $failure = null;

    public function __construct(private DirectorySnapshot $snapshot)
    {
    }

    public function getSnapshot(): DirectorySnapshot
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->snapshot;
    }
}

final class ProcessingClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-09-25T05:00:00+02:00',
            new DateTimeZone('Africa/Johannesburg')
        );
    }
}

final class ProcessingLearningContactStore implements \ADCT\ParishIntake\Core\Ports\ParishContactStoreInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    private int $nextId = 1;

    public function findByEmail(string $email): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['email'] === strtolower(trim($email))
        ));
    }

    public function findLink(int $contactId, int $parishId): ?array
    {
        $row = $this->rows[$contactId] ?? null;

        return is_array($row) && (int) $row['parish_id'] === $parishId ? $row : null;
    }

    public function findForParish(int $parishId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => (int) $row['parish_id'] === $parishId
        ));
    }

    public function saveLink(
        int $parishId,
        string $email,
        string $displayName,
        string $roleLabel,
        bool $receivesReminders,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): void {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'parish_id' => $parishId,
            'email' => strtolower(trim($email)),
            'display_name' => $displayName,
            'role_label' => $roleLabel,
            'receives_reminders' => $receivesReminders ? 1 : 0,
            'trust' => $trust,
            'verified_at' => $verifiedAt,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    public function savePendingLink(
        int $parishId,
        string $email,
        string $displayName,
        string $roleLabel,
        bool $receivesReminders,
        string $timestamp
    ): int {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'parish_id' => $parishId,
            'email' => strtolower(trim($email)),
            'display_name' => $displayName,
            'role_label' => $roleLabel,
            'receives_reminders' => $receivesReminders ? 1 : 0,
            'trust' => \ADCT\ParishIntake\Core\Directory\SenderTrust::PENDING,
            'verified_at' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        return 1;
    }

    public function updateLink(
        int $contactId,
        int $parishId,
        string $email,
        string $displayName,
        string $roleLabel,
        bool $receivesReminders,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): int {
        return 0;
    }

    public function deleteLink(int $contactId, int $parishId): int
    {
        return 0;
    }

    public function setTrustForEmail(
        string $email,
        string $trust,
        ?string $verifiedAt,
        string $timestamp
    ): int {
        $updated = 0;
        $normalized = strtolower(trim($email));

        foreach ($this->rows as $id => $row) {
            if ($row['email'] === $normalized) {
                $this->rows[$id]['trust'] = $trust;
                $this->rows[$id]['verified_at'] = $verifiedAt;
                $this->rows[$id]['updated_at'] = $timestamp;
                ++$updated;
            }
        }

        return $updated;
    }
}

final class ProcessingSourceStore implements \ADCT\ParishIntake\Core\Ports\SourceStoreInterface
{
    /**
     * @param array<int, Source> $sources
     */
    public function __construct(private array $sources)
    {
    }

    public function findSource(int $sourceId): ?Source
    {
        return $this->sources[$sourceId] ?? null;
    }

    public function findForParish(int $parishId): array
    {
        return [];
    }

    public function saveSource(Source $source, string $timestamp): Source
    {
        return $source;
    }

    public function registerOfficialEmailSourceIfMissing(
        int $parishId,
        string $email,
        string $timestamp
    ): bool {
        return false;
    }
}
