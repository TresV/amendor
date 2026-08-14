<?php

/**
 * Plugin Name:       Amendor
 * Plugin URI:        https://thebrandplace.com/
 * Description:       Search and replace text within Elementor data, with backup, history, and persistent debug logging.
 * Version:           1.6.0
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

if (function_exists('ame_fs')) {
    // Freemius SDK is already initialized (e.g. the free version is active).
    // Register this file as the active plugin basename so the SDK can
    // auto-deactivate the free version when this (Pro) version is active.
    ame_fs()->set_basename(true, __FILE__);
} else {
    /**
     * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
     * `function_exists` CALL ABOVE TO PROPERLY WORK.
     */
    if (!function_exists('ame_fs')) {
        // Create a helper function for easy SDK access.
        function ame_fs() {
            global $ame_fs;

            if (!isset($ame_fs)) {
                // Include Freemius SDK.
                require_once dirname(__FILE__) . '/vendor/freemius/start.php';

                $ame_fs = fs_dynamic_init(array(
                    'id'                  => '37047',
                    'slug'                => 'amendor',
                    'premium_slug'        => 'amendor-pro',
                    'type'                => 'plugin',
                    'public_key'          => 'pk_012686be28e26e18e6fc293ad87d3',
                    'is_premium'          => true,
                    'premium_suffix'      => 'Amendor Pro',
                    // If your plugin is a serviceware, set this option to false.
                    'has_premium_version' => true,
                    'has_addons'          => false,
                    'has_paid_plans'      => true,
                    'is_org_compliant'    => true,
                    // Automatically removed in the free version. If you're not using the
                    // auto-generated free version, delete this line before uploading to wp.org.
                    'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                    'menu'                => array(
                        // Attach Freemius submenu items (Account, Contact Us) under Amendor.
                        'slug'        => 'amendor',
                        // Custom redirect after plugin activation.
                        'first-path'  => 'admin.php?page=amendor@welcome-message=true',
                        'support'     => false,
                    ),
                ));
            }

            return $ame_fs;
        }

        // Init Freemius.
        ame_fs();
        // Signal that SDK was initiated.
        do_action('ame_fs_loaded');
    }

    if (!defined('AMENDOR_PLUGIN_MODE')) {
        define('AMENDOR_PLUGIN_MODE', 'amendor');
    }
    if (!defined('AMENDOR_VERSION')) {
        define('AMENDOR_VERSION', '1.6.0');
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

    add_action('plugins_loaded', 'amendor_load_textdomain');

    register_activation_hook(AMENDOR_PLUGIN_FILE, 'amendor_activate_amendor');
    register_deactivation_hook(AMENDOR_PLUGIN_FILE, 'amendor_clear_log_pruning_schedule');

    add_action('admin_init', 'amendor_handle_backup_download');
}
