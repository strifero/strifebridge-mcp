<?php
/**
 * Credential check for the REST surface.
 *
 * As of 3.0.0 this accepts two kinds of credential and delegates the decision to
 * sbmcp_oauth_validate_request(), which is shared with the MCP endpoint so the
 * two surfaces cannot drift apart on what counts as authenticated:
 *
 *   - the legacy site token, via X-StrifeBridge-Token or Authorization: Bearer,
 *     behaving exactly as it did in 2.4.0;
 *   - an OAuth 2.1 access token, which additionally binds the request to the
 *     WordPress user it was issued to.
 *
 * The function name and its bool return are unchanged, because it is passed by
 * name as a permission_callback and handed to add-ons through the
 * sbmcp_register_rest_routes action. Pro registers its routes with whatever this
 * resolves to, so changing the contract here would change it for Pro silently.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_validate_token(WP_REST_Request $request): bool {
    return sbmcp_oauth_validate_request($request, 'API');
}
