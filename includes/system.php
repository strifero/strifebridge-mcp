<?php
/**
 * System information endpoints.
 *
 * Error log, cron jobs, and cache clearing are available in StrifeBridge MCP Pro.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_get_site_info() {
    $theme = wp_get_theme();
    return ['site_name' => get_bloginfo('name'), 'site_url' => get_site_url(), 'home_url' => get_home_url(), 'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'active_theme' => ['name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'slug' => get_stylesheet()], 'multisite' => is_multisite(), 'debug_mode' => defined('WP_DEBUG') && WP_DEBUG, 'timezone' => get_option('timezone_string') ?: get_option('gmt_offset'), 'language' => get_locale()];
}

function sbmcp_flush_rewrite_rules() {
    flush_rewrite_rules(true);
    return ['status' => 'flushed'];
}
