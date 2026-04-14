<?php
/**
 * Media library endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_list_media(WP_REST_Request $request) {
    $per_page = (int) ($request->get_param('per_page') ?? 50);
    $items = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => min($per_page, 200)]);
    return array_map(fn($item) => ['id' => $item->ID, 'title' => $item->post_title, 'filename' => basename(get_attached_file($item->ID)), 'url' => wp_get_attachment_url($item->ID), 'type' => $item->post_mime_type, 'date' => $item->post_date], $items);
}

function sbmcp_get_media(WP_REST_Request $request) {
    $id   = (int) $request['id'];
    $post = get_post($id);
    if (!$post || $post->post_type !== 'attachment') {
        return new WP_Error('not_found', 'Media item not found.', ['status' => 404]);
    }
    return ['id' => $post->ID, 'title' => $post->post_title, 'filename' => basename(get_attached_file($id)), 'url' => wp_get_attachment_url($id), 'type' => $post->post_mime_type, 'alt' => get_post_meta($id, '_wp_attachment_image_alt', true), 'date' => $post->post_date, 'meta' => wp_get_attachment_metadata($id)];
}

function sbmcp_upload_media(WP_REST_Request $request) {
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $params = $request->get_json_params();
    $url    = $params['url']      ?? null;
    $b64    = $params['base64']   ?? null;
    $name   = $params['filename'] ?? null;
    $title  = $params['title']    ?? null;

    if ($url) {
        $scheme = strtolower(wp_parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new WP_Error('invalid_url', 'URL must use http or https.', ['status' => 400]);
        }
        $id = media_sideload_image($url, 0, $title, 'id');
        if (is_wp_error($id)) return new WP_Error('upload_error', $id->get_error_message(), ['status' => 500]);
        return ['status' => 'uploaded', 'id' => $id, 'url' => wp_get_attachment_url($id)];
    }

    if ($b64 && $name) {
        $data = base64_decode($b64);
        if ($data === false) return new WP_Error('invalid_base64', 'Invalid base64 data.', ['status' => 400]);

        $svg_mime_filter = null;
        $svg_ext_filter  = null;
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'svg') {
            $svg_mime_filter = function($mimes) { $mimes['svg'] = 'image/svg+xml'; return $mimes; };
            $svg_ext_filter  = function($data, $file, $filename, $mimes) { if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') { $data['ext'] = 'svg'; $data['type'] = 'image/svg+xml'; } return $data; };
            add_filter('upload_mimes', $svg_mime_filter, 10, 1);
            add_filter('wp_check_filetype_and_ext', $svg_ext_filter, 10, 4);
        }
        $upload = wp_upload_bits($name, null, $data);
        if ($svg_mime_filter) { remove_filter('upload_mimes', $svg_mime_filter, 10); remove_filter('wp_check_filetype_and_ext', $svg_ext_filter, 10); }
        if ($upload['error']) return new WP_Error('upload_error', $upload['error'], ['status' => 500]);

        $filetype = wp_check_filetype($upload['file']);
        $id = wp_insert_attachment(['post_mime_type' => $filetype['type'] ?: 'image/svg+xml', 'post_title' => $title ?? sanitize_file_name($name), 'post_content' => '', 'post_status' => 'inherit'], $upload['file']);
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
        return ['status' => 'uploaded', 'id' => $id, 'url' => wp_get_attachment_url($id)];
    }

    return new WP_Error('missing_fields', 'Provide url, or base64 + filename.', ['status' => 400]);
}

function sbmcp_delete_media(WP_REST_Request $request) {
    $id   = (int) $request['id'];
    $post = get_post($id);
    if (!$post || $post->post_type !== 'attachment') return new WP_Error('not_found', 'Media item not found.', ['status' => 404]);
    $result = wp_delete_attachment($id, true);
    if (!$result) return new WP_Error('delete_error', 'Could not delete media item.', ['status' => 500]);
    return ['status' => 'deleted', 'id' => $id];
}
