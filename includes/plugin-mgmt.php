<?php
/**
 * Plugin management endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_list_plugins() {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $all = get_plugins(); $active = get_option('active_plugins', []); $result = [];
    foreach ($all as $file => $data) {
        $slug = dirname($file);
        $result[] = ['file' => $file, 'slug' => $slug === '.' ? basename($file, '.php') : $slug, 'name' => $data['Name'], 'version' => $data['Version'], 'author' => $data['Author'], 'active' => in_array($file, $active, true)];
    }
    return $result;
}

function sbmcp_activate_plugin(WP_REST_Request $request) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin = $request->get_json_params()['plugin'] ?? null;
    if (!$plugin) return new WP_Error('missing_plugin', 'Provide a plugin file.', ['status' => 400]);
    if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin)) return new WP_Error('not_found', 'Plugin not found.', ['status' => 404]);
    $result = activate_plugin($plugin);
    if (is_wp_error($result)) return new WP_Error('activate_error', $result->get_error_message(), ['status' => 400]);
    return ['status' => 'activated', 'plugin' => $plugin];
}

function sbmcp_deactivate_plugin(WP_REST_Request $request) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin = $request->get_json_params()['plugin'] ?? null;
    if (!$plugin) return new WP_Error('missing_plugin', 'Provide a plugin file.', ['status' => 400]);
    deactivate_plugins($plugin);
    return ['status' => 'deactivated', 'plugin' => $plugin];
}

function sbmcp_delete_plugin(WP_REST_Request $request) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $slug = $request['slug'];
    if (!$slug) return new WP_Error('missing_slug', 'Provide a plugin slug.', ['status' => 400]);
    $all = get_plugins(); $plugin_file = null;
    foreach ($all as $file => $data) {
        $file_slug = dirname($file) === '.' ? basename($file, '.php') : dirname($file);
        if ($file_slug === $slug) { $plugin_file = $file; break; }
    }
    if (!$plugin_file) return new WP_Error('not_found', 'Plugin not found.', ['status' => 404]);
    if (is_plugin_active($plugin_file)) deactivate_plugins($plugin_file);
    $result = delete_plugins([$plugin_file]);
    if (is_wp_error($result)) return new WP_Error('delete_error', $result->get_error_message(), ['status' => 500]);
    return ['status' => 'deleted', 'plugin' => $plugin_file];
}
