<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Parsing\UnparsedDateTimeCandidate;
use ADCT\ParishIntake\Core\Review\CandidateEditResult;
use ADCT\ParishIntake\Core\Review\CandidateFieldSet;
use ADCT\ParishIntake\WordPress\Database\Repository\ReviewQueueRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The single-candidate detail screen: what the parish sent, every field the
 * parser produced, and the form to correct them.
 *
 * A GET only ever renders. Saving, approving and rejecting all go through POST
 * handlers in {@see ReviewQueuePage}, so a bookmarked or prefetched URL cannot
 * change anything.
 */
final class CandidateDetailView
{
    public function __construct(
        private readonly CandidateEditForm $form = new CandidateEditForm(),
        private readonly CandidateSourceFiles $source = new CandidateSourceFiles()
    ) {
    }

    /**
     * @param array<string, mixed> $row the scoped candidate row
     * @param array<string, mixed>|null $message
     * @param list<array<string, mixed>> $attachments
     * @param list<array<string, mixed>> $parishes
     * @param array<string, string> $errors
     * @param string $posterPanel the stored poster shown beside the form, or ''
     *        when the notice had no browser-readable image to show
     * @param bool $canStartManual whether this reviewer may open a blank event to
     *        type in beside the poster
     * @param bool $needsMatchResolution whether the parser flagged this candidate's
     *        match as ambiguous, so the reviewer is offered the control that clears
     *        it (issue #177). Decided by the page from the same policy the publisher
     *        trusts, never from the rendered markup.
     * @param bool $detailsUnreadable whether this candidate's stored `fields` cannot be
     *        decoded. The screen still renders — it must, or a row nobody can parse becomes
     *        a row nobody can reach — but it is told plainly, because a screen that quietly
     *        offers no match control and no approval looks exactly like a screen with
     *        nothing wrong (issue #177).
     * @param array{available: bool, reason: string, lastResentAt: ?DateTimeImmutable, nextAllowedAt: ?DateTimeImmutable, now?: DateTimeImmutable} $resend
     *        whether this saved candidate can have its confirmation preview resent, and
     *        when it last was (issue #176). An empty array means "no service", which
     *        renders nothing at all.
     * @param bool $canPromote whether this reviewer may publish an attachment's
     *        source material with the event (issue #172). False renders no offer and
     *        no form, so the default is "nothing is promoted" rather than "something
     *        might be".
     */
    public function render(
        array $row,
        ?array $message,
        array $attachments,
        array $parishes,
        string $tab,
        string $search,
        bool $editable,
        bool $canApprove,
        ?CandidateEditResult $attempt = null,
        ?callable $isDownloadable = null,
        ?callable $renderFieldConfidence = null,
        string $posterPanel = '',
        bool $canStartManual = false,
        bool $needsMatchResolution = false,
        bool $detailsUnreadable = false,
        array $resend = [],
        bool $canPromote = false
    ): void {
        $id = (int) $row['id'];
        $fields = CandidateFieldSet::decodeFields($row['fields'] ?? null);
        $recurrence = CandidateFieldSet::decodeFields($row['recurrence'] ?? null);
        $inputs = $attempt?->submittedInputs() ?? [];
        if ($inputs === []) {
            $inputs = CandidateFieldSet::fromFields($fields, $recurrence)->toInputs();
        }
        $errors = $attempt?->errors ?? [];
        ?>
        <div class="wrap adct-pi-candidate-detail">
            <h1>Candidate #<?php echo esc_html((string) $id); ?></h1>
            <p>
                <a href="<?php echo esc_url(ReviewQueuePage::queueUrl($tab, $search)); ?>">Back to review queue</a>
            </p>
            <?php $this->renderAttemptNotice($attempt, $id); ?>
            <?php $this->renderUnreadableNotice($id, $detailsUnreadable); ?>
            <?php $this->renderMatchResolution($row, $fields, $parishes, $id, $tab, $search, $needsMatchResolution); ?>
            <?php $this->renderResendConfirmation($id, $tab, $search, $resend); ?>
            <div class="adct-pi-detail-columns">
                <div class="adct-pi-detail-main">
                    <?php $this->form->render(
                                            $inputs,
                                            $errors,
                                            $parishes,
                                            $id,
                                            $tab,
                                            $search,
                                            $editable,
                                            $canApprove,
                                            // #167: only when the notice's date could not be read, and only where
                                            // approving is on offer -- there is nothing to acknowledge on a row that
                                            // is already decided or held for review.
                                            $this->unparsedDateSentence($row)
                                        ); ?>
                                    </div>
                <div class="adct-pi-detail-side">
                    <?php if ($posterPanel !== '') {
                        echo $posterPanel; // already-escaped markup built by the page
                    } ?>
                    <?php $this->renderSummary($fields, $renderFieldConfidence); ?>
                    <?php $this->renderProvenance($row); ?>
                </div>
            </div>
            <?php $this->source->render($message, $attachments, $isDownloadable, $canStartManual, $canPromote); ?>
            <?php $this->renderDownloadForms($id, $canPromote); ?>
        </div>
        <?php
    }

    /**
     * The control that clears an ambiguous match, above the editor (issue #177).
     *
     * Its own form, its own action and its own nonce, so pressing Enter in a title
     * field cannot resolve anything. It sits above the editor rather than in the
     * side column because it is the one thing standing between this candidate and an
     * approval, and the reviewer should not have to read a provenance table to find
     * it.
     *
     * The panel is only offered when the candidate actually needs it. Once resolved,
     * the flag is gone and the panel disappears rather than offering to resolve a
     * second time.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fields
     * @param list<array<string, mixed>> $parishes
     */
    private function renderMatchResolution(
        array $row,
        array $fields,
        array $parishes,
        int $id,
        string $tab,
        string $search,
        bool $needsMatchResolution
    ): void {
        if (! $needsMatchResolution) {
            return;
        }
        $current = (int) ($row['parish_id'] ?? 0);
        $matched = (int) ($fields['matched_candidate_id'] ?? 0);
        ?>
        <div class="adct-pi-card adct-pi-match-resolution">
            <h2>Resolve this match</h2>
            <div class="notice notice-warning inline"><p><?php echo esc_html(
                'The parser could not tell what this event refers to, so it cannot be approved until '
                . 'somebody resolves it here.'
            ); ?></p></div>
            <p class="description">
                <?php echo esc_html($matched > 0
                    ? 'It most closely resembles event #' . $matched . ' on the list above.'
                    : 'It resembles another event closely enough that it was not treated as new.'); ?>
                Choose the parish this event really belongs to, or leave it unassigned for an archdiocese
                reviewer. Either choice clears the block and is recorded in the history below.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(ReviewQueuePage::RESOLVE_MATCH_ACTION); ?>" />
                <input type="hidden" name="candidate_id" value="<?php echo esc_attr((string) $id); ?>" />
                <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
                <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />
                <?php wp_nonce_field(ReviewQueuePage::RESOLVE_MATCH_ACTION, ReviewQueuePage::RESOLVE_MATCH_NONCE); ?>
                <p>
                    <label for="adct-pi-resolve-parish">Parish</label>
                    <select name="parish_id" id="adct-pi-resolve-parish">
                        <option value="">Leave unassigned</option>
                        <?php foreach ($parishes as $parish) : ?>
                            <option value="<?php echo esc_attr((string) $parish['id']); ?>"
                                <?php selected((int) $parish['id'], $current); ?>>
                                <?php echo esc_html((string) ($parish['name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label for="adct-pi-resolve-venue">Venue</label>
                    <input type="number" name="venue_id" id="adct-pi-resolve-venue" min="1" class="small-text"
                        value="<?php echo esc_attr((string) (int) ($fields['venue_id'] ?? 0)); ?>" />
                    <span class="description">A venue number belonging to the parish above, or blank for none.</span>
                </p>
                <p class="submit">
                    <button type="submit" class="button button-primary">Resolve this match</button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * The control that resends the confirmation preview to the parish (issue #176).
     *
     * Its own form, action and nonce, for the same reason the match panel has its
     * own: this one puts an email in front of somebody outside the archdiocese,
     * so it must not be something a stray Enter in a text field can trigger.
     *
     * The panel never renders for a candidate with nothing saved, because there is
     * nothing to render *from* — the email is built from the current stored
     * fields, not from what is on this screen.
     *
     * The button being disabled inside the cooldown is presentation only. The
     * service refuses a second resend in the same hour regardless of what was
     * posted, so the guard cannot be bypassed by hand-crafting the POST; this
     * exists so the reviewer is not invited to press something that will bounce.
     *
     * @param array{available: bool, reason: string, lastResentAt: ?DateTimeImmutable, nextAllowedAt: ?DateTimeImmutable, now?: DateTimeImmutable} $resend
     */
    private function renderResendConfirmation(int $id, string $tab, string $search, array $resend): void
    {
        if ($resend === []) {
            return;
        }

        $timezone = new DateTimeZone('Africa/Johannesburg');
        $lastResentAt = $resend['lastResentAt'] ?? null;
        $nextAllowedAt = $resend['nextAllowedAt'] ?? null;
                $now = $resend['now'] ?? null;
                        $waiting = $nextAllowedAt !== null
                            && ($now === null || $nextAllowedAt->getTimestamp() > $now->getTimestamp());
        ?>
        <div class="adct-pi-card adct-pi-resend-confirmation">
            <h2>Confirmation preview</h2>
            <?php if (($resend['available'] ?? false) !== true) : ?>
                <p class="description"><?php echo esc_html((string) $resend['reason']); ?></p>
            <?php else : ?>
                <p class="description">
                    The parish received a preview of this event when it arrived. Resending re-sends the
                    preview as it stands <strong>now</strong>, using the details saved above, and any
                    links in the older email stop working.
                </p>
                <?php if ($lastResentAt !== null) : ?>
                    <p class="description"><?php echo esc_html(sprintf(
                        'Last resent %s.',
                        $lastResentAt->setTimezone($timezone)->format('d/m/Y H:i')
                    )); ?></p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(ReviewQueuePage::RESEND_CONFIRMATION_ACTION); ?>" />
                    <input type="hidden" name="candidate_id" value="<?php echo esc_attr((string) $id); ?>" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
                    <input type="hidden" name="search" value="<?php echo esc_attr($search); ?>" />
                    <?php wp_nonce_field(
                        ReviewQueuePage::RESEND_CONFIRMATION_ACTION,
                        ReviewQueuePage::RESEND_CONFIRMATION_NONCE
                    ); ?>
                    <p class="submit">
                        <button type="submit" class="button" <?php echo $waiting ? 'disabled="disabled"' : ''; ?>>
                            <?php echo $waiting ? 'aria-disabled="true"' : ''; ?>>
                            <?php echo esc_html($waiting ? 'Resend unavailable right now' : 'Resend confirmation'); ?>
                        </button>
                    </p>
                </form>
                <?php if ($waiting && $nextAllowedAt !== null) : ?>
                    <p class="description"><?php echo esc_html(sprintf(
                        'A confirmation can only be resent once an hour, to keep within the email limits. '
                        . 'The last one was sent at %s; you may resend again after %s.',
                        $lastResentAt !== null
                            ? $lastResentAt->setTimezone($timezone)->format('d/m/Y H:i')
                            : 'an earlier time',
                        $nextAllowedAt->setTimezone($timezone)->format('d/m/Y H:i')
                    )); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param array<string, mixed> $fields */
    private function renderSummary(array $fields, ?callable $renderFieldConfidence = null): void
    {
        $rows = [];
        foreach (CandidateFieldSet::EDITABLE_KEYS as $key) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }
            $rows[$key] = $this->display($fields[$key]);
        }
        $extra = array_diff(array_keys($fields), array_keys($rows));
        sort($extra);
        foreach ($extra as $key) {
            $rows[$key] = $this->display($fields[$key]);
        }

        ?>
        <div class="adct-pi-card">
            <h2>Every extracted field</h2>
            <p class="description">What the parser stored, including the keys this form does not edit.</p>
            <table class="widefat striped"><tbody>
                <?php foreach ($rows as $key => $value) : ?>
                    <tr>
                        <th scope="row"><code><?php echo esc_html((string) $key); ?></code></th>
                        <td><?php echo esc_html($value === '' ? '—' : $value); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []) : ?>
                    <tr><td>No fields were stored on this candidate.</td></tr>
                <?php endif; ?>
                <?php if ($renderFieldConfidence !== null) {
                    $renderFieldConfidence($fields);
                } ?>
            </tbody></table>
        </div>
        <?php
    }

    /**
     * The sentence that says the notice's date could not be read, or null when it did.
     *
     * Only the date: a notice with an unreadable time but a good date publishes as an all-day
     * event, which the provenance panel already reports and which is not the failure an approver
     * has to sign off on.
     *
     * @param array<string, mixed> $row
     */
    private function unparsedDateSentence(array $row): ?string
    {
        $unparsed = UnparsedDateTimeCandidate::fromNotes($this->notes($row));

        return $unparsed->hasDate()
            ? UnparsedDateTimeCandidate::describe(
                UnparsedDateTimeCandidate::DATE_REASON . ':' . $unparsed->phrases()[0]
            )
            : null;
    }

    /**
     * The candidate's parser notes as a list, decoded from the stored JSON.
     *
     * A row that has never been through the parser can hold anything at all in this column, so an
     * unreadable blob yields no notes rather than failing the page.
     *
     * @param array<string, mixed> $row
     * @return list<mixed>
     */
    private function notes(array $row): array
    {
        $notes = json_decode((string) ($row['notes'] ?? ''), true);

        return is_array($notes) ? $notes : [];
    }

    /** @param array<string, mixed> $row */
    private function renderProvenance(array $row): void
    {
        $notes = $this->notes($row);
        $strategies = json_decode((string) ($row['strategies'] ?? ''), true);
        $strategies = is_array($strategies) ? $strategies : [];
        ?>
        <div class="adct-pi-card">
            <h2>How this was parsed</h2>
            <table class="widefat striped"><tbody>
                <tr><th scope="row">Parser</th><td><?php echo esc_html((string) ($row['parser_version'] ?? 'unknown')); ?></td></tr>
                <tr><th scope="row">Confidence</th>
                    <td><?php echo esc_html(number_format((float) ($row['confidence'] ?? 0) * 100, 0) . '%'); ?></td></tr>
                <tr><th scope="row">AI used</th>
                    <td><?php echo esc_html(($row['ai_used'] ?? 0) ? (string) ($row['ai_model'] ?? 'yes') : 'no'); ?></td></tr>
                <tr><th scope="row">Match</th>
                    <td><?php echo esc_html((string) ($row['match_kind'] ?? 'new')
                        . ($row['match_event_id'] !== null ? ' (event #' . (string) $row['match_event_id'] . ')' : '')); ?></td></tr>
                <tr><th scope="row">Sender</th>
                    <td><?php echo esc_html((string) ($row['sender_email'] ?: 'No inbound sender')); ?></td></tr>
                <tr><th scope="row">Parish</th>
                    <td><?php echo esc_html((string) ($row['parish_name'] ?: 'Unassigned')); ?></td></tr>
                <tr><th scope="row">Status</th><td><?php echo esc_html((string) ($row['status'] ?? 'unknown')); ?></td></tr>
                <tr><th scope="row">Updated</th><td><?php echo esc_html((string) ($row['updated_at'] ?? 'unknown')); ?> UTC</td></tr>
                <tr><th scope="row">Decision</th>
                    <td><?php echo esc_html(
                        (string) (($row['decided_by'] ?? '') ?: 'Not decided')
                        . ' / ' . (string) (($row['decided_at'] ?? '') ?: '—')
                    ); ?></td></tr>
                <?php if (($row['decision_note'] ?? '') !== '') : ?>
                    <tr><th scope="row">Reason</th>
                        <td><?php echo esc_html((string) $row['decision_note']); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($this->warnings($notes) as $warning) : ?>
                    <tr><th scope="row">Parser warning</th><td><?php echo esc_html($warning); ?></td></tr>
                <?php endforeach; ?>
                <?php if ($strategies !== []) : ?>
                    <tr><th scope="row">Strategies</th>
                        <td><?php echo esc_html(implode(', ', array_map(
                            'strval',
                            array_is_list($strategies) ? $strategies : array_keys($strategies)
                        ))); ?></td></tr>
                <?php endif; ?>
            </tbody></table>
        </div>
        <?php
    }

    /**
     * The POST forms the buttons on this screen submit through.
     *
     * They are separate from the edit form so that pressing Enter in a text
     * field can never trigger a download or a new blank event. Each carries the
     * candidate the reviewer opened, which is what the handlers check the request
     * against.
     */
    private function renderDownloadForms(int $candidateId, bool $canPromote): void
    {
        ?>
        <form id="adct-pi-raw-message-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(ReviewQueuePage::RAW_MESSAGE_ACTION); ?>" />
            <input type="hidden" name="candidate" value="<?php echo esc_attr((string) $candidateId); ?>" />
            <?php wp_nonce_field(ReviewQueuePage::RAW_MESSAGE_ACTION, ReviewQueuePage::SOURCE_NONCE); ?>
        </form>
        <form id="adct-pi-attachment-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(ReviewQueuePage::ATTACHMENT_ACTION); ?>" />
            <input type="hidden" name="candidate" value="<?php echo esc_attr((string) $candidateId); ?>" />
            <?php wp_nonce_field(ReviewQueuePage::ATTACHMENT_ACTION, ReviewQueuePage::SOURCE_NONCE); ?>
        </form>
        <form id="adct-pi-create-manual-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(ReviewQueuePage::CREATE_MANUAL_ACTION); ?>" />
            <input type="hidden" name="candidate" value="<?php echo esc_attr((string) $candidateId); ?>" ?>
            <?php wp_nonce_field(ReviewQueuePage::CREATE_MANUAL_ACTION, ReviewQueuePage::CREATE_MANUAL_NONCE); ?>
        </form>
        <?php
        if (! $canPromote) {
            return;
        }
        ?>
        <form id="<?php echo esc_attr(CandidateSourceFiles::PROMOTE_FORM); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(ReviewQueuePage::PROMOTE_SOURCE_ACTION); ?>" />
            <input type="hidden" name="candidate" value="<?php echo esc_attr((string) $candidateId); ?>" />
            <?php wp_nonce_field(ReviewQueuePage::PROMOTE_SOURCE_ACTION, ReviewQueuePage::PROMOTE_SOURCE_NONCE); ?>
        </form>
        <?php
    }

    /**
     * The audit trail for this candidate, so a reviewer can see who has already
     * touched it.
     *
     * @param list<array<string, string>> $history
     */
    public function renderAuditTrail(array $history): void
    {
        if ($history === []) {
            return;
        }
        ?>
        <h2>History</h2>
        <table class="widefat striped">
            <thead><tr><th scope="col">When (UTC)</th><th scope="col">Who</th><th scope="col">What</th></tr></thead>
            <tbody>
            <?php foreach ($history as $entry) : ?>
                <tr>
                    <td><?php echo esc_html((string) ($entry['created_at'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($entry['actor'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($entry['action'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Says plainly that this row's stored details cannot be read, and what that costs
     * the reviewer (issue #177).
     *
     * A resolve writes fresh details over the ones we just failed to read, so this row
     * is offered no resolve control — but saying nothing about that would read as
     * "nothing to fix here", which is the opposite of the truth.
     */
    private function renderUnreadableNotice(int $id, bool $detailsUnreadable): void
    {
        if (! $detailsUnreadable) {
            return;
        }

        printf(
            '<div class="notice notice-error inline"><p>%s</p></div>',
            esc_html(
                sprintf(
                    'The stored event details for candidate #%d could not be read, so this notice cannot be matched,'
                    . ' edited or approved here. The source email below is intact — an archdiocese reviewer needs'
                    . ' to repair the stored details by hand.',
                    $id
                )
            )
        );
    }

    private function renderAttemptNotice(?CandidateEditResult $attempt, int $id): void
    {
        if ($attempt === null) {
            return;
        }
        if ($attempt->hasErrors()) {
            printf(
                '<div class="notice notice-error inline"><p>%s</p></div>',
                esc_html('Candidate #' . $id . ' was not saved. Fix the highlighted fields and try again.')
            );

            return;
        }
        printf(
            '<div class="notice notice-success inline"><p>%s</p></div>',
            esc_html('Candidate #' . $id . ' was saved. Review the source email before approving.')
        );
    }

    /**
     * A parser note, rendered as a readable sentence. Anything unrecognised is
     * shown verbatim rather than dropped, because a reviewer may need it.
     *
     * @param array<mixed> $notes
     * @return list<string>
     */
    private function warnings(array $notes): array
    {
        $warnings = [];
        foreach ($notes as $note) {
            if (! is_string($note)) {
                continue;
            }
            if ($note === ReviewQueueRepository::MANUAL_NOTE) {
                // This row was typed in by a person, so there is no parsing to
                // report. Saying "parser warning" would be misleading.
                continue;
            }
            if (preg_match('/\Apossible_missed_event_after_skipped_section:\s*(\d+)\z/D', $note, $matches) === 1) {
                $warnings[] = 'Possible missed event after ' . (int) $matches[1] . ' skipped sections.';
                continue;
            }
            if (str_starts_with($note, 'skipped_sections:')) {
                $warnings[] = 'The parser skipped sections of this message.';
                continue;
            }
            // #167: a date or time the parser found and could not read. The sentence has to
            // replace the token -- a reason string on its own tells a reviewer nothing about what
            // to do, and it is the whole of what they have to go on here.
            $unreadable = UnparsedDateTimeCandidate::describe($note);
            if ($unreadable !== null) {
                $warnings[] = $unreadable;
                continue;
            }

            $warnings[] = $note;
        }

        return array_values(array_unique($warnings));
    }

    /**
     * A stored value, made human-readable without changing what it means.
     */
    private function display(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($value === null) {
            return '—';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            if ($value === []) {
                return '—';
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $json === false ? '[unreadable]' : $json;
        }

        $text = (string) $value;
        if (mb_strlen($text) > 300) {
            return mb_substr($text, 0, 300) . '…';
        }

        return $text;
    }
}
