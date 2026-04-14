<?php
/**
 * Admin settings page for StrifeBridge MCP.
 */

if (!defined('ABSPATH')) {
    exit;
}

function sbmcp_admin_menu() {
    add_options_page(
        'StrifeBridge MCP',
        'StrifeBridge MCP',
        'manage_options',
        'strifebridge-mcp',
        'sbmcp_settings_page'
    );
}
add_action('admin_menu', 'sbmcp_admin_menu');

function sbmcp_handle_regenerate() {
    if (!isset($_POST['sbmcp_regenerate'])) return;
    check_admin_referer('sbmcp_regenerate_token');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    update_option('sbmcp_api_token', bin2hex(random_bytes(32)));
    wp_redirect(admin_url('options-general.php?page=strifebridge-mcp&regenerated=1')); exit;
}
add_action('admin_init', 'sbmcp_handle_regenerate');

function sbmcp_handle_lockdown() {
    if (!isset($_POST['sbmcp_lockdown_action'])) return;
    check_admin_referer('sbmcp_lockdown');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $disabling = $_POST['sbmcp_lockdown_action'] === 'disable';
    update_option('sbmcp_api_disabled', $disabling ? 1 : 0);
    wp_redirect(admin_url('options-general.php?page=strifebridge-mcp&' . ($disabling ? 'api_disabled=1' : 'api_enabled=1'))); exit;
}
add_action('admin_init', 'sbmcp_handle_lockdown');

function sbmcp_handle_tool_toggles() {
    if (!isset($_POST['sbmcp_save_tools'])) return;
    check_admin_referer('sbmcp_tool_toggles');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $groups   = array_keys(sbmcp_tool_groups());
    $enabled  = isset($_POST['sbmcp_tools']) && is_array($_POST['sbmcp_tools'])
                    ? array_map('sanitize_key', $_POST['sbmcp_tools'])
                    : [];
    $disabled = array_values(array_diff($groups, $enabled));
    update_option('sbmcp_disabled_tools', $disabled);
    wp_redirect(admin_url('options-general.php?page=strifebridge-mcp&tools_saved=1')); exit;
}
add_action('admin_init', 'sbmcp_handle_tool_toggles');

function sbmcp_handle_dismiss_review() {
    if (!isset($_POST['sbmcp_dismiss_review'])) return;
    check_admin_referer('sbmcp_dismiss_review');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $action = sanitize_key($_POST['sbmcp_dismiss_review']);
    if ($action === 'later') {
        update_option('sbmcp_review_remind_at', time() + (7 * DAY_IN_SECONDS));
    } elseif ($action === 'never') {
        update_option('sbmcp_review_dismissed', 1);
    }
    wp_redirect(admin_url('options-general.php?page=strifebridge-mcp')); exit;
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
    ?>
    <style>
        .sb-wrap{max-width:960px}.sb-header{background:#1a1a2e;color:#fff;padding:20px 28px;border-radius:4px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between}.sb-header h1{color:#fff;margin:0;padding:0;font-size:22px;line-height:1;border:none}.sb-header p{color:#a0a8c0;margin:4px 0 0;font-size:13px}.sb-badge{background:#22c55e;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;letter-spacing:.5px;text-transform:uppercase}
        .sb-links{display:flex;gap:14px;margin-bottom:20px;font-size:13px}.sb-links a{text-decoration:none;color:#3D9FD5}
        .sb-layout{display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start}
        .sb-card{background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:24px 28px;margin-bottom:20px}.sb-card h2{margin-top:0;font-size:15px;color:#1a1a2e}.sb-card p{color:#555;margin-bottom:16px}.sb-field{margin-bottom:16px}.sb-field label{display:block;font-weight:600;font-size:13px;margin-bottom:6px;color:#333}.sb-field .sb-input-row{display:flex;gap:8px;align-items:center}.sb-field input[type=text]{font-family:monospace;font-size:13px;flex:1}.sb-field .description{margin-top:6px;color:#777;font-size:12px}.sb-divider{border:none;border-top:1px solid #eee;margin:20px 0}.sb-danger{border-top:3px solid #ef4444}.sb-danger h2{color:#ef4444}
        .sb-tools-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px}
        .sb-tool-item{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:4px;background:#fafafa;cursor:pointer;transition:border-color .15s}
        .sb-tool-item:hover{border-color:#3D9FD5}
        .sb-tool-item input[type=checkbox]{margin-top:2px;flex-shrink:0}
        .sb-tool-item .sb-tool-label{font-weight:600;font-size:13px;color:#1a1a2e;line-height:1.3}
        .sb-tool-item .sb-tool-desc{font-size:12px;color:#6b7280;margin-top:2px}
        .sb-sidebar-card{background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:20px;margin-bottom:16px}
        .sb-sidebar-card h3{margin:0 0 10px;font-size:14px;color:#1a1a2e}
        .sb-sidebar-card p{color:#555;font-size:13px;margin:0 0 12px}
        .sb-sidebar-card ul{margin:0;padding:0 0 0 16px;font-size:13px;color:#555}
        .sb-sidebar-card ul li{margin-bottom:4px}
        .sb-promo-btn{display:inline-block;background:#3D9FD5;color:#fff;padding:8px 20px;border-radius:4px;text-decoration:none;font-weight:600;font-size:13px;text-align:center;width:100%;box-sizing:border-box}
        .sb-promo-btn:hover{background:#2d8bc2;color:#fff}
        .sb-review-nag{background:#f0f9ff;border:1px solid #bae6fd;border-radius:4px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:16px}
        .sb-review-nag p{margin:0;color:#1a1a2e;font-size:13px}
        .sb-review-actions{display:flex;gap:8px;flex-shrink:0}
        @media(max-width:800px){.sb-layout{grid-template-columns:1fr}.sb-tools-grid{grid-template-columns:1fr}}
    </style>
    <div class="wrap sb-wrap">

        <?php if ($show_review): ?>
        <div class="sb-review-nag">
            <p>StrifeBridge MCP has been running for <strong><?php echo esc_html($days_active); ?> days</strong>. Enjoying it? A quick review helps other WordPress users discover it.</p>
            <div class="sb-review-actions">
                <a href="https://wordpress.org/support/plugin/strifebridge-mcp-for-wordpress/reviews/#new-post" target="_blank" class="button button-primary" style="white-space:nowrap;">Leave a Review</a>
                <form method="post" style="margin:0;"><?php wp_nonce_field('sbmcp_dismiss_review'); ?><button type="submit" name="sbmcp_dismiss_review" value="later" class="button">Maybe Later</button></form>
                <form method="post" style="margin:0;"><?php wp_nonce_field('sbmcp_dismiss_review'); ?><button type="submit" name="sbmcp_dismiss_review" value="never" class="button" style="color:#9ca3af;">Never</button></form>
            </div>
        </div>
        <?php endif; ?>

        <div class="sb-header">
            <div>
                <h1>StrifeBridge MCP</h1>
                <p>AI bridge for WordPress &mdash; MCP server &amp; REST API</p>
            </div>
            <div style="text-align:right;">
                <?php if ($api_disabled): ?><span class="sb-badge" style="background:#ef4444;">API Disabled</span><?php else: ?><span class="sb-badge">Active</span><?php endif; ?>
                <div style="color:#a0a8c0;font-size:11px;margin-top:6px;">v<?php echo esc_html($version); ?></div>
            </div>
        </div>

        <div class="sb-links">
            <a href="https://strifetech.com" target="_blank">Strife Technologies</a>
            <a href="https://strifetech.com/strifebridge-mcp-pro" target="_blank">Pro</a>
            <a href="https://strifetech.com/blog" target="_blank">Blog</a>
            <a href="https://github.com/strifero/strifebridge-mcp/issues" target="_blank">Support</a>
            <a href="https://strifetech.com/docs/strifebridge-mcp" target="_blank">Docs</a>
        </div>

        <?php if ($api_disabled): ?><div class="notice notice-error"><p><strong>StrifeBridge MCP API is disabled.</strong> Re-enable in the Danger Zone below.</p></div><?php endif; ?>
        <?php if ($api_just_disabled): ?><div class="notice notice-warning is-dismissible"><p><strong>API disabled.</strong></p></div><?php endif; ?>
        <?php if ($api_just_enabled): ?><div class="notice notice-success is-dismissible"><p><strong>API re-enabled.</strong></p></div><?php endif; ?>
        <?php if ($regenerated): ?><div class="notice notice-success is-dismissible"><p><strong>Token regenerated.</strong> Update your connector URL in Claude.ai.</p></div><?php endif; ?>
        <?php if ($tools_saved): ?><div class="notice notice-success is-dismissible"><p><strong>Tool settings saved.</strong></p></div><?php endif; ?>

        <div class="sb-layout">
            <div class="sb-main">

                <!-- Claude.ai Connector -->
                <div class="sb-card">
                    <h2>Claude.ai Connector</h2>
                    <p>Copy the URL below and paste it into Claude.ai &rarr; Settings &rarr; Integrations &rarr; Add custom connector.</p>
                    <div class="sb-field"><label>Connector URL</label><div class="sb-input-row"><input type="text" id="sb-mcp-url" value="<?php echo esc_attr($mcp_url_tok); ?>" class="large-text" readonly /><button type="button" class="button button-primary" onclick="navigator.clipboard.writeText(document.getElementById('sb-mcp-url').value).then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000)});">Copy</button></div></div>
                    <hr class="sb-divider">
                    <div class="sb-field"><label>Bearer Token <span style="font-weight:400;color:#777;">(for direct API use)</span></label><div class="sb-input-row"><input type="text" id="sb-token" value="<?php echo esc_attr($token); ?>" class="regular-text" readonly /><button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('sb-token').value).then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000)});">Copy</button></div></div>
                </div>

                <!-- Tool Settings -->
                <div class="sb-card">
                    <h2>Tool Settings</h2>
                    <p>Enable or disable individual tool groups. Disabled tools are removed from the MCP server and REST API entirely &mdash; Claude will not be able to see or call them.</p>
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
                        <button type="submit" name="sbmcp_save_tools" class="button button-primary">Save Tool Settings</button>
                    </form>
                </div>

                <!-- Danger Zone -->
                <div class="sb-card sb-danger">
                    <h2>Danger Zone</h2>
                    <p><strong>Emergency Lockdown</strong> &mdash; instantly disables all API and MCP access without changing the token.</p>
                    <form method="post" style="margin-bottom:20px;"><?php wp_nonce_field('sbmcp_lockdown'); ?><?php if ($api_disabled): ?><input type="hidden" name="sbmcp_lockdown_action" value="enable" /><button type="submit" class="button button-primary" onclick="return confirm('Re-enable the StrifeBridge MCP API?');">Re-enable API</button><?php else: ?><input type="hidden" name="sbmcp_lockdown_action" value="disable" /><button type="submit" class="button button-secondary" style="border-color:#ef4444;color:#ef4444;" onclick="return confirm('Disable the entire StrifeBridge MCP API?');">Disable API</button><?php endif; ?></form>
                    <hr class="sb-divider">
                    <p>Regenerating the token will invalidate your current connector URL.</p>
                    <form method="post"><?php wp_nonce_field('sbmcp_regenerate_token'); ?><button type="submit" name="sbmcp_regenerate" class="button button-secondary" style="border-color:#ef4444;color:#ef4444;" onclick="return confirm('Regenerate token?');">Regenerate Token</button></form>
                </div>

            </div>

            <!-- Sidebar -->
            <div class="sb-sidebar">
                <div class="sb-sidebar-card">
                    <h3>Take StrifeBridge to the Next Level</h3>
                    <ul>
                        <li>Theme file editing</li>
                        <li>Plugin file editing</li>
                        <li>Database access</li>
                        <li>User management</li>
                        <li>Error log &amp; cron</li>
                        <li>Priority support</li>
                    </ul>
                    <br>
                    <a href="https://strifetech.com/strifebridge-mcp-pro" target="_blank" class="sb-promo-btn">Get StrifeBridge MCP Pro</a>
                </div>
                <div class="sb-sidebar-card">
                    <h3>Community</h3>
                    <p><a href="https://github.com/strifero/strifebridge-mcp/discussions" target="_blank">GitHub Discussions</a></p>
                </div>
                <div class="sb-sidebar-card">
                    <h3>Getting Started</h3>
                    <p><a href="https://strifetech.com/docs/strifebridge-mcp" target="_blank">Read the docs</a></p>
                </div>
                <div class="sb-sidebar-card">
                    <h3>Support</h3>
                    <p><a href="https://github.com/strifero/strifebridge-mcp/issues" target="_blank">Report an issue</a></p>
                </div>
                <div class="sb-sidebar-card">
                    <h3>Submit a Review</h3>
                    <p><a href="https://wordpress.org/support/plugin/strifebridge-mcp-for-wordpress/reviews/#new-post" target="_blank">Leave a review on wp.org</a></p>
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
