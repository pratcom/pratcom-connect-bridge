<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

/**
 * Onglet Aide. Contenu iso-fonctionnel au monolithe (O0).
 * Propriete : chantier Plugin .org.
 */
class HelpTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-help';

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Aide', 'pratcom-connect');
    }

    public function icon(): string
    {
        return 'editor-help';
    }

    public function render(): void
    {
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Aide', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Documentation, support et informations systeme.', 'pratcom-connect'); ?>
        </p>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Documentation', 'pratcom-connect'); ?></h2>
            <p style="color: var(--pc-text-muted); margin: 0 0 12px 0;">
                <?php esc_html_e('Guide complet d\'installation, de connexion et de gestion des modules.', 'pratcom-connect'); ?>
            </p>
            <div class="pc-actions">
                <a href="https://docs.pratcom.net/connect/bridge" target="_blank" rel="noopener" class="pc-btn pc-btn--secondary">
                    <?php esc_html_e('Ouvrir la documentation', 'pratcom-connect'); ?>
                </a>
            </div>
        </div>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Support', 'pratcom-connect'); ?></h2>
            <p style="color: var(--pc-text-muted); margin: 0 0 12px 0;">
                <?php esc_html_e('Pour toute question ou demande de support, contactez Pratcom Media :', 'pratcom-connect'); ?>
            </p>
            <div class="pc-actions">
                <a href="mailto:support@pratcom.net" class="pc-btn pc-btn--secondary">support@pratcom.net</a>
            </div>
        </div>

        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Systeme', 'pratcom-connect'); ?></h2>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Version du plugin', 'pratcom-connect'); ?></span>
                <span class="pc-card__value">v<?php echo esc_html(PRATCOM_CONNECT_BRIDGE_VERSION); ?></span>
            </div>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('API endpoint', 'pratcom-connect'); ?></span>
                <span class="pc-card__value"><?php echo esc_html(PRATCOM_CONNECT_BRIDGE_API_BASE); ?></span>
            </div>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Loader URL', 'pratcom-connect'); ?></span>
                <span class="pc-card__value"><?php echo esc_html(PRATCOM_CONNECT_BRIDGE_LOADER_URL); ?></span>
            </div>
            <div class="pc-actions">
                <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="pc-btn pc-btn--secondary">
                    <?php esc_html_e('Verifier les mises a jour', 'pratcom-connect'); ?>
                </a>
            </div>
        </div>
        <?php
    }
}
