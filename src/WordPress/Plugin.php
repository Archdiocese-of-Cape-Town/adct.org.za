<?php

namespace ADCT\ParishIntake\WordPress;

use ADCT\ParishIntake\Core\Approval\ApprovalRouteResolver;
use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Auth\RoleInstaller;
use ADCT\ParishIntake\Core\Auth\VersionedRoleInstaller;
use ADCT\ParishIntake\Core\Database\CreateSchemaMigration;
use ADCT\ParishIntake\Core\Database\MailboxSchemaMigration;
use ADCT\ParishIntake\Core\Database\MigrationRunner;
use ADCT\ParishIntake\Core\Database\VenueSchemaMigration;
use ADCT\ParishIntake\Core\Events\EventValidator;
use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use ADCT\ParishIntake\Core\Events\RRuleValidator;
use ADCT\ParishIntake\Core\Directory\DeaneryCsvImporter;
use ADCT\ParishIntake\Core\Directory\ContactService;
use ADCT\ParishIntake\Core\Directory\ParishCsvImporter;
use ADCT\ParishIntake\Core\Directory\VenueAdministrationService;
use ADCT\ParishIntake\Core\Directory\VenueDirectoryImporter;
use ADCT\ParishIntake\Core\Jobs\FrameworkHeartbeatJob;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Ingestion\Imap\ImapMailbox;
use ADCT\ParishIntake\Core\Ingestion\Imap\MailboxConnectionConfig;
use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ingestion\AuthenticationResultsParser;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettings;
use ADCT\ParishIntake\Core\Ingestion\MailboxConnectionTestService;
use ADCT\ParishIntake\Core\Ingestion\MailboxSettingsValidator;
use ADCT\ParishIntake\Core\Ingestion\MessageContentHasher;
use ADCT\ParishIntake\Core\Ingestion\RawMessageInspector;
use ADCT\ParishIntake\Core\Jobs\MailboxPollingJob;
use ADCT\ParishIntake\Core\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Parsing\SectionSkipper;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\MailboxInterface;
use ADCT\ParishIntake\Core\Security\SecretRegistry;
use ADCT\ParishIntake\Core\Sources\SourceHealthRecorder;
use ADCT\ParishIntake\Core\Sources\SourceRegistryService;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Admin\ScheduledJobsPage;
use ADCT\ParishIntake\WordPress\Admin\DeaneriesPage;
use ADCT\ParishIntake\WordPress\Admin\MailboxesPage;
use ADCT\ParishIntake\WordPress\Admin\ParserPage;
use ADCT\ParishIntake\WordPress\Admin\ParishesPage;
use ADCT\ParishIntake\WordPress\Admin\SendersPage;
use ADCT\ParishIntake\WordPress\Admin\SourcesPage;
use ADCT\ParishIntake\WordPress\Ai\OpenRouterProvider;
use ADCT\ParishIntake\WordPress\Auth\WordPressRoleCapabilityStore;
use ADCT\ParishIntake\WordPress\Auth\WordPressRoleVersionStore;
use ADCT\ParishIntake\WordPress\Database\DbDeltaSchemaInstaller;
use ADCT\ParishIntake\WordPress\Database\Repository\ApprovalRouteRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishContactRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ParishRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\MailboxRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\SourceRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\VenueRepository;
use ADCT\ParishIntake\WordPress\Database\Schema;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Database\WordPressInboundMessageStore;
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
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobLock;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobScheduler;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobStateStore;
use ADCT\ParishIntake\WordPress\Events\EventEditor;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use ADCT\ParishIntake\WordPress\Security\WordPressSecretResolver;
use DateTimeZone;

final class Plugin
{
    private static ?self $instance = null;

    private string $pluginFile;
    private Schema $schema;
    private PipelineFactory $pipelineFactory;
    private ParserPage $parserPage;
    private HttpClientInterface $httpClient;
    private WordPressJobScheduler $jobScheduler;
    private ScheduledJobsPage $scheduledJobsPage;
    private DeaneriesPage $deaneriesPage;
    private ParishesPage $parishesPage;
    private SendersPage $sendersPage;
    private SourcesPage $sourcesPage;
    private EventPostType $eventPostType;
    private EventEditor $eventEditor;
    private MailboxesPage $mailboxesPage;

    private function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->schema = new Schema();
        $this->httpClient = new WordPressHttpClient();
        $clock = new SystemClock();
        $database = new WordPressDatabaseConnection();
        $directoryVersions = new WordPressDirectoryVersionStore($database);
        $parishes = new ParishRepository($database, $directoryVersions);
        $deaneries = new DeaneryRepository($database);
        $approvers = new DeaneryApproverRepository($database);
        $contacts = new ParishContactRepository($database, $directoryVersions);
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
            $attachmentRepository
        );
        $secrets = new WordPressSecretResolver();
        $directorySnapshots = new CachedDirectorySnapshotProvider(
            $directoryVersions,
            new WordPressDirectorySnapshotCache(),
            new WordPressDirectorySnapshotLoader($parishes, $venues, $contacts)
        );
        $this->pipelineFactory = new PipelineFactory($clock, $directorySnapshots);
        $this->parserPage = new ParserPage(
            $this->schema,
            $this->pipelineFactory,
            new StaticReportGenerator($this->schema),
            $this->httpClient
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
        $venueAdministrationService = new VenueAdministrationService($venues, $clock);
        $approvalRouteResolver = new ApprovalRouteResolver(new ApprovalRouteRepository($database));
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
            $this->sourcesPage
        );
        $this->sendersPage = new SendersPage($contacts, $contactService, $parishes);
        $this->eventPostType = new EventPostType();
        $this->eventEditor = new EventEditor(
            $parishes,
            $venues,
            new EventValidator($timezone, $rruleValidator),
            new RRulePresetMapper($rruleValidator),
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
        $mailboxPollingJob = new MailboxPollingJob(
            $mailboxes,
            $sources,
            $inboundMessageStore,
            new ProtectedInboundMailStorage(),
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
        $this->jobScheduler = new WordPressJobScheduler(
            [new FrameworkHeartbeatJob(), $mailboxPollingJob],
            $jobRunner,
            $stateStore,
            $clock
        );
        $this->scheduledJobsPage = new ScheduledJobsPage(
            $this->jobScheduler,
            $jobRunner,
            $stateStore
        );
    }

    public static function boot(string $pluginFile): void
    {
        if (self::$instance instanceof self) {
            return;
        }

        self::$instance = new self($pluginFile);
        self::$instance->registerHooks();
    }

    public static function activate(): void
    {
        if (! function_exists('add_option')) {
            return;
        }

        add_option('adct_parish_intake_ai_enabled', '0');
        add_option('adct_parish_intake_ai_provider', 'none');
        add_option('adct_parish_intake_openrouter_model', 'openrouter/auto');
        add_option('adct_parish_intake_ai_threshold', '0.55');
        add_option('adct_parish_intake_section_keywords', SectionSkipper::defaultKeywordLists());

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

        add_action('init', [$this->eventPostType, 'register'], 5);
        add_action('add_meta_boxes_adct_event', [$this->eventEditor, 'registerMetaBox']);
        add_action('save_post_adct_event', [$this->eventEditor, 'handleSavePost'], 10, 3);
        add_action('admin_notices', [$this->eventPostType, 'renderSetupNotice']);
        add_action('admin_notices', [$this->eventEditor, 'renderValidationNotice']);
        add_filter('manage_adct_event_posts_columns', [$this->eventEditor, 'filterColumns']);
        add_action('manage_adct_event_posts_custom_column', [$this->eventEditor, 'renderColumn'], 10, 2);
        add_filter('rest_pre_insert_adct_event', [$this->eventEditor, 'validateRestRequest'], 10, 2);
        add_filter('show_admin_bar', [$this, 'hideAdminBarForPortalRoles']);
        add_action('admin_menu', [$this->parserPage, 'registerMenu']);
        add_action('admin_menu', [$this->deaneriesPage, 'registerMenu']);
        add_action('admin_menu', [$this->parishesPage, 'registerMenu']);
        add_action('admin_menu', [$this->sendersPage, 'registerMenu']);
        add_action('admin_menu', [$this->sourcesPage, 'registerMenu']);
        add_action('admin_menu', [$this->mailboxesPage, 'registerMenu']);
        add_action('admin_menu', [$this->scheduledJobsPage, 'registerMenu']);
        add_action('admin_init', [$this, 'maybeUpgradeRoles'], 1);
        add_action('admin_init', [$this, 'restrictWpAdminForPortalRoles'], 2);
        add_action('admin_init', [$this, 'maybeRunDatabaseMigrations'], 5);
        add_action('admin_init', [$this->parserPage, 'maybeHandleSettings']);
        add_action('admin_post_adct_pi_save_parish', [$this->parishesPage, 'handleSaveParish']);
        add_action('admin_post_adct_pi_bulk_assign_parishes', [$this->parishesPage, 'handleBulkAssign']);
        add_action('admin_post_adct_pi_venue_action', [$this->parishesPage, 'handleVenueAction']);
        add_action('admin_post_adct_pi_venue_backfill', [$this->parishesPage, 'handleVenueBackfill']);
        add_action('admin_post_adct_pi_parish_contact', [$this->parishesPage, 'handleContactAction']);
        add_action('admin_post_adct_pi_save_source', [$this->sourcesPage, 'handleSaveSource']);
        add_action('admin_post_adct_pi_save_mailbox', [$this->mailboxesPage, 'handleSaveMailbox']);
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
        add_action('admin_post_adct_pi_sender_action', [$this->sendersPage, 'handleAction']);
        add_action('admin_post_adct_pi_run_job', [$this->scheduledJobsPage, 'handleRunNow']);
        add_action('admin_notices', [$this, 'renderMigrationNotice']);
        add_action('admin_notices', [$this->scheduledJobsPage, 'renderResultNotice']);
        $this->jobScheduler->registerHooks();
    }

    private static function createRoleInstaller(): VersionedRoleInstaller
    {
        return new VersionedRoleInstaller(
            new RoleInstaller(new WordPressRoleCapabilityStore()),
            new WordPressRoleVersionStore()
        );
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

    private static function createMigrationRunner(): MigrationRunner
    {
        $database = new WordPressDatabaseConnection();

        return new MigrationRunner(
            [
                new CreateSchemaMigration(new DbDeltaSchemaInstaller($database)),
                new VenueSchemaMigration(new DbDeltaSchemaInstaller($database)),
                new MailboxSchemaMigration(new DbDeltaSchemaInstaller($database)),
            ],
            new WordPressMigrationVersionStore(),
            new WordPressMigrationLogger()
        );
    }

    public function makeAiProvider(): AiProviderInterface
    {
        if (! function_exists('get_option')) {
            return new NullAiProvider();
        }

        $enabled = get_option('adct_parish_intake_ai_enabled', '0') === '1';
        $provider = get_option('adct_parish_intake_ai_provider', 'none');

        if (! $enabled || $provider !== 'openrouter') {
            return new NullAiProvider();
        }

        $apiKey = (new WordPressSecretResolver())->resolve(SecretRegistry::AI_API_KEY);
        $model = trim((string) get_option('adct_parish_intake_openrouter_model', 'openrouter/auto'));

        if ($apiKey === '') {
            return new NullAiProvider();
        }

        return new OpenRouterProvider($apiKey, $model, $this->httpClient);
    }
}
