<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Onglet Modules. Contenu iso-fonctionnel au monolithe (O0).
 * La vitrine freemium (modules verrouilles + bouton Activer) arrive en O2.
 * Propriete : chantier Plugin .org.
 */
class ModulesTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-modules';

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Modules', 'pratcom-connect-bridge');
    }

    public function icon(): string
    {
        return 'admin-plugins';
    }

    public function render(): void
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
}
