<?php

namespace ADCT\ParishIntake\WordPress;

use ADCT\ParishIntake\Core\Database\CreateSchemaMigration;
use ADCT\ParishIntake\Core\Database\MigrationRunner;
use ADCT\ParishIntake\Core\Jobs\FrameworkHeartbeatJob;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\AiProviderInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Admin\ScheduledJobsPage;
use ADCT\ParishIntake\WordPress\Admin\ParserPage;
use ADCT\ParishIntake\WordPress\Ai\OpenRouterProvider;
use ADCT\ParishIntake\WordPress\Database\DbDeltaSchemaInstaller;
use ADCT\ParishIntake\WordPress\Database\Schema;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Database\WordPressMigrationLogger;
use ADCT\ParishIntake\WordPress\Database\WordPressMigrationVersionStore;
use ADCT\ParishIntake\WordPress\Export\StaticReportGenerator;
use ADCT\ParishIntake\WordPress\Http\WordPressHttpClient;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobLock;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobScheduler;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobStateStore;

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

    private function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->schema = new Schema();
        $this->pipelineFactory = new PipelineFactory();
        $this->httpClient = new WordPressHttpClient();
        $this->parserPage = new ParserPage(
            $this->schema,
            $this->pipelineFactory,
            new StaticReportGenerator($this->schema),
            $this->httpClient
        );

        $clock = new SystemClock();
        $stateStore = new WordPressJobStateStore();
        $jobRunner = new JobRunner(
            new WordPressJobLock(),
            $stateStore,
            $clock,
            JobRunner::DEFAULT_TIME_BUDGET_SECONDS,
            JobRunner::DEFAULT_ITEM_BUDGET,
            JobRunner::DEFAULT_LOCK_TTL_SECONDS
        );
        $this->jobScheduler = new WordPressJobScheduler(
            [new FrameworkHeartbeatJob()],
            $jobRunner,
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

        (new Schema())->install();
        self::createMigrationRunner()->run();
    }

    public static function deactivate(): void
    {
        if (self::$instance instanceof self) {
            self::$instance->jobScheduler->clearScheduledEvents();
        }
    }

    public function maybeRunDatabaseMigrations(): void
    {
        self::createMigrationRunner()->run();
    }

    public function renderMigrationNotice(): void
    {
        if (! function_exists('current_user_can') || ! current_user_can('manage_options')) {
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

        add_action('admin_menu', [$this->parserPage, 'registerMenu']);
        add_action('admin_menu', [$this->scheduledJobsPage, 'registerMenu']);
        add_action('admin_init', [$this, 'maybeRunDatabaseMigrations'], 5);
        add_action('admin_init', [$this->parserPage, 'maybeHandleSettings']);
        add_action('admin_post_adct_pi_run_job', [$this->scheduledJobsPage, 'handleRunNow']);
        add_action('admin_notices', [$this, 'renderMigrationNotice']);
        add_action('admin_notices', [$this->scheduledJobsPage, 'renderResultNotice']);
        $this->jobScheduler->registerHooks();
    }

    private static function createMigrationRunner(): MigrationRunner
    {
        $database = new WordPressDatabaseConnection();

        return new MigrationRunner(
            [new CreateSchemaMigration(new DbDeltaSchemaInstaller($database))],
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

        $apiKey = trim((string) get_option('adct_parish_intake_openrouter_api_key', ''));
        $model = trim((string) get_option('adct_parish_intake_openrouter_model', 'openrouter/auto'));

        if ($apiKey === '') {
            return new NullAiProvider();
        }

        return new OpenRouterProvider($apiKey, $model, $this->httpClient);
    }
}
