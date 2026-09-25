<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Admin;

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Jobs\InboundMessageProcessingJob;
use ADCT\ParishIntake\Core\Jobs\JobRunStatus;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Ports\InboundMessageProcessingStoreInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\InboundMessageRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class InboundMessagesPage
{
    private const PAGE_SLUG = 'adct-parish-intake-inbox';
    private const ACTION = 'adct_pi_reprocess_inbound_messages';
    private const NONCE_FIELD = 'adct_pi_reprocess_nonce';
    private const PAGE_SIZE = 25;

    public function __construct(
        private InboundMessageRepository $inboundMessages,
        private InboundMessageProcessingStoreInterface $messageStore,
        private InboundMessageProcessingJob $processingJob,
        private JobRunner $jobRunner,
        private ClockInterface $clock
    ) {
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'adct-parish-intake',
            'Parish Intake Inbox',
            'Inbox',
            Capabilities::REVIEW,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function handleReprocess(): void
    {
        $this->requireReviewCapability();
        check_admin_referer(self::ACTION, self::NONCE_FIELD);
        $messageIds = $this->postedMessageIds();
        $requeuedIds = $this->messageStore->requeueFailedMessages($messageIds, $this->timestamp());
        $runStatus = null;

        if ($requeuedIds !== []) {
            try {
                $this->processingJob->prioritizeMessageIds($requeuedIds);
                $runStatus = $this->jobRunner->run($this->processingJob, true)->status;
            } catch (Throwable $failure) {
                error_log(
                    '[ADCT Parish Intake] Inbound message reprocessing could not complete ('
                    . get_class($failure) . ').'
                );
                $runStatus = JobRunStatus::FAILED;
            } finally {
                $this->processingJob->clearPrioritizedMessageIds();
            }
        }

        $arguments = [
            'page' => self::PAGE_SLUG,
            'requeued' => count($requeuedIds),
            'run_status' => $runStatus?->value ?? 'none',
        ];
        $status = $this->postedStatus();

        if ($status !== null) {
            $arguments['status'] = $status;
        }

        wp_safe_redirect(add_query_arg($arguments, admin_url('admin.php')));
        exit;
    }

    public function renderPage(): void
    {
        $this->requireReviewCapability();
        $status = $this->queryStatus();
        $invalidStatus = $this->hasInvalidQueryStatus();
        $total = $this->inboundMessages->countForInbox($status);
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($this->queryPage(), $totalPages);
        $messages = $this->inboundMessages->findForInbox(
            $status,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE
        );
        ?>
        <div class="wrap">
            <h1>Inbox</h1>
            <p>Messages are stored privately and processed in bounded jobs. This screen shows message details and processing notes, not the email body or attachments.</p>
            <p>Reprocessing uses the same protected email file and message record. It does not poll the mailbox, change its checkpoint, publish an event, or send confirmation email.</p>

            <?php $this->renderResultNotice(); ?>

            <?php if ($invalidStatus) : ?>
                <div class="notice notice-warning"><p>The selected status filter was not recognized; showing all messages.</p></div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <label for="adct-pi-inbox-status">Filter by status</label>
                <select id="adct-pi-inbox-status" name="status">
                    <option value="" <?php selected($status ?? '', ''); ?>>All messages</option>
                    <?php foreach ($this->statusOptions() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button('Filter', 'secondary', '', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                <input type="hidden" name="inbox_status" value="<?php echo esc_attr($status ?? ''); ?>" />
                <?php wp_nonce_field(self::ACTION, self::NONCE_FIELD); ?>

                <p>
                    <button class="button button-primary" type="submit" name="reprocess_bulk" value="1">
                        Reprocess selected failed messages
                    </button>
                    <span class="description">Select up to <?php echo esc_html((string) InboundMessageProcessingJob::MAX_REPROCESS_BATCH); ?> failed messages.</span>
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><span class="screen-reader-text">Select</span></th>
                            <th scope="col">Received</th>
                            <th scope="col">Sender</th>
                            <th scope="col">Subject</th>
                            <th scope="col">Status</th>
                            <th scope="col">Error or note</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($messages === []) : ?>
                            <tr><td colspan="7">No messages match this filter.</td></tr>
                        <?php else : ?>
                            <?php foreach ($messages as $message) : ?>
                                <?php $this->renderMessageRow($message); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p>
                    <button class="button button-primary" type="submit" name="reprocess_bulk" value="1">
                        Reprocess selected failed messages
                    </button>
                </p>
            </form>

            <?php $this->renderPagination($page, $totalPages, $total, $status); ?>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $message
     */
    private function renderMessageRow(array $message): void
    {
        $id = (int) ($message['id'] ?? 0);
        $status = is_string($message['status'] ?? null) ? $message['status'] : '';
        $canReprocess = $status === InboundMessageRecord::STATUS_FAILED && $id > 0;
        $senderName = is_string($message['sender_name'] ?? null) ? $message['sender_name'] : '';
        $senderEmail = is_string($message['sender_email'] ?? null) ? $message['sender_email'] : '';
        $subject = is_string($message['subject'] ?? null) ? $message['subject'] : '';
        $error = is_string($message['error'] ?? null) ? $message['error'] : '';
        ?>
        <tr>
            <td>
                <input
                    type="checkbox"
                    name="message_ids[]"
                    value="<?php echo esc_attr((string) $id); ?>"
                    aria-label="<?php echo esc_attr(sprintf('Select message %d for reprocessing', $id)); ?>"
                    <?php disabled(! $canReprocess); ?>
                />
            </td>
            <td><?php echo esc_html($this->formatReceivedAt($message['received_at'] ?? null)); ?></td>
            <td>
                <?php if ($senderName !== '') : ?>
                    <strong><?php echo esc_html($senderName); ?></strong><br />
                <?php endif; ?>
                <?php echo esc_html($senderEmail !== '' ? $senderEmail : 'Unknown sender'); ?>
                <?php if (in_array($message['is_auto_reply'] ?? null, [1, '1', true], true)) : ?>
                    <br /><span class="description">Automated or mailing-list message</span>
                <?php endif; ?>
            </td>
            <td>
                <strong>#<?php echo esc_html((string) $id); ?></strong><br />
                <?php echo esc_html($subject !== '' ? $subject : '(No subject)'); ?>
            </td>
            <td><?php echo esc_html($this->statusLabel($status)); ?></td>
            <td><?php echo esc_html($error !== '' ? $error : '—'); ?></td>
            <td>
                <?php if ($canReprocess) : ?>
                    <button
                        class="button button-secondary"
                        type="submit"
                        name="reprocess_one"
                        value="<?php echo esc_attr((string) $id); ?>"
                    >Reprocess</button>
                <?php else : ?>
                    <span aria-hidden="true">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function renderResultNotice(): void
    {
        if (
            ! isset($_GET['requeued'], $_GET['run_status'])
            || ! is_string($_GET['requeued'])
            || ! is_string($_GET['run_status'])
        ) {
            return;
        }

        $requeued = absint(wp_unslash($_GET['requeued']));
        $runStatus = sanitize_key(wp_unslash($_GET['run_status']));

        if ($requeued === 0) {
            $class = 'notice-info';
            $message = 'No failed messages were selected for reprocessing.';
        } else {
            [$class, $message] = $this->runNotice($requeued, JobRunStatus::tryFrom($runStatus));
        }
        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function runNotice(int $requeued, ?JobRunStatus $status): array
    {
        if ($status === JobRunStatus::COMPLETED) {
            return [
                'notice-info',
                sprintf(
                    'The reprocess attempt finished for %d message(s). Review each status and note below; messages that still failed need attention.',
                    $requeued
                ),
            ];
        }

        if ($status === JobRunStatus::LOCKED) {
            return [
                'notice-warning',
                sprintf(
                    '%d failed message(s) were requeued. Another processing run is active and will pick them up.',
                    $requeued
                ),
            ];
        }

        if (in_array($status, [JobRunStatus::TIME_BUDGET_REACHED, JobRunStatus::ITEM_BUDGET_REACHED], true)) {
            return [
                'notice-warning',
                sprintf(
                    '%d failed message(s) were requeued. Processing paused at its safety limit; remaining messages will be picked up by the next scheduled run.',
                    $requeued
                ),
            ];
        }

        return [
            'notice-error',
            sprintf(
                '%d failed message(s) were requeued, but processing did not finish. Check Scheduled jobs; the messages remain available for another run.',
                $requeued
            ),
        ];
    }

    private function renderPagination(int $page, int $totalPages, int $total, ?string $status): void
    {
        if ($totalPages <= 1) {
            return;
        }

        $baseArguments = [];

        if ($status !== null) {
            $baseArguments['status'] = $status;
        }
        ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html(sprintf('%d messages', $total)); ?></span>
                <span class="pagination-links">
                    <?php if ($page > 1) : ?>
                        <a class="prev-page" href="<?php echo esc_url($this->pageUrl($baseArguments + ['paged' => $page - 1])); ?>">Previous</a>
                    <?php else : ?>
                        <span class="tablenav-pages-navspan">Previous</span>
                    <?php endif; ?>
                    <span class="paging-input"><?php echo esc_html(sprintf('Page %d of %d', $page, $totalPages)); ?></span>
                    <?php if ($page < $totalPages) : ?>
                        <a class="next-page" href="<?php echo esc_url($this->pageUrl($baseArguments + ['paged' => $page + 1])); ?>">Next</a>
                    <?php else : ?>
                        <span class="tablenav-pages-navspan">Next</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            InboundMessageRecord::STATUS_RECEIVED => 'Received',
            InboundMessageRecord::STATUS_EXTRACTING => 'Extracting',
            InboundMessageRecord::STATUS_PARSED => 'Parsed',
            InboundMessageRecord::STATUS_FAILED => 'Failed',
            InboundMessageRecord::STATUS_IGNORED => 'Ignored',
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            InboundMessageRecord::STATUS_RECEIVED => 'Received',
            InboundMessageRecord::STATUS_EXTRACTING => 'Extracting',
            InboundMessageRecord::STATUS_PARSED => 'Parsed',
            InboundMessageRecord::STATUS_FAILED => 'Failed',
            InboundMessageRecord::STATUS_IGNORED,
            InboundMessageRecord::STATUS_SKIPPED => 'Ignored',
            default => 'Unknown',
        };
    }

    private function queryStatus(): ?string
    {
        if (! isset($_GET['status']) || ! is_string($_GET['status'])) {
            return null;
        }

        $status = sanitize_key(wp_unslash($_GET['status']));

        return in_array($status, InboundMessageRecord::FILTER_STATUSES, true) ? $status : null;
    }

    private function hasInvalidQueryStatus(): bool
    {
        if (! isset($_GET['status']) || ! is_string($_GET['status'])) {
            return false;
        }

        $status = sanitize_key(wp_unslash($_GET['status']));

        return $status !== ''
            && ! in_array($status, InboundMessageRecord::FILTER_STATUSES, true);
    }

    private function queryPage(): int
    {
        if (! isset($_GET['paged']) || ! is_string($_GET['paged'])) {
            return 1;
        }

        return max(1, absint(wp_unslash($_GET['paged'])));
    }

    private function postedStatus(): ?string
    {
        if (! isset($_POST['inbox_status']) || ! is_string($_POST['inbox_status'])) {
            return null;
        }

        $status = sanitize_key(wp_unslash($_POST['inbox_status']));

        return in_array($status, InboundMessageRecord::FILTER_STATUSES, true) ? $status : null;
    }

    /**
     * @return list<int>
     */
    private function postedMessageIds(): array
    {
        if (isset($_POST['reprocess_one'])) {
            $submittedIds = [wp_unslash($_POST['reprocess_one'])];
        } elseif (isset($_POST['reprocess_bulk'])) {
            $submittedIds = isset($_POST['message_ids']) && is_array($_POST['message_ids'])
                ? wp_unslash($_POST['message_ids'])
                : [];
        } else {
            wp_die(esc_html__('Choose one failed message or select failed messages to reprocess.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        if (count($submittedIds) > InboundMessageProcessingJob::MAX_REPROCESS_BATCH) {
            wp_die(esc_html__('Select no more than 20 failed messages at a time.', 'adct-parish-intake'), '', [
                'response' => 400,
            ]);
        }

        $ids = [];

        foreach ($submittedIds as $submittedId) {
            if (! is_string($submittedId) && ! is_int($submittedId)) {
                wp_die(esc_html__('A selected message ID is invalid.', 'adct-parish-intake'), '', [
                    'response' => 400,
                ]);
            }

            $value = (string) $submittedId;

            if (preg_match('/\A[1-9]\d{0,18}\z/', $value) !== 1) {
                wp_die(esc_html__('A selected message ID is invalid.', 'adct-parish-intake'), '', [
                    'response' => 400,
                ]);
            }

            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($id)) {
                wp_die(esc_html__('A selected message ID is invalid.', 'adct-parish-intake'), '', [
                    'response' => 400,
                ]);
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    private function formatReceivedAt(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return 'Unknown date';
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC')
        );

        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            return $value;
        }

        $timezone = function_exists('wp_timezone')
            ? wp_timezone()
            : new DateTimeZone('Africa/Johannesburg');

        return $date->setTimezone($timezone)->format('Y-m-d H:i T');
    }

    private function timestamp(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, int|string> $arguments
     */
    private function pageUrl(array $arguments = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_SLUG], $arguments),
            admin_url('admin.php')
        );
    }

    private function requireReviewCapability(): void
    {
        if (! current_user_can(Capabilities::REVIEW)) {
            wp_die(esc_html__('You do not have permission to access the inbox.', 'adct-parish-intake'), '', [
                'response' => 403,
            ]);
        }
    }
}
