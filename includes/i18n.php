<?php
/**
 * Internationalization Functions
 *
 * @package ElementorTextReplacer
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Load plugin textdomain.
 */
function amendor_load_textdomain()
{
    load_plugin_textdomain(
        AMENDOR_TEXT_DOMAIN,
        false,
        dirname(plugin_basename(AMENDOR_PLUGIN_FILE)) . '/languages/'
    );
}
// Hooked in main plugin file: add_action('plugins_loaded', 'amendor_load_textdomain');