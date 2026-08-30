<?php
/**
 * The tool registry: one declaration per tool, from which everything else is
 * derived.
 *
 * Before this file, a tool was described in four places that had to agree by
 * hand: the group map in mcp.php, the read-only allowlist in safe-mode.php, the
 * capability map in capabilities.php, and the loggable-argument allowlist in
 * audit-log.php. Free kept them in step. An add-on that reached free through
 * the sbmcp_mcp_tool_call and sbmcp_mcp_tools seams had to know to extend all
 * four — and Pro extended none, so its tools had no group (the tool-group
 * toggle did not apply on the MCP path), no read classification (read-only
 * mode refused its reads), fell to the scope default, and logged no
 * arguments. Four separate findings in one audit, with one cause.
 *
 * Now a tool is declared once, here or through the sbmcp_tool_registry filter,
 * and the four consumers read from it. Each consumer keeps its own filter for
 * back-compatibility, applied on top of what the registry produces.
 *
 * Entry shape:
 *
 *   'tool_name' => [
 *       'group'        => 'posts',        // tool-group toggle slug (required)
 *       'read'         => true,           // reads only; permitted in read-only mode
 *       'capability'   => 'edit_posts',   // required of the bound WordPress user
 *       'log_args'     => ['id'],         // argument keys safe to record
 *       'scope'        => 'mcp:admin',    // optional explicit OAuth scope
 *       'irreversible' => true,           // optional: cannot be trashed; refused
 *                                         // under "Trash instead of delete"
 *   ]
 *
 * Derivations, when not stated explicitly:
 *   - scope: mcp:admin for any group in sbmcp_admin_tool_groups(), else
 *     mcp:read when 'read' is true, else mcp:write.
 *   - capability: manage_options.
 *   - read: false. A tool that does not say it reads is treated as a write.
 *
 * Every default fails closed. An add-on that declares nothing but a group gets
 * a write tool requiring manage_options and (unless the group is content)
 * mcp:write — and a tool absent from the registry altogether requires
 * mcp:admin and manage_options.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Every tool the plugin knows, keyed by name.
 *
 * @return array<string, array<string, mixed>>
 */
function sbmcp_tool_registry(): array {
    $posts  = 'posts';
    $media  = 'media';
    $opts   = 'options';
    $users  = 'users';
    $plug   = 'plugin_mgmt';
    $menus  = 'menus';
    $tax    = 'taxonomies';
    $widg   = 'widgets';
    $sys    = 'system';

    $tools = [
        // Posts and pages. Reads include drafts and private content, hence
        // edit_posts rather than read.
        'list_posts'          => ['group' => $posts, 'read' => true,  'capability' => 'edit_posts',    'log_args' => ['type', 'status', 'per_page']],
        'list_pages'          => ['group' => $posts, 'read' => true,  'capability' => 'edit_posts',    'log_args' => ['per_page']],
        'get_post'            => ['group' => $posts, 'read' => true,  'capability' => 'edit_posts',    'log_args' => ['id']],
        'get_post_details'    => ['group' => $posts, 'read' => true,  'capability' => 'edit_posts',    'log_args' => ['id', 'include', 'exclude']],
        'create_post'         => ['group' => $posts, 'read' => false, 'capability' => 'edit_posts',    'log_args' => ['title', 'status', 'type']],
        'update_post'         => ['group' => $posts, 'read' => false, 'capability' => 'edit_posts',    'log_args' => ['id', 'title', 'status']],
        'delete_post'         => ['group' => $posts, 'read' => false, 'capability' => 'delete_posts',  'log_args' => ['id', 'force']],

        // Media. delete_media handles "trash instead of delete" itself, since
        // attachments have a trash when MEDIA_TRASH is on.
        'list_media'          => ['group' => $media, 'read' => true,  'capability' => 'upload_files',  'log_args' => ['per_page']],
        'get_media'           => ['group' => $media, 'read' => true,  'capability' => 'upload_files',  'log_args' => ['id']],
        'upload_media'        => ['group' => $media, 'read' => false, 'capability' => 'upload_files',  'log_args' => ['filename', 'title', 'url']],
        'delete_media'        => ['group' => $media, 'read' => false, 'capability' => 'delete_posts',  'log_args' => ['id']],

        // Options. Reading configuration is an admin act — values include
        // secrets — so the whole group is admin scope, reads included.
        // (see sbmcp_admin_tool_groups()).
        'get_option'          => ['group' => $opts,  'read' => true,  'capability' => 'manage_options', 'log_args' => ['key']],
        'update_option'       => ['group' => $opts,  'read' => false, 'capability' => 'manage_options', 'log_args' => ['key']],
        'list_options'        => ['group' => $opts,  'read' => true,  'capability' => 'manage_options', 'log_args' => ['keys', 'pattern', 'max_value_bytes']],

        // Deliberately admin scope despite being a read: emails and roles are
        // reconnaissance, per an earlier audit.
        'list_users'          => ['group' => $users, 'read' => true,  'capability' => 'list_users',     'log_args' => ['per_page']],

        // Explicit mcp:read: pure reads with no sensitive output, so a read-only
        // grant may use them even though their groups are otherwise admin scope.
        'list_plugins'        => ['group' => $plug,  'read' => true,  'capability' => 'activate_plugins', 'log_args' => [], 'scope' => 'mcp:read'],
        'activate_plugin'     => ['group' => $plug,  'read' => false, 'capability' => 'activate_plugins', 'log_args' => ['plugin']],
        'deactivate_plugin'   => ['group' => $plug,  'read' => false, 'capability' => 'activate_plugins', 'log_args' => ['plugin']],
        'delete_plugin'       => ['group' => $plug,  'read' => false, 'capability' => 'delete_plugins',   'log_args' => ['slug'], 'irreversible' => true],

        'get_menus'           => ['group' => $menus, 'read' => true,  'capability' => 'edit_theme_options', 'log_args' => []],
        'get_menu_items'      => ['group' => $menus, 'read' => true,  'capability' => 'edit_theme_options', 'log_args' => ['id']],
        'create_menu_item'    => ['group' => $menus, 'read' => false, 'capability' => 'edit_theme_options', 'log_args' => ['menu_id', 'title', 'url']],
        'update_menu_item'    => ['group' => $menus, 'read' => false, 'capability' => 'edit_theme_options', 'log_args' => ['id', 'title', 'url']],
        'delete_menu_item'    => ['group' => $menus, 'read' => false, 'capability' => 'edit_theme_options', 'log_args' => ['id'], 'irreversible' => true],

        'list_terms'          => ['group' => $tax,   'read' => true,  'capability' => 'edit_posts',        'log_args' => ['taxonomy', 'per_page']],
        'create_term'         => ['group' => $tax,   'read' => false, 'capability' => 'manage_categories', 'log_args' => ['name', 'taxonomy']],
        'update_term'         => ['group' => $tax,   'read' => false, 'capability' => 'manage_categories', 'log_args' => ['id', 'taxonomy', 'name', 'slug']],
        'delete_term'         => ['group' => $tax,   'read' => false, 'capability' => 'manage_categories', 'log_args' => ['id', 'taxonomy'], 'irreversible' => true],

        'list_sidebars'       => ['group' => $widg,  'read' => true,  'capability' => 'edit_theme_options', 'log_args' => []],
        'get_widgets'         => ['group' => $widg,  'read' => true,  'capability' => 'edit_theme_options', 'log_args' => ['id']],
        'update_widget'       => ['group' => $widg,  'read' => false, 'capability' => 'edit_theme_options', 'log_args' => ['widget_id']],

        'get_site_info'       => ['group' => $sys,   'read' => true,  'capability' => 'manage_options', 'log_args' => [], 'scope' => 'mcp:read'],
        'flush_rewrite_rules' => ['group' => $sys,   'read' => false, 'capability' => 'manage_options', 'log_args' => []],
        // server_ping is how every MCP client confirms the connection is alive;
        // a read-only grant that cannot ping cannot tell it is connected at all.
        'server_ping'         => ['group' => $sys,   'read' => true,  'capability' => 'read',           'log_args' => [], 'scope' => 'mcp:read'],
    ];

    /**
     * Add-ons declare their tools here, once. Every consumer — the tool-group
     * toggle, read-only mode, the capability gate, OAuth scope, and the
     * activity log's argument allowlist — reads from the result.
     *
     * @param array<string, array<string, mixed>> $tools
     */
    return apply_filters('sbmcp_tool_registry', $tools);
}

/**
 * One tool's declaration, or null when the tool is not registered.
 *
 * @param string $tool
 * @return array<string, mixed>|null
 */
function sbmcp_tool_definition(string $tool): ?array {
    $registry = sbmcp_tool_registry();
    if (!isset($registry[$tool]) || !is_array($registry[$tool])) {
        return null;
    }
    return $registry[$tool];
}

/**
 * Tool groups whose every tool is administrative, whether it reads or writes.
 *
 * Reading an option or enumerating users is an admin act; so is reading a
 * theme file or running a SELECT. A group listed here is mcp:admin scope
 * regardless of the individual tool's read flag.
 *
 * @return string[]
 */
function sbmcp_admin_tool_groups(): array {
    return apply_filters('sbmcp_admin_tool_groups', ['options', 'users', 'plugin_mgmt', 'system']);
}

/**
 * Whether a tool has declared that its effect cannot be undone — there is no
 * trash to move the thing to. Under "Trash instead of delete", such a tool is
 * refused rather than run.
 *
 * @param string $tool
 * @return bool
 */
function sbmcp_tool_is_irreversible(string $tool): bool {
    $def = sbmcp_tool_definition($tool);
    return $def !== null && !empty($def['irreversible']);
}
