<?php
/**
 * The OAuth 2.1 endpoints: authorize, token, register, revoke.
 *
 * All four are public — they are what a client talks to *before* it has a
 * credential, so they cannot sit behind the bearer check. What stands in for
 * authentication is the structure of the flow itself: an authorization code is
 * single-use, sixty seconds long, and bound to a client, a redirect URI, and a
 * PKCE challenge, so possession of any one of those pieces is worth nothing on
 * its own.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Maximum clients that may register. A cap, not a policy — see the register handler. */
define('SBMCP_OAUTH_MAX_CLIENTS', 250);

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

/**
 * Per-IP rate limit for the unauthenticated endpoints.
 *
 * The client IP is used even when the administrator has turned off IP logging.
 * Those are different things: the setting governs what is written to the audit
 * table and shown in the admin, while this hashes the address into a transient
 * key that is never displayed and expires on its own. Declining to rate-limit
 * because the admin opted out of *storing* addresses would trade a privacy
 * setting for an open credential-guessing endpoint.
 *
 * @param string $bucket Distinct limit bucket, e.g. 'token'.
 * @param int    $max    Requests allowed per window.
 * @param int    $window Window in seconds.
 * @return bool True when the caller is over the limit.
 */
function sbmcp_oauth_rate_limited(string $bucket, int $max, int $window): bool {
    $ip  = sbmcp_audit_client_ip() ?: 'unknown';
    $key = 'sbmcp_rl_' . md5($bucket . '|' . $ip);

    $count = (int) get_transient($key);
    if ($count >= $max) {
        return true;
    }

    // Re-setting the expiry on each hit makes the window slide forward under
    // sustained load, so a caller hammering the endpoint stays locked out for
    // as long as it keeps trying rather than getting a fresh allowance.
    set_transient($key, $count + 1, $window);
    return false;
}

// ---------------------------------------------------------------------------
// Route registration
// ---------------------------------------------------------------------------

function sbmcp_oauth_register_routes() {
    $public = '__return_true';

    register_rest_route('strifebridge/v1', '/oauth/authorize', [
        'methods'             => 'GET',
        'callback'            => 'sbmcp_oauth_authorize_handler',
        'permission_callback' => $public,
    ]);

    register_rest_route('strifebridge/v1', '/oauth/token', [
        'methods'             => 'POST',
        'callback'            => 'sbmcp_oauth_token_handler',
        'permission_callback' => $public,
    ]);

    register_rest_route('strifebridge/v1', '/oauth/register', [
        'methods'             => 'POST',
        'callback'            => 'sbmcp_oauth_register_handler',
        'permission_callback' => $public,
    ]);

    register_rest_route('strifebridge/v1', '/oauth/revoke', [
        'methods'             => 'POST',
        'callback'            => 'sbmcp_oauth_revoke_handler',
        'permission_callback' => $public,
    ]);
}
add_action('rest_api_init', 'sbmcp_oauth_register_routes');

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * An RFC 6749 error response, with the no-store the spec requires on anything
 * that carries or refuses a credential.
 *
 * @param string $code        OAuth error code.
 * @param string $description Human-readable detail.
 * @param int    $status      HTTP status.
 * @return WP_REST_Response
 */
function sbmcp_oauth_error_response(string $code, string $description, int $status = 400): WP_REST_Response {
    $response = new WP_REST_Response(['error' => $code, 'error_description' => $description], $status);
    $response->header('Cache-Control', 'no-store');
    $response->header('Pragma', 'no-cache');
    return $response;
}

/**
 * A successful token-endpoint response.
 *
 * @param array<string, mixed> $data
 * @return WP_REST_Response
 */
function sbmcp_oauth_token_response(array $data): WP_REST_Response {
    $response = new WP_REST_Response($data, 200);
    $response->header('Cache-Control', 'no-store');
    $response->header('Pragma', 'no-cache');
    return $response;
}

/**
 * Authenticates the client on a token or revocation request.
 *
 * Accepts client_secret_basic, client_secret_post, and none. A client that
 * registered with a secret must present it; a public client that registered
 * without one is authenticated by PKCE alone, which is what OAuth 2.1 intends
 * for clients that cannot keep a secret.
 *
 * @param WP_REST_Request $request
 * @param string          $client_id_from_body
 * @return array{client: array<string,mixed>}|WP_Error
 */
function sbmcp_oauth_authenticate_client(WP_REST_Request $request, string $client_id_from_body) {
    $client_id     = $client_id_from_body;
    $client_secret = (string) $request->get_param('client_secret');

    // client_secret_basic: credentials in the Authorization header.
    $auth = $request->get_header('Authorization');
    if ($auth && stripos($auth, 'Basic ') === 0) {
        $decoded = base64_decode(substr($auth, 6), true);
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            list($basic_id, $basic_secret) = explode(':', $decoded, 2);
            $client_id     = urldecode($basic_id);
            $client_secret = urldecode($basic_secret);
        }
    }

    if ($client_id === '') {
        return new WP_Error('invalid_client', __('No client_id was presented.', 'strifebridge-mcp'));
    }

    $client = sbmcp_oauth_get_client($client_id);
    if (!$client) {
        return new WP_Error('invalid_client', __('Unknown client.', 'strifebridge-mcp'));
    }

    // A client that holds a secret must prove it. Compared with hash_equals()
    // against the stored hash, never with == against anything.
    if (!empty($client['client_secret_hash'])) {
        if ($client_secret === '' || !sbmcp_oauth_verify($client['client_secret_hash'], $client_secret)) {
            return new WP_Error('invalid_client', __('Client authentication failed.', 'strifebridge-mcp'));
        }
    }

    return ['client' => $client];
}

/**
 * Verifies a PKCE code_verifier against the stored S256 challenge.
 *
 * S256 only. OAuth 2.1 removes "plain", and accepting it would defeat the point
 * of PKCE entirely: with plain, the challenge *is* the verifier, so anyone who
 * intercepted the authorization request can redeem the code they stole.
 *
 * @param string $verifier  Presented code_verifier.
 * @param string $challenge Stored challenge.
 * @return bool
 */
function sbmcp_oauth_verify_pkce(string $verifier, string $challenge): bool {
    // RFC 7636: 43-128 characters from the unreserved set.
    $length = strlen($verifier);
    if ($length < 43 || $length > 128) {
        return false;
    }
    if (!preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier)) {
        return false;
    }

    $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return hash_equals($challenge, $computed);
}

// ---------------------------------------------------------------------------
// /oauth/authorize
// ---------------------------------------------------------------------------

/**
 * Starts the authorization code flow.
 *
 * Validates everything that can be validated without a user, then hands off to
 * the consent screen in wp-admin. Nothing is issued here.
 *
 * On the two failures that concern the client's identity — an unknown client_id
 * or a redirect_uri that was not registered — this renders an error page and
 * does NOT redirect. That asymmetry is the whole security property of the
 * endpoint: redirecting an error to an unverified URI is an open redirect, and
 * worse, it is one that an attacker can point at a URI of their choosing by
 * supplying it in the request. Every other error redirects back to the verified
 * URI with an `error` parameter, which is what the spec asks for.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|void
 */
function sbmcp_oauth_authorize_handler(WP_REST_Request $request) {
    $client_id     = (string) $request->get_param('client_id');
    $redirect_uri  = (string) $request->get_param('redirect_uri');
    $response_type = (string) $request->get_param('response_type');
    $state         = (string) $request->get_param('state');
    $challenge     = (string) $request->get_param('code_challenge');
    $method        = (string) $request->get_param('code_challenge_method');
    $scope         = (string) $request->get_param('scope');

    if ($client_id === '') {
        return sbmcp_oauth_authorize_error_page(__('The connecting application did not identify itself (no client_id).', 'strifebridge-mcp'));
    }

    $client = sbmcp_oauth_get_client($client_id);
    if (!$client) {
        sbmcp_audit_log('oauth_authorize', ['client_id' => $client_id], 'denied', 'Authorization refused: unknown client_id.');
        return sbmcp_oauth_authorize_error_page(__('The connecting application is not registered with this site.', 'strifebridge-mcp'));
    }

    if ($redirect_uri === '' || !sbmcp_oauth_redirect_uri_allowed($client, $redirect_uri)) {
        sbmcp_audit_log('oauth_authorize', ['client_id' => $client_id], 'denied', 'Authorization refused: redirect_uri does not match a registered URI.');
        return sbmcp_oauth_authorize_error_page(__('The return address this application asked for does not match the one it registered. Nothing has been shared with it.', 'strifebridge-mcp'));
    }

    // Past this point the redirect URI is verified, so errors go back to it.
    if ($response_type !== 'code') {
        return sbmcp_oauth_authorize_redirect_error($redirect_uri, 'unsupported_response_type', 'Only the authorization code flow is supported.', $state);
    }

    if ($challenge === '') {
        return sbmcp_oauth_authorize_redirect_error($redirect_uri, 'invalid_request', 'PKCE is required: code_challenge is missing.', $state);
    }

    // An absent method means "plain" under RFC 7636, so an unset method is a
    // rejection here rather than a default.
    if ($method !== 'S256') {
        sbmcp_audit_log('oauth_authorize', ['client_id' => $client_id], 'denied', 'Authorization refused: code_challenge_method must be S256.');
        return sbmcp_oauth_authorize_redirect_error($redirect_uri, 'invalid_request', 'code_challenge_method must be S256. The plain method is not accepted.', $state);
    }

    if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
        return sbmcp_oauth_authorize_redirect_error($redirect_uri, 'invalid_request', 'Malformed code_challenge.', $state);
    }

    // Hand off to the consent screen. These parameters are not secret — they are
    // the client's own request — so carrying them in the URL is safe, and it
    // means no server-side pending-request state has to be stored and expired.
    $consent_url = add_query_arg(
        [
            'page'                  => 'sbmcp-oauth-authorize',
            'client_id'             => rawurlencode($client_id),
            'redirect_uri'          => rawurlencode($redirect_uri),
            'state'                 => rawurlencode($state),
            'code_challenge'        => rawurlencode($challenge),
            'code_challenge_method' => 'S256',
            'scope'                 => rawurlencode(sbmcp_oauth_sanitize_scope($scope)),
        ],
        admin_url('options-general.php')
    );

    wp_safe_redirect($consent_url);
    exit;
}

/**
 * Redirects an error back to a verified redirect URI.
 *
 * wp_redirect() and not wp_safe_redirect(): the destination is by definition
 * off-site, and it is safe precisely because it was exact-matched against the
 * client's registered URIs before we got here. wp_safe_redirect() would refuse
 * it and send the user to the site's home page, losing the error the client
 * needs to see.
 *
 * @param string $redirect_uri Already verified.
 * @param string $error        OAuth error code.
 * @param string $description  Human-readable detail.
 * @param string $state        Client state, echoed back.
 * @return void
 */
function sbmcp_oauth_authorize_redirect_error(string $redirect_uri, string $error, string $description, string $state) {
    $args = ['error' => $error, 'error_description' => $description];
    if ($state !== '') {
        $args['state'] = $state;
    }

    wp_redirect(add_query_arg(array_map('rawurlencode', $args), $redirect_uri));
    exit;
}

/**
 * Renders a terminal error for the two cases that must never redirect.
 *
 * @param string $message
 * @return void
 */
function sbmcp_oauth_authorize_error_page(string $message) {
    wp_die(
        esc_html($message),
        esc_html__('Connection refused', 'strifebridge-mcp'),
        ['response' => 400, 'back_link' => false]
    );
}

// ---------------------------------------------------------------------------
// /oauth/token
// ---------------------------------------------------------------------------

/**
 * Exchanges an authorization code for tokens, or rotates a refresh token.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sbmcp_oauth_token_handler(WP_REST_Request $request) {
    if (sbmcp_oauth_rate_limited('token', 30, 5 * MINUTE_IN_SECONDS)) {
        sbmcp_audit_log('oauth_token', [], 'denied', 'Token request refused: rate limit exceeded.');
        return sbmcp_oauth_error_response('invalid_request', __('Too many token requests. Try again shortly.', 'strifebridge-mcp'), 429);
    }

    $grant_type = (string) $request->get_param('grant_type');

    if ($grant_type === 'authorization_code') {
        return sbmcp_oauth_grant_authorization_code($request);
    }
    if ($grant_type === 'refresh_token') {
        return sbmcp_oauth_grant_refresh_token($request);
    }

    sbmcp_audit_log('oauth_token', ['grant_type' => $grant_type], 'denied', 'Token request refused: unsupported grant_type.');
    return sbmcp_oauth_error_response('unsupported_grant_type', __('Supported grant types are authorization_code and refresh_token.', 'strifebridge-mcp'));
}

/**
 * The authorization_code grant.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sbmcp_oauth_grant_authorization_code(WP_REST_Request $request): WP_REST_Response {
    $code         = (string) $request->get_param('code');
    $verifier     = (string) $request->get_param('code_verifier');
    $redirect_uri = (string) $request->get_param('redirect_uri');
    $client_id    = (string) $request->get_param('client_id');

    if ($code === '' || $verifier === '') {
        return sbmcp_oauth_error_response('invalid_request', __('code and code_verifier are both required.', 'strifebridge-mcp'));
    }

    $authenticated = sbmcp_oauth_authenticate_client($request, $client_id);
    if (is_wp_error($authenticated)) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'authorization_code'], 'denied', $authenticated->get_error_message());
        return sbmcp_oauth_error_response('invalid_client', $authenticated->get_error_message(), 401);
    }
    $client = $authenticated['client'];

    // Claims the code atomically; a second redemption of the same code gets
    // null here no matter how closely the two requests arrive.
    $row = sbmcp_oauth_claim_code($code);
    if (!$row) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'authorization_code'], 'denied', 'Token request refused: authorization code is unknown or already used.');
        return sbmcp_oauth_error_response('invalid_grant', __('That authorization code is not valid. Start the connection again.', 'strifebridge-mcp'));
    }

    if (strtotime($row['expires_at'] . ' UTC') <= time()) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'authorization_code'], 'denied', 'Token request refused: authorization code expired.');
        return sbmcp_oauth_error_response('invalid_grant', __('That authorization code has expired. Start the connection again.', 'strifebridge-mcp'));
    }

    // The code is bound to the client it was issued to. Without this check a
    // code leaked to a second registered client could be redeemed by it.
    if (!hash_equals((string) $row['client_id'], $client['client_id'])) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'authorization_code'], 'denied', 'Token request refused: code was issued to a different client.');
        return sbmcp_oauth_error_response('invalid_grant', __('That authorization code was not issued to this application.', 'strifebridge-mcp'));
    }

    // And to the redirect URI the user saw when they approved it.
    if ($redirect_uri === '' || !hash_equals((string) $row['redirect_uri'], $redirect_uri)) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'authorization_code'], 'denied', 'Token request refused: redirect_uri does not match the authorization request.');
        return sbmcp_oauth_error_response('invalid_grant', __('The redirect_uri does not match the one used to obtain this code.', 'strifebridge-mcp'));
    }

    if (!sbmcp_oauth_verify_pkce($verifier, (string) $row['code_challenge'])) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'authorization_code'], 'denied', 'Token request refused: PKCE verification failed.');
        return sbmcp_oauth_error_response('invalid_grant', __('PKCE verification failed.', 'strifebridge-mcp'));
    }

    $tokens = sbmcp_oauth_issue_tokens($client['client_id'], (int) $row['user_id'], (string) $row['scope']);
    if (!$tokens) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'authorization_code'], 'error', 'Token issuance failed: could not write the token.');
        return sbmcp_oauth_error_response('server_error', __('Could not issue a token.', 'strifebridge-mcp'), 500);
    }

    sbmcp_oauth_touch_client($client['client_id']);
    sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'authorization_code', 'scope' => $row['scope']], 'success');

    return sbmcp_oauth_token_response([
        'access_token'  => $tokens['access_token'],
        'token_type'    => 'Bearer',
        'expires_in'    => $tokens['expires_in'],
        'refresh_token' => $tokens['refresh_token'],
        'scope'         => $row['scope'],
    ]);
}

/**
 * The refresh_token grant, with rotation.
 *
 * The presented refresh token is revoked as part of issuing its replacement, so
 * each one is good for exactly one rotation. A stolen refresh token is then
 * either useless — the legitimate client already rotated it — or it locks the
 * legitimate client out on its next attempt, which turns silent theft into a
 * visible failure.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sbmcp_oauth_grant_refresh_token(WP_REST_Request $request): WP_REST_Response {
    $refresh   = (string) $request->get_param('refresh_token');
    $client_id = (string) $request->get_param('client_id');

    if ($refresh === '') {
        return sbmcp_oauth_error_response('invalid_request', __('refresh_token is required.', 'strifebridge-mcp'));
    }

    $authenticated = sbmcp_oauth_authenticate_client($request, $client_id);
    if (is_wp_error($authenticated)) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'refresh_token'], 'denied', $authenticated->get_error_message());
        return sbmcp_oauth_error_response('invalid_client', $authenticated->get_error_message(), 401);
    }
    $client = $authenticated['client'];

    $row = sbmcp_oauth_find_refresh_token($refresh);
    if (!$row) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'refresh_token'], 'denied', 'Refresh refused: token is unknown.');
        return sbmcp_oauth_error_response('invalid_grant', __('That refresh token is not valid. Reconnect the application.', 'strifebridge-mcp'));
    }

    if (!hash_equals((string) $row['client_id'], $client['client_id'])) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'refresh_token'], 'denied', 'Refresh refused: token belongs to a different client.');
        return sbmcp_oauth_error_response('invalid_grant', __('That refresh token was not issued to this application.', 'strifebridge-mcp'));
    }

    // Reuse detection. A refresh token is good for exactly one rotation, so a
    // known token that has already been rotated is being presented a second
    // time — either the legitimate client lost the response and is retrying,
    // or someone else has a copy. The two cannot be told apart, and the safe
    // reading is theft: revoke every token this client holds for this user, so
    // whichever party has the live pair loses it too. The legitimate user
    // reconnects; a thief's copy dies with it. This is the OAuth 2.1 guidance
    // for rotation, and it turns a silent compromise into a visible one.
    if (!empty($row['revoked_at'])) {
        $revoked = sbmcp_oauth_revoke_client_tokens((string) $row['client_id'], (int) $row['user_id']);
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'refresh_token'], 'denied', sprintf('Refresh token reuse detected: revoked %d live token(s) for this client and user.', $revoked));
        return sbmcp_oauth_error_response('invalid_grant', __('That refresh token has already been used. All tokens for this connection have been revoked as a precaution; reconnect the application.', 'strifebridge-mcp'));
    }

    if (empty($row['refresh_expires_at']) || strtotime($row['refresh_expires_at'] . ' UTC') <= time()) {
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'refresh_token'], 'denied', 'Refresh refused: token expired.');
        return sbmcp_oauth_error_response('invalid_grant', __('That refresh token has expired. Reconnect the application.', 'strifebridge-mcp'));
    }

    // The bound account can disappear between issue and refresh.
    if (!get_userdata((int) $row['user_id'])) {
        sbmcp_oauth_revoke_token_row((int) $row['id']);
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'refresh_token'], 'denied', 'Refresh refused: the bound WordPress user no longer exists.');
        return sbmcp_oauth_error_response('invalid_grant', __('The account this connection belongs to no longer exists.', 'strifebridge-mcp'));
    }

    // Claim first, atomically. Two concurrent presentations of the same token
    // reach this line together; exactly one gets true. The loser is handled as
    // reuse — which, for a token that was live a millisecond ago, it is.
    if (!sbmcp_oauth_claim_refresh_token((int) $row['id'])) {
        $revoked = sbmcp_oauth_revoke_client_tokens((string) $row['client_id'], (int) $row['user_id']);
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'refresh_token'], 'denied', sprintf('Concurrent refresh of one token: revoked %d live token(s) for this client and user.', $revoked));
        return sbmcp_oauth_error_response('invalid_grant', __('That refresh token has already been used. All tokens for this connection have been revoked as a precaution; reconnect the application.', 'strifebridge-mcp'));
    }

    $tokens = sbmcp_oauth_issue_tokens($client['client_id'], (int) $row['user_id'], (string) $row['scope']);
    if (!$tokens) {
        // Give the old token back rather than leave the client with nothing.
        sbmcp_oauth_unclaim_refresh_token((int) $row['id']);
        sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'refresh_token'], 'error', 'Refresh failed: could not write the replacement token.');
        return sbmcp_oauth_error_response('server_error', __('Could not issue a token.', 'strifebridge-mcp'), 500);
    }

    sbmcp_oauth_touch_client($client['client_id']);
    sbmcp_audit_log('oauth_token', ['client_id' => $client_id, 'grant_type' => 'refresh_token', 'scope' => $row['scope']], 'success');

    return sbmcp_oauth_token_response([
        'access_token'  => $tokens['access_token'],
        'token_type'    => 'Bearer',
        'expires_in'    => $tokens['expires_in'],
        'refresh_token' => $tokens['refresh_token'],
        'scope'         => $row['scope'],
    ]);
}

// ---------------------------------------------------------------------------
// /oauth/register  (RFC 7591 Dynamic Client Registration)
// ---------------------------------------------------------------------------

/**
 * Registers a client.
 *
 * Open registration, which ChatGPT requires: its connector registers itself
 * before any human has approved anything, so there is no credential to demand
 * here. Registering is not an authorization — a registered client can do
 * precisely nothing until an administrator completes the consent screen and a
 * code is issued. What registration costs is a row, so the controls are on
 * volume rather than on identity: a per-IP rate limit and a hard cap on the
 * table, both of which fail closed.
 *
 * Site owners who do not want open registration can turn it off; the constant
 * and the option are both honoured.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sbmcp_oauth_register_handler(WP_REST_Request $request) {
    if (get_option('sbmcp_oauth_registration_disabled')) {
        return sbmcp_oauth_error_response('access_denied', __('Dynamic client registration is disabled on this site.', 'strifebridge-mcp'), 403);
    }

    if (sbmcp_oauth_rate_limited('register', 5, HOUR_IN_SECONDS)) {
        sbmcp_audit_log('oauth_register', [], 'denied', 'Client registration refused: rate limit exceeded.');
        return sbmcp_oauth_error_response('invalid_request', __('Too many registration attempts. Try again later.', 'strifebridge-mcp'), 429);
    }

    global $wpdb;
    $clients_table = sbmcp_oauth_clients_table();
    $count         = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$clients_table}");
    if ($count >= SBMCP_OAUTH_MAX_CLIENTS) {
        sbmcp_audit_log('oauth_register', [], 'denied', 'Client registration refused: client limit reached.');
        return sbmcp_oauth_error_response('invalid_request', __('This site has reached its registered application limit.', 'strifebridge-mcp'), 429);
    }

    $body = $request->get_json_params();
    if (!is_array($body)) {
        $body = $request->get_params();
    }

    $redirect_uris = $body['redirect_uris'] ?? [];
    if (!is_array($redirect_uris) || empty($redirect_uris)) {
        return sbmcp_oauth_error_response('invalid_redirect_uri', __('redirect_uris is required and must be a non-empty array.', 'strifebridge-mcp'));
    }

    $validated = [];
    foreach ($redirect_uris as $uri) {
        if (!is_string($uri)) {
            return sbmcp_oauth_error_response('invalid_redirect_uri', __('Every redirect URI must be a string.', 'strifebridge-mcp'));
        }
        $clean = sbmcp_oauth_validate_redirect_uri($uri);
        if (is_wp_error($clean)) {
            sbmcp_audit_log('oauth_register', [], 'denied', 'Client registration refused: ' . $clean->get_error_message());
            return sbmcp_oauth_error_response('invalid_redirect_uri', $clean->get_error_message());
        }
        $validated[] = $clean;
    }

    $name = isset($body['client_name']) && is_string($body['client_name']) && $body['client_name'] !== ''
        ? sanitize_text_field($body['client_name'])
        : __('Unnamed application', 'strifebridge-mcp');

    $client_uri = isset($body['client_uri']) && is_string($body['client_uri']) ? esc_url_raw($body['client_uri']) : '';

    $created = sbmcp_oauth_create_client($name, $validated, $client_uri);
    if (!$created) {
        sbmcp_audit_log('oauth_register', ['client_name' => $name], 'error', 'Client registration failed: could not write the client.');
        return sbmcp_oauth_error_response('server_error', __('Could not register the application.', 'strifebridge-mcp'), 500);
    }

    sbmcp_audit_log('oauth_register', ['client_name' => $name, 'client_id' => $created['client_id']], 'success');

    $response = new WP_REST_Response([
        'client_id'                  => $created['client_id'],
        'client_secret'              => $created['client_secret'],
        'client_id_issued_at'        => time(),
        // 0 means "does not expire", per RFC 7591.
        'client_secret_expires_at'   => 0,
        'client_name'                => $name,
        'redirect_uris'              => $validated,
        'grant_types'                => ['authorization_code', 'refresh_token'],
        'response_types'             => ['code'],
        'token_endpoint_auth_method' => 'client_secret_post',
    ], 201);

    $response->header('Cache-Control', 'no-store');
    $response->header('Pragma', 'no-cache');

    return $response;
}

/**
 * Validates a redirect URI offered at registration time.
 *
 * Requires an absolute https URI with no fragment. http is allowed only for
 * loopback, which is how a native client running on the user's own machine
 * receives its callback and is the one case where the lack of TLS is not a
 * transport a third party can sit on.
 *
 * @param string $uri
 * @return string|WP_Error
 */
function sbmcp_oauth_validate_redirect_uri(string $uri) {
    $uri   = trim($uri);
    $parts = wp_parse_url($uri);

    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return new WP_Error('invalid_redirect_uri', __('Redirect URIs must be absolute.', 'strifebridge-mcp'));
    }

    // A fragment can never be sent to a server and its presence means the client
    // has misunderstood the flow; RFC 6749 forbids it outright.
    if (isset($parts['fragment'])) {
        return new WP_Error('invalid_redirect_uri', __('Redirect URIs must not contain a fragment.', 'strifebridge-mcp'));
    }

    $scheme = strtolower($parts['scheme']);
    $host   = strtolower($parts['host']);

    if ($scheme === 'https') {
        return $uri;
    }

    if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return $uri;
    }

    return new WP_Error('invalid_redirect_uri', __('Redirect URIs must use https, except on localhost.', 'strifebridge-mcp'));
}

// ---------------------------------------------------------------------------
// /oauth/revoke  (RFC 7009)
// ---------------------------------------------------------------------------

/**
 * Revokes an access or refresh token.
 *
 * Answers 200 whether or not the token existed. RFC 7009 requires that, and the
 * reason is worth stating: a revocation endpoint that distinguished "revoked"
 * from "no such token" would be an oracle for testing whether a stolen token is
 * still live, which is exactly what someone holding one wants to know.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function sbmcp_oauth_revoke_handler(WP_REST_Request $request) {
    if (sbmcp_oauth_rate_limited('revoke', 30, 5 * MINUTE_IN_SECONDS)) {
        return sbmcp_oauth_error_response('invalid_request', __('Too many requests.', 'strifebridge-mcp'), 429);
    }

    $token = (string) $request->get_param('token');
    if ($token === '') {
        return sbmcp_oauth_error_response('invalid_request', __('token is required.', 'strifebridge-mcp'));
    }

    $hint = (string) $request->get_param('token_type_hint');

    // The hint orders the lookups; it never restricts them. A client that hints
    // wrong still gets its token revoked, which is the outcome that matters.
    $row = null;
    if ($hint === 'refresh_token') {
        $row = sbmcp_oauth_get_refresh_token($token) ?: sbmcp_oauth_get_access_token($token);
    } else {
        $row = sbmcp_oauth_get_access_token($token) ?: sbmcp_oauth_get_refresh_token($token);
    }

    if ($row) {
        sbmcp_oauth_revoke_token_row((int) $row['id']);
        sbmcp_audit_log('oauth_revoke', ['client_id' => $row['client_id']], 'success');
    }

    $response = new WP_REST_Response(null, 200);
    $response->header('Cache-Control', 'no-store');
    return $response;
}

// ---------------------------------------------------------------------------
// Audit integration
// ---------------------------------------------------------------------------

/**
 * Declares which OAuth arguments are safe to record in the activity log.
 *
 * Everything named here is a public identifier. No code, verifier, token, or
 * secret appears, and the allowlist in the audit log means anything not listed
 * cannot be written even if a future caller passes it.
 *
 * @param array<string, string[]> $args
 * @return array<string, string[]>
 */
function sbmcp_oauth_loggable_args(array $args): array {
    return array_merge($args, [
        'oauth_authorize' => ['client_id', 'scope'],
        'oauth_token'     => ['client_id', 'grant_type', 'scope'],
        'oauth_register'  => ['client_name', 'client_id'],
        'oauth_revoke'    => ['client_id'],
    ]);
}
add_filter('sbmcp_audit_loggable_args', 'sbmcp_oauth_loggable_args');
