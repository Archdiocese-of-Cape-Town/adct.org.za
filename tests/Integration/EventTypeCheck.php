<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\WordPress\Events\EventPostType;
use ADCT\ParishIntake\WordPress\Events\EventTypeKeywords;

final class EventTypeCheck
{
    public static function run(callable $fail): void
    {
        $types = new EventTypeKeywords();
        $fundraising = get_term_by('slug', 'fundraising', EventPostType::TAXONOMY);
        $pilgrimage = get_term_by('slug', 'pilgrimage', EventPostType::TAXONOMY);
        if (! $fundraising instanceof WP_Term || ! $pilgrimage instanceof WP_Term
            || get_term_meta($fundraising->term_id, EventTypeKeywords::META_KEY, true) === []) {
            $fail('The default event type keywords or Pilgrimage term were not seeded.');
        }

        $original = get_term_meta($fundraising->term_id, EventTypeKeywords::META_KEY, true);
        $meeting = get_term_by('slug', 'meeting', EventPostType::TAXONOMY);
        $previousUser = get_current_user_id();
        $previousPost = $_POST;
        $_POST = [];
        $customPilgrimageId = null;
        $administrators = get_users(['role' => 'administrator', 'number' => 1]);
        if ($administrators === []) {
            $fail('The integration site has no administrator for the keyword editor check.');
        }
        try {
            wp_set_current_user($administrators[0]->ID);
            wp_delete_term($pilgrimage->term_id, EventPostType::TAXONOMY);
            update_option(EventPostType::CONTENT_VERSION_OPTION, 1);
            (new EventPostType())->register();
            $pilgrimage = get_term_by('slug', 'pilgrimage', EventPostType::TAXONOMY);
            $fundraisingAfterUpgrade = get_term_by('slug', 'fundraising', EventPostType::TAXONOMY);
            $meetingAfterUpgrade = get_term_by('slug', 'meeting', EventPostType::TAXONOMY);
            if (! $pilgrimage instanceof WP_Term
                || ! $meeting instanceof WP_Term
                || ! $fundraisingAfterUpgrade instanceof WP_Term
                || ! $meetingAfterUpgrade instanceof WP_Term
                || $fundraisingAfterUpgrade->term_id !== $fundraising->term_id
                || $meetingAfterUpgrade->term_id !== $meeting->term_id
                || get_term_meta($pilgrimage->term_id, EventTypeKeywords::META_KEY, true)
                    !== \ADCT\ParishIntake\Core\Parsing\EventTypeClassifier::DEFAULT_KEYWORDS['pilgrimage']) {
                $fail('The v1 taxonomy upgrade did not add Pilgrimage while preserving Meeting and Fundraising.');
            }

            if (EventTypeKeywords::sanitize(" retreat \nRETREAT\n parish picnic ") !== ['retreat', 'parish picnic']) {
                $fail('Keyword input was not trimmed and de-duplicated safely.');
            }
            $tooManyKeywords = implode("\n", array_map(
                static fn (int $number): string => 'keyword' . $number,
                range(1, 26)
            ));
            foreach ([$tooManyKeywords, str_repeat('a', 101), str_repeat('a', 3001)] as $invalidKeywords) {
                try {
                    EventTypeKeywords::sanitize($invalidKeywords);
                    $fail('An over-limit keyword list was accepted.');
                } catch (InvalidArgumentException $expected) {
                }
            }

            $_POST = [
                '_wpnonce' => wp_create_nonce('update-tag_' . $fundraising->term_id),
                'adct_pi_type_keywords' => "community gala\nbenefit dinner",
            ];
            if (wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['_wpnonce'])),
                'update-tag_' . $fundraising->term_id
            ) === false) {
                $fail('The integration editor could not verify its generated event-type nonce.');
            }
            $types->save($fundraising->term_id);
            $_POST = [];
            $factory = new PipelineFactory(null, null, $types);
            $parse = static fn (string $subject) => $factory->create()->parse(new Message(
                'email', 'synthetic-event', 'sender@example.test', 'Parish office',
                $subject, 'Join us on 12 Oct 2026 at 19:00.'
            ));
            if ($parse('Community gala')->getField('event_type') !== 'fundraising'
                || $parse('Fundraiser')->getField('event_type') !== 'other') {
                $fail(sprintf(
                    'An admin keyword edit did not change live event classification (community gala: %s, fundraiser: %s).',
                    $parse('Community gala')->getField('event_type') ?? 'missing',
                    $parse('Fundraiser')->getField('event_type') ?? 'missing'
                ));
            }
            update_option(EventPostType::CONTENT_VERSION_OPTION, 1);
            (new EventPostType())->register();
            if (get_term_meta($fundraising->term_id, EventTypeKeywords::META_KEY, true)
                !== ['community gala', 'benefit dinner']) {
                $fail('A versioned upgrade overwrote an edited keyword list.');
            }

            wp_delete_term($pilgrimage->term_id, EventPostType::TAXONOMY);
            $alternative = wp_insert_term('Pilgrimage', EventPostType::TAXONOMY, ['slug' => 'local-pilgrimage']);
            if (is_wp_error($alternative)) {
                $fail('Could not prepare an existing customized Pilgrimage term.');
            }
            $customPilgrimageId = (int) $alternative['term_id'];
            update_term_meta($alternative['term_id'], EventTypeKeywords::META_KEY, ['local journey']);
            update_option(EventPostType::CONTENT_VERSION_OPTION, 1);
            (new EventPostType())->register();
            $pilgrimageTerms = get_terms([
                'taxonomy' => EventPostType::TAXONOMY,
                'hide_empty' => false,
                'name' => 'Pilgrimage',
            ]);
            if (get_term_by('slug', 'pilgrimage', EventPostType::TAXONOMY) !== false
                || ! is_array($pilgrimageTerms)
                || count($pilgrimageTerms) !== 1
                || get_term_meta($alternative['term_id'], EventTypeKeywords::META_KEY, true) !== ['local journey']
                || $parse('Local journey')->getField('event_type') !== 'local-pilgrimage') {
                $fail('The upgrade duplicated or reset a site-customized Pilgrimage term.');
            }
        } finally {
            $_POST = [];
            update_term_meta($fundraising->term_id, EventTypeKeywords::META_KEY, $original);
            if ($customPilgrimageId !== null) {
                wp_delete_term($customPilgrimageId, EventPostType::TAXONOMY);
            }
            (new EventPostType())->activate();
            $_POST = $previousPost;
            wp_set_current_user($previousUser);
        }
    }
}
