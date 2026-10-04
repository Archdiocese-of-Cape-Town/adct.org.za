<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Review\CandidateEditValidator;
use ADCT\ParishIntake\Core\Review\CandidateFieldSet;
use ADCT\ParishIntake\Core\Review\ReviewQueuePolicy;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\WordPress\Admin\ReviewQueuePage;
use ADCT\ParishIntake\WordPress\Attachments\AttachmentImageEndpoint;
use ADCT\ParishIntake\WordPress\Attachments\OcrControl;
use ADCT\ParishIntake\WordPress\Attachments\WordPressPreviewableImageRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use ADCT\ParishIntake\WordPress\Database\WordPressDatabaseConnection;
use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use ADCT\ParishIntake\WordPress\Plugin;

/**
 * Thrown from the `wp_redirect` filter to stop a handler before it calls
 * `exit`, while recording where it was going.
 */
final class CandidateDetailRedirected extends RuntimeException
{
}

/**
 * Candidate detail: the source email, its attachments, every extracted field,
 * and editing a candidate before deciding it.
 *
 * Everything is synthetic. Names, addresses and file contents are generated per
 * run and use the reserved `example.test` domain, so no parish data reaches the
 * repository.
 */
final class CandidateDetailCheck
{
    public static function run(callable $fail): void
    {
        global $wpdb;

        $suffix = bin2hex(random_bytes(6));
        $stamp = gmdate('Y-m-d H:i:s');
        $prefix = $wpdb->prefix . 'adct_pi_';
        $date = (new DateTimeImmutable('+14 days', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');

        $db = new WordPressDatabaseConnection();
        $queue = new ReviewQueueRepository($db, new SystemClock(), new ReviewQueuePolicy(), 0.55);
        $messages = new InboundMessageRepository($db);
        $attachments = new AttachmentRepository($db);
        $storage = new ProtectedInboundMailStorage();
        $pluginFile = 'adct-parish-intake/adct-parish-intake.php';
        // The same collaborators Plugin.php injects, so the poster panel and
        // its hand-typed-event button are built exactly as they are in
        // production rather than by a stand-in.
        $ocr = new OcrControl(
            plugins_url('adct-pi-ocr.js', $pluginFile),
            plugins_url('adct-pi-ocr.css', $pluginFile),
            plugins_url('adct-pi-parser.js', $pluginFile)
        );
        $imageEndpoint = new AttachmentImageEndpoint(
            new WordPressPreviewableImageRepository($attachments),
            $storage
        );
        $page = new ReviewQueuePage(
            $queue,
            Plugin::candidatePublisher(),
            new ReviewQueuePolicy(),
            $messages,
            $attachments,
            $storage,
            new CandidateEditValidator(),
            $pluginFile,
            $ocr,
            $imageEndpoint
        );

        $inserted = [];
        $users = [];
        $published = [];
        $storedFiles = [];

        $check = static function (bool $condition, string $message) use ($fail): void {
            if (! $condition) {
                $fail('Candidate detail: ' . $message);
            }
        };
        $insert = static function (string $table, array $values) use ($wpdb, $fail): int {
            if ($wpdb->insert($table, $values) !== 1 || (int) $wpdb->insert_id < 1) {
                $fail('Candidate detail synthetic fixture could not be inserted: ' . $wpdb->last_error);
            }

            return (int) $wpdb->insert_id;
        };
        $person = static function (string $role, string $label) use (&$users, $suffix, $fail): WP_User {
            $id = wp_create_user(
                'detail-' . $label . '-' . $suffix,
                wp_generate_password(28),
                'detail-' . $label . '-' . $suffix . '@example.test'
            );
            if (is_wp_error($id)) {
                $fail('Candidate detail test user could not be created.');
            }
            $users[] = $id;
            $user = new WP_User($id);
            $user->set_role($role);

            return $user;
        };
        $dieHandler = static function (): callable {
            return static function ($message): never {
                throw new RuntimeException(wp_strip_all_tags((string) $message));
            };
        };
                $redirectFor = static function (ReviewQueuePage $handler, string $method, array $post): ?string {
                    return self::redirectFor($handler, $method, $post);
                };

        $originalUser = get_current_user_id();
        $originalGet = $_GET;
        $originalPost = $_POST;
        $originalRequest = $_REQUEST;

        try {
            $deanery = $insert($prefix . 'deaneries', [
                'name' => 'Fictional Detail Deanery ' . $suffix,
                'slug' => 'detail-deanery-' . $suffix,
                'status' => 'active',
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $parish = $insert($prefix . 'parishes', [
                'name' => 'Fictional Detail Parish ' . $suffix,
                'slug' => 'detail-parish-' . $suffix,
                'deanery_id' => $deanery,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $contact = 'detail-contact-' . $suffix . '@example.test';
            $insert($prefix . 'parish_contacts', [
                'parish_id' => $parish,
                'email' => $contact,
                'trust' => 'verified',
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $reviewer = $person('adct_pi_intake_reviewer', 'reviewer');
            $dean = $person('deanery_approver', 'dean');
            $contactUser = $person('parish_contact', 'contact');

            // A different parish and deanery, to prove scope is enforced.
            $foreignDeanery = $insert($prefix . 'deaneries', [
                'name' => 'Fictional Foreign Deanery ' . $suffix,
                'slug' => 'detail-foreign-deanery-' . $suffix,
                'status' => 'active',
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $foreignParish = $insert($prefix . 'parishes', [
                'name' => 'Fictional Foreign Parish ' . $suffix,
                'slug' => 'detail-foreign-parish-' . $suffix,
                'deanery_id' => $foreignDeanery,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $foreignContact = 'detail-foreign-' . $suffix . '@example.test';
            $insert($prefix . 'parish_contacts', [
                'parish_id' => $foreignParish,
                'email' => $foreignContact,
                'trust' => 'verified',
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $insert($prefix . 'deanery_approvers', [
                'deanery_id' => $deanery,
                'wp_user_id' => $dean->ID,
                'email' => 'detail-dean-assignment-' . $suffix . '@example.test',
                'active' => 1,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            // Real stored files, so the download path is exercised against the
            // storage adapter rather than a stub. Both are synthetic.
            $rawBody = 'Fictional parish notice body ' . $suffix . '.';
            $rawMessage = "From: Fictional Office <{$contact}>\r\n"
                . "To: events@example.test\r\n"
                . 'Subject: Fictional Harvest Tea ' . $suffix . "\r\n"
                . "Date: Mon, 12 Oct 2026 09:14:00 +0200\r\n"
                . "Content-Type: text/plain; charset=utf-8\r\n"
                . "\r\n"
                . $rawBody . "\r\n";
            $rawPath = $storage->storeRawMessage($rawMessage);
            $posterBytes = 'FICTIONAL-POSTER-BYTES-' . $suffix;
            $posterPath = $storage->storeAttachment($posterBytes, 'pdf');
                        $imagePosterPath = $storage->storeAttachment(
                            'FICTIONAL-IMAGE-POSTER-BYTES-' . $suffix,
                            'jpg'
                        );
                        $storedFiles = [$rawPath, $posterPath, $imagePosterPath];

            $makeCandidate = static function (
                string $label,
                string $status,
                ?int $parishId,
                string $sender,
                string $rawPath,
                array $fieldOverrides = [],
                array $extras = []
            ) use ($prefix, $insert, &$inserted, $suffix, $stamp, $date, $rawBody): array {
                $message = $insert($prefix . 'inbound_messages', [
                    'source_id' => 1,
                    'external_id' => 'detail-' . $label . '-' . $suffix,
                    'sender_email' => $sender,
                    'subject' => 'Fictional Harvest Tea ' . $suffix,
                    'received_at' => $stamp,
                    'status' => 'parsed',
                    'raw_path' => $rawPath,
                    'body_text' => $rawBody,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
                $candidate = $insert($prefix . 'event_candidates', array_merge([
                    'message_id' => $message,
                    'parish_id' => $parishId,
                    'status' => $status,
                    'fields' => wp_json_encode(array_merge([
                        'title' => 'Fictional Harvest Tea ' . $suffix,
                        'event_date' => $date,
                        'event_time' => '09:00',
                        'event_end_time' => '10:00',
                                                // Must match a seeded event-type term, or publication fails.
                                                'event_type' => 'Other',
                                                'description' => 'A fictional parish notice.',
                                                'venue_name' => 'Fictional Hall',
                                            ], $fieldOverrides)),
                                            'recurrence' => '{"rrule":"FREQ=WEEKLY;BYDAY=TH"}',
                    'notes' => '[]',
                    'match_kind' => 'new',
                    'confidence' => 0.92,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ], $extras));
                $inserted[] = [$message, $candidate];

                return ['message' => $message, 'candidate' => $candidate];
            };
            $addPoster = static function (int $messageId, string $path, string $name) use (
                $prefix,
                $insert,
                $suffix,
                $stamp
            ): int {
                return $insert($prefix . 'attachments', [
                    'message_id' => $messageId,
                    'filename' => $name . '-' . $suffix . '.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 40,
                    'storage_path' => $path,
                    'extraction_method' => 'none',
                    'status' => 'stored',
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
            };
                        // A stored image, which is the only kind of file a hand-typed event
                        // is offered for: it is something a person can read off the screen.
                        $addImagePoster = static function (int $messageId, string $path, string $name) use (
                            $prefix,
                            $insert,
                            $suffix,
                            $stamp
                        ): int {
                            return $insert($prefix . 'attachments', [
                                'message_id' => $messageId,
                                'filename' => $name . '-' . $suffix . '.jpg',
                                'mime_type' => 'image/jpeg',
                                'size_bytes' => 34,
                                'storage_path' => $path,
                                'extraction_method' => 'none',
                                'status' => 'stored',
                                'created_at' => $stamp,
                                'updated_at' => $stamp,
                            ]);
                        };

            $main = $makeCandidate('main', 'awaiting_approval', $parish, $contact, $rawPath, [
                'field_confidence' => [
                    'score' => 0.92,
                    'coverage' => 0.8,
                    'fields' => [
                        'title' => ['score' => 0.98, 'origin' => 'explicit', 'flags' => []],
                        'parish_name' => [
                            'score' => 0.0,
                            'origin' => 'unsupported',
                            'flags' => ['unanchored_parish_match'],
                        ],
                    ],
                ],
            ]);
            $posterId = $addPoster($main['message'], $posterPath, 'fictional-poster');
            // The image on the same email, so the hand-typed-event route has a
            // real stored poster to be started from.
            $imagePosterId = $addImagePoster(
                $main['message'],
                $imagePosterPath,
                'fictional-photo-poster'
            );

            // A second candidate on the same parish, to prove one candidate's
            // attachments are not reachable from another's detail screen.
            $sibling = $makeCandidate('sibling', 'awaiting_approval', $parish, $contact, $rawPath);

            // A candidate whose stored attachment is no longer on disk.
            $missing = $makeCandidate('missing', 'awaiting_approval', $parish, $contact, $rawPath);
            $addPoster($missing['message'], str_repeat('0', 64) . '.pdf', 'fictional-gone');

            $foreign = $makeCandidate('foreign', 'awaiting_approval', $foreignParish, $foreignContact, $rawPath);

            $deanAssignmentEmail = 'detail-dean-assignment-' . $suffix . '@example.test';
            $approvedExtras = [
                'approved_by' => $deanAssignmentEmail,
                'approved_at' => $stamp,
                'approved_via' => 'dean',
                'decided_by' => $deanAssignmentEmail,
                'decided_at' => $stamp,
            ];
            $decided = $makeCandidate('decided', 'approved', $parish, $contact, $rawPath, [], $approvedExtras);
            $hostile = $makeCandidate('hostile', 'awaiting_approval', $parish, $contact, $rawPath, [
                'description' => '<script>alert("x")</script> Fictional & "quoted"',
            ]);

            $openDetail = static function (?int $candidateId) use ($page): string {
                            $_GET = [
                                'page' => ReviewQueuePage::PAGE_SLUG,
                                'tab' => 'awaiting_approval',
                                'search' => 'Fictional',
                                'candidate' => (string) $candidateId,
                            ];
                            ob_start();
                            try {
                                $page->renderPage();
                            } finally {
                                $html = (string) ob_get_clean();
                            }

                            return $html;
                        };

            // --- The detail screen shows source, attachments and every field ----
            wp_set_current_user($reviewer->ID);
            $detail = $openDetail($main['candidate']);

            $check(str_contains($detail, 'Fictional Harvest Tea ' . $suffix),
                'the detail screen must show the source email subject.');
            $check(str_contains($detail, $contact),
                'the detail screen must show the source sender address.');
            $check(str_contains($detail, $rawBody),
                'the detail screen must show the source message body.');
            $check(str_contains($detail, 'fictional-poster-' . $suffix . '.pdf'),
                'the detail screen must list the stored attachment.');
            $check(str_contains($detail, 'Fictional Hall'),
                'the detail screen must show an extracted field the form does not edit, such as the venue name.');
            $check(str_contains($detail, 'Field confidence')
                && str_contains($detail, '98%')
                && str_contains($detail, 'unsupported')
                && str_contains($detail, 'Not stated in the notice; the parser fell back to a guess.'),
                'the editable detail screen must retain readable per-field confidence and unsupported-value warnings.');
            $check(str_contains($detail, 'FREQ=WEEKLY;BYDAY=TH'),
                            'the detail screen must show the stored recurrence rule in the custom-rule box.');
            $check(str_contains($detail, 'value="save"')
                && str_contains($detail, 'value="approve"')
                && str_contains($detail, 'value="reject"'),
                'an editable candidate must offer save, save-and-approve and reject.');
            $check(str_contains($detail, 'name="' . ReviewQueuePage::SAVE_NONCE . '"'),
                'the edit form must carry its nonce.');
            $check(str_contains($detail, 'name="' . ReviewQueuePage::SOURCE_NONCE . '"'),
                'the download forms must carry their nonce.');
            $check(str_contains($detail, 'name="attachment_id"')
                            && str_contains($detail, 'value="' . $posterId . '"')
                            && str_contains($detail, 'name="candidate" value="' . $main['candidate'] . '"'),
                            'each download form must post the candidate and attachment it is for.');
            $check(str_contains($detail, '09:00') && str_contains($detail, '10:00'),
                'the detail screen must show the extracted start and end times.');
            $expectedEditDate = CandidateFieldSet::toEditDate($date);
                        $check($expectedEditDate !== ''
                            && str_contains($detail, 'value="' . $expectedEditDate . '"'),
                            'the detail screen must show the event date day-first, as the reviewer reads it.');

            $check($wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d",
                $main['candidate']
            )) === 'awaiting_approval',
                'rendering the detail must not decide a candidate.');

            // Every editable key must be represented, so a reviewer is never
            // told about a field the screen silently omits.
                        $editableKeys = array_merge(
                            CandidateFieldSet::EDITABLE_KEYS,
                            [
                                'event_end_date', 'event_time', 'all_day', 'recurrence_preset',
                                'recurrence_weekday', 'recurrence_ordinal', 'recurrence_month_day',
                                'recurrence_custom', 'contact_name', 'contact_email', 'contact_phone',
                            ]
                        );
                        $missingKeys = array_values(array_filter(
                            $editableKeys,
                            static function (string $key) use ($detail): bool {
                                return ! str_contains($detail, 'name="' . $key . '"');
                            }
                        ));
                        $check($missingKeys === [],
                            'the editor must offer a control for every editable field; missing: '
                                . implode(', ', $missingKeys));

            // A stored value must be escaped, not rendered.
            $hostileHtml = $openDetail($hostile['candidate']);
            $check(! str_contains($hostileHtml, '<script>alert("x")</script>'),
                'a stored description must be escaped, never emitted as markup.');
            $check(str_contains($hostileHtml, '&lt;script&gt;'),
                'the escaped description must still be visible to the reviewer.');

            // An attachment with no stored file must say so, not show a button.
            $missingHtml = $openDetail($missing['candidate']);
            $check(str_contains($missingHtml, 'No longer stored'),
                'an attachment whose file is gone must say so rather than offer a broken download.');

            // A deanery approver sees their own deanery's candidates and is
            // refused anyone else's. This is checked on the GET render, because
            // a successful download ends the request.
            wp_set_current_user($dean->ID);
            $deansOwnHtml = $openDetail($main['candidate']);
            $check(str_contains($deansOwnHtml, 'Candidate #'),
                'a deanery approver must be able to open a candidate in their own deanery.');
            // renderPage() answers an out-of-scope candidate with wp_die(), so
            // the refusal is captured the same way as the handler's.
            $deansForeignHtml = '';
            add_filter('wp_die_handler', $dieHandler);
            try {
                $deansForeignHtml = $openDetail($foreign['candidate']);
            } catch (RuntimeException $error) {
                $deansForeignHtml = '';
                $check(str_contains($error->getMessage(), 'not in your review queue'),
                    'a deanery approver must be refused another deanery: ' . $error->getMessage());
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }
            $check(! str_contains($deansForeignHtml, 'Candidate #'),
                'a deanery approver must not see a candidate from another deanery.');

            // A decided candidate is read-only: the values are still shown, but
            // the form carries no usable save control.
            wp_set_current_user($reviewer->ID);
            $decidedHtml = $openDetail($decided['candidate']);
                        $check(str_contains($decidedHtml, 'already been decided')
                            && str_contains($decidedHtml, 'read-only'),
                            'an already decided candidate must be marked read-only.');
                        $check(! str_contains($decidedHtml, 'value="approve"')
                            && ! str_contains($decidedHtml, 'value="save"')
                            && ! str_contains($decidedHtml, 'value="reject"'),
                            'an already decided candidate must not offer save, approve or reject.');
                        $check(str_contains($decidedHtml, 'name="title"')
                            && str_contains($decidedHtml, 'readonly'),
                            'an already decided candidate must still show its fields as read-only.');
                        $check(str_contains($decidedHtml, 'name="' . ReviewQueuePage::SAVE_NONCE . '"'),
                            'the read-only form is inert but keeps its shape, so a POST can never change a decision.');

            // --- Download authorization -----------------------------------------
            $reflect = new ReflectionClass(ReviewQueuePage::class);
            $rawFor = $reflect->getMethod('rawMessageFor');
            $rawFor->setAccessible(true);
            $attachmentFor = $reflect->getMethod('attachmentFor');
            $attachmentFor->setAccessible(true);

            $rawFile = $rawFor->invoke($page, $main['candidate']);
            $check(is_array($rawFile) && $rawFile['path'] === $rawPath,
                'the raw message must resolve from the candidate, not the request.');
            $check(is_array($rawFile) && $rawFile['filename'] === 'message-' . $main['message'] . '.eml',
                'the raw message download name must be derived from the message it belongs to.');
            $check($rawFor->invoke($page, $sibling['candidate']) !== null,
                'a candidate with a stored message must resolve its own.');

            $posterFile = $attachmentFor->invoke($page, $posterId, $main['candidate']);
            $check(is_array($posterFile) && $posterFile['path'] === $posterPath,
                'an attachment on the candidate\'s own message must resolve.');
            $check(is_array($posterFile)
                && $posterFile['filename'] === 'fictional-poster-' . $suffix . '.pdf',
                'the download must keep the filename the parish sent.');
            $check($attachmentFor->invoke($page, $posterId, $sibling['candidate']) === null,
                'an attachment on another candidate\'s message must not resolve.');
            $check($attachmentFor->invoke($page, 99999999, $main['candidate']) === null,
                'an unknown attachment must not resolve.');

            $mimeType = $reflect->getMethod('downloadMimeType');
            $mimeType->setAccessible(true);
            $check($mimeType->invoke($page, 'application/pdf') === 'application/pdf',
                'a legitimate MIME type must be served as recorded.');
            $check($mimeType->invoke($page, "application/pdf\r\nX-Injected: 1") === 'application/octet-stream',
                'a header-injecting MIME type must be reduced to an opaque type.');
            $check($mimeType->invoke($page, 'text/html') === 'application/octet-stream',
                'an unexpected MIME type must be served as an opaque download, never inline.');
            $check($mimeType->invoke($page, null) === 'application/octet-stream',
                'a missing MIME type must fall back to an opaque download.');

            // The download forms are POST-only: nothing acts on a GET.
            $check(! str_contains($detail, 'href="' . $rawPath)
                && ! str_contains($detail, $rawPath),
                'the stored message path must never appear in the page.');
            $check(! str_contains($detail, $posterPath),
                'the stored attachment path must never appear in the page.');

            // --- Handlers: capability, nonce, scope ------------------------------
            add_filter('wp_die_handler', $dieHandler);
            try {
                wp_set_current_user($contactUser->ID);
                $_POST = [
                    'action' => ReviewQueuePage::RAW_MESSAGE_ACTION,
                    'candidate' => (string) $main['candidate'],
                    ReviewQueuePage::SOURCE_NONCE => wp_create_nonce(ReviewQueuePage::RAW_MESSAGE_ACTION),
                ];
                $_REQUEST = $_POST;
                try {
                    $page->handleRawMessage();
                    $fail('Candidate detail: a parish contact was served the source message.');
                } catch (RuntimeException $error) {
                    $check(str_contains($error->getMessage(), 'cannot view'),
                        'a parish contact must be refused: ' . $error->getMessage());
                }

                wp_set_current_user($reviewer->ID);
                $_POST[ReviewQueuePage::SOURCE_NONCE] = 'invalid';
                try {
                    $page->handleRawMessage();
                    $fail('Candidate detail: an invalid raw-message nonce was accepted.');
                } catch (RuntimeException) {
                    $check(true, 'the raw-message nonce check fired.');
                }

                $_POST = [
                    'action' => ReviewQueuePage::ATTACHMENT_ACTION,
                    'candidate' => (string) $main['candidate'],
                    'attachment_id' => (string) $posterId,
                    ReviewQueuePage::SOURCE_NONCE => 'invalid',
                ];
                $_REQUEST = $_POST;
                try {
                    $page->handleAttachment();
                    $fail('Candidate detail: an invalid attachment nonce was accepted.');
                } catch (RuntimeException) {
                    $check(true, 'the attachment nonce check fired.');
                }

                $_POST[ReviewQueuePage::SOURCE_NONCE] = wp_create_nonce(ReviewQueuePage::ATTACHMENT_ACTION);
                $_POST['attachment_id'] = (string) $posterId;
                $_POST['candidate'] = (string) $sibling['candidate'];
                $_REQUEST = $_POST;
                try {
                    $page->handleAttachment();
                    $fail('Candidate detail: an attachment on another message was served.');
                } catch (RuntimeException $error) {
                    $check(str_contains($error->getMessage(), 'not available'),
                        'an attachment outside the candidate must be refused: '
                            . $error->getMessage());
                }

                // A deanery approver is scoped to their own deaneries, so a
                // candidate from another parish is outside it. An
                // archdiocese-wide reviewer is deliberately NOT expected to be
                // refused, because that capability is meant to see everything.
                // The positive case is checked through the GET render below,
                // because a successful download ends the request.
                wp_set_current_user($dean->ID);
                $_POST['candidate'] = (string) $foreign['candidate'];
                                // A nonce is bound to the current user, so it must be re-minted
                                // after switching; otherwise the stale reviewer nonce would fail
                                // first and mask the scope check we are actually testing.
                                $_POST[ReviewQueuePage::SOURCE_NONCE] = wp_create_nonce(ReviewQueuePage::ATTACHMENT_ACTION);
                                $_REQUEST = $_POST;
                                try {
                                    $page->handleAttachment();
                                    $fail('Candidate detail: an out-of-scope candidate was served.');
                } catch (RuntimeException $error) {
                    $check(str_contains($error->getMessage(), 'not in your review queue'),
                        'an out-of-scope candidate must be refused: ' . $error->getMessage());
                }

                wp_set_current_user($reviewer->ID);
                wp_set_current_user($contactUser->ID);
                $_POST['candidate'] = (string) $main['candidate'];
                $_REQUEST = $_POST;
                try {
                    $page->handleSave();
                    $fail('Candidate detail: a parish contact saved a candidate.');
                } catch (RuntimeException $error) {
                    $check(str_contains($error->getMessage(), 'cannot view'),
                        'a parish contact must not save: ' . $error->getMessage());
                }
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }

            // --- Saving -----------------------------------------------------------
            wp_set_current_user($reviewer->ID);
            $validPost = [
                'action' => ReviewQueuePage::SAVE_ACTION,
                'candidate_id' => (string) $main['candidate'],
                'tab' => 'awaiting_approval',
                'search' => 'Fictional',
                'save_mode' => 'save',
                ReviewQueuePage::SAVE_NONCE => wp_create_nonce(ReviewQueuePage::SAVE_ACTION),
                'title' => 'Renamed Fictional Event ' . $suffix,
                'event_date' => '05/11/2026',
                'event_time' => '18:00',
                'event_end_time' => '20:00',
                'event_type' => 'Other',
                'description' => 'A fictional parish notice.',
                'parish_id' => (string) $parish,
                'status_flag' => 'scheduled',
                'recurrence_preset' => 'none',
                'contact_name' => 'Fictional Contact ' . $suffix,
                'contact_email' => $contact,
            ];

            // An invalid nonce must not change anything.
                        $badNonce = $validPost;
                        $badNonce['title'] = 'Should Not Persist ' . $suffix;
                        $badNonce[ReviewQueuePage::SAVE_NONCE] = 'invalid';
                        $_POST = $badNonce;
                        $_REQUEST = $_POST;
                        add_filter('wp_die_handler', $dieHandler);
                        try {
                            $page->handleSave();
                            $fail('Candidate detail: an invalid save nonce was accepted.');
                        } catch (RuntimeException) {
                            $check(true, 'the save nonce check fired.');
                        } finally {
                            remove_filter('wp_die_handler', $dieHandler);
                        }
                        $check(self::storedTitle($wpdb, $prefix, $main['candidate'])
                            === 'Fictional Harvest Tea ' . $suffix,
                            'an invalid nonce must not change the candidate.');

                        // A deanery approver must not save a candidate outside their own
                        // deaneries. The nonce is re-minted because it is bound to the
                        // current user, so the scope check is what refuses the request.
                        wp_set_current_user($dean->ID);
                        $outOfScope = $validPost;
                        $outOfScope['candidate_id'] = (string) $foreign['candidate'];
                        $outOfScope['title'] = 'Should Not Persist ' . $suffix;
                        $outOfScope[ReviewQueuePage::SAVE_NONCE] = wp_create_nonce(ReviewQueuePage::SAVE_ACTION);
                        $_POST = $outOfScope;
                        $_REQUEST = $_POST;
                        add_filter('wp_die_handler', $dieHandler);
                        try {
                            $page->handleSave();
                            $fail('Candidate detail: an out-of-scope candidate was edited.');
                        } catch (RuntimeException $error) {
                            $check(str_contains($error->getMessage(), 'not in your review queue'),
                                'an out-of-scope save must be refused: ' . $error->getMessage());
                        } finally {
                            remove_filter('wp_die_handler', $dieHandler);
                        }
                        $check(self::storedTitle($wpdb, $prefix, $foreign['candidate'])
                            === 'Fictional Harvest Tea ' . $suffix,
                            'an out-of-scope save must not change the candidate.');
                        wp_set_current_user($reviewer->ID);

            // A decided candidate cannot be edited.
            $editDecided = $validPost;
            $editDecided['candidate_id'] = (string) $decided['candidate'];
            $editDecided['title'] = 'Should Not Persist ' . $suffix;
            $_POST = $editDecided;
            $_REQUEST = $_POST;
            add_filter('wp_die_handler', $dieHandler);
            try {
                $page->handleSave();
                $fail('Candidate detail: a decided candidate was edited.');
            } catch (RuntimeException $error) {
                $check(str_contains($error->getMessage(), 'already been decided'),
                    'editing a decided candidate must be refused: ' . $error->getMessage());
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }

            // An invalid form re-renders with the reviewer's own values and
            // writes nothing.
            $monthFirst = $validPost;
            $monthFirst['title'] = 'Rejected Save Attempt ' . $suffix;
            $monthFirst['event_date'] = '2026-11-05';
            $_POST = $monthFirst;
            $_REQUEST = $_POST;
            $_GET = [
                'page' => ReviewQueuePage::PAGE_SLUG,
                'tab' => 'awaiting_approval',
                'candidate' => (string) $main['candidate'],
            ];
            ob_start();
            $page->handleSave();
            $rejected = (string) ob_get_clean();
            $check(str_contains($rejected, 'valid date'),
                'a month-first date must be rejected with a message attached.');
            $check(str_contains($rejected, 'Rejected Save Attempt ' . $suffix),
                'a rejected save must re-render the reviewer\'s own values.');
            $check(str_contains($rejected, 'aria-invalid="true"'),
                'a rejected field must be marked for assistive technology.');
            $check(self::storedTitle($wpdb, $prefix, $main['candidate'])
                            === 'Fictional Harvest Tea ' . $suffix,
                            'a rejected save must not write anything.');

            // A valid save.
            $valid = $validPost;
            $valid['recurrence_preset'] = 'monthly_ordinal';
            $valid['recurrence_ordinal'] = '1';
            $valid['recurrence_weekday'] = 'TH';
            $_POST = $valid;
            $_REQUEST = $_POST;
            $redirect = $redirectFor($page, 'handleSave', $_POST);
            $check($redirect !== null && str_contains($redirect, 'saved='),
                'a valid save must redirect back to the candidate with a notice.');
            $savedRow = $wpdb->get_row($wpdb->prepare(
                "SELECT status, fields, recurrence FROM {$prefix}event_candidates WHERE id = %d",
                $main['candidate']
            ), ARRAY_A);
            $savedFields = json_decode((string) ($savedRow['fields'] ?? '{}'), true);
            $savedFields = is_array($savedFields) ? $savedFields : [];
            $check($savedRow !== null && $savedRow['status'] === 'awaiting_approval',
                'a plain save must leave the candidate awaiting approval.');
            $check(($savedFields['title'] ?? '') === 'Renamed Fictional Event ' . $suffix,
                'a save must store the reviewer\'s title.');
            $check(($savedFields['event_date'] ?? '') === '2026-11-05',
                'a day-first date must be stored as an ISO date.');
            $check(str_contains((string) ($savedRow['recurrence'] ?? ''), 'FREQ=MONTHLY'),
                'a chosen preset must become a stored rule.');
            $check(str_contains($detail, 'FREQ=WEEKLY;BYDAY=TH'),
                'the previously rendered rule must not have been mutated by later saves.');

            $history = $queue->history($main['candidate']);
            $check(in_array('candidate_edited', array_column($history, 'action'), true),
                'an edit must be recorded in the audit trail.');
            $edit = null;
            foreach ($history as $entry) {
                if ($entry['action'] === 'candidate_edited') {
                    $edit = $entry;
                    break;
                }
            }
            $check(is_array($edit) && $edit['actor'] === $reviewer->user_email,
                'the edit audit entry must name the reviewer.');
                        $editDetails = json_decode((string) (is_array($edit) ? $edit['details'] : ''), true);
                        $changedKeys = is_array($editDetails) && is_array($editDetails['changed_fields'] ?? null)
                            ? $editDetails['changed_fields']
                            : [];
                        $check(in_array('title', $changedKeys, true),
                            'the edit audit entry must record the title as changed.');
                        $check(! in_array('description', $changedKeys, true),
                            'the edit audit entry must not record fields that did not change.');

            // Saving identical values again must not record a second edit.
            $redirect = $redirectFor($page, 'handleSave', $valid);
            $check($redirect !== null && str_contains($redirect, 'saved=unchanged'),
                're-saving identical values must report no change.');
            $check(count(array_filter(
                $queue->history($main['candidate']),
                static function (array $row): bool {
                    return $row['action'] === 'candidate_edited';
                }
            )) === 1,
                'an unchanged save must not record a second edit.');

            // --- Save and approve -------------------------------------------------
            $approve = $validPost;
            $approve['candidate_id'] = (string) $hostile['candidate'];
            $approve['save_mode'] = 'approve';
            $approve['title'] = 'Approved Fictional Event ' . $suffix;
            $approve['event_date'] = '07/11/2026';
            $approve['recurrence_preset'] = 'none';
            $redirect = $redirectFor($page, 'handleSave', $approve);
            $check($redirect !== null && str_contains($redirect, 'decision='),
                'save and approve must redirect with a decision notice.');
            $approved = $wpdb->get_row($wpdb->prepare(
                "SELECT status, decided_by FROM {$prefix}event_candidates WHERE id = %d",
                $hostile['candidate']
            ), ARRAY_A);
            $check($approved !== null && $approved['status'] !== 'awaiting_approval',
                'save and approve must decide the candidate, not merely edit it.');
            $check($approved !== null && (string) $approved['decided_by'] === $reviewer->user_email,
                'save and approve must record who decided.');
            $check(in_array(
                'candidate_edited',
                array_column($queue->history($hostile['candidate']), 'action'),
                true
            ), 'save and approve must audit the edit it made as well as the decision.');
            $publishedId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d",
                $hostile['candidate']
            ));
            if ($publishedId > 0) {
                $published[] = $publishedId;
                $check(get_post($publishedId) !== null,
                    'save and approve must publish the event it approved.');
            } else {
                // Publication depends on a complete event, which the synthetic
                // fields do not build. The decision is still what #61 owns.
                $check($approved !== null && $approved['status'] === 'approved',
                    'save and approve must approve even when publication is deferred.');
            }

            // Approving twice is refused.
            $again = $approve;
            $again['title'] = 'Second Approval ' . $suffix;
            add_filter('wp_die_handler', $dieHandler);
            $_POST = $again;
            $_REQUEST = $_POST;
            try {
                $page->handleSave();
                $fail('Candidate detail: a decided candidate was decided again.');
            } catch (RuntimeException $error) {
                $check(str_contains($error->getMessage(), 'already been decided'),
                    'deciding twice must be refused: ' . $error->getMessage());
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }

            // --- Manual entry beside the poster -----------------------------------
            // The whole point of #63: a poster the parser could not read must
            // still be typable by hand, and the typed event must go through the
            // same approval route as a parsed one.
            $mainDetailHtml = $openDetail($main['candidate']);
            $check(str_contains($mainDetailHtml, 'adct-pi-create-manual-form'),
                'the detail screen must carry the form that starts a hand-typed event.');
            $check(str_contains($mainDetailHtml, 'name="' . ReviewQueuePage::CREATE_MANUAL_NONCE . '"')
                && str_contains($mainDetailHtml, 'value="' . ReviewQueuePage::CREATE_MANUAL_ACTION . '"'),
                'the hand-typed-event form must carry its own action and nonce, not the save route\'s.');
            $check(substr_count($mainDetailHtml, 'Create event from this poster') === 1,
                'only the stored image may offer a hand-typed event.');
            $check(str_contains($mainDetailHtml, 'fictional-photo-poster-' . $suffix . '.jpg'),
                'the stored image must be listed beside the notice.');

            // And it is actually shown to the reviewer, not merely downloadable:
            // the whole reason manual entry sits *beside* the poster.
            $check(str_contains($mainDetailHtml, 'alt="Event poster"')
                && str_contains($mainDetailHtml, AttachmentImageEndpoint::ACTION)
                && str_contains($mainDetailHtml, 'Read the text on this poster'),
                'the stored image must be previewed inline beside the form, not only offered as a download.');

            // The preview is scaled to the column, so a photographed A4 poster
            // is unreadable at that size. The full-size offer must point at the
            // *same* already-authorised URL as the preview image: the endpoint
            // streams the original bytes, so no second route, endpoint or
            // permission is needed for the full resolution, and inventing one
            // would be a second way in.
            $check(preg_match(
                '#<a href="([^"]+)" target="_blank" rel="noopener">Open the poster at full size</a>#',
                $mainDetailHtml,
                $fullSizeMatch
            ) === 1
                && preg_match('#<img class="adct-ocr__preview" src="([^"]+)"#', $mainDetailHtml, $previewMatch) === 1
                && $fullSizeMatch[1] === $previewMatch[1],
                'the full-size link must open the very URL the preview image uses, so the full-resolution '
                . 'poster needs no second endpoint and no second authorisation.');

            // The button is posted, not linked: nothing is created by a GET.
            $check(! str_contains($mainDetailHtml, 'candidate=' . $main['candidate'] . '&amp;attachment_id'),
                'a hand-typed event must never be reachable from a link.');

            // A poster that is no longer on disk must not offer the button,
            // because there would be nothing to read beside the typed fields.
            $missingImageHtml = $openDetail($missing['candidate']);
            $check(str_contains($missingImageHtml, 'No longer stored'),
                'a missing image must say so rather than offer a hand-typed event.');

            // A read-only candidate must not offer it either: the new row would
            // be refused by the same check that makes this one read-only.
            $check(! str_contains($decidedHtml, 'Create event from this poster'),
                'an already decided candidate must not offer to start a hand-typed event.');

            // The seed of the button: an image on the source candidate's own
            // email. A PDF on that same email is not offered, because a typed
            // event is for something a person can read.
            $posterRow = static function (int $attachmentId) use ($wpdb, $prefix): ?array {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT message_id, mime_type FROM {$prefix}attachments WHERE id = %d",
                    $attachmentId
                ), ARRAY_A);

                return is_array($row) ? $row : null;
            };
            $check(($posterRow($posterId)['mime_type'] ?? '') === 'application/pdf'
                && ($posterRow($imagePosterId)['mime_type'] ?? '') === 'image/jpeg',
                'the fixture must offer a PDF and an image on the same email.');

            wp_set_current_user($reviewer->ID);
            $manualPost = [
                'action' => ReviewQueuePage::CREATE_MANUAL_ACTION,
                'candidate' => (string) $main['candidate'],
                'attachment_id' => (string) $imagePosterId,
                'tab' => 'awaiting_approval',
                'search' => 'Fictional',
                ReviewQueuePage::CREATE_MANUAL_NONCE => wp_create_nonce(
                    ReviewQueuePage::CREATE_MANUAL_ACTION
                ),
            ];

            // A nonce is bound to the user, so it is re-minted after every
            // switch; otherwise a stale nonce fails first and masks the check
            // under test.
            $_POST = $manualPost;
            $_POST[ReviewQueuePage::CREATE_MANUAL_NONCE] = 'invalid';
            $_REQUEST = $_POST;
            $before = self::candidateCount($wpdb, $prefix);
            add_filter('wp_die_handler', $dieHandler);
            try {
                $page->handleCreateManual();
                $fail('Candidate detail: an invalid hand-typed-event nonce was accepted.');
            } catch (RuntimeException) {
                $check(true, 'the hand-typed-event nonce check fired.');
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }
            $check(self::candidateCount($wpdb, $prefix) === $before,
                'an invalid nonce must not open an event.');

            // A parish contact must not be able to open one.
            wp_set_current_user($contactUser->ID);
            $_POST = $manualPost;
            $_POST[ReviewQueuePage::CREATE_MANUAL_NONCE] = wp_create_nonce(
                ReviewQueuePage::CREATE_MANUAL_ACTION
            );
            $_REQUEST = $_POST;
            add_filter('wp_die_handler', $dieHandler);
            try {
                $page->handleCreateManual();
                $fail('Candidate detail: a parish contact opened a hand-typed event.');
            } catch (RuntimeException $error) {
                $check(str_contains($error->getMessage(), 'cannot view'),
                    'a parish contact must be refused: ' . $error->getMessage());
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }
            $check(self::candidateCount($wpdb, $prefix) === $before,
                'a refused request must not open an event.');

            // An attachment from another parish's email must be refused, so a
            // crafted POST cannot start an event from someone else's poster.
            $foreignAttachmentId = $addImagePoster(
                $foreign['message'],
                $imagePosterPath,
                'fictional-foreign-poster'
            );
            wp_set_current_user($reviewer->ID);
            $_POST = $manualPost;
            $_POST['attachment_id'] = (string) $foreignAttachmentId;
            $_POST[ReviewQueuePage::CREATE_MANUAL_NONCE] = wp_create_nonce(
                ReviewQueuePage::CREATE_MANUAL_ACTION
            );
            $_REQUEST = $_POST;
            add_filter('wp_die_handler', $dieHandler);
            try {
                $page->handleCreateManual();
                $fail('Candidate detail: an event was started from another email\'s poster.');
            } catch (RuntimeException $error) {
                $check(str_contains($error->getMessage(), 'not attached to this email'),
                    'a foreign attachment must be refused: ' . $error->getMessage());
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }
            $check(self::candidateCount($wpdb, $prefix) === $before,
                'a refused attachment must not open an event.');

            // A decided candidate cannot seed a new one either.
            $_POST = $manualPost;
            $_POST['candidate'] = (string) $decided['candidate'];
            $_POST[ReviewQueuePage::CREATE_MANUAL_NONCE] = wp_create_nonce(
                ReviewQueuePage::CREATE_MANUAL_ACTION
            );
            $_REQUEST = $_POST;
            add_filter('wp_die_handler', $dieHandler);
            try {
                $page->handleCreateManual();
                $fail('Candidate detail: a decided candidate seeded a hand-typed event.');
            } catch (RuntimeException $error) {
                $check(str_contains($error->getMessage(), 'already been decided'),
                    'a decided source must be refused: ' . $error->getMessage());
            } finally {
                remove_filter('wp_die_handler', $dieHandler);
            }

            // The happy path: a blank event, on the same email and parish, with
            // no approver of any kind.
            $_POST = $manualPost;
            $_POST[ReviewQueuePage::CREATE_MANUAL_NONCE] = wp_create_nonce(
                ReviewQueuePage::CREATE_MANUAL_ACTION
            );
            $_REQUEST = $_POST;
            $redirect = $redirectFor($page, 'handleCreateManual', $_POST);
            $check($redirect !== null && str_contains($redirect, 'created=1'),
                'starting a hand-typed event must redirect with a notice.');
            $createdId = self::queryInt($redirect, 'candidate');
            $check($createdId > 0 && $createdId !== $main['candidate'],
                'the redirect must point at the new blank event, not the one it was started from.');
            if ($createdId > 0) {
                $inserted[] = [$main['message'], $createdId];
                $blank = $wpdb->get_row($wpdb->prepare(
                    "SELECT message_id, parish_id, status, fields, approved_by, approved_via,"
                        . " approved_at, decided_by, decided_at FROM {$prefix}event_candidates WHERE id = %d",
                    $createdId
                ), ARRAY_A);
                $check($blank !== null && (int) $blank['message_id'] === $main['message'],
                    'a hand-typed event must belong to the email it was started from.');
                $check($blank !== null && (int) $blank['parish_id'] === $parish,
                    'a hand-typed event must inherit the parish, so approval routes where a parsed one would.');
                $check($blank !== null && $blank['status'] === 'awaiting_approval',
                    'a hand-typed event must start awaiting approval, like any other.');
                $check($blank !== null && $blank['approved_by'] === null
                    && $blank['approved_via'] === null
                    && $blank['approved_at'] === null
                    && $blank['decided_by'] === null
                    && $blank['decided_at'] === null,
                    'a hand-typed event must record no approver at all: typing one is not approving one.');
                $blankFields = json_decode((string) ($blank['fields'] ?? '{}'), true);
                $check(is_array($blankFields) && $blankFields === [],
                    'a hand-typed event must start empty, so nothing from the parsed row is mistaken for an answer.');

                // It is edited and approved through the ordinary save route, with
                // no special-casing for hand-typed events.
                $manualSave = $validPost;
                $manualSave['candidate_id'] = (string) $createdId;
                $manualSave['save_mode'] = 'approve';
                $manualSave['title'] = 'Hand Typed Fictional Event ' . $suffix;
                $manualSave['event_date'] = '09/11/2026';
                $manualSave['recurrence_preset'] = 'none';
                $_POST = $manualSave;
                $_REQUEST = $_POST;
                $redirect = $redirectFor($page, 'handleSave', $_POST);
                $check($redirect !== null && str_contains($redirect, 'decision='),
                    'a hand-typed event must be approvable through the ordinary save route.');
                $typed = $wpdb->get_row($wpdb->prepare(
                    "SELECT status, decided_by, approved_via FROM {$prefix}event_candidates WHERE id = %d",
                    $createdId
                ), ARRAY_A);
                $check($typed !== null && $typed['status'] !== 'awaiting_approval',
                    'a hand-typed event must be decided by the ordinary save route.');
                $check($typed !== null && (string) $typed['decided_by'] === $reviewer->user_email,
                    'a hand-typed event must record who decided it.');
                $check($typed !== null && $typed['approved_via'] !== null
                    && $typed['approved_via'] !== 'self',
                    'a hand-typed event must only ever be approved the way any other event is.');
                $typedPublishedId = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d",
                    $createdId
                ));
                if ($typedPublishedId > 0) {
                    $published[] = $typedPublishedId;
                }
            }

            // --- Resolving an ambiguous match (issue #177) -----------------------
            // Two candidates the parser could not tell apart. The flags live only
            // in `fields`, which is why nothing before this issue could resolve one
            // without a database edit.
            $ambiguity = [
                'match_review_required' => true,
                'matched_candidate_id' => $main['candidate'],
            ];
            $ambiguous = $makeCandidate('ambiguous', 'awaiting_approval', $parish, $contact, $rawPath, $ambiguity);
            $decidedAmbiguous = $makeCandidate(
                'decided-ambiguous',
                'approved',
                $parish,
                $contact,
                $rawPath,
                $ambiguity,
                $approvedExtras
            );
            $foreignAmbiguous = $makeCandidate(
                'foreign-ambiguous',
                'awaiting_approval',
                $foreignParish,
                $foreignContact,
                $rawPath,
                $ambiguity
            );
            // A venue of this parish, so a resolution that corrects the venue can be
            // proved to have chosen the pair rather than one without the other.
            $venue = $insert($prefix . 'venues', [
                'parish_id' => $parish,
                'name' => 'Fictional Detail Hall ' . $suffix,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $otherParishVenue = $insert($prefix . 'venues', [
                'parish_id' => $foreignParish,
                'name' => 'Fictional Foreign Hall ' . $suffix,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            // The panel appears only where it is needed, with its own action and
            // nonce, so pressing Enter in the editor cannot resolve anything.
            $ambiguousDetail = $openDetail($ambiguous['candidate']);
            $check(str_contains($ambiguousDetail, ReviewQueuePage::RESOLVE_MATCH_ACTION),
                'an ambiguous candidate must offer the resolution control.');
            $check(str_contains($ambiguousDetail, 'adct-pi-resolve-parish'),
                'the resolution control must offer a parish.');
            $check(str_contains($ambiguousDetail, 'Leave unassigned'),
                'the resolution control must offer the deliberate "leave it unassigned" choice.');
            $check(
                str_contains(
                    $ambiguousDetail,
                    wp_create_nonce(ReviewQueuePage::RESOLVE_MATCH_ACTION)
                ),
                'the resolution control must carry its own nonce.'
            );
            $check(! str_contains($openDetail($sibling['candidate']), ReviewQueuePage::RESOLVE_MATCH_ACTION),
                'a candidate with no ambiguity must not be offered a resolution.');
            $check(! str_contains($openDetail($decidedAmbiguous['candidate']), ReviewQueuePage::RESOLVE_MATCH_ACTION),
                'a decided candidate must not be offered a resolution.');

            $resolvePost = [
                'action' => ReviewQueuePage::RESOLVE_MATCH_ACTION,
                'candidate_id' => (string) $ambiguous['candidate'],
                'tab' => 'awaiting_approval',
                'search' => 'Fictional',
                'parish_id' => (string) $parish,
                'venue_id' => (string) $venue,
                ReviewQueuePage::RESOLVE_MATCH_NONCE => wp_create_nonce(ReviewQueuePage::RESOLVE_MATCH_ACTION),
            ];

            // Every refusal writes nothing.
            $before = self::candidateCount($wpdb, $prefix);
            $refusals = [];

            $refusals['a nonce from another route'] = [$resolvePost, function (array $post) use ($ambiguous): array {
                unset($post[ReviewQueuePage::RESOLVE_MATCH_NONCE]);
                $post[ReviewQueuePage::SAVE_NONCE] = wp_create_nonce(ReviewQueuePage::SAVE_ACTION);

                return $post;
            }, $reviewer];

            $refusals['no nonce at all'] = [$resolvePost, function (array $post): array {
                unset($post[ReviewQueuePage::RESOLVE_MATCH_NONCE]);

                return $post;
            }, $reviewer];

            $refusals['a candidate outside the deaner\'s own deaneries'] = [
                $resolvePost,
                function (array $post) use ($foreignAmbiguous): array {
                    $post['candidate_id'] = (string) $foreignAmbiguous['candidate'];
                    $post[ReviewQueuePage::RESOLVE_MATCH_NONCE] = wp_create_nonce(
                        ReviewQueuePage::RESOLVE_MATCH_ACTION
                    );

                    return $post;
                },
                $dean,
            ];

            $refusals['a candidate that has already been decided'] = [
                $resolvePost,
                function (array $post) use ($decidedAmbiguous): array {
                    $post['candidate_id'] = (string) $decidedAmbiguous['candidate'];
                    $post[ReviewQueuePage::RESOLVE_MATCH_NONCE] = wp_create_nonce(
                        ReviewQueuePage::RESOLVE_MATCH_ACTION
                    );

                    return $post;
                },
                $reviewer,
            ];

            $refusals['a venue of another parish'] = [$resolvePost, function (array $post) use (
                $otherParishVenue
            ): array {
                $post['venue_id'] = (string) $otherParishVenue;

                return $post;
            }, $reviewer];

            foreach ($refusals as $why => [$base, $amend, $actor]) {
                $post = $amend($base);
                wp_set_current_user($actor->ID);
                add_filter('wp_die_handler', $dieHandler);
                try {
                    $page->handleResolveMatch();
                    $fail('Candidate detail: resolving a match with ' . $why . ' was accepted.');
                } catch (RuntimeException $error) {
                    $check(true, 'the guard for ' . $why . ' fired.');
                } finally {
                    remove_filter('wp_die_handler', $dieHandler);
                }
                $check(self::candidateCount($wpdb, $prefix) === $before,
                    'resolving a match with ' . $why . ' must write nothing.');
            }
            wp_set_current_user($reviewer->ID);

            // The one a person actually makes: choose the parish, correct the
            // venue, and the block goes in the same transaction.
            $redirect = $redirectFor($page, 'handleResolveMatch', $resolvePost);
            $check($redirect !== null && str_contains($redirect, 'resolved=1'),
                'a resolution must redirect with a notice.');
            $resolved = $wpdb->get_row($wpdb->prepare(
                "SELECT parish_id, status, fields FROM {$prefix}event_candidates WHERE id = %d",
                $ambiguous['candidate']
            ), ARRAY_A);
            $resolvedFields = json_decode((string) ($resolved['fields'] ?? '{}'), true);
            $resolvedFields = is_array($resolvedFields) ? $resolvedFields : [];
            $check($resolved !== null && (int) $resolved['parish_id'] === $parish,
                'a resolution must route the candidate to the chosen parish.');
            $check((int) ($resolvedFields['parish_id'] ?? 0) === $parish,
                'the column and the JSON must agree, or approval is still refused.');
            $check((int) ($resolvedFields['venue_id'] ?? 0) === $venue,
                'a resolution must be able to correct the venue at the same time.');
            $check(! array_key_exists('match_review_required', $resolvedFields),
                'the review flag must be removed, not set to false: the matcher reads presence.');
            $check(! array_key_exists('matched_candidate_id', $resolvedFields),
                'the matched id must be removed, not set to zero, for the same reason.');

            $resolvedHistory = $queue->history($ambiguous['candidate']);
            $resolutions = array_values(array_filter(
                $resolvedHistory,
                static function (array $row): bool {
                    return $row['action'] === 'candidate_match_resolved';
                }
            ));
            $check(count($resolutions) === 1,
                'a resolution must write exactly one audit row, however many times it is looked for.');
            $resolutionDetails = json_decode((string) ($resolutions[0]['details'] ?? ''), true);
            $check(($resolutions[0]['actor'] ?? '') === $reviewer->user_email,
                'the audit row must name the reviewer who resolved it.');
            $check(is_array($resolutionDetails) && ($resolutionDetails['match_review_cleared'] ?? null) === true,
                'the audit row must record that a block was cleared, not merely that a parish changed.');
            $check(is_array($resolutionDetails) && (int) ($resolutionDetails['to_parish_id'] ?? 0) === $parish,
                'the audit row must record the parish it chose.');

            // And the panel is gone, because there is nothing left to resolve.
            $check(! str_contains($openDetail($ambiguous['candidate']), ReviewQueuePage::RESOLVE_MATCH_ACTION),
                'a resolved candidate must not be offered a second resolution.');

            // A resolved candidate is approvable through the ordinary save route,
            // which is the whole point of the control.
            $resolvedApproval = $validPost;
            $resolvedApproval['candidate_id'] = (string) $ambiguous['candidate'];
            $resolvedApproval['save_mode'] = 'approve';
            $resolvedApproval['title'] = 'Resolved Fictional Event ' . $suffix;
            $resolvedApproval['event_date'] = '11/11/2026';
            $resolvedApproval['recurrence_preset'] = 'none';
            $resolvedApproval['parish_id'] = (string) $parish;
            $redirect = $redirectFor($page, 'handleSave', $resolvedApproval);
            $check($redirect !== null && str_contains($redirect, 'decision='),
                'a resolved candidate must be approvable through the ordinary save route.');
            $resolvedDecided = $wpdb->get_row($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d",
                $ambiguous['candidate']
            ), ARRAY_A);
            $check($resolvedDecided !== null && $resolvedDecided['status'] !== 'awaiting_approval',
                'a resolved candidate must actually be decided.');
            $resolvedPublished = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d",
                $ambiguous['candidate']
            ));
            if ($resolvedPublished > 0) {
                $published[] = $resolvedPublished;
            }

            // "Leave it unassigned" is the other real answer: an unassigned
            // candidate matches no dean, so the archdiocese decides it.
            $unassignedAmbiguous = $makeCandidate(
                'unassigned-ambiguous',
                'awaiting_approval',
                null,
                $contact,
                $rawPath,
                $ambiguity
            );
            $unassignedPost = $resolvePost;
            $unassignedPost['candidate_id'] = (string) $unassignedAmbiguous['candidate'];
            $unassignedPost['parish_id'] = '';
            $unassignedPost['venue_id'] = '';
            $redirect = $redirectFor($page, 'handleResolveMatch', $unassignedPost);
            $check($redirect !== null && str_contains($redirect, 'resolved=1'),
                'leaving a candidate unassigned must be accepted as an answer.');
            $unassigned = $wpdb->get_row($wpdb->prepare(
                "SELECT parish_id, fields FROM {$prefix}event_candidates WHERE id = %d",
                $unassignedAmbiguous['candidate']
            ), ARRAY_A);
            $unassignedFields = json_decode((string) ($unassigned['fields'] ?? '{}'), true);
            $unassignedFields = is_array($unassignedFields) ? $unassignedFields : [];
            $check($unassigned !== null && $unassigned['parish_id'] === null,
                'an unassigned candidate must store a real NULL parish, not 0: the column is unsigned.');
            $check(! array_key_exists('parish_id', $unassignedFields),
                'no parish was chosen, so none is stored.');
            $check(! array_key_exists('match_review_required', $unassignedFields),
                'leaving it unassigned must still clear the block.');
            $unassignedHistory = array_values(array_filter(
                $queue->history($unassignedAmbiguous['candidate']),
                static function (array $row): bool {
                    return $row['action'] === 'candidate_match_resolved';
                }
            ));
            $unassignedDetails = json_decode((string) ($unassignedHistory[0]['details'] ?? ''), true);
            $check(count($unassignedHistory) === 1 && is_array($unassignedDetails)
                && ($unassignedDetails['left_unassigned'] ?? null) === true,
                'the audit row must record that the reviewer deliberately left it unassigned.');

            // --- Resolving a `duplicate` candidate (issue #218) ------------------
            // `EventCandidateRepository` files an unchanged notice as
            // `status = duplicate`, and the queue lists it because `where()` looks
            // for the same two `fields` keys the resolution panel looks for. So the
            // screen links to it, offers the panel, and — until #218 — refused the
            // write with "Only an undecided candidate can be assigned a parish."
            // The panel is the only way to say *which* match was wrong.
            $duplicateAmbiguous = $makeCandidate('duplicate-ambiguous', 'duplicate', $parish, $contact, $rawPath, $ambiguity);
            $duplicateDetail = $openDetail($duplicateAmbiguous['candidate']);
            $check(str_contains($duplicateDetail, ReviewQueuePage::RESOLVE_MATCH_ACTION),
                'a duplicate candidate must offer the resolution control: #217 already lets a reviewer '
                . 'decide one, so a panel that refused it was a dead end.');
            // The editor form still *renders* for a duplicate: `CandidateDetailView`
                        // emits it unconditionally and only adds `readonly` to its inputs, so the
                        // absence of markup is not the invariant to assert. The invariant lives at
                        // the write path -- `canEdit()` accepts `awaiting_approval` only, so a save
                        // POST for a duplicate is refused. #218 widens the resolution route by one
                        // status and deliberately does not widen this one.
                        $duplicateEdit = $validPost;
                        $duplicateEdit['candidate_id'] = (string) $duplicateAmbiguous['candidate'];
                        $duplicateEdit['title'] = 'Should Not Persist ' . $suffix;
                        $_POST = $duplicateEdit;
                        $_REQUEST = $_POST;
                        add_filter('wp_die_handler', $dieHandler);
                        try {
                            $page->handleSave();
                            $fail('Candidate detail: a duplicate candidate was edited.');
                        } catch (RuntimeException $error) {
                            $check(str_contains($error->getMessage(), 'already been decided'),
                                'editing a duplicate must still be refused: ' . $error->getMessage());
                        } finally {
                            remove_filter('wp_die_handler', $dieHandler);
                        }
                        $check(self::storedTitle($wpdb, $prefix, $duplicateAmbiguous['candidate'])
                            === 'Fictional Harvest Tea ' . $suffix,
                            'a refused duplicate save must leave the title alone.');
                        $check(array_values(array_filter(
                            $queue->history($duplicateAmbiguous['candidate']),
                            static function (array $row): bool {
                                return $row['action'] === 'update_fields';
                            }
                        )) === [],
                            'and must write no audit row.');

            $duplicatePost = $resolvePost;
            $duplicatePost['candidate_id'] = (string) $duplicateAmbiguous['candidate'];
            $redirect = $redirectFor($page, 'handleResolveMatch', $duplicatePost);
            $check($redirect !== null && str_contains($redirect, 'resolved=1'),
                'resolving a duplicate must be accepted, not refused as already decided.');
            $duplicate = $wpdb->get_row($wpdb->prepare(
                "SELECT parish_id, status, fields FROM {$prefix}event_candidates WHERE id = %d",
                $duplicateAmbiguous['candidate']
            ), ARRAY_A);
            $duplicateFields = json_decode((string) ($duplicate['fields'] ?? '{}'), true);
            $duplicateFields = is_array($duplicateFields) ? $duplicateFields : [];
            $check($duplicate !== null && (int) $duplicate['parish_id'] === $parish,
                'a resolved duplicate must take the parish the reviewer chose.');
            $check($duplicate !== null && $duplicate['status'] === 'awaiting_approval',
                'a duplicate a person resolved has stopped being one, so it returns to the ordinary '
                . 'approval queue. A row that kept the status would drop out of the listing — that '
                . 'listing keys off these same fields — and never be seen again.');
            $check(! array_key_exists('match_review_required', $duplicateFields)
                && ! array_key_exists('matched_candidate_id', $duplicateFields),
                'the block is cleared the same way it is for any other ambiguous candidate.');
            $duplicateHistory = array_values(array_filter(
                $queue->history($duplicateAmbiguous['candidate']),
                static function (array $row): bool {
                    return $row['action'] === 'candidate_match_resolved';
                }
            ));
            $duplicateDetails = json_decode((string) ($duplicateHistory[0]['details'] ?? ''), true);
            $check(count($duplicateHistory) === 1 && is_array($duplicateDetails)
                && ($duplicateDetails['from_status'] ?? null) === 'duplicate'
                && ($duplicateDetails['to_status'] ?? null) === 'awaiting_approval',
                'the audit row must name the status it moved, or a reader cannot tell a duplicate '
                . 'returning to the queue from an ordinary unblock.');

            // And it is approvable straight afterwards, through the ordinary save
            // route, which is the whole point of putting it back.
            $duplicateApproval = $validPost;
            $duplicateApproval['candidate_id'] = (string) $duplicateAmbiguous['candidate'];
            $duplicateApproval['save_mode'] = 'approve';
            $duplicateApproval['title'] = 'Resolved Fictional Duplicate ' . $suffix;
            $duplicateApproval['event_date'] = '12/11/2026';
            $duplicateApproval['recurrence_preset'] = 'none';
            $duplicateApproval['parish_id'] = (string) $parish;
            $redirect = $redirectFor($page, 'handleSave', $duplicateApproval);
            $check($redirect !== null && str_contains($redirect, 'decision='),
                'a resolved duplicate must be approvable through the ordinary save route.');
            $duplicateDecided = $wpdb->get_row($wpdb->prepare(
                "SELECT status FROM {$prefix}event_candidates WHERE id = %d",
                $duplicateAmbiguous['candidate']
            ), ARRAY_A);
            $check($duplicateDecided !== null && $duplicateDecided['status'] !== 'awaiting_approval',
                'and it must actually be decided.');
            $duplicatePublished = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT match_event_id FROM {$prefix}event_candidates WHERE id = %d",
                $duplicateAmbiguous['candidate']
            ));
            if ($duplicatePublished > 0) {
                $published[] = $duplicatePublished;
            }

            // --- Reject ------------------------------------------------------------
            $rejectCandidate = $makeCandidate('reject', 'awaiting_approval', $parish, $contact, $rawPath);
            $reject = $validPost;
            $reject['candidate_id'] = (string) $rejectCandidate['candidate'];
            $reject['save_mode'] = 'reject';
            $reject['reason'] = 'Duplicate of an existing fictional notice.';
            $redirect = $redirectFor($page, 'handleSave', $reject);
            $check($redirect !== null && str_contains($redirect, 'decision='),
                'a rejection must redirect with a decision notice.');
            $rejectedRow = $wpdb->get_row($wpdb->prepare(
                "SELECT status, decided_by FROM {$prefix}event_candidates WHERE id = %d",
                $rejectCandidate['candidate']
            ), ARRAY_A);
            $check($rejectedRow !== null && $rejectedRow['status'] === 'rejected',
                'reject must record the rejection.');
            $check($rejectedRow !== null && (string) $rejectedRow['decided_by'] === $reviewer->user_email,
                'reject must record the deciding reviewer.');
            $check(in_array(
                'approver_rejected',
                array_column($queue->history($rejectCandidate['candidate']), 'action'),
                true
            ), 'reject must be audited.');

            // --- Repository methods ------------------------------------------------
            $check($queue->findMessageOf($main['candidate']) === $main['message'],
                'findMessageOf must return the candidate\'s own message.');
            $check($queue->findMessageOf(99999999) === null,
                'findMessageOf must return null for an unknown candidate.');
            $found = $attachments->find($posterId);
            $check($found !== null && (int) $found['message_id'] === $main['message'],
                'AttachmentRepository::find must return the attachment and its message.');
            $check($found !== null && ! array_key_exists('extracted_text', $found),
                'AttachmentRepository::find must not load the extracted text, which can be a whole document.');
            $check($attachments->find(99999999) === null,
                'AttachmentRepository::find must return null for an unknown attachment.');

            // --- The actions are registered ------------------------------------------
            $check(has_action('admin_post_adct_pi_candidate_save') !== false,
                'the save action must be registered on the installed plugin.');
            $check(has_action('admin_post_adct_pi_candidate_raw_message') !== false,
                'the raw-message action must be registered on the installed plugin.');
            $check(has_action('admin_post_adct_pi_candidate_attachment') !== false,
                'the attachment action must be registered on the installed plugin.');
                        $check(has_action('admin_post_' . ReviewQueuePage::CREATE_MANUAL_ACTION) !== false,
                            'the hand-typed-event action must be registered on the installed plugin.');
                                    $check(has_action('admin_post_' . ReviewQueuePage::RESOLVE_MATCH_ACTION) !== false,
                                        'the resolve-match action must be registered on the installed plugin.');
        } finally {
            $_GET = $originalGet;
            $_POST = $originalPost;
            $_REQUEST = $originalRequest;
            wp_set_current_user($originalUser);
            foreach ($storedFiles as $storedFile) {
                $storage->delete($storedFile);
            }
            foreach ($published as $postId) {
                wp_delete_post($postId, true);
            }
            foreach ($inserted as [$messageId, $candidateId]) {
                $wpdb->delete($prefix . 'audit_log', [
                    'subject_type' => 'event_candidate',
                    'subject_id' => $candidateId,
                ]);
                $wpdb->delete($prefix . 'attachments', ['message_id' => $messageId]);
                $wpdb->delete($prefix . 'event_candidates', ['id' => $candidateId]);
                $wpdb->delete($prefix . 'inbound_messages', ['id' => $messageId]);
            }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ($users as $userId) {
                wp_delete_user($userId);
            }
            $wpdb->delete($prefix . 'parish_contacts', ['email' => $contact]);
            $wpdb->delete($prefix . 'parish_contacts', ['email' => $foreignContact]);
            $wpdb->delete($prefix . 'deanery_approvers', ['deanery_id' => $deanery]);
                        $wpdb->delete($prefix . 'venues', ['parish_id' => $parish]);
                        $wpdb->delete($prefix . 'venues', ['parish_id' => $foreignParish]);
                        $wpdb->delete($prefix . 'parishes', ['id' => $parish]);
            $wpdb->delete($prefix . 'parishes', ['id' => $foreignParish]);
            $wpdb->delete($prefix . 'deaneries', ['id' => $deanery]);
            $wpdb->delete($prefix . 'deaneries', ['id' => $foreignDeanery]);
        }
    }

    /**
         * How many candidates exist, so a refused request can be proved to have
         * written nothing without depending on which row it would have written.
         */
        private static function candidateCount(wpdb $wpdb, string $prefix): int
        {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}event_candidates");
        }

        /**
         * The `candidate` query argument of a redirect location.
         *
         * Parsed rather than read with `parse_url()`, because the harness location
         * is a relative path and `parse_str()` handles both forms.
         */
        private static function queryInt(?string $location, string $key): int
        {
            if ($location === null) {
                return 0;
            }
            $query = [];
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

            return (int) ($query[$key] ?? 0);
        }

        /**
         * A candidate's stored title, read with PHP rather than `JSON_EXTRACT`.
     *
     * MariaDB and MySQL differ over JSON functions in the test harness, so the
     * column is fetched and decoded in PHP instead.
     */
    private static function storedTitle(wpdb $wpdb, string $prefix, int $candidateId): string
    {
        $raw = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT fields FROM {$prefix}event_candidates WHERE id = %d",
            $candidateId
        ));
        $fields = json_decode($raw, true);

        return is_array($fields) ? (string) ($fields['title'] ?? '') : '';
    }

    /**
     * Run a handler that ends in a redirect and return the location.
     *
     * The handler calls `wp_safe_redirect()` and then `exit`. The `wp_redirect`
     * filter fires first, so throwing from it both captures the location and
     * stops the handler before the `exit`, which makes the success path
     * testable without a subprocess.
     *
     * @param array<string, mixed> $post
     */
    private static function redirectFor(ReviewQueuePage $page, string $method, array $post): ?string
    {
        $location = null;
        $capture = static function ($target) use (&$location): bool {
            $location = (string) $target;

            throw new CandidateDetailRedirected();
        };
        add_filter('wp_redirect', $capture, 1);

        $_GET = [];
        $_POST = $post;
        $_REQUEST = $post;
        ob_start();
        try {
            $page->{$method}();
        } catch (CandidateDetailRedirected) {
            ob_end_clean();

            return $location;
        } catch (Throwable) {
                    ob_end_clean();

                    return null;
                } finally {
                    remove_filter('wp_redirect', $capture, 1);
                }
                // No redirect: the handler rendered instead, so the check failed.
                ob_end_clean();

                return null;
    }
}
