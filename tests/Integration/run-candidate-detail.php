<?php

require_once __DIR__ . '/CandidateDetailCheck.php';

CandidateDetailCheck::run(static function (string $message): never {
    WP_CLI::error($message);
});

WP_CLI::success('Installed-ZIP candidate detail checks passed.');