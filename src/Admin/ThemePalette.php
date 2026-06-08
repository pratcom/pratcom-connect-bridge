<?php

namespace Pratcom\Connect\Bridge\Admin;

/**
 * Palette de marque riche pour l'onglet Apparence.
 *
 * Étend le thème (jusqu'ici limité à `primary` / `onPrimary`) avec les clés
 * additionnelles consommées par les modules Pratcom Connect via
 * `workspaces.settings.theme` : primaryDark, secondary, text, font, radius,
 * logoUrl. La forme miroir le type `BrandTheme` de `@pratcom/connect-core`
 * (voir src/theme.ts) afin que le rendu de contraste WCAG soit identique
 * côté serveur (resolveTheme) et runtime (privacy.js / loader Forms).
 *
 * ⚠️ ADDITIF UNIQUEMENT — n'enlève jamais une clé existante du thème.
 * `primary` (et son override `onPrimary`) restent gérés par
 * SettingsPage::section_appearance() / handle_save_theme(). Ce helper ne
 * touche qu'aux clés supplémentaires.
 *
 * Câblage (à appliquer dans SettingsPage.php) :
 *   - section_appearance() : après le champ « Couleur principale », appeler
 *       self::render_fields($theme);   // dans le <form>, avant le bouton submit
 *   - handle_save_theme() : avant update_option(OPTION_THEME, $theme), faire
 *       $theme = array_merge($theme, self::sanitize($_POST));
 */
class ThemePalette
{
    /**
     * Clés couleur additionnelles (hex) — sanitisées via sanitize_hex_color().
     * @var string[]
     */
    private const COLOR_KEYS = ['primaryDark', 'secondary', 'text'];

    /**
     * Sanitise les champs de palette riche présents dans $post et retourne
     * UNIQUEMENT les clés réellement fournies (valeurs vides ignorées, pour
     * ne jamais écraser une valeur dérivée par défaut côté resolveTheme()).
     *
     * @param array<string,mixed> $post  Typiquement $_POST.
     * @return array<string,string>      Clés additives prêtes à fusionner.
     */
    public static function sanitize(array $post): array
    {
        $out = [];

        foreach (self::COLOR_KEYS as $key) {
            if (!isset($post[$key])) {
                continue;
            }
            $hex = sanitize_hex_color(wp_unslash((string) $post[$key]));
            if ($hex) {
                $out[$key] = $hex;
            }
        }

        // Police : on garde une pile de polices CSS courte et inoffensive.
        if (isset($post['font'])) {
            $font = sanitize_text_field(wp_unslash((string) $post['font']));
            if ($font !== '') {
                $out['font'] = $font;
            }
        }

        // Rayon de bordure : accepte un entier (px) ou une valeur CSS simple.
        if (isset($post['radius'])) {
            $radius = trim(sanitize_text_field(wp_unslash((string) $post['radius'])));
            if ($radius !== '' && preg_match('/^\d{1,3}(px|rem|em|%)?$/', $radius)) {
                $out['radius'] = $radius;
            }
        }

        // URL du logo de marque.
        if (isset($post['logoUrl'])) {
            $url = esc_url_raw(wp_unslash((string) $post['logoUrl']));
            if ($url !== '') {
                $out['logoUrl'] = $url;
            }
        }

        return $out;
    }

    /**
     * Rend les champs de palette riche dans le formulaire Apparence.
     * À appeler à l'intérieur du <form> de section_appearance(), après le
     * champ « Couleur principale » et avant le bouton « Enregistrer ».
     *
     * @param array<string,mixed> $theme  Thème courant (Plugin::get_theme()).
     */
    public static function render_fields(array $theme): void
    {
        $td = 'pratcom-connect-bridge';

        $primary_dark = !empty($theme['primaryDark']) ? (string) $theme['primaryDark'] : '';
        $secondary    = !empty($theme['secondary']) ? (string) $theme['secondary'] : '';
        $text         = !empty($theme['text']) ? (string) $theme['text'] : '';
        $font         = !empty($theme['font']) ? (string) $theme['font'] : '';
        $radius       = !empty($theme['radius']) ? (string) $theme['radius'] : '';
        $logo_url     = !empty($theme['logoUrl']) ? (string) $theme['logoUrl'] : '';

        $colors = [
            'primaryDark' => [
                'value' => $primary_dark,
                'label' => __('Couleur principale (foncee)', 'pratcom-connect-bridge'),
                'help'  => __('Survol des boutons et bordures actives. Calculee automatiquement si laissee vide.', 'pratcom-connect-bridge'),
            ],
            'secondary' => [
                'value' => $secondary,
                'label' => __('Couleur secondaire', 'pratcom-connect-bridge'),
                'help'  => __('Accent secondaire. Retombe sur la couleur principale si vide.', 'pratcom-connect-bridge'),
            ],
            'text' => [
                'value' => $text,
                'label' => __('Couleur du texte', 'pratcom-connect-bridge'),
                'help'  => __('Texte de base des modules. Calculee pour le contraste si vide.', 'pratcom-connect-bridge'),
            ],
        ];
        ?>
        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Palette avancee (optionnel)', 'pratcom-connect-bridge'); ?></h2>
            <p style="color: var(--pc-text-muted); margin: 0 0 18px 0;">
                <?php esc_html_e('Affinez la palette de marque. Tous ces champs sont optionnels : laisses vides, ils sont derives automatiquement de la couleur principale en respectant le contraste WCAG.', 'pratcom-connect-bridge'); ?>
            </p>

            <?php foreach ($colors as $key => $field): ?>
                <div class="pc-form-field">
                    <label for="pratcom_<?php echo esc_attr($key); ?>" class="pc-form-label">
                        <?php echo esc_html($field['label']); ?>
                    </label>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <input type="color" id="pratcom_<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                               value="<?php echo esc_attr($field['value'] ?: '#377ba6'); ?>"
                               style="width:48px;height:40px;padding:0;border:1px solid #d0d7dc;border-radius:8px;cursor:pointer;" />
                        <input type="text" class="pc-form-input" name="<?php echo esc_attr($key); ?>_hex"
                               value="<?php echo esc_attr($field['value']); ?>" style="max-width:140px;"
                               pattern="^#[0-9a-fA-F]{6}$" placeholder="<?php esc_attr_e('(auto)', 'pratcom-connect-bridge'); ?>"
                               autocomplete="off"
                               oninput="var p=this.previousElementSibling; if(/^#[0-9a-fA-F]{6}$/.test(this.value)){p.value=this.value;}" />
                    </div>
                    <p class="pc-form-help"><?php echo esc_html($field['help']); ?></p>
                </div>
            <?php endforeach; ?>

            <div class="pc-form-field">
                <label for="pratcom_font" class="pc-form-label">
                    <?php esc_html_e('Police (pile CSS)', 'pratcom-connect-bridge'); ?>
                </label>
                <input type="text" id="pratcom_font" name="font" class="pc-form-input"
                       value="<?php echo esc_attr($font); ?>"
                       placeholder="Inter, -apple-system, sans-serif" autocomplete="off" />
                <p class="pc-form-help"><?php esc_html_e('Laissez vide pour la police systeme par defaut.', 'pratcom-connect-bridge'); ?></p>
            </div>

            <div class="pc-form-field">
                <label for="pratcom_radius" class="pc-form-label">
                    <?php esc_html_e('Rayon des coins', 'pratcom-connect-bridge'); ?>
                </label>
                <input type="text" id="pratcom_radius" name="radius" class="pc-form-input"
                       value="<?php echo esc_attr($radius); ?>" style="max-width:140px;"
                       pattern="^\d{1,3}(px|rem|em|%)?$" placeholder="10px" autocomplete="off" />
                <p class="pc-form-help"><?php esc_html_e('Ex : 10px. Par defaut 10px si vide.', 'pratcom-connect-bridge'); ?></p>
            </div>

            <div class="pc-form-field">
                <label for="pratcom_logoUrl" class="pc-form-label">
                    <?php esc_html_e('Logo de marque (URL)', 'pratcom-connect-bridge'); ?>
                </label>
                <input type="url" id="pratcom_logoUrl" name="logoUrl" class="pc-form-input"
                       value="<?php echo esc_attr($logo_url); ?>"
                       placeholder="https://exemple.com/logo.svg" autocomplete="off" />
                <p class="pc-form-help"><?php esc_html_e('Affiche par certains modules (optionnel).', 'pratcom-connect-bridge'); ?></p>
            </div>
        </div>
        <?php
    }
}
