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

/**
 * Post types the posts tools refuse to operate on.
 *
 * Attachments and nav menu items are posts underneath, so without this guard
 * the posts tools reach straight past the per-group toggles: wp_delete_post()
 * dispatches to wp_delete_attachment() for attachments, so delete_post would
 * delete media with the Media group switched off, and menu items with Menus
 * off. Callers are routed to the dedicated media and menu tools, which are
 * gated by their own group toggle.
 */
const SBMCP_POSTS_DELEGATED_TYPES = ['attachment', 'nav_menu_item'];

/**
 * Returns a WP_Error if the post type belongs to another tool group, else null.
 *
 * @param string $post_type Post type slug.
 * @return WP_Error|null
 */
function sbmcp_posts_delegated_type_error(string $post_type) {
    if (!in_array($post_type, SBMCP_POSTS_DELEGATED_TYPES, true)) {
        return null;
    }
    $tool = ($post_type === 'attachment') ? 'media' : 'menu';
    return new WP_Error(
        'wrong_tool_group',
        sprintf('Post type "%s" is not available through the posts tools. Use the %s tools instead.', $post_type, $tool),
        ['status' => 403]
    );
}

function sbmcp_get_posts(WP_REST_Request $request) {
    $type  = (string) ($request->get_param('type') ?? 'post');
    $error = sbmcp_posts_delegated_type_error($type);
    if ($error) return $error;
    $per_page = min(max((int) ($request->get_param('per_page') ?? 50), 1), 200);
    $posts = get_posts(['numberposts' => $per_page, 'post_status' => $request->get_param('status') ?? 'publish', 'post_type' => $type]);
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
    $error = sbmcp_posts_delegated_type_error($post->post_type);
    if ($error) return $error;
    return ['id' => $post->ID, 'title' => $post->post_title, 'content' => $post->post_content, 'status' => $post->post_status, 'type' => $post->post_type, 'date' => $post->post_date, 'meta' => sbmcp_filter_public_meta(get_post_meta($post->ID)), 'url' => get_permalink($post->ID)];
}

function sbmcp_create_post(WP_REST_Request $request) {
    $params = $request->get_json_params();

    // Validate the post type: an unregistered type used to be accepted
    // silently, producing content that never appears anywhere in the admin.
    $type  = (string) ($params['type'] ?? 'post');
    $error = sbmcp_posts_delegated_type_error($type);
    if ($error) return $error;
    if (!post_type_exists($type)) {
        return new WP_Error('invalid_post_type', sprintf('Post type "%s" is not registered on this site.', $type), ['status' => 400]);
    }

    $status = (string) ($params['status'] ?? 'draft');
    // Safe Mode: force every new post to draft, whatever was requested.
    if (sbmcp_safe_mode_enabled('force_draft')) {
        $status = 'draft';
    }
    if (!get_post_status_object($status)) {
        return new WP_Error('invalid_post_status', sprintf('Post status "%s" is not registered on this site.', $status), ['status' => 400]);
    }

    $post_data = ['post_title' => $params['title'] ?? 'Untitled', 'post_content' => $params['content'] ?? '', 'post_status' => $status, 'post_type' => $type];

    // An OAuth request runs as a real WordPress user, and that user is the
    // author: wp_insert_post() attributes to the current user when
    // post_author is left unset. Only a legacy token request has nobody
    // behind it; without the default it would store post_author = 0 and the
    // post would show as authorless everywhere. Setting the default
    // unconditionally, as 3.0.0 did, silently overrode the bound user.
    if (!sbmcp_oauth_context()) {
        $author = sbmcp_default_author();
        if ($author > 0) {
            $post_data['post_author'] = $author;
        }
    }

    if (!empty($params['meta']) && is_array($params['meta'])) {
        $meta = sbmcp_filter_public_meta($params['meta']);
        if (!empty($meta)) $post_data['meta_input'] = $meta;
    }
    $id = wp_insert_post($post_data, true);
    if (is_wp_error($id)) return new WP_Error('create_error', $id->get_error_message(), ['status' => 400]);
    $thumb_id = $params['featured_media'] ?? ($params['thumbnail_id'] ?? null);
    if ($thumb_id !== null && (int) $thumb_id > 0) {
        set_post_thumbnail($id, (int) $thumb_id);
    }
    return ['status' => 'created', 'id' => $id, 'url' => get_permalink($id)];
}

function sbmcp_update_post(WP_REST_Request $request) {
    $id = (int) $request['id']; $params = $request->get_json_params();
    // get_json_params() returns null on an absent or unparseable body. The
    // ?? reads below tolerate that, but array_key_exists() does not: on PHP 8
    // it throws a TypeError, so POST /post/123 with no body was a fatal.
    if (!is_array($params)) $params = [];
    $post = get_post($id);
    if (!$post) return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    $error = sbmcp_posts_delegated_type_error($post->post_type);
    if ($error) return $error;
    // Safe Mode: refuse to publish through an update.
    //
    // Deliberately a rejection of the transition rather than a blanket force to
    // 'draft'. Forcing the status would unpublish a live post every time the AI
    // edited one, which is worse than the thing the setting exists to prevent.
    // A post that is already published stays published: re-sending its current
    // status is not a transition, so only a genuine move into publish or future
    // is blocked.
    if (sbmcp_safe_mode_enabled('force_draft') && isset($params['status'])) {
        $requested = (string) $params['status'];
        $publishing = ['publish', 'future'];
        if (in_array($requested, $publishing, true) && !in_array($post->post_status, $publishing, true)) {
            return new WP_Error(
                'safe_mode_publish_blocked',
                'Safe Mode "force draft" is enabled in StrifeBridge MCP Settings, so this post cannot be published through the API. Retry without the status field to save the other changes, or publish it yourself in the editor.',
                ['status' => 403]
            );
        }
    }

    $update = ['ID' => $id];
    if (isset($params['content'])) $update['post_content'] = $params['content'];
    if (isset($params['title']))   $update['post_title']   = $params['title'];
    if (isset($params['status']))  $update['post_status']  = $params['status'];
    $result = wp_update_post($update, true);
    if (is_wp_error($result)) return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    if (array_key_exists('featured_media', $params) || array_key_exists('thumbnail_id', $params)) {
        $thumb_id = (int) ($params['featured_media'] ?? $params['thumbnail_id'] ?? 0);
        if ($thumb_id > 0) {
            set_post_thumbnail($id, $thumb_id);
        } else {
            delete_post_thumbnail($id);
        }
    }
    if (!empty($params['meta']) && is_array($params['meta'])) {
        foreach (sbmcp_filter_public_meta($params['meta']) as $key => $value) update_post_meta($id, $key, $value);
    }
    return ['status' => 'updated', 'id' => $id];
}

function sbmcp_delete_post(WP_REST_Request $request) {
    $id = (int) $request['id']; $force = sbmcp_param_bool($request, 'force');
    $post = get_post($id);
    if (!$post) return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    $error = sbmcp_posts_delegated_type_error($post->post_type);
    if ($error) return $error;

    // Safe Mode: never delete permanently, whatever force says. The post goes
    // to the trash and stays recoverable.
    if (sbmcp_safe_mode_enabled('trash_not_delete')) {
        if ($post->post_status === 'trash') {
            return ['status' => 'trashed', 'id' => $id];
        }
        if (!wp_trash_post($id)) {
            return new WP_Error('delete_error', 'Could not trash post.', ['status' => 500]);
        }
        return ['status' => 'trashed', 'id' => $id];
    }

    if (!$force) {
        // Trash explicitly rather than relying on wp_delete_post() to do it.
        // wp_delete_post($id, false) only diverts to the trash for 'post' and
        // 'page', when the post is not already trashed, and when
        // EMPTY_TRASH_DAYS is nonzero — for anything else it deletes
        // permanently. A WooCommerce product, a wp_template, any custom post
        // type, or a post already in the trash was therefore destroyed by a
        // call that asked to trash it, and then reported as 'trashed' because
        // the status below was derived from the flag rather than from what
        // actually happened. Data loss reported as success.
        if ($post->post_status === 'trash') {
            return ['status' => 'trashed', 'id' => $id];
        }
        if (!wp_trash_post($id)) {
            return new WP_Error('delete_error', 'Could not trash post. The trash may be disabled on this site (EMPTY_TRASH_DAYS); pass force=true to delete permanently.', ['status' => 500]);
        }
    } else {
        if (!wp_delete_post($id, true)) {
            return new WP_Error('delete_error', 'Could not delete post.', ['status' => 500]);
        }
    }

    // Report what the post is now, never what the caller asked for. A post
    // that no longer exists was deleted; one that still exists in the trash
    // was trashed. Any other state means neither happened and is an error.
    $after = get_post_status($id);
    if ($after === false) {
        return ['status' => 'deleted', 'id' => $id];
    }
    if ($after === 'trash') {
        return ['status' => 'trashed', 'id' => $id];
    }
    return new WP_Error('delete_error', sprintf('Post is still present with status "%s".', $after), ['status' => 500]);
}
