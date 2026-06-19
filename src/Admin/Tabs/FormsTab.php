<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;
use Pratcom\Connect\Bridge\Admin\OrgManagePanel;
use Pratcom\Connect\Bridge\Admin\AdminShell;
use Pratcom\Connect\Bridge\Admin\ModuleShowcase;

/**
 * Onglet Formulaires (O2) : liste des formulaires du workspace + shortcode
 * copiable par formulaire. UI = chantier Plugin .org ; la data appartient au
 * chantier Forms (API F4 cote serveur, exposee au plugin via la route
 * lecture seule GET /api/bridge/forms — voir demande inter-chantiers).
 *
 * Non connecte ou module inactif : vitrine verrouillee animee (W4, upsell
 * conforme WordPress.org — dans NOS pages uniquement, jamais de notice globale).
 *
 * O5b : section additive render_builder_section() — iframe builder signe.
 */
class FormsTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-forms';

    private const NONCE_REFRESH = 'pratcom_connect_bridge_forms_refresh';
    private const TRANSIENT = 'pratcom_connect_bridge_forms_cache';
    private const CACHE_TTL = 300; // 5 minutes

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Formulaires', 'pratcom-connect');
    }

    public function icon(): string
    {
        return 'feedback';
    }

    public function register(): void
    {
        add_action('admin_post_pratcom_connect_bridge_forms_refresh', [$this, 'handle_refresh']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_forms_scripts']);
    }

    /**
     * JS de l'onglet Formulaires (copie du shortcode, selection au focus) —
     * fichier enqueue page-scoped, remplace le <script> inline historique
     * (revue WordPress.org : pas de balise script brute dans l'admin).
     * Charge aussi les assets de la vitrine verrouillee (W4), page-scoped.
     */
    public function enqueue_forms_scripts(string $hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        wp_enqueue_script(
            'pratcom-connect-bridge-forms-copy',
            PRATCOM_CONNECT_BRIDGE_URL . 'assets/js/forms-copy-shortcode.js',
            [],
            PRATCOM_CONNECT_BRIDGE_VERSION,
            true
        );
        wp_localize_script(
            'pratcom-connect-bridge-forms-copy',
            'pratcomFormsCopy',
            ['copied' => __('Copié !', 'pratcom-connect')]
        );

        // Vitrine verrouillee (W4) : CSS + JS d'animation, meme page uniquement.
        ModuleShowcase::enqueue();
    }

    public function render(): void
    {
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Formulaires', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Formulaires intelligents Connect Forms : insérez-les n\'importe où avec un shortcode.', 'pratcom-connect'); ?>
        </p>
        <?php

        if (!Plugin::is_connected() || !$this->forms_enabled()) {
            $this->render_locked();
            return;
        }

        $this->render_list();
        $this->render_builder_section();
    }

    private function forms_enabled(): bool
    {
        $packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        return is_array($packs) && !empty($packs['forms']['enabled']);
    }

    /** Vitrine verrouillee animee (W4) : demo de formulaire + fonctions + CTA. */
    private function render_locked(): void
    {
        ModuleShowcase::render([
            'title'    => __('Connect Forms', 'pratcom-connect'),
            'subtitle' => __('Des formulaires qui se remplissent, se notent et se routent tout seuls, prêts à coller n\'importe où avec un shortcode.', 'pratcom-connect'),
            'demo'     => 'forms',
            'tagline'  => __('Formulaires multi-étapes avec scoring de leads, double opt-in conforme, notifications bilingues et insertion par shortcode, sans aucune extension supplémentaire.', 'pratcom-connect'),
            'features' => [
                __('Formulaires intelligents', 'pratcom-connect'),
                __('Constructeur visuel', 'pratcom-connect'),
                __('Scoring de leads', 'pratcom-connect'),
                __('Routage automatique', 'pratcom-connect'),
                __('Anti-pourriel (honeypot, délai, Turnstile)', 'pratcom-connect'),
                __('Double opt-in (LCAP)', 'pratcom-connect'),
                __('Multi-étapes', 'pratcom-connect'),
            ],
            'note'     => __('Module fourni par le service Pratcom Connect (abonnement requis).', 'pratcom-connect'),
            'cta_html' => $this->locked_cta_html(),
        ]);
    }

    /** CTA de la vitrine (mêmes boutons que la vitrine historique). */
    private function locked_cta_html(): string
    {
        ob_start();
        if (Plugin::is_connected()) {
            ?>
            <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=forms-tab" target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                <?php esc_html_e('Activer ce module', 'pratcom-connect'); ?>
            </a>
            <?php
        } else {
            ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . ConnectionTab::PAGE_SLUG)); ?>" class="pc-btn pc-btn--primary">
                <?php esc_html_e('Connecter mon compte', 'pratcom-connect'); ?>
            </a>
            <a href="<?php echo esc_url(AdminShell::marketing_url('forms/')); ?>" target="_blank" rel="noopener" class="pc-btn pc-btn--secondary">
                <?php esc_html_e('Découvrir Connect Forms', 'pratcom-connect'); ?>
            </a>
            <?php
        }
        return (string) ob_get_clean();
    }

    /** Liste des formulaires du workspace + shortcodes copiables. */
    private function render_list(): void
    {
        $result = $this->get_forms_cached();

        if (!($result['ok'] ?? false)) {
            ?>
            <div class="pc-notice pc-notice--warning">
                <?php esc_html_e('La liste des formulaires est momentanément indisponible. Vous pouvez tout de même insérer un formulaire avec son shortcode :', 'pratcom-connect'); ?>
                <code>[pratcom_form slug="contact"]</code>
            </div>
            <?php
            $this->render_refresh_button();
            return;
        }

        $forms = $result['forms'] ?? [];
        if (!is_array($forms) || $forms === []) {
            ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Aucun formulaire', 'pratcom-connect'); ?></h2>
                <p style="color: var(--pc-text-muted); margin: 0 0 12px 0;">
                    <?php esc_html_e('Aucun formulaire n\'est défini pour ce workspace. Créez-en un depuis votre tableau de bord Pratcom Connect.', 'pratcom-connect'); ?>
                </p>
                <div class="pc-actions">
                    <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=forms-tab" target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Ouvrir Pratcom Connect', 'pratcom-connect'); ?>
                    </a>
                </div>
            </div>
            <?php
            $this->render_refresh_button();
            return;
        }
        ?>
        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Formulaires du workspace', 'pratcom-connect'); ?></h2>
            <table class="pc-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Nom', 'pratcom-connect'); ?></th>
                        <th><?php esc_html_e('Type', 'pratcom-connect'); ?></th>
                        <th><?php esc_html_e('Statut', 'pratcom-connect'); ?></th>
                        <th><?php esc_html_e('Shortcode', 'pratcom-connect'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forms as $form):
                        if (!is_array($form) || empty($form['slug'])) continue;
                        $slug = (string) $form['slug'];
                        $name = (string) ($form['name'] ?? $slug);
                        $type = (string) ($form['type'] ?? '');
                        $status = (string) ($form['status'] ?? '');
                        $shortcode = '[pratcom_form slug="' . $slug . '"]';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($name); ?></strong><br />
                                <span style="color: var(--pc-text-muted); font-size: 12px;"><?php echo esc_html($slug); ?></span></td>
                            <td><?php echo esc_html($type); ?></td>
                            <td>
                                <?php if ($status !== ''): ?>
                                    <span class="pc-badge-status pc-badge-status--<?php echo esc_attr(sanitize_html_class($status, 'draft')); ?>">
                                        <?php echo esc_html($status); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="pc-shortcode">
                                    <input type="text" readonly value="<?php echo esc_attr($shortcode); ?>"
                                        aria-label="<?php esc_attr_e('Shortcode du formulaire', 'pratcom-connect'); ?>" />
                                    <button type="button" class="pc-btn pc-btn--secondary pc-copy-shortcode"
                                        data-shortcode="<?php echo esc_attr($shortcode); ?>">
                                        <?php esc_html_e('Copier', 'pratcom-connect'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="pc-form-help" style="margin-top: 10px;">
                <?php esc_html_e('Astuce : ajoutez lang="en" au shortcode pour forcer la version anglaise, ex. [pratcom_form slug="contact" lang="en"].', 'pratcom-connect'); ?>
            </p>
        </div>
        <?php
        $this->render_refresh_button();
    }

    private function render_refresh_button(): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px;">
            <input type="hidden" name="action" value="pratcom_connect_bridge_forms_refresh" />
            <?php wp_nonce_field(self::NONCE_REFRESH); ?>
            <button type="submit" class="pc-btn pc-btn--secondary">
                <?php esc_html_e('Actualiser la liste', 'pratcom-connect'); ?>
            </button>
        </form>
        <?php
    }

    // ─── O5b : section builder iframe (additif) ───────────────────

    /**
     * Section additive « Modifier dans le builder » — iframe signee B1.
     * Appele uniquement si connected + forms_enabled (gardes dans render()).
     * Repli gracieux si l'API retourne une erreur.
     */
    private function render_builder_section(): void
    {
        $key = Plugin::get_api_key();
        if (!$key) {
            return;
        }

        // Build .org : pas d'iframe builder dans le wp-admin (revue WordPress.org).
        // La liste des formulaires + shortcodes (render_list) reste native ; le
        // builder se gere via un lien sortant. Le build premium garde l'iframe.
        if (PRATCOM_CONNECT_BRIDGE_CHANNEL === 'org') {
            OrgManagePanel::render(
                __('Modifier dans le builder', 'pratcom-connect'),
                __('La création et la modification de formulaires se font dans le builder de votre tableau de bord Pratcom Connect. Insérez ensuite chaque formulaire avec son shortcode ci-dessus.', 'pratcom-connect'),
                'forms',
                __('Ouvrir le builder', 'pratcom-connect')
            );
            return;
        }

        $res = (new ApiClient())->get_builder_session($key);

        if (!($res['ok'] ?? false) || empty($res['url'])) {
            $code = $res['error'] ?? 'unavailable';
            ?>
            <div class="pc-card" style="margin-top:24px;">
                <h2 class="pc-card__title"><?php esc_html_e('Modifier dans le builder', 'pratcom-connect'); ?></h2>
                <div class="pc-notice pc-notice--warning" style="margin-bottom:12px;">
                    <?php echo esc_html(
                        sprintf(
                            /* translators: %s: error code */
                            __('Le builder de formulaires est momentanément indisponible. (%s)', 'pratcom-connect'),
                            $code
                        )
                    ); ?>
                </div>
                <div class="pc-actions">
                    <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=forms-builder"
                       target="_blank" rel="noopener" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e("Ouvrir l'interface web", 'pratcom-connect'); ?>
                    </a>
                </div>
            </div>
            <?php
            return;
        }

        $src = esc_url($res['url']);
        $crm_url = '';
        if (!empty($res['workspace_slug'])) {
            $crm_url = 'https://connect.pratcom.net/crm/' . rawurlencode((string) $res['workspace_slug']) . '/forms';
        }
        ?>
        <div class="pc-card pc-embed-card" style="margin-top:24px;padding:0;overflow:hidden;">
            <div class="pc-embed-header">
                <span class="pc-embed-header__label">
                    <?php esc_html_e('Modifier dans le builder', 'pratcom-connect'); ?>
                </span>
                <?php if ($crm_url): ?>
                <a href="<?php echo esc_url($crm_url); ?>" target="_blank" rel="noopener"
                   class="pc-embed-header__link">
                    <?php esc_html_e("Ouvrir en plein écran \u{2197}", 'pratcom-connect'); ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="pc-embed-wrap">
                <iframe
                    src="<?php echo esc_url($src); ?>"
                    class="pc-embed-frame"
                    sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
                    allow="clipboard-write"
                    loading="lazy"
                    title="<?php esc_attr_e('Builder de formulaires Pratcom Connect', 'pratcom-connect'); ?>"
                ></iframe>
            </div>
        </div>
        <?php
    }

    // ─── Helpers ────────────────────────────────────

    /** @return array{ok?: bool, forms?: array} */
    private function get_forms_cached(): array
    {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $key = Plugin::get_api_key();
        if (!$key) {
            return ['ok' => false];
        }

        $res = (new ApiClient())->get_forms($key);
        if ($res['ok'] ?? false) {
            set_transient(self::TRANSIENT, $res, self::CACHE_TTL);
        }
        return $res;
    }

    public function handle_refresh(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_REFRESH);

        delete_transient(self::TRANSIENT);
        $this->redirect_with_notice(self::PAGE_SLUG, 'forms_refreshed', '');
    }
}
