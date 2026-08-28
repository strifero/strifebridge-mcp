<?php
/**
 * Media library endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_list_media(WP_REST_Request $request) {
    // Floor at 1: a negative posts_per_page means "no limit" to WP_Query, so
    // per_page=-1 would return the entire media library in one response.
    $per_page = min(max((int) ($request->get_param('per_page') ?? 50), 1), 200);
    $items = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => $per_page]);
    return array_map(fn($item) => ['id' => $item->ID, 'title' => $item->post_title, 'filename' => basename((string) get_attached_file($item->ID)), 'url' => wp_get_attachment_url($item->ID), 'type' => $item->post_mime_type, 'date' => $item->post_date], $items);
}

function sbmcp_get_media(WP_REST_Request $request) {
    $id   = (int) $request['id'];
    $post = get_post($id);
    if (!$post || $post->post_type !== 'attachment') {
        return new WP_Error('not_found', 'Media item not found.', ['status' => 404]);
    }
    return ['id' => $post->ID, 'title' => $post->post_title, 'filename' => basename((string) get_attached_file($id)), 'url' => wp_get_attachment_url($id), 'type' => $post->post_mime_type, 'alt' => get_post_meta($id, '_wp_attachment_image_alt', true), 'date' => $post->post_date, 'meta' => wp_get_attachment_metadata($id)];
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
        if (!wp_http_validate_url($url)) {
            return new WP_Error('invalid_url', 'URL failed validation (private/loopback addresses are blocked).', ['status' => 400]);
        }
        $id = media_sideload_image($url, 0, $title, 'id');
        if (is_wp_error($id)) return new WP_Error('upload_error', $id->get_error_message(), ['status' => 500]);
        return ['status' => 'uploaded', 'id' => $id, 'url' => wp_get_attachment_url($id)];
    }

    if ($b64 && $name) {
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'svg') {
            return new WP_Error('disallowed_type', 'SVG uploads are not supported.', ['status' => 400]);
        }
        // Cap the encoded payload before decoding so an oversized body can't
        // exhaust memory/disk. 10 MB of base64 decodes to ~7.5 MB on disk.
        if (strlen($b64) > 10485760) {
            return new WP_Error('payload_too_large', 'Base64 payload exceeds the 10 MB limit.', ['status' => 413]);
        }
        // Validate the declared file type from the filename BEFORE writing to disk,
        // so a disallowed type is rejected without ever landing a file on the server.
        $filetype = wp_check_filetype($name);
        if (empty($filetype['type'])) {
            return new WP_Error('disallowed_type', 'File type is not permitted.', ['status' => 400]);
        }
        $data = base64_decode($b64);
        if ($data === false) return new WP_Error('invalid_base64', 'Invalid base64 data.', ['status' => 400]);

        $upload = wp_upload_bits($name, null, $data);
        if ($upload['error']) return new WP_Error('upload_error', $upload['error'], ['status' => 500]);

        // Re-check the written file by CONTENT, not just by extension.
        // wp_check_filetype() above only maps the filename extension to a MIME
        // type, so a file whose real bytes are something else entirely (PHP
        // source or HTML shipped as .jpg) passes it. wp_check_filetype_and_ext()
        // sniffs the written bytes with finfo and reports a corrected
        // proper_filename when the content disagrees with the extension. Either
        // a missing type or a proposed rename means the declared type was a lie,
        // so the file is removed before it can be served.
        // Note: without the fileinfo PHP extension this degrades to the same
        // extension-only check as wp_check_filetype().
        $checked = wp_check_filetype_and_ext($upload['file'], basename($upload['file']));
        if (empty($checked['type'])) {
            @unlink($upload['file']);
            return new WP_Error('disallowed_type', 'File type is not permitted.', ['status' => 400]);
        }
        if (!empty($checked['proper_filename'])) {
            @unlink($upload['file']);
            return new WP_Error('type_mismatch', 'File content does not match its extension. Rename the file to match its actual type.', ['status' => 400]);
        }
        $filetype = ['ext' => $checked['ext'], 'type' => $checked['type']];
        // $wp_error=true: without it a failed insert returns 0, which this
        // handler reported as a successful upload with id 0, leaving the written
        // file orphaned in uploads with no attachment row pointing at it.
        $id = wp_insert_attachment(['post_mime_type' => $filetype['type'], 'post_title' => $title ?? sanitize_file_name($name), 'post_content' => '', 'post_status' => 'inherit'], $upload['file'], 0, true);
        if (is_wp_error($id) || !$id) {
            @unlink($upload['file']);
            return new WP_Error('upload_error', is_wp_error($id) ? $id->get_error_message() : 'Could not create the attachment record.', ['status' => 500]);
        }
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
