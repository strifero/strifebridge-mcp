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
    'siteurl', 'home', 'admin_email',
    'default_role', 'users_can_register',
    'mailserver_url', 'mailserver_login', 'mailserver_pass',
    'wp_user_roles', 'db_version', 'cron',
]);

/**
 * Patterns that match option keys likely to hold credentials or secrets.
 * Used by list_options to filter out third-party plugin secrets.
 */
const SBMCP_OPTIONS_SENSITIVE_PATTERNS = [
    '/_key$/i',
    '/_secret$/i',
    '/token/i',
    '/password/i',
    '/_pass$/i',
    '/_user_roles$/i',
    '/_capabilities$/i',
];

function sbmcp_option_is_allowed(string $key): bool {
    // Guard every plugin-internal option. The sbmcp_ prefixed options store the
    // API token, lockdown state, tool-group toggles, and the abilities switch.
    // Letting the options tool read or write them would let a token holder undo
    // the controls an administrator configured (for example switching a disabled
    // tool group back on, or clearing sbmcp_abilities_disabled). None of these
    // are legitimately reachable through the generic options tool.
    if (strpos($key, 'sbmcp_') === 0) {
        return false;
    }

    // Prefix-aware guard for the role/capability maps. The static blacklist below
    // only lists the default-prefix 'wp_user_roles'; on installs with a custom
    // $wpdb->prefix the real options are {$prefix}user_roles and {$prefix}user_settings,
    // which would otherwise pass this check and let a token holder rewrite the
    // role -> capability map (a persistent privilege escalation). Block them
    // regardless of prefix, plus any key ending in _user_roles / _capabilities as
    // a backstop. Applied here so both the read and write paths are covered.
    global $wpdb;
    $prefix = (isset($wpdb) && !empty($wpdb->prefix)) ? $wpdb->prefix : 'wp_';
    if (in_array($key, [$prefix . 'user_roles', $prefix . 'user_settings'], true)) {
        return false;
    }
    if (preg_match('/(_user_roles|_capabilities)$/i', $key)) {
        return false;
    }

    return !in_array($key, SBMCP_OPTIONS_BLACKLIST, true);
}

function sbmcp_option_is_sensitive(string $key): bool {
    foreach (SBMCP_OPTIONS_SENSITIVE_PATTERNS as $pattern) {
        if (preg_match($pattern, $key)) return true;
    }
    return false;
}

function sbmcp_get_option(WP_REST_Request $request) {
    $key = $request->get_param('key');
    if (!$key) return new WP_Error('missing_key', 'Provide an option key.', ['status' => 400]);
    if (!sbmcp_option_is_allowed($key)) return new WP_Error('forbidden', 'This option cannot be accessed via the API.', ['status' => 403]);
    if (sbmcp_option_is_sensitive($key)) return new WP_Error('forbidden', 'This option key matches a sensitive pattern (key/secret/token/password) and cannot be read via the API.', ['status' => 403]);
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
    // Mirror the read path: a key matching a sensitive pattern (token/secret/key/
    // password/roles/caps) cannot be read, so it must not be writable either.
    // Without this the reader blocks it but the writer would happily overwrite a
    // third-party plugin's stored secret with an attacker-chosen value.
    if (sbmcp_option_is_sensitive($key)) return new WP_Error('forbidden_sensitive_option', 'This option key matches a sensitive pattern (key/secret/token/password) and cannot be modified via the API.', ['status' => 403]);

    // Distinguish a genuine no-op from a failed write. update_option() returns
    // false both when the stored value already equals the new one and when the
    // write fails, so pre-read with a sentinel default to tell "absent" apart
    // from a stored false, and only report "unchanged" when the value truly matches.
    $sentinel = '__sbmcp_option_absent__';
    $current  = get_option($key, $sentinel);
    if ($current !== $sentinel && $current === $value) {
        return ['status' => 'unchanged', 'key' => $key];
    }
    if (!update_option($key, $value)) {
        return new WP_Error('update_failed', 'The option value could not be written.', ['status' => 500]);
    }
    return ['status' => 'updated', 'key' => $key];
}

function sbmcp_list_options(WP_REST_Request $request) {
    global $wpdb;

    $keys = $request->get_param('keys');
    $max_value_bytes = (int) ($request->get_param('max_value_bytes') ?? 4096);
    $max_value_bytes = max(0, min($max_value_bytes, 65536));

    if (is_array($keys) && !empty($keys)) {
        // Explicit keys mode: caller asks for specific options by name.
        $safe_keys = [];
        foreach ($keys as $key) {
            $key = sanitize_text_field((string) $key);
            if ($key !== '' && sbmcp_option_is_allowed($key) && !sbmcp_option_is_sensitive($key)) {
                $safe_keys[] = $key;
            }
        }
        if (empty($safe_keys)) {
            return ['options' => [], 'count' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($safe_keys), '%s'));
        $sql  = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders) ORDER BY option_name";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $safe_keys), ARRAY_A);
        $rows = $rows ?: [];
    } else {
        // Pattern mode (existing behavior).
        $pattern = $request->get_param('pattern') ?? '%';
        $rows    = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 100", $pattern), ARRAY_A);
        $rows    = array_values(array_filter($rows ?: [], fn($r) => sbmcp_option_is_allowed($r['option_name']) && !sbmcp_option_is_sensitive($r['option_name'])));
    }

    // Cap large values so giant serialized transients don't blow past response size.
    if ($max_value_bytes > 0) {
        $rows = array_map(function($r) use ($max_value_bytes) {
            $v = $r['option_value'];
            if (is_string($v) && strlen($v) > $max_value_bytes) {
                $r['option_value']   = substr($v, 0, $max_value_bytes);
                $r['_truncated']     = true;
                $r['_original_bytes'] = strlen($v);
            }
            return $r;
        }, $rows);
    }

    return ['options' => $rows, 'count' => count($rows)];
}
