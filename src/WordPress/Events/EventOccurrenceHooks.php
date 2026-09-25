<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Events\OccurrenceWindow;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use DateTimeZone;
use Throwable;

final class EventOccurrenceHooks
{
    /**
     * @var array<int, Throwable>
     */
    private array $restFailures = [];

    public function __construct(
        private WordPressEventOccurrenceMaintenance $maintenance,
        private ClockInterface $clock,
        private DateTimeZone $timezone
    ) {
    }

    public function handleSavePost(int $postId, \WP_Post $post, bool $update): void
    {
        if (
            $post->post_type !== EventPostType::POST_TYPE
            || $post->post_status !== 'publish'
            || wp_is_post_revision($postId)
            || wp_is_post_autosave($postId)
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || (defined('REST_REQUEST') && REST_REQUEST)
        ) {
            return;
        }

        $rawForm = $_POST[EventEditor::FORM_KEY] ?? null;
        $nonce = $_POST[EventEditor::NONCE_FIELD] ?? null;

        if ($rawForm !== null || $nonce !== null) {
            if (
                ! is_array($rawForm)
                || ! is_scalar($nonce)
                || ! current_user_can('edit_post', $postId)
            ) {
                return;
            }

            $nonce = (string) wp_unslash((string) $nonce);

            if (! wp_verify_nonce($nonce, EventEditor::NONCE_ACTION_PREFIX . $postId)) {
                return;
            }
        }

        try {
            $this->maintenance->rebuildEvent($postId, $this->currentWindow());
        } catch (Throwable $failure) {
            $this->recordFailure($postId, $failure, false);
        }
    }

    public function handleRestAfterInsert(
        \WP_Post $post,
        \WP_REST_Request $request,
        bool $creating
    ): void {
        if (
            $post->post_type !== EventPostType::POST_TYPE
            || $post->post_status !== 'publish'
        ) {
            return;
        }

        try {
            $this->maintenance->rebuildEvent((int) $post->ID, $this->currentWindow());
        } catch (Throwable $failure) {
            $this->recordFailure((int) $post->ID, $failure, true);
        }
    }

    public function handleStatusTransition(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if (
            $post->post_type !== EventPostType::POST_TYPE
            || $newStatus === 'publish'
            || $newStatus === $oldStatus
        ) {
            return;
        }

        $this->deleteForNonPublicEvent((int) $post->ID);
    }

    public function handleBeforeDeletePost(int $postId, \WP_Post $post): void
    {
        if ($post->post_type === EventPostType::POST_TYPE) {
            $this->deleteForNonPublicEvent($postId);
        }
    }

    /**
     * @param mixed $response
     * @return mixed
     */
    public function filterRestResponse(
        $response,
        \WP_REST_Server $server,
        \WP_REST_Request $request
    ) {
        if (
            ! in_array(strtoupper($request->get_method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || preg_match('#^/wp/v2/adct_event(?:/\d+)?$#', $request->get_route()) !== 1
        ) {
            return $response;
        }

        $postId = absint($request->get_param('id'));

        if ($postId < 1 && $response instanceof \WP_REST_Response) {
            $data = $response->get_data();
            $postId = is_array($data) ? absint($data['id'] ?? 0) : 0;
        }

        if ($postId < 1 || ! isset($this->restFailures[$postId])) {
            return $response;
        }

        unset($this->restFailures[$postId]);

        return new \WP_Error(
            'adct_event_occurrence_rebuild_failed',
            'The event was saved, but its occurrence dates could not be refreshed. The previous occurrences were kept; retry the save or contact an administrator.',
            ['status' => 500]
        );
    }

    public function renderFailureNotice(): void
    {
        $screen = get_current_screen();

        if (
            ! $screen instanceof \WP_Screen
            || $screen->post_type !== EventPostType::POST_TYPE
            || ! isset($_GET['post'])
        ) {
            return;
        }

        $postId = absint($_GET['post']);

        if ($postId < 1 || ! current_user_can('edit_post', $postId)) {
            return;
        }

        $key = $this->failureTransientKey($postId);
        $message = get_transient($key);

        if (! is_string($message) || $message === '') {
            return;
        }

        delete_transient($key);
        ?>
        <div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
        <?php
    }

    private function currentWindow(): OccurrenceWindow
    {
        return OccurrenceWindow::rollingTwelveMonths($this->clock->now(), $this->timezone);
    }

    private function deleteForNonPublicEvent(int $postId): void
    {
        try {
            $this->maintenance->deleteEventOccurrences($postId);
        } catch (Throwable $failure) {
            $this->recordFailure($postId, $failure, true);
        }
    }

    private function recordFailure(int $postId, Throwable $failure, bool $rest): void
    {
        error_log(
            '[ADCT Parish Intake] Event occurrence update failed for event '
            . $postId
            . ' ('
            . get_class($failure)
            . '): '
            . $failure->getMessage()
        );

        if ($rest) {
            $this->restFailures[$postId] = $failure;
        }

        if (is_admin() && current_user_can('edit_post', $postId)) {
            set_transient(
                $this->failureTransientKey($postId),
                'The occurrence dates could not be updated. The previous rows were kept; correct the event and retry, or contact an administrator.',
                900
            );
        }
    }

    private function failureTransientKey(int $postId): string
    {
        return 'adct_pi_occurrence_error_' . get_current_user_id() . '_' . $postId;
    }
}
