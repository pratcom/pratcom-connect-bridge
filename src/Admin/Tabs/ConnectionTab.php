<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;
use Pratcom\Connect\Bridge\HealthCheck;

/**
 * Onglet Connexion : liaison du site au workspace Pratcom Connect (cle pck_).
 * Contenu + handlers admin_post iso-fonctionnels au monolithe (O0).
 * Propriete : chantier Plugin .org.
 */
class ConnectionTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-connection';

    private const NONCE_CONNECT = 'pratcom_connect_bridge_connect';
    private const NONCE_DISCONNECT = 'pratcom_connect_bridge_disconnect';
    private const NONCE_CHECK = 'pratcom_connect_bridge_check_now';

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Compte', 'pratcom-connect');
    }

    public function icon(): string
    {
        return 'admin-network';
    }

    public function register(): void
    {
        add_action('admin_post_pratcom_connect_bridge_connect', [$this, 'handle_connect']);
        add_action('admin_post_pratcom_connect_bridge_disconnect', [$this, 'handle_disconnect']);
        add_action('admin_post_pratcom_connect_bridge_check_now', [$this, 'handle_check_now']);
    }

    public function render(): void
    {
        $status = (string) get_option(Plugin::OPTION_STATUS, 'disconnected');
        $prefix = (string) get_option(Plugin::OPTION_KEY_PREFIX, '');
        $last_four = (string) get_option(Plugin::OPTION_KEY_LAST_FOUR, '');
        $workspace_slug = (string) get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
        $workspace_id = (string) get_option(Plugin::OPTION_WORKSPACE_ID, '');
        $last_handshake = (string) get_option(Plugin::OPTION_LAST_HANDSHAKE, '');
        $last_error = (string) get_option(Plugin::OPTION_LAST_ERROR, '');
        $connected = Plugin::is_connected();
        $has_key = !empty(Plugin::get_api_key());
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Compte', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Gerez la liaison entre ce site et votre compte Pratcom Connect.', 'pratcom-connect'); ?>
        </p>

        <?php if ($connected): ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Connexion active', 'pratcom-connect'); ?></h2>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Workspace', 'pratcom-connect'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($workspace_slug); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Workspace ID', 'pratcom-connect'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($workspace_id); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Cle API', 'pratcom-connect'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($prefix); ?>&hellip;<?php echo esc_html($last_four); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Dernier handshake', 'pratcom-connect'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($last_handshake ?: '—'); ?></span>
                </div>

                <div class="pc-actions">
                    <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=account-tab" target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Ouvrir mon portail Pratcom Connect', 'pratcom-connect'); ?>
                    </a>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="pratcom_connect_bridge_check_now" />
                        <?php wp_nonce_field(self::NONCE_CHECK); ?>
                        <button type="submit" class="pc-btn pc-btn--secondary">
                            <?php esc_html_e('Verifier maintenant', 'pratcom-connect'); ?>
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="pratcom_connect_bridge_disconnect" />
                        <?php wp_nonce_field(self::NONCE_DISCONNECT); ?>
                        <button type="submit" class="pc-btn pc-btn--danger"
                            onclick="return confirm('<?php echo esc_js(__('Confirmer la deconnexion ?', 'pratcom-connect')); ?>')">
                            <?php esc_html_e('Se deconnecter', 'pratcom-connect'); ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php elseif ($has_key && in_array($status, ['error', 'revoked'], true)): ?>
            <div class="pc-card" style="border-left: 4px solid var(--pc-danger);">
                <h2 class="pc-card__title">
                    <?php echo esc_html($status === 'revoked'
                        ? __('Cle revoquee', 'pratcom-connect')
                        : __('Erreur de connexion', 'pratcom-connect')); ?>
                </h2>
                <p style="color: var(--pc-text-muted);"><?php echo esc_html($last_error); ?></p>
                <p><span class="pc-card__value"><?php echo esc_html($prefix); ?>&hellip;<?php echo esc_html($last_four); ?></span></p>
                <div class="pc-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="pratcom_connect_bridge_check_now" />
                        <?php wp_nonce_field(self::NONCE_CHECK); ?>
                        <button type="submit" class="pc-btn pc-btn--primary">
                            <?php esc_html_e('Re-essayer', 'pratcom-connect'); ?>
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="pratcom_connect_bridge_disconnect" />
                        <?php wp_nonce_field(self::NONCE_DISCONNECT); ?>
                        <button type="submit" class="pc-btn pc-btn--danger">
                            <?php esc_html_e('Effacer la cle', 'pratcom-connect'); ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Connecter ce site', 'pratcom-connect'); ?></h2>
                <p style="color: var(--pc-text-muted); margin: 0 0 16px 0;">
                    <?php esc_html_e('Collez la cle API fournie par Pratcom Media. Format : pck_workspace_xxxx (48 caracteres).', 'pratcom-connect'); ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pratcom_connect_bridge_connect" />
                    <?php wp_nonce_field(self::NONCE_CONNECT); ?>

                    <div class="pc-form-field">
                        <label for="pratcom_api_key" class="pc-form-label">
                            <?php esc_html_e('Cle API', 'pratcom-connect'); ?>
                        </label>
                        <input type="password" id="pratcom_api_key" name="pratcom_api_key" class="pc-form-input"
                            placeholder="pck_workspace-slug_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                            autocomplete="off" required />
                        <p class="pc-form-help">
                            <?php esc_html_e('Cette cle est stockee localement et utilisee uniquement pour authentifier ce site aupres de l\'API Pratcom Connect.', 'pratcom-connect'); ?>
                        </p>
                    </div>

                    <div class="pc-actions">
                        <button type="submit" class="pc-btn pc-btn--primary">
                            <?php esc_html_e('Connecter', 'pratcom-connect'); ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        <?php
    }

    public function handle_connect(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_CONNECT);

        $raw_key = isset($_POST['pratcom_api_key']) ? trim(wp_unslash($_POST['pratcom_api_key'])) : '';
        if (!preg_match('/^pck_[a-z0-9-]+_[A-Za-z0-9]{32}$/', $raw_key)) {
            $this->redirect_with_notice(self::PAGE_SLUG, 'error', __('Format de cle invalide.', 'pratcom-connect'));
        }

        $domain = wp_parse_url(home_url(), PHP_URL_HOST);
        $client = new ApiClient();
        $res = $client->handshake($raw_key, $domain);

        if (!($res['ok'] ?? false)) {
            $err = $res['error'] ?? 'http_error';
            $msg = sprintf('%s (HTTP %s)', $err, $res['http_code'] ?? '?');
            update_option(Plugin::OPTION_STATUS, 'error');
            update_option(Plugin::OPTION_LAST_ERROR, $msg);
            $this->redirect_with_notice(self::PAGE_SLUG, 'error', $msg);
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
        $this->redirect_with_notice(DashboardTab::PAGE_SLUG, 'connected', '');
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
        $this->redirect_with_notice(self::PAGE_SLUG, 'disconnected', '');
    }

    public function handle_check_now(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_CHECK);

        $hc = new HealthCheck();
        $res = $hc->run();
        $this->redirect_with_notice(self::PAGE_SLUG, 'checked', $res['status'] ?? 'unknown');
    }
}
