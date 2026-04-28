<?php
/**
 * MCP Streamable HTTP server.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'sbmcp_register_mcp_route');

function sbmcp_register_mcp_route() {
    $args = ['methods' => 'POST', 'callback' => 'sbmcp_mcp_handler', 'permission_callback' => 'sbmcp_mcp_validate'];
    register_rest_route('strifebridge/v1', '/http', $args);
    register_rest_route('strifebridge/v1', '/mcp',  $args);

    // Dual-register old namespace for migration (drop in v2.2.0)
    register_rest_route('pressbridge/v1', '/http', $args);
    register_rest_route('pressbridge/v1', '/mcp',  $args);

    // Token-in-path route: the token embedded in the URL IS the authentication.
    // show_in_index => false keeps the token out of the anonymous /wp-json/ route discovery.
    if (!get_option('sbmcp_api_disabled')) {
        $token = get_option('sbmcp_api_token');
        if ($token) {
            $token_args = [
                'methods'             => 'POST',
                'callback'            => 'sbmcp_mcp_handler',
                'permission_callback' => 'sbmcp_mcp_validate_token_path',
                'show_in_index'       => false,
            ];
            register_rest_route('strifebridge/v1', '/' . $token, $token_args);
            register_rest_route('pressbridge/v1', '/' . $token, $token_args);
        }
    }
}

/**
 * Validates the token-in-path route. The token is already embedded in the URL,
 * so we validate it here rather than returning true blindly.
 */
function sbmcp_mcp_validate_token_path(WP_REST_Request $request): bool {
    if (get_option('sbmcp_api_disabled')) return false;
    $stored = get_option('sbmcp_api_token');
    if (!$stored) return false;
    // Extract token from the route — the last segment of the path.
    $route = $request->get_route();
    $segments = explode('/', trim($route, '/'));
    $path_token = end($segments);
    return hash_equals($stored, $path_token);
}

function sbmcp_mcp_validate(WP_REST_Request $request): bool {
    if (get_option('sbmcp_api_disabled')) return false;
    $stored = get_option('sbmcp_api_token');
    if (!$stored) return false;
    $auth = $request->get_header('Authorization');
    if ($auth && strpos($auth, 'Bearer ') === 0) return hash_equals($stored, substr($auth, 7));
    return false;
}

function sbmcp_mcp_handler(WP_REST_Request $request) {
    $body = $request->get_json_params();
    if (!isset($body['id'])) return new WP_REST_Response(null, 202);
    if (($body['jsonrpc'] ?? '') !== '2.0') return sbmcp_mcp_error(-32600, 'Invalid Request', null);
    $method = $body['method'] ?? '';
    $id     = $body['id'];
    $params = $body['params'] ?? [];
    switch ($method) {
        case 'initialize':  return sbmcp_mcp_initialize($id);
        case 'tools/list':  return sbmcp_mcp_tools_list($id);
        case 'tools/call':  return sbmcp_mcp_tools_call($id, $params);
        case 'ping':        return sbmcp_mcp_response($id, new stdClass());
        default:            return sbmcp_mcp_error(-32601, 'Method not found', $id);
    }
}

function sbmcp_mcp_initialize($id) {
    return sbmcp_mcp_response($id, ['protocolVersion' => '2025-03-26', 'capabilities' => ['tools' => new stdClass()], 'serverInfo' => ['name' => 'StrifeBridge MCP', 'version' => SBMCP_VERSION]]);
}

function sbmcp_mcp_tool_group_map(): array {
    return [
        'list_posts'          => 'posts',
        'list_pages'          => 'posts',
        'get_post'            => 'posts',
        'get_post_details'    => 'posts',
        'create_post'         => 'posts',
        'update_post'         => 'posts',
        'delete_post'         => 'posts',
        'list_media'          => 'media',
        'get_media'           => 'media',
        'upload_media'        => 'media',
        'delete_media'        => 'media',
        'get_option'          => 'options',
        'update_option'       => 'options',
        'list_options'        => 'options',
        'list_users'          => 'users',
        'list_plugins'        => 'plugin_mgmt',
        'activate_plugin'     => 'plugin_mgmt',
        'deactivate_plugin'   => 'plugin_mgmt',
        'delete_plugin'       => 'plugin_mgmt',
        'get_menus'           => 'menus',
        'get_menu_items'      => 'menus',
        'create_menu_item'    => 'menus',
        'update_menu_item'    => 'menus',
        'delete_menu_item'    => 'menus',
        'list_terms'          => 'taxonomies',
        'create_term'         => 'taxonomies',
        'update_term'         => 'taxonomies',
        'delete_term'         => 'taxonomies',
        'list_sidebars'       => 'widgets',
        'get_widgets'         => 'widgets',
        'update_widget'       => 'widgets',
        'get_site_info'       => 'system',
        'flush_rewrite_rules' => 'system',
        'server_ping'         => 'system',
    ];
}

// ---------------------------------------------------------------------------
// Tool annotation helpers (MCP 2025-03-26 spec)
// ---------------------------------------------------------------------------

function _sbmcp_ann_read(): array {
    return ['annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true]];
}

function _sbmcp_ann_write(): array {
    return ['annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false]];
}

function sbmcp_mcp_all_tools(): array {
    return [
        'list_posts'   => array_merge(['name' => 'list_posts',   'description' => 'List posts. Optional: type, status, per_page.', 'inputSchema' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'status' => ['type' => 'string'], 'per_page' => ['type' => 'integer']]]], _sbmcp_ann_read()),
        'list_pages'   => array_merge(['name' => 'list_pages',   'description' => 'List published pages.', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]], _sbmcp_ann_read()),
        'get_post'     => array_merge(['name' => 'get_post',     'description' => 'Get full content and details of a post or page by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]], _sbmcp_ann_read()),

        'get_post_details' => array_merge([
            'name'        => 'get_post_details',
            'description' => 'Get complete post data in a single call: all post fields, custom meta, all taxonomy terms, featured image, and author info. More efficient than multiple separate calls. Use exclude=[content] for large posts where you only need metadata.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'      => ['type' => 'integer', 'description' => 'Post ID.'],
                    'include' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Data sections to include. Options: meta, terms, thumbnail, author. Defaults to all.'],
                    'exclude' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Post fields to exclude from the post object. Use content to skip post_content on large posts.'],
                ],
                'required'   => ['id'],
            ],
        ], _sbmcp_ann_read()),

        'create_post'  => array_merge(['name' => 'create_post',  'description' => 'Create a new post or page. Params: title, content, status, type, meta.', 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string'], 'type' => ['type' => 'string'], 'meta' => ['type' => 'object']]]], _sbmcp_ann_write()),
        'update_post'  => array_merge(['name' => 'update_post',  'description' => 'Update a post or page by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'content' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'string'], 'meta' => ['type' => 'object']], 'required' => ['id']]], _sbmcp_ann_write()),
        'delete_post'  => array_merge(['name' => 'delete_post',  'description' => 'Trash or permanently delete a post. force=true permanently deletes.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']], 'required' => ['id']]], _sbmcp_ann_write()),

        'list_media'   => array_merge(['name' => 'list_media',   'description' => 'List media library items.', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer']]]], _sbmcp_ann_read()),
        'get_media'    => array_merge(['name' => 'get_media',    'description' => 'Get details of a media item by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]], _sbmcp_ann_read()),
        'upload_media' => array_merge(['name' => 'upload_media', 'description' => 'Upload media from a URL or base64 string.', 'inputSchema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'base64' => ['type' => 'string'], 'filename' => ['type' => 'string'], 'title' => ['type' => 'string']]]], _sbmcp_ann_write()),
        'delete_media' => array_merge(['name' => 'delete_media', 'description' => 'Permanently delete a media item by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]], _sbmcp_ann_write()),

        'get_option'    => array_merge(['name' => 'get_option',    'description' => 'Read a WordPress option by key.', 'inputSchema' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string']], 'required' => ['key']]], _sbmcp_ann_read()),
        'update_option' => array_merge(['name' => 'update_option', 'description' => 'Write a WordPress option.', 'inputSchema' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string', 'description' => 'Option value. Strings, numbers, and booleans stored as-is; pass objects/arrays as JSON-encoded string.']], 'required' => ['key', 'value']]], _sbmcp_ann_write()),
        'list_options'  => array_merge(['name' => 'list_options',  'description' => 'Search options by key pattern (SQL LIKE).', 'inputSchema' => ['type' => 'object', 'properties' => ['pattern' => ['type' => 'string']]]], _sbmcp_ann_read()),

        'list_users'   => array_merge(['name' => 'list_users',   'description' => 'List all users with roles.', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]], _sbmcp_ann_read()),

        'list_plugins'      => array_merge(['name' => 'list_plugins',      'description' => 'List all installed plugins with active status.', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]], _sbmcp_ann_read()),
        'activate_plugin'   => array_merge(['name' => 'activate_plugin',   'description' => 'Activate a plugin by its file path (e.g. "akismet/akismet.php").', 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin']]], _sbmcp_ann_write()),
        'deactivate_plugin' => array_merge(['name' => 'deactivate_plugin', 'description' => 'Deactivate a plugin by its file path.', 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin']]], _sbmcp_ann_write()),
        'delete_plugin'     => array_merge(['name' => 'delete_plugin',     'description' => 'Deactivate and permanently delete a plugin by slug.', 'inputSchema' => ['type' => 'object', 'properties' => ['slug' => ['type' => 'string']], 'required' => ['slug']]], _sbmcp_ann_write()),

        'get_menus'        => array_merge(['name' => 'get_menus',        'description' => 'List all navigation menus.', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]], _sbmcp_ann_read()),
        'get_menu_items'   => array_merge(['name' => 'get_menu_items',   'description' => 'Get items in a nav menu by menu ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]], _sbmcp_ann_read()),
        'create_menu_item' => array_merge(['name' => 'create_menu_item', 'description' => 'Add an item to a menu.', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string'], 'parent' => ['type' => 'integer'], 'order' => ['type' => 'integer']], 'required' => ['menu_id', 'title']]], _sbmcp_ann_write()),
        'update_menu_item' => array_merge(['name' => 'update_menu_item', 'description' => 'Update a menu item by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string'], 'order' => ['type' => 'integer'], 'parent' => ['type' => 'integer']], 'required' => ['id']]], _sbmcp_ann_write()),
        'delete_menu_item' => array_merge(['name' => 'delete_menu_item', 'description' => 'Delete a menu item by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]], _sbmcp_ann_write()),

        'list_terms'  => array_merge(['name' => 'list_terms',  'description' => 'List terms for a taxonomy (default: category).', 'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string'], 'per_page' => ['type' => 'integer']]]], _sbmcp_ann_read()),
        'create_term' => array_merge(['name' => 'create_term', 'description' => 'Create a term.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'taxonomy' => ['type' => 'string'], 'parent' => ['type' => 'integer'], 'description' => ['type' => 'string']], 'required' => ['name']]], _sbmcp_ann_write()),
        'update_term' => array_merge(['name' => 'update_term', 'description' => 'Update a term by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'taxonomy' => ['type' => 'string'], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'slug' => ['type' => 'string']], 'required' => ['id']]], _sbmcp_ann_write()),
        'delete_term' => array_merge(['name' => 'delete_term', 'description' => 'Delete a term by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'taxonomy' => ['type' => 'string']], 'required' => ['id']]], _sbmcp_ann_write()),

        'list_sidebars' => array_merge(['name' => 'list_sidebars', 'description' => 'List all registered sidebars and their active widget IDs.', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]], _sbmcp_ann_read()),
        'get_widgets'   => array_merge(['name' => 'get_widgets',   'description' => 'Get widgets and their settings for a sidebar by ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']]], _sbmcp_ann_read()),
        'update_widget' => array_merge(['name' => 'update_widget', 'description' => 'Update widget settings.', 'inputSchema' => ['type' => 'object', 'properties' => ['widget_id' => ['type' => 'string'], 'settings' => ['type' => 'object']], 'required' => ['widget_id', 'settings']]], _sbmcp_ann_write()),

        'get_site_info'       => array_merge(['name' => 'get_site_info',       'description' => 'Get site URL, WP version, PHP version, active theme, and more.', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]], _sbmcp_ann_read()),
        'flush_rewrite_rules' => array_merge(['name' => 'flush_rewrite_rules', 'description' => 'Regenerate permalink/rewrite rules.', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]], _sbmcp_ann_write()),

        'server_ping' => array_merge([
            'name'        => 'server_ping',
            'description' => 'Verify connectivity to this WordPress site. Returns site URL, plugin version, WordPress version, PHP version, and current server time.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ], _sbmcp_ann_read()),
    ];
}

function sbmcp_mcp_tools_list($id) {
    $all_tools  = sbmcp_mcp_all_tools();
    $group_map  = sbmcp_mcp_tool_group_map();

    $tools = [];
    foreach ($all_tools as $tool_name => $tool_def) {
        $group = $group_map[$tool_name] ?? null;
        if ($group && !sbmcp_tool_enabled($group)) continue;
        $tools[] = $tool_def;
    }

    // Extension point: let add-ons (e.g. StrifeBridge MCP Pro) register additional tools.
    $tools = apply_filters('sbmcp_mcp_tools', $tools);

    return sbmcp_mcp_response($id, ['tools' => $tools]);
}

function sbmcp_mcp_tools_call($id, $params) {
    $name  = $params['name']      ?? '';
    $input = $params['arguments'] ?? [];

    $group_map = sbmcp_mcp_tool_group_map();
    $group     = $group_map[$name] ?? null;

    if ($group && !sbmcp_tool_enabled($group)) {
        return sbmcp_mcp_tool_error($id, "Tool group '{$group}' is disabled. Enable it in StrifeBridge MCP Settings.");
    }

    // Extension point: let add-ons handle their own tool calls.
    $ext_result = apply_filters('sbmcp_mcp_tool_call', null, $name, $input, $id);
    if ($ext_result !== null) return $ext_result;

    $json_req = function(array $body) {
        $req = new WP_REST_Request();
        $req->set_body(wp_json_encode($body));
        $req->set_header('content-type', 'application/json');
        return $req;
    };
    $param_req = function(array $p) {
        $req = new WP_REST_Request();
        foreach ($p as $k => $v) $req->set_param($k, $v);
        return $req;
    };
    $id_req = function(int $post_id, array $extra = []) {
        $req = new WP_REST_Request();
        $req['id'] = $post_id;
        foreach ($extra as $k => $v) $req->set_param($k, $v);
        return $req;
    };

    switch ($name) {
        case 'list_posts':    return sbmcp_mcp_cb($id, sbmcp_get_posts($param_req(['type' => $input['type'] ?? 'post', 'status' => $input['status'] ?? 'publish', 'per_page' => $input['per_page'] ?? 50])));
        case 'list_pages':    return sbmcp_mcp_cb($id, sbmcp_get_pages($param_req([])));
        case 'get_post':      return sbmcp_mcp_cb($id, sbmcp_get_post($id_req((int) ($input['id'] ?? 0))));
        case 'create_post':   return sbmcp_mcp_cb($id, sbmcp_create_post($json_req($input)));
        case 'update_post':   $pid = (int) ($input['id'] ?? 0); unset($input['id']); $req = $json_req($input); $req['id'] = $pid; return sbmcp_mcp_cb($id, sbmcp_update_post($req));
        case 'delete_post':   return sbmcp_mcp_cb($id, sbmcp_delete_post($id_req((int) ($input['id'] ?? 0), ['force' => $input['force'] ?? false])));

        case 'get_post_details':
            $pid = (int) ($input['id'] ?? 0);
            if (!$pid) return sbmcp_mcp_tool_error($id, 'id is required');
            $p = get_post($pid);
            if (!$p) return sbmcp_mcp_tool_error($id, "Post {$pid} not found");

            $include = $input['include'] ?? ['meta', 'terms', 'thumbnail', 'author'];
            $exclude = $input['exclude'] ?? [];

            $snapshot = ['post' => [
                'ID'            => $p->ID,
                'post_title'    => $p->post_title,
                'post_type'     => $p->post_type,
                'post_status'   => $p->post_status,
                'post_name'     => $p->post_name,
                'post_excerpt'  => $p->post_excerpt,
                'post_date'     => $p->post_date,
                'post_modified' => $p->post_modified,
                'permalink'     => get_permalink($p),
            ]];

            if (!in_array('content', (array) $exclude, true)) {
                $snapshot['post']['post_content'] = $p->post_content;
            }
            if (in_array('meta', (array) $include, true)) {
                $snapshot['meta'] = [];
                foreach (sbmcp_filter_public_meta(get_post_meta($pid)) as $key => $value) {
                    $snapshot['meta'][$key] = count($value) === 1 ? maybe_unserialize($value[0]) : array_map('maybe_unserialize', $value);
                }
            }
            if (in_array('terms', (array) $include, true)) {
                $snapshot['terms'] = [];
                foreach (get_object_taxonomies($p->post_type) as $taxonomy) {
                    $terms = wp_get_post_terms($pid, $taxonomy);
                    if (!is_wp_error($terms) && !empty($terms)) {
                        $snapshot['terms'][$taxonomy] = array_map(function($t) {
                            return ['term_id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug];
                        }, $terms);
                    }
                }
            }
            if (in_array('thumbnail', (array) $include, true)) {
                $thumb_id = get_post_thumbnail_id($pid);
                if ($thumb_id) {
                    $snapshot['thumbnail'] = ['ID' => $thumb_id, 'url' => wp_get_attachment_url($thumb_id), 'alt' => get_post_meta($thumb_id, '_wp_attachment_image_alt', true)];
                }
            }
            if (in_array('author', (array) $include, true)) {
                $author = get_userdata($p->post_author);
                if ($author) {
                    $snapshot['author'] = ['ID' => $author->ID, 'display_name' => $author->display_name, 'user_login' => $author->user_login];
                }
            }
            return sbmcp_mcp_tool_result($id, wp_json_encode($snapshot));

        case 'list_media':   return sbmcp_mcp_cb($id, sbmcp_list_media($param_req(['per_page' => $input['per_page'] ?? 50])));
        case 'get_media':    return sbmcp_mcp_cb($id, sbmcp_get_media($id_req((int) ($input['id'] ?? 0))));
        case 'upload_media': return sbmcp_mcp_cb($id, sbmcp_upload_media($json_req($input)));
        case 'delete_media': return sbmcp_mcp_cb($id, sbmcp_delete_media($id_req((int) ($input['id'] ?? 0))));

        case 'get_option':    return sbmcp_mcp_cb($id, sbmcp_get_option($param_req(['key' => $input['key'] ?? ''])));
        case 'update_option': return sbmcp_mcp_cb($id, sbmcp_update_option($json_req(['key' => $input['key'] ?? '', 'value' => $input['value'] ?? null])));
        case 'list_options':  return sbmcp_mcp_cb($id, sbmcp_list_options($param_req(['pattern' => $input['pattern'] ?? '%'])));

        case 'list_users':   return sbmcp_mcp_cb($id, sbmcp_list_users(new WP_REST_Request()));

        case 'list_plugins':      return sbmcp_mcp_cb($id, sbmcp_list_plugins());
        case 'activate_plugin':   return sbmcp_mcp_cb($id, sbmcp_activate_plugin($json_req(['plugin' => $input['plugin'] ?? ''])));
        case 'deactivate_plugin': return sbmcp_mcp_cb($id, sbmcp_deactivate_plugin($json_req(['plugin' => $input['plugin'] ?? ''])));
        case 'delete_plugin':     $req = new WP_REST_Request(); $req['slug'] = $input['slug'] ?? ''; return sbmcp_mcp_cb($id, sbmcp_delete_plugin($req));

        case 'get_menus':        return sbmcp_mcp_cb($id, sbmcp_get_menus());
        case 'get_menu_items':   return sbmcp_mcp_cb($id, sbmcp_get_menu_items($id_req((int) ($input['id'] ?? 0))));
        case 'create_menu_item': $mid = (int) ($input['menu_id'] ?? 0); unset($input['menu_id']); $req = $json_req($input); $req['id'] = $mid; return sbmcp_mcp_cb($id, sbmcp_create_menu_item($req));
        case 'update_menu_item': $iid = (int) ($input['id'] ?? 0); unset($input['id']); $req = $json_req($input); $req['id'] = $iid; return sbmcp_mcp_cb($id, sbmcp_update_menu_item($req));
        case 'delete_menu_item': return sbmcp_mcp_cb($id, sbmcp_delete_menu_item($id_req((int) ($input['id'] ?? 0))));

        case 'list_terms':  return sbmcp_mcp_cb($id, sbmcp_list_terms($param_req(['taxonomy' => $input['taxonomy'] ?? 'category', 'per_page' => $input['per_page'] ?? 100])));
        case 'create_term': return sbmcp_mcp_cb($id, sbmcp_create_term($json_req($input)));
        case 'update_term': $tid = (int) ($input['id'] ?? 0); unset($input['id']); $req = $json_req($input); $req['id'] = $tid; return sbmcp_mcp_cb($id, sbmcp_update_term($req));
        case 'delete_term': return sbmcp_mcp_cb($id, sbmcp_delete_term($id_req((int) ($input['id'] ?? 0), ['taxonomy' => $input['taxonomy'] ?? 'category'])));

        case 'list_sidebars': return sbmcp_mcp_cb($id, sbmcp_list_sidebars());
        case 'get_widgets':   $req = new WP_REST_Request(); $req['id'] = $input['id'] ?? ''; return sbmcp_mcp_cb($id, sbmcp_get_widgets($req));
        case 'update_widget': return sbmcp_mcp_cb($id, sbmcp_update_widget($json_req($input)));

        case 'get_site_info':       return sbmcp_mcp_cb($id, sbmcp_get_site_info());
        case 'flush_rewrite_rules': return sbmcp_mcp_cb($id, sbmcp_flush_rewrite_rules());

        case 'server_ping':
            return sbmcp_mcp_tool_result($id, wp_json_encode([
                'status'        => 'ok',
                'site'          => get_site_url(),
                'strifebridge'  => SBMCP_VERSION,
                'wordpress'     => get_bloginfo('version'),
                'php'           => PHP_VERSION,
                'time'          => current_time('mysql'),
            ]));

        default: return sbmcp_mcp_error(-32601, "Unknown tool: {$name}", $id);
    }
}

function sbmcp_mcp_cb($id, $result) {
    if (is_wp_error($result)) return sbmcp_mcp_tool_error($id, $result->get_error_message());
    return sbmcp_mcp_tool_result($id, wp_json_encode($result));
}
function sbmcp_mcp_response($id, $result) {
    return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}
function sbmcp_mcp_tool_result($id, string $text) {
    return sbmcp_mcp_response($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]);
}
function sbmcp_mcp_tool_error($id, string $message) {
    return sbmcp_mcp_response($id, ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true]);
}
function sbmcp_mcp_error($code, $message, $id) {
    return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
}
