<?php
/**
 * The authorization screen.
 *
 * Rendered inside wp-admin rather than as a standalone page, which settles
 * authentication for free: WordPress will not show an admin page to a logged-out
 * visitor, it sends them to wp-login.php and returns them here afterwards. There
 * is no session handling to write and no way to reach the approve button
 * without having logged in first.
 *
 * The screen is reached only by redirect from /oauth/authorize, which has
 * already checked the client and exact-matched the redirect URI. Everything is
 * checked again here anyway, because this page is reachable by URL and its
 * parameters arrive from the browser.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the consent screen as a hidden options page.
 *
 * Added under Settings and then removed from the menu: the page stays reachable
 * by URL, which is all the redirect needs, without putting a "Authorize an
 * application" item in the sidebar where it would be meaningless on its own.
 *
 * add_submenu_page() with a null parent would do the same thing in one call and
 * is deprecated in current WordPress, which is why this takes the long way.
 *
 * @return void
 */
function sbmcp_oauth_register_consent_page() {
    add_submenu_page(
        'options-general.php',
        __('Authorize Application', 'strifebridge-mcp'),
        __('Authorize Application', 'strifebridge-mcp'),
        'manage_options',
        'sbmcp-oauth-authorize',
        'sbmcp_oauth_consent_page'
    );
    remove_submenu_page('options-general.php', 'sbmcp-oauth-authorize');
}
add_action('admin_menu', 'sbmcp_oauth_register_consent_page');

/**
 * Reads and re-validates the authorization request carried in the URL.
 *
 * @return array{client: array<string,mixed>, redirect_uri: string, state: string, challenge: string, scope: string}|WP_Error
 */
function sbmcp_oauth_consent_request() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading
    // the client's own authorization request, which is not a state change. The
    // approval below is nonce-checked.
    $client_id    = isset($_REQUEST['client_id']) ? sanitize_text_field(wp_unslash($_REQUEST['client_id'])) : '';
    $redirect_uri = isset($_REQUEST['redirect_uri']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_uri'])) : '';
    $state        = isset($_REQUEST['state']) ? sanitize_text_field(wp_unslash($_REQUEST['state'])) : '';
    $challenge    = isset($_REQUEST['code_challenge']) ? sanitize_text_field(wp_unslash($_REQUEST['code_challenge'])) : '';
    $method       = isset($_REQUEST['code_challenge_method']) ? sanitize_text_field(wp_unslash($_REQUEST['code_challenge_method'])) : '';
    $scope        = isset($_REQUEST['scope']) ? sanitize_text_field(wp_unslash($_REQUEST['scope'])) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    if ($client_id === '') {
        return new WP_Error('invalid_request', __('No application was specified.', 'strifebridge-mcp'));
    }

    $client = sbmcp_oauth_get_client($client_id);
    if (!$client) {
        return new WP_Error('invalid_client', __('That application is not registered with this site.', 'strifebridge-mcp'));
    }

    // Re-checked here and not merely trusted from the redirect: this page can be
    // opened directly with any parameters someone cares to put in the URL.
    if ($redirect_uri === '' || !sbmcp_oauth_redirect_uri_allowed($client, $redirect_uri)) {
        return new WP_Error('invalid_redirect_uri', __('The return address does not match one this application registered.', 'strifebridge-mcp'));
    }

    if ($method !== 'S256' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
        return new WP_Error('invalid_request', __('The application did not supply a valid PKCE challenge.', 'strifebridge-mcp'));
    }

    return [
        'client'       => $client,
        'redirect_uri' => $redirect_uri,
        'state'        => $state,
        'challenge'    => $challenge,
        'scope'        => sbmcp_oauth_sanitize_scope($scope),
    ];
}

/**
 * Handles the approve/deny submission.
 *
 * @return void
 */
function sbmcp_oauth_handle_consent() {
    if (!isset($_POST['sbmcp_oauth_decision'])) {
        return;
    }
    check_admin_referer('sbmcp_oauth_consent');

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'strifebridge-mcp'));
    }

    $parsed = sbmcp_oauth_consent_request();
    if (is_wp_error($parsed)) {
        wp_die(
            esc_html($parsed->get_error_message()),
            esc_html__('Connection refused', 'strifebridge-mcp'),
            ['response' => 400, 'back_link' => false]
        );
    }

    $decision = sanitize_key(wp_unslash($_POST['sbmcp_oauth_decision']));
    $client   = $parsed['client'];

    if ($decision !== 'approve') {
        sbmcp_audit_log(
            'oauth_authorize',
            ['client_id' => $client['client_id'], 'scope' => $parsed['scope']],
            'denied',
            'Authorization declined by the administrator.'
        );
        sbmcp_oauth_authorize_redirect_error(
            $parsed['redirect_uri'],
            'access_denied',
            'The site administrator declined the request.',
            $parsed['state']
        );
    }

    $user_id = get_current_user_id();

    $code = sbmcp_oauth_issue_code(
        $client['client_id'],
        $user_id,
        $parsed['redirect_uri'],
        $parsed['challenge'],
        $parsed['scope']
    );

    if (!$code) {
        sbmcp_audit_log('oauth_authorize', ['client_id' => $client['client_id']], 'error', 'Authorization failed: could not issue a code.');
        sbmcp_oauth_authorize_redirect_error($parsed['redirect_uri'], 'server_error', 'Could not issue an authorization code.', $parsed['state']);
    }

    sbmcp_audit_log(
        'oauth_authorize',
        ['client_id' => $client['client_id'], 'scope' => $parsed['scope']],
        'success'
    );

    $args = ['code' => rawurlencode($code)];
    if ($parsed['state'] !== '') {
        $args['state'] = rawurlencode($parsed['state']);
    }

    // Off-site by definition, and safe because it was exact-matched against the
    // client's registered URIs twice. See sbmcp_oauth_authorize_redirect_error().
    wp_redirect(add_query_arg($args, $parsed['redirect_uri']));
    exit;
}
add_action('admin_init', 'sbmcp_oauth_handle_consent');

/**
 * Renders the consent screen.
 *
 * @return void
 */
function sbmcp_oauth_consent_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'strifebridge-mcp'));
    }

    $parsed = sbmcp_oauth_consent_request();
    if (is_wp_error($parsed)) {
        echo '<div class="wrap sb-wrap"><h1>' . esc_html__('Connection refused', 'strifebridge-mcp') . '</h1>';
        echo '<div class="notice notice-error"><p>' . esc_html($parsed->get_error_message()) . '</p></div>';
        echo '<p>' . esc_html__('Nothing has been shared with the application.', 'strifebridge-mcp') . '</p></div>';
        return;
    }

    $client      = $parsed['client'];
    $scopes      = sbmcp_oauth_scopes();
    $requested   = preg_split('/\s+/', trim($parsed['scope'])) ?: [];
    $user        = wp_get_current_user();
    $host        = wp_parse_url($parsed['redirect_uri'], PHP_URL_HOST);
    ?>
    <div class="wrap sb-wrap sb-oauth-consent">
        <h1><?php esc_html_e('Authorize application', 'strifebridge-mcp'); ?></h1>

        <div class="sb-card">
            <h2>
                <?php
                printf(
                    /* translators: %s: name of the application requesting access. */
                    esc_html__('%s wants to connect to this site', 'strifebridge-mcp'),
                    '<strong>' . esc_html($client['client_name']) . '</strong>'
                );
                ?>
            </h2>

            <?php if (!empty($client['client_uri'])): ?>
                <p class="sb-tool-desc">
                    <a href="<?php echo esc_url($client['client_uri']); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html($client['client_uri']); ?></a>
                </p>
            <?php endif; ?>

            <p>
                <?php
                printf(
                    /* translators: 1: WordPress username, 2: user's role-bearing display name. */
                    esc_html__('It will act as %1$s (%2$s). It can never do more than that account is allowed to do.', 'strifebridge-mcp'),
                    '<strong>' . esc_html($user->user_login) . '</strong>',
                    esc_html($user->display_name)
                );
                ?>
            </p>

            <h3><?php esc_html_e('It is asking to:', 'strifebridge-mcp'); ?></h3>
            <ul class="sb-scope-list">
                <?php foreach ($requested as $scope): ?>
                    <?php if (!isset($scopes[$scope])) { continue; } ?>
                    <li>
                        <strong><?php echo esc_html($scopes[$scope]['label']); ?></strong>
                        <span class="sb-tool-desc"><?php echo esc_html($scopes[$scope]['description']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <table class="widefat sb-oauth-detail">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Returns to', 'strifebridge-mcp'); ?></th>
                        <td><code><?php echo esc_html($host ?: $parsed['redirect_uri']); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Application ID', 'strifebridge-mcp'); ?></th>
                        <td><code><?php echo esc_html($client['client_id']); ?></code></td>
                    </tr>
                </tbody>
            </table>

            <p class="sb-tool-desc">
                <?php esc_html_e('Only approve this if you started the connection yourself. You can revoke it at any time from Connected Applications in StrifeBridge MCP settings.', 'strifebridge-mcp'); ?>
            </p>

            <form method="post" class="sb-oauth-actions">
                <?php wp_nonce_field('sbmcp_oauth_consent'); ?>
                <input type="hidden" name="client_id" value="<?php echo esc_attr($client['client_id']); ?>" />
                <input type="hidden" name="redirect_uri" value="<?php echo esc_attr($parsed['redirect_uri']); ?>" />
                <input type="hidden" name="state" value="<?php echo esc_attr($parsed['state']); ?>" />
                <input type="hidden" name="code_challenge" value="<?php echo esc_attr($parsed['challenge']); ?>" />
                <input type="hidden" name="code_challenge_method" value="S256" />
                <input type="hidden" name="scope" value="<?php echo esc_attr($parsed['scope']); ?>" />

                <button type="submit" name="sbmcp_oauth_decision" value="approve" class="button button-primary button-hero">
                    <?php esc_html_e('Approve', 'strifebridge-mcp'); ?>
                </button>
                <button type="submit" name="sbmcp_oauth_decision" value="deny" class="button button-hero">
                    <?php esc_html_e('Cancel', 'strifebridge-mcp'); ?>
                </button>
            </form>
        </div>
    </div>
    <?php
}
