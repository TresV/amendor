<?php

/**
 * Plugin Name:       Amendor
 * Plugin URI:        https://thebrandplace.com/
 * Description:       Search and replace text within Elementor data, with backup, history, and persistent debug logging.
 * Version:           1.5.1
 * Author:            TheBrandPlace
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
    define('AMENDOR_VERSION', '1.5.1');
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
require_once AMENDOR_PLUGIN_DIR . 'includes/i18n.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/activation.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/functions.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/admin-actions.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/ajax-handlers.php';
require_once AMENDOR_PLUGIN_DIR . 'includes/admin-pages.php';

add_action('plugins_loaded', 'amendor_load_textdomain');

register_activation_hook(AMENDOR_PLUGIN_FILE, 'amendor_activate_amendor');

add_action('admin_init', 'amendor_handle_backup_download');
