<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Onglet « Emplois » — feature pack Connect Jobs Pro.
 * Etat (lecture seule) de la synchronisation des offres/candidatures vers la CIL.
 * Propriete : chantier Connect Jobs Pro.
 */
class JobsTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-jobs';

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

    /** Le plugin gratuit Connect Jobs est-il actif (hooks disponibles) ? */
    private function jobs_plugin_active(): bool
    {
        return defined('CONNECT_JOBS_VERSION') || post_type_exists('connect_job');
    }

    public function render(): void
    {
        $connected = Plugin::is_connected();
        $jobs_active = $this->jobs_plugin_active();
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Emplois', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Synchronise vos offres d\'emploi et candidatures Connect Jobs vers Pratcom Connect (chatbot, CRM).', 'pratcom-connect'); ?>
        </p>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Etat', 'pratcom-connect'); ?></h2>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Plugin Connect Jobs', 'pratcom-connect'); ?></span>
                <span class="pc-card__value">
                    <?php echo $jobs_active
                        ? esc_html__('Detecte', 'pratcom-connect')
                        : esc_html__('Non detecte', 'pratcom-connect'); ?>
                </span>
            </div>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Connexion Pratcom Connect', 'pratcom-connect'); ?></span>
                <span class="pc-card__value">
                    <?php echo $connected
                        ? esc_html__('Connecte', 'pratcom-connect')
                        : esc_html__('Non connecte', 'pratcom-connect'); ?>
                </span>
            </div>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Synchronisation', 'pratcom-connect'); ?></span>
                <span class="pc-card__value">
                    <?php echo ($connected && $jobs_active)
                        ? esc_html__('Active', 'pratcom-connect')
                        : esc_html__('En attente', 'pratcom-connect'); ?>
                </span>
            </div>
        </div>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Ce qui est synchronise', 'pratcom-connect'); ?></h2>
            <ul style="margin: 0; padding-left: 18px; color: var(--pc-text-muted);">
                <li><?php esc_html_e('Offres publiees ou mises a jour -> moteur du chatbot (reponses sur les postes ouverts).', 'pratcom-connect'); ?></li>
                <li><?php esc_html_e('Offres depubliees ou supprimees -> retirees automatiquement.', 'pratcom-connect'); ?></li>
                <li><?php esc_html_e('Candidatures recues -> CRM (aucun CV transmis, metadonnees seulement).', 'pratcom-connect'); ?></li>
            </ul>
        </div>

        <?php if (!$jobs_active) : ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Installer Connect Jobs', 'pratcom-connect'); ?></h2>
                <p style="color: var(--pc-text-muted); margin: 0;">
                    <?php esc_html_e('Le module Connect Jobs n\'est pas encore actif sur ce site. Une fois installe et active, la synchronisation demarre automatiquement.', 'pratcom-connect'); ?>
                </p>
            </div>
        <?php endif; ?>
        <?php
    }
}
