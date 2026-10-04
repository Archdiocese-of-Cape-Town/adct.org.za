<?php

namespace ADCT\ParishIntake\WordPress;

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Auth\MagicLinkLoginService;
use ADCT\ParishIntake\Core\Auth\RoleInstaller;
use ADCT\ParishIntake\Core\Auth\VersionedRoleInstaller;
use ADCT\ParishIntake\Core\Database\CreateSchemaMigration;
use ADCT\ParishIntake\Core\Database\MailboxSchemaMigration;
use ADCT\ParishIntake\Core\Database\MigrationRunner;
use ADCT\ParishIntake\Core\Database\ProcessedMailboxOwnershipSchemaMigration;
use ADCT\ParishIntake\Core\Database\VenueSchemaMigration;
use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Events\IcsCalendar;
use ADCT\ParishIntake\Core\Publishing\CandidatePublisher;
use ADCT\ParishIntake\Core\Publishing\ReviewRequiredPublicationAuthority;
use ADCT\ParishIntake\Core\Events\OccurrenceExpander;
use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use ADCT\ParishIntake\Core\Events\RRuleValidator;
use ADCT\ParishIntake\Core\Events\SuburbResolver;
use ADCT\ParishIntake\Core\Directory\DeaneryCsvImporter;
use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\ParishCsvImporter;
use ADCT\ParishIntake\Core\Directory\SenderLearningService;
use ADCT\ParishIntake\Core\Directory\SenderParishSuggester;
use ADCT\ParishIntake\Core\Directory\VenueAdministrationService;
use ADCT\ParishIntake\Core\Directory\VenueDirectoryImporter;
use ADCT\ParishIntake\Core\Jobs\FrameworkHeartbeatJob;
use ADCT\ParishIntake\Core\Jobs\InboundMessageProcessingJob;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Jobs\ConfirmationEmailPreviewJob;
use ADCT\ParishIntake\Core\Jobs\MailQueueSenderJob;
use ADCT\ParishIntake\Core\Jobs\OccurrenceExpansionJob;
use ADCT\ParishIntake\Core\Ingestion\Imap\ImapMailbox;
use ADCT\ParishIntake\Core\Ingestion\MimeMessageParser;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResultsParser;
use ADCT\ParishIntake\Core\Ingestion\InboundHeaderBlockParser;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ingestion\MailboxConnectionTestService;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettingsValidator;
use ADCT\ParishIntake\Core\Ingestion\MessageContentHasher;
use ADCT\ParishIntake\Core\Ingestion\RawMessageInspector;
use ADCT\ParishIntake\Core\Jobs\MailboxPollingJob;
use ADCT\ParishIntake\Core\Mail\MailQueueConfiguration;
use ADCT\ParishIntake\Core\Mail\MailQueueDispatcher;
use ADCT\ParishIntake\Core\Mail\MailQueueService;
use ADCT\ParishIntake\Core\Mail\MailQueueStats;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailComposer;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailPreviewService;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailRenderer;
use ADCT\ParishIntake\Core\Mail\ConfirmationEmailResendService;
use ADCT\ParishIntake\Core\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Parsing\SectionSkipper;
use ADCT\ParishIntake\Core\Parsing\Stages\ConfidenceScoringStage;
use ADCT\ParishIntake\Core\Ocr\OcrTextEnrichmentService;
use ADCT\ParishIntake\Core\Pdf\PdfTextEnrichmentService;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\Core\Ports\MailQueueRepositoryInterface;
use ADCT\ParishIntake\Core\Security\SecretRegistry;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use ADCT\ParishIntake\Core\Sources\SourceRegistryService;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\Core\Review\CandidateEditValidator;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\WordPress\Pdf\PrinsFrankPdfTextExtractor;
use ADCT\ParishIntake\WordPress\Pdf\WordPressAttachmentExtractionStore;
use ADCT\ParishIntake\WordPress\Admin\AuditLogPage;
use ADCT\ParishIntake\WordPress\Admin\SubjectAuditPanel;
use ADCT\ParishIntake\WordPress\Admin\ScheduledJobsPage;
use ADCT\ParishIntake\WordPress\Admin\HealthPage;
use ADCT\ParishIntake\WordPress\Admin\WordPressHelp;
use ADCT\ParishIntake\WordPress\Admin\InboundMessagesPage;
use ADCT\ParishIntake\WordPress\Admin\DeaneriesPage;
use ADCT\ParishIntake\WordPress\Admin\MailboxesPage;
use ADCT\ParishIntake\WordPress\Admin\OutboundMailPage;
use ADCT\ParishIntake\WordPress\Admin\ParserPage;
use ADCT\ParishIntake\WordPress\Admin\ParishesPage;
use ADCT\ParishIntake\WordPress\Admin\SendersPage;
use ADCT\ParishIntake\WordPress\Admin\SourcesPage;
use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
use ADCT\ParishIntake\WordPress\Ai\OpenAiCompatibleProvider;
use ADCT\ParishIntake\WordPress\Ai\WordPressAiCallGate;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Auth\ConfirmationDecisionHandler;
use ADCT\ParishIntake\WordPress\Auth\ApprovalDecisionHandler;
use ADCT\ParishIntake\WordPress\Auth\ApprovalEditHandler;
use ADCT\ParishIntake\WordPress\Auth\LoginHandler;
use ADCT\ParishIntake\WordPress\Auth\MagicLinkLoginRequestPage;
use ADCT\ParishIntake\WordPress\Auth\NotifyModeChangeHandler;
use ADCT\ParishIntake\WordPress\Auth\RevertChangeHandler;
use ADCT\ParishIntake\WordPress\Auth\UnpublishEventHandler;
use ADCT\ParishIntake\Core\Approval\ApprovalReminderSettings;
use ADCT\ParishIntake\WordPress\Auth\WordPressLoginSubjectResolver;
use ADCT\ParishIntake\WordPress\Auth\WordPressMagicLinkDelivery;
use ADCT\ParishIntake\WordPress\Approval\ApprovalNoticeJob;
use ADCT\ParishIntake\WordPress\Approval\ApprovalReminderJob;
use ADCT\ParishIntake\WordPress\Approval\ApprovalReminderOptionReader;
use ADCT\ParishIntake\WordPress\Approval\ApprovalRecipients;
use ADCT\ParishIntake\WordPress\Approval\FrontEndApprovalQueue;
use ADCT\ParishIntake\WordPress\Approval\ReviewerNotificationPreference;
use ADCT\ParishIntake\WordPress\Auth\WordPressConfirmationActionLinkProvider;
use ADCT\ParishIntake\WordPress\Auth\WordPressActionTokenRateLimitKeyProvider;
use ADCT\ParishIntake\WordPress\Auth\WordPressActionTokenRenewalDelivery;
use ADCT\ParishIntake\WordPress\Auth\WordPressRoleCapabilityStore;
use ADCT\ParishIntake\WordPress\Auth\WordPressRoleVersionStore;
use ADCT\ParishIntake\WordPress\Database\ActionTokenRateLimitSchemaMigration;
use ADCT\ParishIntake\WordPress\Database\ApprovalNoticesMigration;
use ADCT\ParishIntake\WordPress\Database\ConfirmationEmailPreviewSchemaMigration;
use ADCT\ParishIntake\WordPress\Database\DbDeltaSchemaInstaller;
use ADCT\ParishIntake\WordPress\Database\FollowUpParishNullableMigration;
use ADCT\ParishIntake\WordPress\Database\MailQueueGroupKeyMigration;
use ADCT\ParishIntake\WordPress\Database\OccurrenceParishNullableMigration;
use ADCT\ParishIntake\WordPress\Database\Repository\FollowUpRepository;
use ADCT\ParishIntake\WordPress\Database\SenderSuggestionMigration;
use ADCT\ParishIntake\WordPress\Attachments\ActionTokenImageEndpoint;
use ADCT\ParishIntake\WordPress\Attachments\AttachmentImageEndpoint;
use ADCT\ParishIntake\WordPress\Attachments\OcrControl;
use ADCT\ParishIntake\WordPress\Attachments\SourceMaterialAuditTrail;
use ADCT\ParishIntake\WordPress\Attachments\WordPressSourceMaterialCopier;
use ADCT\ParishIntake\WordPress\Attachments\WordPressSourceMaterialStore;
use ADCT\ParishIntake\WordPress\Attachments\WordPressCandidateSourceMessage;
use ADCT\ParishIntake\WordPress\Attachments\WordPressPreviewableImageRepository;
use ADCT\ParishIntake\WordPress\Audit\AuditLogRepository;
use ADCT\ParishIntake\WordPress\Audit\ContactAuditRecorder;
use ADCT\ParishIntake\Core\Attachments\CandidateSourceImageResolver;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialPromotion;
use ADCT\ParishIntake\WordPress\Database\Repository\ApprovalRouteRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\MailboxRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\OccurrenceRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use ADCT\ParishIntake\WordPress\Database\Schema;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenStore;
use ADCT\ParishIntake\WordPress\Database\WordPressMailQueueRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressInboundMessageStore;
use ADCT\ParishIntake\WordPress\Database\WordPressProcessedMailboxMessageStore;
use ADCT\ParishIntake\WordPress\Database\WordPressEventCandidateStore;
use ADCT\ParishIntake\WordPress\Database\WordPressMigrationLogger;
use ADCT\ParishIntake\WordPress\Database\WordPressMigrationVersionStore;
use ADCT\ParishIntake\WordPress\Directory\CachedDirectorySnapshotProvider;
use ADCT\ParishIntake\WordPress\Export\StaticReportGenerator;
use ADCT\ParishIntake\WordPress\Http\WordPressHttpClient;
use ADCT\ParishIntake\WordPress\Directory\DirectoryImportService;
use ADCT\ParishIntake\WordPress\Directory\DeaneryApproverAssignmentService;
use ADCT\ParishIntake\WordPress\Directory\WordPressDirectorySnapshotCache;
use ADCT\ParishIntake\WordPress\Directory\WordPressDirectorySnapshotLoader;
use ADCT\ParishIntake\WordPress\Directory\WordPressDirectoryVersionStore;
use ADCT\ParishIntake\WordPress\Ocr\LazyOcrProvider;
use ADCT\ParishIntake\WordPress\Ocr\OcrSpaceProvider;
use ADCT\ParishIntake\WordPress\Ocr\WordPressImageOcrExtractionStore;
use ADCT\ParishIntake\WordPress\Ocr\WordPressOcrCallGate;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobLock;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobScheduler;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobStateStore;
use ADCT\ParishIntake\WordPress\Jobs\HealthAlerts;
use ADCT\ParishIntake\WordPress\Jobs\RetentionCleanupJob;
use ADCT\ParishIntake\WordPress\Jobs\RetentionSettings;
use ADCT\ParishIntake\WordPress\Jobs\OcrSettings;
use ADCT\ParishIntake\WordPress\Jobs\WordPressInboundMessageProcessingFailureLogger;
use ADCT\ParishIntake\WordPress\Change\ChangeHistoryBox;
use ADCT\ParishIntake\WordPress\Change\ChangeHistoryRepository;
use ADCT\ParishIntake\WordPress\Change\ChangeNoticeJob;
use ADCT\ParishIntake\WordPress\Mail\WordPressMailDeliveryAdapter;
use ADCT\ParishIntake\WordPress\Mail\WordPressMailQueueImmediateDispatch;
use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeRecipientPolicy;
use ADCT\ParishIntake\WordPress\Mail\WordPressTestModeSettings;
use ADCT\ParishIntake\WordPress\Events\EventEditor;
use ADCT\ParishIntake\WordPress\Events\EventOccurrenceHooks;
use ADCT\ParishIntake\WordPress\Events\EventListingGeneration;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use ADCT\ParishIntake\WordPress\Events\EventSourceMaterialEditor;
use ADCT\ParishIntake\WordPress\Events\PublicEventPage;
use ADCT\ParishIntake\WordPress\Events\EventTypeKeywords;
use ADCT\ParishIntake\WordPress\Events\PublicEventListing;
use ADCT\ParishIntake\WordPress\Events\PublicIcsFeed;
use ADCT\ParishIntake\WordPress\Events\PlaceCoordinateLookup;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use ADCT\ParishIntake\WordPress\Ingestion\WordPressConfirmationEmailJobSource;
use ADCT\ParishIntake\WordPress\Ingestion\WordPressConfirmationEmailResendSource;
use ADCT\ParishIntake\WordPress\Events\WordPressEventOccurrenceMaintenance;
use ADCT\ParishIntake\WordPress\Publishing\WordPressPublicationStore;
use ADCT\ParishIntake\WordPress\Security\WordPressSecretResolver;
use DateTimeZone;

final class Plugin
{
    /**
     * ADR 0007: a magic-link sign-in gets a long session for the two portal
     * roles. Overridable with `ADCT_PI_AUTH_COOKIE_LIFETIME_DAYS` in
     * `wp-config.php`, because it is a hosting decision.
     */
    public const DEFAULT_AUTH_COOKIE_LIFETIME_DAYS = 365;

    private static ?self $instance = null;

    private string $pluginFile;
    private Schema $schema;
    private ActionTokenService $actionTokenService;
    private ActionTokenHandlerRegistry $actionTokenHandlers;
    private ActionTokenEndpoint $actionTokenEndpoint;

    /**
     * Collaborators for {@see self::actionTokenEndpoint()}, built eagerly because
     * they are plain PHP. Only the endpoint itself is deferred, since it renders
     * OCR markup and therefore needs `plugins_url()`.
     *
     * @var array{renewals: ActionTokenRenewalService, images: ActionTokenImageEndpoint}
     */
    private array $actionTokenEndpointDependencies;

    /**
     * Built on first use so that construction stays free of WordPress functions.
     */
    private function actionTokenEndpoint(): ActionTokenEndpoint
    {
        if (! isset($this->actionTokenEndpoint)) {
            $this->actionTokenEndpoint = new ActionTokenEndpoint(
                $this->actionTokenService,
                $this->actionTokenHandlers,
                $this->actionTokenEndpointDependencies['renewals'],
                $this->actionTokenEndpointDependencies['images'],
                $this->ocrControl()
            );
        }

        return $this->actionTokenEndpoint;
    }

            /**
             * The confirmation resend service for the review screen (issue #176).
             *
             * Built on first use rather than in the constructor for two reasons. The mail
             * queue and the token service are both assembled later than the review screen,
             * and `wp_timezone()` is a WordPress function, which the release bootstrap
             * check would hit if this ran during construction.
             *
             * Returns null before the mail queue exists, which is the one case the screen
             * renders without a resend control rather than an error.
             */
            private function confirmationResendService(): ?ConfirmationEmailResendService
            {
                if (! isset($this->mailQueue, $this->actionTokenService, $this->mailQueueRepository)) {
                    return null;
                }

                if ($this->confirmationResendService === null) {
                    $timezone = function_exists('wp_timezone')
                        ? wp_timezone()
                        : new DateTimeZone('Africa/Johannesburg');

                    $this->confirmationResendService = new ConfirmationEmailResendService(
                        $this->clock,
                        $this->mailQueue,
                        new ConfirmationEmailComposer(
                            $this->actionTokenService,
                            new WordPressConfirmationActionLinkProvider(),
                            $this->confirmationEmailRenderer($timezone)
                        ),
                        $this->mailQueueRepository,
                        new WordPressConfirmationEmailResendSource(
                            $this->database,
                            $this->reviewQueue(),
                            $this->parishContacts,
                            new ProtectedInboundMailStorage(),
                            new InboundHeaderBlockParser(),
                            $timezone
                        ),
                        $timezone
                    );
                }

                return $this->confirmationResendService;
            }

            /**
             * The renderer shared by the confirmation preview job and the resend service.
             *
             * Shared on purpose: a resend must render the parish's email through exactly
             * the same code as the original, or the two would drift and a "resend" could
             * produce a subtly different document from the one the parish already has.
             */
            private function confirmationEmailRenderer(DateTimeZone $timezone): ConfirmationEmailRenderer
            {
                if ($this->confirmationRenderer === null) {
                    $this->confirmationRenderer = new ConfirmationEmailRenderer(
                        $timezone,
                        $this->confidenceOption(
                            'adct_parish_intake_field_confidence_threshold',
                            ConfidenceScoringStage::DEFAULT_FIELD_THRESHOLD
                        )
                    );
                }

                return $this->confirmationRenderer;
            }

                private PipelineFactory $pipelineFactory;
    private ParserPage $parserPage;
    private HttpClientInterface $httpClient;
    private WordPressJobScheduler $jobScheduler;
    private ScheduledJobsPage $scheduledJobsPage;
    private HealthPage $healthPage;
    private WordPressHelp $adminHelp;
        private WordPressDatabaseConnection $database;
        private ParishContactRepository $parishContacts;
        private ?ClockInterface $clock = null;
        private ?ConfirmationEmailRenderer $confirmationRenderer = null;
    private HealthAlerts $healthAlerts;
    private MailQueueService $mailQueue;
    private OutboundMailPage $outboundMailPage;
    private DeaneriesPage $deaneriesPage;
    private ParishesPage $parishesPage;
    private SendersPage $sendersPage;
    private SourcesPage $sourcesPage;
    private EventPostType $eventPostType;
    private EventEditor $eventEditor;
    private ChangeHistoryBox $changeHistoryBox;
    private EventOccurrenceHooks $eventOccurrenceHooks;
    private CandidatePublisher $candidatePublisher;
    private PublicEventListing $publicEventListing;
    private PublicEventPage $publicEventPage;
    private PublicIcsFeed $publicIcsFeed;
    private MailboxesPage $mailboxesPage;
    private InboundMessagesPage $inboundMessagesPage;
    private AuditLogPage $auditLogPage;

    /**
     * Kept so the audit screen and the integration check read the same
     * repository the rest of the plugin writes through.
     */
    private AuditLogRepository $auditLog;
        private ?MailQueueRepositoryInterface $mailQueueRepository = null;
        private ?ConfirmationEmailResendService $confirmationResendService = null;
    private ?ReviewQueuePage $reviewQueuePage = null;
    private ?ReviewQueueRepository $reviewQueue = null;
    private ?FrontEndApprovalQueue $frontEndApprovalQueue = null;
    private ?MagicLinkLoginRequestPage $magicLinkLoginRequestPage = null;
    private ReviewerNotificationPreference $reviewerNotificationPreference;
    private ?OcrControl $ocrControl = null;

    /**
     * The event editor's poster and bulletin box, built on first use.
     *
     * Lazy for the same reason {@see ocrControl()} is: `Plugin` is constructed
     * by the release bootstrap check with no WordPress loaded, so nothing here
     * may touch a global. The box is optional - when its collaborators cannot be
     * built the property stays null and the meta box is simply not registered.
     */
    private ?EventSourceMaterialEditor $eventSourceMaterial = null;
    private AttachmentImageEndpoint $attachmentImageEndpoint;

    /**
     * The shared OCR markup renderer, built on first use.
     *
     * `plugins_url()` is only called from here rather than in the constructor,
     * because the constructor also runs under the release bootstrap check,
     * which loads the plugin outside WordPress.
     */
    private function ocrControl(): OcrControl
    {
        if ($this->ocrControl === null) {
            $this->ocrControl = new OcrControl(
                plugins_url('assets/ocr.js', $this->pluginFile),
                plugins_url('assets/ocr.css', $this->pluginFile),
                plugins_url('assets/ocr-settings.js', $this->pluginFile)
            );
        }

        return $this->ocrControl;
    }

    /**
     * The promotion rules shared by the review queue's promote control and the
     * event editor's box (issue #172).
     *
     * Both surfaces get the *same* instance so the rules - at most one poster,
          * a copy taken at the moment of the decision, removal never deletes - cannot
     * drift apart between them.
     */
    private function sourceMaterialPromotion(): SourceMaterialPromotion
    {
        return new SourceMaterialPromotion(
            new WordPressSourceMaterialCopier(new ProtectedInboundMailStorage()),
            new WordPressSourceMaterialStore()
        );
    }

    private function sourceMaterialAuditTrail(): SourceMaterialAuditTrail
    {
        return new SourceMaterialAuditTrail($this->auditLog, $this->auditLog->actorResolver());
    }

    private function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->schema = new Schema();
        $this->httpClient = new WordPressHttpClient();
        $clock = new SystemClock();
        $database = new WordPressDatabaseConnection();
                $this->clock = $clock;
                $this->database = $database;
        $directoryVersions = new WordPressDirectoryVersionStore($database);
        $parishes = new ParishRepository($database, $directoryVersions);
        $deaneries = new DeaneryRepository($database);
        $approvers = new DeaneryApproverRepository($database);
        $contacts = new ParishContactRepository($database, $directoryVersions);
                $this->parishContacts = $contacts;
        $venues = new VenueRepository($database, $directoryVersions);
        $sources = new SourceRepository($database);
        $timezone = function_exists('wp_timezone')
            ? wp_timezone()
            : new DateTimeZone('Africa/Johannesburg');
        $rruleValidator = new RRuleValidator();
        $mailboxes = new MailboxRepository($database);
        $inboundMessages = new InboundMessageRepository($database);
        $attachmentRepository = new AttachmentRepository($database);
        $inboundMessageStore = new WordPressInboundMessageStore(
            $database,
            $inboundMessages,
            $attachmentRepository,
            static fn (): RetentionSettings => RetentionSettings::current()
        );
        $processedMailboxMessages = new WordPressProcessedMailboxMessageStore($database);
        $secrets = new WordPressSecretResolver();
        $directorySnapshots = new CachedDirectorySnapshotProvider(
            $directoryVersions,
            new WordPressDirectorySnapshotCache(),
            new WordPressDirectorySnapshotLoader($parishes, $venues, $contacts)
        );
        $this->pipelineFactory = new PipelineFactory($clock, $directorySnapshots, new EventTypeKeywords());
        $previewableImages = new WordPressPreviewableImageRepository($attachmentRepository);
        $this->attachmentImageEndpoint = new AttachmentImageEndpoint(
            $previewableImages,
            new ProtectedInboundMailStorage()
        );
        $auditLog = new AuditLogRepository($database, $clock);
        $this->auditLog = $auditLog;
        // One resolver for every screen that writes a row, so the repository
        // and the screens agree on who the actor is.
        $actorResolver = $auditLog->actorResolver();
        $contactAudit = new ContactAuditRecorder($auditLog, $actorResolver);
                // One panel, three screens (issue #58). Built here so every screen reads
                // the audit trail the same way; each host takes it as an optional
                // collaborator, so none of them breaks when it is absent.
                $subjectAuditPanel = new SubjectAuditPanel($auditLog, $clock, $timezone);
                $this->parserPage = new ParserPage(
            $this->schema,
            $this->pipelineFactory,
            new StaticReportGenerator($this->schema),
            $this->httpClient,
            new WordPressAiCallGate(
                new WordPressActionTokenRateLimitStore($database),
                $clock
            ),
            $attachmentRepository,
            $this->pluginFile,
            $this->attachmentImageEndpoint,
            $auditLog,
            $actorResolver
        );
        $sourceRegistryService = new SourceRegistryService($sources, $clock);
        $this->mailboxesPage = new MailboxesPage(
            $mailboxes,
            $inboundMessages,
            $sources,
            $sourceRegistryService,
            new MailboxSettingsValidator(),
            new MailboxConnectionTestService(
                new SourceHealthRecorder($sources, $clock),
                static fn (MailboxConnectionConfig $config): MailboxInterface => new ImapMailbox($config)
            ),
            $secrets,
            $clock
        );
        $contactService = new ContactService($contacts, $clock);
        $senderLearningService = new SenderLearningService(
            $contactService,
            new SenderParishSuggester($directorySnapshots)
        );
        $venueAdministrationService = new VenueAdministrationService($venues, $clock);
        $approvalRouteResolver = new ApprovalRouteResolver(new ApprovalRouteRepository($database));
        $approvalRecipients = new ApprovalRecipients($approvalRouteResolver);
        $this->reviewerNotificationPreference = new ReviewerNotificationPreference();
        $this->sourcesPage = new SourcesPage($sources, $sourceRegistryService, $parishes);
        $this->deaneriesPage = new DeaneriesPage(
            $deaneries,
            $approvers,
            new DeaneryApproverAssignmentService($approvers),
            $clock
        );
        $this->parishesPage = new ParishesPage(
            $parishes,
            $deaneries,
            $contacts,
            $contactService,
            new DirectoryImportService(
                new ParishCsvImporter(),
                new DeaneryCsvImporter(),
                $parishes,
                $deaneries,
                $contactService,
                $clock,
                $sourceRegistryService,
                new VenueDirectoryImporter($venues, $clock)
            ),
            $approvalRouteResolver,
            $venues,
            $venueAdministrationService,
            $clock,
            $this->sourcesPage,
            $contactAudit,
            $subjectAuditPanel
        );
        $this->sendersPage = new SendersPage($contacts, $contactService, $parishes, $contactAudit);
        $this->eventPostType = new EventPostType();
        $listingGeneration = new EventListingGeneration();
        $occurrences = new OccurrenceRepository($database);
        $this->publicEventListing = new PublicEventListing(
            $clock,
            new DateTimeZone('Africa/Johannesburg'),
            $pluginFile,
            $listingGeneration,
            new SuburbResolver(new PlaceCoordinateLookup($database))
        );
        $this->publicIcsFeed = new PublicIcsFeed($clock, $listingGeneration, new IcsCalendar());
        $this->publicEventPage = new PublicEventPage(
            $clock,
            $timezone,
            $parishes,
            $venues,
            $occurrences,
                        $pluginFile,
                        // Stateless, so the renderers and the promotion service share one
                        // instance. Constructed here rather than inside
                        // sourceMaterialPromotion() so the three readers of the promoted
                        // list cannot disagree about what has been promoted.
                        new WordPressSourceMaterialStore()
                    );
        $this->eventEditor = new EventEditor(
            $parishes,
            $venues,
            new EventValidator($timezone, $rruleValidator),
            new RRulePresetMapper($rruleValidator),
            $timezone,
            $clock,
            $subjectAuditPanel
        );
        // ADR 0008 point 4 promises approvers a before/after summary; the change
        // notice mail carries it, and this box is the second place it has to be
        // readable -- a dean answering "what did this event look like before you
        // changed it?" weeks later is reading the event, not an old mail.
        // Read-only, because both state transitions run over the mailed token
        // flow (see ChangeHistoryBox).
        $this->changeHistoryBox = new ChangeHistoryBox(new ChangeHistoryRepository($database));
        $occurrenceMaintenance = new WordPressEventOccurrenceMaintenance(
            $occurrences,
            new OccurrenceExpander($timezone, $rruleValidator),
            $clock,
            function (): void {
                $this->publicEventListing->invalidate();
            }
        );
        $this->candidatePublisher = new CandidatePublisher(
            new WordPressPublicationStore(
                $database,
                new EventCandidateRepository($database),
                $occurrenceMaintenance,
                $listingGeneration,
                $clock,
                $timezone,
                $auditLog
            ),
            new EventValidator($timezone, $rruleValidator),
            // Issue #200 asks whether a verified contact may publish a change or whether
            // it must still be reviewed. It is still open, so the conservative answer is
            // the one that ships: a contact change waits for a dean or a reviewer like
            // anything else, and ReviewRequiredPublicationAuthority says so without ever
            // looking at the directory. When the owner answers, this argument becomes
            // new VerifiedContactPublicationAuthority($contacts) and nothing else changes.
            new ReviewRequiredPublicationAuthority(),
            $contacts
        );
        if (function_exists('add_action')) {
            // The review threshold is deliberately its own setting (#43). It used to be read from
            // the AI threshold, which conflates "how confident must the parser be before a human
            // looks at this" with "when should we pay for an AI call". Sites on the old value keep
            // their behaviour, because both defaults are 0.55.
            $confidenceThreshold = $this->confidenceOption(
                'adct_parish_intake_confidence_threshold',
                ReviewQueuePolicy::DEFAULT_CONFIDENCE_THRESHOLD
            );
            $this->reviewQueue = new ReviewQueueRepository(
                $database,
                $clock,
                new ReviewQueuePolicy(),
                $confidenceThreshold
            );
            $this->reviewQueuePage = new ReviewQueuePage(
                $this->reviewQueue,
                $this->candidatePublisher,
                new ReviewQueuePolicy(),
                $inboundMessages,
                $attachmentRepository,
                new ProtectedInboundMailStorage(),
                new CandidateEditValidator(),
                $this->pluginFile,
                $this->ocrControl(),
                $this->attachmentImageEndpoint,
                $this->confirmationResendService(),
                $subjectAuditPanel,
                $this->sourceMaterialPromotion(),
                $this->sourceMaterialAuditTrail()
            );
            // #172: the event editor's poster and bulletin box, sharing this
            // repository so a file it offers is a file the queue would offer.
            $this->eventSourceMaterial = new EventSourceMaterialEditor(
                $this->sourceMaterialPromotion(),
                $this->reviewQueue,
                $attachmentRepository,
                $this->sourceMaterialAuditTrail()
            );
        // #72: the same repository and policy behind a front-end page, so a
        // dean is scoped by exactly the same predicate as a reviewer in
        // wp-admin (ADR 0008: deans never need wp-admin).
        $this->frontEndApprovalQueue = new FrontEndApprovalQueue(
            new ReviewQueueRepository($database, $clock, new ReviewQueuePolicy(), $confidenceThreshold),
            $this->candidatePublisher,
            new ReviewQueuePolicy(),
            new CandidateEditValidator()
        );
        }
        // Attached rather than passed to the constructor: EventEditor's own
        // constructor runs before the review queue repository exists.
        $this->eventEditor->attachSourceMaterial($this->eventSourceMaterial);
        $this->eventOccurrenceHooks = new EventOccurrenceHooks(
            $occurrenceMaintenance,
            $clock,
            $timezone
        );
        $stateStore = new WordPressJobStateStore();
        $trustedAuthservIds = defined('ADCT_PI_TRUSTED_AUTHSERV_IDS')
            ? constant('ADCT_PI_TRUSTED_AUTHSERV_IDS')
            : [];

        if (! is_array($trustedAuthservIds)) {
            throw new \InvalidArgumentException(
                'ADCT_PI_TRUSTED_AUTHSERV_IDS must be a list of authentication server IDs.'
            );
        }

        $jobRunner = new JobRunner(
            new WordPressJobLock(),
            $stateStore,
            $clock,
            JobRunner::DEFAULT_TIME_BUDGET_SECONDS,
            JobRunner::DEFAULT_ITEM_BUDGET,
            JobRunner::DEFAULT_LOCK_TTL_SECONDS
        );
        $mailQueueConfiguration = self::mailQueueConfiguration();
        $mailRecipientPolicy = new WordPressTestModeRecipientPolicy();
        $mailQueueRepository = new WordPressMailQueueRepository($database);
                $this->mailQueueRepository = $mailQueueRepository;
                $this->outboundMailPage = new OutboundMailPage($mailQueueRepository);
        $mailQueueDispatcher = new MailQueueDispatcher(
            $mailQueueRepository,
            new WordPressMailDeliveryAdapter(),
            $mailRecipientPolicy,
            $clock,
            $mailQueueConfiguration
        );
        $mailQueueSenderJob = new MailQueueSenderJob($mailQueueDispatcher);
        $this->mailQueue = new MailQueueService(
            $mailQueueRepository,
            $mailRecipientPolicy,
            $clock,
            new WordPressMailQueueImmediateDispatch($jobRunner, $mailQueueSenderJob)
        );
        $protectedInboundMailStorage = new ProtectedInboundMailStorage();
        $this->actionTokenService = new ActionTokenService(
            new WordPressActionTokenStore($database),
            $clock
        );
        $actionTokenRateLimiter = new ActionTokenRateLimiter(
            new WordPressActionTokenRateLimitStore($database),
            $clock,
            new WordPressActionTokenRateLimitKeyProvider()
        );
        $this->actionTokenHandlers = new ActionTokenHandlerRegistry();
        foreach ([\ADCT\ParishIntake\Core\Auth\ActionTokenPurpose::CONFIRM,
            \ADCT\ParishIntake\Core\Auth\ActionTokenPurpose::DENY] as $purpose) {
            $this->actionTokenHandlers->register(new ConfirmationDecisionHandler(
                $purpose,
                $database,
                $approvalRouteResolver,
                $this->candidatePublisher,
                $clock
            ));
        }
        foreach ([\ADCT\ParishIntake\Core\Auth\ActionTokenPurpose::APPROVE_EVENT,
            \ADCT\ParishIntake\Core\Auth\ActionTokenPurpose::REJECT_EVENT] as $purpose) {
            $this->actionTokenHandlers->register(new ApprovalDecisionHandler(
                $purpose, $database, $approvalRecipients, $this->candidatePublisher, $this->mailQueue, $clock
            ));
        }
        $this->actionTokenHandlers->register(new ApprovalEditHandler(
            $database, $approvalRecipients, $clock
        ));
        $this->actionTokenHandlers->register(new RevertChangeHandler(
            $database,
            $approvalRecipients,
            $this->mailQueue,
            $clock,
            $occurrenceMaintenance,
            $listingGeneration,
            $timezone
        ));
        // ADR 0008 point 4 pairs Revert with Unpublish in the same notice.
        // Reverting puts back the fields a change overwrote; it cannot answer
        // "this event was never ours to publish", which needs the event itself
        // to come down. Registered as a separate handler rather than a flag on
        // RevertChangeHandler so the two tokens cannot be confused for one
        // another: only one of them can be walked back from the trail.
        $this->actionTokenHandlers->register(new UnpublishEventHandler(
            $database,
            $approvalRecipients,
            $this->mailQueue,
            $clock,
            $occurrenceMaintenance,
            $listingGeneration,
            $timezone
        ));
        // ADR 0007: the magic link that signs a dean in to the front-end
        // approval queue. Registered against LOGIN here so the reservation test
        // can see the purpose and its handler from one place.
        $this->actionTokenHandlers->register(new LoginHandler(ActionTokenPurpose::LOGIN));
        // Issue #169: the link a deanery approver follows to choose between
        // per-item notices and a daily digest. A dean authenticates by emailed
        // link (ADR 0007) and has no profile page of their own to change it on,
        // so without this the Deaneries screen was the only way to set it.
        $this->actionTokenHandlers->register(new NotifyModeChangeHandler(
            $database, $approvers, $clock
        ));
        $this->magicLinkLoginRequestPage = new MagicLinkLoginRequestPage(
            new MagicLinkLoginService(
                $this->actionTokenService,
                $actionTokenRateLimiter,
                new WordPressLoginSubjectResolver(),
                new WordPressMagicLinkDelivery($this->mailQueue)
            )
        );
        $this->actionTokenEndpointDependencies = [
            'renewals' => new ActionTokenRenewalService(
                $this->actionTokenService,
                $actionTokenRateLimiter,
                new WordPressActionTokenRenewalDelivery($this->mailQueue)
            ),
            'images' => new ActionTokenImageEndpoint(
                $this->actionTokenService,
                new CandidateSourceImageResolver(
                    $previewableImages,
                    new WordPressCandidateSourceMessage(new EventCandidateRepository($database))
                ),
                $previewableImages,
                $protectedInboundMailStorage
            ),
        ];
        $confirmationPreviewJob = new ConfirmationEmailPreviewJob(
            new WordPressConfirmationEmailJobSource(
                $database,
                $contacts,
                new ProtectedInboundMailStorage(),
                new InboundHeaderBlockParser(),
                $timezone
            ),
            new ConfirmationEmailPreviewService(
                $this->actionTokenService,
                new WordPressConfirmationActionLinkProvider(),
                $this->mailQueue,
                $mailQueueRepository,
                                $this->confirmationEmailRenderer($timezone)
                            ),
                            $clock
                        );
        $mailboxPollingJob = new MailboxPollingJob(
            $mailboxes,
            $sources,
            $inboundMessageStore,
            $processedMailboxMessages,
            $protectedInboundMailStorage,
            new SourceHealthRecorder($sources, $clock),
            new RawMessageInspector(new AuthenticationResultsParser($trustedAuthservIds)),
            new AttachmentStoragePolicy(),
            new MessageContentHasher(),
            $clock,
            static fn (MailboxSettings $settings): string => $secrets->resolve(
                SecretRegistry::IMAP_PASSWORD,
                $settings->secretScope()
            ),
            static function (MailboxSettings $settings, string $password): MailboxInterface {
                return new ImapMailbox(new MailboxConnectionConfig(
                    host: $settings->host,
                    port: $settings->port,
                    encryption: $settings->encryption,
                    username: $settings->username,
                    password: $password,
                    folders: [
                        'inbox' => $settings->inboxFolder,
                        'processed' => $settings->processedFolder,
                    ],
                    maxMessageSizeBytes: $settings->maxMessageSizeBytes
                ));
            }
        );
        $inboundMessageProcessingJob = new InboundMessageProcessingJob(
            $inboundMessageStore,
            $protectedInboundMailStorage,
            new MimeMessageParser(),
            fn () => $this->parserPage->createConfiguredPipeline(
                function_exists('wp_doing_cron') && wp_doing_cron()
            ),
            new WordPressEventCandidateStore(new EventCandidateRepository($database)),
            new WordPressInboundMessageProcessingFailureLogger(),
            $directorySnapshots,
            $clock,
            $senderLearningService,
            pdfTextEnrichment: new PdfTextEnrichmentService(
                new WordPressAttachmentExtractionStore(
                    new AttachmentRepository($database),
                    $clock
                ),
                new PrinsFrankPdfTextExtractor(),
                $protectedInboundMailStorage
                            ),
                            // Poster OCR is off until an archdiocese administrator opts in on the
                            // settings screen, and an absent key leaves the provider unavailable, so
                            // this collapses to the no-OCR path rather than failing the message. The
                            // provider is built lazily: this constructor runs before WordPress is
                            // loaded, so reading options or resolving secrets here would be a fatal
                            // at install time, and it also means a settings change takes effect on the
                            // next message rather than needing a re-activation.
                            ocrTextEnrichment: new OcrTextEnrichmentService(
                                new WordPressImageOcrExtractionStore($attachmentRepository, $clock),
                                new LazyOcrProvider(
                                    function () use ($secrets, $database, $clock): OcrSpaceProvider {
                                        $settings = OcrSettings::current();

                                        return new OcrSpaceProvider(
                                            $secrets->resolve(SecretRegistry::OCR_API_KEY),
                                            $this->httpClient,
                                            new WordPressOcrCallGate(
                                                new WordPressActionTokenRateLimitStore($database),
                                                $clock,
                                                $settings->isEnabled() ? $settings->dailyCallLimit() : 1
                                            )
                                        );
                                    }
                                ),
                                $protectedInboundMailStorage,
                                null,
                                null,
                                static fn (): bool => OcrSettings::current()->isEnabled()
                            )
                        );
        // This is the only scheduled retention path; processed mail is deleted only by exact stored move receipts.
        $retentionCleanupJob = new RetentionCleanupJob(
            $database,
            $processedMailboxMessages,
            $protectedInboundMailStorage,
            $mailboxes,
            static fn (): RetentionSettings => RetentionSettings::current(),
            static fn (MailboxSettings $settings): string => $secrets->resolve(
                SecretRegistry::IMAP_PASSWORD,
                $settings->secretScope()
            ),
            static function (MailboxSettings $settings, string $password): MailboxInterface {
                return new ImapMailbox(new MailboxConnectionConfig(
                    host: $settings->host,
                    port: $settings->port,
                    encryption: $settings->encryption,
                    username: $settings->username,
                    password: $password,
                    folders: [
                        'inbox' => $settings->inboxFolder,
                        'processed' => $settings->processedFolder,
                    ],
                    maxMessageSizeBytes: $settings->maxMessageSizeBytes
                ));
            },
            $clock
        );
        $this->inboundMessagesPage = new InboundMessagesPage(
            $inboundMessages,
            $inboundMessageStore,
            $inboundMessageProcessingJob,
            $jobRunner,
            $clock
        );
        $this->auditLogPage = new AuditLogPage(
            $auditLog,
            $clock,
            new DateTimeZone('Africa/Johannesburg')
        );
        $this->jobScheduler = new WordPressJobScheduler(
            [
                new FrameworkHeartbeatJob(),
                $mailboxPollingJob,
                $confirmationPreviewJob,
                new ApprovalNoticeJob(
                    $database, $approvalRecipients, $this->actionTokenService,
                    $this->mailQueue, $mailQueueRepository, $clock,
                    $approvers,
                    self::approvalDigestHour()
                ),
                // The settings reader is resolved on first use, not here: this block
                // runs during plugins_loaded, before WordPress options are safe to read,
                // and no constructor may call get_option().
                new ApprovalReminderJob(
                    $database,
                    $approvalRecipients,
                    $this->actionTokenService,
                    $this->mailQueue,
                    $mailQueueRepository,
                    new FollowUpRepository($database, $clock),
                    $clock,
                    static fn (): ApprovalReminderSettings => (new ApprovalReminderOptionReader())->read()
                ),
                // Same digest hour as the approval notice, deliberately: two jobs
                // reading one setting would let them disagree about when "daily"
                // starts, and a change notice is an approval notice about an event
                // that is already live.
                new ChangeNoticeJob(
                    $database, $approvalRecipients, $this->actionTokenService,
                    $this->mailQueue, $mailQueueRepository, $clock,
                    self::approvalDigestHour()
                ),
                $inboundMessageProcessingJob,
                $retentionCleanupJob,
                new OccurrenceExpansionJob($occurrenceMaintenance, $clock, $timezone),
                $mailQueueSenderJob,
            ],
            $jobRunner,
            $stateStore,
            $clock
        );
        $this->scheduledJobsPage = new ScheduledJobsPage(
            $this->jobScheduler,
            $jobRunner,
            $stateStore
        );
        $this->healthAlerts = new HealthAlerts(
            $this->jobScheduler,
            $stateStore,
            $sources,
            $this->mailQueue,
            $clock
        );
        $this->adminHelp = new WordPressHelp();
        $this->healthPage = new HealthPage(
            $this->jobScheduler,
            $jobRunner,
            $stateStore,
            $sources,
            $mailboxes,
            $inboundMessages,
            $this->mailQueue,
            $deaneries,
            $this->healthAlerts,
            $clock,
            fn (): ReviewQueueRepository => $this->reviewQueue()
        );
    }

    /**
     * The review queue repository is only built once WordPress has loaded, so it
     * is resolved on first use rather than in the constructor.
     */
    private function reviewQueue(): ReviewQueueRepository
    {
        if ($this->reviewQueue === null) {
            throw new \LogicException('The review queue was not initialized.');
        }

        return $this->reviewQueue;
    }

    public static function boot(string $pluginFile): void
    {
        if (self::$instance instanceof self) {
            return;
        }

        self::$instance = new self($pluginFile);
        self::$instance->registerHooks();
    }

    public static function mailer(): MailerInterface
    {
        if (! self::$instance instanceof self) {
            throw new \RuntimeException('The Parish Intake plugin has not been booted.');
        }

        return self::$instance->mailQueue;
    }

    public static function actionTokenService(): ActionTokenService
    {
        if (! self::$instance instanceof self) {
            throw new \RuntimeException('The Parish Intake plugin has not been booted.');
        }

        return self::$instance->actionTokenService;
    }

    public static function candidatePublisher(): CandidatePublisher
    {
        if (! self::$instance instanceof self) {
            throw new \RuntimeException('The Parish Intake plugin has not been booted.');
        }

        return self::$instance->candidatePublisher;
    }

    public static function actionTokenHandlers(): ActionTokenHandlerRegistry
    {
        if (! self::$instance instanceof self) {
            throw new \RuntimeException('The Parish Intake plugin has not been booted.');
        }

        return self::$instance->actionTokenHandlers;
    }

    public static function mailQueueStats(): MailQueueStats
    {
        if (! self::$instance instanceof self) {
            throw new \RuntimeException('The Parish Intake plugin has not been booted.');
        }

        return self::$instance->mailQueue->stats();
    }

    public static function publicEventPage(): PublicEventPage
    {
        if (! self::$instance instanceof self) {
            throw new \RuntimeException('The Parish Intake plugin has not been booted.');
        }

        return self::$instance->publicEventPage;
    }

    public static function auditLog(): AuditLogRepository
    {
        if (! self::$instance instanceof self) {
            throw new \RuntimeException('The Parish Intake plugin has not been booted.');
        }

        return self::$instance->auditLog;
    }

    public static function activate(): void
    {
        if (! function_exists('add_option')) {
            return;
        }

        add_option('adct_parish_intake_ai_enabled', '0');
        add_option('adct_parish_intake_ai_provider', 'none');
        add_option('adct_parish_intake_openrouter_model', OpenAiCompatibleProvider::FREE_MODEL);
        add_option('adct_parish_intake_ai_base_url', OpenAiCompatibleProvider::DEFAULT_URL);
        add_option('adct_parish_intake_ai_threshold', '0.55');
        // Poster OCR sends an image off-site, so it ships off: an
        // administrator has to opt in on the settings screen before a single
        // poster leaves this server.
        add_option(OcrSettings::ENABLED_OPTION, '0');
        add_option(
            OcrSettings::DAILY_CALL_LIMIT_OPTION,
            (string) OcrSettings::DEFAULT_DAILY_CALL_LIMIT
        );
        add_option(
            'adct_parish_intake_confidence_threshold',
            (string) ReviewQueuePolicy::DEFAULT_CONFIDENCE_THRESHOLD
        );
        add_option(
            'adct_parish_intake_field_confidence_threshold',
            (string) ConfidenceScoringStage::DEFAULT_FIELD_THRESHOLD
        );
        add_option('adct_parish_intake_section_keywords', SectionSkipper::defaultKeywordLists());
        add_option(
            WordPressTestModeSettings::TEST_MODE_OPTION,
            WordPressTestModeSettings::MODE_DISABLED,
            '',
            false
        );
        add_option(WordPressTestModeSettings::ALLOWLIST_OPTION, [], '', false);

        self::createRoleInstaller()->install();
        (new Schema())->install();
        self::createMigrationRunner()->run();
        (new EventPostType())->activate();
    }

    public static function deactivate(): void
    {
        if (! (self::$instance instanceof self)) {
            return;
        }

        try {
            self::$instance->jobScheduler->clearScheduledEvents();
        } catch (\Throwable $failure) {
            error_log(
                '[ADCT Parish Intake] Could not clear scheduled job hooks during deactivation ('
                . get_class($failure) . ').'
            );
        }
    }

    public function maybeRunDatabaseMigrations(): void
    {
        self::createMigrationRunner()->run();
    }

    public function maybeUpgradeRoles(): void
    {
        self::createRoleInstaller()->upgradeIfNeeded();
    }

    public function restrictWpAdminForPortalRoles(): void
    {
        if (
            ! is_admin()
            || (defined('DOING_AJAX') && DOING_AJAX)
            || $this->isAdminPostRequest()
        ) {
            return;
        }

        $user = wp_get_current_user();

        if (
            ! ($user instanceof \WP_User)
            || (int) $user->ID === 0
            || ! $this->hasPortalOnlyRole($user)
            || current_user_can('edit_posts')
        ) {
            return;
        }

        if (current_user_can(Capabilities::APPROVE_DEANERY)
            && isset($_GET['page']) && is_string($_GET['page'])
            && wp_unslash($_GET['page']) === ReviewQueuePage::PAGE_SLUG) {
            return;
        }

        wp_safe_redirect(home_url('/'));
        exit;
    }

    public function hideAdminBarForPortalRoles(bool $show): bool
    {
        $user = wp_get_current_user();

        if (
            ! ($user instanceof \WP_User)
            || ! $this->hasPortalOnlyRole($user)
            || current_user_can('edit_posts')
        ) {
            return $show;
        }

        return false;
    }

    public function renderMigrationNotice(): void
    {
        if (! function_exists('current_user_can') || ! current_user_can(Capabilities::MANAGE_SETTINGS)) {
            return;
        }

        $message = get_option('adct_pi_db_migration_error', '');

        if (! is_string($message) || $message === '') {
            return;
        }
        ?>
        <div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
        <?php
    }

    private function registerHooks(): void
    {
        if (! function_exists('add_action')) {
            return;
        }
        if ($this->reviewQueuePage === null) {
            throw new \LogicException('The review queue was not initialized.');
        }

        $this->reviewerNotificationPreference->registerHooks();
        add_filter('query_vars', [$this->actionTokenEndpoint(), 'registerQueryVars']);
        add_action('template_redirect', [$this->actionTokenEndpoint(), 'handleRequest'], 0);
        add_action('template_redirect', [$this->publicIcsFeed, 'handleRequest'], 1);
        add_action('init', [$this->publicEventPage, 'register'], 12);
        add_action('init', [$this->eventPostType, 'register'], 5);
        $typeKeywords = new EventTypeKeywords();
        add_action(EventPostType::TAXONOMY . '_add_form_fields', [$typeKeywords, 'renderAddField']);
        add_action(EventPostType::TAXONOMY . '_edit_form_fields', [$typeKeywords, 'renderEditField']);
        add_action('created_' . EventPostType::TAXONOMY, [$typeKeywords, 'save']);
        add_action('edited_' . EventPostType::TAXONOMY, [$typeKeywords, 'save']);
        add_action('init', [$this->publicEventListing, 'register'], 10);
        add_action('rest_api_init', [$this->publicEventListing, 'registerRestRoute']);
        add_action('wp_enqueue_scripts', [$this->publicEventListing, 'styles']);
        add_action('save_post_adct_event', [$this->publicEventListing, 'invalidate'], 30);
        add_action('rest_after_insert_adct_event', [$this->publicEventListing, 'invalidateTerms'], 30);
        add_action('transition_post_status', [$this->publicEventListing, 'invalidateOnStatus'], 30, 3);
        add_action('before_delete_post', [$this->publicEventListing, 'invalidate'], 30);
        add_action('added_post_meta', [$this->publicEventListing, 'invalidateMeta'], 10, 2);
        add_action('updated_post_meta', [$this->publicEventListing, 'invalidateMeta'], 10, 2);
        add_action('deleted_post_meta', [$this->publicEventListing, 'invalidateMeta'], 10, 2);
        add_action('set_object_terms', [$this->publicEventListing, 'invalidateTerms'], 10, 1);
        add_action('add_meta_boxes_adct_event', [$this->eventEditor, 'registerMetaBox']);
        add_action(
            'add_meta_boxes_' . EventPostType::POST_TYPE,
            [$this->changeHistoryBox, 'register']
        );
        // #172: two separate routes rather than one with a mode, because a remove
        // takes a file off the public site and must never be reachable by pressing
        // Enter in a filename field. Both ask the box to check the capability
        // before the nonce.
        add_action(
            'admin_post_' . EventSourceMaterialEditor::ADD_ACTION,
            [$this->eventSourceMaterial, 'handleAdd']
        );
        add_action(
            'admin_post_' . EventSourceMaterialEditor::REMOVE_ACTION,
            [$this->eventSourceMaterial, 'handleRemove']
        );
        add_action('save_post_adct_event', [$this->eventEditor, 'handleSavePost'], 10, 3);
        add_action('save_post_adct_event', [$this->eventOccurrenceHooks, 'handleSavePost'], 20, 3);
        add_action(
            'rest_after_insert_adct_event',
            [$this->eventOccurrenceHooks, 'handleRestAfterInsert'],
            10,
            3
        );
        add_action(
            'transition_post_status',
            [$this->eventOccurrenceHooks, 'handleStatusTransition'],
            10,
            3
        );
        add_action(
            'before_delete_post',
            [$this->eventOccurrenceHooks, 'handleBeforeDeletePost'],
            10,
            2
        );
        add_filter(
            'rest_request_before_callbacks',
            [$this->eventOccurrenceHooks, 'beginRestWrite'],
            10,
            3
        );
        add_filter(
            'rest_request_after_callbacks',
            [$this->eventOccurrenceHooks, 'endRestWrite'],
            10,
            3
        );
        add_filter(
            'rest_post_dispatch',
            [$this->eventOccurrenceHooks, 'filterRestResponse'],
            10,
            3
        );
        add_action('admin_notices', [$this->eventPostType, 'renderSetupNotice']);
        add_action('admin_notices', [$this->eventEditor, 'renderValidationNotice']);
        add_action('admin_notices', [$this->eventOccurrenceHooks, 'renderFailureNotice']);
        add_filter('manage_adct_event_posts_columns', [$this->eventEditor, 'filterColumns']);
        add_action('manage_adct_event_posts_custom_column', [$this->eventEditor, 'renderColumn'], 10, 2);
        add_action('restrict_manage_posts', [$this->eventEditor, 'renderListFilters']);
        add_action('pre_get_posts', [$this->eventEditor, 'filterListQuery']);
        add_filter('posts_where', [$this->eventEditor, 'filterNextDateWhere'], 10, 2);
        add_filter('rest_pre_insert_adct_event', [$this->eventEditor, 'validateRestRequest'], 10, 2);
        add_action('rest_after_insert_adct_event', [$this->eventEditor, 'markRestFeaturedChoice'], 10, 2);
        add_filter('show_admin_bar', [$this, 'hideAdminBarForPortalRoles']);
        // #72: deans reach the queue on the front end (ADR 0007 long session),
        // so the shortcode/block, its POST handlers and the cookie lifetime are
        // all registered here rather than behind an admin menu.
        if ($this->frontEndApprovalQueue !== null) {
            add_action('init', [$this->frontEndApprovalQueue, 'register'], 11);
            add_action(
                'admin_post_' . FrontEndApprovalQueue::BULK_ACTION,
                [$this->frontEndApprovalQueue, 'handleBulk']
            );
            add_action(
                'admin_post_' . FrontEndApprovalQueue::SAVE_ACTION,
                [$this->frontEndApprovalQueue, 'handleSave']
            );
            add_action(
                'admin_post_' . FrontEndApprovalQueue::REVERT_ACTION,
                [$this->frontEndApprovalQueue, 'handleRevertRequest']
            );
        }
        if ($this->magicLinkLoginRequestPage !== null) {
            add_action('init', [$this->magicLinkLoginRequestPage, 'register'], 11);
            add_action(
                'admin_post_' . MagicLinkLoginRequestPage::ACTION,
                [$this->magicLinkLoginRequestPage, 'handleRequest']
            );
        }
        add_filter('auth_cookie_expiration', [$this, 'authCookieExpiration'], 10, 3);
        add_action('admin_menu', [$this->parserPage, 'registerMenu']);
        add_action('admin_menu', [$this->deaneriesPage, 'registerMenu']);
        add_action('admin_menu', [$this->parishesPage, 'registerMenu']);
        add_action('admin_menu', [$this->sendersPage, 'registerMenu']);
        add_action('admin_menu', [$this->sourcesPage, 'registerMenu']);
        add_action('admin_menu', [$this->mailboxesPage, 'registerMenu']);
        add_action('admin_menu', [$this->inboundMessagesPage, 'registerMenu']);
        add_action('admin_menu', [$this->reviewQueuePage, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this->reviewQueuePage, 'enqueueDetailAssets']);
        add_action('admin_enqueue_scripts', [$this->reviewQueuePage, 'enqueueDetailOcrAssets']);
        add_action('admin_menu', [$this->outboundMailPage, 'registerMenu']);
        add_action('admin_menu', [$this->scheduledJobsPage, 'registerMenu']);
        add_action('admin_menu', [$this->healthPage, 'registerMenu']);
        $this->adminHelp->register();
        add_action('admin_menu', [$this->auditLogPage, 'registerMenu']);
        add_action('admin_post_adct_pi_health_check_now', [$this->healthPage, 'handleCheckNow']);
        add_action('init', [$this, 'checkHealthAlerts'], 20);
        add_action('admin_init', [$this, 'maybeUpgradeRoles'], 1);
        add_action('admin_init', [$this, 'restrictWpAdminForPortalRoles'], 2);
        add_action('admin_init', [$this, 'maybeRunDatabaseMigrations'], 5);
        add_action('admin_init', [$this->parserPage, 'maybeHandleSettings']);
        add_action('admin_init', [$this->outboundMailPage, 'maybeHandleSettings']);
        add_action('admin_post_adct_pi_save_parish', [$this->parishesPage, 'handleSaveParish']);
        add_action('admin_post_adct_pi_bulk_assign_parishes', [$this->parishesPage, 'handleBulkAssign']);
        add_action('admin_post_adct_pi_venue_action', [$this->parishesPage, 'handleVenueAction']);
        add_action('admin_post_adct_pi_venue_backfill', [$this->parishesPage, 'handleVenueBackfill']);
        add_action('admin_post_adct_pi_parish_contact', [$this->parishesPage, 'handleContactAction']);
        add_action('admin_post_adct_pi_save_source', [$this->sourcesPage, 'handleSaveSource']);
        add_action('admin_post_adct_pi_save_mailbox', [$this->mailboxesPage, 'handleSaveMailbox']);
        add_action(
            'admin_post_adct_pi_reprocess_inbound_messages',
            [$this->inboundMessagesPage, 'handleReprocess']
        );
        add_action('admin_post_adct_pi_review_bulk', [$this->reviewQueuePage, 'handleBulk']);
        add_action('admin_post_adct_pi_candidate_save', [$this->reviewQueuePage, 'handleSave']);
        add_action(
            'admin_post_' . ReviewQueuePage::PROMOTE_SOURCE_ACTION,
            [$this->reviewQueuePage, 'handlePromoteSourceMaterial']
        );
        add_action(
            'admin_post_adct_pi_candidate_raw_message',
            [$this->reviewQueuePage, 'handleRawMessage']
        );
        add_action(
            'admin_post_adct_pi_candidate_attachment',
            [$this->reviewQueuePage, 'handleAttachment']
        );
        add_action(
            'admin_post_' . ReviewQueuePage::CREATE_MANUAL_ACTION,
            [$this->reviewQueuePage, 'handleCreateManual']
        );
        add_action(
            'admin_post_' . ReviewQueuePage::RESOLVE_MATCH_ACTION,
            [$this->reviewQueuePage, 'handleResolveMatch']
        );
                    add_action(
                        'admin_post_' . ReviewQueuePage::RESEND_CONFIRMATION_ACTION,
                        [$this->reviewQueuePage, 'handleResendConfirmation']
                    );
        add_action('admin_post_adct_pi_test_mailbox', [$this->mailboxesPage, 'handleTestConnection']);
        add_action(
            'admin_post_adct_pi_create_mailbox_processed_folder',
            [$this->mailboxesPage, 'handleCreateProcessedFolder']
        );
        add_action('admin_post_adct_pi_save_deanery', [$this->deaneriesPage, 'handleSaveDeanery']);
        add_action('admin_post_adct_pi_deactivate_deanery', [$this->deaneriesPage, 'handleDeactivateDeanery']);
        add_action('admin_post_adct_pi_deanery_approver', [$this->deaneriesPage, 'handleApproverAction']);
        add_action('admin_post_adct_pi_directory_import_preview', [$this->parishesPage, 'handleImportPreview']);
        add_action('admin_post_adct_pi_directory_import_confirm', [$this->parishesPage, 'handleImportConfirm']);
        add_action('admin_post_adct_pi_directory_export', [$this->parishesPage, 'handleExport']);
        add_action(
            'admin_post_' . AttachmentImageEndpoint::ACTION,
            [$this->attachmentImageEndpoint, 'handleRequest']
        );
        add_action(
            'admin_enqueue_scripts',
            [$this->parserPage, 'enqueueOcrAssets']
        );
        add_action('admin_post_adct_pi_sender_action', [$this->sendersPage, 'handleAction']);
        add_action('admin_post_adct_pi_run_job', [$this->scheduledJobsPage, 'handleRunNow']);
        add_action('admin_notices', [$this, 'renderMigrationNotice']);
        add_action('admin_notices', [$this->outboundMailPage, 'renderAdminNotice']);
        add_action('admin_notices', [$this->scheduledJobsPage, 'renderResultNotice']);
        $this->jobScheduler->registerHooks();
    }

    public function checkHealthAlerts(): void
    {
        if (get_transient('adct_pi_health_scan') !== false) {
            return;
        }
        try {
            $this->healthAlerts->check();
            set_transient('adct_pi_health_scan', '1', 300);
        } catch (\Throwable $failure) {
            error_log('[ADCT Parish Intake] Health alert check failed (' . get_class($failure) . '): '
                . $failure->getMessage());
        }
    }

    private static function createRoleInstaller(): VersionedRoleInstaller
    {
        return new VersionedRoleInstaller(
            new RoleInstaller(new WordPressRoleCapabilityStore()),
            new WordPressRoleVersionStore()
        );
    }

    /**
     * Read a confidence threshold from an option, falling back to the documented default.
     *
     * A stored value that is missing or out of range must not silently become 0 or 1, which would
     * either send everything to review or let everything publish unreviewed.
     */
    private function confidenceOption(string $option, float $default): float
    {
        if (! function_exists('get_option')) {
            return $default;
        }

        $value = get_option($option, (string) $default);

        if (! is_numeric($value) || (float) $value < 0.0 || (float) $value > 1.0) {
            error_log(sprintf(
                '[ADCT Parish Intake] Invalid %s; using %s.',
                $option,
                (string) $default
            ));

            return $default;
        }

        return (float) $value;
    }

    private function isAdminPostRequest(): bool
    {
        return isset($_SERVER['PHP_SELF'])
            && is_string($_SERVER['PHP_SELF'])
            && basename($_SERVER['PHP_SELF']) === 'admin-post.php';
    }

    private function hasPortalOnlyRole(\WP_User $user): bool
    {
        return in_array('parish_contact', $user->roles, true)
            || in_array('deanery_approver', $user->roles, true);
    }

    /**
     * ADR 0007: a magic-link sign-in gets a long session, so a dean is not
     * asked for a new link every time they open the queue.
     *
     * Only the two portal roles get the long cookie — an archdiocese reviewer
     * keeps WordPress's own expiry, so widening this filter cannot quietly
     * extend a staff session. The lifetime is a `wp-config.php` constant
     * (`ADCT_PI_AUTH_COOKIE_LIFETIME_DAYS`) because it is a hosting decision,
     * and an absurd value is ignored in favour of the documented default.
     */
    public function authCookieExpiration(
        int $expiration,
        int $userId,
        bool $remember = false
    ): int {
        unset($remember);

        $days = defined('ADCT_PI_AUTH_COOKIE_LIFETIME_DAYS')
            ? constant('ADCT_PI_AUTH_COOKIE_LIFETIME_DAYS')
            : self::DEFAULT_AUTH_COOKIE_LIFETIME_DAYS;

        if (! is_numeric($days) || (int) $days < 1) {
            error_log(
                '[ADCT Parish Intake] Ignoring ADCT_PI_AUTH_COOKIE_LIFETIME_DAYS; using the default of '
                . self::DEFAULT_AUTH_COOKIE_LIFETIME_DAYS . ' days.'
            );
            $days = self::DEFAULT_AUTH_COOKIE_LIFETIME_DAYS;
        }

        $user = get_userdata($userId);
        if (! $user instanceof \WP_User || ! $this->hasPortalOnlyRole($user)) {
            return $expiration;
        }

        return (int) $days * DAY_IN_SECONDS;
    }

    private static function createMigrationRunner(): MigrationRunner
    {
        $database = new WordPressDatabaseConnection();

        return new MigrationRunner(
            [
                new CreateSchemaMigration(new DbDeltaSchemaInstaller($database)),
                new VenueSchemaMigration(new DbDeltaSchemaInstaller($database)),
                new MailboxSchemaMigration(new DbDeltaSchemaInstaller($database)),
                new OccurrenceParishNullableMigration($database),
                new MailQueueGroupKeyMigration($database),
                new ActionTokenRateLimitSchemaMigration(new DbDeltaSchemaInstaller($database)),
                new ConfirmationEmailPreviewSchemaMigration($database),
                new ApprovalNoticesMigration(new DbDeltaSchemaInstaller($database)),
                new ProcessedMailboxOwnershipSchemaMigration(new DbDeltaSchemaInstaller($database)),
                new SenderSuggestionMigration(new DbDeltaSchemaInstaller($database)),
                new FollowUpParishNullableMigration($database),
            ],
            new WordPressMigrationVersionStore(),
            new WordPressMigrationLogger()
        );
    }

    private static function mailQueueConfiguration(): MailQueueConfiguration
    {
        if (! defined('ADCT_PI_MAIL_HOURLY_CAP')) {
            return new MailQueueConfiguration();
        }

        $configuredCap = constant('ADCT_PI_MAIL_HOURLY_CAP');

        if (
            ! is_int($configuredCap)
            && (
                ! is_string($configuredCap)
                || preg_match('/\A\d+\z/D', $configuredCap) !== 1
            )
        ) {
            self::logInvalidMailQueueCap();

            return new MailQueueConfiguration();
        }

        try {
            return new MailQueueConfiguration((int) $configuredCap);
        } catch (\InvalidArgumentException) {
            self::logInvalidMailQueueCap();

            return new MailQueueConfiguration();
        }
    }

    /**
     * Local hour (Africa/Johannesburg) from which daily approval digests may
     * be sent. Earlier arrivals wait for a later cron run on the same day.
     */
    private static function approvalDigestHour(): int
    {
        if (! defined('ADCT_PI_APPROVAL_DIGEST_HOUR')) {
            return ApprovalNoticeJob::DEFAULT_DIGEST_HOUR;
        }

        $configuredHour = constant('ADCT_PI_APPROVAL_DIGEST_HOUR');

        if (
            ! is_int($configuredHour)
            && (
                ! is_string($configuredHour)
                || preg_match('/\A\d+\z/D', $configuredHour) !== 1
            )
        ) {
            self::logInvalidDigestHour();

            return ApprovalNoticeJob::DEFAULT_DIGEST_HOUR;
        }

        $hour = (int) $configuredHour;

        if ($hour < 0 || $hour > 23) {
            self::logInvalidDigestHour();

            return ApprovalNoticeJob::DEFAULT_DIGEST_HOUR;
        }

        return $hour;
    }

    private static function logInvalidDigestHour(): void
    {
        error_log(
            '[ADCT Parish Intake] ADCT_PI_APPROVAL_DIGEST_HOUR must be an integer from 0 to 23;'
            . ' using default ' . ApprovalNoticeJob::DEFAULT_DIGEST_HOUR . '.'
        );
    }

    private static function logInvalidMailQueueCap(): void
    {
        error_log(
            '[ADCT Parish Intake] ADCT_PI_MAIL_HOURLY_CAP must be an integer from 1 to 500; using default 100.'
        );
    }

    public function makeAiProvider(): AiProviderInterface
    {
        if (! function_exists('get_option')) {
            return new NullAiProvider();
        }

        return $this->parserPage->buildAiProvider();
    }
}
