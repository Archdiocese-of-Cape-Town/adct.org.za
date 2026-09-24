<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Database\Repository;

final class DeaneryRepository extends AbstractRepository
{
    protected const TABLE_SUFFIX = 'adct_pi_deaneries';

    protected const FIELD_FORMATS = [
        'name' => '%s',
        'slug' => '%s',
        'dean_name' => '%s',
        'vice_dean_name' => '%s',
        'secretary_name' => '%s',
        'status' => '%s',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];
}
