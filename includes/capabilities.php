<?php
/**
 * Per-tool capability requirements and the gate that enforces them.
 *
 * WHAT THIS FIXES
 *
 * Before OAuth, a request carried a bearer token and nothing else. There was no
 * user behind it, so there was no capability to check, and every handler ran
 * with whatever authority the code path happened to have — in practice, full
 * administrator. That is the standing MEDIUM audit finding: the token is
 * admin-equivalent and cannot be scoped down. A leaked token was root.
 *
 * An OAuth access token is bound to a real WordPress user. Once the request runs
 * as that user, `current_user_can()` becomes meaningful, and a token issued to
 * an editor can be held to an editor's authority. A leaked token becomes "as
 * that user" instead of "as root", which is the whole point of the exercise.
 *
 * THIS GATE IS INERT IN 3.0.0. DO NOT DELETE IT.
 *
 * 3.0.0 ships with the consent screen requiring manage_options, so only an
 * administrator can approve a connection, so every bound user is an
 * administrator, so every capability check below currently passes. Read in
 * isolation the capability map looks like code that never refuses anything, and
 * the obvious conclusion — that it is dead and can go — is wrong on three
 * counts:
 *
 *   1. Non-admin authorization is 3.1. Relaxing the capability on the consent
 *      screen is a one-line change, and on the day it lands this gate is the
 *      only thing standing between an editor's token and the options table.
 *      Removing it now means shipping that change with no enforcement at all.
 *
 *   2. Scope enforcement below is NOT inert today. A connection granted only
 *      mcp:read is refused a write tool right now, administrator or not, and
 *      that refusal comes from this function.
 *
 *   3. wp_set_current_user() has already made every capability check inside
 *      WordPress itself live for OAuth requests. This gate refuses the call
 *      early and records it as 'denied' with a message naming the capability;
 *      without it the same call still fails, but deeper in, as an unexplained
 *      error. The gate is what makes the refusal legible.
 *
 * Verify against the consent screen's capability in admin/oauth-consent.php
 * before concluding anything here is unreachable.
 *
 * WHY THE LEGACY PATH IS NOT GATED
 *
 * The gate applies only to requests bound to a user. A legacy bearer request has
 * no bound user, so there is no authority to compare against and the gate does
 * not run — existing installs behave exactly as they did in 2.4.0. Gating them
 * would mean inventing a user for them, and any choice there silently changes
 * what an existing connector is allowed to do. That is an upgrade breaking a
 * working install, which the legacy path exists to prevent. The bearer token
 * remains admin-equivalent; it is marked as the legacy path in the UI and OAuth
 * is what new connections are pointed at.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tool to required WordPress capability.
 *
 * Chosen to match what the underlying WordPress operation actually requires, so
 * a role that can do something through wp-admin can do the same thing through a
 * tool, and nothing more. Read tools are not free: list_posts surfaces drafts
 * and private posts, which is why it asks for edit_posts rather than read.
 *
 * @return array<string, string>
 */
function sbmcp_tool_capabilities(): array {
    return apply_filters('sbmcp_tool_capabilities', [
        // Posts and pages. Reads include unpublished content.
        'list_posts'          => 'edit_posts',
        'list_pages'          => 'edit_posts',
        'get_post'            => 'edit_posts',
        'get_post_details'    => 'edit_posts',
        'create_post'         => 'edit_posts',
        'update_post'         => 'edit_posts',
        'delete_post'         => 'delete_posts',

        // Media.
        'list_media'          => 'upload_files',
        'get_media'           => 'upload_files',
        'upload_media'        => 'upload_files',
        'delete_media'        => 'delete_posts',

        // Options are site configuration in both directions: reading them
        // exposes configuration, writing them changes how the site runs.
        'get_option'          => 'manage_options',
        'update_option'       => 'manage_options',
        'list_options'        => 'manage_options',

        'list_users'          => 'list_users',

        'list_plugins'        => 'activate_plugins',
        'activate_plugin'     => 'activate_plugins',
        'deactivate_plugin'   => 'activate_plugins',
        'delete_plugin'       => 'delete_plugins',

        'get_menus'           => 'edit_theme_options',
        'get_menu_items'      => 'edit_theme_options',
        'create_menu_item'    => 'edit_theme_options',
        'update_menu_item'    => 'edit_theme_options',
        'delete_menu_item'    => 'edit_theme_options',

        'list_terms'          => 'edit_posts',
        'create_term'         => 'manage_categories',
        'update_term'         => 'manage_categories',
        'delete_term'         => 'manage_categories',

        'list_sidebars'       => 'edit_theme_options',
        'get_widgets'         => 'edit_theme_options',
        'update_widget'       => 'edit_theme_options',

        'get_site_info'       => 'manage_options',
        'flush_rewrite_rules' => 'manage_options',
        'server_ping'         => 'read',
    ]);
}

/**
 * The capability a tool requires.
 *
 * An unmapped tool requires manage_options. That is deliberate and it is the
 * safe direction: Pro registers its own tools through the filter above, and one
 * that has not declared a capability yet stays administrator-only rather than
 * defaulting open. A missing declaration should cost a Pro user an admin
 * account, not hand an editor the database tools.
 *
 * @param string $tool
 * @return string
 */
function sbmcp_tool_capability(string $tool): string {
    $map = sbmcp_tool_capabilities();
    return $map[$tool] ?? 'manage_options';
}

// ---------------------------------------------------------------------------
// Scopes
// ---------------------------------------------------------------------------

/**
 * The scopes a client may request, with the text shown on the consent screen.
 *
 * Three, not thirty. A consent screen listing every tool group is a screen
 * nobody reads, and the capability gate is the real enforcement — scope is the
 * coarse statement of intent the user is asked to approve, and the user's own
 * capabilities bound it from the other side. A client granted mcp:admin by an
 * editor still cannot touch options.
 *
 * @return array<string, array{label: string, description: string}>
 */
function sbmcp_oauth_scopes(): array {
    return apply_filters('sbmcp_oauth_scopes', [
        'mcp:read'  => [
            'label'       => __('Read your content', 'strifebridge-mcp'),
            'description' => __('List and read posts, pages, media, menus, and terms — including drafts and private content.', 'strifebridge-mcp'),
        ],
        'mcp:write' => [
            'label'       => __('Create and change content', 'strifebridge-mcp'),
            'description' => __('Create, update, and delete posts, pages, media, menus, and terms.', 'strifebridge-mcp'),
        ],
        'mcp:admin' => [
            'label'       => __('Manage site configuration', 'strifebridge-mcp'),
            'description' => __('Read and change site options, manage plugins, list users, and read system information.', 'strifebridge-mcp'),
        ],
    ]);
}

/** The scope granted when a client asks for none. */
function sbmcp_oauth_default_scope(): string {
    return 'mcp:read mcp:write';
}

/**
 * The scope a tool sits in.
 *
 * Derived from the tool group and from whether the tool writes, rather than
 * from a third hand-maintained map. Two maps that list every tool already exist
 * — the group map and the read-only list — and a third would be one more place
 * to forget a tool when adding one.
 *
 * @param string $tool
 * @return string
 */
function sbmcp_tool_scope(string $tool): string {
    $group_map = sbmcp_mcp_tool_group_map();
    $group     = $group_map[$tool] ?? null;

    // The administrative surface, regardless of whether the specific call reads
    // or writes: reading options or enumerating users is an admin act.
    if (in_array($group, ['options', 'users', 'plugin_mgmt', 'system'], true)) {
        return 'mcp:admin';
    }

    return sbmcp_tool_is_write($tool) ? 'mcp:write' : 'mcp:read';
}

/**
 * Whether a granted scope string satisfies a required scope.
 *
 * Ordered, not a set membership test: mcp:admin implies mcp:write implies
 * mcp:read. A client granted write access that then had to ask separately for
 * permission to read what it just wrote would be a worse experience for no
 * security gain — anything it can change, it can already observe.
 *
 * @param string $granted  Space-delimited granted scopes.
 * @param string $required Single required scope.
 * @return bool
 */
function sbmcp_oauth_scope_satisfies(string $granted, string $required): bool {
    $rank  = ['mcp:read' => 1, 'mcp:write' => 2, 'mcp:admin' => 3];
    $need  = $rank[$required] ?? 3;
    $held  = 0;

    foreach (preg_split('/\s+/', trim($granted)) ?: [] as $scope) {
        if (isset($rank[$scope]) && $rank[$scope] > $held) {
            $held = $rank[$scope];
        }
    }

    return $held >= $need;
}

/**
 * Filters a requested scope string down to the scopes this server recognises.
 *
 * An unknown scope is dropped rather than rejected: OAuth clients routinely ask
 * for a superset, and failing the whole authorization because one entry was not
 * understood is how a connector ends up unable to connect for no useful reason.
 *
 * @param string $requested
 * @return string Space-delimited, or the default when nothing recognisable remains.
 */
function sbmcp_oauth_sanitize_scope(string $requested): string {
    $known = array_keys(sbmcp_oauth_scopes());
    $kept  = [];

    foreach (preg_split('/\s+/', trim($requested)) ?: [] as $scope) {
        if (in_array($scope, $known, true) && !in_array($scope, $kept, true)) {
            $kept[] = $scope;
        }
    }

    return $kept ? implode(' ', $kept) : sbmcp_oauth_default_scope();
}

// ---------------------------------------------------------------------------
// The gate
// ---------------------------------------------------------------------------

/**
 * Returns a WP_Error when the bound user or the granted scope does not permit
 * this tool, else null.
 *
 * Both error codes are registered as denial codes, so a refusal here is recorded
 * as 'denied' and not as 'error'. That distinction is the reason the activity
 * log is readable: "what did my guards stop?" has to stay separate from "what
 * broke?", and a capability refusal is squarely the former.
 *
 * Returns null when there is no OAuth context — see the file header.
 *
 * NOTE: the capability branch below cannot fail in 3.0.0, because the consent
 * screen requires manage_options and an administrator passes every capability
 * in the map. It is deliberate and it is load-bearing from 3.1, when non-admin
 * accounts can approve a connection. The scope branch above it is live today.
 * See the "THIS GATE IS INERT IN 3.0.0" note in the file header before removing
 * anything here as unreachable.
 *
 * @param string $tool
 * @return WP_Error|null
 */
function sbmcp_capability_guard(string $tool) {
    $context = sbmcp_oauth_context();
    if (!$context) {
        return null;
    }

    $required_scope = sbmcp_tool_scope($tool);
    if (!sbmcp_oauth_scope_satisfies($context['scope'], $required_scope)) {
        return new WP_Error(
            'oauth_scope_insufficient',
            sprintf(
                /* translators: 1: tool name, 2: required OAuth scope. */
                __('This connection was not granted the scope required by %1$s (%2$s). Reconnect and approve that scope.', 'strifebridge-mcp'),
                $tool,
                $required_scope
            ),
            ['status' => 403]
        );
    }

    $capability = sbmcp_tool_capability($tool);
    if (!current_user_can($capability)) {
        return new WP_Error(
            'insufficient_capability',
            sprintf(
                /* translators: 1: tool name, 2: WordPress capability. */
                __('The WordPress account this connection is bound to lacks the %2$s capability required by %1$s.', 'strifebridge-mcp'),
                $tool,
                $capability
            ),
            ['status' => 403]
        );
    }

    return null;
}

/**
 * Registers the gate's error codes as denials rather than failures.
 *
 * @param string[] $codes
 * @return string[]
 */
function sbmcp_oauth_denial_codes(array $codes): array {
    return array_merge($codes, [
        'insufficient_capability',
        'oauth_scope_insufficient',
    ]);
}
add_filter('sbmcp_audit_denial_codes', 'sbmcp_oauth_denial_codes');
