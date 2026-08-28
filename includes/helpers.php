<?php
/**
 * Shared request-parameter helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads a boolean parameter from a request without the PHP truthiness trap.
 *
 * A plain (bool) cast is wrong for anything that arrives over the wire. Query
 * strings and form bodies carry every value as a string, and PHP evaluates the
 * string "false" as true — so `force=false` on a DELETE cast with (bool) meant
 * "permanently delete" and destroyed the post the caller asked to trash. JSON
 * bodies deliver a real boolean, so the same handler behaved correctly over MCP
 * and destructively over REST, which is why this went unnoticed.
 *
 * Treats "false", "0", "" and 0 as false, matching rest_sanitize_boolean().
 * Every handler reading a boolean parameter must route through this.
 *
 * @param WP_REST_Request $request Request to read from.
 * @param string          $key     Parameter name.
 * @param bool            $default Value when the parameter is absent or null.
 * @return bool
 */
function sbmcp_param_bool(WP_REST_Request $request, string $key, bool $default = false): bool {
    $value = $request->get_param($key);
    if ($value === null) {
        return $default;
    }
    return sbmcp_to_bool($value, $default);
}

/**
 * Coerces an already-extracted value to a boolean using the same rules as
 * sbmcp_param_bool(). Use when the value came from get_json_params() rather
 * than straight off the request.
 *
 * @param mixed $value   Raw value.
 * @param bool  $default Value when $value is null.
 * @return bool
 */
function sbmcp_to_bool($value, bool $default = false): bool {
    if ($value === null) {
        return $default;
    }
    if (is_string($value)) {
        $value = strtolower(trim($value));
        if (in_array($value, ['false', '0', ''], true)) {
            return false;
        }
        return true;
    }
    return (bool) $value;
}
