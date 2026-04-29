<?php
/**
 * Plugin Name: StrifeBridge MCP
 * Plugin URI:  https://strifetech.com/strifebridge-mcp
 * Description: Connect your WordPress site to Claude and other AI models via a secure MCP server and REST API.
 * Version:     2.0.0
 * Author:      Strife Technologies
 * Author URI:  https://strifetech.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: strifebridge-mcp
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

define('SBMCP_VERSION', '2.0.0');
define('SBMCP_PATH', plugin_dir_path(__FILE__));
define('SBMCP_URL',  plugin_dir_url(__FILE__));

require_once SBMCP_PATH . 'includes/auth.php';
require_once SBMCP_PATH . 'includes/tool-toggles.php';
require_once SBMCP_PATH . 'includes/media.php';
require_once SBMCP_PATH . 'includes/options.php';
require_once SBMCP_PATH . 'includes/users.php';
require_once SBMCP_PATH . 'includes/plugin-mgmt.php';
require_once SBMCP_PATH . 'includes/menus.php';
require_once SBMCP_PATH . 'includes/taxonomies.php';
require_once SBMCP_PATH . 'includes/widgets.php';
require_once SBMCP_PATH . 'includes/system.php';
require_once SBMCP_PATH . 'api/api.php';
require_once SBMCP_PATH . 'mcp/mcp.php';
require_once SBMCP_PATH . 'admin/settings.php';

register_activation_hook(__FILE__, 'sbmcp_activate');
function sbmcp_activate() {
    if (!get_option('sbmcp_api_token')) {
        update_option('sbmcp_api_token', bin2hex(random_bytes(32)));
    }
    if (!get_option('sbmcp_activated_at')) {
        update_option('sbmcp_activated_at', time());
    }
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sbmcp_action_links');
function sbmcp_action_links($links) {
    array_unshift($links, '<a href="' . admin_url('options-general.php?page=strifebridge-mcp') . '">' . __('Settings', 'strifebridge-mcp') . '</a>');
    return $links;
}

add_action('rest_api_init', 'sbmcp_register_routes');
function sbmcp_register_routes() {
    $auth = 'sbmcp_validate_token';

    if (sbmcp_tool_enabled('posts')) {
        register_rest_route('strifebridge/v1', '/posts', ['methods' => 'GET',    'callback' => 'sbmcp_get_posts',   'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/posts', ['methods' => 'POST',   'callback' => 'sbmcp_create_post', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/pages', ['methods' => 'GET',    'callback' => 'sbmcp_get_pages',   'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/post/(?P<id>\d+)', ['methods' => 'GET',    'callback' => 'sbmcp_get_post',    'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/post/(?P<id>\d+)', ['methods' => 'POST',   'callback' => 'sbmcp_update_post', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/post/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_post', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('media')) {
        register_rest_route('strifebridge/v1', '/media',             ['methods' => 'GET',    'callback' => 'sbmcp_list_media',   'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/media/upload',      ['methods' => 'POST',   'callback' => 'sbmcp_upload_media', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/media/(?P<id>\d+)', ['methods' => 'GET',    'callback' => 'sbmcp_get_media',    'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/media/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_media', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('options')) {
        register_rest_route('strifebridge/v1', '/option',  ['methods' => 'GET',  'callback' => 'sbmcp_get_option',    'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/option',  ['methods' => 'POST', 'callback' => 'sbmcp_update_option', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/options', ['methods' => 'GET',  'callback' => 'sbmcp_list_options',  'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('users')) {
        register_rest_route('strifebridge/v1', '/users', ['methods' => 'GET', 'callback' => 'sbmcp_list_users', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('plugin_mgmt')) {
        register_rest_route('strifebridge/v1', '/plugins',                     ['methods' => 'GET',    'callback' => 'sbmcp_list_plugins',      'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/plugin/activate',             ['methods' => 'POST',   'callback' => 'sbmcp_activate_plugin',   'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/plugin/deactivate',           ['methods' => 'POST',   'callback' => 'sbmcp_deactivate_plugin', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/plugin/(?P<slug>[a-z0-9-]+)', ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_plugin',     'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('menus')) {
        register_rest_route('strifebridge/v1', '/menus',                  ['methods' => 'GET',    'callback' => 'sbmcp_get_menus',        'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/menu/(?P<id>\d+)/items', ['methods' => 'GET',    'callback' => 'sbmcp_get_menu_items',   'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/menu/(?P<id>\d+)/items', ['methods' => 'POST',   'callback' => 'sbmcp_create_menu_item', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/menu/item/(?P<id>\d+)',  ['methods' => 'POST',   'callback' => 'sbmcp_update_menu_item', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/menu/item/(?P<id>\d+)',  ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_menu_item', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('taxonomies')) {
        register_rest_route('strifebridge/v1', '/terms',            ['methods' => 'GET',    'callback' => 'sbmcp_list_terms',  'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/terms',            ['methods' => 'POST',   'callback' => 'sbmcp_create_term', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/term/(?P<id>\d+)', ['methods' => 'POST',   'callback' => 'sbmcp_update_term', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/term/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_term', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('widgets')) {
        register_rest_route('strifebridge/v1', '/sidebars',                             ['methods' => 'GET',  'callback' => 'sbmcp_list_sidebars', 'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/sidebar/(?P<id>[a-z0-9_-]+)/widgets', ['methods' => 'GET',  'callback' => 'sbmcp_get_widgets',   'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/widget',                               ['methods' => 'POST', 'callback' => 'sbmcp_update_widget', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('system')) {
        register_rest_route('strifebridge/v1', '/system/info',           ['methods' => 'GET',  'callback' => 'sbmcp_get_site_info',      'permission_callback' => $auth]);
        register_rest_route('strifebridge/v1', '/system/flush-rewrites', ['methods' => 'POST', 'callback' => 'sbmcp_flush_rewrite_rules', 'permission_callback' => $auth]);
    }

    // Dual-register old namespace for migration (drop in v2.2.0)
    sbmcp_register_routes_for_namespace('pressbridge/v1', $auth);

    // Extension point: let add-ons (e.g. StrifeBridge MCP Pro) register additional routes.
    do_action('sbmcp_register_rest_routes', $auth);
}

/**
 * Registers routes under a given namespace. Used for backward-compat dual-registration.
 */
function sbmcp_register_routes_for_namespace(string $ns, $auth) {
    if (sbmcp_tool_enabled('posts')) {
        register_rest_route($ns, '/posts', ['methods' => 'GET',    'callback' => 'sbmcp_get_posts',   'permission_callback' => $auth]);
        register_rest_route($ns, '/posts', ['methods' => 'POST',   'callback' => 'sbmcp_create_post', 'permission_callback' => $auth]);
        register_rest_route($ns, '/pages', ['methods' => 'GET',    'callback' => 'sbmcp_get_pages',   'permission_callback' => $auth]);
        register_rest_route($ns, '/post/(?P<id>\d+)', ['methods' => 'GET',    'callback' => 'sbmcp_get_post',    'permission_callback' => $auth]);
        register_rest_route($ns, '/post/(?P<id>\d+)', ['methods' => 'POST',   'callback' => 'sbmcp_update_post', 'permission_callback' => $auth]);
        register_rest_route($ns, '/post/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_post', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('media')) {
        register_rest_route($ns, '/media',             ['methods' => 'GET',    'callback' => 'sbmcp_list_media',   'permission_callback' => $auth]);
        register_rest_route($ns, '/media/upload',      ['methods' => 'POST',   'callback' => 'sbmcp_upload_media', 'permission_callback' => $auth]);
        register_rest_route($ns, '/media/(?P<id>\d+)', ['methods' => 'GET',    'callback' => 'sbmcp_get_media',    'permission_callback' => $auth]);
        register_rest_route($ns, '/media/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_media', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('options')) {
        register_rest_route($ns, '/option',  ['methods' => 'GET',  'callback' => 'sbmcp_get_option',    'permission_callback' => $auth]);
        register_rest_route($ns, '/option',  ['methods' => 'POST', 'callback' => 'sbmcp_update_option', 'permission_callback' => $auth]);
        register_rest_route($ns, '/options', ['methods' => 'GET',  'callback' => 'sbmcp_list_options',  'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('users')) {
        register_rest_route($ns, '/users', ['methods' => 'GET', 'callback' => 'sbmcp_list_users', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('plugin_mgmt')) {
        register_rest_route($ns, '/plugins',                     ['methods' => 'GET',    'callback' => 'sbmcp_list_plugins',      'permission_callback' => $auth]);
        register_rest_route($ns, '/plugin/activate',             ['methods' => 'POST',   'callback' => 'sbmcp_activate_plugin',   'permission_callback' => $auth]);
        register_rest_route($ns, '/plugin/deactivate',           ['methods' => 'POST',   'callback' => 'sbmcp_deactivate_plugin', 'permission_callback' => $auth]);
        register_rest_route($ns, '/plugin/(?P<slug>[a-z0-9-]+)', ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_plugin',     'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('menus')) {
        register_rest_route($ns, '/menus',                  ['methods' => 'GET',    'callback' => 'sbmcp_get_menus',        'permission_callback' => $auth]);
        register_rest_route($ns, '/menu/(?P<id>\d+)/items', ['methods' => 'GET',    'callback' => 'sbmcp_get_menu_items',   'permission_callback' => $auth]);
        register_rest_route($ns, '/menu/(?P<id>\d+)/items', ['methods' => 'POST',   'callback' => 'sbmcp_create_menu_item', 'permission_callback' => $auth]);
        register_rest_route($ns, '/menu/item/(?P<id>\d+)',  ['methods' => 'POST',   'callback' => 'sbmcp_update_menu_item', 'permission_callback' => $auth]);
        register_rest_route($ns, '/menu/item/(?P<id>\d+)',  ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_menu_item', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('taxonomies')) {
        register_rest_route($ns, '/terms',            ['methods' => 'GET',    'callback' => 'sbmcp_list_terms',  'permission_callback' => $auth]);
        register_rest_route($ns, '/terms',            ['methods' => 'POST',   'callback' => 'sbmcp_create_term', 'permission_callback' => $auth]);
        register_rest_route($ns, '/term/(?P<id>\d+)', ['methods' => 'POST',   'callback' => 'sbmcp_update_term', 'permission_callback' => $auth]);
        register_rest_route($ns, '/term/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => 'sbmcp_delete_term', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('widgets')) {
        register_rest_route($ns, '/sidebars',                             ['methods' => 'GET',  'callback' => 'sbmcp_list_sidebars', 'permission_callback' => $auth]);
        register_rest_route($ns, '/sidebar/(?P<id>[a-z0-9_-]+)/widgets', ['methods' => 'GET',  'callback' => 'sbmcp_get_widgets',   'permission_callback' => $auth]);
        register_rest_route($ns, '/widget',                               ['methods' => 'POST', 'callback' => 'sbmcp_update_widget', 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('system')) {
        register_rest_route($ns, '/system/info',           ['methods' => 'GET',  'callback' => 'sbmcp_get_site_info',      'permission_callback' => $auth]);
        register_rest_route($ns, '/system/flush-rewrites', ['methods' => 'POST', 'callback' => 'sbmcp_flush_rewrite_rules', 'permission_callback' => $auth]);
    }
}
