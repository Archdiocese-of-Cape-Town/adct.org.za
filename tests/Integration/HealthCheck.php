<?php

declare(strict_types=1);

use ADCT\ParishIntake\Core\Auth\Capabilities;
use ADCT\ParishIntake\Core\Jobs\JobState;
use ADCT\ParishIntake\WordPress\Jobs\WordPressJobStateStore;

$healthSlug = 'adct-parish-intake-health';
$healthItems = array_values(array_filter(
    $GLOBALS['submenu']['adct-parish-intake'] ?? [],
    static fn ($item): bool => is_array($item) && ($item[2] ?? null) === $healthSlug
));
if (count($healthItems) !== 1 || $healthItems[0][1] !== Capabilities::VIEW_REPORTS) {
    $fail('The health page must be protected by report access.');
}
if (has_action('admin_post_adct_pi_health_check_now') === false) {
    $fail('The capability- and nonce-checked Check now action was not registered.');
}

$healthHook = get_plugin_page_hookname($healthSlug, 'adct-parish-intake');
$store = new WordPressJobStateStore();
$previous = $store->load('framework_heartbeat');
$store->save('framework_heartbeat', new JobState(
    lastRunAt: new DateTimeImmutable('2026-09-25T10:00:00+02:00'),
    lastSuccessAt: new DateTimeImmutable('2026-09-25T10:01:00+02:00'),
    lastTrigger: 'cron',
    consecutiveFailures: 3
));
ob_start();
try {
    do_action($healthHook);
} finally {
    $healthHtml = (string) ob_get_clean();
    $store->save('framework_heartbeat', $previous);
}
foreach ([
    'Parish Intake health',
    'Check now',
    'Last run',
    'Trigger',
    'cron',
    'Failures in a row',
    '25/09/2026 10:00',
    'Mailboxes',
    'Sources',
    'oldest waiting',
    'Deaneries without an active approver',
    'What to do',
] as $expected) {
    if (! str_contains($healthHtml, $expected)) {
        $fail('The release ZIP health dashboard is missing: ' . $expected);
    }
}
if (! str_contains($healthHtml, 'name="_wpnonce"')) {
    $fail('The Check now form has no nonce.');
}
