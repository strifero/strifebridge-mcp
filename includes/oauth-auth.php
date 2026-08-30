<?php
/**
 * Authenticating a request as an OAuth-bound WordPress user.
 *
 * Two credentials can reach the same endpoints and they are not equivalent:
 *
 *   - A legacy bearer token. No user, no capability checks, full authority.
 *     Exactly as it behaved in 2.4.0, deliberately unchanged.
 *   - An OAuth access token. Bound to a WordPress user, so the request runs as
 *     that user and every capability check in WordPress and in the capability
 *     gate applies to it.
 *
 * Both arrive as `Authorization: Bearer <token>`, so this file is what decides
 * which one is in hand. The legacy token is checked first because it is a single
 * constant-time comparison against one option, and because an install that has
 * never used OAuth should not pay a database round trip on every request.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The OAuth context for the request being served, or null.
 *
 * Null means one of two things and callers should treat them identically: the
 * request presented a legacy bearer token, or it is not an API request at all.
 * In both cases there is no OAuth-bound user and the capability gate stands
 * down.
 *
 * @param array<string, mixed>|false $set Internal. Pass an array to set, false to clear.
 * @return array{user_id: int, client_id: string, scope: string, token_id: int}|null
 */
function sbmcp_oauth_context($set = null) {
    static $context = null;

    if ($set === false) {
        $context = null;
    } elseif (is_array($set)) {
        $context = $set;
    }

    return $context;
}

/**
 * Extracts a bearer token from the request.
 *
 * Checks the X-StrifeBridge-Token header too, because the legacy path accepts
 * it and dropping that would break installs that use it.
 *
 * @param WP_REST_Request $request
 * @return string Empty when no credential was presented.
 */
function sbmcp_oauth_bearer_from_request(WP_REST_Request $request): string {
    $custom = $request->get_header('X-StrifeBridge-Token');
    if ($custom) {
        return trim($custom);
    }

    $auth = $request->get_header('Authorization');
    if ($auth && stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    return '';
}

/**
 * True when the presented token is the legacy site token.
 *
 * @param string $token
 * @return bool
 */
function sbmcp_oauth_is_legacy_token(string $token): bool {
    $stored = get_option('sbmcp_api_token');
    if (!$stored || $token === '') {
        return false;
    }
    return hash_equals($stored, $token);
}

/**
 * Authenticates an OAuth access token and binds the request to its user.
 *
 * The wp_set_current_user() call is the load-bearing line in this file. Until it
 * runs, current_user_can() answers for nobody and every capability check passes
 * vacuously or fails uniformly. After it, the request genuinely *is* that user:
 * the capability gate works, and so does every capability check inside
 * WordPress itself — wp_insert_post(), delete_post(), and the rest stop being
 * reachable by anyone holding a credential and start being reachable by someone
 * with the authority to use them.
 *
 * Called from the permission callbacks rather than from the determine_current_user
 * filter, so it applies to this plugin's routes and changes nothing about how
 * the rest of the site resolves its user.
 *
 * @param string $token Presented access token.
 * @return bool True when the token is valid and the user is now bound.
 */
function sbmcp_oauth_authenticate_token(string $token): bool {
    if ($token === '') {
        return false;
    }

    $row = sbmcp_oauth_get_access_token($token);
    if (!$row) {
        return false;
    }

    $user_id = (int) $row['user_id'];
    $user    = get_userdata($user_id);

    // The bound account can be deleted while tokens for it are still live. A
    // token pointing at a user that no longer exists must not fall through to
    // running as nobody, which in WordPress means running with no capabilities
    // but also with no owner — refuse it outright and revoke it, since it can
    // never become valid again.
    if (!$user) {
        sbmcp_oauth_revoke_token_row((int) $row['id']);
        sbmcp_audit_log_auth_failure('OAuth token refused: the bound WordPress user no longer exists.');
        return false;
    }

    wp_set_current_user($user_id);

    sbmcp_oauth_context([
        'user_id'   => $user_id,
        'client_id' => (string) $row['client_id'],
        'scope'     => (string) $row['scope'],
        'token_id'  => (int) $row['id'],
    ]);

    // The client touch rides on the token touch's once-a-minute throttle.
    // Unthrottled it was an UPDATE on the clients table per request.
    if (sbmcp_oauth_touch_token($row)) {
        sbmcp_oauth_touch_client((string) $row['client_id']);
    }

    return true;
}

/**
 * Shared credential check for every StrifeBridge endpoint.
 *
 * @param WP_REST_Request $request
 * @param string          $surface Label used in the audit message ('API' or 'MCP').
 * @return bool
 */
function sbmcp_oauth_validate_request(WP_REST_Request $request, string $surface = 'API'): bool {
    // Emergency Lockdown stops everything, OAuth included. A kill switch that
    // only closed one of two doors would not be a kill switch.
    if (get_option('sbmcp_api_disabled')) {
        sbmcp_audit_log_auth_failure("{$surface} request refused: API is disabled (Emergency Lockdown).");
        return false;
    }

    sbmcp_oauth_context(false);

    $token = sbmcp_oauth_bearer_from_request($request);
    if ($token === '') {
        sbmcp_audit_log_auth_failure("{$surface} request refused: no credentials presented.");
        return false;
    }

    if (sbmcp_oauth_is_legacy_token($token)) {
        return true;
    }

    if (sbmcp_oauth_authenticate_token($token)) {
        return true;
    }

    sbmcp_audit_log_auth_failure("{$surface} request refused: the presented token is not valid.");
    return false;
}

// ---------------------------------------------------------------------------
// Telling an unauthenticated client where to authenticate
// ---------------------------------------------------------------------------

/**
 * Adds the WWW-Authenticate header to 401s from this plugin's routes.
 *
 * This is not decoration. It is how an MCP client discovers that the endpoint
 * speaks OAuth and where its metadata lives: the client makes an unauthenticated
 * request, reads `resource_metadata` out of the 401, fetches that document, and
 * follows it to the authorization server. Without this header a connector has
 * no way to get from "401" to "here is where you log in", and the ChatGPT and
 * Claude connector flows stall at the first request.
 *
 * @param WP_HTTP_Response $response
 * @param WP_REST_Server   $server
 * @param WP_REST_Request  $request
 * @return WP_HTTP_Response
 */
function sbmcp_oauth_add_www_authenticate($response, $server, $request) {
    if (!($response instanceof WP_HTTP_Response)) {
        return $response;
    }
    if ($response->get_status() !== 401) {
        return $response;
    }

    $route = $request->get_route();
    if (strpos($route, '/strifebridge/v1') !== 0 && strpos($route, '/pressbridge/v1') !== 0) {
        return $response;
    }

    $metadata = sbmcp_oauth_protected_resource_url();
    $response->header('WWW-Authenticate', sprintf('Bearer realm="StrifeBridge MCP", resource_metadata="%s"', $metadata));

    return $response;
}
add_filter('rest_post_dispatch', 'sbmcp_oauth_add_www_authenticate', 10, 3);
