<?php

namespace Pratcom\Connect\Bridge\Admin;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;
use Pratcom\Connect\Bridge\HealthCheck;

class SettingsPage
{
    private const SLUG = 'pratcom-connect';
    private const NONCE_CONNECT = 'pratcom_connect_bridge_connect';
    private const NONCE_DISCONNECT = 'pratcom_connect_bridge_disconnect';
    private const NONCE_CHECK = 'pratcom_connect_bridge_check_now';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_pratcom_connect_bridge_connect', [$this, 'handle_connect']);
        add_action('admin_post_pratcom_connect_bridge_disconnect', [$this, 'handle_disconnect']);
        add_action('admin_post_pratcom_connect_bridge_check_now', [$this, 'handle_check_now']);
    }

    public function register_menu(): void
    {
        add_options_page(
            __('Pratcom Connect', 'pratcom-connect-bridge'),
            __('Pratcom Connect', 'pratcom-connect-bridge'),
            'manage_options',
            self::SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) return;

        $status = get_option(Plugin::OPTION_STATUS, 'disconnected');
        $prefix = get_option(Plugin::OPTION_KEY_PREFIX, '');
        $last_four = get_option(Plugin::OPTION_KEY_LAST_FOUR, '');
        $workspace_slug = get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
        $workspace_id = get_option(Plugin::OPTION_WORKSPACE_ID, '');
        $last_handshake = get_option(Plugin::OPTION_LAST_HANDSHAKE, '');
        $last_error = get_option(Plugin::OPTION_LAST_ERROR, '');
        $connected = $status === 'connected' && !empty($workspace_id);
        $has_key = !empty(Plugin::get_api_key());

        $notice = $_GET['pratcom_notice'] ?? '';
        $notice_msg = $_GET['pratcom_msg'] ?? '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pratcom Connect', 'pratcom-connect-bridge'); ?></h1>

            <?php if ($notice === 'connected'): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e('Connecte avec succes.', 'pratcom-connect-bridge'); ?>
                </p></div>
            <?php elseif ($notice === 'disconnected'): ?>
                <div class="notice notice-info is-dismissible"><p>
                    <?php esc_html_e('Plugin deconnecte. La cle API a ete retiree localement.', 'pratcom-connect-bridge'); ?>
                </p></div>
            <?php elseif ($notice === 'checked'): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html(sprintf(__('Verification effectuee. Statut: %s', 'pratcom-connect-bridge'), $notice_msg)); ?>
                </p></div>
            <?php elseif ($notice === 'error'): ?>
                <div class="notice notice-error is-dismissible"><p>
                    <?php echo esc_html(sprintf(__('Erreur: %s', 'pratcom-connect-bridge'), $notice_msg)); ?>
                </p></div>
            <?php endif; ?>

            <?php if ($connected): ?>
                <div class="card" style="max-width: 720px;">
                    <h2><?php esc_html_e('Connecte', 'pratcom-connect-bridge'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr><th><?php esc_html_e('Workspace', 'pratcom-connect-bridge'); ?></th>
                            <td><strong><?php echo esc_html($workspace_slug); ?></strong> <code><?php echo esc_html($workspace_id); ?></code></td></tr>
                        <tr><th><?php esc_html_e('Cle API', 'pratcom-connect-bridge'); ?></th>
                            <td><code><?php echo esc_html($prefix); ?>...<?php echo esc_html($last_four); ?></code></td></tr>
                        <tr><th><?php esc_html_e('Dernier handshake', 'pratcom-connect-bridge'); ?></th>
                            <td><?php echo esc_html($last_handshake); ?></td></tr>
                    </table>

                    <div style="margin-top: 16px; display: flex; gap: 8px;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="pratcom_connect_bridge_check_now" />
                            <?php wp_nonce_field(self::NONCE_CHECK); ?>
                            <button type="submit" class="button"><?php esc_html_e('Verifier maintenant', 'pratcom-connect-bridge'); ?></button>
                        </form>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="pratcom_connect_bridge_disconnect" />
                            <?php wp_nonce_field(self::NONCE_DISCONNECT); ?>
                            <button type="submit" class="button" onclick="return confirm('<?php echo esc_js(__('Confirmer la deconnexion ?', 'pratcom-connect-bridge')); ?>')">
                                <?php esc_html_e('Se deconnecter', 'pratcom-connect-bridge'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php elseif ($has_key && in_array($status, ['error', 'revoked'], true)): ?>
                <div class="card" style="max-width: 720px; border-left: 4px solid #d63638;">
                    <h2><?php echo esc_html($status === 'revoked' ? __('Cle revoquee', 'pratcom-connect-bridge') : __('Erreur', 'pratcom-connect-bridge')); ?></h2>
                    <p><?php echo esc_html($last_error); ?></p>
                    <p><code><?php echo esc_html($prefix); ?>...<?php echo esc_html($last_four); ?></code></p>
                    <div style="display: flex; gap: 8px;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="pratcom_connect_bridge_check_now" />
                            <?php wp_nonce_field(self::NONCE_CHECK); ?>
                            <button type="submit" class="button"><?php esc_html_e('Re-essayer', 'pratcom-connect-bridge'); ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="pratcom_connect_bridge_disconnect" />
                            <?php wp_nonce_field(self::NONCE_DISCONNECT); ?>
                            <button type="submit" class="button"><?php esc_html_e('Effacer la cle', 'pratcom-connect-bridge'); ?></button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <p><?php esc_html_e('Coller la cle API fournie par Pratcom Media pour activer les modules Pratcom Connect sur ce site.', 'pratcom-connect-bridge'); ?></p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pratcom_connect_bridge_connect" />
                    <?php wp_nonce_field(self::NONCE_CONNECT); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="pratcom_api_key"><?php esc_html_e('Cle API', 'pratcom-connect-bridge'); ?></label></th>
                            <td>
                                <input type="password" id="pratcom_api_key" name="pratcom_api_key" class="regular-text"
                                    placeholder="pck_workspace-slug_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off" required />
                                <p class="description"><?php esc_html_e('Format: pck_workspace_xxxx (48 caracteres).', 'pratcom-connect-bridge'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Connecter', 'pratcom-connect-bridge'); ?></button>
                    </p>
                </form>
            <?php endif; ?>

            <p class="description" style="margin-top: 32px;">
                <?php echo wp_kses_post(__('Documentation : <a href="https://docs.pratcom.net/connect/bridge" target="_blank">docs.pratcom.net/connect/bridge</a>', 'pratcom-connect-bridge')); ?>
            </p>
        </div>
        <?php
    }

    public function handle_connect(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_CONNECT);

        $raw_key = isset($_POST['pratcom_api_key']) ? trim(wp_unslash($_POST['pratcom_api_key'])) : '';
        if (!preg_match('/^pck_[a-z0-9-]+_[A-Za-z0-9]{32}$/', $raw_key)) {
            $this->redirect_with_notice('error', __('Format de cle invalide.', 'pratcom-connect-bridge'));
        }

        $domain = wp_parse_url(home_url(), PHP_URL_HOST);
        $client = new ApiClient();
        $res = $client->handshake($raw_key, $domain);

        if (!($res['ok'] ?? false)) {
            $err = $res['error'] ?? 'http_error';
            $msg = sprintf('%s (HTTP %s)', $err, $res['http_code'] ?? '?');
            update_option(Plugin::OPTION_STATUS, 'error');
            update_option(Plugin::OPTION_LAST_ERROR, $msg);
            $this->redirect_with_notice('error', $msg);
        }

        preg_match('/^(pck_[a-z0-9-]+_)([A-Za-z0-9]{32})$/', $raw_key, $m);
        $prefix = $m[1] ?? '';
        $last_four = isset($m[2]) ? substr($m[2], -4) : '';

        update_option(Plugin::OPTION_API_KEY_PLAINTEXT, $raw_key);
        update_option(Plugin::OPTION_KEY_PREFIX, $prefix);
        update_option(Plugin::OPTION_KEY_LAST_FOUR, $last_four);
        update_option(Plugin::OPTION_WORKSPACE_ID, $res['workspace_id'] ?? '');
        update_option(Plugin::OPTION_WORKSPACE_SLUG, $res['workspace_slug'] ?? '');
        update_option(Plugin::OPTION_FEATURE_PACKS, $res['feature_packs'] ?? []);
        update_option(Plugin::OPTION_LAST_HANDSHAKE, current_time('mysql'));
        update_option(Plugin::OPTION_STATUS, 'connected');
        delete_option(Plugin::OPTION_LAST_ERROR);

        HealthCheck::schedule();
        $this->redirect_with_notice('connected', '');
    }

    public function handle_disconnect(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_DISCONNECT);

        delete_option(Plugin::OPTION_API_KEY_PLAINTEXT);
        delete_option(Plugin::OPTION_KEY_PREFIX);
        delete_option(Plugin::OPTION_KEY_LAST_FOUR);
        delete_option(Plugin::OPTION_WORKSPACE_ID);
        delete_option(Plugin::OPTION_WORKSPACE_SLUG);
        delete_option(Plugin::OPTION_FEATURE_PACKS);
        delete_option(Plugin::OPTION_LAST_HANDSHAKE);
        delete_option(Plugin::OPTION_LAST_ERROR);
        update_option(Plugin::OPTION_STATUS, 'disconnected');

        HealthCheck::unschedule();
        $this->redirect_with_notice('disconnected', '');
    }

    public function handle_check_now(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_CHECK);

        $hc = new HealthCheck();
        $res = $hc->run();
        $this->redirect_with_notice('checked', $res['status'] ?? 'unknown');
    }

    private function redirect_with_notice(string $notice, string $msg): void
    {
        $url = add_query_arg([
            'page' => self::SLUG,
            'pratcom_notice' => $notice,
            'pratcom_msg' => $msg,
        ], admin_url('options-general.php'));
        wp_safe_redirect($url);
        exit;
    }
}
