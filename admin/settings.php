<?php
/**
 * Admin settings page for StrifeBridge MCP.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_admin_menu() {
    $hook = add_options_page(
        __('StrifeBridge MCP', 'strifebridge-mcp-for-wordpress'),
        __('StrifeBridge MCP', 'strifebridge-mcp-for-wordpress'),
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
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp-for-wordpress'));
    update_option('sbmcp_api_token', bin2hex(random_bytes(32)));
    wp_safe_redirect(admin_url('options-general.php?page=strifebridge-mcp&regenerated=1')); exit;
}
add_action('admin_init', 'sbmcp_handle_regenerate');

function sbmcp_handle_lockdown() {
    if (!isset($_POST['sbmcp_lockdown_action'])) return;
    check_admin_referer('sbmcp_lockdown');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp-for-wordpress'));
    $disabling = $_POST['sbmcp_lockdown_action'] === 'disable';
    update_option('sbmcp_api_disabled', $disabling ? 1 : 0);
    wp_safe_redirect(admin_url('options-general.php?page=strifebridge-mcp&' . ($disabling ? 'api_disabled=1' : 'api_enabled=1'))); exit;
}
add_action('admin_init', 'sbmcp_handle_lockdown');

function sbmcp_handle_tool_toggles() {
    if (!isset($_POST['sbmcp_save_tools'])) return;
    check_admin_referer('sbmcp_tool_toggles');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp-for-wordpress'));

    $groups   = array_keys(sbmcp_tool_groups());
    $enabled  = isset($_POST['sbmcp_tools']) && is_array($_POST['sbmcp_tools'])
                    ? array_map('sanitize_key', $_POST['sbmcp_tools'])
                    : [];
    $disabled = array_values(array_diff($groups, $enabled));
    update_option('sbmcp_disabled_tools', $disabled);
    wp_safe_redirect(admin_url('options-general.php?page=strifebridge-mcp&tools_saved=1')); exit;
}
add_action('admin_init', 'sbmcp_handle_tool_toggles');

function sbmcp_handle_dismiss_review() {
    if (!isset($_POST['sbmcp_dismiss_review'])) return;
    check_admin_referer('sbmcp_dismiss_review');
    if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized', 'strifebridge-mcp-for-wordpress'));
    $action = sanitize_key($_POST['sbmcp_dismiss_review']);
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
    $version     = defined('SBMCP_VERSION') ? SBMCP_VERSION : '2.0.0';
    $api_disabled        = (bool) get_option('sbmcp_api_disabled');
    $disabled_tools      = get_option('sbmcp_disabled_tools', []);
    if (!is_array($disabled_tools)) $disabled_tools = [];
    $tool_groups         = sbmcp_tool_groups();
    $api_just_disabled   = isset($_GET['api_disabled']);
    $api_just_enabled    = isset($_GET['api_enabled']);
    $regenerated         = isset($_GET['regenerated']);
    $tools_saved         = isset($_GET['tools_saved']);

    // Review nag logic
    $activated_at   = get_option('sbmcp_activated_at', 0);
    if (!$activated_at) { update_option('sbmcp_activated_at', time()); $activated_at = time(); }
    $days_active    = max(1, (int) ((time() - $activated_at) / DAY_IN_SECONDS));
    $review_dismissed = get_option('sbmcp_review_dismissed', 0);
    $review_remind_at = (int) get_option('sbmcp_review_remind_at', 0);
    $show_review = !$review_dismissed && $days_active >= 7 && (!$review_remind_at || time() >= $review_remind_at);

    $copy_label  = __('Copy', 'strifebridge-mcp-for-wordpress');
    $copied_label = __('Copied!', 'strifebridge-mcp-for-wordpress');
    ?>
    <div class="wrap sb-wrap">

        <?php if ($show_review): ?>
        <div class="sb-review-nag">
            <p>
                <?php
                printf(
                    /* translators: %s: number of days the plugin has been active. */
                    esc_html__('StrifeBridge MCP has been running for %s days. Enjoying it? A quick review helps other WordPress users discover it.', 'strifebridge-mcp-for-wordpress'),
                    '<strong>' . esc_html($days_active) . '</strong>'
                );
                ?>
            </p>
            <div class="sb-review-actions">
                <a href="https://wordpress.org/support/plugin/strifebridge-mcp-for-wordpress/reviews/#new-post" target="_blank" rel="noopener" class="button button-primary sb-nowrap"><?php esc_html_e('Leave a Review', 'strifebridge-mcp-for-wordpress'); ?></a>
                <form method="post" class="sb-form-inline"><?php wp_nonce_field('sbmcp_dismiss_review'); ?><button type="submit" name="sbmcp_dismiss_review" value="later" class="button"><?php esc_html_e('Maybe Later', 'strifebridge-mcp-for-wordpress'); ?></button></form>
                <form method="post" class="sb-form-inline"><?php wp_nonce_field('sbmcp_dismiss_review'); ?><button type="submit" name="sbmcp_dismiss_review" value="never" class="button sb-muted"><?php esc_html_e('Never', 'strifebridge-mcp-for-wordpress'); ?></button></form>
            </div>
        </div>
        <?php endif; ?>

        <div class="sb-header">
            <div>
                <h1><?php esc_html_e('StrifeBridge MCP', 'strifebridge-mcp-for-wordpress'); ?></h1>
                <p><?php esc_html_e('AI bridge for WordPress — MCP server & REST API', 'strifebridge-mcp-for-wordpress'); ?></p>
            </div>
            <div class="sb-header-right">
                <?php if ($api_disabled): ?>
                    <span class="sb-badge sb-badge-disabled"><?php esc_html_e('API Disabled', 'strifebridge-mcp-for-wordpress'); ?></span>
                <?php else: ?>
                    <span class="sb-badge"><?php esc_html_e('Active', 'strifebridge-mcp-for-wordpress'); ?></span>
                <?php endif; ?>
                <div class="sb-version">
                    <?php
                    /* translators: %s: plugin version number. */
                    printf(esc_html__('v%s', 'strifebridge-mcp-for-wordpress'), esc_html($version));
                    ?>
                </div>
            </div>
        </div>

        <div class="sb-links">
            <a href="https://strifetech.com" target="_blank" rel="noopener"><?php esc_html_e('Strife Technologies', 'strifebridge-mcp-for-wordpress'); ?></a>
            <a href="https://strifetech.com/strifebridge-mcp/#pricing" target="_blank" rel="noopener"><?php esc_html_e('Pro', 'strifebridge-mcp-for-wordpress'); ?></a>
            <a href="https://strifetech.com/blog" target="_blank" rel="noopener"><?php esc_html_e('Blog', 'strifebridge-mcp-for-wordpress'); ?></a>
            <a href="https://github.com/strifero/strifebridge-mcp/issues" target="_blank" rel="noopener"><?php esc_html_e('Support', 'strifebridge-mcp-for-wordpress'); ?></a>
            <a href="https://strifetech.com/docs/strifebridge-mcp" target="_blank" rel="noopener"><?php esc_html_e('Docs', 'strifebridge-mcp-for-wordpress'); ?></a>
        </div>

        <?php if ($api_disabled): ?><div class="notice notice-error"><p><strong><?php esc_html_e('StrifeBridge MCP API is disabled.', 'strifebridge-mcp-for-wordpress'); ?></strong> <?php esc_html_e('Re-enable in the Danger Zone below.', 'strifebridge-mcp-for-wordpress'); ?></p></div><?php endif; ?>
        <?php if ($api_just_disabled): ?><div class="notice notice-warning is-dismissible"><p><strong><?php esc_html_e('API disabled.', 'strifebridge-mcp-for-wordpress'); ?></strong></p></div><?php endif; ?>
        <?php if ($api_just_enabled): ?><div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('API re-enabled.', 'strifebridge-mcp-for-wordpress'); ?></strong></p></div><?php endif; ?>
        <?php if ($regenerated): ?><div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('Token regenerated.', 'strifebridge-mcp-for-wordpress'); ?></strong> <?php esc_html_e('Update your connector URL in Claude.ai.', 'strifebridge-mcp-for-wordpress'); ?></p></div><?php endif; ?>
        <?php if ($tools_saved): ?><div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('Tool settings saved.', 'strifebridge-mcp-for-wordpress'); ?></strong></p></div><?php endif; ?>

        <div class="sb-layout">
            <div class="sb-main">

                <!-- Claude.ai Connector -->
                <div class="sb-card">
                    <h2><?php esc_html_e('Claude.ai Connector', 'strifebridge-mcp-for-wordpress'); ?></h2>
                    <p><?php esc_html_e('Copy the URL below and paste it into Claude.ai → Settings → Integrations → Add custom connector.', 'strifebridge-mcp-for-wordpress'); ?></p>
                    <div class="sb-field">
                        <label for="sb-mcp-url"><?php esc_html_e('Connector URL', 'strifebridge-mcp-for-wordpress'); ?></label>
                        <div class="sb-input-row">
                            <input type="text" id="sb-mcp-url" value="<?php echo esc_attr($mcp_url_tok); ?>" class="large-text" readonly />
                            <button type="button" class="button button-primary" data-sb-copy="sb-mcp-url" data-label="<?php echo esc_attr($copy_label); ?>" data-copied="<?php echo esc_attr($copied_label); ?>"><?php echo esc_html($copy_label); ?></button>
                        </div>
                    </div>
                    <hr class="sb-divider">
                    <div class="sb-field">
                        <label for="sb-token"><?php esc_html_e('Bearer Token', 'strifebridge-mcp-for-wordpress'); ?> <span class="sb-tool-desc">(<?php esc_html_e('for direct API use', 'strifebridge-mcp-for-wordpress'); ?>)</span></label>
                        <div class="sb-input-row">
                            <input type="text" id="sb-token" value="<?php echo esc_attr($token); ?>" class="regular-text" readonly />
                            <button type="button" class="button" data-sb-copy="sb-token" data-label="<?php echo esc_attr($copy_label); ?>" data-copied="<?php echo esc_attr($copied_label); ?>"><?php echo esc_html($copy_label); ?></button>
                        </div>
                    </div>
                </div>

                <!-- Tool Settings -->
                <div class="sb-card">
                    <h2><?php esc_html_e('Tool Settings', 'strifebridge-mcp-for-wordpress'); ?></h2>
                    <p><?php esc_html_e('Enable or disable individual tool groups. Disabled tools are removed from the MCP server and REST API entirely — Claude will not be able to see or call them.', 'strifebridge-mcp-for-wordpress'); ?></p>
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
                        <button type="submit" name="sbmcp_save_tools" class="button button-primary"><?php esc_html_e('Save Tool Settings', 'strifebridge-mcp-for-wordpress'); ?></button>
                    </form>
                </div>

                <!-- Danger Zone -->
                <div class="sb-card sb-danger">
                    <h2><?php esc_html_e('Danger Zone', 'strifebridge-mcp-for-wordpress'); ?></h2>
                    <p><strong><?php esc_html_e('Emergency Lockdown', 'strifebridge-mcp-for-wordpress'); ?></strong> — <?php esc_html_e('instantly disables all API and MCP access without changing the token.', 'strifebridge-mcp-for-wordpress'); ?></p>
                    <form method="post" class="sb-mb-20" data-sb-confirm="<?php echo esc_attr($api_disabled ? __('Re-enable the StrifeBridge MCP API?', 'strifebridge-mcp-for-wordpress') : __('Disable the entire StrifeBridge MCP API?', 'strifebridge-mcp-for-wordpress')); ?>">
                        <?php wp_nonce_field('sbmcp_lockdown'); ?>
                        <?php if ($api_disabled): ?>
                            <input type="hidden" name="sbmcp_lockdown_action" value="enable" />
                            <button type="submit" class="button button-primary"><?php esc_html_e('Re-enable API', 'strifebridge-mcp-for-wordpress'); ?></button>
                        <?php else: ?>
                            <input type="hidden" name="sbmcp_lockdown_action" value="disable" />
                            <button type="submit" class="button button-secondary sb-danger-btn"><?php esc_html_e('Disable API', 'strifebridge-mcp-for-wordpress'); ?></button>
                        <?php endif; ?>
                    </form>
                    <hr class="sb-divider">
                    <p><?php esc_html_e('Regenerating the token will invalidate your current connector URL.', 'strifebridge-mcp-for-wordpress'); ?></p>
                    <form method="post" data-sb-confirm="<?php echo esc_attr__('Regenerate token?', 'strifebridge-mcp-for-wordpress'); ?>">
                        <?php wp_nonce_field('sbmcp_regenerate_token'); ?>
                        <button type="submit" name="sbmcp_regenerate" class="button button-secondary sb-danger-btn"><?php esc_html_e('Regenerate Token', 'strifebridge-mcp-for-wordpress'); ?></button>
                    </form>
                </div>

            </div>

            <!-- Sidebar -->
            <div class="sb-sidebar">
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Take StrifeBridge to the Next Level', 'strifebridge-mcp-for-wordpress'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('Theme file editing', 'strifebridge-mcp-for-wordpress'); ?></li>
                        <li><?php esc_html_e('Plugin file editing', 'strifebridge-mcp-for-wordpress'); ?></li>
                        <li><?php esc_html_e('Database access', 'strifebridge-mcp-for-wordpress'); ?></li>
                        <li><?php esc_html_e('User management', 'strifebridge-mcp-for-wordpress'); ?></li>
                        <li><?php esc_html_e('Error log & cron', 'strifebridge-mcp-for-wordpress'); ?></li>
                        <li><?php esc_html_e('Priority support', 'strifebridge-mcp-for-wordpress'); ?></li>
                    </ul>
                    <br>
                    <a href="https://strifetech.com/strifebridge-mcp/#pricing" target="_blank" rel="noopener" class="sb-promo-btn"><?php esc_html_e('Get StrifeBridge MCP Pro', 'strifebridge-mcp-for-wordpress'); ?></a>
                </div>
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Community', 'strifebridge-mcp-for-wordpress'); ?></h3>
                    <p><a href="https://github.com/strifero/strifebridge-mcp/discussions" target="_blank" rel="noopener"><?php esc_html_e('GitHub Discussions', 'strifebridge-mcp-for-wordpress'); ?></a></p>
                </div>
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Getting Started', 'strifebridge-mcp-for-wordpress'); ?></h3>
                    <p><a href="https://strifetech.com/docs/strifebridge-mcp" target="_blank" rel="noopener"><?php esc_html_e('Read the docs', 'strifebridge-mcp-for-wordpress'); ?></a></p>
                </div>
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Support', 'strifebridge-mcp-for-wordpress'); ?></h3>
                    <p><a href="https://github.com/strifero/strifebridge-mcp/issues" target="_blank" rel="noopener"><?php esc_html_e('Report an issue', 'strifebridge-mcp-for-wordpress'); ?></a></p>
                </div>
                <div class="sb-sidebar-card">
                    <h3><?php esc_html_e('Submit a Review', 'strifebridge-mcp-for-wordpress'); ?></h3>
                    <p><a href="https://wordpress.org/support/plugin/strifebridge-mcp-for-wordpress/reviews/#new-post" target="_blank" rel="noopener"><?php esc_html_e('Leave a review on wp.org', 'strifebridge-mcp-for-wordpress'); ?></a></p>
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
