<?php
/**
 * Plugin Name: Waitpress
 * Description: Community garden waitlist management.
 * Version: 0.1.0
 * Author: Waitpress Contributors
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: waitpress
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WAITPRESS_VERSION', '0.1.0');
define('WAITPRESS_PLUGIN_FILE', __FILE__);
require_once __DIR__ . '/includes/class-waitpress-plugin.php';

function waitpress_bootstrap() {
    return Waitpress_Plugin::instance();
}

waitpress_bootstrap();
