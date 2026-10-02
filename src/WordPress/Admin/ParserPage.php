<?php

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Attachments\PreviewableImage;
use ADCT\ParishIntake\Core\Parsing\Ai\NullAiProvider;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\Pipeline;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Parsing\SectionSkipper;
use ADCT\ParishIntake\Core\Parsing\Stages\ConfidenceScoringStage;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
use ADCT\ParishIntake\Core\Security\SecretRegistry;
use ADCT\ParishIntake\WordPress\Ai\OpenAiCompatibleProvider;
use ADCT\ParishIntake\WordPress\Attachments\AttachmentImageEndpoint;
use ADCT\ParishIntake\WordPress\Attachments\OcrControl;
use ADCT\ParishIntake\WordPress\Database\Schema;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Export\StaticReportGenerator;
use ADCT\ParishIntake\WordPress\Security\WordPressSecretResolver;
use ADCT\ParishIntake\WordPress\Jobs\RetentionSettings;
use Throwable;

final class ParserPage
{
    private const MENU_CAPABILITY = Capabilities::REVIEW;
    private const SETTINGS_CAPABILITY = Capabilities::MANAGE_SETTINGS;
    private const REVIEW_CAPABILITY = Capabilities::REVIEW;
    private const REPORTS_CAPABILITY = Capabilities::VIEW_REPORTS;
    private const SECTION_KEYWORDS_OPTION = 'adct_parish_intake_section_keywords';
    private const MENU_SLUG = 'toplevel_page_adct-parish-intake';

    /**
     * Bump this whenever assets/ocr.js or assets/ocr.css changes, so browsers
     * pick up a new copy instead of a cached one.
     */
    private const ASSET_VERSION = '1.0.0';

    private Schema $schema;
    private PipelineFactory $pipelineFactory;
    private StaticReportGenerator $reportGenerator;
    private HttpClientInterface $httpClient;
    private AiCallGateInterface $aiGate;
    private bool $settingsSaveSucceeded = false;
    private ?string $settingsSaveError = null;
    private ?AttachmentRepository $attachments;

    /**
<<<<<<< origin/main
     * Form field name to [option name, default value].
     *
     * @var array<string, array{0: string, 1: float}>
     */
    private const CONFIDENCE_SETTINGS = [
        'confidence_threshold' => [
            'adct_parish_intake_confidence_threshold',
            ReviewQueuePolicy::DEFAULT_CONFIDENCE_THRESHOLD,
        ],
        'field_confidence_threshold' => [
            'adct_parish_intake_field_confidence_threshold',
            ConfidenceScoringStage::DEFAULT_FIELD_THRESHOLD,
        ],
    ];
=======
     * Loads the OCR module on the Manual parser screen, and only when that
     * screen actually has a control to bind to (ADR 0018).
     *
     * `admin_enqueue_scripts` runs on every admin page, so the screen is
     * checked first: the poster query must not run site-wide.
     */
    public function enqueueOcrAssets(?string $hookSuffix = null): void
    {
        if ($this->ocrAssetsEnqueued || $hookSuffix !== self::MENU_SLUG) {
            return;
        }

        if ($this->recentImages() === []) {
            return;
        }

        $this->ocrAssetsEnqueued = true;

        wp_enqueue_style(
            'adct-parish-intake-ocr',
            plugins_url('assets/ocr.css', $this->pluginFile),
            [],
            self::ASSET_VERSION
        );

        wp_enqueue_script(
            'adct-parish-intake-ocr',
            plugins_url('assets/ocr.js', $this->pluginFile),
            [],
            self::ASSET_VERSION,
            true
        );
    }

    private ?OcrControl $ocrControl = null;
    private bool $ocrAssetsEnqueued = false;
    private ?AttachmentImageEndpoint $imageEndpoint = null;
>>>>>>> origin/pr160

    public function __construct(
        Schema $schema,
        PipelineFactory $pipelineFactory,
        StaticReportGenerator $reportGenerator,
        HttpClientInterface $httpClient,
        AiCallGateInterface $aiGate,
        ?AttachmentRepository $attachments = null,
        private string $pluginFile = '',
        ?AttachmentImageEndpoint $imageEndpoint = null
    ) {
        $this->schema = $schema;
        $this->pipelineFactory = $pipelineFactory;
        $this->reportGenerator = $reportGenerator;
        $this->httpClient = $httpClient;
        $this->aiGate = $aiGate;
        $this->attachments = $attachments;
        $this->imageEndpoint = $imageEndpoint;
    }

    /**
     * The on-demand OCR control, built once per request from the plugin's own
     * asset URLs (ADR 0018).
     */
    private function ocr(): OcrControl
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
     * The posters an operator can still read text out of in their browser.
     *
     * Only rows that pass the full `PreviewableImage` validation get a control,
     * so an image that is not browser-readable, or is missing from disk, quietly
     * falls back to manual entry rather than offering a button that cannot work.
     *
     * @return list<PreviewableImage>
     */
    private function recentImages(): array
    {
        if ($this->attachments === null || $this->imageEndpoint === null) {
            return [];
        }

        if (! current_user_can(self::REVIEW_CAPABILITY)) {
            return [];
        }

        try {
            $rows = $this->attachments->findRecentImages(5);
        } catch (Throwable $failure) {
            error_log(
                '[ADCT Parish Intake] Could not load recent image attachments ('
                . get_class($failure) . ').'
            );

            return [];
        }

        $images = [];

        foreach ($rows as $row) {
            try {
                $images[] = new PreviewableImage(
                    (int) $row['id'],
                    (int) $row['message_id'],
                    (string) $row['filename'],
                    (string) $row['storage_path'],
                    (string) $row['mime_type'],
                    (int) $row['size_bytes']
                );
            } catch (Throwable) {
                // Not browser-readable, oversized, or a row we cannot trust:
                // fall back to manual entry rather than a broken control.
                continue;
            }
        }

        return $images;
    }

    /**
     * One stored confidence threshold, falling back to its documented default.
     */
    private function confidenceSetting(string $key): float
    {
        [$option, $default] = self::CONFIDENCE_SETTINGS[$key];
        $value = get_option($option, (string) $default);

        return is_numeric($value) && (float) $value >= 0.0 && (float) $value <= 1.0
            ? (float) $value
            : $default;
    }

    /**
     * @return list<array{filename: string, status: string, updated_at: string}>
     */
    private function unreadablePdfs(): array
    {
        if ($this->attachments === null) {
            return [];
        }

        try {
            return $this->attachments->findRecentUnreadablePdfs(5);
        } catch (Throwable $failure) {
            error_log(
                '[ADCT Parish Intake] Could not load unreadable PDF attachment warnings ('
                . get_class($failure) . ').'
            );

            return [];
        }
    }

    private function pdfStatusLabel(string $status): string
    {
        return match ($status) {
            PdfExtractionResult::STATUS_NO_TEXT_LAYER => 'No text layer (likely a scan)',
            PdfExtractionResult::STATUS_SKIPPED_SIZE => 'Too large to read',
            PdfExtractionResult::STATUS_SKIPPED_PAGE_LIMIT => 'Too many pages',
            PdfExtractionResult::STATUS_SKIPPED_TIMEOUT => 'Took too long to read',
            PdfExtractionResult::STATUS_FAILED => 'Could not be opened',
            default => 'Not read',
        };
    }

    public function createConfiguredPipeline(bool $allowAi = false): Pipeline
    {
        $settings = $this->settings();

        return $this->pipelineFactory->create([
            'ai_enabled' => $allowAi && $settings['ai_enabled'],
            'ai_threshold' => $settings['ai_threshold'],
            'ai_provider' => $allowAi ? $this->buildAiProvider() : new NullAiProvider(),
            'section_keywords' => $settings['section_keywords'],
            'confidence_threshold' => $settings['confidence_threshold'],
            'field_confidence_threshold' => $settings['field_confidence_threshold'],
        ]);
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

        if (isset($_POST['reset_section_keywords'])) {
            update_option(
                self::SECTION_KEYWORDS_OPTION,
                SectionSkipper::defaultKeywordLists()
            );
            return;
        }

        $this->settingsSaveSucceeded = false;
        $this->settingsSaveError = null;

        $retentionSettings = RetentionSettings::fromValues(
            isset($_POST['retention_raw_enabled']) ? '1' : '0',
            wp_unslash($_POST['retention_raw_days'] ?? null),
            isset($_POST['retention_processed_enabled']) ? '1' : '0',
            wp_unslash($_POST['retention_processed_days'] ?? null),
            isset($_POST['retention_action_tokens_enabled']) ? '1' : '0',
            isset($_POST['retention_audit_enabled']) ? '1' : '0'
        );

        if ($retentionSettings->configurationError() !== null) {
            $this->settingsSaveError = $retentionSettings->configurationError();

            return;
        }

        update_option('adct_parish_intake_ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
        update_option('adct_parish_intake_ai_provider', sanitize_text_field(wp_unslash($_POST['ai_provider'] ?? 'none')));
        update_option('adct_parish_intake_openrouter_model', sanitize_text_field(wp_unslash($_POST['openrouter_model'] ?? OpenAiCompatibleProvider::FREE_MODEL)));
        update_option('adct_parish_intake_ai_base_url', esc_url_raw(wp_unslash($_POST['ai_base_url'] ?? OpenAiCompatibleProvider::DEFAULT_URL)));

        $secretResolver = new WordPressSecretResolver();
        $apiKeyOption = SecretRegistry::optionName(SecretRegistry::AI_API_KEY);

        if (isset($_POST['remove_openrouter_api_key'])) {
            delete_option($apiKeyOption);
        } elseif (
            ! $secretResolver->isConstantConfigured(SecretRegistry::AI_API_KEY)
            && isset($_POST['openrouter_api_key'])
            && is_string($_POST['openrouter_api_key'])
        ) {
            $apiKey = trim(wp_unslash($_POST['openrouter_api_key']));

            if ($apiKey !== '') {
                update_option($apiKeyOption, sanitize_text_field($apiKey));
            }
        }

        update_option('adct_parish_intake_ai_threshold', (string) max(0, min(1, (float) wp_unslash($_POST['ai_threshold'] ?? '0.55'))));

        foreach (self::CONFIDENCE_SETTINGS as $key => [$option, $default]) {
            $submitted = wp_unslash($_POST[$key] ?? null);

            // A missing or non-numeric field leaves the stored value alone rather than resetting
            // a working setting to 0 or 1, which would publish or queue everything.
            if (! is_scalar($submitted) || ! is_numeric($submitted)) {
                continue;
            }

            update_option($option, (string) max(0.0, min(1.0, (float) $submitted)));
        }

        $submittedKeywords = wp_unslash($_POST['section_keywords'] ?? []);
        $keywordLists = [];

        if (is_array($submittedKeywords)) {
            foreach (SectionSkipper::CATEGORIES as $category => $label) {
                $value = $submittedKeywords[$category] ?? null;

                if (! is_string($value)) {
                    continue;
                }

                $phrases = preg_split('/\R/u', $value) ?: [];
                $keywordLists[$category] = array_map('sanitize_text_field', $phrases);
            }
        }

        update_option(
            self::SECTION_KEYWORDS_OPTION,
            SectionSkipper::sanitizeKeywordLists($keywordLists)
        );

        update_option(
            RetentionSettings::RAW_ENABLED_OPTION,
            $retentionSettings->rawCleanupEnabled() ? '1' : '0'
        );
        update_option(
            RetentionSettings::RAW_DAYS_OPTION,
            (string) $retentionSettings->rawRetentionDays()
        );
        update_option(
            RetentionSettings::PROCESSED_ENABLED_OPTION,
            $retentionSettings->processedCleanupEnabled() ? '1' : '0'
        );
        update_option(
            RetentionSettings::PROCESSED_DAYS_OPTION,
            (string) $retentionSettings->processedRetentionDays()
        );
        update_option(
            RetentionSettings::ACTION_TOKENS_ENABLED_OPTION,
            $retentionSettings->actionTokenCleanupEnabled() ? '1' : '0'
        );
        update_option(
            RetentionSettings::AUDIT_ENABLED_OPTION,
            $retentionSettings->auditCleanupEnabled() ? '1' : '0'
        );

        $this->settingsSaveSucceeded = true;
    }

    public function renderSettingsPage(): void
    {
        if (! current_user_can(self::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adct-parish-intake'));
        }

        $settings = $this->settings();
        $retentionSettings = RetentionSettings::current();
        ?>
        <div class="wrap">
            <h1>Parish Intake Settings</h1>
            <p>Use this screen to configure the parser. Manual test parsing is available under <strong>Parish Intake → Manual parser</strong>.</p>

            <?php if ($this->settingsSaveError !== null) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($this->settingsSaveError); ?></p></div>
            <?php elseif ($this->settingsSaveSucceeded) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <h2>AI fallback</h2>
            <p>The parser runs locally first. AI is only used by the background inbox processing job when you enable it and a message scores below the confidence threshold. Manual parser requests never call AI.</p>
            <?php if ($settings['ai_enabled'] && ! str_ends_with($settings['openrouter_model'], ':free')) : ?>
                <div class="notice notice-warning"><p><strong>AI cost warning:</strong> This model is not marked <code>:free</code>. It may incur charges; other providers and even free-tier endpoints may have quotas or fees. Check your provider's pricing before processing mail.</p></div>
            <?php endif; ?>
            <?php if ($retentionSettings->configurationError() !== null) : ?>
                <div class="notice notice-error"><p><strong>Retention cleanup is not configured safely.</strong> <?php echo esc_html($retentionSettings->configurationError()); ?></p></div>
            <?php elseif ($retentionSettings->hasAnyCleanupEnabled()) : ?>
                <div class="notice notice-warning"><p><strong>Retention cleanup is on.</strong> Raw files, attachments, expired tokens and old audit rows will be removed automatically if you enable their separate switches. Deletions are permanent.</p></div>
            <?php endif; ?>
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
                                <option value="groq" <?php selected($settings['ai_provider'], 'groq'); ?>>Groq</option>
                                <option value="ollama" <?php selected($settings['ai_provider'], 'ollama'); ?>>Local Ollama</option>
                                <option value="custom" <?php selected($settings['ai_provider'], 'custom'); ?>>Other OpenAI-compatible endpoint</option>
                            </select>
                            <p class="description">Choose which AI provider to call when fallback is enabled.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Model</th>
                        <td>
                            <input class="regular-text" type="text" name="openrouter_model" value="<?php echo esc_attr($settings['openrouter_model']); ?>" />
                            <p class="description">Default OpenRouter model: <code><?php echo esc_html(OpenAiCompatibleProvider::FREE_MODEL); ?></code>. Free models have rate limits and availability may change. Choose a model your provider supports.</p>
                            <?php if (! str_ends_with($settings['openrouter_model'], ':free')) : ?>
                                <p class="description"><strong>Not marked free: this model may incur charges.</strong> Check the provider's pricing.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API base URL</th>
                        <td>
                            <input class="regular-text" type="url" name="ai_base_url" value="<?php echo esc_attr($settings['ai_base_url']); ?>" />
                            <p class="description">Include <code>/v1</code> where required; the plugin appends <code>/chat/completions</code>. HTTPS required except loopback local Ollama. No external URLs from email are fetched.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Provider API key</th>
                        <td>
                            <?php if ($settings['openrouter_api_key_is_constant']) : ?>
                                <p class="description">Set in wp-config.php</p>
                                <?php if ($settings['openrouter_api_key_is_saved']) : ?>
                                    <p class="description">A database key is also saved.</p>
                                    <label><input type="checkbox" name="remove_openrouter_api_key" value="1" /> Remove saved key</label>
                                <?php endif; ?>
                            <?php else : ?>
                                <input class="regular-text" type="password" name="openrouter_api_key" value="" autocomplete="new-password" />
                                <?php if ($settings['openrouter_api_key_is_saved']) : ?>
                                    <p class="description">A key is saved. Leave blank to keep it.</p>
                                    <label><input type="checkbox" name="remove_openrouter_api_key" value="1" /> Remove saved key</label>
                                <?php else : ?>
                                    <p class="description">Paste a valid API key only when you need to add or replace it.</p>
                                <?php endif; ?>
                            <?php endif; ?>
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
                <h2>Confidence and review</h2>
                <p>Each extracted field is scored on the evidence behind it, and the event score is
                    derived from those field scores. A field the parser had to invent scores zero and
                    lowers the event score, so guessing cannot make a notice look reliable.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Review threshold</th>
                        <td>
                            <input type="number" step="0.05" min="0" max="1" name="confidence_threshold" value="<?php echo esc_attr((string) $settings['confidence_threshold']); ?>" />
                            <p class="description">Events scoring below this value go to a human for approval instead of being published. Lower it to review more, raise it to review less.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Field confidence threshold</th>
                        <td>
                            <input type="number" step="0.05" min="0" max="1" name="field_confidence_threshold" value="<?php echo esc_attr((string) $settings['field_confidence_threshold']); ?>" />
                            <p class="description">A field scoring below this value is marked as needing attention on the confirmation email and the review screen. The event title and date are always treated this way.</p>
                        </td>
                    </tr>
                </table>
                <h2>Retention and cleanup</h2>
                <p>Retention cleanup is off by default. Enable only what you need and set clear retention periods first, because the plugin deletes data permanently once it is eligible. Raw-data pruning removes stored `.eml` files and related attachment files after the retention period; Processed-folder pruning removes only messages with exact plugin move receipts and matching mailbox identity and UIDVALIDITY. Existing or untracked mailbox messages are never pruned. Expired action tokens are removed after 30 days past expiry and audit log rows older than 24 months are pruned only when their separate switches are enabled.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Raw message retention</th>
                        <td>
                            <label>
                                <input type="checkbox" name="retention_raw_enabled" value="1" <?php checked($settings['retention_raw_enabled']); ?> />
                                Delete stored `.eml` files and attachments after the retention period
                            </label>
                            <p class="description">Keep the message row and metadata, but remove the raw mail and attachment files after the selected number of days. Leave this off if you still need raw mail for review or reprocessing.</p>
                            <label for="adct-pi-retention-raw-days">Delete after</label>
                            <input id="adct-pi-retention-raw-days" type="number" min="1" step="1" name="retention_raw_days" value="<?php echo esc_attr((string) $settings['retention_raw_days']); ?>" />
                            <span class="description">days</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Processed folder pruning</th>
                        <td>
                            <label>
                                <input type="checkbox" name="retention_processed_enabled" value="1" <?php checked($settings['retention_processed_enabled']); ?> />
                                Delete eligible plugin-moved messages from the Processed folder
                            </label>
                            <p class="description">Only messages moved by this plugin with an exact server-provided UID mapping are eligible, and only while the mailbox identity and folder UIDVALIDITY still match. Existing or untracked messages are never deleted. Safe deletion also requires UIDPLUS; without it, cleanup fails closed and leaves messages in place.</p>
                            <label for="adct-pi-retention-processed-days">Prune after</label>
                            <input id="adct-pi-retention-processed-days" type="number" min="1" step="1" name="retention_processed_days" value="<?php echo esc_attr((string) $settings['retention_processed_days']); ?>" />
                            <span class="description">days</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Action token cleanup</th>
                        <td>
                            <label>
                                <input type="checkbox" name="retention_action_tokens_enabled" value="1" <?php checked($settings['retention_action_tokens_enabled']); ?> />
                                Delete expired confirmation, login and approval tokens after 30 days
                            </label>
                            <p class="description">This removes only tokens whose own expiry date has already passed.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Audit log cleanup</th>
                        <td>
                            <label>
                                <input type="checkbox" name="retention_audit_enabled" value="1" <?php checked($settings['retention_audit_enabled']); ?> />
                                Delete audit log rows older than 24 months
                            </label>
                            <p class="description">Keep this off if you need a longer review or compliance trail.</p>
                        </td>
                    </tr>
                </table>
                <h2>Skip non-event bulletin sections</h2>
                <p>These phrases help keep routine or sensitive bulletin sections out of event candidates and AI enrichment. Matching is case- and punctuation-insensitive; a skipped section continues until the next heading. Leave a category blank to use its built-in defaults.</p>
                <table class="form-table" role="presentation">
                    <?php foreach (SectionSkipper::CATEGORIES as $category => $label) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td>
                                <textarea class="large-text code" rows="4" name="section_keywords[<?php echo esc_attr($category); ?>]"><?php echo esc_textarea(implode("\n", $settings['section_keywords'][$category])); ?></textarea>
                                <p class="description">Enter one heading or leading phrase per line.</p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <p>
                    <button class="button" type="submit" name="reset_section_keywords" value="1">Reset section keywords to defaults</button>
                    <span class="description">This changes only the section keyword lists.</span>
                </p>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>Email inbox processing</h2>
            <p>Configure mailbox connections under <strong>Parish Intake → Mailboxes</strong>. Stored messages appear in <strong>Parish Intake → Inbox</strong> with their processing status and any action needed.</p>
            <p class="description">Reprocessing uses the protected copy already stored by the plugin. It does not reconnect to the mailbox, change its checkpoint, or send confirmation email.</p>
        </div>
        <?php
    }

    public function renderManualParserPage(): void
    {
        if (! current_user_can(self::REVIEW_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'adct-parish-intake'));
        }

        $outcome = null;
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

            $outcome = $this->createConfiguredPipeline()->parseAll($message);
            $this->schema->insertMessageResult($message, $outcome->getPrimaryResult());
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
            <p>Use this screen to test the parser with a pasted message and store the result. Every detected candidate is shown; the legacy prototype table stores the first candidate, or the notice result when none is detected. Configuration lives under <strong>Parish Intake → Settings</strong>.</p>

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
                        <td>
                            <input type="email" class="regular-text" name="sender_email" value="" />
                            <p class="description">A verified address linked to one parish fills that parish and its default venue; other senders are matched from message text.</p>
                        </td>
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

            <?php if ($outcome) : ?>
                <h2>Latest parse outcome</h2>
                <?php if (array_filter(
                    $outcome->getNotes(),
                    static fn (string $note): bool => str_starts_with(
                        $note,
                        'possible_missed_event_after_skipped_section: '
                    )
                ) !== []) : ?>
                    <div class="notice notice-warning">
                        <p><?php echo esc_html__('A skipped private section may contain an event after a blank line. Review the original message manually; the skipped text was not parsed or sent to AI.', 'adct-parish-intake'); ?></p>
                    </div>
                <?php endif; ?>
                <pre><?php echo esc_html(wp_json_encode($outcome->toArray(), JSON_PRETTY_PRINT)); ?></pre>
            <?php endif; ?>

            <?php if ($report && ! empty($report['url'])) : ?>
                <p><strong>Static snapshot:</strong> <a href="<?php echo esc_url($report['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($report['url']); ?></a></p>
            <?php endif; ?>

            <?php $unreadablePdfs = $this->unreadablePdfs(); ?>
            <?php if ($unreadablePdfs !== []) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php echo esc_html__('PDF posters that could not be read', 'adct-parish-intake'); ?></strong></p>
                    <p><?php echo esc_html__('These PDFs arrived as attachments but produced no text, so any event in them must be entered by hand. The email around them was still processed.', 'adct-parish-intake'); ?></p>
                    <ul>
                        <?php foreach ($unreadablePdfs as $pdf) : ?>
                            <li>
                                <code><?php echo esc_html((string) ($pdf['filename'] ?? '')); ?></code>
                                &mdash; <?php echo esc_html($this->pdfStatusLabel((string) ($pdf['status'] ?? ''))); ?>
                                <?php if ((string) ($pdf['updated_at'] ?? '') !== '') : ?>
                                    <br /><small><?php echo esc_html((string) $pdf['updated_at']); ?> UTC</small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php $posterImages = $this->recentImages(); ?>
            <?php if ($posterImages !== []) : ?>
                <h2>Recent poster images</h2>
                <p><?php echo esc_html__('These images arrived as attachments and were not read by the parser, so any event in them must be entered by hand. You can read the text off a poster here in your own browser: nothing is uploaded, and nothing is saved.', 'adct-parish-intake'); ?></p>
                <div class="adct-ocr-list">
                    <?php foreach ($posterImages as $image) : ?>
                        <div class="adct-ocr-list__item">
                            <code><?php echo esc_html($image->filename); ?></code>
                            <?php echo $this->ocr()->render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- OcrControl escapes every part.
                                $this->imageEndpoint->imageUrl($image->attachmentId),
                                'adct-ocr-result-' . $image->attachmentId
                            ); ?>
                            <label class="adct-ocr-list__label">
                                <?php echo esc_html__('Text you read from this poster', 'adct-parish-intake'); ?>
                                <textarea id="adct-ocr-result-<?php echo esc_attr((string) $image->attachmentId); ?>" rows="4" class="large-text"></textarea>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
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

    public function buildAiProvider()
    {
        $enabled = get_option('adct_parish_intake_ai_enabled', '0') === '1';
        $provider = get_option('adct_parish_intake_ai_provider', 'none');
        $apiKey = (new WordPressSecretResolver())->resolve(SecretRegistry::AI_API_KEY);
        $settings = $this->settings();
        if (! $enabled || ! in_array($provider, ['openrouter', 'groq', 'ollama', 'custom'], true)) {
            return new NullAiProvider();
        }

        return new OpenAiCompatibleProvider(
            $settings['ai_base_url'],
            $settings['openrouter_model'],
            $apiKey,
            $provider,
            $this->httpClient,
            $this->aiGate
        );
    }

    private function settings(): array
    {
        $secretResolver = new WordPressSecretResolver();

        return [
            'ai_enabled' => get_option('adct_parish_intake_ai_enabled', '0') === '1',
            'ai_provider' => (string) get_option('adct_parish_intake_ai_provider', 'none'),
            'openrouter_model' => $this->configuredModel(),
            'ai_base_url' => $this->configuredBaseUrl(),
            'openrouter_api_key_is_constant' => $secretResolver->isConstantConfigured(SecretRegistry::AI_API_KEY),
            'openrouter_api_key_is_saved' => $secretResolver->hasStoredOption(SecretRegistry::AI_API_KEY),
            'ai_threshold' => (float) get_option('adct_parish_intake_ai_threshold', '0.55'),
            'confidence_threshold' => $this->confidenceSetting('confidence_threshold'),
            'field_confidence_threshold' => $this->confidenceSetting('field_confidence_threshold'),
            'retention_raw_enabled' => get_option(RetentionSettings::RAW_ENABLED_OPTION, '0') === '1',
            'retention_raw_days' => $this->retentionDays(
                get_option(RetentionSettings::RAW_DAYS_OPTION, RetentionSettings::DEFAULT_RAW_DAYS),
                RetentionSettings::DEFAULT_RAW_DAYS
            ),
            'retention_processed_enabled' => get_option(RetentionSettings::PROCESSED_ENABLED_OPTION, '0') === '1',
            'retention_processed_days' => $this->retentionDays(
                get_option(RetentionSettings::PROCESSED_DAYS_OPTION, RetentionSettings::DEFAULT_PROCESSED_DAYS),
                RetentionSettings::DEFAULT_PROCESSED_DAYS
            ),
            'retention_action_tokens_enabled' => get_option(RetentionSettings::ACTION_TOKENS_ENABLED_OPTION, '0') === '1',
            'retention_audit_enabled' => get_option(RetentionSettings::AUDIT_ENABLED_OPTION, '0') === '1',
            'section_keywords' => $this->sectionKeywords(),
        ];
    }

    private function configuredModel(): string
    {
        $value = defined('ADCT_PI_AI_MODEL')
            ? constant('ADCT_PI_AI_MODEL')
            : get_option('adct_parish_intake_openrouter_model', OpenAiCompatibleProvider::FREE_MODEL);
        return $value === 'openrouter/auto' ? OpenAiCompatibleProvider::FREE_MODEL : trim((string) $value);
    }

    private function configuredBaseUrl(): string
    {
        $value = defined('ADCT_PI_AI_BASE_URL')
            ? constant('ADCT_PI_AI_BASE_URL')
            : get_option('adct_parish_intake_ai_base_url', OpenAiCompatibleProvider::DEFAULT_URL);
        return trim((string) $value);
    }

    private function sectionKeywords(): array
    {
        $keywordLists = get_option(self::SECTION_KEYWORDS_OPTION, []);

        return is_array($keywordLists)
            ? SectionSkipper::sanitizeKeywordLists($keywordLists)
            : SectionSkipper::defaultKeywordLists();
    }

    private function retentionDays(mixed $value, int $default): int
    {
        $days = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($days) ? $days : $default;
    }
}
