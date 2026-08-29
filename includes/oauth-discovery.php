<?php
/**
 * OAuth discovery documents, served from the site root.
 *
 * Two documents, because the two halves of the spec ask different questions:
 *
 *   /.well-known/oauth-authorization-server  (RFC 8414)
 *       "Where do I authorize, where do I exchange, what do you support?"
 *
 *   /.well-known/oauth-protected-resource    (RFC 9728)
 *       "What resource is this, and who issues tokens for it?"
 *
 * A connector reaches the second one first — the 401 from an unauthenticated
 * MCP request points at it via WWW-Authenticate — and follows it to the first.
 * Both have to be present and consistent or the flow stops at discovery.
 *
 * WHY A REWRITE RULE
 *
 * These paths live at the site root, not under /wp-json/, so register_rest_route()
 * cannot reach them. There was no rewrite rule anywhere in the plugin before
 * this; the pattern here is new rather than borrowed from the MCP routes, which
 * register as REST routes.
 *
 * SUBDIRECTORY INSTALLS
 *
 * Everything below is anchored to home_url(). On a WordPress installed in a
 * subdirectory, WordPress only sees requests under that subdirectory, so the
 * documents are served from `example.com/blog/.well-known/...` and the issuer
 * is `example.com/blog` to match. That is self-consistent and a connector that
 * follows the documents will work. A connector that guesses at the domain root
 * instead will not find them, and no rewrite rule inside WordPress can fix that
 * — it needs a redirect in the webserver.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Bumped when the rewrite rules below change, to trigger a flush. */
define('SBMCP_REWRITE_VERSION', '1.0');

/**
 * The issuer identifier for this site.
 *
 * Must be byte-identical to the `issuer` in the authorization server metadata
 * and to the entry in `authorization_servers`, because clients compare them
 * as strings. Normalised without a trailing slash for that reason.
 *
 * @return string
 */
function sbmcp_oauth_issuer(): string {
    return untrailingslashit(home_url());
}

/** URL of the protected resource metadata document. */
function sbmcp_oauth_protected_resource_url(): string {
    return sbmcp_oauth_issuer() . '/.well-known/oauth-protected-resource';
}

/** URL of the authorization server metadata document. */
function sbmcp_oauth_authorization_server_url(): string {
    return sbmcp_oauth_issuer() . '/.well-known/oauth-authorization-server';
}

/** The MCP endpoint these tokens are for. */
function sbmcp_oauth_resource_url(): string {
    return rest_url('strifebridge/v1/mcp');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

/**
 * Registers the .well-known rewrite rules.
 *
 * Both patterns tolerate a trailing path segment. MCP clients may append the
 * resource's own path to the metadata URL — asking for
 * `/.well-known/oauth-protected-resource/wp-json/strifebridge/v1/mcp` rather
 * than the bare path — and a rule anchored with `$` would 404 exactly the
 * request a spec-following client makes.
 *
 * @return void
 */
function sbmcp_oauth_add_rewrite_rules() {
    add_rewrite_rule(
        '^\.well-known/oauth-authorization-server(/.*)?$',
        'index.php?sbmcp_wellknown=authorization-server',
        'top'
    );
    add_rewrite_rule(
        '^\.well-known/oauth-protected-resource(/.*)?$',
        'index.php?sbmcp_wellknown=protected-resource',
        'top'
    );
}
add_action('init', 'sbmcp_oauth_add_rewrite_rules');

/**
 * @param string[] $vars
 * @return string[]
 */
function sbmcp_oauth_query_vars(array $vars): array {
    $vars[] = 'sbmcp_wellknown';
    return $vars;
}
add_filter('query_vars', 'sbmcp_oauth_query_vars');

/**
 * Flushes rewrite rules once, after the rules change.
 *
 * Guarded by a stored version rather than called on every load: flushing
 * regenerates every rewrite rule on the site and writes an option, which is far
 * too expensive to do per request.
 *
 * @return void
 */
function sbmcp_oauth_maybe_flush_rewrites() {
    if (get_option('sbmcp_rewrite_version') === SBMCP_REWRITE_VERSION) {
        return;
    }
    sbmcp_oauth_add_rewrite_rules();
    flush_rewrite_rules(false);
    update_option('sbmcp_rewrite_version', SBMCP_REWRITE_VERSION);
}
add_action('init', 'sbmcp_oauth_maybe_flush_rewrites', 20);

/**
 * Serves whichever discovery document was asked for.
 *
 * Runs on parse_request and exits, so the theme never loads and nothing else
 * can append output to a document that has to parse as strict JSON.
 *
 * @param WP $wp
 * @return void
 */
function sbmcp_oauth_serve_wellknown($wp) {
    $which = $wp->query_vars['sbmcp_wellknown'] ?? '';
    if (!$which) {
        return;
    }

    $document = ($which === 'authorization-server')
        ? sbmcp_oauth_authorization_server_metadata()
        : sbmcp_oauth_protected_resource_metadata();

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    // Public, non-secret metadata that a browser-based client may need to read
    // cross-origin during discovery.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        status_header(204);
        exit;
    }

    status_header(200);
    echo wp_json_encode($document, JSON_UNESCAPED_SLASHES);
    exit;
}
add_action('parse_request', 'sbmcp_oauth_serve_wellknown');

// ---------------------------------------------------------------------------
// The documents
// ---------------------------------------------------------------------------

/**
 * RFC 8414 authorization server metadata.
 *
 * `code_challenge_methods_supported` lists S256 and only S256. Advertising
 * "plain" would be advertising a downgrade: under OAuth 2.1 plain PKCE is no
 * PKCE, and the token endpoint rejects it, so listing it would promise
 * something that does not work.
 *
 * `token_endpoint_auth_methods_supported` includes "none" because a public
 * client — a native app, anything that cannot hold a secret — is a legitimate
 * OAuth 2.1 client when it uses PKCE, which is mandatory here regardless.
 *
 * @return array<string, mixed>
 */
function sbmcp_oauth_authorization_server_metadata(): array {
    $metadata = [
        'issuer'                                => sbmcp_oauth_issuer(),
        'authorization_endpoint'                => rest_url('strifebridge/v1/oauth/authorize'),
        'token_endpoint'                        => rest_url('strifebridge/v1/oauth/token'),
        'registration_endpoint'                 => rest_url('strifebridge/v1/oauth/register'),
        'revocation_endpoint'                   => rest_url('strifebridge/v1/oauth/revoke'),
        'scopes_supported'                      => array_keys(sbmcp_oauth_scopes()),
        'response_types_supported'              => ['code'],
        'response_modes_supported'              => ['query'],
        'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
        'revocation_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
        'code_challenge_methods_supported'      => ['S256'],
        'service_documentation'                 => 'https://strifetech.com/docs/strifebridge-mcp',
    ];

    return apply_filters('sbmcp_oauth_authorization_server_metadata', $metadata);
}

/**
 * RFC 9728 protected resource metadata.
 *
 * @return array<string, mixed>
 */
function sbmcp_oauth_protected_resource_metadata(): array {
    $metadata = [
        'resource'                 => sbmcp_oauth_resource_url(),
        'authorization_servers'    => [sbmcp_oauth_issuer()],
        'scopes_supported'         => array_keys(sbmcp_oauth_scopes()),
        'bearer_methods_supported' => ['header'],
        'resource_documentation'   => 'https://strifetech.com/docs/strifebridge-mcp',
    ];

    return apply_filters('sbmcp_oauth_protected_resource_metadata', $metadata);
}
