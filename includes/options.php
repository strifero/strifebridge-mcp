<?php
/**
 * WordPress options read/write endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SBMCP_OPTIONS_BLACKLIST', [
    'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
    'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
    'sbmcp_api_token', 'sbmcp_api_disabled',
    'active_plugins', 'template', 'stylesheet',
]);

function sbmcp_option_is_allowed(string $key): bool {
    return !in_array($key, SBMCP_OPTIONS_BLACKLIST, true);
}

function sbmcp_get_option(WP_REST_Request $request) {
    $key = $request->get_param('key');
    if (!$key) return new WP_Error('missing_key', 'Provide an option key.', ['status' => 400]);
    if (!sbmcp_option_is_allowed($key)) return new WP_Error('forbidden', 'This option cannot be accessed via the API.', ['status' => 403]);
    $value = get_option($key);
    if ($value === false) return new WP_Error('not_found', 'Option not found.', ['status' => 404]);
    return ['key' => $key, 'value' => $value];
}

function sbmcp_update_option(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $key    = $params['key']   ?? null;
    $value  = $params['value'] ?? null;
    if (!$key || $value === null) return new WP_Error('missing_fields', 'Provide key and value.', ['status' => 400]);
    if (!sbmcp_option_is_allowed($key)) return new WP_Error('forbidden', 'This option cannot be modified via the API.', ['status' => 403]);
    return ['status' => update_option($key, $value) ? 'updated' : 'unchanged', 'key' => $key];
}

function sbmcp_list_options(WP_REST_Request $request) {
    global $wpdb;
    $pattern = $request->get_param('pattern') ?? '%';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 100", $pattern), ARRAY_A);
    $rows = array_filter($rows, fn($r) => !in_array($r['option_name'], SBMCP_OPTIONS_BLACKLIST, true));
    return ['options' => array_values($rows), 'count' => count($rows)];
}
