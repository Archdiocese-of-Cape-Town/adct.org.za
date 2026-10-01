<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

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
     * @param array<string, mixed>|null $message
     * @param list<array<string, mixed>> $attachments
     * @param callable(string): bool|null $isDownloadable tells the view whether a
     *        stored name still resolves to a real file, so a Download button is
     *        only offered when the request would actually succeed.
     */
    public function render(?array $message, array $attachments, ?callable $isDownloadable = null): void
    {
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
                    <?php $this->attachmentRow($attachment, $isDownloadable); ?>
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
    private function attachmentRow(array $attachment, ?callable $isDownloadable): void
    {
        $id = (int) ($attachment['id'] ?? 0);
        $method = trim((string) ($attachment['extraction_method'] ?? ''));
        $path = trim((string) ($attachment['storage_path'] ?? ''));
        $stored = $id > 0 && $path !== ''
            && ($isDownloadable === null || $isDownloadable($path));
        ?>
        <tr>
            <td><?php echo esc_html((string) ($attachment['filename'] ?? '(unnamed)')); ?></td>
            <td><?php echo esc_html((string) ($attachment['mime_type'] ?? 'application/octet-stream')); ?></td>
            <td><?php echo esc_html($this->size((int) ($attachment['size_bytes'] ?? 0))); ?></td>
            <td><?php echo esc_html($method === '' ? 'not extracted' : $method); ?></td>
            <td>
                <?php if ($stored) : ?>
                    <button type="submit" class="button" form="adct-pi-attachment-form"
                        name="attachment_id" value="<?php echo esc_attr((string) $id); ?>" formnovalidate>
                        Download
                    </button>
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
