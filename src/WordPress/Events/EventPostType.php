<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Events;

use RuntimeException;

final class EventPostType
{
    public const POST_TYPE = 'adct_event';
    public const TAXONOMY = 'adct_event_type';
    public const CONTENT_VERSION_OPTION = 'adct_pi_event_content_version';
    public const SETUP_ERROR_OPTION = 'adct_pi_event_content_error';
    public const CURRENT_CONTENT_VERSION = 1;

    /**
     * @var list<array{name: string, slug: string}>
     */
    public const DEFAULT_TERMS = [
        ['name' => 'Social', 'slug' => 'social'],
        ['name' => 'Spiritual', 'slug' => 'spiritual'],
        ['name' => 'Formation', 'slug' => 'formation'],
        ['name' => 'Liturgy/Mass', 'slug' => 'liturgy-mass'],
        ['name' => 'Youth', 'slug' => 'youth'],
        ['name' => 'Outreach', 'slug' => 'outreach'],
        ['name' => 'Fundraising', 'slug' => 'fundraising'],
        ['name' => 'Meeting', 'slug' => 'meeting'],
        ['name' => 'Other', 'slug' => 'other'],
    ];

    public function register(): void
    {
        $this->registerPostType();
        $this->registerTaxonomy();
        $this->registerMeta();

        if ((int) get_option(self::CONTENT_VERSION_OPTION, 0) < self::CURRENT_CONTENT_VERSION) {
            $this->upgrade();
        }
    }

    public function activate(): void
    {
        $this->registerPostType();
        $this->registerTaxonomy();
        $this->registerMeta();
        $this->seedDefaultTerms();
        $this->saveVersion();
        flush_rewrite_rules(false);
        delete_option(self::SETUP_ERROR_OPTION);
    }

    public function renderSetupNotice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $message = get_option(self::SETUP_ERROR_OPTION, '');

        if (! is_string($message) || $message === '') {
            return;
        }
        ?>
        <div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
        <?php
    }

    private function registerPostType(): void
    {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }

        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Events',
                'singular_name' => 'Event',
                'add_new_item' => 'Add New Event',
                'edit_item' => 'Edit Event',
                'new_item' => 'New Event',
                'view_item' => 'View Event',
                'search_items' => 'Search Events',
                'not_found' => 'No events found.',
                'not_found_in_trash' => 'No events found in Trash.',
                'all_items' => 'All Events',
            ],
            'public' => true,
            'has_archive' => 'events',
            'rewrite' => [
                'slug' => 'events',
                'with_front' => false,
            ],
            'show_in_rest' => true,
            'supports' => [
                'title',
                'editor',
                'excerpt',
                'thumbnail',
                'revisions',
                'custom-fields',
            ],
            'taxonomies' => [self::TAXONOMY],
            'capability_type' => ['event', 'events'],
            'map_meta_cap' => true,
        ]);
    }

    private function registerTaxonomy(): void
    {
        if (taxonomy_exists(self::TAXONOMY)) {
            return;
        }

        register_taxonomy(self::TAXONOMY, [self::POST_TYPE], [
            'labels' => [
                'name' => 'Event Types',
                'singular_name' => 'Event Type',
                'search_items' => 'Search Event Types',
                'all_items' => 'All Event Types',
                'parent_item' => 'Parent Event Type',
                'parent_item_colon' => 'Parent Event Type:',
                'edit_item' => 'Edit Event Type',
                'update_item' => 'Update Event Type',
                'add_new_item' => 'Add New Event Type',
                'new_item_name' => 'New Event Type Name',
                'menu_name' => 'Event Types',
            ],
            'public' => true,
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => false,
            'show_in_rest' => true,
            'rest_base' => 'event-types',
            'capabilities' => [
                'assign_terms' => 'edit_events',
            ],
            'default_term' => [
                'name' => 'Other',
                'slug' => 'other',
            ],
        ]);
    }

    private function registerMeta(): void
    {
        $publicAccess = static function (
            $allowed,
            $metaKey,
            $postId,
            $userId,
            $cap,
            $caps
        ): bool {
            return true;
        };
        $editorAccess = static function (
            $allowed,
            $metaKey,
            $postId,
            $userId,
            $cap,
            $caps
        ): bool {
            return (int) $postId > 0 && current_user_can('edit_post', (int) $postId);
        };
        $localDateTime = [
            'type' => 'string',
            'maxLength' => 16,
        ];

        $definitions = [
            'parish_id' => [
                'type' => 'integer',
                'single' => true,
                'default' => 0,
                'show_in_rest' => ['schema' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'context' => ['view', 'edit'],
                ]],
                'sanitize_callback' => [self::class, 'sanitizeInteger'],
                'auth_callback' => $publicAccess,
            ],
            'venue_id' => [
                'type' => 'integer',
                'single' => true,
                'default' => 0,
                'show_in_rest' => ['schema' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'context' => ['view', 'edit'],
                ]],
                'sanitize_callback' => [self::class, 'sanitizeInteger'],
                'auth_callback' => $publicAccess,
            ],
            'start_local' => [
                'type' => 'string',
                'single' => true,
                'default' => '',
                'show_in_rest' => ['schema' => array_merge($localDateTime, [
                    'context' => ['view', 'edit'],
                ])],
                'sanitize_callback' => [self::class, 'sanitizeText'],
                'auth_callback' => $publicAccess,
            ],
            'end_local' => [
                'type' => 'string',
                'single' => true,
                'default' => '',
                'show_in_rest' => ['schema' => array_merge($localDateTime, [
                    'context' => ['view', 'edit'],
                ])],
                'sanitize_callback' => [self::class, 'sanitizeText'],
                'auth_callback' => $publicAccess,
            ],
            'all_day' => [
                'type' => 'boolean',
                'single' => true,
                'default' => false,
                'show_in_rest' => ['schema' => [
                    'type' => 'boolean',
                    'default' => false,
                    'context' => ['view', 'edit'],
                ]],
                'sanitize_callback' => [self::class, 'sanitizeBoolean'],
                'auth_callback' => $publicAccess,
            ],
            'rrule' => [
                'type' => 'string',
                'single' => true,
                'default' => '',
                'show_in_rest' => ['schema' => [
                    'type' => 'string',
                    'maxLength' => 512,
                    'context' => ['view', 'edit'],
                ]],
                'sanitize_callback' => [self::class, 'sanitizeText'],
                'auth_callback' => $publicAccess,
            ],
            'exdates' => [
                'type' => 'array',
                'single' => true,
                'default' => [],
                'show_in_rest' => ['schema' => [
                    'type' => 'array',
                    'items' => array_merge($localDateTime, [
                        'pattern' => '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$',
                    ]),
                    'maxItems' => 500,
                    'context' => ['view', 'edit'],
                ]],
                'sanitize_callback' => [self::class, 'sanitizeDateList'],
                'auth_callback' => $publicAccess,
            ],
            'rdates' => [
                'type' => 'array',
                'single' => true,
                'default' => [],
                'show_in_rest' => ['schema' => [
                    'type' => 'array',
                    'items' => array_merge($localDateTime, [
                        'pattern' => '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$',
                    ]),
                    'maxItems' => 500,
                    'context' => ['view', 'edit'],
                ]],
                'sanitize_callback' => [self::class, 'sanitizeDateList'],
                'auth_callback' => $publicAccess,
            ],
            'featured' => [
                'type' => 'boolean',
                'single' => true,
                'default' => false,
                'show_in_rest' => ['schema' => [
                    'type' => 'boolean',
                    'default' => false,
                    'context' => ['view', 'edit'],
                ]],
                'sanitize_callback' => [self::class, 'sanitizeBoolean'],
                'auth_callback' => $publicAccess,
            ],
            'status_flag' => [
                'type' => 'string',
                'single' => true,
                'default' => 'scheduled',
                'show_in_rest' => ['schema' => [
                    'type' => 'string',
                    'enum' => ['scheduled', 'cancelled', 'postponed'],
                    'default' => 'scheduled',
                    'context' => ['view', 'edit'],
                ]],
                'sanitize_callback' => [self::class, 'sanitizeKey'],
                'auth_callback' => $publicAccess,
            ],
            'source_candidate_id' => [
                'type' => 'integer',
                'single' => true,
                'default' => 0,
                'show_in_rest' => ['schema' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'context' => ['edit'],
                ]],
                'sanitize_callback' => [self::class, 'sanitizeInteger'],
                'auth_callback' => $editorAccess,
            ],
            'contact' => [
                'type' => 'object',
                'single' => true,
                'show_in_rest' => false,
                'sanitize_callback' => [self::class, 'sanitizeContact'],
                'auth_callback' => $editorAccess,
            ],
        ];

        foreach ($definitions as $metaKey => $definition) {
            register_post_meta(self::POST_TYPE, $metaKey, $definition);
        }
    }

    public static function sanitizeInteger(mixed $value): int
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    public static function sanitizeText(mixed $value): string
    {
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }

    public static function sanitizeBoolean(mixed $value): bool
    {
        return rest_sanitize_boolean($value);
    }

    public static function sanitizeKey(mixed $value): string
    {
        return is_scalar($value) ? sanitize_key((string) $value) : '';
    }

    /**
     * @return list<string>
     */
    public static function sanitizeDateList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $dates = [];

        foreach ($value as $date) {
            $dates[] = is_scalar($date) ? sanitize_text_field((string) $date) : '';
        }

        return array_values($dates);
    }

    /**
     * @return array{name: string, email: string, phone: string}
     */
    public static function sanitizeContact(mixed $value): array
    {
        if (! is_array($value)) {
            return [
                'name' => '',
                'email' => '',
                'phone' => '',
            ];
        }

        return [
            'name' => is_scalar($value['name'] ?? null)
                ? sanitize_text_field((string) $value['name'])
                : '',
            'email' => is_scalar($value['email'] ?? null)
                ? sanitize_email((string) $value['email'])
                : '',
            'phone' => is_scalar($value['phone'] ?? null)
                ? sanitize_text_field((string) $value['phone'])
                : '',
        ];
    }

    private function upgrade(): void
    {
        try {
            $this->seedDefaultTerms();
            $this->saveVersion();
            flush_rewrite_rules(false);
            delete_option(self::SETUP_ERROR_OPTION);
        } catch (RuntimeException $failure) {
            update_option(self::SETUP_ERROR_OPTION, $failure->getMessage(), false);
            error_log('[ADCT Parish Intake] Event content setup failed: ' . $failure->getMessage());
        }
    }

    private function seedDefaultTerms(): void
    {
        foreach (self::DEFAULT_TERMS as $term) {
            if (term_exists($term['slug'], self::TAXONOMY) || term_exists($term['name'], self::TAXONOMY)) {
                continue;
            }

            $result = wp_insert_term($term['name'], self::TAXONOMY, [
                'slug' => $term['slug'],
            ]);

            if (is_wp_error($result)) {
                if ($result->get_error_code() === 'term_exists') {
                    continue;
                }

                throw new RuntimeException(
                    'Could not seed the "' . $term['name'] . '" event type: ' . $result->get_error_message()
                );
            }
        }
    }

    private function saveVersion(): void
    {
        if (
            ! update_option(
                self::CONTENT_VERSION_OPTION,
                self::CURRENT_CONTENT_VERSION,
                false
            )
            && (int) get_option(self::CONTENT_VERSION_OPTION, 0) < self::CURRENT_CONTENT_VERSION
        ) {
            throw new RuntimeException('Could not save the event content version.');
        }
    }
}
