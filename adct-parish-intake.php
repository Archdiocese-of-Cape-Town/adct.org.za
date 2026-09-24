<?php
/**
 * Plugin Name: ADCT Parish Intake
 * Description: Offline-first parish email parsing with optional AI enrichment.
 * Version: 0.1.0
 * Author: Archdiocese of Cape Town
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor-prefixed/autoload.php';
require_once __DIR__ . '/src/WordPress/Autoloader.php';

ADCT\ParishIntake\WordPress\Autoloader::register();

register_activation_hook(__FILE__, ['ADCT\\ParishIntake\\WordPress\\Plugin', 'activate']);

ADCT\ParishIntake\WordPress\Plugin::boot(__FILE__);
