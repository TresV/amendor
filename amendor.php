<?php

/**
 * Plugin Name:       Amendor
 * Description:       Search and replace text within Elementor data, with backup, history, and persistent debug logging.
 * Version:           1.0.2
 * Author:            TheBrandPlaceLTD
 * Author URI:        https://thebrandplace.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       amendor
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Elementor tested up to: 3.15
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('AMENDOR_PLUGIN_MODE')) {
    define('AMENDOR_PLUGIN_MODE', 'amendor');
}
if (!defined('AMENDOR_VERSION')) {
    define('AMENDOR_VERSION', '1.0.2');
}
if (!defined('AMENDOR_PLUGIN_DIR')) {
    define('AMENDOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('AMENDOR_PLUGIN_URL')) {
    define('AMENDOR_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('AMENDOR_PLUGIN_FILE')) {
    define('AMENDOR_PLUGIN_FILE', __FILE__);
}
if (!defined('AMENDOR_TEXT_DOMAIN')) {
    define('AMENDOR_TEXT_DOMAIN', 'amendor');
}

require_once AMENDOR_PLUGIN_DIR . 'includes/plugin-context.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/activation.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/search-data.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/backups.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/search-engine.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/search-cache.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/presets.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/render-results.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/action-handlers.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/admin-chrome.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/admin-main-page.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/log-debug-page.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/log-history-page.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/elementor-editor.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/ajax-handlers.php';

register_activation_hook(AMENDOR_PLUGIN_FILE, 'amendor_activate_amendor');
register_deactivation_hook(AMENDOR_PLUGIN_FILE, 'amendor_clear_log_pruning_schedule');

add_action('admin_init', 'amendor_handle_backup_download');
