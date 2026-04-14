<?php
/**
 * Taxonomy term endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_list_terms(WP_REST_Request $request) {
    $taxonomy = $request->get_param('taxonomy') ?? 'category';
    if (!taxonomy_exists($taxonomy)) return new WP_Error('invalid_taxonomy', 'Taxonomy does not exist.', ['status' => 400]);
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => min((int) ($request->get_param('per_page') ?? 100), 500)]);
    if (is_wp_error($terms)) return new WP_Error('query_error', $terms->get_error_message(), ['status' => 500]);
    return array_map(fn($term) => ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'taxonomy' => $term->taxonomy, 'parent' => $term->parent, 'count' => $term->count, 'description' => $term->description], $terms);
}

function sbmcp_create_term(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $name = $params['name'] ?? null; $taxonomy = $params['taxonomy'] ?? 'category';
    if (!$name) return new WP_Error('missing_name', 'Provide a term name.', ['status' => 400]);
    if (!taxonomy_exists($taxonomy)) return new WP_Error('invalid_taxonomy', 'Taxonomy does not exist.', ['status' => 400]);
    $result = wp_insert_term($name, $taxonomy, ['parent' => $params['parent'] ?? 0, 'description' => $params['description'] ?? '']);
    if (is_wp_error($result)) return new WP_Error('create_error', $result->get_error_message(), ['status' => 400]);
    return ['status' => 'created', 'id' => $result['term_id']];
}

function sbmcp_update_term(WP_REST_Request $request) {
    $id = (int) $request['id']; $params = $request->get_json_params(); $taxonomy = $params['taxonomy'] ?? 'category';
    $args = [];
    if (isset($params['name']))        $args['name']        = $params['name'];
    if (isset($params['description'])) $args['description'] = $params['description'];
    if (isset($params['parent']))      $args['parent']      = (int) $params['parent'];
    if (isset($params['slug']))        $args['slug']        = $params['slug'];
    $result = wp_update_term($id, $taxonomy, $args);
    if (is_wp_error($result)) return new WP_Error('update_error', $result->get_error_message(), ['status' => 400]);
    return ['status' => 'updated', 'id' => $id];
}

function sbmcp_delete_term(WP_REST_Request $request) {
    $id = (int) $request['id']; $taxonomy = $request->get_param('taxonomy') ?? 'category';
    $result = wp_delete_term($id, $taxonomy);
    if (is_wp_error($result)) return new WP_Error('delete_error', $result->get_error_message(), ['status' => 400]);
    if ($result === false) return new WP_Error('not_found', 'Term not found.', ['status' => 404]);
    return ['status' => 'deleted', 'id' => $id];
}
