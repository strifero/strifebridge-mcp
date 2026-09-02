<?php
/**
 * Plugin Name: StrifeBridge MCP
 * Plugin URI:  https://strifetech.com/strifebridge-mcp
 * Description: Connect your WordPress site to Claude and other AI models via a secure MCP server and REST API.
 * Version:     3.1.0
 * Author:      Strife Technologies
 * Author URI:  https://strifetech.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: strifebridge-mcp
 * Requires at least: 5.6
 * Tested up to: 7.1
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

define('SBMCP_VERSION', '3.1.0');
define('SBMCP_PATH', plugin_dir_path(__FILE__));
define('SBMCP_URL',  plugin_dir_url(__FILE__));

require_once SBMCP_PATH . 'includes/helpers.php';
require_once SBMCP_PATH . 'includes/tool-registry.php';
require_once SBMCP_PATH . 'includes/audit-log.php';
require_once SBMCP_PATH . 'includes/safe-mode.php';
require_once SBMCP_PATH . 'includes/auth.php';
require_once SBMCP_PATH . 'includes/oauth-store.php';
require_once SBMCP_PATH . 'includes/oauth-auth.php';
require_once SBMCP_PATH . 'includes/capabilities.php';
require_once SBMCP_PATH . 'includes/oauth-discovery.php';
require_once SBMCP_PATH . 'includes/oauth-endpoints.php';
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
require_once SBMCP_PATH . 'includes/abilities.php';
require_once SBMCP_PATH . 'admin/settings.php';
require_once SBMCP_PATH . 'admin/oauth-consent.php';

register_activation_hook(__FILE__, 'sbmcp_activate');
function sbmcp_activate() {
    $is_new_install = !get_option('sbmcp_api_token');

    if ($is_new_install) {
        update_option('sbmcp_api_token', bin2hex(random_bytes(32)));
    }
    if (!get_option('sbmcp_activated_at')) {
        update_option('sbmcp_activated_at', time());
    }

    // Audit log storage. Also runs on the plugins_loaded upgrade check, because
    // this hook does not fire when a plugin is updated in place.
    sbmcp_audit_install_table();
    sbmcp_audit_schedule_prune();

    // OAuth storage, on the same terms as the audit table above.
    sbmcp_oauth_install_tables();
    sbmcp_oauth_schedule_prune();

    // The .well-known discovery documents are served by rewrite rules, which do
    // not exist until the rules are regenerated. Without this the two documents
    // 404 until someone happens to re-save permalinks, and a connector that
    // cannot read them cannot start the OAuth flow at all.
    sbmcp_oauth_add_rewrite_rules();
    flush_rewrite_rules(false);
    update_option('sbmcp_rewrite_version', SBMCP_REWRITE_VERSION);

    // Safer default for brand-new installs only: deletions go to the trash and
    // stay recoverable. Existing installs upgrading to 2.4.0 keep their current
    // behavior, so an upgrade never silently changes what a tool does.
    if ($is_new_install && get_option('sbmcp_safe_mode') === false) {
        update_option('sbmcp_safe_mode', ['trash_not_delete' => 1]);
    }
}

register_deactivation_hook(__FILE__, 'sbmcp_deactivate');
function sbmcp_deactivate() {
    // Leave the log table and its rows in place: deactivating is not the same
    // as uninstalling, and the history should survive a toggle. Only the cron
    // event is cleared, so it does not keep firing for an inactive plugin.
    sbmcp_audit_unschedule_prune();
    sbmcp_oauth_unschedule_prune();

    // Drops the .well-known rules from the rewrite cache, so the paths stop
    // resolving while the plugin is off rather than 500ing into a handler that
    // is no longer loaded.
    flush_rewrite_rules(false);
    delete_option('sbmcp_rewrite_version');
}

/**
 * Whether StrifeBridge MCP Pro is active on this site.
 *
 * The mirror of Pro's own dependency check: Pro defines SBMCP_PRO_VERSION at
 * the top of its main file, before any guard, so the constant is present
 * whenever Pro is active - including when Pro is active but dormant. The
 * function_exists() fallback covers a Pro old enough to predate the constant.
 *
 * Only call this at runtime, inside a hook, never at file scope: Pro may load
 * after free, so at require time the answer would be a misleading false.
 *
 * @return bool
 */
function sbmcp_pro_is_present() {
    return defined('SBMCP_PRO_VERSION') || function_exists('sbmcp_pro_get_tier');
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sbmcp_action_links');
function sbmcp_action_links($links) {
    array_unshift($links, '<a href="' . admin_url('options-general.php?page=strifebridge-mcp') . '">' . __('Settings', 'strifebridge-mcp') . '</a>');

    // Upsell sits to the left of Settings, and only for users who have not
    // bought Pro yet. Showing "Unlock Pro" to an existing customer reads as a
    // billing failure and generates support mail, so this is gated rather
    // than always-on. One link, no banner: the row stays a normal action row,
    // and nothing is gated behind it.
    if (!sbmcp_pro_is_present()) {
        array_unshift($links, sprintf(
            '<a href="%s" target="_blank" rel="noopener" style="color:#39b54a;font-weight:600;">%s</a>',
            esc_url('https://strifetech.com/strifebridge-mcp/#pricing'),
            esc_html__('Get Pro', 'strifebridge-mcp')
        ));
    }

    return $links;
}

add_action('rest_api_init', 'sbmcp_register_routes');
function sbmcp_register_routes() {
    $auth = 'sbmcp_validate_token';

    sbmcp_register_routes_for_namespace('strifebridge/v1', $auth);
    // DEPRECATED back-compat namespace, kept so existing connectors continue to work. Tracked for removal in issue #9.
    sbmcp_register_routes_for_namespace('pressbridge/v1', $auth);

    // Extension point: let add-ons (e.g. StrifeBridge MCP Pro) register additional routes.
    do_action('sbmcp_register_rest_routes', $auth);
}

function sbmcp_register_routes_for_namespace($ns, $auth) {
    // Every callback goes through sbmcp_guarded_callback(), which applies
    // read-only mode and writes the audit log entry. The first argument is the
    // tool name the call is recorded under, matching the MCP tool names.
    if (sbmcp_tool_enabled('posts')) {
        register_rest_route($ns, '/posts', ['methods' => 'GET',    'callback' => sbmcp_guarded_callback('list_posts',  'sbmcp_get_posts'),   'permission_callback' => $auth]);
        register_rest_route($ns, '/posts', ['methods' => 'POST',   'callback' => sbmcp_guarded_callback('create_post', 'sbmcp_create_post'), 'permission_callback' => $auth]);
        register_rest_route($ns, '/pages', ['methods' => 'GET',    'callback' => sbmcp_guarded_callback('list_pages',  'sbmcp_get_pages'),   'permission_callback' => $auth]);
        register_rest_route($ns, '/post/(?P<id>\d+)', ['methods' => 'GET',    'callback' => sbmcp_guarded_callback('get_post',    'sbmcp_get_post'),    'permission_callback' => $auth]);
        register_rest_route($ns, '/post/(?P<id>\d+)', ['methods' => 'POST',   'callback' => sbmcp_guarded_callback('update_post', 'sbmcp_update_post'), 'permission_callback' => $auth]);
        register_rest_route($ns, '/post/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => sbmcp_guarded_callback('delete_post', 'sbmcp_delete_post'), 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('media')) {
        register_rest_route($ns, '/media',             ['methods' => 'GET',    'callback' => sbmcp_guarded_callback('list_media',   'sbmcp_list_media'),   'permission_callback' => $auth]);
        register_rest_route($ns, '/media/upload',      ['methods' => 'POST',   'callback' => sbmcp_guarded_callback('upload_media', 'sbmcp_upload_media'), 'permission_callback' => $auth]);
        register_rest_route($ns, '/media/(?P<id>\d+)', ['methods' => 'GET',    'callback' => sbmcp_guarded_callback('get_media',    'sbmcp_get_media'),    'permission_callback' => $auth]);
        register_rest_route($ns, '/media/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => sbmcp_guarded_callback('delete_media', 'sbmcp_delete_media'), 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('options')) {
        register_rest_route($ns, '/option',  ['methods' => 'GET',  'callback' => sbmcp_guarded_callback('get_option',    'sbmcp_get_option'),    'permission_callback' => $auth]);
        register_rest_route($ns, '/option',  ['methods' => 'POST', 'callback' => sbmcp_guarded_callback('update_option', 'sbmcp_update_option'), 'permission_callback' => $auth, 'args' => [
            // Declared so the flag is part of the documented REST contract, not
            // just an undocumented body key. Deliberately no 'default': absent and
            // false mean different things here (reject vs. store literally), and a
            // default would collapse the two before the handler could tell them apart.
            'json' => ['type' => 'boolean', 'required' => false, 'description' => 'true decodes value as JSON and stores the resulting array; false stores value as a literal string. Omit to have a JSON-looking value rejected rather than guessed at.'],
        ]]);
        register_rest_route($ns, '/options', ['methods' => 'GET',  'callback' => sbmcp_guarded_callback('list_options',  'sbmcp_list_options'),  'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('users')) {
        register_rest_route($ns, '/users', ['methods' => 'GET', 'callback' => sbmcp_guarded_callback('list_users', 'sbmcp_list_users'), 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('plugin_mgmt')) {
        register_rest_route($ns, '/plugins',                     ['methods' => 'GET',    'callback' => sbmcp_guarded_callback('list_plugins',      'sbmcp_list_plugins'),      'permission_callback' => $auth]);
        register_rest_route($ns, '/plugin/activate',             ['methods' => 'POST',   'callback' => sbmcp_guarded_callback('activate_plugin',   'sbmcp_activate_plugin'),   'permission_callback' => $auth]);
        register_rest_route($ns, '/plugin/deactivate',           ['methods' => 'POST',   'callback' => sbmcp_guarded_callback('deactivate_plugin', 'sbmcp_deactivate_plugin'), 'permission_callback' => $auth]);
        register_rest_route($ns, '/plugin/(?P<slug>[a-z0-9-]+)', ['methods' => 'DELETE', 'callback' => sbmcp_guarded_callback('delete_plugin',     'sbmcp_delete_plugin'),     'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('menus')) {
        register_rest_route($ns, '/menus',                  ['methods' => 'GET',    'callback' => sbmcp_guarded_callback('get_menus',        'sbmcp_get_menus'),        'permission_callback' => $auth]);
        register_rest_route($ns, '/menu/(?P<id>\d+)/items', ['methods' => 'GET',    'callback' => sbmcp_guarded_callback('get_menu_items',   'sbmcp_get_menu_items'),   'permission_callback' => $auth]);
        register_rest_route($ns, '/menu/(?P<id>\d+)/items', ['methods' => 'POST',   'callback' => sbmcp_guarded_callback('create_menu_item', 'sbmcp_create_menu_item'), 'permission_callback' => $auth]);
        register_rest_route($ns, '/menu/item/(?P<id>\d+)',  ['methods' => 'POST',   'callback' => sbmcp_guarded_callback('update_menu_item', 'sbmcp_update_menu_item'), 'permission_callback' => $auth]);
        register_rest_route($ns, '/menu/item/(?P<id>\d+)',  ['methods' => 'DELETE', 'callback' => sbmcp_guarded_callback('delete_menu_item', 'sbmcp_delete_menu_item'), 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('taxonomies')) {
        register_rest_route($ns, '/terms',            ['methods' => 'GET',    'callback' => sbmcp_guarded_callback('list_terms',  'sbmcp_list_terms'),  'permission_callback' => $auth]);
        register_rest_route($ns, '/terms',            ['methods' => 'POST',   'callback' => sbmcp_guarded_callback('create_term', 'sbmcp_create_term'), 'permission_callback' => $auth]);
        register_rest_route($ns, '/term/(?P<id>\d+)', ['methods' => 'POST',   'callback' => sbmcp_guarded_callback('update_term', 'sbmcp_update_term'), 'permission_callback' => $auth]);
        register_rest_route($ns, '/term/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => sbmcp_guarded_callback('delete_term', 'sbmcp_delete_term'), 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('widgets')) {
        register_rest_route($ns, '/sidebars',                             ['methods' => 'GET',  'callback' => sbmcp_guarded_callback('list_sidebars', 'sbmcp_list_sidebars'), 'permission_callback' => $auth]);
        register_rest_route($ns, '/sidebar/(?P<id>[a-z0-9_-]+)/widgets', ['methods' => 'GET',  'callback' => sbmcp_guarded_callback('get_widgets',   'sbmcp_get_widgets'),   'permission_callback' => $auth]);
        register_rest_route($ns, '/widget',                               ['methods' => 'POST', 'callback' => sbmcp_guarded_callback('update_widget', 'sbmcp_update_widget'), 'permission_callback' => $auth]);
    }
    if (sbmcp_tool_enabled('system')) {
        register_rest_route($ns, '/system/info',           ['methods' => 'GET',  'callback' => sbmcp_guarded_callback('get_site_info',       'sbmcp_get_site_info'),      'permission_callback' => $auth]);
        register_rest_route($ns, '/system/flush-rewrites', ['methods' => 'POST', 'callback' => sbmcp_guarded_callback('flush_rewrite_rules', 'sbmcp_flush_rewrite_rules'), 'permission_callback' => $auth]);
    }
}
