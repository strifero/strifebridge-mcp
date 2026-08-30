<?php
/**
 * Safe Mode: administrator-set guardrails on what tools are allowed to do.
 *
 * Three independent toggles, stored as an array in the sbmcp_safe_mode option:
 *
 *   force_draft      create_post always produces a draft, and update_post refuses
 *                    a status change that would publish or schedule a post that
 *                    is not already published.
 *   trash_not_delete delete_post trashes instead of permanently deleting.
 *   read_only        every write tool is refused; only reads run.
 *
 * All default to off, so upgrading an existing install never changes what a
 * tool does. New installs start with trash_not_delete on (see sbmcp_activate).
 *
 * This file also carries the request wrapper that applies the read-only guard
 * and writes the audit log entry for REST-route calls. Wrapping at route
 * registration means the tool name is stated once, next to the callback, with
 * no route-string parsing and no per-handler edits. The MCP and Abilities
 * surfaces call the handlers directly and are guarded and logged inside
 * sbmcp_mcp_tools_call() instead, so nothing is recorded twice.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the safe-mode settings array.
 *
 * @return array<string, int>
 */
function sbmcp_safe_mode(): array {
    $modes = get_option('sbmcp_safe_mode', []);
    return is_array($modes) ? $modes : [];
}

/**
 * Returns true when a given safe-mode toggle is on.
 *
 * @param string $key One of 'force_draft', 'trash_not_delete', 'read_only'.
 * @return bool
 */
function sbmcp_safe_mode_enabled(string $key): bool {
    $modes = sbmcp_safe_mode();
    return !empty($modes[$key]);
}

/**
 * The full set of safe-mode toggles with their admin labels.
 *
 * @return array<string, array{label: string, description: string}>
 */
function sbmcp_safe_mode_options(): array {
    return [
        'force_draft' => [
            'label'       => 'Never publish',
            'description' => 'New posts and pages are always created as drafts, and an update that would publish or schedule an existing draft is refused. Editing a post that is already published still works — publishing stays your decision.',
        ],
        'trash_not_delete' => [
            'label'       => 'Trash instead of delete',
            'description' => 'Nothing is removed permanently. Posts and pages go to the trash instead; media does too when the media trash is enabled. Anything with no trash to go to — terms, menu items, plugins, and add-on tools that say so — is refused rather than deleted.',
        ],
        'read_only' => [
            'label'       => 'Read-only mode',
            'description' => 'Every tool that would create, change, or delete anything is refused. The AI can look but not touch. Refused calls are recorded in the activity log.',
        ],
    ];
}

/**
 * Tools that only read. Everything else counts as a write for read-only mode.
 *
 * Stated as an allowlist so a newly added tool is treated as a write by
 * default: a new tool that slipped through as "read" would silently punch a
 * hole in read-only mode, while a read tool misfiled as a write only produces a
 * visible, reversible refusal.
 *
 * @return string[]
 */
function sbmcp_read_only_tools(): array {
    // Derived from the registry: a tool is a read only if it says so. An
    // add-on tool that declares nothing is a write, which is the direction a
    // mistake should fall.
    $reads = [];
    foreach (sbmcp_tool_registry() as $tool => $def) {
        if (is_array($def) && !empty($def['read'])) {
            $reads[] = $tool;
        }
    }
    return $reads;
}

/**
 * Returns true when the named tool modifies the site.
 *
 * @param string $tool Tool name.
 * @return bool
 */
function sbmcp_tool_is_write(string $tool): bool {
    return !in_array($tool, apply_filters('sbmcp_read_only_tools', sbmcp_read_only_tools()), true);
}

/**
 * Returns a WP_Error when read-only mode should refuse this tool, else null.
 *
 * @param string $tool Tool name.
 * @return WP_Error|null
 */
function sbmcp_write_guard(string $tool) {
    if (!sbmcp_safe_mode_enabled('read_only') || !sbmcp_tool_is_write($tool)) {
        return null;
    }
    return new WP_Error(
        'read_only_mode',
        'Read-only mode is enabled in StrifeBridge MCP Settings. This tool cannot change anything while it is on.',
        ['status' => 403]
    );
}

/**
 * Returns a WP_Error when "Trash instead of delete" should refuse this tool,
 * else null.
 *
 * delete_post and delete_media honour the setting by trashing. A tool that has
 * declared itself irreversible in the registry has nowhere to trash to, so the
 * only way to honour "nothing is removed permanently" is to refuse it.
 *
 * @param string $tool Tool name.
 * @return WP_Error|null
 */
function sbmcp_irreversible_guard(string $tool) {
    if (!sbmcp_safe_mode_enabled('trash_not_delete') || !sbmcp_tool_is_irreversible($tool)) {
        return null;
    }
    return new WP_Error(
        'trash_not_delete_blocked',
        sprintf(
            'Safe Mode "Trash instead of delete" is enabled in StrifeBridge MCP Settings, and %s cannot be undone — there is no trash for it to go to. Turn the setting off to allow permanent deletions.',
            $tool
        ),
        ['status' => 403]
    );
}

/**
 * Tracks the closures produced by sbmcp_guarded_callback(), so the REST
 * enforcement filter below can tell a guarded route from a raw handler.
 *
 * @param Closure|null $register Closure to record.
 * @return array<int, true> Registered closure ids.
 */
function sbmcp_rest_guarded_callbacks(?Closure $register = null): array {
    static $ids = [];
    if ($register !== null) {
        $ids[spl_object_id($register)] = true;
    }
    return $ids;
}

/**
 * Callbacks under the plugin's REST namespaces that are legitimately not tool
 * handlers and so are not wrapped: the MCP transport and the OAuth endpoints.
 * An add-on with a non-tool endpoint declares it through the filter.
 *
 * @return string[]
 */
function sbmcp_rest_unguarded_callbacks(): array {
    return apply_filters('sbmcp_rest_unguarded_callbacks', [
        'sbmcp_mcp_handler',
        'sbmcp_oauth_authorize_handler',
        'sbmcp_oauth_token_handler',
        'sbmcp_oauth_register_handler',
        'sbmcp_oauth_revoke_handler',
    ]);
}

/**
 * Refuses any route in the plugin's namespaces whose callback is neither a
 * guarded closure nor a declared non-tool endpoint.
 *
 * This is what makes it impossible to register a tool route unguarded. Before
 * it, an add-on that passed a raw handler to register_rest_route() got a route
 * that skipped read-only mode, the capability and scope gate, and the activity
 * log — and Pro's every route did exactly that. Rather than trying to guard
 * such a route generically (the tool name is not knowable from a bare
 * callback), the request is refused with a message naming the fix, and the
 * refusal is logged so the administrator can see an add-on needs updating.
 *
 * Runs before the permission callback, so the response is 403 even for an
 * unauthenticated caller; the log entry is throttled per route and IP so a
 * scan cannot flood the table.
 *
 * @param WP_REST_Response|WP_Error|null $response
 * @param array                          $handler
 * @param WP_REST_Request                $request
 * @return WP_REST_Response|WP_Error|null
 */
function sbmcp_rest_enforce_guard($response, $handler, $request) {
    if ($response !== null) {
        return $response;
    }

    $route = $request->get_route();
    if (strpos($route, '/strifebridge/v1') !== 0 && strpos($route, '/pressbridge/v1') !== 0) {
        return $response;
    }

    $callback = $handler['callback'] ?? null;

    if ($callback instanceof Closure && isset(sbmcp_rest_guarded_callbacks()[spl_object_id($callback)])) {
        return $response;
    }
    if (is_string($callback) && in_array($callback, sbmcp_rest_unguarded_callbacks(), true)) {
        return $response;
    }

    $key = 'sbmcp_unguarded_' . md5($route . '|' . (sbmcp_audit_client_ip() ?: 'unknown'));
    if (!get_transient($key)) {
        set_transient($key, 1, MINUTE_IN_SECONDS);
        sbmcp_audit_log('rest', ['route' => $route], 'denied', 'Route is registered without StrifeBridge guards and was refused. The add-on that registers it needs updating.');
    }

    return new WP_Error(
        'unguarded_route',
        'This endpoint is registered without StrifeBridge MCP\'s guards and has been refused. The add-on that registers it must wrap its routes in sbmcp_guarded_callback().',
        ['status' => 403]
    );
}
add_filter('rest_request_before_callbacks', 'sbmcp_rest_enforce_guard', 10, 3);

/**
 * Wraps a REST callback so it is guarded by read-only mode, the capability and
 * scope gate, and "trash instead of delete", and recorded in the audit log.
 *
 * @param string   $tool    Tool name used in the log.
 * @param callable $handler The underlying REST callback.
 * @return callable
 */
function sbmcp_guarded_callback(string $tool, callable $handler): callable {
    $wrapped = function (WP_REST_Request $request) use ($tool, $handler) {
        $args = $request->get_params();

        $denied = sbmcp_write_guard($tool) ?: sbmcp_irreversible_guard($tool);
        if ($denied) {
            sbmcp_audit_log($tool, $args, 'denied', $denied->get_error_message());
            return $denied;
        }

        // Capability and scope. See sbmcp_capability_guard(): this stands down
        // for legacy bearer requests, which carry no bound user.
        $denied = sbmcp_capability_guard($tool);
        if ($denied) {
            sbmcp_audit_log($tool, $args, 'denied', $denied->get_error_message());
            return $denied;
        }

        $result = call_user_func($handler, $request);

        if (is_wp_error($result)) {
            // Not every WP_Error is a failure. A handler that refuses a call —
            // an options guard, an upload type check, a missing required
            // parameter — is a denial, and is logged as one.
            sbmcp_audit_log($tool, $args, sbmcp_audit_result_for_error($result), $result->get_error_message());
        } else {
            sbmcp_audit_log($tool, $args, 'success');
        }
        return $result;
    };

    sbmcp_rest_guarded_callbacks($wrapped);
    return $wrapped;
}

/**
 * Returns the user ID that API-created posts should be attributed to.
 *
 * Token requests carry no logged-in user, so wp_insert_post() would otherwise
 * store post_author = 0 and the post shows up authorless in the admin list,
 * feeds, and author archives. Defaults to the oldest administrator.
 *
 * @return int User ID, or 0 when no administrator can be found.
 */
function sbmcp_default_author(): int {
    $configured = (int) get_option('sbmcp_default_author', 0);
    if ($configured > 0 && get_userdata($configured)) {
        return $configured;
    }

    $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID']);
    return !empty($admins) ? (int) $admins[0] : 0;
}
