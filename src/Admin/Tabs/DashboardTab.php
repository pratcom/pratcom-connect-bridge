<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\FeaturePacks;
use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Suspension;

/**
 * Onglet Tableau de bord. Contenu iso-fonctionnel au monolithe (O0).
 * Propriete : chantier Plugin .org.
 */
class DashboardTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect';

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Tableau de bord', 'pratcom-connect');
    }

    public function icon(): string
    {
        return 'dashboard';
    }

    public function render(): void
    {
        $workspace_slug = (string) get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
        $last_handshake = (string) get_option(Plugin::OPTION_LAST_HANDSHAKE, '');
        $connected = Plugin::is_connected();
        // Lus du handshake par FeaturePacks (formes table et liste, `jobs` compris).
        // Libelles (Chat, Formulaires, Privacy, Emplois), pas les cles brutes.
        $active_modules = array_map([FeaturePacks::class, 'libelle'], FeaturePacks::actifs());
        $suspendu = Suspension::est_suspendu();
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Tableau de bord', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Vue d\'ensemble de votre connexion à Pratcom Connect.', 'pratcom-connect'); ?>
        </p>

        <?php if ($connected): ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Connexion active', 'pratcom-connect'); ?></h2>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Workspace', 'pratcom-connect'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($workspace_slug); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Modules actifs', 'pratcom-connect'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($active_modules === [] ? '0' : implode(', ', $active_modules)); ?></span>
                </div>
                <?php if ($suspendu): ?>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('État du site', 'pratcom-connect'); ?></span>
                    <span class="pc-card__value"><?php esc_html_e('Suspendu : les modules ne sont plus affichés aux visiteurs.', 'pratcom-connect'); ?></span>
                </div>
                <?php endif; ?>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Dernier handshake', 'pratcom-connect'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($last_handshake ?: '—'); ?></span>
                </div>
                <div class="pc-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . ModulesTab::PAGE_SLUG)); ?>" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Gérer les modules', 'pratcom-connect'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . AppearanceTab::PAGE_SLUG)); ?>" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e('Apparence', 'pratcom-connect'); ?>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Bienvenue dans Pratcom Connect', 'pratcom-connect'); ?></h2>
                <p style="color: var(--pc-text-muted); margin: 0 0 16px 0;">
                    <?php esc_html_e('Connectez ce site à Pratcom Connect pour activer les modules (Chat IA, Forms, Privacy, Emplois) via une seule clé API fournie par Pratcom Média.', 'pratcom-connect'); ?>
                </p>
                <div class="pc-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . ConnectionTab::PAGE_SLUG)); ?>" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Se connecter', 'pratcom-connect'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . ModulesTab::PAGE_SLUG)); ?>" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e('Voir les modules', 'pratcom-connect'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }
}
