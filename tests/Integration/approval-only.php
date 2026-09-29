<?php

if (! class_exists(\ADCT\ParishIntake\WordPress\Plugin::class)) {
    WP_CLI::error('Install and activate the built plugin ZIP before running approval checks.');
}

require_once __DIR__ . '/ApprovalDecisionCheck.php';
ApprovalDecisionCheck::run(static function (string $message): void {
    WP_CLI::error($message);
});

WP_CLI::success('Approver decision checks passed.');
