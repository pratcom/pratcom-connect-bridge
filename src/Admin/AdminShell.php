<?php

namespace Pratcom\Connect\Bridge\Admin;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Admin\Tabs\AbstractTab;
use Pratcom\Connect\Bridge\Admin\Tabs\AppearanceTab;
use Pratcom\Connect\Bridge\Admin\Tabs\ConnectionTab;
use Pratcom\Connect\Bridge\Admin\Tabs\DashboardTab;
use Pratcom\Connect\Bridge\Admin\Tabs\FormsTab;
use Pratcom\Connect\Bridge\Admin\Tabs\HelpTab;
use Pratcom\Connect\Bridge\Admin\Tabs\ModulesTab;

/**
 * Shell admin mince : menu, chrome (header / sidebar / notices) et dispatch
 * vers les classes d'onglet (src/Admin/Tabs/*). Aucune logique metier ici.
 *
 * Propriete : chantier Plugin .org. Le CONTENU des onglets appartient aux
 * chantiers respectifs (Apparence = Privacy, Formulaires = Forms, Chat =
 * Chatbot). Refactor O0 - remplace le monolithe SettingsPage.php.
 */
class AdminShell
{
    public const SLUG = 'pratcom-connect';

    /** @var array<string, AbstractTab> Onglets indexes par slug ; l'ordre = la sidebar. */
    private array $tabs = [];

    public function __construct()
    {
        foreach ($this->make_tabs() as $tab) {
            $this->tabs[$tab->slug()] = $tab;
            $tab->register();
        }

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /** @return AbstractTab[] */
    private function make_tabs(): array
    {
        return [
            new DashboardTab(),
            new ModulesTab(),
            new AppearanceTab(),
            new FormsTab(),
            new ConnectionTab(),
            new HelpTab(),
        ];
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Pratcom Connect', 'pratcom-connect-bridge'),
            __('Pratcom Connect', 'pratcom-connect-bridge'),
            'manage_options',
            self::SLUG,
            [$this, 'render_current'],
            'dashicons-randomize',
            80
        );

        foreach ($this->tabs as $slug => $tab) {
            add_submenu_page(
                self::SLUG,
                $tab->label(),
                $tab->label(),
                'manage_options',
                $slug,
                [$this, 'render_current']
            );
        }
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

        // Styles additifs O2 (vitrine + onglet Formulaires) — fichier separe
        // pour ne jamais reecrire admin.css au complet (lecon #4).
        wp_enqueue_style(
            'pratcom-connect-bridge-admin-o2',
            PRATCOM_CONNECT_BRIDGE_URL . 'assets/css/admin-o2.css',
            ['pratcom-connect-bridge-admin'],
            PRATCOM_CONNECT_BRIDGE_VERSION
        );
    }

    /** Callback unique de rendu : resout l'onglet courant puis rend le chrome. */
    public function render_current(): void
    {
        if (!current_user_can('manage_options')) return;

        $slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : self::SLUG;
        $tab = $this->tabs[$slug] ?? $this->tabs[self::SLUG];

        $this->render_chrome($tab);
    }

    private function render_chrome(AbstractTab $current): void
    {
        $status = get_option(Plugin::OPTION_STATUS, 'disconnected');
        $logo_url = PRATCOM_CONNECT_BRIDGE_URL . 'assets/img/logo-pratcom-connect.svg';

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
                        <?php foreach ($this->tabs as $slug => $tab): ?>
                            <li class="pc-sidebar__item">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>"
                                   class="pc-sidebar__link <?php echo $slug === $current->slug() ? 'is-active' : ''; ?>">
                                    <span class="dashicons dashicons-<?php echo esc_attr($tab->icon()); ?>"></span>
                                    <span><?php echo esc_html($tab->label()); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>

                <main class="pc-content">
                    <?php $this->render_notices(); ?>
                    <?php $current->render(); ?>
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
            'forms_refreshed' => ['success', __('Liste des formulaires actualisee.', 'pratcom-connect-bridge')],
            'error'        => ['error', sprintf(__('Erreur : %s', 'pratcom-connect-bridge'), $msg)],
        ];
        if (!isset($map[$notice])) return;
        [$type, $text] = $map[$notice];
        ?>
        <div class="pc-notice pc-notice--<?php echo esc_attr($type); ?>"><?php echo esc_html($text); ?></div>
        <?php
    }

    /** Redirige vers un onglet avec une notice. Utilise par les onglets. */
    public static function redirect_with_notice(string $page_slug, string $notice, string $msg): void
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
