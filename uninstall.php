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
delete_option('sbmcp_oauth_db_version');
delete_option('sbmcp_oauth_registration_disabled');
delete_option('sbmcp_rewrite_version');
delete_option('sbmcp_audit_last_failure');

// Audit log table and its scheduled prune.
wp_clear_scheduled_hook('sbmcp_audit_prune_event');
$sbmcp_audit_table = $wpdb->prefix . 'sbmcp_audit_log';
$wpdb->query("DROP TABLE IF EXISTS {$sbmcp_audit_table}");

// OAuth storage and its scheduled prune. Dropping these revokes every issued
// token by construction: the tokens are only ever stored as hashes in the table
// being dropped, so nothing survives that could still authenticate.
wp_clear_scheduled_hook('sbmcp_oauth_prune_event');
foreach (['sbmcp_oauth_tokens', 'sbmcp_oauth_codes', 'sbmcp_oauth_clients'] as $sbmcp_oauth_table) {
    $sbmcp_oauth_table = $wpdb->prefix . $sbmcp_oauth_table;
    $wpdb->query("DROP TABLE IF EXISTS {$sbmcp_oauth_table}");
}

// Rewrite rules cached the .well-known routes; clear them so the paths stop
// resolving once the handler is gone.
flush_rewrite_rules(false);

// Per-IP rate-limit counters, which are transients rather than options.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sbmcp_rl_%' OR option_name LIKE '_transient_timeout_sbmcp_rl_%'");
