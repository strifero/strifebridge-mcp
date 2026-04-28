<?php
/**
 * Navigation menu endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_get_menus() {
    return array_map(fn($m) => ['id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug, 'count' => $m->count], wp_get_nav_menus());
}

function sbmcp_get_menu_items(WP_REST_Request $request) {
    $items = wp_get_nav_menu_items((int) $request['id']);
    if ($items === false) return new WP_Error('not_found', 'Menu not found.', ['status' => 404]);
    return array_map(fn($item) => ['id' => $item->ID, 'title' => $item->title, 'url' => $item->url, 'order' => $item->menu_order, 'parent' => (int) $item->menu_item_parent, 'type' => $item->type, 'object' => $item->object, 'object_id' => $item->object_id], $items);
}

function sbmcp_create_menu_item(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $id = wp_update_nav_menu_item((int) $request['id'], 0, ['menu-item-title' => $params['title'] ?? '', 'menu-item-url' => $params['url'] ?? '', 'menu-item-status' => 'publish', 'menu-item-parent-id' => $params['parent'] ?? 0, 'menu-item-position' => $params['order'] ?? 0, 'menu-item-type' => $params['type'] ?? 'custom']);
    if (is_wp_error($id)) return new WP_Error('create_error', $id->get_error_message(), ['status' => 400]);
    return ['status' => 'created', 'id' => $id];
}

function sbmcp_update_menu_item(WP_REST_Request $request) {
    $item_id = (int) $request['id'];
    $params  = $request->get_json_params();
    $item    = get_post($item_id);
    if (!$item || $item->post_type !== 'nav_menu_item') return new WP_Error('not_found', 'Menu item not found.', ['status' => 404]);
    $menus = wp_get_post_terms($item_id, 'nav_menu');
    if (empty($menus)) return new WP_Error('no_menu', 'Could not determine menu for this item.', ['status' => 400]);
    $item_data = [];
    if (isset($params['title']))  $item_data['menu-item-title']     = $params['title'];
    if (isset($params['url']))    $item_data['menu-item-url']       = $params['url'];
    if (isset($params['order']))  $item_data['menu-item-position']  = $params['order'];
    if (isset($params['parent'])) $item_data['menu-item-parent-id'] = $params['parent'];
    $result = wp_update_nav_menu_item($menus[0]->term_id, $item_id, $item_data);
    if (is_wp_error($result)) return new WP_Error('update_error', $result->get_error_message(), ['status' => 400]);
    return ['status' => 'updated', 'id' => $item_id];
}

function sbmcp_delete_menu_item(WP_REST_Request $request) {
    $item_id = (int) $request['id'];
    $item    = get_post($item_id);
    if (!$item || $item->post_type !== 'nav_menu_item') return new WP_Error('not_found', 'Menu item not found.', ['status' => 404]);
    if (!wp_delete_post($item_id, true)) return new WP_Error('delete_error', 'Could not delete menu item.', ['status' => 500]);
    return ['status' => 'deleted', 'id' => $item_id];
}
