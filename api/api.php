<?php
/**
 * Posts and pages REST API callbacks.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Strip meta keys that begin with an underscore. WordPress treats those as
 * protected/internal (REST blocks writes to them unless explicitly registered),
 * so the public MCP surface mirrors that behavior.
 */
function sbmcp_filter_public_meta(array $meta): array {
    $out = [];
    foreach ($meta as $key => $value) {
        if (is_string($key) && strpos($key, '_') === 0) continue;
        $out[$key] = $value;
    }
    return $out;
}

function sbmcp_get_posts(WP_REST_Request $request): array {
    $per_page = min(max((int) ($request->get_param('per_page') ?? 50), 1), 200);
    $posts = get_posts(['numberposts' => $per_page, 'post_status' => $request->get_param('status') ?? 'publish', 'post_type' => $request->get_param('type') ?? 'post']);
    return array_map(fn($p) => ['id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'date' => $p->post_date, 'url' => get_permalink($p->ID)], $posts);
}

function sbmcp_get_pages(WP_REST_Request $request): array {
    $per_page = min(max((int) ($request->get_param('per_page') ?? 50), 1), 200);
    $pages = get_posts(['numberposts' => $per_page, 'post_status' => $request->get_param('status') ?? 'publish', 'post_type' => 'page']);
    return array_map(fn($p) => ['id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'date' => $p->post_date, 'url' => get_permalink($p->ID)], $pages);
}

function sbmcp_get_post(WP_REST_Request $request) {
    $post = get_post((int) $request['id']);
    if (!$post) return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    return ['id' => $post->ID, 'title' => $post->post_title, 'content' => $post->post_content, 'status' => $post->post_status, 'type' => $post->post_type, 'date' => $post->post_date, 'meta' => sbmcp_filter_public_meta(get_post_meta($post->ID)), 'url' => get_permalink($post->ID)];
}

function sbmcp_create_post(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $post_data = ['post_title' => $params['title'] ?? 'Untitled', 'post_content' => $params['content'] ?? '', 'post_status' => $params['status'] ?? 'draft', 'post_type' => $params['type'] ?? 'post'];
    if (!empty($params['meta']) && is_array($params['meta'])) {
        $meta = sbmcp_filter_public_meta($params['meta']);
        if (!empty($meta)) $post_data['meta_input'] = $meta;
    }
    $id = wp_insert_post($post_data, true);
    if (is_wp_error($id)) return new WP_Error('create_error', $id->get_error_message(), ['status' => 400]);
    return ['status' => 'created', 'id' => $id, 'url' => get_permalink($id)];
}

function sbmcp_update_post(WP_REST_Request $request) {
    $id = (int) $request['id']; $params = $request->get_json_params();
    if (!get_post($id)) return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    $update = ['ID' => $id];
    if (isset($params['content'])) $update['post_content'] = $params['content'];
    if (isset($params['title']))   $update['post_title']   = $params['title'];
    if (isset($params['status']))  $update['post_status']  = $params['status'];
    $result = wp_update_post($update, true);
    if (is_wp_error($result)) return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    if (!empty($params['meta']) && is_array($params['meta'])) {
        foreach (sbmcp_filter_public_meta($params['meta']) as $key => $value) update_post_meta($id, $key, $value);
    }
    return ['status' => 'updated', 'id' => $id];
}

function sbmcp_delete_post(WP_REST_Request $request) {
    $id = (int) $request['id']; $force = (bool) ($request->get_param('force') ?? false);
    if (!get_post($id)) return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    $result = wp_delete_post($id, $force);
    if (!$result) return new WP_Error('delete_error', 'Could not delete post.', ['status' => 500]);
    return ['status' => $force ? 'deleted' : 'trashed', 'id' => $id];
}
