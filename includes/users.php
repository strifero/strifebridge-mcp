<?php
/**
 * User listing endpoint (free tier — read only).
 *
 * User create/update/delete operations are available in StrifeBridge MCP Pro.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_list_users(WP_REST_Request $request) {
    return array_map(fn($u) => ['id' => $u->ID, 'username' => $u->user_login, 'email' => $u->user_email, 'display_name' => $u->display_name, 'roles' => $u->roles, 'registered' => $u->user_registered], get_users(['number' => 100]));
}
