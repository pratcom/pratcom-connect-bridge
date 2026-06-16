<?php

namespace Pratcom\Connect\Bridge\Admin;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Panneau natif « Gérer dans le tableau de bord » — variante WordPress.org.
 *
 * Dans le build .org (PRATCOM_CONNECT_BRIDGE_CHANNEL === 'org'), les onglets
 * Chat / Formulaires / Confidentialité ne chargent AUCUNE iframe de session
 * connect.pratcom.net dans le wp-admin (revue WordPress.org : les iframes
 * d'application tierce ne sont pas permises hors embeds documentés). À la
 * place, ce panneau affiche le statut de connexion + la clé pck_ (masquée) et
 * un lien sortant vers le tableau de bord Connect — exactement la
 * recommandation du reviewer (« simply link to the external site »).
 *
 * Le build premium (hors annuaire, Plugin Update Checker) garde le miroir
 * iframe (O5) : une SEULE base de code, comportement différencié par la
 * constante de canal. Aucune logique métier ici — lecture des options locales
 * (déjà posées au handshake) + lien sortant, zéro appel serveur.
 *
 * Fichier neuf petit (leçon #4) — jamais d'édition inline du monolithe.
 */
final class OrgManagePanel
{
    /**
     * Rend le panneau natif d'un module géré côté tableau de bord.
     *
     * @param string $title  Titre de la section (déjà traduit).
     * @param string $intro  Phrase d'explication (déjà traduite).
     * @param string $module Segment de route CRM : 'chat' | 'forms' | 'privacy'.
     * @param string $cta    Libellé du bouton (déjà traduit).
     */
    public static function render(string $title, string $intro, string $module, string $cta): void
    {
        $slug      = (string) get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
        $prefix    = (string) get_option(Plugin::OPTION_KEY_PREFIX, '');
        $last_four = (string) get_option(Plugin::OPTION_KEY_LAST_FOUR, '');

        $dashboard_url = $slug !== ''
            ? 'https://connect.pratcom.net/crm/' . rawurlencode($slug) . '/' . rawurlencode($module)
            : 'https://connect.pratcom.net/';
        ?>
        <div class="pc-card" style="margin-top:24px;">
            <h2 class="pc-card__title"><?php echo esc_html($title); ?></h2>

            <?php if ($slug !== ''): ?>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Workspace', 'pratcom-connect'); ?></span>
                <span class="pc-card__value"><?php echo esc_html($slug); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($prefix !== '' || $last_four !== ''): ?>
            <div class="pc-card__row">
                <span class="pc-card__label"><?php esc_html_e('Clé API', 'pratcom-connect'); ?></span>
                <span class="pc-card__value"><?php echo esc_html($prefix); ?>&hellip;<?php echo esc_html($last_four); ?></span>
            </div>
            <?php endif; ?>

            <p class="pc-form-help" style="margin:14px 0 0;">
                <?php echo esc_html($intro); ?>
            </p>

            <div class="pc-actions" style="margin-top:14px;">
                <a href="<?php echo esc_url($dashboard_url); ?>" target="_blank" rel="noopener"
                   class="pc-btn pc-btn--primary">
                    <?php echo esc_html($cta); ?>&nbsp;&#8599;
                </a>
            </div>
        </div>
        <?php
    }
}
