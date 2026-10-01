<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Review\CandidateEditResult;
use ADCT\ParishIntake\Core\Review\CandidateFieldSet;

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
        ?callable $renderFieldConfidence = null
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
                        $canApprove
                    ); ?>
                </div>
                <div class="adct-pi-detail-side">
                    <?php $this->renderSummary($fields, $renderFieldConfidence); ?>
                    <?php $this->renderProvenance($row); ?>
                </div>
            </div>
            <?php $this->source->render($message, $attachments, $isDownloadable); ?>
            <?php $this->renderDownloadForms($id); ?>
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

    /** @param array<string, mixed> $row */
    private function renderProvenance(array $row): void
    {
        $notes = json_decode((string) ($row['notes'] ?? ''), true);
        $notes = is_array($notes) ? $notes : [];
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
     * The two POST forms the download buttons submit through.
     *
     * They are separate from the edit form so that pressing Enter in a text
     * field can never trigger a download. Each carries the candidate the
     * reviewer opened, which is what the attachment handler checks the file
     * against.
     */
    private function renderDownloadForms(int $candidateId): void
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
            if (preg_match('/\Apossible_missed_event_after_skipped_section:\s*(\d+)\z/D', $note, $matches) === 1) {
                $warnings[] = 'Possible missed event after ' . (int) $matches[1] . ' skipped sections.';
                continue;
            }
            if (str_starts_with($note, 'skipped_sections:')) {
                $warnings[] = 'The parser skipped sections of this message.';
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
