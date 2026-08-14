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

    // The in-editor live tool is a Pro feature (stripped from the free build).
    if (ame_fs()->is__premium_only()) {
        if (!amendor_can_use_premium_features()) {
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
            'version' => AMENDOR_VERSION,
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
                'replace' => __('Replace with', 'amendor'),
                'undo' => __('Undo', 'amendor'),
                'replaced' => __('%d value(s) replaced', 'amendor'),
                'reverted' => __('Changes restored.', 'amendor'),
                'confirmReplace' => __('Replace %d value(s) on this page? You can undo afterwards.', 'amendor'),
                'enterTerm' => __('Enter a search term first.', 'amendor'),
                'fields' => __('Fields', 'amendor'),
                'fieldText' => __('Text & content', 'amendor'),
                'fieldUrl' => __('URLs & links', 'amendor'),
                'fieldShortcode' => __('Shortcodes', 'amendor'),
                'fieldCode' => __('Code & CSS', 'amendor'),
                'fieldOther' => __('Other (incl. internal)', 'amendor'),
                'replaceSelected' => __('Replace Selected (%d)', 'amendor'),
                'selectedOf' => __('%d of %d selected', 'amendor'),
            ],
        ]);
    }
}
add_action('elementor/editor/after_enqueue_scripts', 'amendor_elementor_editor_assets');
