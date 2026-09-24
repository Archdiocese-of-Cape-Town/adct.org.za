<?php

namespace ADCT\ParishIntake;

use ADCT\ParishIntake\Admin\ParserPage;
use ADCT\ParishIntake\Database\Schema;
use ADCT\ParishIntake\Export\StaticReportGenerator;
use ADCT\ParishIntake\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Parsing\Ai\OpenRouterProvider;
use ADCT\ParishIntake\Parsing\PipelineFactory;

final class Plugin
{
    private static ?self $instance = null;

    private string $pluginFile;
    private Schema $schema;
    private PipelineFactory $pipelineFactory;
    private ParserPage $parserPage;

    private function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->schema = new Schema();
        $this->pipelineFactory = new PipelineFactory();
        $this->parserPage = new ParserPage(
            $this->schema,
            $this->pipelineFactory,
            new StaticReportGenerator($this->schema)
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
    }

    private function registerHooks(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('admin_menu', [$this->parserPage, 'registerMenu']);
        add_action('admin_init', [$this->parserPage, 'maybeHandleSettings']);
    }

    public function makeAiProvider(): object
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

        return new OpenRouterProvider($apiKey, $model);
    }
}
