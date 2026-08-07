<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Admin\ModuleShowcase;

/**
 * Onglet « Emplois » — feature pack Connect Jobs Pro.
 * Etat (lecture seule) de la synchronisation des offres/candidatures vers la CIL.
 * Propriete : chantier Connect Jobs Pro.
 *
 * Module inactif : vitrine verrouillee (W4, upsell conforme WordPress.org),
 * meme motif que Chat et Formulaires — barriere en tete de render(), retour
 * anticipe.
 *
 * AUCUNE garde PRATCOM_CONNECT_BRIDGE_CHANNEL ici, et c'est volontaire : cette
 * garde sert a retirer du build .org les iframes d'application tierce (revue
 * WordPress.org), or cet onglet n'en affiche aucune. Masquer l'onglet par canal
 * priverait de son onglet un client venu du catalogue WordPress.org qui achete
 * ensuite le pack — asymetrie que Chat et Formulaires n'appliquent pas.
 * Arbitrage Super Vigie du 2026-08-06 (voie A).
 */
class JobsTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-jobs';

    // ─── AbstractTab ────────────────────────────────────

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Emplois', 'pratcom-connect');
    }

    public function icon(): string
    {
        return 'businessperson';
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_showcase_assets']);
    }

    /**
     * Assets de la vitrine verrouillee — enqueue page-scoped : uniquement sur
     * l'onglet Emplois. Aucun <style>/<script> inline (conformite WordPress.org).
     */
    public function enqueue_showcase_assets(string $hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        ModuleShowcase::enqueue();
    }

    // ─── Rendu ────────────────────────────────────────

    public function render(): void
    {
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Emplois', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Synchronise vos offres d\'emploi et candidatures Connect Jobs vers Pratcom Connect (chatbot, CRM).', 'pratcom-connect'); ?>
        </p>
        <?php

        if (!Plugin::is_connected() || !$this->jobs_enabled()) {
            $this->render_locked();
            return;
        }

        $this->render_status();
    }

    // ─── Methodes privees ──────────────────────────────────

    /**
     * Le feature pack Emplois est-il actif pour ce site ?
     *
     * Forme de reference : $feature_packs['jobs']['enabled'] — la meme que
     * ModulesTab et FormsTab. NE PAS copier celle de ChatTab
     * (`!empty($packs['chat'])`), qui renvoie vrai meme quand le serveur repond
     * `['enabled' => false]`, un tableau non vide passant le test.
     */
    private function jobs_enabled(): bool
    {
        $packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        return is_array($packs) && !empty($packs['jobs']['enabled']);
    }

    /** Le plugin gratuit Connect Jobs est-il actif (hooks disponibles) ? */
    private function jobs_plugin_active(): bool
    {
        return defined('CONNECT_JOBS_VERSION') || post_type_exists('connect_job');
    }

    /** Vitrine verrouillee : meme composant et memes CTA que Chat et Formulaires. */
    private function render_locked(): void
    {
        ModuleShowcase::render([
            'title'    => __('Connect Jobs Pro', 'pratcom-connect'),
            'subtitle' => __('Vos offres d\'emploi et vos candidatures rejoignent automatiquement votre chatbot et votre CRM.', 'pratcom-connect'),
            'tagline'  => __('Le plugin Connect Jobs reste gratuit et autonome. Le pack Emplois y ajoute le pont vers Pratcom Connect : chaque offre publiée nourrit les réponses de votre assistant, chaque candidature devient un contact dans votre CRM.', 'pratcom-connect'),
            'features' => [
                __('Offres synchronisées vers le moteur du chatbot', 'pratcom-connect'),
                __('Candidatures poussées vers le CRM', 'pratcom-connect'),
                __('Retrait automatique des offres dépubliées', 'pratcom-connect'),
                __('Aucun CV transmis : métadonnées seulement', 'pratcom-connect'),
                __('Lieu, salaire et mentions de conformité normalisés', 'pratcom-connect'),
                __('Bilingue français / anglais', 'pratcom-connect'),
            ],
            'note'     => __('Module fourni par le service Pratcom Connect (abonnement requis).', 'pratcom-connect'),
            'cta_html' => $this->locked_cta_html(),
        ]);
    }

    /** CTA de la vitrine : memes boutons que les autres modules verrouilles. */
    private function locked_cta_html(): string
    {
        ob_start();
        if (Plugin::is_connected()) {
            ?>
            <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=jobs-tab"
               target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                <?php esc_html_e('Activer ce module', 'pratcom-connect'); ?>
            </a>
            <?php
        } else {
            ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . ConnectionTab::PAGE_SLUG)); ?>"
               class="pc-btn pc-btn--primary">
                <?php esc_html_e('Connecter mon compte', 'pratcom-connect'); ?>
            </a>
            <?php
        }
        return (string) ob_get_clean();
    }

    /** Etat de la synchronisation — module actif uniquement. */
    private function render_status(): void
    {
        $jobs_active = $this->jobs_plugin_active();
        ?>
        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('État', 'pratcom-connect'); ?></h2>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Plugin Connect Jobs', 'pratcom-connect'); ?></span>
                <span class="pc-card__value">
                    <?php echo $jobs_active
                        ? esc_html__('Détecté', 'pratcom-connect')
                        : esc_html__('Non détecté', 'pratcom-connect'); ?>
                </span>
            </div>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Connexion Pratcom Connect', 'pratcom-connect'); ?></span>
                <span class="pc-card__value"><?php esc_html_e('Connecté', 'pratcom-connect'); ?></span>
            </div>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Synchronisation', 'pratcom-connect'); ?></span>
                <span class="pc-card__value">
                    <?php echo $jobs_active
                        ? esc_html__('Active', 'pratcom-connect')
                        : esc_html__('En attente', 'pratcom-connect'); ?>
                </span>
            </div>
        </div>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Ce qui est synchronisé', 'pratcom-connect'); ?></h2>
            <ul style="margin: 0; padding-left: 18px; color: var(--pc-text-muted);">
                <li><?php esc_html_e('Offres publiées ou mises à jour → moteur du chatbot (réponses sur les postes ouverts).', 'pratcom-connect'); ?></li>
                <li><?php esc_html_e('Offres dépubliées ou supprimées → retirées automatiquement.', 'pratcom-connect'); ?></li>
                <li><?php esc_html_e('Candidatures reçues → CRM (aucun CV transmis, métadonnées seulement).', 'pratcom-connect'); ?></li>
            </ul>
        </div>

        <?php if (!$jobs_active) : ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Installer Connect Jobs', 'pratcom-connect'); ?></h2>
                <p style="color: var(--pc-text-muted); margin: 0;">
                    <?php esc_html_e('Le module Connect Jobs n\'est pas encore actif sur ce site. Une fois installé et activé, la synchronisation démarre automatiquement.', 'pratcom-connect'); ?>
                </p>
            </div>
        <?php endif; ?>
        <?php
    }
}
