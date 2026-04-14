<?php
/**
 * Widget and sidebar endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_list_sidebars() {
    global $wp_registered_sidebars;
    $active = get_option('sidebars_widgets', []); $result = [];
    foreach ($wp_registered_sidebars as $id => $sidebar) {
        $result[] = ['id' => $id, 'name' => $sidebar['name'], 'description' => $sidebar['description'], 'widgets' => $active[$id] ?? []];
    }
    return $result;
}

function sbmcp_get_widgets(WP_REST_Request $request) {
    $sidebar_id = $request['id']; $active = get_option('sidebars_widgets', []);
    if (!isset($active[$sidebar_id])) return new WP_Error('not_found', 'Sidebar not found or has no widgets.', ['status' => 404]);
    $widgets = [];
    foreach ($active[$sidebar_id] as $widget_id) {
        if (!preg_match('/^(.+)-(\d+)$/', $widget_id, $matches)) continue;
        // WordPress stores each widget type's settings in an option named 'widget_{type}'.
        // This is WP core's own naming convention, not a StrifeBridge MCP prefix.
        $settings = get_option('widget_' . $matches[1], []);
        $widgets[] = ['id' => $widget_id, 'type' => $matches[1], 'number' => (int) $matches[2], 'settings' => $settings[(int) $matches[2]] ?? []];
    }
    return ['sidebar' => $sidebar_id, 'widgets' => $widgets];
}

function sbmcp_update_widget(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $widget_id = $params['widget_id'] ?? null; $settings = $params['settings'] ?? null;
    if (!$widget_id || $settings === null) return new WP_Error('missing_fields', 'Provide widget_id and settings.', ['status' => 400]);
    if (!preg_match('/^(.+)-(\d+)$/', $widget_id, $matches)) return new WP_Error('invalid_widget_id', 'Widget ID format must be "type-number".', ['status' => 400]);
    // WordPress stores each widget type's settings in an option named 'widget_{type}'.
    // This is WP core's own naming convention, not a StrifeBridge MCP prefix.
    $all = get_option('widget_' . $matches[1], []);
    $all[(int) $matches[2]] = $settings; $all['_multiwidget'] = 1;
    update_option('widget_' . $matches[1], $all);
    return ['status' => 'updated', 'widget_id' => $widget_id];
}
