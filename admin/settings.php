<?php
/**
 * Admin settings page for StrifeBridge MCP.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_admin_menu() {
    $hook = add_options_page(
        __('StrifeBridge MCP', 'strifebridge-mcp'),
        __('StrifeBridge MCP', 'strifebridge-mcp'),
        'manage_options',
        'strifebridge-mcp',
        'sbmcp_settings_page'
    );
    if ($hook) {
        add_action('admin_print_styles-' . $hook, 'sbmcp_enqueue_admin_assets');
    }
}
add_action('admin_menu', 'sbmcp_admin_menu');

function sbmcp_enqueue_admin_assets() {
    $version = defined('SBMCP_VERSION') ? SBMCP_VERSION : '2.0.0';
    wp_enqueue_style(
        'sbmcp-settings',
        SBMCP_URL . 'admin/css/settings.css',
        [],
        $version
    );
    wp_enqueue_script(
        'sbmcp-settings',
        SBMCP_URL . 'admin/js/settings.js',
        [],
        $version,
        true
    );
}

function sbmcp_handle_regenerate() {
    if (!isset($_POST['sbmcp_regenerate'])) return;
    check_admin_referer('sbmcp_regenerate_token');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp'));
    update_option('sbmcp_api_token', bin2hex(random_bytes(32)));
    wp_safe_redirect(admin_url('options-general.php?page=strifebridge-mcp&regenerated=1')); exit;
}
add_action('admin_init', 'sbmcp_handle_regenerate');

function sbmcp_handle_lockdown() {
    if (!isset($_POST['sbmcp_lockdown_action'])) return;
    check_admin_referer('sbmcp_lockdown');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp'));
    $disabling = sanitize_key(wp_unslash($_POST['sbmcp_lockdown_action'])) === 'disable';
    update_option('sbmcp_api_disabled', $disabling ? 1 : 0);
    wp_safe_redirect(admin_url('options-general.php?page=strifebridge-mcp&' . ($disabling ? 'api_disabled=1' : 'api_enabled=1'))); exit;
}
add_action('admin_init', 'sbmcp_handle_lockdown');

function sbmcp_handle_tool_toggles() {
    if (!isset($_POST['sbmcp_save_tools'])) return;
    check_admin_referer('sbmcp_tool_toggles');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp'));

    $groups   = array_keys(sbmcp_tool_groups());
    $enabled  = isset($_POST['sbmcp_tools']) && is_array($_POST['sbmcp_tools'])
                    ? array_map('sanitize_key', wp_unslash($_POST['sbmcp_tools']))
                    : [];
    $disabled = array_values(array_diff($groups, $enabled));
    update_option('sbmcp_disabled_tools', $disabled);
    wp_safe_redirect(admin_url('options-general.php?page=strifebridge-mcp&tools_saved=1')); exit;
}
add_action('admin_init', 'sbmcp_handle_tool_toggles');

function sbmcp_handle_safety() {
    if (!isset($_POST['sbmcp_save_safety'])) return;
    check_admin_referer('sbmcp_safety');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp'));

    $valid  = array_keys(sbmcp_safe_mode_options());
    $posted = isset($_POST['sbmcp_safe_mode']) && is_array($_POST['sbmcp_safe_mode'])
                  ? array_map('sanitize_key', wp_unslash($_POST['sbmcp_safe_mode']))
                  : [];

    $modes = [];
    foreach ($valid as $key) {
        if (in_array($key, $posted, true)) $modes[$key] = 1;
    }
    update_option('sbmcp_safe_mode', $modes);

    $author = isset($_POST['sbmcp_default_author']) ? (int) $_POST['sbmcp_default_author'] : 0;
    update_option('sbmcp_default_author', ($author > 0 && get_userdata($author)) ? $author : 0);

    // Defaults on, so an absent checkbox means the admin unticked it.
    update_option('sbmcp_log_ip', isset($_POST['sbmcp_log_ip']) ? 1 : 0);

    wp_safe_redirect(admin_url('options-general.php?page=strifebridge-mcp&safety_saved=1')); exit;
}
add_action('admin_init', 'sbmcp_handle_safety');

/**
 * Revokes every live token a connected application holds for one user.
 *
 * Revocation is immediate: the tokens are marked revoked, and
 * sbmcp_oauth_get_access_token() refuses a revoked row, so the next request the
 * application makes fails. There is no cache to wait out.
 */
function sbmcp_handle_oauth_revoke() {
    if (!isset($_POST['sbmcp_oauth_revoke'])) return;
    check_admin_referer('sbmcp_oauth_revoke');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp'));

    $client_id = sanitize_text_field(wp_unslash($_POST['sbmcp_oauth_revoke']));
    $user_id   = isset($_POST['sbmcp_oauth_revoke_user']) ? (int) $_POST['sbmcp_oauth_revoke_user'] : 0;

    $revoked = sbmcp_oauth_revoke_client_tokens($client_id, $user_id);
    sbmcp_audit_log('oauth_revoke', ['client_id' => $client_id], 'success', sprintf('Revoked %d token(s) from the admin.', $revoked));

    wp_safe_redirect(admin_url('options-general.php?page=strifebridge-mcp&revoked=1')); exit;
}
add_action('admin_init', 'sbmcp_handle_oauth_revoke');

function sbmcp_handle_dismiss_review() {
    if (!isset($_POST['sbmcp_dismiss_review'])) return;
    check_admin_referer('sbmcp_dismiss_review');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp'));
    $action = sanitize_key(wp_unslash($_POST['sbmcp_dismiss_review']));
    if ($action === 'later') {
        update_option('sbmcp_review_remind_at', time() + (7 * DAY_IN_SECONDS));
    } elseif ($action === 'never') {
        update_option('sbmcp_review_dismissed', 1);
    }
    wp_safe_redirect(admin_url('options-general.php?page=strifebridge-mcp')); exit;
}
add_action('admin_init', 'sbmcp_handle_dismiss_review');

function sbmcp_settings_page() {
    $token       = get_option('sbmcp_api_token', '');
    $mcp_url_tok = get_rest_url(null, 'strifebridge/v1/' . $token);
    $mcp_url_oauth = get_rest_url(null, 'strifebridge/v1/mcp');
    $version     = defined('SBMCP_VERSION') ? SBMCP_VERSION : '2.0.0';
    $api_disabled        = (bool) get_option('sbmcp_api_disabled');
    $disabled_tools      = get_option('sbmcp_disabled_tools', []);
    if (!is_array($disabled_tools)) $disabled_tools = [];
    $tool_groups         = sbmcp_tool_groups();
    $api_just_disabled   = isset($_GET['api_disabled']);
    $api_just_enabled    = isset($_GET['api_enabled']);
    $regenerated         = isset($_GET['regenerated']);
    $tools_saved         = isset($_GET['tools_saved']);
    $safety_saved        = isset($_GET['safety_saved']);
    $safe_modes          = sbmcp_safe_mode();
    $safety_options      = sbmcp_safe_mode_options();
    $recent_activity     = sbmcp_audit_log_query(['limit' => 10]);
    $current_author      = (int) get_option('sbmcp_default_author', 0);
    $log_ip              = sbmcp_audit_ip_logging_enabled();
    $connected_apps      = sbmcp_oauth_connected_apps();
    // Read immediately after the call: distinguishes "nothing is connected"
    // from "the list could not be read", which look identical otherwise.
    $connected_error     = sbmcp_oauth_last_store_error();
    $oauth_revoked       = isset($_GET['revoked']);
    $oauth_scopes        = sbmcp_oauth_scopes();

    // Review nag logic
    $activated_at   = get_option('sbmcp_activated_at', 0);
    if (!$activated_at) { update_option('sbmcp_activated_at', time()); $activated_at = time(); }
    $days_active    = max(1, (int) ((time() - $activated_at) / DAY_IN_SECONDS));
    $review_dismissed = get_option('sbmcp_review_dismissed', 0);
    $review_remind_at = (int) get_option('sbmcp_review_remind_at', 0);
    $show_review = !$review_dismissed && $days_active >= 7 && (!$review_remind_at || time() >= $review_remind_at);

    $copy_label  = __('Copy', 'strifebridge-mcp');
    $copied_label = __('Copied!', 'strifebridge-mcp');
    ?>
    <div class="wrap sb-wrap">

        <?php if ($show_review): ?>
        <div class="sb-review-nag">
            <p>
                <?php
                printf(
                    /* translators: %s: number of days the plugin has been active. */
                    esc_html__('StrifeBridge MCP has been running for %s days. Enjoying it? A quick review helps other WordPress users discover it.', 'strifebridge-mcp'),
                    '<strong>' . esc_html($days_active) . '</strong>'
                );
                ?>
            </p>
            <div class="sb-review-actions">
                <a href="https://wordpress.org/support/plugin/strifebridge-mcp/reviews/#new-post" target="_blank" rel="noopener" class="button button-primary sb-nowrap"><?php esc_html_e('Leave a Review', 'strifebridge-mcp'); ?></a>
                <form method="post" class="sb-form-inline"><?php wp_nonce_field('sbmcp_dismiss_review'); ?><button type="submit" name="sbmcp_dismiss_review" value="later" class="button"><?php esc_html_e('Maybe Later', 'strifebridge-mcp'); ?></button></form>
                <form method="post" class="sb-form-inline"><?php wp_nonce_field('sbmcp_dismiss_review'); ?><button type="submit" name="sbmcp_dismiss_review" value="never" class="button sb-muted"><?php esc_html_e('Never', 'strifebridge-mcp'); ?></button></form>
            </div>
        </div>
        <?php endif; ?>

        <div class="sb-header">
            <div>
                <h1><?php esc_html_e('StrifeBridge MCP', 'strifebridge-mcp'); ?></h1>
                <p><?php esc_html_e('AI bridge for WordPress — MCP server & REST API', 'strifebridge-mcp'); ?></p>
            </div>
            <div class="sb-header-right">
                <?php if ($api_disabled): ?>
                    <span class="sb-badge sb-badge-disabled"><?php esc_html_e('API Disabled', 'strifebridge-mcp'); ?></span>
                <?php else: ?>
                    <span class="sb-badge"><?php esc_html_e('Active', 'strifebridge-mcp'); ?></span>
                <?php endif; ?>
                <div class="sb-version">
                    <?php
                    /* translators: %s: plugin version number. */
                    printf(esc_html__('v%s', 'strifebridge-mcp'), esc_html($version));
                    ?>
                </div>
            </div>
        </div>

        <div class="sb-links">
            <a href="https://strifetech.com" target="_blank" rel="noopener"><?php esc_html_e('Strife Technologies', 'strifebridge-mcp'); ?></a>
            <a href="https://strifetech.com/strifebridge-mcp/#pricing" target="_blank" rel="noopener"><?php esc_html_e('Pro', 'strifebridge-mcp'); ?></a>
            <a href="https://strifetech.com/blog" target="_blank" rel="noopener"><?php esc_html_e('Blog', 'strifebridge-mcp'); ?></a>
            <a href="https://github.com/strifero/strifebridge-mcp/issues" target="_blank" rel="noopener"><?php esc_html_e('Support', 'strifebridge-mcp'); ?></a>
            <a href="https://strifetech.com/docs/strifebridge-mcp" target="_blank" rel="noopener"><?php esc_html_e('Docs', 'strifebridge-mcp'); ?></a>
        </div>

        <?php if ($api_disabled): ?><div class="notice notice-error"><p><strong><?php esc_html_e('StrifeBridge MCP API is disabled.', 'strifebridge-mcp'); ?></strong> <?php esc_html_e('Re-enable in the Danger Zone below.', 'strifebridge-mcp'); ?></p></div><?php endif; ?>
        <?php if ($api_just_disabled): ?><div class="notice notice-warning is-dismissible"><p><strong><?php esc_html_e('API disabled.', 'strifebridge-mcp'); ?></strong></p></div><?php endif; ?>
        <?php if ($api_just_enabled): ?><div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('API re-enabled.', 'strifebridge-mcp'); ?></strong></p></div><?php endif; ?>
        <?php if ($regenerated): ?><div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('Token regenerated.', 'strifebridge-mcp'); ?></strong> <?php esc_html_e('Update your connector URL in Claude.ai.', 'strifebridge-mcp'); ?></p></div><?php endif; ?>
        <?php if ($tools_saved): ?><div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('Tool settings saved.', 'strifebridge-mcp'); ?></strong></p></div><?php endif; ?>
        <?php if ($safety_saved): ?><div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('Safety settings saved.', 'strifebridge-mcp'); ?></strong></p></div><?php endif; ?>
        <?php if ($oauth_revoked): ?><div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('Application disconnected.', 'strifebridge-mcp'); ?></strong> <?php esc_html_e('Its tokens no longer work.', 'strifebridge-mcp'); ?></p></div><?php endif; ?>

        <div class="sb-layout">
            <div class="sb-main">

                <!-- Connect an AI assistant -->
                <div class="sb-card">
                    <h2><?php esc_html_e('Connect an AI assistant', 'strifebridge-mcp'); ?></h2>
                    <p><?php esc_html_e('Paste this URL into the assistant&#8217;s connector settings. ChatGPT, Claude, and Gemini will bring you back here to sign in and approve the connection, after which the assistant acts as your WordPress account and can do nothing that account cannot.', 'strifebridge-mcp'); ?></p>
                    <div class="sb-field">
                        <label for="sb-mcp-oauth-url"><?php esc_html_e('Server URL', 'strifebridge-mcp'); ?></label>
                        <div class="sb-input-row">
                            <input type="text" id="sb-mcp-oauth-url" value="<?php echo esc_attr($mcp_url_oauth); ?>" class="large-text" readonly />
                            <button type="button" class="button button-primary" data-sb-copy="sb-mcp-oauth-url" data-label="<?php echo esc_attr($copy_label); ?>" data-copied="<?php echo esc_attr($copied_label); ?>"><?php echo esc_html($copy_label); ?></button>
                        </div>
                        <p class="sb-tool-desc"><?php esc_html_e('Recommended for all new connections. There is no token to copy by hand, and you can disconnect any assistant below without disturbing the others.', 'strifebridge-mcp'); ?></p>
                    </div>
                </div>

                <!-- Connected Applications -->
                <div class="sb-card">
                    <h2><?php esc_html_e('Connected Applications', 'strifebridge-mcp'); ?></h2>
                    <p><?php esc_html_e('Assistants you have approved. Revoking takes effect on the application&#8217;s very next request.', 'strifebridge-mcp'); ?></p>

                    <?php if ($connected_error): ?>
                        <div class="notice notice-error inline">
                            <p>
                                <strong><?php esc_html_e('This list could not be read.', 'strifebridge-mcp'); ?></strong>
                                <?php esc_html_e('Applications may still be connected — do not read this as nothing having access. If you need to cut off access right now, use Emergency Lockdown in the Danger Zone below.', 'strifebridge-mcp'); ?>
                            </p>
                            <p><code><?php echo esc_html($connected_error); ?></code></p>
                        </div>
                    <?php elseif (empty($connected_apps)): ?>
                        <p class="sb-tool-desc"><?php esc_html_e('No applications are connected yet.', 'strifebridge-mcp'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat striped sb-connected-apps">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Application', 'strifebridge-mcp'); ?></th>
                                    <th><?php esc_html_e('Acting as', 'strifebridge-mcp'); ?></th>
                                    <th><?php esc_html_e('Access', 'strifebridge-mcp'); ?></th>
                                    <th><?php esc_html_e('Last used', 'strifebridge-mcp'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($connected_apps as $app): ?>
                                <?php
                                $app_user = get_userdata((int) $app['user_id']);
                                $granted  = preg_split('/\s+/', trim((string) $app['scope'])) ?: [];
                                $labels   = [];
                                foreach ($granted as $granted_scope) {
                                    if (isset($oauth_scopes[$granted_scope])) {
                                        $labels[] = $oauth_scopes[$granted_scope]['label'];
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($app['client_name'] ? $app['client_name'] : __('Unnamed application', 'strifebridge-mcp')); ?></strong>
                                        <?php if (!empty($app['client_uri'])): ?>
                                            <br /><span class="sb-tool-desc"><?php echo esc_html($app['client_uri']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($app_user): ?>
                                            <?php echo esc_html($app_user->user_login); ?>
                                        <?php else: ?>
                                            <span class="sb-tool-desc"><?php esc_html_e('deleted user', 'strifebridge-mcp'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sb-tool-desc"><?php echo esc_html($labels ? implode(', ', $labels) : (string) $app['scope']); ?></td>
                                    <td class="sb-tool-desc">
                                        <?php
                                        echo esc_html(
                                            !empty($app['last_used_at'])
                                                ? get_date_from_gmt($app['last_used_at'], 'Y-m-d H:i')
                                                : __('never', 'strifebridge-mcp')
                                        );
                                        ?>
                                    </td>
                                    <td>
                                        <form method="post" class="sb-form-inline">
                                            <?php wp_nonce_field('sbmcp_oauth_revoke'); ?>
                                            <input type="hidden" name="sbmcp_oauth_revoke_user" value="<?php echo esc_attr((int) $app['user_id']); ?>" />
                                            <button type="submit" name="sbmcp_oauth_revoke" value="<?php echo esc_attr($app['client_id']); ?>" class="button button-small"><?php esc_html_e('Revoke', 'strifebridge-mcp'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Legacy token -->
                <div class="sb-card">
                    <h2><?php esc_html_e('Legacy token', 'strifebridge-mcp'); ?> <span class="sb-badge sb-badge-legacy"><?php esc_html_e('Legacy', 'strifebridge-mcp'); ?></span></h2>
                    <p><?php esc_html_e('The original connection method, still fully supported so existing setups keep working. It carries full administrator authority and cannot be scoped down or attributed to a person, so use the OAuth connection above for anything new.', 'strifebridge-mcp'); ?></p>
                    <div class="sb-field">
                        <label for="sb-mcp-url"><?php esc_html_e('Connector URL', 'strifebridge-mcp'); ?></label>
                        <div class="sb-input-row">
                            <input type="text" id="sb-mcp-url" value="<?php echo esc_attr($mcp_url_tok); ?>" class="large-text" readonly />
                            <button type="button" class="button" data-sb-copy="sb-mcp-url" data-label="<?php echo esc_attr($copy_label); ?>" data-copied="<?php echo esc_attr($copied_label); ?>"><?php echo esc_html($copy_label); ?></button>
                        </div>
                    </div>
                    <hr class="sb-divider">
                    <div class="sb-field">
                        <label for="sb-token"><?php esc_html_e('Bearer Token', 'strifebridge-mcp'); ?> <span class="sb-tool-desc">(<?php esc_html_e('for direct API use', 'strifebridge-mcp'); ?>)</span></label>
                        <div class="sb-input-row">
                            <input type="text" id="sb-token" value="<?php echo esc_attr($token); ?>" class="regular-text" readonly />
                            <button type="button" class="button" data-sb-copy="sb-token" data-label="<?php echo esc_attr($copy_label); ?>" data-copied="<?php echo esc_attr($copied_label); ?>"><?php echo esc_html($copy_label); ?></button>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="sb-card">
                    <h2><?php esc_html_e('Recent Activity', 'strifebridge-mcp'); ?></h2>
                    <p><?php esc_html_e('Every tool call is recorded — what ran, whether it worked, and where it came from. Refused calls and failed sign-in attempts appear here too.', 'strifebridge-mcp'); ?></p>

                    <?php if (empty($recent_activity)): ?>
                        <p class="sb-tool-desc"><?php esc_html_e('No activity recorded yet. Once your AI assistant connects and starts working, its actions will show up here.', 'strifebridge-mcp'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat striped sb-activity">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Time', 'strifebridge-mcp'); ?></th>
                                    <th><?php esc_html_e('Tool', 'strifebridge-mcp'); ?></th>
                                    <th><?php esc_html_e('Details', 'strifebridge-mcp'); ?></th>
                                    <th><?php esc_html_e('Result', 'strifebridge-mcp'); ?></th>
                                    <th><?php esc_html_e('Who', 'strifebridge-mcp'); ?></th>
                                    <th><?php esc_html_e('IP', 'strifebridge-mcp'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent_activity as $row): ?>
                                <?php
                                $who_user = !empty($row['user_id']) ? get_userdata((int) $row['user_id']) : null;
                                if ($who_user) {
                                    $who = $who_user->user_login;
                                } elseif (!empty($row['user_id'])) {
                                    $who = __('deleted user', 'strifebridge-mcp');
                                } elseif ($row['tool'] === 'auth' || $row['tool'] === 'rest') {
                                    $who = '';
                                } else {
                                    $who = __('legacy token', 'strifebridge-mcp');
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html(get_date_from_gmt($row['ts'], 'Y-m-d H:i')); ?></td>
                                    <td><code><?php echo esc_html($row['tool']); ?></code></td>
                                    <td class="sb-tool-desc"><?php echo esc_html($row['args_summary'] ?? ''); ?></td>
                                    <td>
                                        <span class="sb-result sb-result-<?php echo esc_attr($row['result']); ?>"><?php echo esc_html($row['result']); ?></span>
                                        <?php if (!empty($row['error_msg'])): ?>
                                            <div class="sb-tool-desc"><?php echo esc_html($row['error_msg']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="sb-tool-desc"><?php echo esc_html($who); ?></td>
                                    <td class="sb-tool-desc"><?php echo esc_html($row['ip'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <p class="sb-tool-desc sb-activity-upsell">
                        <?php esc_html_e('Showing the last 10 actions. StrifeBridge MCP Pro keeps full searchable history with CSV export.', 'strifebridge-mcp'); ?>
                        <a href="https://strifetech.com/strifebridge-mcp/#pricing" target="_blank" rel="noopener"><?php esc_html_e('Learn more →', 'strifebridge-mcp'); ?></a>
                    </p>
                </div>

                <!-- Tool Settings -->
                <div class="sb-card">
                    <h2><?php esc_html_e('Tool Settings', 'strifebridge-mcp'); ?></h2>
                    <p><?php esc_html_e('Enable or disable individual tool groups. Disabled tools are removed from the MCP server and REST API entirely — Claude will not be able to see or call them.', 'strifebridge-mcp'); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('sbmcp_tool_toggles'); ?>
                        <div class="sb-tools-grid">
                            <?php foreach ($tool_groups as $slug => $group):
                                $checked = !in_array($slug, $disabled_tools, true);
                            ?>
                            <label class="sb-tool-item">
                                <input type="checkbox"
                                       name="sbmcp_tools[]"
                                       value="<?php echo esc_attr($slug); ?>"
                                       <?php checked($checked); ?>>
                                <div>
                                    <div class="sb-tool-label"><?php echo esc_html($group['label']); ?></div>
                                    <div class="sb-tool-desc"><?php echo esc_html($group['description']); ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" name="sbmcp_save_tools" class="button button-primary"><?php esc_html_e('Save Tool Settings', 'strifebridge-mcp'); ?></button>
                    </form>
                </div>

                <!-- Safety -->
                <div class="sb-card">
                    <h2><?php esc_html_e('Safety', 'strifebridge-mcp'); ?></h2>
                    <p><?php esc_html_e('Guardrails on what the AI is allowed to do. These apply to every connected assistant, on top of the tool group settings above.', 'strifebridge-mcp'); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('sbmcp_safety'); ?>
                        <div class="sb-tools-grid">
                            <?php foreach ($safety_options as $slug => $option): ?>
                            <label class="sb-tool-item">
                                <input type="checkbox"
                                       name="sbmcp_safe_mode[]"
                                       value="<?php echo esc_attr($slug); ?>"
                                       <?php checked(!empty($safe_modes[$slug])); ?>>
                                <div>
                                    <div class="sb-tool-label"><?php echo esc_html($option['label']); ?></div>
                                    <div class="sb-tool-desc"><?php echo esc_html($option['description']); ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <hr class="sb-divider">
                        <div class="sb-tools-grid">
                            <label class="sb-tool-item">
                                <input type="checkbox" name="sbmcp_log_ip" value="1" <?php checked($log_ip); ?>>
                                <div>
                                    <div class="sb-tool-label"><?php esc_html_e('Log IP addresses', 'strifebridge-mcp'); ?></div>
                                    <div class="sb-tool-desc"><?php esc_html_e('Record the IP address each request came from in the activity log. Turn this off if you would rather not store it; everything else about the log is unchanged, and existing entries keep the addresses already recorded.', 'strifebridge-mcp'); ?></div>
                                </div>
                            </label>
                        </div>

                        <hr class="sb-divider">
                        <div class="sb-field">
                            <label for="sb-default-author"><?php esc_html_e('Author for AI-created posts', 'strifebridge-mcp'); ?></label>
                            <div class="sb-tool-desc sb-mb-20"><?php esc_html_e('Requests authenticated with the token have no logged-in user, so new posts need an author to be attributed to. Defaults to the oldest administrator.', 'strifebridge-mcp'); ?></div>
                            <?php
                            wp_dropdown_users([
                                'name'             => 'sbmcp_default_author',
                                'id'               => 'sb-default-author',
                                'selected'         => $current_author,
                                'include_selected' => true,
                                'show_option_none' => __('Default (oldest administrator)', 'strifebridge-mcp'),
                                'option_none_value' => 0,
                                // role__in rather than the newer 'capability' arg:
                                // 'capability' only landed in WP 5.9 and this plugin
                                // supports 5.6.
                                'role__in'         => ['administrator', 'editor', 'author'],
                            ]);
                            ?>
                        </div>

                        <button type="submit" name="sbmcp_save_safety" class="button button-primary"><?php esc_html_e('Save Safety Settings', 'strifebridge-mcp'); ?></button>
                    </form>
                </div>

                <!-- Danger Zone -->
                <div class="sb-card sb-danger">
                    <h2><?php esc_html_e('Danger Zone', 'strifebridge-mcp'); ?></h2>
                    <p><strong><?php esc_html_e('Emergency Lockdown', 'strifebridge-mcp'); ?></strong> — <?php esc_html_e('instantly disables all API and MCP access without changing the token.', 'strifebridge-mcp'); ?></p>
                    <form method="post" class="sb-mb-20" data-sb-confirm="<?php echo esc_attr($api_disabled ? __('Re-enable the StrifeBridge MCP API?', 'strifebridge-mcp') : __('Disable the entire StrifeBridge MCP API?', 'strifebridge-mcp')); ?>">
                        <?php wp_nonce_field('sbmcp_lockdown'); ?>
                        <?php if ($api_disabled): ?>
                            <input type="hidden" name="sbmcp_lockdown_action" value="enable" />
                            <button type="submit" class="button button-primary"><?php esc_html_e('Re-enable API', 'strifebridge-mcp'); ?></button>
                        <?php else: ?>
                            <input type="hidden" name="sbmcp_lockdown_action" value="disable" />
                            <button type="submit" class="button button-secondary sb-danger-btn"><?php esc_html_e('Disable API', 'strifebridge-mcp'); ?></button>
                        <?php endif; ?>
                    </form>
                    <hr class="sb-divider">
                    <p><?php esc_html_e('Regenerating the token will invalidate your current connector URL.', 'strifebridge-mcp'); ?></p>
                    <form method="post" data-sb-confirm="<?php echo esc_attr__('Regenerate token?', 'strifebridge-mcp'); ?>">
                        <?php wp_nonce_field('sbmcp_regenerate_token'); ?>
                        <button type="submit" name="sbmcp_regenerate" class="button button-secondary sb-danger-btn"><?php esc_html_e('Regenerate Token', 'strifebridge-mcp'); ?></button>
                    </form>
                </div>

            </div>

            <!-- Sidebar -->
            <div class="sb-sidebar">
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Take StrifeBridge to the Next Level', 'strifebridge-mcp'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('Theme file editing', 'strifebridge-mcp'); ?></li>
                        <li><?php esc_html_e('Plugin file editing', 'strifebridge-mcp'); ?></li>
                        <li><?php esc_html_e('Database access', 'strifebridge-mcp'); ?></li>
                        <li><?php esc_html_e('User management', 'strifebridge-mcp'); ?></li>
                        <li><?php esc_html_e('Error log & cron', 'strifebridge-mcp'); ?></li>
                        <li><?php esc_html_e('Priority support', 'strifebridge-mcp'); ?></li>
                    </ul>
                    <br>
                    <a href="https://strifetech.com/strifebridge-mcp/#pricing" target="_blank" rel="noopener" class="sb-promo-btn"><?php esc_html_e('Get StrifeBridge MCP Pro', 'strifebridge-mcp'); ?></a>
                </div>
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Community', 'strifebridge-mcp'); ?></h3>
                    <p><a href="https://github.com/strifero/strifebridge-mcp/discussions" target="_blank" rel="noopener"><?php esc_html_e('GitHub Discussions', 'strifebridge-mcp'); ?></a></p>
                </div>
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Getting Started', 'strifebridge-mcp'); ?></h3>
                    <p><a href="https://strifetech.com/docs/strifebridge-mcp" target="_blank" rel="noopener"><?php esc_html_e('Read the docs', 'strifebridge-mcp'); ?></a></p>
                </div>
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Support', 'strifebridge-mcp'); ?></h3>
                    <p><a href="https://github.com/strifero/strifebridge-mcp/issues" target="_blank" rel="noopener"><?php esc_html_e('Report an issue', 'strifebridge-mcp'); ?></a></p>
                </div>
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Submit a Review', 'strifebridge-mcp'); ?></h3>
                    <p><a href="https://wordpress.org/support/plugin/strifebridge-mcp/reviews/#new-post" target="_blank" rel="noopener"><?php esc_html_e('Leave a review on wp.org', 'strifebridge-mcp'); ?></a></p>
                </div>
            </div>
        </div>

        <?php
        // Extension point: let add-ons render additional admin tabs/sections.
        do_action('sbmcp_admin_after_settings');
        ?>
    </div>
    <?php
}
