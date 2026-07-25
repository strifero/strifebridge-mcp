<?php
/**
 * Uninstall hook — runs when the plugin is deleted from the WP admin.
 * Removes all options stored by StrifeBridge MCP so no data is left behind.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('sbmcp_api_token');
delete_option('sbmcp_api_disabled');
delete_option('sbmcp_disabled_tools');
delete_option('sbmcp_abilities_disabled');
delete_option('sbmcp_activated_at');
delete_option('sbmcp_review_dismissed');
delete_option('sbmcp_review_remind_at');
delete_option('sbmcp_db_version');
delete_option('sbmcp_safe_mode');
delete_option('sbmcp_default_author');
delete_option('sbmcp_log_ip');

// Audit log table and its scheduled prune.
wp_clear_scheduled_hook('sbmcp_audit_prune_event');
$sbmcp_audit_table = $wpdb->prefix . 'sbmcp_audit_log';
$wpdb->query("DROP TABLE IF EXISTS {$sbmcp_audit_table}");
