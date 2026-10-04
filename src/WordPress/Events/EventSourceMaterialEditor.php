<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialPromotion;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use ADCT\ParishIntake\WordPress\Attachments\SourceMaterialAuditTrail;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use Throwable;

/**
 * Adds and removes an event's public source files from the event editor
 * (issue #172).
 *
 * The review queue's promote panel is the first way a file gets published;
 * this is the second, for the case where an event's files were forgotten at
 * approval time, or the event was created by hand. Both surfaces call
 * {@see SourceMaterialPromotion}, so the rules — at most one poster, a copy
 * taken at the moment of the decision, removal never deletes — cannot drift
 * apart between them.
 *
 * Two things this screen deliberately does not offer, because each would be a
 * way to publish something nobody chose:
 *
 * - **Uploading a file.** Only a file that arrived with the notice can be
 *   published. The intake store is where the parish's own file is, and an
 *   upload here would have no provenance row to audit.
 * - **Editing a role in place.** Turning a poster into a document is a remove
 *   followed by an add, and it is written as one so each half leaves its own
 *   audit row. Anything else makes the trail describe something that did not
 *   happen.
 *
 * The event id is the only thing taken from the request. Which candidate a
 * file may be published from is derived from the event through
 * {@see ReviewQueueRepository::findPublishedCandidateForEvent()}, so a crafted
 * POST cannot name another parish's file, and a published event with no intake
 * origin simply offers nothing.
 */
final class EventSourceMaterialEditor
{
    /**
     * The POST action and nonce names.
     *
     * Public because the meta box and `Plugin.php` both have to name the same
     * pair, and a mismatch fails silently — the form would render and the
     * route would refuse it.
     *
     * Two actions rather than one with a mode, because they do opposite things
     * and one of them takes a file off the public site: pressing Enter in a
     * filename field must never be able to do that.
     */
    public const ADD_ACTION = 'adct_pi_event_add_source_material';
    public const ADD_NONCE = 'adct_pi_event_add_source_nonce';
    public const REMOVE_ACTION = 'adct_pi_event_remove_source_material';
    public const REMOVE_NONCE = 'adct_pi_event_remove_source_nonce';

    /**
     * Public because {@see EventEditor::registerMetaBox()} puts the box on the
     * same screen under this id.
     */
    public const META_BOX_ID = 'adct_event_source_material';

    /** Carries the outcome of an add or a remove to the redirect that follows it. */
    private const NOTICE_TRANSIENT_PREFIX = 'adct_pi_source_material_notice_';

    /**
     * Two minutes. Long enough to survive the redirect and an admin page that
     * takes a moment to render, short enough that a notice cannot outlive the
     * decision it describes.
     */
    private const NOTICE_TTL_SECONDS = 120;

    public function __construct(
        private readonly SourceMaterialPromotion $sourceMaterial,
        private readonly ReviewQueueRepository $queue,
        private readonly AttachmentRepository $attachments,
        private readonly SourceMaterialAuditTrail $audit
    ) {
    }

    /**
     * Add the box to the event edit screen.
     *
     * Registered from {@see EventEditor::registerMetaBox()} so the box cannot
     * exist without the editor that owns the screen.
     */
    public function registerMetaBox(): void
    {
        add_meta_box(
            self::META_BOX_ID,
            'Poster and bulletin',
            [$this, 'renderMetaBox'],
            EventPostType::POST_TYPE,
            'normal',
            'default'
        );
    }

    /**
     * The outcome of the last add or remove, or null.
     *
     * Read on the render path and deleted on first read, so a redirect can
     * report what happened without putting the message in a query argument a
     * visitor could forge, and without the notice reappearing on a later edit
     * of the same event.
     */
    public function notice(int $postId): ?array
    {
        $state = get_transient(self::NOTICE_TRANSIENT_PREFIX . $postId);

        if (! is_array($state) || ! isset($state['message']) || ! is_string($state['message'])) {
            return null;
        }

        delete_transient(self::NOTICE_TRANSIENT_PREFIX . $postId);

        return [
            'message' => $state['message'],
            'success' => (bool) ($state['success'] ?? false),
        ];
    }

    /**
     * `admin_post_adct_pi_event_add_source_material`.
     *
     * The capability check comes **before** `check_admin_referer()`, and the
     * notice — and therefore any audit row — is only written after both pass,
     * so an unauthorised or forged request cannot leave a trace in the audit
     * trail attributing a promotion to somebody who did not make one.
     */
    public function handleAdd(): void
    {
        $eventId = $this->eventFromRequest();

        $this->authorise($eventId);
        check_admin_referer(self::ADD_ACTION, self::ADD_NONCE);

        $candidateId = $this->queue->findPublishedCandidateForEvent($eventId);

        if ($candidateId === null) {
            $this->die(
                esc_html('This event was not published from a notice, so it has no poster or bulletin to publish.'),
                409
            );
        }

        $messageId = $this->queue->findMessageOf($candidateId);

        if ($messageId === null || $messageId < 1) {
            $this->die(esc_html('That notice has no source material to publish.'), 400);
        }

        $selection = $this->selection($_POST, $messageId);

        if ($selection === []) {
            // An empty form publishes nothing and says so. Refusing with a 400
            // would make an accidental submit look like a failure.
            $this->remember($eventId, 'Nothing was selected, so nothing was published.', true);

            return;
        }

        try {
            foreach ($selection as $item) {
                $reference = $this->sourceMaterial->promote(
                    $eventId,
                    $item['storage_name'],
                    $item['original_name'],
                    $item['mime_type'],
                    $item['role']
                );

                $this->audit->recordPromotion($eventId, $reference, $item['attachment_id']);
            }
        } catch (Throwable $failure) {
            error_log(
                '[ADCT Parish Intake] Source material could not be published from the event editor: '
                . $failure->getMessage()
            );
            $this->remember(
                $eventId,
                'The source material could not be published. Try again in a moment.',
                false
            );

            return;
        }

        $published = count($selection);
        $this->remember(
            $eventId,
            sprintf(
                'Published %d file%s beside this event. Anyone can fetch %s by its address.',
                $published,
                $published === 1 ? '' : 's',
                $published === 1 ? 'it' : 'them'
            ),
            true
        );
    }

    /**
     * `admin_post_adct_pi_event_remove_source_material`.
     *
     * Removes the reference and releases the featured image; the media-library
     * copy and the intake row both stay. Publishing the same file again copies
     * it afresh rather than restoring the old copy, which is the point: the
     * removal and the re-publication are both in the audit trail, and nothing
     * is silently brought back from a state a visitor may still have cached.
     */
    public function handleRemove(): void
    {
        $eventId = $this->eventFromRequest();

        $this->authorise($eventId);
        check_admin_referer(self::REMOVE_ACTION, self::REMOVE_NONCE);

        $attachmentId = absint($this->text($_POST['attachment_id'] ?? ''));

        if ($attachmentId < 1) {
            $this->die(esc_html('That file is not valid.'), 400);
        }

        $known = false;
        foreach ($this->sourceMaterial->forEvent($eventId) as $reference) {
            if ($reference->attachmentId === $attachmentId) {
                $known = true;
                break;
            }
        }

        if (! $known) {
            // Refused before anything is touched: a crafted id must not be able
            // to detach an unrelated attachment from the event's media list.
            $this->die(esc_html('That file is not published beside this event.'), 404);
        }

        $reference = $this->referenceFor($eventId, $attachmentId);

        try {
            $this->sourceMaterial->remove($eventId, $attachmentId);
            $this->audit->recordRemoval($eventId, $reference);
        } catch (Throwable $failure) {
            error_log(
                '[ADCT Parish Intake] Source material could not be removed from the event editor: '
                . $failure->getMessage()
            );
            $this->remember(
                $eventId,
                'That file could not be taken off the event. Try again in a moment.',
                false
            );

            return;
        }

        $this->remember(
            $eventId,
            'That file is no longer published beside this event. The file itself was kept.',
            true
        );
    }

    /**
     * @param \WP_Post $post
     */
    public function renderMetaBox($post): void
    {
        $eventId = (int) ($post->ID ?? 0);

        if ($eventId < 1 || ! current_user_can('edit_post', $eventId)) {
            return;
        }

        $notice = $this->notice($eventId);
        ?>
        <div id="adct-event-source-material-box">
            <?php if ($notice !== null) : ?>
                <div class="notice notice-<?php echo $notice['success'] ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>
            <?php $this->renderPublished($eventId); ?>
            <?php $this->renderAvailable($eventId); ?>
        </div>
        <?php
    }

    /**
     * The files already published beside this event, each with its own remove
     * button.
     */
    private function renderPublished(int $eventId): void
    {
        $references = $this->sourceMaterial->forEvent($eventId);
        ?>
        <h3>Published beside this event</h3>
        <?php if ($references === []) : ?>
            <p class="description">
                Nothing from this notice is published. The event page shows no poster and offers no
                download.
            </p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col">File</th>
                    <th scope="col">Published as</th>
                    <th scope="col">In the media library</th>
                    <th scope="col">Take it off the event</th>
                </tr></thead>
                <tbody>
                <?php foreach ($references as $reference) : ?>
                    <tr>
                        <td><?php echo esc_html($reference->originalName !== ''
                            ? $reference->originalName
                            : 'Attachment #' . $reference->attachmentId); ?></td>
                        <td><?php echo esc_html($this->roleLabel($reference->role)); ?></td>
                        <td><?php $this->renderLibraryLink($reference); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action"
                                    value="<?php echo esc_attr(self::REMOVE_ACTION); ?>" />
                                <input type="hidden" name="event_id"
                                    value="<?php echo esc_attr((string) $eventId); ?>" />
                                <input type="hidden" name="attachment_id"
                                    value="<?php echo esc_attr((string) $reference->attachmentId); ?>" />
                                <input type="hidden" name="redirect_to"
                                    value="<?php echo esc_attr($this->returnUrl($eventId)); ?>" />
                                <?php wp_nonce_field(self::REMOVE_ACTION, self::REMOVE_NONCE); ?>
                                <button type="submit" class="button button-secondary">
                                    Take off the event
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">
                Taking a file off an event hides it from the event page. It does not delete the file
                or the original notice it came from, so publishing it again is always possible.
            </p>
        <?php endif;
    }

    /**
     * The files that arrived with the notice and are not published yet.
     */
    private function renderAvailable(int $eventId): void
    {
        $candidateId = $this->queue->findPublishedCandidateForEvent($eventId);

        if ($candidateId === null) {
            ?>
            <p class="description">
                This event was not published from a notice, so it has no poster or bulletin to
                publish beside it. Only the files that arrived with the notice can be published.
            </p>
            <?php

            return;
        }

        $messageId = $this->queue->findMessageOf($candidateId);

        if ($messageId === null || $messageId < 1) {
            return;
        }

        $available = $this->unpublished($eventId, $messageId);

        if ($available === []) {
            return;
        }

        $roles = [];
        foreach ($available as $attachment) {
            $roles[(int) ($attachment['id'] ?? 0)] = SourceMaterialRole::rolesFor(
                strtolower(trim((string) ($attachment['mime_type'] ?? '')))
            );
        }
        ?>
        <h3>Files from the notice that are not published yet</h3>
        <p class="description">
            Tick a file to publish it beside this event, and say what it is. Everything ticked here
            becomes public the moment you submit: it is copied into the media library, where anyone
            can fetch it by its address. Nothing is ticked for you.
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADD_ACTION); ?>" />
            <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventId); ?>" />
            <input type="hidden" name="redirect_to"
                value="<?php echo esc_attr($this->returnUrl($eventId)); ?>" />
            <?php wp_nonce_field(self::ADD_ACTION, self::ADD_NONCE); ?>
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col">Publish</th>
                    <th scope="col">File</th>
                    <th scope="col">Publish it as</th>
                </tr></thead>
                <tbody>
                <?php foreach ($available as $attachment) : ?>
                    <?php $this->renderAvailableRow($attachment, $roles[(int) ($attachment['id'] ?? 0)] ?? []); ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="submit" class="button button-primary">
                    Publish the ticked files
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * One unpublished file, offering only the roles its own type can fill.
     *
     * @param array<string, mixed> $attachment
     * @param list<string> $roles
     */
    private function renderAvailableRow(array $attachment, array $roles): void
    {
        $id = (int) ($attachment['id'] ?? 0);

        if ($id < 1) {
            return;
        }

        $id = (string) $id;
        ?>
        <tr>
            <td>
                <input type="checkbox" name="selected[]" id="adct-pi-publish-<?php echo esc_attr($id); ?>"
                    value="<?php echo esc_attr($id); ?>" />
            </td>
            <td>
                <label for="adct-pi-publish-<?php echo esc_attr($id); ?>">
                    <?php echo esc_html((string) ($attachment['filename'] ?? 'Attachment')); ?>
                </label>
            </td>
            <td>
                <?php if ($roles === []) : ?>
                    <span class="description">
                        This file's type cannot be published beside an event.
                    </span>
                <?php else : ?>
                    <select name="roles[<?php echo esc_attr($id); ?>]"
                        aria-label="Publish <?php echo esc_attr($id); ?> as">
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($roles as $role) : ?>
                            <option value="<?php echo esc_attr($role); ?>">
                                <?php echo esc_html($this->roleLabel($role)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * A link into the media library, when the attachment is still there.
     *
     * A missing row is reported as text rather than as a link that would 404:
     * somebody deleting the attachment out of band is a real thing to find
     * out about, not a reason to render a broken control.
     */
    private function renderLibraryLink(SourceMaterialReference $reference): void
    {
        $post = get_post($reference->attachmentId);

        if (! is_object($post) || (int) ($post->ID ?? 0) !== $reference->attachmentId) {
            echo '<span class="description">The attachment row is gone; only the link is left.</span>';

            return;
        }

        $editLink = get_edit_post_link($reference->attachmentId, 'raw');

        if (! is_string($editLink) || $editLink === '') {
            echo '<span class="description">#' . esc_html((string) $reference->attachmentId) . '</span>';

            return;
        }

        printf(
            '<a href="%1$s">#%2$s</a>',
            esc_url($editLink),
            esc_html((string) $reference->attachmentId)
        );
    }

    /**
     * The notice's own files that are not published yet.
     *
     * An intake file already promoted under any role is left out, because the
     * public list names a *copy*: publishing it twice would make two
     * media-library rows for one parish file and two audit rows for one
     * decision.
     *
     * The match is on the file name, which is what the public list records.
     * That is a name-based heuristic rather than a link back to the intake
     * attachment, so two different files that happen to share a name are
     * treated as one. That is the safer of the two mistakes here: the
     * alternative would let a reviewer publish the same bulletin twice.
     *
     * @return list<array<string, mixed>>
     */
    private function unpublished(int $eventId, int $messageId): array
    {
        $alreadyCopied = [];
        foreach ($this->sourceMaterial->forEvent($eventId) as $reference) {
            $alreadyCopied[] = $reference->originalName;
        }

        $remaining = [];
        foreach ($this->attachments->findPromotableForMessage($messageId) as $attachment) {
            $name = trim((string) ($attachment['filename'] ?? ''));
            if ($name !== '' && in_array($name, $alreadyCopied, true)) {
                continue;
            }

            $remaining[] = $attachment;
        }

        return $remaining;
    }

    /**
     * The files the request chose, validated against what the notice owns.
     *
     * The same three refusals as the review queue's panel, for the same
     * reasons: an id that is not the notice's own, a role that is not on
     * {@see SourceMaterialRole}'s closed list, and a role the file's declared
     * type cannot fill. All three are about the request rather than about any
     * one file, so they are checked once here rather than per row.
     *
     * @param array<string, mixed> $post the raw `$_POST`
     * @return list<array{attachment_id: int, storage_name: string, original_name: string, mime_type: string, role: string}>
     */
    private function selection(array $post, int $messageId): array
    {
        $owned = [];
        foreach ($this->attachments->findPromotableForMessage($messageId) as $attachment) {
            $owned[(int) ($attachment['id'] ?? 0)] = $attachment;
        }

        $chosen = $post['selected'] ?? [];
        $roles = is_array($post['roles'] ?? null) ? $post['roles'] : [];
        $selection = [];

        foreach (is_array($chosen) ? $chosen : [] as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $attachmentId = absint((string) $value);
            $attachment = $owned[$attachmentId] ?? null;

            if ($attachmentId < 1 || $attachment === null) {
                $this->die(esc_html('One of the files you chose is not on this notice.'), 400);
            }

            $role = SourceMaterialRole::fromInput($roles[(string) $attachmentId] ?? null);
            $mimeType = strtolower(trim((string) ($attachment['mime_type'] ?? '')));

            if ($role === null || ! SourceMaterialRole::allows($role, $mimeType)) {
                $this->die(esc_html('One of the files you chose cannot be published in that role.'), 400);
            }

            $storageName = $this->text($attachment['storage_path'] ?? '');

            if ($storageName === '') {
                $this->die(esc_html('One of the files you chose is no longer stored.'), 400);
            }

            $selection[] = [
                'attachment_id' => $attachmentId,
                'storage_name' => $storageName,
                'original_name' => (string) ($attachment['filename'] ?? ''),
                'mime_type' => $mimeType,
                'role' => $role,
            ];
        }

        return $selection;
    }

    /**
     * The published reference for an attachment id, or a neutral stand-in.
     *
     * The fallback is unreachable in practice — {@see handleRemove()} refuses an
     * id that is not in the list first — but it keeps the audit row complete if
     * the two ever disagree, and an audit row that records the removal without a
     * role is better than no row at all.
     */
    private function referenceFor(int $eventId, int $attachmentId): SourceMaterialReference
    {
        foreach ($this->sourceMaterial->forEvent($eventId) as $reference) {
            if ($reference->attachmentId === $attachmentId) {
                return $reference;
            }
        }

        return new SourceMaterialReference($attachmentId, SourceMaterialRole::DOCUMENT);
    }

    /**
     * The event the request names, or a refusal.
     *
     * The post type is checked here as well as in the capability check, so a
     * crafted id naming a page cannot reach a promotion.
     */
    private function eventFromRequest(): int
    {
        $eventId = absint($this->text($_POST['event_id'] ?? ''));

        if ($eventId < 1 || get_post_type($eventId) !== EventPostType::POST_TYPE) {
            $this->die(esc_html('That event is not valid.'), 400);
        }

        return $eventId;
    }

    /**
     * Capability first, and a refusal that says which permission is missing.
     *
     * 403 rather than 404: the person is signed in and the event exists, and
     * saying so is what a non-technical user needs. Every other admin route in
     * this plugin answers an unauthorised request the same way.
     */
    private function authorise(int $eventId): void
    {
        if (! current_user_can('edit_post', $eventId)) {
            $this->die(
                esc_html('You do not have permission to change this event.'),
                403
            );
        }
    }

    /**
     * Where a form should send the user back to.
     *
     * Empty means "there is nowhere in particular", and the route then falls
     * back to the event's own edit screen. See {@see redirectTarget()} for why
     * the URL is validated rather than trusted.
     */
    private function returnUrl(int $eventId): string
    {
        return $this->redirectTarget($eventId) ?? '';
    }

    /**
     * The post's own edit screen, or the URL the form asked to return to.
     *
     * `redirect_to` is read back through `wp_validate_redirect()` rather than
     * trusted: an admin POST that redirects to an arbitrary URL is an open
     * redirect, and only this site's own addresses may be used. An off-site or
     * malformed value falls through to the edit screen rather than being
     * refused, so a hand-edited form still lands somewhere the user can work.
     */
    private function redirectTarget(int $eventId): ?string
    {
        $requested = $this->text($_POST['redirect_to'] ?? '');

        if ($requested !== '') {
            $validated = wp_validate_redirect($requested, '');

            if (is_string($validated) && $validated !== '') {
                return $validated;
            }
        }

        $editLink = get_edit_post_link($eventId, 'raw');

        return is_string($editLink) && $editLink !== '' ? $editLink : null;
    }

    /**
     * Records the outcome, then returns to the editor.
     *
     * With nowhere to redirect to — which should not happen, because the meta
     * box always offers the edit screen — the message is shown as the response
     * instead, so the outcome is never silently discarded.
     */
    private function remember(int $eventId, string $message, bool $success): void
    {
        set_transient(
            self::NOTICE_TRANSIENT_PREFIX . $eventId,
            ['message' => $message, 'success' => $success],
            self::NOTICE_TTL_SECONDS
        );

        $target = $this->redirectTarget($eventId);

        if ($target === null) {
            $this->die(esc_html($message), $success ? 303 : 500);
        }

        wp_safe_redirect($target);
        exit;
    }

    /**
     * The word a reviewer and a visitor see for a role.
     *
     * A role that is somehow not on the closed list renders as the neutral
     * label rather than throwing: a screen that dies part-way down a table
     * leaves a reviewer with no way to take a file *off* an event, which is
     * the one action they came for.
     */
    private function roleLabel(string $role): string
    {
        try {
            return SourceMaterialRole::label($role);
        } catch (Throwable) {
            return 'Source material';
        }
    }

    /**
     * A request value as trimmed, unslashed text.
     *
     * Non-scalars are dropped rather than stringified: an array in `event_id` is
     * a forged request, and `"Array"` would be refused anyway, but refusing it
     * here keeps the refusal honest.
     */
    private function text(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) wp_unslash((string) $value));
    }

    private function die(string $message, int $status): void
    {
        wp_die($message, '', ['response' => $status]);
    }
}
