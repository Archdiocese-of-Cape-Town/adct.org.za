<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

final class SourceRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_sources';

    protected const FIELD_FORMATS = [
        'parish_id' => '%d',
        'type' => '%s',
        'identifier' => '%s',
        'role' => '%s',
        'status' => '%s',
        'poll_interval_minutes' => '%d',
        'checkpoint' => '%s',
        'last_checked_at' => '%s',
        'last_success_at' => '%s',
        'last_item_at' => '%s',
        'consecutive_failures' => '%d',
        'last_error' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];
}
