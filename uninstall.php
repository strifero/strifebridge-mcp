<?php
/**
 * Uninstall hook — runs when the plugin is deleted from the WP admin.
 * Removes all options stored by StrifeBridge MCP so no data is left behind.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('sbmcp_api_token');
delete_option('sbmcp_api_disabled');
delete_option('sbmcp_disabled_tools');
delete_option('sbmcp_activated_at');
delete_option('sbmcp_review_dismissed');
delete_option('sbmcp_review_remind_at');
