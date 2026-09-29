<?php

/**
 * Integration checks for the on-demand client-side OCR surfaces of ADR 0017.
 *
 * The unit suite covers the gate logic in isolation. This check proves the
 * wiring holds against a real database and a real page render: an approval link
 * for a candidate whose message carried a poster must render exactly one OCR
 * control, a candidate with no poster must render none and must not load the
 * OCR assets at all, a token must never reach another candidate's poster, and
 * rendering the page must write nothing back to the attachment row.
 */

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenHandlerRegistry;
use ADCT\ParishIntake\Core\Auth\ActionTokenOutcome;
use ADCT\ParishIntake\Core\Auth\ActionTokenPreview;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenRateLimiter;
use ADCT\ParishIntake\Core\Auth\ActionTokenRenewalService;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Mail\MailQueueEnqueueResult;
use ADCT\ParishIntake\Core\Mail\MailQueueStatus;
use ADCT\ParishIntake\Core\Mail\OutboundEmail;
use ADCT\ParishIntake\Core\Ports\ActionTokenActionHandlerInterface;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\MailerInterface;
use ADCT\ParishIntake\WordPress\Attachments\ActionTokenImageEndpoint;
use ADCT\ParishIntake\WordPress\Attachments\OcrControl;
use ADCT\ParishIntake\WordPress\Attachments\WordPressCandidateSourceMessage;
use ADCT\ParishIntake\WordPress\Attachments\WordPressPreviewableImageRepository;
use ADCT\ParishIntake\WordPress\Auth\ActionTokenEndpoint;
use ADCT\ParishIntake\WordPress\Auth\WordPressActionTokenRenewalDelivery;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\EventCandidateRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenRateLimitStore;
use ADCT\ParishIntake\WordPress\Database\WordPressActionTokenStore;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;

final class OcrImageCheck
{
    private const SCRIPT_MARKER = 'assets/ocr.js';
    private const STYLE_MARKER = 'assets/ocr.css';
    private const CONTROL_MARKER = 'data-adct-ocr';

    public static function run(callable $fail, string $pluginFile): void
    {
        global $wpdb;

        $check = static function (bool $condition, string $message) use ($fail): void {
            if (! $condition) {
                $fail($message);
            }
        };

        if ($pluginFile === '') {
            $fail('The OCR check needs the plugin file to build its asset URLs.');

            return;
        }

        $database = new WordPressDatabaseConnection();
        $clock = new OcrImageCheckClock();
        $tokens = new ActionTokenService(new WordPressActionTokenStore($database), $clock);
        $limiter = new ActionTokenRateLimiter(
            new WordPressActionTokenRateLimitStore($database),
            $clock,
            wp_salt('auth')
        );
        $mailer = new OcrImageCheckMailer();
        $images = new WordPressPreviewableImageRepository(new AttachmentRepository($database));
        $resolver = new \ADCT\ParishIntake\Core\Attachments\CandidateSourceImageResolver(
            $images,
            new WordPressCandidateSourceMessage(new EventCandidateRepository($database))
        );
        $ocr = new OcrControl(
            plugins_url(self::SCRIPT_MARKER, $pluginFile),
            plugins_url(self::STYLE_MARKER, $pluginFile)
        );
        $imageEndpoint = new ActionTokenImageEndpoint(
            $tokens,
            $resolver,
            $images,
            new ProtectedInboundMailStorage()
        );
        $endpoint = new ActionTokenEndpoint(
            $tokens,
            new ActionTokenHandlerRegistry([new OcrImageCheckHandler()]),
            new ActionTokenRenewalService($tokens, $limiter, new WordPressActionTokenRenewalDelivery($mailer)),
            $imageEndpoint,
            $ocr
        );

        $messages = $wpdb->prefix . 'adct_pi_inbound_messages';
        $attachments = $wpdb->prefix . 'adct_pi_attachments';
        $candidates = $wpdb->prefix . 'adct_pi_event_candidates';
        $tokensTable = $wpdb->prefix . 'adct_pi_action_tokens';
        $sources = $wpdb->prefix . 'adct_pi_sources';

        $suffix = strtolower(bin2hex(random_bytes(8)));
        $email = 'ocr-' . $suffix . '@example.test';
        $otherEmail = 'ocr-other-' . $suffix . '@example.test';
        $storageName = str_repeat('a', 64) . '.jpg';
        $otherStorageName = str_repeat('b', 64) . '.jpg';
        $now = '2026-09-25 06:00:00';

        $sourceId = 0;
        $messageId = 0;
        $otherMessageId = 0;
        $candidateId = 0;
        $attachmentId = 0;
        $otherAttachmentId = 0;

        try {
            // Inbound messages need a real source row: the table is unique on
            // (source_id, external_id) and the candidate repository joins on it.
            $wpdb->insert($sources, [
                'parish_id' => null,
                'type' => 'mailbox',
                'identifier' => 'ocr-check-' . $suffix,
                'role' => 'monitored',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $sourceId = (int) $wpdb->insert_id;
            $check($sourceId > 0, 'The OCR check could not create a source row.');

            $messageId = self::insertMessage($wpdb, $messages, $sources, $sourceId, $suffix, $email, $now);
            $check($messageId > 0, 'The OCR check could not create a message row.');

            $otherMessageId = self::insertMessage($wpdb, $messages, $sources, $sourceId, $suffix . '-other', $otherEmail, $now);
            $check($otherMessageId > 0, 'The OCR check could not create a second message row.');

            $wpdb->insert($candidates, [
                'message_id' => $messageId,
                'block_index' => 0,
                'parish_id' => null,
                'fields' => wp_json_encode(['title' => 'Parish Mass', 'description' => '']),
                'confidence' => 0.900,
                'parser_version' => 'ocr-check',
                'status' => 'awaiting_approval',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $candidateId = (int) $wpdb->insert_id;
            $check($candidateId > 0, 'The OCR check could not create a candidate row.');

            $binding = new ActionTokenBinding(
                ActionTokenPurpose::APPROVE_EVENT,
                'event_candidate',
                $candidateId,
                $email
            );
            $token = $tokens->issue($binding, $clock->now()->modify('+1 hour'))->token();

            // No poster yet: the control must be absent and the assets unloaded.
            $withoutPoster = $endpoint->respond('GET', $token, '', '', '', '203.0.113.51');

            $check($withoutPoster->statusCode === 200, 'The OCR check could not render the token page.');
            $check(
                substr_count($withoutPoster->body, self::CONTROL_MARKER) === 0,
                'A candidate whose message had no poster rendered an OCR control.'
            );
            $check(
                strpos($withoutPoster->body, self::SCRIPT_MARKER) === false,
                'A page with no OCR control still loaded the OCR module.'
            );
            $check(
                strpos($withoutPoster->body, self::STYLE_MARKER) === false,
                'A page with no OCR control still loaded the OCR stylesheet.'
            );

            $wpdb->insert($attachments, [
                'message_id' => $messageId,
                'filename' => 'poster.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 2048,
                'storage_path' => $storageName,
                'content_hash' => hash('sha256', 'ocr-check-poster-' . $suffix),
                'extraction_method' => 'none',
                'status' => 'stored',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $attachmentId = (int) $wpdb->insert_id;
            $check($attachmentId > 0, 'The OCR check could not create an attachment row.');

            $wpdb->insert($attachments, [
                'message_id' => $otherMessageId,
                'filename' => 'other-poster.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 2048,
                'storage_path' => $otherStorageName,
                'content_hash' => hash('sha256', 'ocr-check-other-' . $suffix),
                'extraction_method' => 'none',
                'status' => 'stored',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $otherAttachmentId = (int) $wpdb->insert_id;
            $check($otherAttachmentId > 0, 'The OCR check could not create a second attachment row.');

            $withPoster = $endpoint->respond('GET', $token, '', '', '', '203.0.113.51');

            $check(
                $withPoster->statusCode === 200
                    && substr_count($withPoster->body, self::CONTROL_MARKER) === 1,
                'A candidate with a poster did not render exactly one OCR control.'
            );
            $check(
                strpos($withPoster->body, self::SCRIPT_MARKER) !== false,
                'The token page with a poster did not load the OCR module.'
            );
            $check(
                strpos($withPoster->body, self::STYLE_MARKER) !== false,
                'The token page with a poster did not load the OCR stylesheet.'
            );
            $check(
                strpos($withPoster->body, 'data-target="adct_edit_description"') !== false,
                'The token page OCR control did not target the description field.'
            );
            // The control must point at this candidate's own poster. Assert on
            // the id itself rather than the whole query string, because the
            // ampersand between the two arguments is entity-encoded by
            // esc_url() and that encoding is not what this check is about.
            $check(
                strpos($withPoster->body, ActionTokenImageEndpoint::IMAGE_PARAM . '=' . $attachmentId . '"') !== false,
                'The token page did not link its own poster id into the OCR control URL.'
            );
            $check(
                strpos($withPoster->body, $storageName) === false
                    && strpos($withPoster->body, $otherStorageName) === false,
                'The token page exposed a private storage name.'
            );
            $check(
                strpos($withPoster->body, $email) === false,
                'The token page leaked the submitter email address.'
            );
            $check(
                strpos($withPoster->body, $otherStorageName) === false
                    && strpos($withPoster->body, $otherAttachmentId . '"') === false,
                'The token page pointed at another candidate’s poster.'
            );

            // The point of ADR 0017: the browser does the OCR, so the page
            // render must leave the row exactly as it found it.
            $after = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT extracted_text, extraction_method, storage_path, size_bytes, status"
                    . " FROM {$attachments} WHERE id = %d",
                    $attachmentId
                ),
                ARRAY_A
            );

            $check(
                is_array($after)
                    && $after['extracted_text'] === null
                    && $after['extraction_method'] === 'none'
                    && $after['storage_path'] === $storageName
                    && (int) $after['size_bytes'] === 2048
                    && $after['status'] === 'stored',
                'Rendering the OCR control changed the attachment row.'
            );
            $check(
                (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM {$attachments} WHERE message_id IN (%d, %d)", $messageId, $otherMessageId)
                ) === 2,
                'Rendering the OCR control created or removed an attachment row.'
            );

            // A token authorises exactly one image: its own candidate's poster.
            $check(
                $imageEndpoint->allowedImage($token, $attachmentId) !== null,
                'An approval token could not reach its own candidate’s poster.'
            );
            $check(
                $imageEndpoint->allowedImage($token, $otherAttachmentId) === null,
                'An approval token reached another candidate’s poster.'
            );
            $check(
                $imageEndpoint->allowedImage($token, $attachmentId + 1000) === null,
                'An approval token reached a non-existent poster id.'
            );

            // Reading a poster must not consume the token: the reviewer still
            // has exactly one chance to decide (ADR 0004).
            $check(
                $tokens->inspect($token)->status === \ADCT\ParishIntake\Core\Auth\ActionTokenStatus::VALID,
                'Reading the poster consumed the approval token.'
            );
        } finally {
            foreach ([$messageId, $otherMessageId] as $id) {
                if ($id > 0) {
                    $wpdb->delete($attachments, ['message_id' => $id]);
                }
            }

            if ($candidateId > 0) {
                $wpdb->delete($tokensTable, ['subject_type' => 'event_candidate', 'subject_id' => $candidateId]);
                $wpdb->delete($candidates, ['id' => $candidateId]);
            }

            foreach ([$messageId, $otherMessageId] as $id) {
                if ($id > 0) {
                    $wpdb->delete($messages, ['id' => $id]);
                }
            }

            if ($sourceId > 0) {
                $wpdb->delete($sources, ['id' => $sourceId]);
            }
        }
    }

    private static function insertMessage(
        \wpdb $database,
        string $messages,
        string $sources,
        int $sourceId,
        string $suffix,
        string $email,
        string $now
    ): int {
        $database->insert($messages, [
            'source_id' => $sourceId,
            'external_id' => 'ocr-check-' . $suffix,
            'sender_email' => $email,
            'subject' => 'Parish notice',
            'received_at' => $now,
            'status' => 'received',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $database->insert_id;
    }
}

final class OcrImageCheckHandler implements ActionTokenActionHandlerInterface
{
    public function purpose(): ActionTokenPurpose
    {
        return ActionTokenPurpose::APPROVE_EVENT;
    }

    public function preview(ActionTokenBinding $binding): ?ActionTokenPreview
    {
        return new ActionTokenPreview(
            'Parish Mass',
            'Review and approve this fictional event.',
            'Approve',
            ['St Mary fictional parish']
        );
    }

    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        return new ActionTokenOutcome('Action completed.');
    }
}

final class OcrImageCheckMailer implements MailerInterface
{
    public array $messages = [];

    public function enqueue(OutboundEmail $email): MailQueueEnqueueResult
    {
        $this->messages[] = $email;

        return new MailQueueEnqueueResult(
            count($this->messages),
            MailQueueStatus::QUEUED,
            false
        );
    }
}

final class OcrImageCheckClock implements ClockInterface
{
    private DateTimeImmutable $instant;

    public function __construct()
    {
        $this->instant = new DateTimeImmutable('2026-09-25 08:00:00', new DateTimeZone('Africa/Johannesburg'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
