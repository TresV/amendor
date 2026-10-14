<?php

/**
 * Elementor Editor Integration (experimental)
 *
 * Adds a floating search tool to the Elementor editor that highlights
 * matching widgets in the current document and can replace values in place.
 * In-editor replacements go through Elementor's own settings commands, so they
 * are undone with Elementor's history (Cmd/Ctrl+Z) and only persist once the
 * page is saved. Multi-page work with backups belongs in the Amendor admin UI.
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

    // The in-editor live tool is enabled by default; it can be disabled with
    // the `amendor_enable_elementor_editor_integration` filter above.
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
            /* translators: %d: Number of matches highlighted. */
            'found' => __('%d match(es) highlighted', 'amendor'),
            'none' => __('No matches found.', 'amendor'),
            'invalidRegex' => __('Invalid regular expression.', 'amendor'),
            'exact' => __('Exact', 'amendor'),
            'partial' => __('Partial', 'amendor'),
            'regex' => __('Regex', 'amendor'),
            'replace' => __('Replace with', 'amendor'),
            'undo' => __('Undo', 'amendor'),
            /* translators: %d: Number of values replaced. */
            'replaced' => __('%d value(s) replaced', 'amendor'),
            'reverted' => __('Changes restored.', 'amendor'),
            /* translators: %d: Number of values that will be replaced. */
            'confirmReplace' => __('Replace %d value(s) on this page? You can undo afterwards.', 'amendor'),
            'enterTerm' => __('Enter a search term first.', 'amendor'),
            'fields' => __('Fields', 'amendor'),
            'fieldText' => __('Text & content', 'amendor'),
            'fieldUrl' => __('URLs & links', 'amendor'),
            'fieldShortcode' => __('Shortcodes', 'amendor'),
            'fieldCode' => __('Code & CSS', 'amendor'),
            'fieldOther' => __('Other (incl. internal)', 'amendor'),
            /* translators: %d: Number of selected occurrences to replace. */
            'replaceSelected' => __('Replace Selected (%d)', 'amendor'),
            /* translators: 1: Number of selected occurrences, 2: Total number of occurrences. */
            'selectedOf' => __('%1$d of %2$d selected', 'amendor'),
        ],
    ]);
}
add_action('elementor/editor/after_enqueue_scripts', 'amendor_elementor_editor_assets');
