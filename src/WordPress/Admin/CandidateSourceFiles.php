<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Publishing\SourceAttachment;

/**
 * The source material behind a candidate: the email a parish sent and the files
 * attached to it.
 *
 * Everything rendered here is parish-supplied and untrusted, so it is escaped on
 * the way out and never interpolated into markup. Downloads go through their own
 * POST actions with a nonce, so a reviewer never follows a link to a stored file.
 */
final class CandidateSourceFiles
{
    /**
     * The id of the hidden form that carries a promote button to its handler.
     *
     * A button outside any form would post to the current URL, which is the
     * review queue's *display* action rather than the promote action -- the bug
     * this indirection exists to make impossible. The form itself is rendered
     * by `CandidateDetailView`, once per screen, so that the nonce is printed
     * once rather than once per row.
     */
    public const PROMOTE_FORM = 'adct-pi-promote-source-form';

    /**
     * @param array<string, mixed>|null $message
     * @param list<array<string, mixed>> $attachments
     * @param callable(string): bool|null $isDownloadable tells the view whether a
     *        stored name still resolves to a real file, so a Download button is
     *        only offered when the request would actually succeed.
     * @param bool $canStartManual whether to offer opening a blank event to type in
     *        from one of these files. A PDF never offers it: a typed-in event is
     *        for a poster or photograph a person can read, and every notice has
     *        a PDF if this were not limited to images.
     * @param bool $canPromote whether to offer publishing a stored file *with*
     *        the event (issue #172). False renders no control at all rather than
     *        a disabled one, so no button on the screen looks live and is not.
     *        Nothing is ever promoted without this being pressed: the offer is a
     *        button, and the promotion is a separate POST with its own action
     *        and nonce, well away from the review/publish form.
     */
    public function render(
        ?array $message,
        array $attachments,
        ?callable $isDownloadable = null,
        bool $canStartManual = false,
        bool $canPromote = false
    ): void {
        ?>
        <h2>Source email and attachments</h2>
        <p class="description">
            This is what the parish sent. Check the notice against the details before approving:
            the parser can only read what it was given.
        </p>
        <?php $this->renderMessage($message); ?>
        <h3>Attachments (<?php echo esc_html((string) count($attachments)); ?>)</h3>
        <?php if ($attachments === []) : ?>
            <p>This email had no attachments.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col">File</th><th scope="col">Type</th><th scope="col">Size</th>
                    <th scope="col">Extracted by</th><th scope="col">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($attachments as $attachment) : ?>
                    <?php $this->attachmentRow($attachment, $isDownloadable, $canStartManual, $canPromote); ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /** @param array<string, mixed>|null $message */
    private function renderMessage(?array $message): void
    {
        if ($message === null) {
            ?>
            <p>This candidate is not linked to a stored email. It may have been entered by hand.</p>
            <?php

            return;
        }

        $body = (string) ($message['body_text'] ?? '');
        ?>
        <h3>Email</h3>
        <ul class="adct-pi-source-meta">
            <li><strong>From</strong>:
                <?php if (trim((string) ($message['sender_name'] ?? '')) !== '') : ?>
                    <?php echo esc_html((string) $message['sender_name'] . ' <' . (string) ($message['sender_email'] ?? '') . '>'); ?>
                <?php else : ?>
                    <?php echo esc_html((string) ($message['sender_email'] ?: 'No inbound sender')); ?>
                <?php endif; ?>
            </li>
            <li><strong>Received</strong>: <?php echo esc_html((string) ($message['received_at'] ?? 'unknown')); ?> UTC</li>
            <?php if (trim((string) ($message['subject'] ?? '')) !== '') : ?>
                <li><strong>Subject</strong>: <?php echo esc_html((string) $message['subject']); ?></li>
            <?php endif; ?>
            <li><strong>Mailbox status</strong>: <?php echo esc_html((string) ($message['status'] ?? 'unknown')); ?></li>
        </ul>
        <p>
            <button type="submit" class="button" form="adct-pi-raw-message-form" formnovalidate>
                Download the original message
            </button>
        </p>
        <?php if (trim($body) === '') : ?>
            <p>No body text was extracted from this email.</p>
        <?php else : ?>
            <pre class="adct-pi-source-body"><?php echo esc_html($body); ?></pre>
        <?php endif; ?>
        <?php
    }

    /** @param array<string, mixed> $attachment */
        private function attachmentRow(
            array $attachment,
            ?callable $isDownloadable,
            bool $canStartManual,
            bool $canPromote
        ): void {
            $id = (int) ($attachment['id'] ?? 0);
            $method = trim((string) ($attachment['extraction_method'] ?? ''));
            $path = trim((string) ($attachment['storage_path'] ?? ''));
            $stored = $id > 0 && $path !== ''
                && ($isDownloadable === null || $isDownloadable($path));
            $mimeType = strtolower(trim((string) ($attachment['mime_type'] ?? '')));
            $poster = $id > 0 && str_starts_with($mimeType, 'image/');
            // Whether the file may be published is a property of its type, not of
            // whether it happens to be stored: HEIC and HEIF are accepted at intake
            // and render in no browser, so offering one would publish a link that is
            // broken for every visitor who follows it.
            $publishable = $stored && SourceAttachment::isPromotableMimeType($mimeType);
            ?>
            <tr>
                <td><?php echo esc_html((string) ($attachment['filename'] ?? '(unnamed)')); ?></td>
                <td><?php echo esc_html($mimeType === '' ? 'application/octet-stream' : $mimeType); ?></td>
                <td><?php echo esc_html($this->size((int) ($attachment['size_bytes'] ?? 0))); ?></td>
                <td><?php echo esc_html($method === '' ? 'not extracted' : $method); ?></td>
                <td>
                    <?php if ($stored) : ?>
                        <button type="submit" class="button" form="adct-pi-attachment-form"
                            name="attachment_id" value="<?php echo esc_attr((string) $id); ?>" formnovalidate>
                            Download
                        </button>
                        <?php if ($canStartManual && $poster) : ?>
                            <button type="submit" class="button button-secondary" form="adct-pi-create-manual-form"
                                name="attachment_id" value="<?php echo esc_attr((string) $id); ?>" formnovalidate>
                                Create event from this poster
                            </button>
                        <?php endif; ?>
                        <?php if ($canPromote && $publishable) : ?>
                            <?php $role = SourceAttachment::defaultRoleFor($mimeType); ?>
                            <?php // The role travels with the press rather than with the form, so the one hidden
                                  // field of the shared form stays the nonce and each row contributes only its own
                                  // attachment id and role. The role is decided by the file's type, not guessed at
                                  // promotion time; a person who wants a different role uses the editor's add
                                  // control, which asks them. ?>
                            <input type="hidden" form="<?php echo esc_attr(self::PROMOTE_FORM); ?>"
                                name="role" value="<?php echo esc_attr($role); ?>">
                            <button type="submit" class="button button-primary" form="<?php echo esc_attr(self::PROMOTE_FORM); ?>"
                                name="attachment_id" value="<?php echo esc_attr((string) $id); ?>" formnovalidate
                                aria-label="<?php echo esc_attr(sprintf(
                                    'Publish %s with the event as its %s',
                                    (string) ($attachment['filename'] ?? 'this file'),
                                    $role
                                )); ?>">
                                Publish this file with the event
                            </button>
                        <?php elseif ($stored && ! $publishable) : ?>
                            <span class="description">
                                A <?php echo esc_html($mimeType); ?> file cannot be published with an event;
                                no browser can display it.
                            </span>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="description">No longer stored; only its extracted text was kept.</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }

    private function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        return number_format($bytes / 1024, 1) . ' KB';
    }
}
