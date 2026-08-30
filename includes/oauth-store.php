<?php
/**
 * Storage layer for OAuth 2.1: registered clients, authorization codes, and
 * issued token pairs.
 *
 * Three tables rather than one, because the three have genuinely different
 * lifetimes. A client is durable and survives every token it is ever issued. A
 * code lives sixty seconds and is destroyed by being used. A token pair lives
 * an hour and thirty days respectively and rotates. Folding them together would
 * mean one prune policy for three different clocks.
 *
 * SECRET STORAGE
 *
 * Nothing here stores a secret in a form that can be read back. Client secrets,
 * authorization codes, and both halves of a token pair are stored as SHA-256
 * hashes, and the plaintext is returned exactly once — at the moment of issue —
 * and never again.
 *
 * SHA-256 and not password_hash(): every one of these values is 256 bits of
 * output from random_bytes(). A slow KDF exists to make guessing a low-entropy
 * human-chosen password expensive, and buys nothing against a value that cannot
 * be guessed at all. What a KDF would cost here is the ability to find the row:
 * bcrypt salts each hash, so the same secret hashes differently every time and
 * there is nothing to look up by. A deterministic hash is what makes an indexed
 * lookup possible, which is the whole reason this design works.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema version for the OAuth tables.
 *
 * Deliberately separate from SBMCP_DB_VERSION, which versions the audit log.
 * The two schemas change for unrelated reasons, and sharing a version would
 * mean every audit change re-ran the OAuth migration and vice versa.
 */
define('SBMCP_OAUTH_DB_VERSION', '1.0');

/** Authorization code lifetime. OAuth 2.1 recommends well under ten minutes. */
define('SBMCP_OAUTH_CODE_TTL', 60);

/** Access token lifetime. */
define('SBMCP_OAUTH_ACCESS_TTL', HOUR_IN_SECONDS);

/** Refresh token lifetime. Rotated on every use, so this is the idle ceiling. */
define('SBMCP_OAUTH_REFRESH_TTL', 30 * DAY_IN_SECONDS);

// ---------------------------------------------------------------------------
// Table names
// ---------------------------------------------------------------------------

function sbmcp_oauth_clients_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'sbmcp_oauth_clients';
}

function sbmcp_oauth_codes_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'sbmcp_oauth_codes';
}

function sbmcp_oauth_tokens_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'sbmcp_oauth_tokens';
}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

/**
 * Creates or updates the three OAuth tables.
 *
 * Called from the activation hook and from the upgrade check, for the same
 * reason sbmcp_audit_install_table() is: register_activation_hook() does not
 * fire when a plugin is updated in place, so an install upgrading to 3.0.0
 * would otherwise come up with no OAuth tables at all.
 *
 * @return void
 */
function sbmcp_oauth_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $collate = $wpdb->get_charset_collate();
    $clients = sbmcp_oauth_clients_table();
    $codes   = sbmcp_oauth_codes_table();
    $tokens  = sbmcp_oauth_tokens_table();

    // dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one field
    // per line, lowercase KEY names. Matches the audit table's formatting.
    dbDelta("CREATE TABLE {$clients} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id varchar(64) NOT NULL,
  client_secret_hash char(64) DEFAULT NULL,
  client_name varchar(191) NOT NULL,
  redirect_uris text NOT NULL,
  client_uri varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  last_used_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY client_id (client_id)
) {$collate};");

    dbDelta("CREATE TABLE {$codes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code_hash char(64) NOT NULL,
  client_id varchar(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  redirect_uri varchar(255) NOT NULL,
  code_challenge varchar(128) NOT NULL,
  scope varchar(255) NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code_hash (code_hash),
  KEY expires_at (expires_at)
) {$collate};");

    dbDelta("CREATE TABLE {$tokens} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  access_hash char(64) NOT NULL,
  refresh_hash char(64) DEFAULT NULL,
  client_id varchar(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  scope varchar(255) NOT NULL,
  access_expires_at datetime NOT NULL,
  refresh_expires_at datetime DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  last_used_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY access_hash (access_hash),
  KEY refresh_hash (refresh_hash),
  KEY client_user (client_id, user_id),
  KEY access_expires_at (access_expires_at)
) {$collate};");

    update_option('sbmcp_oauth_db_version', SBMCP_OAUTH_DB_VERSION);
}

/**
 * Runs the OAuth schema migration on a normal page load, covering the
 * plugin-update path where the activation hook never fires.
 *
 * @return void
 */
function sbmcp_oauth_maybe_upgrade() {
    if (get_option('sbmcp_oauth_db_version') === SBMCP_OAUTH_DB_VERSION) {
        return;
    }
    sbmcp_oauth_install_tables();
}
add_action('plugins_loaded', 'sbmcp_oauth_maybe_upgrade');

// ---------------------------------------------------------------------------
// Secret handling
// ---------------------------------------------------------------------------

/**
 * The lookup hash for a secret. See the file header for why this is SHA-256.
 *
 * @param string $secret Plaintext secret.
 * @return string 64-char lowercase hex.
 */
function sbmcp_oauth_hash(string $secret): string {
    return hash('sha256', $secret);
}

/**
 * A new high-entropy secret, URL-safe so it survives being carried in a query
 * string or an Authorization header without encoding.
 *
 * @param int $bytes Entropy in bytes. 32 bytes = 256 bits.
 * @return string
 */
function sbmcp_oauth_random(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * Constant-time comparison of a presented secret against a stored hash.
 *
 * The SQL lookup that found the row already matched on the hash, so this is the
 * second of two checks rather than the only one. It is here because the lookup
 * is an index probe whose timing is not controlled, and because a row reached by
 * any other path — a refresh token found by its non-unique index, a client row
 * loaded by client_id — still has to have its secret verified rather than
 * assumed. hash_equals() is the only comparison of secret material in the
 * plugin; == and === never appear on this data.
 *
 * @param string|null $stored_hash Hash from the database.
 * @param string      $presented   Plaintext offered by the caller.
 * @return bool
 */
function sbmcp_oauth_verify(?string $stored_hash, string $presented): bool {
    if ($stored_hash === null || $stored_hash === '') {
        return false;
    }
    return hash_equals($stored_hash, sbmcp_oauth_hash($presented));
}

/** Current UTC time in MySQL datetime format. */
function sbmcp_oauth_now(): string {
    return gmdate('Y-m-d H:i:s');
}

/** UTC datetime $seconds from now. */
function sbmcp_oauth_from_now(int $seconds): string {
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

// ---------------------------------------------------------------------------
// Clients
// ---------------------------------------------------------------------------

/**
 * Registers a client and returns its credentials.
 *
 * The plaintext secret is in the return value and nowhere else — only its hash
 * is stored, so this is the one and only time it can be read.
 *
 * @param string   $name          Human-readable client name, shown on the consent screen.
 * @param string[] $redirect_uris Absolute redirect URIs. Matched exactly at authorize time.
 * @param string   $client_uri    Optional homepage, shown on the consent screen.
 * @return array{client_id: string, client_secret: string}|null Null when the insert fails.
 */
function sbmcp_oauth_create_client(string $name, array $redirect_uris, string $client_uri = ''): ?array {
    global $wpdb;

    $client_id     = sbmcp_oauth_random(16);
    $client_secret = sbmcp_oauth_random(32);

    $written = $wpdb->insert(
        sbmcp_oauth_clients_table(),
        [
            'client_id'          => $client_id,
            'client_secret_hash' => sbmcp_oauth_hash($client_secret),
            'client_name'        => substr($name, 0, 191),
            'redirect_uris'      => wp_json_encode(array_values($redirect_uris)),
            'client_uri'         => substr($client_uri, 0, 255) ?: null,
            'created_at'         => sbmcp_oauth_now(),
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s']
    );

    if (!$written) {
        return null;
    }

    return ['client_id' => $client_id, 'client_secret' => $client_secret];
}

/**
 * Loads a client row by its public client_id.
 *
 * @param string $client_id
 * @return array<string, mixed>|null
 */
function sbmcp_oauth_get_client(string $client_id): ?array {
    global $wpdb;
    $table = sbmcp_oauth_clients_table();

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE client_id = %s", $client_id),
        ARRAY_A
    );
    if (!$row) {
        return null;
    }

    $uris = json_decode((string) $row['redirect_uris'], true);
    $row['redirect_uris'] = is_array($uris) ? $uris : [];

    return $row;
}

/**
 * Exact-match check of a redirect URI against what the client registered.
 *
 * Exact string equality, deliberately. No wildcards, no prefix matching, no
 * normalisation beyond what was stored: a prefix match on an attacker-chosen
 * path is the classic route to having the authorization code delivered to the
 * wrong host, and the moment any relaxation is allowed the guarantee is gone.
 *
 * @param array<string, mixed> $client
 * @param string               $redirect_uri
 * @return bool
 */
function sbmcp_oauth_redirect_uri_allowed(array $client, string $redirect_uri): bool {
    foreach ((array) $client['redirect_uris'] as $registered) {
        // hash_equals() on a non-secret, for uniform comparison timing across
        // the registered set — the list itself is not sensitive, but which entry
        // matched should not be inferable.
        if (hash_equals((string) $registered, $redirect_uri)) {
            return true;
        }
    }
    return false;
}

/**
 * Records that a client was used, for the Connected Applications list.
 *
 * @param string $client_id
 * @return void
 */
function sbmcp_oauth_touch_client(string $client_id) {
    global $wpdb;
    $wpdb->update(
        sbmcp_oauth_clients_table(),
        ['last_used_at' => sbmcp_oauth_now()],
        ['client_id' => $client_id],
        ['%s'],
        ['%s']
    );
}

// ---------------------------------------------------------------------------
// Authorization codes
// ---------------------------------------------------------------------------

/**
 * Issues an authorization code bound to a client, a user, a redirect URI, and a
 * PKCE challenge.
 *
 * Every one of those bindings is checked again at redemption. The code on its
 * own authorises nothing.
 *
 * @param string $client_id
 * @param int    $user_id
 * @param string $redirect_uri
 * @param string $code_challenge S256 challenge.
 * @param string $scope          Space-delimited.
 * @return string|null Plaintext code, or null when the insert fails.
 */
function sbmcp_oauth_issue_code(string $client_id, int $user_id, string $redirect_uri, string $code_challenge, string $scope): ?string {
    global $wpdb;

    $code = sbmcp_oauth_random(32);

    $written = $wpdb->insert(
        sbmcp_oauth_codes_table(),
        [
            'code_hash'      => sbmcp_oauth_hash($code),
            'client_id'      => $client_id,
            'user_id'        => $user_id,
            'redirect_uri'   => $redirect_uri,
            'code_challenge' => $code_challenge,
            'scope'          => $scope,
            'expires_at'     => sbmcp_oauth_from_now(SBMCP_OAUTH_CODE_TTL),
            'created_at'     => sbmcp_oauth_now(),
        ],
        ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
    );

    return $written ? $code : null;
}

/**
 * Atomically claims an authorization code, returning its row exactly once.
 *
 * Single-use is enforced by the UPDATE, not by a read-then-write. The write is
 * the gate: `SET used_at WHERE used_at IS NULL` succeeds for exactly one caller
 * no matter how many redeem the same code simultaneously, and the affected-row
 * count is what says which one that was. Checking a `used` column with a SELECT
 * and then updating it would leave a window in which two concurrent redemptions
 * both read "unused" and both get a token.
 *
 * Expiry is checked after the claim rather than inside the WHERE clause, so an
 * expired code is still consumed by the attempt. A code that has been offered
 * once should not remain redeemable because the first attempt was late.
 *
 * @param string $code Plaintext code.
 * @return array<string, mixed>|null Row when this caller claimed it, else null.
 */
function sbmcp_oauth_claim_code(string $code): ?array {
    global $wpdb;
    $table = sbmcp_oauth_codes_table();
    $hash  = sbmcp_oauth_hash($code);

    $claimed = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table} SET used_at = %s WHERE code_hash = %s AND used_at IS NULL",
            sbmcp_oauth_now(),
            $hash
        )
    );

    // 0 means the code does not exist, or another request already took it.
    if ($claimed !== 1) {
        return null;
    }

    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE code_hash = %s", $hash),
        ARRAY_A
    );
}

// ---------------------------------------------------------------------------
// Tokens
// ---------------------------------------------------------------------------

/**
 * Issues an access/refresh pair bound to a client and a WordPress user.
 *
 * @param string $client_id
 * @param int    $user_id
 * @param string $scope
 * @return array{access_token: string, refresh_token: string, expires_in: int}|null
 */
function sbmcp_oauth_issue_tokens(string $client_id, int $user_id, string $scope): ?array {
    global $wpdb;

    $access  = sbmcp_oauth_random(32);
    $refresh = sbmcp_oauth_random(32);

    $written = $wpdb->insert(
        sbmcp_oauth_tokens_table(),
        [
            'access_hash'        => sbmcp_oauth_hash($access),
            'refresh_hash'       => sbmcp_oauth_hash($refresh),
            'client_id'          => $client_id,
            'user_id'            => $user_id,
            'scope'              => $scope,
            'access_expires_at'  => sbmcp_oauth_from_now(SBMCP_OAUTH_ACCESS_TTL),
            'refresh_expires_at' => sbmcp_oauth_from_now(SBMCP_OAUTH_REFRESH_TTL),
            'created_at'         => sbmcp_oauth_now(),
        ],
        ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
    );

    if (!$written) {
        return null;
    }

    return [
        'access_token'  => $access,
        'refresh_token' => $refresh,
        'expires_in'    => SBMCP_OAUTH_ACCESS_TTL,
    ];
}

/**
 * Resolves a presented access token to its row, or null.
 *
 * Returns null for a token that is unknown, revoked, or past its expiry — the
 * caller cannot tell which, and does not need to.
 *
 * @param string $access_token
 * @return array<string, mixed>|null
 */
function sbmcp_oauth_get_access_token(string $access_token): ?array {
    global $wpdb;
    $table = sbmcp_oauth_tokens_table();

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE access_hash = %s", sbmcp_oauth_hash($access_token)),
        ARRAY_A
    );

    if (!$row) {
        return null;
    }
    // Second check, constant-time, on the row the index found.
    if (!sbmcp_oauth_verify($row['access_hash'], $access_token)) {
        return null;
    }
    if (!empty($row['revoked_at'])) {
        return null;
    }
    if (strtotime($row['access_expires_at'] . ' UTC') <= time()) {
        return null;
    }

    return $row;
}

/**
 * Resolves a presented refresh token to its row, or null.
 *
 * @param string $refresh_token
 * @return array<string, mixed>|null
 */
function sbmcp_oauth_get_refresh_token(string $refresh_token): ?array {
    global $wpdb;
    $table = sbmcp_oauth_tokens_table();

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE refresh_hash = %s", sbmcp_oauth_hash($refresh_token)),
        ARRAY_A
    );

    if (!$row) {
        return null;
    }
    if (!sbmcp_oauth_verify($row['refresh_hash'], $refresh_token)) {
        return null;
    }
    if (!empty($row['revoked_at'])) {
        return null;
    }
    if (empty($row['refresh_expires_at']) || strtotime($row['refresh_expires_at'] . ' UTC') <= time()) {
        return null;
    }

    return $row;
}

/**
 * Finds a refresh token's row regardless of its state.
 *
 * Unlike sbmcp_oauth_get_refresh_token(), a revoked or expired row is returned
 * rather than hidden, because the caller needs to tell "unknown" from "known
 * but already used". The second is reuse, and reuse is the signal that a
 * refresh token has been stolen.
 *
 * @param string $refresh_token
 * @return array<string, mixed>|null
 */
function sbmcp_oauth_find_refresh_token(string $refresh_token): ?array {
    global $wpdb;
    $table = sbmcp_oauth_tokens_table();

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE refresh_hash = %s", sbmcp_oauth_hash($refresh_token)),
        ARRAY_A
    );
    if (!$row || !sbmcp_oauth_verify($row['refresh_hash'], $refresh_token)) {
        return null;
    }
    return $row;
}

/**
 * Atomically claims a refresh token for rotation.
 *
 * The UPDATE is the gate, as with authorization codes: it succeeds for exactly
 * one caller no matter how many present the same refresh token at once. The
 * previous SELECT-then-INSERT-then-UPDATE let two concurrent rotations both
 * succeed, each walking away with a valid pair from a single token.
 *
 * @param int $id Row id.
 * @return bool True when this caller claimed it.
 */
function sbmcp_oauth_claim_refresh_token(int $id): bool {
    global $wpdb;
    $table = sbmcp_oauth_tokens_table();
    $claimed = $wpdb->query(
        $wpdb->prepare("UPDATE {$table} SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL", sbmcp_oauth_now(), $id)
    );
    return $claimed === 1;
}

/**
 * Reverses a claim when the replacement could not be written, so a database
 * hiccup during rotation does not leave the client holding no token at all.
 *
 * @param int $id Row id.
 * @return void
 */
function sbmcp_oauth_unclaim_refresh_token(int $id) {
    global $wpdb;
    $wpdb->update(sbmcp_oauth_tokens_table(), ['revoked_at' => null], ['id' => $id], ['%s'], ['%d']);
}

/**
 * Marks a token row revoked.
 *
 * @param int $id Row id.
 * @return void
 */
function sbmcp_oauth_revoke_token_row(int $id) {
    global $wpdb;
    $wpdb->update(
        sbmcp_oauth_tokens_table(),
        ['revoked_at' => sbmcp_oauth_now()],
        ['id' => $id],
        ['%s'],
        ['%d']
    );
}

/**
 * Revokes every live token a client holds for a user, or for all users when
 * $user_id is 0. Used by the Revoke button in Connected Applications.
 *
 * @param string $client_id
 * @param int    $user_id
 * @return int Rows affected.
 */
function sbmcp_oauth_revoke_client_tokens(string $client_id, int $user_id = 0): int {
    global $wpdb;
    $table = sbmcp_oauth_tokens_table();
    $now   = sbmcp_oauth_now();

    if ($user_id > 0) {
        $sql = $wpdb->prepare(
            "UPDATE {$table} SET revoked_at = %s WHERE client_id = %s AND user_id = %d AND revoked_at IS NULL",
            $now,
            $client_id,
            $user_id
        );
    } else {
        $sql = $wpdb->prepare(
            "UPDATE {$table} SET revoked_at = %s WHERE client_id = %s AND revoked_at IS NULL",
            $now,
            $client_id
        );
    }

    return (int) $wpdb->query($sql);
}

/**
 * Records that an access token was used, for the Connected Applications list.
 *
 * Throttled to once a minute per row. Without it every tool call would issue a
 * write to the tokens table purely to move a timestamp, which on a busy MCP
 * session is a write per request for information nobody reads at that
 * resolution.
 *
 * @param array<string, mixed> $token_row
 * @return bool Whether a write happened.
 */
function sbmcp_oauth_touch_token(array $token_row): bool {
    global $wpdb;

    $last = !empty($token_row['last_used_at']) ? strtotime($token_row['last_used_at'] . ' UTC') : 0;
    if ($last && (time() - $last) < MINUTE_IN_SECONDS) {
        return false;
    }

    $wpdb->update(
        sbmcp_oauth_tokens_table(),
        ['last_used_at' => sbmcp_oauth_now()],
        ['id' => (int) $token_row['id']],
        ['%s'],
        ['%d']
    );
    return true;
}

/**
 * The last database error from this file, for the admin to surface.
 *
 * Exists because the previous version of sbmcp_oauth_connected_apps() ended in
 * `is_array($rows) ? $rows : []`, which turns a failed query into "no
 * connections" — visually identical to a site that genuinely has none. For a
 * panel whose entire job is to show what has access to the site, silently
 * rendering "nothing" on failure is the worst available outcome: an
 * administrator reads it as "no application is connected" when the truth is
 * "this panel does not know".
 *
 * @param string|null $set Internal. Pass a string to record, null to read.
 * @return string Empty when the last read succeeded.
 */
function sbmcp_oauth_last_store_error(?string $set = null): string {
    static $error = '';

    if ($set !== null) {
        $error = $set;
    }

    return $error;
}

/**
 * Rows for the Connected Applications list: one per client/user pair that holds
 * at least one live token.
 *
 * Deliberately does no aggregation in SQL. The previous version grouped in the
 * query and returned nothing on a live database, and two things about that
 * shape were wrong independently of whichever one caused the empty panel:
 *
 *   - `MAX(t.scope)` is a lexicographic maximum over a string, not the scope
 *     that was actually granted. With a live 'mcp:admin' token and a live
 *     'mcp:read' token for the same client, MAX() returns 'mcp:read' because
 *     'a' < 'r'. The Access column could therefore understate or overstate what
 *     an application may do, which is precisely the fact the column exists to
 *     report. "The most recent token's scope" is what is wanted and it is not
 *     expressible as an aggregate.
 *
 *   - The GROUP BY carried four columns across two tables, two of them from the
 *     LEFT-JOINed side, and grouped queries are the ones exposed to server
 *     sql_mode differences (ONLY_FULL_GROUP_BY and friends) that vary between
 *     MySQL and MariaDB versions and between hosts. A plain SELECT behaves the
 *     same everywhere.
 *
 * Folding the rows in PHP costs one pass over a list bounded by the number of
 * live tokens, and removes both problems.
 *
 * @return array<int, array<string, mixed>>
 */
function sbmcp_oauth_connected_apps(): array {
    global $wpdb;
    $tokens  = sbmcp_oauth_tokens_table();
    $clients = sbmcp_oauth_clients_table();

    sbmcp_oauth_last_store_error('');

    // Newest first, so the first row seen for a client/user pair is the one
    // whose scope is current.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT t.client_id, t.user_id, t.scope, t.created_at, t.last_used_at,
                    c.client_name, c.client_uri
             FROM {$tokens} t
             LEFT JOIN {$clients} c ON c.client_id = t.client_id
             WHERE t.revoked_at IS NULL AND t.refresh_expires_at > %s
             ORDER BY t.created_at DESC",
            sbmcp_oauth_now()
        ),
        ARRAY_A
    );

    if (!empty($wpdb->last_error)) {
        sbmcp_oauth_last_store_error($wpdb->last_error);
        return [];
    }

    if (!is_array($rows)) {
        return [];
    }

    $apps = [];

    foreach ($rows as $row) {
        $key = $row['client_id'] . '|' . $row['user_id'];

        if (!isset($apps[$key])) {
            $apps[$key] = [
                'client_id'    => $row['client_id'],
                'user_id'      => (int) $row['user_id'],
                'client_name'  => $row['client_name'],
                'client_uri'   => $row['client_uri'],
                // First row wins: the ordering above makes it the newest token,
                // so this is the grant currently in force.
                'scope'        => $row['scope'],
                'connected_at' => $row['created_at'],
                'last_used_at' => $row['last_used_at'],
            ];
            continue;
        }

        // Oldest live token is when the application first connected.
        if ($row['created_at'] < $apps[$key]['connected_at']) {
            $apps[$key]['connected_at'] = $row['created_at'];
        }

        // Most recent use across every live token for this pair. String
        // comparison is correct here: these are UTC 'Y-m-d H:i:s', which sorts
        // lexicographically in the same order it sorts chronologically.
        if (!empty($row['last_used_at'])
            && (empty($apps[$key]['last_used_at']) || $row['last_used_at'] > $apps[$key]['last_used_at'])) {
            $apps[$key]['last_used_at'] = $row['last_used_at'];
        }
    }

    return array_values($apps);
}

// ---------------------------------------------------------------------------
// Pruning
// ---------------------------------------------------------------------------

/**
 * Deletes spent codes and dead tokens.
 *
 * Codes go once expired: they are single-use and sixty seconds long, so nothing
 * is lost. Tokens go a week after the refresh half expires, which keeps a
 * recently disconnected client visible in the log rather than having it vanish
 * from the admin the moment it lapses.
 *
 * @return void
 */
function sbmcp_oauth_prune() {
    global $wpdb;

    $codes  = sbmcp_oauth_codes_table();
    $tokens = sbmcp_oauth_tokens_table();

    $wpdb->query($wpdb->prepare("DELETE FROM {$codes} WHERE expires_at < %s", sbmcp_oauth_now()));

    $cutoff = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$tokens} WHERE (refresh_expires_at IS NOT NULL AND refresh_expires_at < %s)
                OR (revoked_at IS NOT NULL AND revoked_at < %s)",
            $cutoff,
            $cutoff
        )
    );
}
add_action('sbmcp_oauth_prune_event', 'sbmcp_oauth_prune');

/**
 * Schedules the daily prune if it is not already scheduled.
 *
 * @return void
 */
function sbmcp_oauth_schedule_prune() {
    if (!wp_next_scheduled('sbmcp_oauth_prune_event')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sbmcp_oauth_prune_event');
    }
}
add_action('plugins_loaded', 'sbmcp_oauth_schedule_prune');

/**
 * Clears the prune event, so it does not keep firing for an inactive plugin.
 *
 * @return void
 */
function sbmcp_oauth_unschedule_prune() {
    wp_clear_scheduled_hook('sbmcp_oauth_prune_event');
}
