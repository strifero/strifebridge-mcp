<?php
/**
 * Safe Mode: administrator-set guardrails on what tools are allowed to do.
 *
 * Three independent toggles, stored as an array in the sbmcp_safe_mode option:
 *
 *   force_draft      create_post always produces a draft, whatever was asked for.
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
            'description' => 'Deleting a post moves it to the trash instead of removing it permanently, so it can be restored.',
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
    return [
        'list_posts', 'list_pages', 'get_post', 'get_post_details',
        'list_media', 'get_media',
        'get_option', 'list_options',
        'list_users',
        'list_plugins',
        'get_menus', 'get_menu_items',
        'list_terms',
        'list_sidebars', 'get_widgets',
        'get_site_info', 'server_ping',
    ];
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
 * Wraps a REST callback so it is guarded by read-only mode and recorded in the
 * audit log.
 *
 * @param string   $tool    Tool name used in the log.
 * @param callable $handler The underlying REST callback.
 * @return callable
 */
function sbmcp_guarded_callback(string $tool, callable $handler): callable {
    return function (WP_REST_Request $request) use ($tool, $handler) {
        $args = $request->get_params();

        $denied = sbmcp_write_guard($tool);
        if ($denied) {
            sbmcp_audit_log($tool, $args, 'denied', $denied->get_error_message());
            return $denied;
        }

        $result = call_user_func($handler, $request);

        if (is_wp_error($result)) {
            sbmcp_audit_log($tool, $args, 'error', $result->get_error_message());
        } else {
            sbmcp_audit_log($tool, $args, 'success');
        }
        return $result;
    };
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
