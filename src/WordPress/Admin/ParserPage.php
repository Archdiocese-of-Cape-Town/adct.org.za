<?php

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\WordPress\Ai\OpenRouterProvider;
use ADCT\ParishIntake\WordPress\Database\Schema;
use ADCT\ParishIntake\WordPress\Export\StaticReportGenerator;

final class ParserPage
{
    private const MENU_CAPABILITY = Capabilities::REVIEW;
    private const SETTINGS_CAPABILITY = Capabilities::MANAGE_SETTINGS;
    private const REVIEW_CAPABILITY = Capabilities::REVIEW;
    private const REPORTS_CAPABILITY = Capabilities::VIEW_REPORTS;

    private Schema $schema;
    private PipelineFactory $pipelineFactory;
    private StaticReportGenerator $reportGenerator;
    private HttpClientInterface $httpClient;

    public function __construct(
        Schema $schema,
        PipelineFactory $pipelineFactory,
        StaticReportGenerator $reportGenerator,
        HttpClientInterface $httpClient
    ) {
        $this->schema = $schema;
        $this->pipelineFactory = $pipelineFactory;
        $this->reportGenerator = $reportGenerator;
        $this->httpClient = $httpClient;
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'Parish Intake',
            'Parish Intake',
            self::MENU_CAPABILITY,
            'adct-parish-intake',
            [$this, 'renderManualParserPage'],
            'dashicons-email-alt2',
            58
        );

        add_submenu_page(
            'adct-parish-intake',
            'Parish Intake Settings',
            'Settings',
            self::SETTINGS_CAPABILITY,
            'adct-parish-intake-settings',
            [$this, 'renderSettingsPage']
        );

        add_submenu_page(
            'adct-parish-intake',
            'Parish Intake Manual Parser',
            'Manual parser',
            self::REVIEW_CAPABILITY,
            'adct-parish-intake-manual-parser',
            [$this, 'renderManualParserPage']
        );
    }

    public function maybeHandleSettings(): void
    {
        if (! is_admin() || ! current_user_can(self::SETTINGS_CAPABILITY)) {
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

    public function renderSettingsPage(): void
    {
        if (! current_user_can(self::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adct-parish-intake'));
        }

        $settings = $this->settings();
        ?>
        <div class="wrap">
            <h1>Parish Intake Settings</h1>
            <p>Use this screen to configure the parser. Manual test parsing is available under <strong>Parish Intake → Manual parser</strong>.</p>

            <?php if (isset($_POST['adct_parish_intake_save_settings'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <h2>AI fallback</h2>
            <p>The parser runs locally first. AI is only used when you enable it and a message scores below the confidence threshold.</p>
            <form method="post">
                <?php wp_nonce_field('adct_parish_intake_save_settings', 'adct_parish_intake_settings_nonce'); ?>
                <input type="hidden" name="adct_parish_intake_save_settings" value="1" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable AI fallback</th>
                        <td>
                            <label><input type="checkbox" name="ai_enabled" value="1" <?php checked($settings['ai_enabled']); ?> /> Use AI only when the rule-based parser is not confident enough.</label>
                            <p class="description">Leave this off if you want all parsing to stay fully deterministic and offline-first.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Provider</th>
                        <td>
                            <select name="ai_provider">
                                <option value="none" <?php selected($settings['ai_provider'], 'none'); ?>>None</option>
                                <option value="openrouter" <?php selected($settings['ai_provider'], 'openrouter'); ?>>OpenRouter</option>
                            </select>
                            <p class="description">Choose which AI provider to call when fallback is enabled.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">OpenRouter model</th>
                        <td>
                            <input class="regular-text" type="text" name="openrouter_model" value="<?php echo esc_attr($settings['openrouter_model']); ?>" />
                            <p class="description">Example: <code>openrouter/auto</code>. This is ignored unless OpenRouter is selected above.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">OpenRouter API key</th>
                        <td>
                            <input class="regular-text" type="password" name="openrouter_api_key" value="" placeholder="Leave blank to keep existing key" />
                            <p class="description">Paste a valid API key only when you need to add or replace it.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">AI threshold</th>
                        <td>
                            <input type="number" step="0.05" min="0" max="1" name="ai_threshold" value="<?php echo esc_attr((string) $settings['ai_threshold']); ?>" />
                            <p class="description">Messages scoring below this confidence value will be sent to the AI fallback when enabled.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>Email inbox parsing</h2>
            <p>This prototype does not yet include automated inbox polling or IMAP/mailbox connection settings.</p>
            <p class="description">Right now, the available workflow is manual testing through <strong>Parish Intake → Manual parser</strong>. When inbox ingestion is built, its connection and scheduling options will appear here.</p>
        </div>
        <?php
    }

    public function renderManualParserPage(): void
    {
        if (! current_user_can(self::REVIEW_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adct-parish-intake'));
        }

        $result = null;
        $report = null;
        $canViewReports = current_user_can(self::REPORTS_CAPABILITY);

        if (
            ! isset($_POST['adct_parish_intake_generate_report'])
            && isset($_POST['adct_parish_intake_parse_nonce'], $_POST['adct_parish_intake_parse'])
        ) {
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
            if ($canViewReports) {
                $report = $this->reportGenerator->generate();
            }
        }

        if (isset($_POST['adct_parish_intake_generate_report'])) {
            if (! $canViewReports) {
                wp_die(esc_html__('You do not have permission to generate reports.', 'adct-parish-intake'), '', [
                    'response' => 403,
                ]);
            }

            check_admin_referer('adct_parish_intake_generate_report', 'adct_parish_intake_report_nonce');
            $report = $this->reportGenerator->generate();
        }

        $recent = $this->schema->fetchRecent();
        ?>
        <div class="wrap">
            <h1>Parish Intake Manual Parser</h1>
            <p>Use this screen to test the parser with a pasted message and store the result. Configuration lives under <strong>Parish Intake → Settings</strong>.</p>

            <h2>Parse a message</h2>
            <form method="post">
                <?php wp_nonce_field('adct_parish_intake_parse', 'adct_parish_intake_parse_nonce'); ?>
                <input type="hidden" name="adct_parish_intake_parse" value="1" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Source type</th>
                        <td>
                            <input type="text" class="regular-text" name="source_type" value="email" />
                            <p class="description">The channel this message came from, for example <code>email</code>, <code>webform</code>, or <code>manual-test</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Message identifier</th>
                        <td>
                            <input type="text" class="regular-text" name="source_identifier" value="manual-admin" />
                            <p class="description">A reference that helps you trace the original item later, for example an inbox UID, message ID, or a label like <code>manual-admin</code>.</p>
                        </td>
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
            </form>

            <?php if ($canViewReports) : ?>
                <form method="post">
                    <?php wp_nonce_field('adct_parish_intake_generate_report', 'adct_parish_intake_report_nonce'); ?>
                    <button class="button" type="submit" name="adct_parish_intake_generate_report" value="1">Generate static report only</button>
                </form>
            <?php endif; ?>

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

        return new OpenRouterProvider($apiKey, $model, $this->httpClient);
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
