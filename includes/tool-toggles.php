<?php
/**
 * Tool toggle definitions and helper.
 *
 * Tools are stored in wp_options as an array of DISABLED tool slugs
 * under the key 'sbmcp_disabled_tools'. Default = all enabled.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the full list of free tool groups with labels.
 *
 * @return array<string, array{label: string, description: string}>
 */
function sbmcp_tool_groups(): array {
    $groups = [
        'posts'        => ['label' => 'Posts & Pages',       'description' => 'Create, read, update, and delete posts and pages.'],
        'media'        => ['label' => 'Media Library',       'description' => 'List, upload, retrieve, and delete media.'],
        'options'      => ['label' => 'WordPress Options',   'description' => 'Get, update, and list wp_options entries.'],
        'users'        => ['label' => 'Users',               'description' => 'List users and their roles.'],
        'plugin_mgmt'  => ['label' => 'Plugin Management',   'description' => 'List, activate, deactivate, and delete plugins.'],
        'menus'        => ['label' => 'Navigation Menus',    'description' => 'Read and manage navigation menus and menu items.'],
        'taxonomies'   => ['label' => 'Taxonomies & Terms',  'description' => 'List, create, update, and delete taxonomy terms.'],
        'widgets'      => ['label' => 'Widgets',             'description' => 'Read and update sidebar widgets.'],
        'system'       => ['label' => 'System',              'description' => 'Site info and rewrite rules.'],
    ];

    // Extension point: let add-ons register additional tool groups.
    return apply_filters('sbmcp_tool_groups', $groups);
}

/**
 * Returns true if the given tool group is currently enabled.
 *
 * @param string $slug  Tool group slug (e.g. 'posts', 'media').
 * @return bool
 */
function sbmcp_tool_enabled(string $slug): bool {
    $disabled = get_option('sbmcp_disabled_tools', []);
    if (!is_array($disabled)) {
        $disabled = [];
    }
    return !in_array($slug, $disabled, true);
}
