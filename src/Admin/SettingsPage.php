<?php

namespace Pratcom\Connect\Bridge\Admin;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;
use Pratcom\Connect\Bridge\HealthCheck;

class SettingsPage
{
    private const SLUG = 'pratcom-connect';
    private const SLUG_MODULES = 'pratcom-connect-modules';
    private const SLUG_APPEARANCE = 'pratcom-connect-appearance';
    private const SLUG_CONNECTION = 'pratcom-connect-connection';
    private const SLUG_HELP = 'pratcom-connect-help';

    private const NONCE_CONNECT = 'pratcom_connect_bridge_connect';
    private const NONCE_DISCONNECT = 'pratcom_connect_bridge_disconnect';
    private const NONCE_CHECK = 'pratcom_connect_bridge_check_now';
    private const NONCE_THEME = 'pratcom_connect_bridge_save_theme';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_pratcom_connect_bridge_connect', [$this, 'handle_connect']);
        add_action('admin_post_pratcom_connect_bridge_disconnect', [$this, 'handle_disconnect']);
        add_action('admin_post_pratcom_connect_bridge_check_now', [$this, 'handle_check_now']);
        add_action('admin_post_pratcom_connect_bridge_save_theme', [$this, 'handle_save_theme']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Pratcom Connect', 'pratcom-connect-bridge'),
            __('Pratcom Connect', 'pratcom-connect-bridge'),
            'manage_options',
            self::SLUG,
            [$this, 'render_dashboard'],
            'dashicons-randomize',
            80
        );

        add_submenu_page(
            self::SLUG,
            __('Tableau de bord', 'pratcom-connect-bridge'),
            __('Tableau de bord', 'pratcom-connect-bridge'),
            'manage_options',
            self::SLUG,
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            self::SLUG,
            __('Modules', 'pratcom-connect-bridge'),
            __('Modules', 'pratcom-connect-bridge'),
            'manage_options',
            self::SLUG_MODULES,
            [$this, 'render_modules']
        );

        add_submenu_page(
            self::SLUG,
            __('Apparence', 'pratcom-connect-bridge'),
            __('Apparence', 'pratcom-connect-bridge'),
            'manage_options',
            self::SLUG_APPEARANCE,
            [$this, 'render_appearance']
        );

        add_submenu_page(
            self::SLUG,
            __('Connexion', 'pratcom-connect-bridge'),
            __('Connexion', 'pratcom-connect-bridge'),
            'manage_options',
            self::SLUG_CONNECTION,
            [$this, 'render_connection']
        );

        add_submenu_page(
            self::SLUG,
            __('Aide', 'pratcom-connect-bridge'),
            __('Aide', 'pratcom-connect-bridge'),
            'manage_options',
            self::SLUG_HELP,
            [$this, 'render_help']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'pratcom-connect') === false) return;

        wp_enqueue_style(
            'pratcom-connect-bridge-admin',
            PRATCOM_CONNECT_BRIDGE_URL . 'assets/css/admin.css',
            [],
            PRATCOM_CONNECT_BRIDGE_VERSION
        );
    }

    public function render_dashboard(): void
    {
        if (!current_user_can('manage_options')) return;
        $this->render_chrome(self::SLUG, function () { $this->section_dashboard(); });
    }

    public function render_modules(): void
    {
        if (!current_user_can('manage_options')) return;
        $this->render_chrome(self::SLUG_MODULES, function () { $this->section_modules(); });
    }

    public function render_appearance(): void
    {
        if (!current_user_can('manage_options')) return;
        $this->render_chrome(self::SLUG_APPEARANCE, function () { $this->section_appearance(); });
    }

    public function render_connection(): void
    {
        if (!current_user_can('manage_options')) return;
        $this->render_chrome(self::SLUG_CONNECTION, function () { $this->section_connection(); });
    }

    public function render_help(): void
    {
        if (!current_user_can('manage_options')) return;
        $this->render_chrome(self::SLUG_HELP, function () { $this->section_help(); });
    }

    private function render_chrome(string $current_slug, callable $body): void
    {
        $status = get_option(Plugin::OPTION_STATUS, 'disconnected');
        $logo_url = PRATCOM_CONNECT_BRIDGE_URL . 'assets/img/logo-pratcom-connect.svg';

        $nav = [
            self::SLUG => ['label' => __('Tableau de bord', 'pratcom-connect-bridge'), 'icon' => 'dashboard'],
            self::SLUG_MODULES => ['label' => __('Modules', 'pratcom-connect-bridge'), 'icon' => 'admin-plugins'],
            self::SLUG_APPEARANCE => ['label' => __('Apparence', 'pratcom-connect-bridge'), 'icon' => 'art'],
            self::SLUG_CONNECTION => ['label' => __('Connexion', 'pratcom-connect-bridge'), 'icon' => 'admin-network'],
            self::SLUG_HELP => ['label' => __('Aide', 'pratcom-connect-bridge'), 'icon' => 'editor-help'],
        ];

        $status_labels = [
            'connected' => __('Connecte', 'pratcom-connect-bridge'),
            'disconnected' => __('Non connecte', 'pratcom-connect-bridge'),
            'error' => __('Erreur', 'pratcom-connect-bridge'),
            'revoked' => __('Cle revoquee', 'pratcom-connect-bridge'),
        ];
        ?>
        <div class="wrap pc-admin-wrap">
            <header class="pc-header">
                <img src="<?php echo esc_url($logo_url); ?>" alt="Pratcom Connect" class="pc-header__logo" />
                <span class="pc-header__version">v<?php echo esc_html(PRATCOM_CONNECT_BRIDGE_VERSION); ?></span>
                <span class="pc-header__status pc-header__status--<?php echo esc_attr($status); ?>">
                    <?php echo esc_html($status_labels[$status] ?? ucfirst($status)); ?>
                </span>
            </header>

            <div class="pc-body">
                <nav class="pc-sidebar">
                    <ul class="pc-sidebar__nav">
                        <?php foreach ($nav as $slug => $item): ?>
                            <li class="pc-sidebar__item">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>"
                                   class="pc-sidebar__link <?php echo $slug === $current_slug ? 'is-active' : ''; ?>">
                                    <span class="dashicons dashicons-<?php echo esc_attr($item['icon']); ?>"></span>
                                    <span><?php echo esc_html($item['label']); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>

                <main class="pc-content">
                    <?php $this->render_notices(); ?>
                    <?php $body(); ?>
                </main>
            </div>
        </div>
        <?php
    }

    private function render_notices(): void
    {
        $notice = isset($_GET['pratcom_notice']) ? sanitize_key($_GET['pratcom_notice']) : '';
        $msg = isset($_GET['pratcom_msg']) ? sanitize_text_field(wp_unslash($_GET['pratcom_msg'])) : '';
        if (!$notice) return;

        $map = [
            'connected'    => ['success', __('Connecte avec succes.', 'pratcom-connect-bridge')],
            'disconnected' => ['info', __('Plugin deconnecte. La cle API a ete retiree localement.', 'pratcom-connect-bridge')],
            'checked'      => ['success', sprintf(__('Verification effectuee. Statut : %s', 'pratcom-connect-bridge'), $msg)],
            'theme_saved'  => ['success', __('Couleurs enregistrees et synchronisees.', 'pratcom-connect-bridge')],
            'error'        => ['error', sprintf(__('Erreur : %s', 'pratcom-connect-bridge'), $msg)],
        ];
        if (!isset($map[$notice])) return;
        [$type, $text] = $map[$notice];
        ?>
        <div class="pc-notice pc-notice--<?php echo esc_attr($type); ?>"><?php echo esc_html($text); ?></div>
        <?php
    }

    private function section_dashboard(): void
    {
        $workspace_slug = (string) get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
        $last_handshake = (string) get_option(Plugin::OPTION_LAST_HANDSHAKE, '');
        $feature_packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        if (!is_array($feature_packs)) $feature_packs = [];

        $connected = Plugin::is_connected();
        $active_modules = 0;
        foreach ($feature_packs as $pack) {
            if (is_array($pack) && !empty($pack['enabled'])) $active_modules++;
        }
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Tableau de bord', 'pratcom-connect-bridge'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Vue d\'ensemble de votre connexion a Pratcom Connect.', 'pratcom-connect-bridge'); ?>
        </p>

        <?php if ($connected): ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Connexion active', 'pratcom-connect-bridge'); ?></h2>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Workspace', 'pratcom-connect-bridge'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($workspace_slug); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Modules actifs', 'pratcom-connect-bridge'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html((string) $active_modules); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Dernier handshake', 'pratcom-connect-bridge'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($last_handshake ?: '—'); ?></span>
                </div>
                <div class="pc-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_MODULES)); ?>" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Gerer les modules', 'pratcom-connect-bridge'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_APPEARANCE)); ?>" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e('Apparence', 'pratcom-connect-bridge'); ?>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Bienvenue dans Pratcom Connect', 'pratcom-connect-bridge'); ?></h2>
                <p style="color: var(--pc-text-muted); margin: 0 0 16px 0;">
                    <?php esc_html_e('Connectez ce site a Pratcom Connect pour activer les modules (Chat IA, Forms, Privacy) via une seule cle API fournie par Pratcom Media.', 'pratcom-connect-bridge'); ?>
                </p>
                <div class="pc-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_CONNECTION)); ?>" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Se connecter', 'pratcom-connect-bridge'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_MODULES)); ?>" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e('Voir les modules', 'pratcom-connect-bridge'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    private function section_modules(): void
    {
        $feature_packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        if (!is_array($feature_packs)) $feature_packs = [];

        $modules = [
            'chat' => [
                'label' => __('Connect Chat', 'pratcom-connect-bridge'),
                'short' => 'C',
                'desc'  => __('Chatbot IA multilingue 24/7 avec base de connaissances RAG par client.', 'pratcom-connect-bridge'),
            ],
            'forms' => [
                'label' => __('Connect Forms', 'pratcom-connect-bridge'),
                'short' => 'F',
                'desc'  => __('Formulaires intelligents avec scoring de leads et routage automatique.', 'pratcom-connect-bridge'),
            ],
            'privacy' => [
                'label' => __('Connect Privacy', 'pratcom-connect-bridge'),
                'short' => 'P',
                'desc'  => __('Banniere de consentement Loi 25 et catalogue de cookies automatique.', 'pratcom-connect-bridge'),
            ],
        ];
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Modules', 'pratcom-connect-bridge'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Modules Pratcom Connect disponibles sur ce site WordPress.', 'pratcom-connect-bridge'); ?>
        </p>

        <div class="pc-modules-grid">
            <?php foreach ($modules as $key => $mod):
                $is_active = isset($feature_packs[$key]['enabled']) && (bool) $feature_packs[$key]['enabled'];

                if ($is_active) {
                    $badge_class = 'active';
                    $badge_label = __('Actif', 'pratcom-connect-bridge');
                    $note = __('Gere par Pratcom Media via la cle API.', 'pratcom-connect-bridge');
                } else {
                    $badge_class = 'inactive';
                    $badge_label = __('Inactif', 'pratcom-connect-bridge');
                    $note = __('Contactez Pratcom Media pour activer.', 'pratcom-connect-bridge');
                }
                ?>
                <article class="pc-module-card">
                    <div class="pc-module-card__header">
                        <div class="pc-module-card__icon"><?php echo esc_html($mod['short']); ?></div>
                        <h3 class="pc-module-card__title"><?php echo esc_html($mod['label']); ?></h3>
                        <span class="pc-module-card__badge pc-module-card__badge--<?php echo esc_attr($badge_class); ?>">
                            <?php echo esc_html($badge_label); ?>
                        </span>
                    </div>
                    <p class="pc-module-card__desc"><?php echo esc_html($mod['desc']); ?></p>
                    <p class="pc-module-card__note"><?php echo esc_html($note); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function section_appearance(): void
    {
        $theme = Plugin::get_theme();
        $primary = !empty($theme['primary']) ? $theme['primary'] : '#377ba6';
        $on_primary = !empty($theme['onPrimary']) ? $theme['onPrimary'] : '';
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Apparence', 'pratcom-connect-bridge'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Couleurs de marque utilisees par les modules Pratcom Connect (banniere Privacy, et a venir le chat et les formulaires).', 'pratcom-connect-bridge'); ?>
        </p>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Palette de marque', 'pratcom-connect-bridge'); ?></h2>
            <p style="color: var(--pc-text-muted); margin: 0 0 18px 0;">
                <?php esc_html_e('Choisissez la couleur principale de votre marque. Le texte des boutons est calcule automatiquement pour respecter le contraste (accessibilite WCAG).', 'pratcom-connect-bridge'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pratcom_connect_bridge_save_theme" />
                <?php wp_nonce_field(self::NONCE_THEME); ?>

                <div class="pc-form-field">
                    <label for="pratcom_primary" class="pc-form-label">
                        <?php esc_html_e('Couleur principale', 'pratcom-connect-bridge'); ?>
                    </label>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <input type="color" id="pratcom_primary" name="primary"
                            value="<?php echo esc_attr($primary); ?>"
                            style="width:48px;height:40px;padding:0;border:1px solid #d0d7dc;border-radius:8px;cursor:pointer;" />
                        <input type="text" id="pratcom_primary_hex" class="pc-form-input"
                            value="<?php echo esc_attr($primary); ?>" style="max-width:140px;"
                            pattern="^#[0-9a-fA-F]{6}$" autocomplete="off" />
                        <span id="pratcom_preview"
                            style="display:inline-flex;align-items:center;justify-content:center;min-width:130px;height:40px;padding:0 16px;border-radius:10px;font-weight:600;font-size:13px;">
                            <?php esc_html_e('Tout accepter', 'pratcom-connect-bridge'); ?>
                        </span>
                    </div>
                    <p class="pc-form-help">
                        <?php esc_html_e('Format hexadecimal, ex : #99BF38.', 'pratcom-connect-bridge'); ?>
                    </p>
                </div>

                <div class="pc-actions">
                    <button type="submit" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Enregistrer les couleurs', 'pratcom-connect-bridge'); ?>
                    </button>
                </div>
            </form>
        </div>

        <script>
        (function () {
            var picker = document.getElementById('pratcom_primary');
            var hex = document.getElementById('pratcom_primary_hex');
            var prev = document.getElementById('pratcom_preview');
            function lum(h) {
                h = h.replace('#', ''); if (h.length !== 6) return 1;
                var n = parseInt(h, 16), r = (n >> 16 & 255) / 255, g = (n >> 8 & 255) / 255, b = (n & 255) / 255;
                function c(v) { return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }
                return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b);
            }
            function apply(v) {
                if (!/^#[0-9a-fA-F]{6}$/.test(v)) return;
                prev.style.background = v;
                prev.style.color = lum(v) > 0.45 ? '#10222b' : '#ffffff';
            }
            if (picker && hex && prev) {
                picker.addEventListener('input', function () { hex.value = picker.value; apply(picker.value); });
                hex.addEventListener('input', function () { if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) { picker.value = hex.value; apply(hex.value); } });
                apply(picker.value);
            }
        })();
        </script>
        <?php
    }

    private function section_connection(): void
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
        <h1 class="pc-content__title"><?php esc_html_e('Connexion', 'pratcom-connect-bridge'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Gerez la liaison entre ce site et Pratcom Connect.', 'pratcom-connect-bridge'); ?>
        </p>

        <?php if ($connected): ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Connexion active', 'pratcom-connect-bridge'); ?></h2>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Workspace', 'pratcom-connect-bridge'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($workspace_slug); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Workspace ID', 'pratcom-connect-bridge'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($workspace_id); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Cle API', 'pratcom-connect-bridge'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($prefix); ?>&hellip;<?php echo esc_html($last_four); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Dernier handshake', 'pratcom-connect-bridge'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($last_handshake ?: '—'); ?></span>
                </div>

                <div class="pc-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="pratcom_connect_bridge_check_now" />
                        <?php wp_nonce_field(self::NONCE_CHECK); ?>
                        <button type="submit" class="pc-btn pc-btn--secondary">
                            <?php esc_html_e('Verifier maintenant', 'pratcom-connect-bridge'); ?>
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="pratcom_connect_bridge_disconnect" />
                        <?php wp_nonce_field(self::NONCE_DISCONNECT); ?>
                        <button type="submit" class="pc-btn pc-btn--danger"
                            onclick="return confirm('<?php echo esc_js(__('Confirmer la deconnexion ?', 'pratcom-connect-bridge')); ?>')">
                            <?php esc_html_e('Se deconnecter', 'pratcom-connect-bridge'); ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php elseif ($has_key && in_array($status, ['error', 'revoked'], true)): ?>
            <div class="pc-card" style="border-left: 4px solid var(--pc-danger);">
                <h2 class="pc-card__title">
                    <?php echo esc_html($status === 'revoked'
                        ? __('Cle revoquee', 'pratcom-connect-bridge')
                        : __('Erreur de connexion', 'pratcom-connect-bridge')); ?>
                </h2>
                <p style="color: var(--pc-text-muted);"><?php echo esc_html($last_error); ?></p>
                <p><span class="pc-card__value"><?php echo esc_html($prefix); ?>&hellip;<?php echo esc_html($last_four); ?></span></p>
                <div class="pc-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="pratcom_connect_bridge_check_now" />
                        <?php wp_nonce_field(self::NONCE_CHECK); ?>
                        <button type="submit" class="pc-btn pc-btn--primary">
                            <?php esc_html_e('Re-essayer', 'pratcom-connect-bridge'); ?>
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="pratcom_connect_bridge_disconnect" />
                        <?php wp_nonce_field(self::NONCE_DISCONNECT); ?>
                        <button type="submit" class="pc-btn pc-btn--danger">
                            <?php esc_html_e('Effacer la cle', 'pratcom-connect-bridge'); ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Connecter ce site', 'pratcom-connect-bridge'); ?></h2>
                <p style="color: var(--pc-text-muted); margin: 0 0 16px 0;">
                    <?php esc_html_e('Collez la cle API fournie par Pratcom Media. Format : pck_workspace_xxxx (48 caracteres).', 'pratcom-connect-bridge'); ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pratcom_connect_bridge_connect" />
                    <?php wp_nonce_field(self::NONCE_CONNECT); ?>

                    <div class="pc-form-field">
                        <label for="pratcom_api_key" class="pc-form-label">
                            <?php esc_html_e('Cle API', 'pratcom-connect-bridge'); ?>
                        </label>
                        <input type="password" id="pratcom_api_key" name="pratcom_api_key" class="pc-form-input"
                            placeholder="pck_workspace-slug_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                            autocomplete="off" required />
                        <p class="pc-form-help">
                            <?php esc_html_e('Cette cle est stockee localement et utilisee uniquement pour authentifier ce site aupres de l\'API Pratcom Connect.', 'pratcom-connect-bridge'); ?>
                        </p>
                    </div>

                    <div class="pc-actions">
                        <button type="submit" class="pc-btn pc-btn--primary">
                            <?php esc_html_e('Connecter', 'pratcom-connect-bridge'); ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        <?php
    }

    private function section_help(): void
    {
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Aide', 'pratcom-connect-bridge'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Documentation, support et informations systeme.', 'pratcom-connect-bridge'); ?>
        </p>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Documentation', 'pratcom-connect-bridge'); ?></h2>
            <p style="color: var(--pc-text-muted); margin: 0 0 12px 0;">
                <?php esc_html_e('Guide complet d\'installation, de connexion et de gestion des modules.', 'pratcom-connect-bridge'); ?>
            </p>
            <div class="pc-actions">
                <a href="https://docs.pratcom.net/connect/bridge" target="_blank" rel="noopener" class="pc-btn pc-btn--secondary">
                    <?php esc_html_e('Ouvrir la documentation', 'pratcom-connect-bridge'); ?>
                </a>
            </div>
        </div>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Support', 'pratcom-connect-bridge'); ?></h2>
            <p style="color: var(--pc-text-muted); margin: 0 0 12px 0;">
                <?php esc_html_e('Pour toute question ou demande de support, contactez Pratcom Media :', 'pratcom-connect-bridge'); ?>
            </p>
            <div class="pc-actions">
                <a href="mailto:support@pratcom.net" class="pc-btn pc-btn--secondary">support@pratcom.net</a>
            </div>
        </div>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Systeme', 'pratcom-connect-bridge'); ?></h2>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Version du plugin', 'pratcom-connect-bridge'); ?></span>
                <span class="pc-card__value">v<?php echo esc_html(PRATCOM_CONNECT_BRIDGE_VERSION); ?></span>
            </div>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('API endpoint', 'pratcom-connect-bridge'); ?></span>
                <span class="pc-card__value"><?php echo esc_html(PRATCOM_CONNECT_BRIDGE_API_BASE); ?></span>
            </div>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Loader URL', 'pratcom-connect-bridge'); ?></span>
                <span class="pc-card__value"><?php echo esc_html(PRATCOM_CONNECT_BRIDGE_LOADER_URL); ?></span>
            </div>
            <div class="pc-actions">
                <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="pc-btn pc-btn--secondary">
                    <?php esc_html_e('Verifier les mises a jour', 'pratcom-connect-bridge'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    public function handle_connect(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_CONNECT);

        $raw_key = isset($_POST['pratcom_api_key']) ? trim(wp_unslash($_POST['pratcom_api_key'])) : '';
        if (!preg_match('/^pck_[a-z0-9-]+_[A-Za-z0-9]{32}$/', $raw_key)) {
            $this->redirect_with_notice(self::SLUG_CONNECTION, 'error', __('Format de cle invalide.', 'pratcom-connect-bridge'));
        }

        $domain = wp_parse_url(home_url(), PHP_URL_HOST);
        $client = new ApiClient();
        $res = $client->handshake($raw_key, $domain);

        if (!($res['ok'] ?? false)) {
            $err = $res['error'] ?? 'http_error';
            $msg = sprintf('%s (HTTP %s)', $err, $res['http_code'] ?? '?');
            update_option(Plugin::OPTION_STATUS, 'error');
            update_option(Plugin::OPTION_LAST_ERROR, $msg);
            $this->redirect_with_notice(self::SLUG_CONNECTION, 'error', $msg);
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
        $this->redirect_with_notice(self::SLUG, 'connected', '');
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
        $this->redirect_with_notice(self::SLUG_CONNECTION, 'disconnected', '');
    }

    public function handle_check_now(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_CHECK);

        $hc = new HealthCheck();
        $res = $hc->run();
        $this->redirect_with_notice(self::SLUG_CONNECTION, 'checked', $res['status'] ?? 'unknown');
    }

    public function handle_save_theme(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_THEME);

        $primary = isset($_POST['primary']) ? sanitize_hex_color(wp_unslash($_POST['primary'])) : '';
        if (!$primary) {
            $this->redirect_with_notice(self::SLUG_APPEARANCE, 'error', __('Couleur invalide.', 'pratcom-connect-bridge'));
        }

        $theme = ['primary' => $primary];
        $on_primary = isset($_POST['on_primary']) ? sanitize_hex_color(wp_unslash($_POST['on_primary'])) : '';
        if ($on_primary) {
            $theme['onPrimary'] = $on_primary;
        }
        update_option(Plugin::OPTION_THEME, $theme);

        // Pousse immediatement au serveur (le handshake embarque le theme).
        if (Plugin::is_connected()) {
            $key = Plugin::get_api_key();
            if ($key) {
                $domain = wp_parse_url(home_url(), PHP_URL_HOST);
                $res = (new ApiClient())->handshake($key, $domain);
                if ($res['ok'] ?? false) {
                    update_option(Plugin::OPTION_FEATURE_PACKS, $res['feature_packs'] ?? get_option(Plugin::OPTION_FEATURE_PACKS, []));
                    update_option(Plugin::OPTION_LAST_HANDSHAKE, current_time('mysql'));
                }
            }
        }

        $this->redirect_with_notice(self::SLUG_APPEARANCE, 'theme_saved', '');
    }

    private function redirect_with_notice(string $page_slug, string $notice, string $msg): void
    {
        $url = add_query_arg([
            'page'           => $page_slug,
            'pratcom_notice' => $notice,
            'pratcom_msg'    => $msg,
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
