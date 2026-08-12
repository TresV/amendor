<?php

/**
 * Elementor Editor Integration (experimental)
 *
 * Adds a floating search tool to the Elementor editor that highlights
 * matching widgets in the current document. Replacement is intentionally
 * performed in the Amendor admin UI (safe, with backups and undo).
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue Elementor editor assets.
 *
 * @return void
 */
function amendor_elementor_editor_assets()
{
    if (!class_exists('\Elementor\Plugin')) {
        return;
    }

    if (!apply_filters('amendor_enable_elementor_editor_integration', true)) {
        return;
    }

    $editor_js_path = AMENDOR_PLUGIN_DIR . 'assets/js/editor.js';
    $version = file_exists($editor_js_path) ? (string) filemtime($editor_js_path) : AMENDOR_VERSION;

    wp_enqueue_script(
        'amendor-elementor-editor',
        AMENDOR_PLUGIN_URL . 'assets/js/editor.js',
        ['jquery'],
        $version,
        true
    );

    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;

    wp_localize_script('amendor-elementor-editor', 'amendor_editor_vars', [
        'adminUrl' => admin_url('admin.php?page=' . amendor_get_admin_parent_slug()),
        'postId' => $post_id,
        'i18n' => [
            'title' => __('Amendor Search', 'amendor'),
            'placeholder' => __('Search for text in this page...', 'amendor'),
            'highlight' => __('Highlight', 'amendor'),
            'clear' => __('Clear', 'amendor'),
            'open' => __('Open in Amendor', 'amendor'),
            'found' => __('%d match(es) highlighted', 'amendor'),
            'none' => __('No matches found.', 'amendor'),
            'invalidRegex' => __('Invalid regular expression.', 'amendor'),
            'exact' => __('Exact', 'amendor'),
            'partial' => __('Partial', 'amendor'),
            'regex' => __('Regex', 'amendor'),
            'experimental' => __('Experimental — best-effort across Elementor versions; replacement happens in the Amendor admin.', 'amendor'),
        ],
    ]);
}
add_action('elementor/editor/after_enqueue_scripts', 'amendor_elementor_editor_assets');
