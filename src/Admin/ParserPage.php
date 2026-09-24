<?php

namespace ADCT\ParishIntake\Admin;

use ADCT\ParishIntake\Database\Schema;
use ADCT\ParishIntake\Export\StaticReportGenerator;
use ADCT\ParishIntake\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Parsing\Ai\OpenRouterProvider;
use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\PipelineFactory;

final class ParserPage
{
    private const CAPABILITY = 'edit_others_posts';

    private Schema $schema;
    private PipelineFactory $pipelineFactory;
    private StaticReportGenerator $reportGenerator;

    public function __construct(Schema $schema, PipelineFactory $pipelineFactory, StaticReportGenerator $reportGenerator)
    {
        $this->schema = $schema;
        $this->pipelineFactory = $pipelineFactory;
        $this->reportGenerator = $reportGenerator;
    }

    public function registerMenu(): void
    {
        add_management_page(
            'Parish Intake Parser',
            'Parish Intake Parser',
            self::CAPABILITY,
            'adct-parish-intake-parser',
            [$this, 'render']
        );
    }

    public function maybeHandleSettings(): void
    {
        if (! is_admin() || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        if (! isset($_POST['adct_parish_intake_settings_nonce'], $_POST['adct_parish_intake_save_settings'])) {
            return;
        }

        check_admin_referer('adct_parish_intake_save_settings', 'adct_parish_intake_settings_nonce');

        update_option('adct_parish_intake_ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
        update_option('adct_parish_intake_ai_provider', sanitize_text_field(wp_unslash($_POST['ai_provider'] ?? 'none')));
        update_option('adct_parish_intake_openrouter_model', sanitize_text_field(wp_unslash($_POST['openrouter_model'] ?? 'openrouter/auto')));

        if (isset($_POST['openrouter_api_key']) && $_POST['openrouter_api_key'] !== '') {
            update_option('adct_parish_intake_openrouter_api_key', sanitize_text_field(wp_unslash($_POST['openrouter_api_key'])));
        }

        update_option('adct_parish_intake_ai_threshold', (string) max(0, min(1, (float) wp_unslash($_POST['ai_threshold'] ?? '0.55'))));
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adct-parish-intake'));
        }

        $result = null;
        $report = null;

        if (isset($_POST['adct_parish_intake_parse_nonce'], $_POST['adct_parish_intake_parse'])) {
            check_admin_referer('adct_parish_intake_parse', 'adct_parish_intake_parse_nonce');

            $message = new Message(
                sanitize_text_field(wp_unslash($_POST['source_type'] ?? 'email')),
                sanitize_text_field(wp_unslash($_POST['source_identifier'] ?? 'manual-admin')),
                sanitize_email(wp_unslash($_POST['sender_email'] ?? '')),
                sanitize_text_field(wp_unslash($_POST['sender_name'] ?? '')),
                sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
                sanitize_textarea_field(wp_unslash($_POST['body'] ?? ''))
            );

            $pipeline = $this->pipelineFactory->create([
                'ai_enabled' => get_option('adct_parish_intake_ai_enabled', '0') === '1',
                'ai_threshold' => (float) get_option('adct_parish_intake_ai_threshold', '0.55'),
                'ai_provider' => $this->buildAiProvider(),
            ]);

            $result = $pipeline->parse($message);
            $this->schema->insertMessageResult($message, $result);
            $report = $this->reportGenerator->generate();
        }

        if (isset($_POST['adct_parish_intake_generate_report'])) {
            check_admin_referer('adct_parish_intake_parse', 'adct_parish_intake_parse_nonce');
            $report = $this->reportGenerator->generate();
        }

        $recent = $this->schema->fetchRecent();
        $settings = $this->settings();
        ?>
        <div class="wrap">
            <h1>Parish Intake Parser</h1>
            <p>Offline-first extraction runs locally with deterministic rules; AI is optional and only used as a fallback.</p>

            <h2>AI settings</h2>
            <form method="post">
                <?php wp_nonce_field('adct_parish_intake_save_settings', 'adct_parish_intake_settings_nonce'); ?>
                <input type="hidden" name="adct_parish_intake_save_settings" value="1" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable AI fallback</th>
                        <td><label><input type="checkbox" name="ai_enabled" value="1" <?php checked($settings['ai_enabled']); ?> /> Only use AI on low-confidence parses</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Provider</th>
                        <td>
                            <select name="ai_provider">
                                <option value="none" <?php selected($settings['ai_provider'], 'none'); ?>>None</option>
                                <option value="openrouter" <?php selected($settings['ai_provider'], 'openrouter'); ?>>OpenRouter</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">OpenRouter model</th>
                        <td><input class="regular-text" type="text" name="openrouter_model" value="<?php echo esc_attr($settings['openrouter_model']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row">OpenRouter API key</th>
                        <td><input class="regular-text" type="password" name="openrouter_api_key" value="" placeholder="Leave blank to keep existing key" /></td>
                    </tr>
                    <tr>
                        <th scope="row">AI threshold</th>
                        <td><input type="number" step="0.05" min="0" max="1" name="ai_threshold" value="<?php echo esc_attr((string) $settings['ai_threshold']); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button('Save AI settings'); ?>
            </form>

            <h2>Parse a message</h2>
            <form method="post">
                <?php wp_nonce_field('adct_parish_intake_parse', 'adct_parish_intake_parse_nonce'); ?>
                <input type="hidden" name="adct_parish_intake_parse" value="1" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Source type</th>
                        <td><input type="text" class="regular-text" name="source_type" value="email" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Source identifier</th>
                        <td><input type="text" class="regular-text" name="source_identifier" value="manual-admin" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Sender email</th>
                        <td><input type="email" class="regular-text" name="sender_email" value="" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Sender name</th>
                        <td><input type="text" class="regular-text" name="sender_name" value="" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Subject</th>
                        <td><input type="text" class="large-text" name="subject" value="" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Body</th>
                        <td><textarea class="large-text code" rows="12" name="body"></textarea></td>
                    </tr>
                </table>
                <?php submit_button('Parse and save'); ?>
                <button class="button" type="submit" name="adct_parish_intake_generate_report" value="1">Generate static report only</button>
            </form>

            <?php if ($result) : ?>
                <h2>Latest parse result</h2>
                <pre><?php echo esc_html(wp_json_encode($result->toArray(), JSON_PRETTY_PRINT)); ?></pre>
            <?php endif; ?>

            <?php if ($report && ! empty($report['url'])) : ?>
                <p><strong>Static snapshot:</strong> <a href="<?php echo esc_url($report['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($report['url']); ?></a></p>
            <?php endif; ?>

            <h2>Recent stored parses</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Created</th>
                        <th>Sender</th>
                        <th>Classification</th>
                        <th>Title</th>
                        <th>Confidence</th>
                        <th>AI</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($recent === []) : ?>
                    <tr><td colspan="7">No stored parses yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($recent as $row) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $row['id']); ?></td>
                            <td><?php echo esc_html((string) $row['created_at']); ?></td>
                            <td><?php echo esc_html((string) $row['sender_email']); ?></td>
                            <td><?php echo esc_html((string) $row['classification']); ?></td>
                            <td><?php echo esc_html((string) $row['title']); ?></td>
                            <td><?php echo esc_html((string) $row['extraction_confidence']); ?></td>
                            <td><?php echo ! empty($row['ai_used']) ? 'yes' : 'no'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function buildAiProvider()
    {
        $enabled = get_option('adct_parish_intake_ai_enabled', '0') === '1';
        $provider = get_option('adct_parish_intake_ai_provider', 'none');
        $apiKey = trim((string) get_option('adct_parish_intake_openrouter_api_key', ''));
        $model = trim((string) get_option('adct_parish_intake_openrouter_model', 'openrouter/auto'));

        if (! $enabled || $provider !== 'openrouter' || $apiKey === '') {
            return new NullAiProvider();
        }

        return new OpenRouterProvider($apiKey, $model);
    }

    private function settings(): array
    {
        return [
            'ai_enabled' => get_option('adct_parish_intake_ai_enabled', '0') === '1',
            'ai_provider' => (string) get_option('adct_parish_intake_ai_provider', 'none'),
            'openrouter_model' => (string) get_option('adct_parish_intake_openrouter_model', 'openrouter/auto'),
            'ai_threshold' => (float) get_option('adct_parish_intake_ai_threshold', '0.55'),
        ];
    }
}
