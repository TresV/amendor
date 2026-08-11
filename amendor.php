<?php
/**
 * Plugin Name:       Amendor
 * Plugin URI:        https://example.com/amendor/
 * Description:       Search and replace text within Elementor data, with backup, history, and persistent debug logging.
 * Version:           1.5.1
 * Author:            Your Name
 * Author URI:        https://example.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       elementor-text-replacer
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Elementor tested up to: 3.15
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AMENDOR_PLUGIN_MODE', 'amendor');
define('AMENDOR_VERSION', '1.5.1');
define('AMENDOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AMENDOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AMENDOR_PLUGIN_FILE', __FILE__);
define('AMENDOR_TEXT_DOMAIN', 'elementor-text-replacer');

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
