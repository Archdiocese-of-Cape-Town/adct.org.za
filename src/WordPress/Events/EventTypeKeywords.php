<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use ADCT\ParishIntake\Core\Ports\EventTypeKeywordProviderInterface;
use InvalidArgumentException;
use RuntimeException;

final class EventTypeKeywords implements EventTypeKeywordProviderInterface
{
    public const META_KEY = 'adct_pi_type_keywords';
    private const MAX_LINES = 25;
    private const MAX_BYTES = 100;
    private const MAX_INPUT_BYTES = 3000;

    /** @return array<string, list<string>> */
    public function keywordLists(): array
    {
        $terms = get_terms(['taxonomy' => EventPostType::TAXONOMY, 'hide_empty' => false]);
        if (is_wp_error($terms) || ! is_array($terms)) {
            throw new RuntimeException('Event type keyword lists could not be loaded.');
        }
        $lists = [];
        foreach ($terms as $term) {
            if (! metadata_exists('term', $term->term_id, self::META_KEY)) {
                $lists[$term->slug] = [];
                continue;
            }
            $value = get_term_meta($term->term_id, self::META_KEY, true);
            if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_LINES) {
                throw new RuntimeException('Invalid keywords stored for event type ' . $term->term_id . '.');
            }
            foreach ($value as $phrase) {
                if (
                    ! is_string($phrase)
                    || $phrase === ''
                    || trim($phrase) !== $phrase
                    || strlen($phrase) > self::MAX_BYTES
                    || preg_match('/[\p{L}]/u', $phrase) !== 1
                ) {
                    throw new RuntimeException('Invalid keywords stored for event type ' . $term->term_id . '.');
                }
            }
            $lists[$term->slug] = $value;
        }
        if (! array_key_exists('other', $lists)) {
            throw new RuntimeException('The Other event type must exist for classification.');
        }
        return $lists;
    }

    public function renderAddField(): void
    {
        ?>
        <div class="form-field">
            <label for="adct_pi_type_keywords">Classification keywords</label>
            <textarea id="adct_pi_type_keywords" name="adct_pi_type_keywords" rows="5" maxlength="<?php echo esc_attr((string) self::MAX_INPUT_BYTES); ?>"></textarea>
            <p>One word or phrase per line (up to 25). Blank means this type is not auto-matched.</p>
        </div>
        <?php
    }

    public function renderEditField(\WP_Term $term): void
    {
        $value = get_term_meta($term->term_id, self::META_KEY, true);
        $lines = is_array($value) ? implode("\n", $value) : '';
        ?>
        <tr class="form-field">
            <th scope="row"><label for="adct_pi_type_keywords">Classification keywords</label></th>
            <td>
                <textarea id="adct_pi_type_keywords" name="adct_pi_type_keywords" rows="6" cols="50" maxlength="<?php echo esc_attr((string) self::MAX_INPUT_BYTES); ?>"><?php echo esc_textarea($lines); ?></textarea>
                <p class="description">One word or phrase per line (up to 25). Blank disables automatic matching for this type.</p>
            </td>
        </tr>
        <?php
    }

    public function save(int $termId): void
    {
        if (! isset($_POST['adct_pi_type_keywords'])) {
            return;
        }
        if (! current_user_can('edit_term', $termId)) {
            wp_die(esc_html__('You cannot edit this event type.', 'adct-parish-intake'));
        }
        $isNew = isset($_POST['_wpnonce_add-tag']);
        $nonce = $isNew ? $_POST['_wpnonce_add-tag'] : ($_POST['_wpnonce'] ?? null);
        $action = $isNew ? 'add-tag' : 'update-tag_' . $termId;
        if (! is_string($nonce) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), $action)) {
            wp_die(esc_html__('The event type form expired. Reload it and try again.', 'adct-parish-intake'));
        }
        try {
            $keywords = self::sanitize(wp_unslash($_POST['adct_pi_type_keywords']));
        } catch (InvalidArgumentException $failure) {
            wp_die(esc_html($failure->getMessage()));
        }
        update_term_meta($termId, self::META_KEY, $keywords);
        if (get_term_meta($termId, self::META_KEY, true) !== $keywords) {
            wp_die(esc_html__('The event type keywords could not be saved.', 'adct-parish-intake'));
        }
    }

    /** @return list<string> */
    public static function sanitize(mixed $input): array
    {
        if (! is_string($input) || strlen($input) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('The keyword list must be text of at most 3000 bytes.');
        }
        $lines = preg_split('/\R/u', $input);
        if ($lines === false) {
            throw new InvalidArgumentException('The keyword list must contain valid text.');
        }
        $keywords = [];
        foreach ($lines as $line) {
            $phrase = trim(sanitize_text_field($line));
            if ($phrase === '') {
                continue;
            }
            if (strlen($phrase) > self::MAX_BYTES || preg_match('/[\p{L}]/u', $phrase) !== 1) {
                throw new InvalidArgumentException('Each keyword must contain letters and be at most 100 bytes.');
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($phrase, 'UTF-8') : strtolower($phrase);
            if (! array_key_exists($key, $keywords)) {
                $keywords[$key] = $phrase;
            }
            if (count($keywords) > self::MAX_LINES) {
                throw new InvalidArgumentException('Use at most 25 keywords per event type.');
            }
        }
        return array_values($keywords);
    }
}
