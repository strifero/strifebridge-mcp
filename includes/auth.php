<?php
/**
 * Token-based authentication for StrifeBridge MCP.
 *
 * Validates the bearer token via the X-StrifeBridge-Token header
 * or the Authorization: Bearer header.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_validate_token(WP_REST_Request $request): bool {
    if (get_option('sbmcp_api_disabled')) {
        sbmcp_audit_log_auth_failure('Request refused: API is disabled (Emergency Lockdown).');
        return false;
    }

    $stored = get_option('sbmcp_api_token');
    if (!$stored) {
        sbmcp_audit_log_auth_failure('Request refused: no API token is configured.');
        return false;
    }

    $token = $request->get_header('X-StrifeBridge-Token');
    if ($token) {
        $valid = hash_equals($stored, $token);
        if (!$valid) {
            sbmcp_audit_log_auth_failure('Invalid token presented in X-StrifeBridge-Token header.');
        }
        return $valid;
    }

    $auth = $request->get_header('Authorization');
    if ($auth && strpos($auth, 'Bearer ') === 0) {
        $valid = hash_equals($stored, substr($auth, 7));
        if (!$valid) {
            sbmcp_audit_log_auth_failure('Invalid bearer token presented in Authorization header.');
        }
        return $valid;
    }

    sbmcp_audit_log_auth_failure('Request refused: no credentials presented.');
    return false;
}
