<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;

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
        $feature_packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        if (!is_array($feature_packs)) $feature_packs = [];

        $connected = Plugin::is_connected();
        $active_modules = 0;
        foreach ($feature_packs as $pack) {
            if (is_array($pack) && !empty($pack['enabled'])) $active_modules++;
        }
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Tableau de bord', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Vue d\'ensemble de votre connexion a Pratcom Connect.', 'pratcom-connect'); ?>
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
                    <span class="pc-card__value"><?php echo esc_html((string) $active_modules); ?></span>
                </div>
                <div class="pc-card__row">
                    <span class="pc-card__label"><?php esc_html_e('Dernier handshake', 'pratcom-connect'); ?></span>
                    <span class="pc-card__value"><?php echo esc_html($last_handshake ?: '—'); ?></span>
                </div>
                <div class="pc-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . ModulesTab::PAGE_SLUG)); ?>" class="pc-btn pc-btn--primary">
                        <?php esc_html_e('Gerer les modules', 'pratcom-connect'); ?>
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
                    <?php esc_html_e('Connectez ce site a Pratcom Connect pour activer les modules (Chat IA, Forms, Privacy) via une seule cle API fournie par Pratcom Media.', 'pratcom-connect'); ?>
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
