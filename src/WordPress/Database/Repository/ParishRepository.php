<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

final class ParishRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_parishes';

    protected const FIELD_FORMATS = [
        'name' => '%s',
        'slug' => '%s',
        'area' => '%s',
        'church' => '%s',
        'kind' => '%s',
        'parent_parish_id' => '%d',
        'deanery_id' => '%d',
        'address' => '%s',
        'suburb' => '%s',
        'latitude' => '%f',
        'longitude' => '%f',
        'website' => '%s',
        'phone' => '%s',
        'official_source_id' => '%d',
        'expected_cadence_days' => '%d',
        'reminders_enabled' => '%d',
        'status' => '%s',
        'notes' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];
}
