<?php
/**
 * Audit log engine.
 *
 * Every tool call made through the MCP endpoint, the REST routes, or the
 * Abilities surface is recorded here: what ran, whether it succeeded, and where
 * it came from. Failed authentication attempts are recorded too.
 *
 * Design note for add-ons (e.g. StrifeBridge MCP Pro): this file is the single
 * implementation. Add-ons must NOT reimplement storage or writing. They extend
 * it through three seams:
 *
 *   - sbmcp_audit_log_retention_days / sbmcp_audit_log_retention_rows filters
 *     raise or remove the free tier's rolling-window caps.
 *   - sbmcp_audit_log_query() is the shared read accessor. Free's "Recent
 *     Activity" panel calls it with limit=10; add-ons call it with the full
 *     parameter set for searchable history and export.
 *   - sbmcp_admin_after_settings renders additional admin UI.
 *
 * Privacy: rows are designed to be safe to export and hand to a client. The
 * argument digest is allowlisted per tool and never contains option values,
 * post bodies, credentials, or token material. See sbmcp_audit_summarize_args().
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bumped whenever the schema changes, so upgrades re-run dbDelta.
 * Compared against the sbmcp_db_version option.
 */
define('SBMCP_DB_VERSION', '1.0');

/**
 * Returns the audit log table name, including the site's table prefix.
 *
 * @return string
 */
function sbmcp_audit_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'sbmcp_audit_log';
}

/**
 * Creates or updates the audit log table.
 *
 * Called from both the activation hook and the upgrade check, because
 * register_activation_hook() does NOT fire when a plugin is updated in place.
 * Without the upgrade path, every existing install would come up on 2.4.0 with
 * no table and silently log nothing.
 *
 * @return void
 */
function sbmcp_audit_install_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table   = sbmcp_audit_table();
    $collate = $wpdb->get_charset_collate();

    // dbDelta is whitespace- and format-sensitive: two spaces after PRIMARY KEY,
    // one field per line, KEY names lowercase.
    $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts datetime NOT NULL,
  tool varchar(64) NOT NULL,
  args_summary varchar(255) DEFAULT NULL,
  result varchar(10) NOT NULL,
  error_msg varchar(255) DEFAULT NULL,
  ip varchar(45) DEFAULT NULL,
  token_hint varchar(12) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY ts (ts),
  KEY tool (tool)
) {$collate};";

    dbDelta($sql);
    update_option('sbmcp_db_version', SBMCP_DB_VERSION);
}

/**
 * Runs pending schema upgrades on a normal page load.
 *
 * Covers the plugin-update path, where the activation hook never fires.
 *
 * @return void
 */
function sbmcp_audit_maybe_upgrade() {
    if (get_option('sbmcp_db_version') === SBMCP_DB_VERSION) {
        return;
    }
    sbmcp_audit_install_table();
}
add_action('plugins_loaded', 'sbmcp_audit_maybe_upgrade');

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

/**
 * Per-tool allowlist of argument keys that are safe to record.
 *
 * This is an allowlist, not a denylist: an argument that is not named here is
 * never written to the log, so a new tool or a new parameter cannot leak by
 * default. Values are truncated by sbmcp_audit_summarize_args().
 *
 * Deliberately absent: 'value' (update_option writes option values), 'content'
 * (post bodies), 'settings' (widget payloads), 'base64' (file data), 'meta'.
 *
 * @return array<string, string[]>
 */
function sbmcp_audit_loggable_args(): array {
    return [
        'list_posts'          => ['type', 'status', 'per_page'],
        'list_pages'          => ['per_page'],
        'get_post'            => ['id'],
        'get_post_details'    => ['id', 'include', 'exclude'],
        'create_post'         => ['title', 'status', 'type'],
        'update_post'         => ['id', 'title', 'status'],
        'delete_post'         => ['id', 'force'],
        'list_media'          => ['per_page'],
        'get_media'           => ['id'],
        'upload_media'        => ['filename', 'title', 'url'],
        'delete_media'        => ['id'],
        'get_option'          => ['key'],
        'update_option'       => ['key'],
        'list_options'        => ['keys', 'pattern', 'max_value_bytes'],
        'list_users'          => ['per_page'],
        'list_plugins'        => [],
        'activate_plugin'     => ['plugin'],
        'deactivate_plugin'   => ['plugin'],
        'delete_plugin'       => ['slug'],
        'get_menus'           => [],
        'get_menu_items'      => ['id'],
        'create_menu_item'    => ['menu_id', 'title', 'url'],
        'update_menu_item'    => ['id', 'title', 'url'],
        'delete_menu_item'    => ['id'],
        'list_terms'          => ['taxonomy', 'per_page'],
        'create_term'         => ['name', 'taxonomy'],
        'update_term'         => ['id', 'taxonomy', 'name', 'slug'],
        'delete_term'         => ['id', 'taxonomy'],
        'list_sidebars'       => [],
        'get_widgets'         => ['id'],
        'update_widget'       => ['widget_id'],
        'get_site_info'       => [],
        'flush_rewrite_rules' => [],
        'server_ping'         => [],
        'auth'                => [],
    ];
}

/**
 * Builds a short, sanitized digest of a tool call's arguments.
 *
 * Only keys allowlisted for that specific tool are considered, each value is
 * flattened to a scalar, individually clipped, and the whole string is capped
 * at 255 characters to match the column width.
 *
 * @param string $tool Tool name.
 * @param array  $args Raw tool arguments.
 * @return string Digest such as "id=42, force=true", or '' when nothing is loggable.
 */
function sbmcp_audit_summarize_args(string $tool, array $args): string {
    $allowed = sbmcp_audit_loggable_args()[$tool] ?? [];

    // Unknown tool (an add-on's, say): log nothing rather than guessing which
    // of its arguments are safe.
    if (empty($allowed)) {
        return '';
    }

    $parts = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $args)) {
            continue;
        }
        $value = $args[$key];

        if (is_bool($value)) {
            $flat = $value ? 'true' : 'false';
        } elseif (is_scalar($value)) {
            $flat = (string) $value;
        } elseif (is_array($value)) {
            // Arrays of scalars only (e.g. list_options keys); anything deeper
            // is reduced to a count so nested payloads cannot slip through.
            $scalars = array_filter($value, 'is_scalar');
            $flat = (count($scalars) === count($value))
                ? implode('|', array_map('strval', $value))
                : '[' . count($value) . ' items]';
        } else {
            continue;
        }

        $flat = sanitize_text_field($flat);
        if (strlen($flat) > 40) {
            $flat = substr($flat, 0, 40) . '…';
        }
        $parts[] = $key . '=' . $flat;
    }

    $summary = implode(', ', $parts);
    return (strlen($summary) > 255) ? substr($summary, 0, 254) . '…' : $summary;
}

/**
 * Returns a non-reversible fingerprint of the bearer token used on this request.
 *
 * NOTE: this is an HMAC prefix, not the token's own characters. The log is
 * meant to be exportable and shareable, so it must not carry credential
 * material — even a partial token narrows a brute-force search. The HMAC gives
 * the same "which token was this?" grouping for the future multi-token feature
 * while being useless to an attacker who reads the table.
 *
 * @return string|null 8-character fingerprint, or null when no token was presented.
 */
function sbmcp_audit_token_hint(): ?string {
    $token = sbmcp_audit_request_token();
    if (!$token) {
        return null;
    }
    return substr(hash_hmac('sha256', $token, wp_salt('nonce')), 0, 8);
}

/**
 * Extracts the bearer token from the current request, from any of the three
 * places the plugin accepts one.
 *
 * @return string|null
 */
function sbmcp_audit_request_token(): ?string {
    if (!empty($_SERVER['HTTP_X_STRIFEBRIDGE_TOKEN'])) {
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_STRIFEBRIDGE_TOKEN']));
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        if (strpos($auth, 'Bearer ') === 0) {
            return substr($auth, 7);
        }
    }
    // Token-in-path route: the last URL segment is the credential.
    if (!empty($_SERVER['REQUEST_URI'])) {
        $path = wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
        if ($path && preg_match('#/(?:strifebridge|pressbridge)/v1/([a-f0-9]{64})/?$#i', $path, $m)) {
            return $m[1];
        }
    }
    return null;
}

/**
 * Whether IP addresses may be written to the log.
 *
 * Stored as its own option rather than inside the sbmcp_safe_mode array
 * because it defaults to ON: in a checkbox-array setting an unticked box is
 * indistinguishable from one that was never saved, so a default-on value
 * cannot be turned off. A standalone option with a default makes both states
 * explicit.
 *
 * @return bool
 */
function sbmcp_audit_ip_logging_enabled(): bool {
    return (bool) get_option('sbmcp_log_ip', 1);
}

/**
 * Returns the requesting IP address.
 *
 * @return string|null
 */
function sbmcp_audit_client_ip(): ?string {
    if (empty($_SERVER['REMOTE_ADDR'])) {
        return null;
    }
    $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    $ip = filter_var($ip, FILTER_VALIDATE_IP);
    return $ip ?: null;
}

/**
 * Records one tool call.
 *
 * Logging is strictly best-effort: a failure here must never turn a working
 * tool call into an error, so every failure path returns quietly.
 *
 * @param string      $tool      Tool name (e.g. 'delete_post', or 'auth' for authentication events).
 * @param array       $args      Raw tool arguments; summarized through the allowlist before storage.
 * @param string      $result    One of 'success', 'error', 'denied'.
 * @param string|null $error_msg Optional short failure reason.
 * @return void
 */
function sbmcp_audit_log(string $tool, array $args = [], string $result = 'success', ?string $error_msg = null) {
    global $wpdb;

    if (!in_array($result, ['success', 'error', 'denied'], true)) {
        $result = 'error';
    }

    // Let add-ons or site owners suppress specific entries (e.g. high-volume
    // read tools) without touching this file.
    if (!apply_filters('sbmcp_audit_should_log', true, $tool, $result)) {
        return;
    }

    try {
        if (get_option('sbmcp_db_version') !== SBMCP_DB_VERSION) {
            sbmcp_audit_maybe_upgrade();
        }

        $error_msg = $error_msg !== null ? substr(sanitize_text_field($error_msg), 0, 255) : null;

        $wpdb->insert(
            sbmcp_audit_table(),
            [
                'ts'           => gmdate('Y-m-d H:i:s'),
                'tool'         => substr(sanitize_key($tool), 0, 64),
                'args_summary' => sbmcp_audit_summarize_args($tool, $args) ?: null,
                'result'       => $result,
                'error_msg'    => $error_msg,
                'ip'           => sbmcp_audit_ip_logging_enabled() ? sbmcp_audit_client_ip() : null,
                'token_hint'   => sbmcp_audit_token_hint(),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    } catch (Throwable $e) {
        // Swallowed on purpose: the tool call it describes has already run.
        return;
    }
}

/**
 * Records a rejected authentication attempt, at most once per IP per minute.
 *
 * The throttle matters: the daily prune caps the table at 1000 rows, but
 * someone hammering the endpoint with bad tokens could insert millions of rows
 * between two prune runs. One row per source per minute still shows the attack
 * clearly in the activity log without letting it become the attack.
 *
 * @param string $reason Short description of why the request was rejected.
 * @return void
 */
function sbmcp_audit_log_auth_failure(string $reason) {
    // The throttle key uses the IP even when IP logging is off: it is a hash
    // held in a 60-second transient, never written to the log table, and
    // without it the throttle could not tell two sources apart.
    $ip  = sbmcp_audit_client_ip() ?: 'unknown';
    $key = 'sbmcp_authfail_' . md5($ip);

    if (get_transient($key)) {
        return;
    }
    set_transient($key, 1, MINUTE_IN_SECONDS);

    sbmcp_audit_log('auth', [], 'denied', $reason);
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/**
 * Shared read accessor for the audit log.
 *
 * Free's "Recent Activity" panel uses this with limit=10; add-ons use the full
 * parameter set to build searchable history and CSV export. Keeping one
 * accessor means add-ons never write their own SQL against this table.
 *
 * @param array $args {
 *     @type int    $limit  Max rows (default 10, hard ceiling 1000).
 *     @type int    $offset Row offset (default 0).
 *     @type string $tool   Filter by exact tool name.
 *     @type string $result Filter by 'success' | 'error' | 'denied'.
 *     @type string $since  Filter to rows at or after this UTC 'Y-m-d H:i:s'.
 *     @type string $until  Filter to rows at or before this UTC 'Y-m-d H:i:s'.
 * }
 * @return array<int, array<string, mixed>> Rows newest first.
 */
function sbmcp_audit_log_query(array $args = []): array {
    global $wpdb;

    $defaults = ['limit' => 10, 'offset' => 0, 'tool' => '', 'result' => '', 'since' => '', 'until' => ''];
    $args     = array_merge($defaults, $args);

    $limit  = min(max((int) $args['limit'], 1), 1000);
    $offset = max((int) $args['offset'], 0);

    $where  = ['1=1'];
    $params = [];

    if ($args['tool'] !== '') {
        $where[]  = 'tool = %s';
        $params[] = sanitize_key($args['tool']);
    }
    if ($args['result'] !== '' && in_array($args['result'], ['success', 'error', 'denied'], true)) {
        $where[]  = 'result = %s';
        $params[] = $args['result'];
    }
    if ($args['since'] !== '') {
        $where[]  = 'ts >= %s';
        $params[] = $args['since'];
    }
    if ($args['until'] !== '') {
        $where[]  = 'ts <= %s';
        $params[] = $args['until'];
    }

    $table    = sbmcp_audit_table();
    $params[] = $limit;
    $params[] = $offset;

    $sql = 'SELECT id, ts, tool, args_summary, result, error_msg, ip, token_hint FROM ' . $table
         . ' WHERE ' . implode(' AND ', $where)
         . ' ORDER BY id DESC LIMIT %d OFFSET %d';

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    return $rows ?: [];
}

/**
 * Returns the total number of rows currently held.
 *
 * @return int
 */
function sbmcp_audit_log_count(): int {
    global $wpdb;
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . sbmcp_audit_table());
}

// ---------------------------------------------------------------------------
// Pruning
// ---------------------------------------------------------------------------

/**
 * Trims the log to the configured rolling window.
 *
 * Free keeps 30 days or 1000 rows, whichever bites first. Add-ons raise both
 * caps through the filters; returning 0 from either disables that dimension.
 *
 * @return void
 */
function sbmcp_audit_prune() {
    global $wpdb;

    $days = (int) apply_filters('sbmcp_audit_log_retention_days', 30);
    $rows = (int) apply_filters('sbmcp_audit_log_retention_rows', 1000);
    $table = sbmcp_audit_table();

    if ($days > 0) {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $table . ' WHERE ts < %s', $cutoff));
    }

    if ($rows > 0) {
        // Find the id of the newest row that is still inside the cap, then drop
        // everything older in one statement.
        $threshold = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $table . ' ORDER BY id DESC LIMIT 1 OFFSET %d', $rows - 1));
        if ($threshold) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $table . ' WHERE id < %d', $threshold));
        }
    }
}
add_action('sbmcp_audit_prune_event', 'sbmcp_audit_prune');

/**
 * Schedules the daily prune. Safe to call repeatedly.
 *
 * @return void
 */
function sbmcp_audit_schedule_prune() {
    if (!wp_next_scheduled('sbmcp_audit_prune_event')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sbmcp_audit_prune_event');
    }
}
add_action('plugins_loaded', 'sbmcp_audit_schedule_prune');

/**
 * Clears the scheduled prune. Called on deactivation.
 *
 * @return void
 */
function sbmcp_audit_unschedule_prune() {
    $timestamp = wp_next_scheduled('sbmcp_audit_prune_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'sbmcp_audit_prune_event');
    }
}
