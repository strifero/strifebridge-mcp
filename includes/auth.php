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
        return false;
    }

    $stored = get_option('sbmcp_api_token');
    if (!$stored) {
        return false;
    }

    $token = $request->get_header('X-StrifeBridge-Token');
    if ($token) {
        return hash_equals($stored, $token);
    }

    $auth = $request->get_header('Authorization');
    if ($auth && strpos($auth, 'Bearer ') === 0) {
        return hash_equals($stored, substr($auth, 7));
    }

    return false;
}
