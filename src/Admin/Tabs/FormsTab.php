<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;

/**
 * Onglet Formulaires (O2) : liste des formulaires du workspace + shortcode
 * copiable par formulaire. UI = chantier Plugin .org ; la data appartient au
 * chantier Forms (API F4 cote serveur, exposee au plugin via la route
 * lecture seule GET /api/bridge/forms — voir demande inter-chantiers).
 *
 * Non connecte ou module inactif : vitrine verrouillee (upsell conforme
 * WordPress.org — dans NOS pages uniquement, jamais de notice globale).
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
        return __('Formulaires', 'pratcom-connect-bridge');
    }

    public function icon(): string
    {
        return 'feedback';
    }

    public function register(): void
    {
        add_action('admin_post_pratcom_connect_bridge_forms_refresh', [$this, 'handle_refresh']);
    }

    public function render(): void
    {
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Formulaires', 'pratcom-connect-bridge'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Formulaires intelligents Connect Forms : inserez-les n\'importe ou avec un shortcode.', 'pratcom-connect-bridge'); ?>
        </p>
        <?php

        if (!Plugin::is_connected() || !$this->forms_enabled()) {
            $this->render_locked();
            return;
        }

        $this->render_list();
    }

    private function forms_enabled(): bool
    {
        $packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        return is_array($packs) && !empty($packs['forms']['enabled']);
    }

    /** Vitrine verrouillee (module non actif ou site non connecte). */
    private function render_locked(): void
    {
        $connected = Plugin::is_connected();
        ?>
        <div class="pc-card pc-module-card--locked">
            <h2 class="pc-card__title">
                <?php esc_html_e('Connect Forms', 'pratcom-connect-bridge'); ?>
                <span class="pc-module-card__badge pc-module-card__badge--locked">
                    <?php esc_html_e('Verrouille', 'pratcom-connect-bridge'); ?>
                </span>
            </h2>
            <p style="color: var(--pc-text-muted); margin: 0 0 8px 0;">
                <?php esc_html_e('Formulaires multi-etapes avec scoring de leads, double opt-in conforme, notifications bilingues et insertion par shortcode — sans aucune extension supplementaire.', 'pratcom-connect-bridge'); ?>
            </p>
            <p class="pc-module-card__note">
                <?php esc_html_e('Module fourni par le service Pratcom Connect (abonnement requis).', 'pratcom-connect-bridge'); ?>
            </p>
            <div class="pc-actions pc-module-card__cta">
                <?php if ($connected): ?>
                    <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=forms-tab" target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Activer ce module', 'pratcom-connect-bridge'); ?>
                    </a>
                <?php else: ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . ConnectionTab::PAGE_SLUG)); ?>" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Connecter mon compte', 'pratcom-connect-bridge'); ?>
                    </a>
                    <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=forms-tab" target="_blank" rel="noopener" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e('Decouvrir Connect Forms', 'pratcom-connect-bridge'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** Liste des formulaires du workspace + shortcodes copiables. */
    private function render_list(): void
    {
        $result = $this->get_forms_cached();

        if (!($result['ok'] ?? false)) {
            // Repli gracieux : la route /api/bridge/forms n'est pas encore
            // deployee (demande inter-chantiers) ou erreur temporaire.
            ?>
            <div class="pc-notice pc-notice--warning">
                <?php esc_html_e('La liste des formulaires est momentanement indisponible. Vous pouvez tout de meme inserer un formulaire avec son shortcode :', 'pratcom-connect-bridge'); ?>
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
                <h2 class="pc-card__title"><?php esc_html_e('Aucun formulaire', 'pratcom-connect-bridge'); ?></h2>
                <p style="color: var(--pc-text-muted); margin: 0 0 12px 0;">
                    <?php esc_html_e('Aucun formulaire n\'est defini pour ce workspace. Creez-en un depuis votre tableau de bord Pratcom Connect.', 'pratcom-connect-bridge'); ?>
                </p>
                <div class="pc-actions">
                    <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=forms-tab" target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Ouvrir Pratcom Connect', 'pratcom-connect-bridge'); ?>
                    </a>
                </div>
            </div>
            <?php
            $this->render_refresh_button();
            return;
        }
        ?>
        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Formulaires du workspace', 'pratcom-connect-bridge'); ?></h2>
            <table class="pc-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Nom', 'pratcom-connect-bridge'); ?></th>
                        <th><?php esc_html_e('Type', 'pratcom-connect-bridge'); ?></th>
                        <th><?php esc_html_e('Statut', 'pratcom-connect-bridge'); ?></th>
                        <th><?php esc_html_e('Shortcode', 'pratcom-connect-bridge'); ?></th>
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
                                        onfocus="this.select();" aria-label="<?php esc_attr_e('Shortcode du formulaire', 'pratcom-connect-bridge'); ?>" />
                                    <button type="button" class="pc-btn pc-btn--secondary pc-copy-shortcode"
                                        data-shortcode="<?php echo esc_attr($shortcode); ?>">
                                        <?php esc_html_e('Copier', 'pratcom-connect-bridge'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="pc-form-help" style="margin-top: 10px;">
                <?php esc_html_e('Astuce : ajoutez lang="en" au shortcode pour forcer la version anglaise, ex. [pratcom_form slug="contact" lang="en"].', 'pratcom-connect-bridge'); ?>
            </p>
        </div>
        <?php
        $this->render_refresh_button();
        ?>
        <script>
        (function () {
            document.querySelectorAll('.pc-copy-shortcode').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var sc = btn.getAttribute('data-shortcode');
                    var done = function () {
                        var prev = btn.textContent;
                        btn.textContent = '<?php echo esc_js(__('Copie !', 'pratcom-connect-bridge')); ?>';
                        setTimeout(function () { btn.textContent = prev; }, 1500);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(sc).then(done);
                    } else {
                        var input = btn.parentNode.querySelector('input');
                        input.select();
                        document.execCommand('copy');
                        done();
                    }
                });
            });
        })();
        </script>
        <?php
    }

    private function render_refresh_button(): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px;">
            <input type="hidden" name="action" value="pratcom_connect_bridge_forms_refresh" />
            <?php wp_nonce_field(self::NONCE_REFRESH); ?>
            <button type="submit" class="pc-btn pc-btn--secondary">
                <?php esc_html_e('Actualiser la liste', 'pratcom-connect-bridge'); ?>
            </button>
        </form>
        <?php
    }

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
