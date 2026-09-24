<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__
    . DIRECTORY_SEPARATOR . 'src'
    . DIRECTORY_SEPARATOR . 'Core'
    . DIRECTORY_SEPARATOR . 'Auth'
    . DIRECTORY_SEPARATOR . 'Capabilities.php';

foreach (array_keys(\ADCT\ParishIntake\Core\Auth\Capabilities::customRoleLabels()) as $role) {
    remove_role($role);
}

foreach (\ADCT\ParishIntake\Core\Auth\Capabilities::builtInRoles() as $roleName) {
    $role = get_role($roleName);

    if ($role === null) {
        continue;
    }

    foreach (\ADCT\ParishIntake\Core\Auth\Capabilities::all() as $capability) {
        $role->remove_cap($capability);
    }
}
