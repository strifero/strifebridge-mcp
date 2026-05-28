<?php
/**
 * WordPress Abilities API bridge.
 *
 * Exposes StrifeBridge MCP tools as native WordPress abilities on WordPress 7.0+
 * so they appear in the core ability registry and become callable through the
 * MCP Adapter by any compatible AI client.
 *
 * Design: this is a thin adapter, not a second implementation. Ability
 * definitions are pulled from the existing MCP tool list (sbmcp_mcp_all_tools()
 * plus the sbmcp_mcp_tools filter, so add-ons are included), and execution is
 * routed back through the existing MCP dispatch (sbmcp_mcp_tools_call). There is
 * one source of truth for both the tool schema and its behavior.
 *
 * Auth model note: the StrifeBridge MCP endpoint authenticates with the bearer
 * token. The abilities surface instead runs under the authenticated WordPress
 * user, so every ability is gated by a real capability via permission_callback.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-tool ability metadata that the MCP tool definitions do not carry:
 * the namespaced ability slug, a category, the capability that gates the
 * ability surface, and whether the action is destructive.
 *
 * Only tools listed here are exposed as abilities. Add-ons (e.g. Pro) can add
 * their own entries via the sbmcp_ability_map filter; by default Pro tools are
 * not exposed as abilities and remain token-only.
 *
 * Format: tool_name => [ability_slug, category, capability, destructive].
 *
 * @return array
 */
function sbmcp_ability_map(): array {
    return array(
        // Posts and pages.
        'list_posts'          => array('strifebridge/list-posts',          'content',  'edit_posts',         false),
        'list_pages'          => array('strifebridge/list-pages',          'content',  'edit_pages',         false),
        'get_post'            => array('strifebridge/get-post',            'content',  'edit_posts',         false),
        'get_post_details'    => array('strifebridge/get-post-details',    'content',  'edit_posts',         false),
        'create_post'         => array('strifebridge/create-post',         'content',  'edit_posts',         false),
        'update_post'         => array('strifebridge/update-post',         'content',  'edit_posts',         false),
        'delete_post'         => array('strifebridge/delete-post',         'content',  'delete_posts',       true),

        // Media.
        'list_media'          => array('strifebridge/list-media',          'media',    'upload_files',       false),
        'get_media'           => array('strifebridge/get-media',           'media',    'upload_files',       false),
        'upload_media'        => array('strifebridge/upload-media',        'media',    'upload_files',       false),
        'delete_media'        => array('strifebridge/delete-media',        'media',    'upload_files',       true),

        // Options.
        'get_option'          => array('strifebridge/get-option',          'options',  'manage_options',     false),
        'update_option'       => array('strifebridge/update-option',       'options',  'manage_options',     false),
        'list_options'        => array('strifebridge/list-options',        'options',  'manage_options',     false),

        // Users.
        'list_users'          => array('strifebridge/list-users',          'users',    'list_users',         false),

        // Plugins.
        'list_plugins'        => array('strifebridge/list-plugins',        'plugins',  'activate_plugins',   false),
        'activate_plugin'     => array('strifebridge/activate-plugin',     'plugins',  'activate_plugins',   false),
        'deactivate_plugin'   => array('strifebridge/deactivate-plugin',   'plugins',  'activate_plugins',   false),
        'delete_plugin'       => array('strifebridge/delete-plugin',       'plugins',  'delete_plugins',     true),

        // Menus.
        'get_menus'           => array('strifebridge/get-menus',           'menus',    'edit_theme_options', false),
        'get_menu_items'      => array('strifebridge/get-menu-items',      'menus',    'edit_theme_options', false),
        'create_menu_item'    => array('strifebridge/create-menu-item',    'menus',    'edit_theme_options', false),
        'update_menu_item'    => array('strifebridge/update-menu-item',    'menus',    'edit_theme_options', false),
        'delete_menu_item'    => array('strifebridge/delete-menu-item',    'menus',    'edit_theme_options', true),

        // Taxonomies.
        'list_terms'          => array('strifebridge/list-terms',          'taxonomy', 'manage_categories',  false),
        'create_term'         => array('strifebridge/create-term',         'taxonomy', 'manage_categories',  false),
        'update_term'         => array('strifebridge/update-term',         'taxonomy', 'manage_categories',  false),
        'delete_term'         => array('strifebridge/delete-term',         'taxonomy', 'manage_categories',  true),

        // Widgets.
        'list_sidebars'       => array('strifebridge/list-sidebars',       'widgets',  'edit_theme_options', false),
        'get_widgets'         => array('strifebridge/get-widgets',         'widgets',  'edit_theme_options', false),
        'update_widget'       => array('strifebridge/update-widget',       'widgets',  'edit_theme_options', false),

        // System.
        'get_site_info'       => array('strifebridge/get-site-info',       'system',   'manage_options',     false),
        'flush_rewrite_rules' => array('strifebridge/flush-rewrite-rules', 'system',   'manage_options',     false),
        'server_ping'         => array('strifebridge/server-ping',         'system',   'read',               false),
    );
}

add_action('wp_abilities_api_init', 'sbmcp_register_abilities');

/**
 * Register StrifeBridge tools as WordPress abilities.
 *
 * Runs only on WordPress 7.0+ where the Abilities API exists. On older
 * installs the wp_abilities_api_init hook never fires, so the MCP endpoint
 * remains the only surface and nothing here executes.
 *
 * @return void
 */
function sbmcp_register_abilities() {
    if (!function_exists('wp_register_ability')) {
        return;
    }

    // Allow the whole bridge to be turned off (option or filter).
    if (get_option('sbmcp_abilities_disabled') || !apply_filters('sbmcp_enable_abilities', true)) {
        return;
    }

    // Collect tool definitions from the same source the MCP tools/list uses,
    // including add-on tools that hook the sbmcp_mcp_tools filter.
    $tools = array();
    foreach (sbmcp_mcp_all_tools() as $name => $def) {
        $tools[$name] = $def;
    }
    $filtered = apply_filters('sbmcp_mcp_tools', array_values(sbmcp_mcp_all_tools()));
    foreach ((array) $filtered as $def) {
        if (isset($def['name'])) {
            $tools[$def['name']] = $def;
        }
    }

    $map        = apply_filters('sbmcp_ability_map', sbmcp_ability_map());
    $group_map  = function_exists('sbmcp_mcp_tool_group_map') ? sbmcp_mcp_tool_group_map() : array();

    foreach ($map as $tool_name => $meta) {
        if (!isset($tools[$tool_name]) || !is_array($meta) || count($meta) < 4) {
            continue;
        }

        list($slug, $category, $cap, $destructive) = $meta;

        // Respect the same per-group enable toggles the MCP surface honors:
        // a group disabled in settings is not exposed as abilities either.
        $group = $group_map[$tool_name] ?? null;
        if ($group && function_exists('sbmcp_tool_enabled') && !sbmcp_tool_enabled($group)) {
            continue;
        }

        $def        = $tools[$tool_name];
        $annotation = $def['annotations'] ?? array();
        $readonly   = !empty($annotation['readOnlyHint']);
        $idempotent = !empty($annotation['idempotentHint']);

        wp_register_ability(
            $slug,
            array(
                'label'               => ucwords(str_replace('_', ' ', $tool_name)),
                'description'         => $def['description'] ?? $tool_name,
                'category'            => $category,
                'input_schema'        => $def['inputSchema'] ?? array('type' => 'object'),
                'meta'                => array(
                    'annotations'  => array(
                        'readonly'    => $readonly,
                        'destructive' => (bool) $destructive,
                        'idempotent'  => $idempotent,
                    ),
                    'show_in_rest' => true,
                ),
                'permission_callback' => function () use ($cap) {
                    return current_user_can($cap);
                },
                'execute_callback'    => function ($input) use ($tool_name) {
                    return sbmcp_ability_execute($tool_name, (array) $input);
                },
            )
        );
    }
}

/**
 * Execute a StrifeBridge tool through the existing MCP dispatch and adapt the
 * result for the Abilities API.
 *
 * The Abilities API validates input against input_schema before calling this,
 * and permission_callback has already enforced the capability, so input here is
 * trusted and authorized. Execution reuses sbmcp_mcp_tools_call so there is a
 * single code path shared with the MCP endpoint (including add-on tool calls
 * routed through the sbmcp_mcp_tool_call filter).
 *
 * @param string $tool_name MCP tool name (underscored).
 * @param array  $input     Validated ability input.
 * @return array|WP_Error Decoded tool result, or WP_Error on failure.
 */
function sbmcp_ability_execute(string $tool_name, array $input) {
    $response = sbmcp_mcp_tools_call('ability', array('name' => $tool_name, 'arguments' => $input));

    $data   = ($response instanceof WP_REST_Response) ? $response->get_data() : $response;
    $result = is_array($data) ? ($data['result'] ?? null) : null;

    if (!is_array($result)) {
        return new WP_Error('sbmcp_ability_failed', 'Tool returned no result.', array('status' => 500));
    }

    if (!empty($result['isError'])) {
        $message = $result['content'][0]['text'] ?? 'Tool execution failed.';
        return new WP_Error('sbmcp_ability_error', $message, array('status' => 400));
    }

    // Tool results are JSON-encoded text content. Decode for a structured
    // ability result; fall back to raw text if it is not JSON.
    $text    = $result['content'][0]['text'] ?? '';
    $decoded = json_decode($text, true);

    return (json_last_error() === JSON_ERROR_NONE) ? $decoded : array('result' => $text);
}
