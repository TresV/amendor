<?php

/**
 * Saved search/replace presets.
 *
 * Lets agencies save, export, import, and re-apply search/replace
 * configurations across sites. Presets are stored site-wide in the
 * `amendor_presets` option and travel between installs as JSON.
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return all saved presets, sorted by name.
 *
 * @return array<int,array{id:int,name:string,created:int,data:array}>
 */
function amendor_get_presets()
{
    $presets = get_option('amendor_presets', []);
    if (!is_array($presets)) {
        return [];
    }

    $presets = array_values(array_filter($presets, static function ($preset) {
        return is_array($preset) && isset($preset['id'], $preset['name']);
    }));

    usort($presets, static function ($a, $b) {
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $presets;
}

/**
 * Return a single preset by ID, or null.
 *
 * @param int $preset_id Preset ID.
 * @return array|null
 */
function amendor_get_preset($preset_id)
{
    foreach (amendor_get_presets() as $preset) {
        if ((int) $preset['id'] === (int) $preset_id) {
            return $preset;
        }
    }
    return null;
}

/**
 * Save (add) a preset and return its ID.
 *
 * @param string $name Preset name.
 * @param array  $data Preset data (validated).
 * @return int
 */
function amendor_save_preset($name, array $data)
{
    $presets = amendor_get_presets();
    $next_id = 1;
    foreach ($presets as $preset) {
        if ((int) $preset['id'] >= $next_id) {
            $next_id = (int) $preset['id'] + 1;
        }
    }

    $presets[] = [
        'id' => $next_id,
        'name' => sanitize_text_field((string) $name),
        'created' => time(),
        'data' => $data,
    ];

    update_option('amendor_presets', $presets, false);
    return $next_id;
}

/**
 * Delete a preset by ID.
 *
 * @param int $preset_id Preset ID.
 * @return bool Whether a preset was removed.
 */
function amendor_delete_preset($preset_id)
{
    $presets = amendor_get_presets();
    $filtered = array_values(array_filter($presets, static function ($preset) use ($preset_id) {
        return (int) $preset['id'] !== (int) $preset_id;
    }));

    update_option('amendor_presets', $filtered, false);
    return count($filtered) !== count($presets);
}

/**
 * Build preset data from the current search form POST input.
 *
 * @return array
 */
function amendor_build_preset_data()
{
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $replace = isset($_POST['replace']) ? sanitize_text_field(wp_unslash($_POST['replace'])) : '';
    $search_mode = isset($_POST['search_mode']) && in_array($_POST['search_mode'], ['partial', 'exact', 'regex'], true) ? $_POST['search_mode'] : 'partial';
    $content_sources = isset($_POST['content_sources']) ? array_map('sanitize_key', (array) $_POST['content_sources']) : [];
    $widget_types = isset($_POST['widget_types']) ? array_map('sanitize_text_field', (array) $_POST['widget_types']) : [];
    $field_keys = isset($_POST['field_keys']) ? sanitize_text_field(wp_unslash($_POST['field_keys'])) : '';
    $bulk_search = isset($_POST['bulk_search']) ? array_values(array_map(static function ($item) {
        return sanitize_text_field(wp_unslash((string) $item));
    }, (array) $_POST['bulk_search'])) : [];
    $bulk_replace = isset($_POST['bulk_replace']) ? array_values(array_map(static function ($item) {
        return sanitize_text_field(wp_unslash((string) $item));
    }, (array) $_POST['bulk_replace'])) : [];

    return [
        'search' => $search,
        'replace' => $replace,
        'search_mode' => $search_mode,
        'content_sources' => amendor_normalize_content_sources($content_sources),
        'widget_types' => amendor_normalize_selected_widgets($widget_types),
        'field_keys' => $field_keys,
        'bulk_search' => $bulk_search,
        'bulk_replace' => $bulk_replace,
    ];
}

/**
 * Validate and normalize preset data (from import).
 *
 * @param array $data Raw preset data.
 * @return array|null Normalized data, or null when invalid.
 */
function amendor_validate_preset_data(array $data)
{
    $search = isset($data['search']) ? sanitize_text_field((string) $data['search']) : '';
    $replace = isset($data['replace']) ? sanitize_text_field((string) $data['replace']) : '';
    $search_mode = isset($data['search_mode']) && in_array($data['search_mode'], ['partial', 'exact', 'regex'], true) ? $data['search_mode'] : 'partial';
    $content_sources = isset($data['content_sources']) && is_array($data['content_sources'])
        ? amendor_normalize_content_sources(array_map('sanitize_key', $data['content_sources']))
        : amendor_get_default_content_sources();
    $widget_types = isset($data['widget_types']) && is_array($data['widget_types'])
        ? amendor_normalize_selected_widgets(array_map('sanitize_text_field', $data['widget_types']))
        : [];
    $field_keys = isset($data['field_keys']) ? sanitize_text_field((string) $data['field_keys']) : '';
    $bulk_search = isset($data['bulk_search']) && is_array($data['bulk_search'])
        ? array_values(array_map(static function ($item) {
            return sanitize_text_field((string) $item);
        }, $data['bulk_search']))
        : [];
    $bulk_replace = isset($data['bulk_replace']) && is_array($data['bulk_replace'])
        ? array_values(array_map(static function ($item) {
            return sanitize_text_field((string) $item);
        }, $data['bulk_replace']))
        : [];

    return compact('search', 'replace', 'search_mode', 'content_sources', 'widget_types', 'field_keys', 'bulk_search', 'bulk_replace');
}

/**
 * Build the JSON export payload for a preset.
 *
 * @param array $preset Preset record.
 * @return array
 */
function amendor_build_preset_export_payload(array $preset)
{
    return [
        'type' => 'amendor_preset',
        'version' => 1,
        'name' => (string) $preset['name'],
        'created' => (int) $preset['created'],
        'data' => (array) $preset['data'],
    ];
}

/**
 * Stream a preset as a JSON download and exit.
 *
 * @param int $preset_id Preset ID.
 * @return bool False when the preset is missing.
 */
function amendor_send_preset_export($preset_id)
{
    $preset = amendor_get_preset((int) $preset_id);
    if (!$preset) {
        return false;
    }

    $json = wp_json_encode(amendor_build_preset_export_payload($preset), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $slug = sanitize_key($preset['name']);
    $slug = $slug !== '' ? $slug : 'preset';
    $filename = 'amendor-preset-' . $slug . '-' . gmdate('Ymd-His') . '.json';

    nocache_headers();
    header('Content-Type: application/json; charset=' . get_option('blog_charset', 'UTF-8'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download payload.
    exit;
}

/**
 * Import a preset from a JSON export string.
 *
 * @param string $json     JSON payload.
 * @param array  $messages Notices to append to.
 * @return bool Whether the import succeeded.
 */
function amendor_handle_import_preset($json, array &$messages)
{
    $data = json_decode((string) $json, true);
    if (!is_array($data) || (($data['type'] ?? '') !== 'amendor_preset') || !isset($data['data']) || !is_array($data['data'])) {
        $messages[] = ['type' => 'error', 'text' => __('❌ Invalid preset JSON. Export a preset from Amendor and import that file.', 'amendor')];
        return false;
    }

    $preset_data = amendor_validate_preset_data($data['data']);
    if ($preset_data === null) {
        $messages[] = ['type' => 'error', 'text' => __('❌ Preset data is not valid.', 'amendor')];
        return false;
    }

    $name = isset($data['name']) && is_string($data['name']) && $data['name'] !== ''
        ? sanitize_text_field($data['name'])
        : __('Imported Preset', 'amendor');

    amendor_save_preset($name, $preset_data);
    /* translators: %s: Preset name. */
    $messages[] = ['type' => 'success', 'text' => sprintf(__('✅ Preset "%s" imported.', 'amendor'), esc_html($name))];
    return true;
}

/**
 * Handle preset admin actions (save / delete / export / import).
 *
 * @param string $action   Current action.
 * @param array  $messages Notices to append to.
 * @return void
 */
function amendor_handle_presets_action($action, array &$messages)
{
    if (!in_array($action, ['save_preset', 'delete_preset', 'export_preset', 'import_preset'], true)) {
        return;
    }

    if (!amendor_current_user_can_manage()) {
        $messages[] = ['type' => 'error', 'text' => __('❌ You do not have permission to manage presets.', 'amendor')];
        return;
    }

    if (!isset($_POST['amendor_presets_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['amendor_presets_nonce'])), 'amendor_presets_action')) {
        $messages[] = ['type' => 'error', 'text' => __('❌ Security check failed for preset action.', 'amendor')];
        return;
    }

    switch ($action) {
        case 'save_preset':
            $name = isset($_POST['preset_name']) ? sanitize_text_field(wp_unslash($_POST['preset_name'])) : '';
            if ($name === '') {
                $messages[] = ['type' => 'error', 'text' => __('❌ Please enter a preset name.', 'amendor')];
                return;
            }
            amendor_save_preset($name, amendor_build_preset_data());
            /* translators: %s: Preset name. */
            $messages[] = ['type' => 'success', 'text' => sprintf(__('✅ Preset "%s" saved.', 'amendor'), esc_html($name))];
            break;

        case 'delete_preset':
            $preset_id = isset($_POST['preset_id']) ? intval($_POST['preset_id']) : 0;
            if ($preset_id > 0 && amendor_delete_preset($preset_id)) {
                $messages[] = ['type' => 'success', 'text' => __('✅ Preset deleted.', 'amendor')];
            } else {
                $messages[] = ['type' => 'error', 'text' => __('❌ Preset not found.', 'amendor')];
            }
            break;

        case 'export_preset':
            $preset_id = isset($_POST['preset_id']) ? intval($_POST['preset_id']) : 0;
            if (!amendor_send_preset_export($preset_id)) {
                $messages[] = ['type' => 'error', 'text' => __('❌ Preset not found for export.', 'amendor')];
            }
            break;

        case 'import_preset':
            $json = isset($_POST['preset_json']) ? wp_unslash($_POST['preset_json']) : '';
            amendor_handle_import_preset($json, $messages);
            break;
    }
}

/**
 * Render the Saved Presets box (list + import). Rendered outside the main
 * search form because each row is its own form.
 *
 * @return void
 */
function amendor_render_presets_box()
{
    $presets = amendor_get_presets();
    $page_url = admin_url('admin.php?page=' . amendor_get_admin_parent_slug());
?>
    <div id="amendor-presets" class="postbox" style="margin-top: 16px;">
        <h2 class="hndle"><span><?php esc_html_e('Saved Presets', 'amendor'); ?></span></h2>
        <div class="inside">
            <p class="description"><?php esc_html_e('Reusable search/replace configurations. Export as JSON to reuse across sites, or import one from another install.', 'amendor'); ?></p>

            <?php if (empty($presets)) : ?>
                <p><em><?php esc_html_e('No saved presets yet. Use "Save Current as Preset" in the sidebar to create one.', 'amendor'); ?></em></p>
            <?php else : ?>
                <ul style="margin: 0; padding: 0; list-style: none;">
                    <?php foreach ($presets as $preset) : ?>
                        <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                            <strong><?php echo esc_html($preset['name']); ?></strong>
                            <div style="margin-top: 6px; display: flex; gap: 6px; flex-wrap: wrap;">
                                <form method="post" action="<?php echo esc_url($page_url); ?>" style="display: inline;">
                                    <?php wp_nonce_field('amendor_presets_action', 'amendor_presets_nonce'); ?>
                                    <?php wp_nonce_field('amendor_search_action', 'amendor_search_nonce'); ?>
                                    <input type="hidden" name="preset_id" value="<?php echo esc_attr((int) $preset['id']); ?>">
                                    <button type="submit" name="action" value="apply_preset" class="button button-small button-primary">
                                        <?php esc_html_e('Apply', 'amendor'); ?>
                                    </button>
                                </form>
                                <form method="post" action="<?php echo esc_url($page_url); ?>" style="display: inline;">
                                    <?php wp_nonce_field('amendor_presets_action', 'amendor_presets_nonce'); ?>
                                    <input type="hidden" name="preset_id" value="<?php echo esc_attr((int) $preset['id']); ?>">
                                    <button type="submit" name="action" value="export_preset" class="button button-small">
                                        <?php esc_html_e('Export', 'amendor'); ?>
                                    </button>
                                </form>
                                <form method="post" action="<?php echo esc_url($page_url); ?>" style="display: inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this preset?', 'amendor')); ?>');">
                                    <?php wp_nonce_field('amendor_presets_action', 'amendor_presets_nonce'); ?>
                                    <input type="hidden" name="preset_id" value="<?php echo esc_attr((int) $preset['id']); ?>">
                                    <button type="submit" name="action" value="delete_preset" class="button button-small">
                                        <?php esc_html_e('Delete', 'amendor'); ?>
                                    </button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <hr style="margin: 12px 0;">
            <h4 style="margin: 0 0 6px;"><?php esc_html_e('Import preset', 'amendor'); ?></h4>
            <form method="post" action="<?php echo esc_url($page_url); ?>">
                <?php wp_nonce_field('amendor_presets_action', 'amendor_presets_nonce'); ?>
                <textarea name="preset_json" rows="4" style="width: 100%; box-sizing: border-box; font-family: monospace; font-size: 11px;" placeholder='{"type":"amendor_preset",...}'></textarea>
                <button type="submit" name="action" value="import_preset" class="button button-secondary" style="margin-top: 6px;">
                    <span class="dashicons dashicons-upload"></span> <?php esc_html_e('Import Preset', 'amendor'); ?>
                </button>
            </form>
        </div>
    </div>
<?php
}
