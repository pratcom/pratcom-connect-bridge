<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;
use Pratcom\Connect\Bridge\Admin\ThemePalette;

/**
 * Onglet Apparence : palette de marque poussee au workspace au handshake.
 *
 * CONTENU = zone du chantier Privacy (source unique du theme :
 * workspaces.settings.theme, consommee par Chat/Forms via resolveTheme).
 * Le shell qui monte cet onglet = chantier Plugin .org.
 *
 * O0 : integre le cablage ThemePalette documente dans la PR Bridge #3
 * (render_fields dans le formulaire + sanitize merge au save) qui devait
 * etre applique "en local" sur le monolithe - le refactor le rend possible
 * par PR normale.
 */
class AppearanceTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-appearance';

    private const NONCE_THEME = 'pratcom_connect_bridge_save_theme';

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Apparence', 'pratcom-connect-bridge');
    }

    public function icon(): string
    {
        return 'art';
    }

    public function register(): void
    {
        add_action('admin_post_pratcom_connect_bridge_save_theme', [$this, 'handle_save_theme']);
    }

    public function render(): void
    {
        $theme = Plugin::get_theme();
        $primary = !empty($theme['primary']) ? $theme['primary'] : '#377ba6';
        $on_primary = !empty($theme['onPrimary']) ? $theme['onPrimary'] : '';
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Apparence', 'pratcom-connect-bridge'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Couleurs de marque utilisees par les modules Pratcom Connect (banniere Privacy, et a venir le chat et les formulaires).', 'pratcom-connect-bridge'); ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="pratcom_connect_bridge_save_theme" />
            <?php wp_nonce_field(self::NONCE_THEME); ?>

            <div class="pc-card">
                <h2 class="pc-card__title"><?php esc_html_e('Palette de marque', 'pratcom-connect-bridge'); ?></h2>
                <p style="color: var(--pc-text-muted); margin: 0 0 18px 0;">
                    <?php esc_html_e('Choisissez la couleur principale de votre marque. Le texte des boutons est calcule automatiquement pour respecter le contraste (accessibilite WCAG).', 'pratcom-connect-bridge'); ?>
                </p>

                <div class="pc-form-field">
                    <label for="pratcom_primary" class="pc-form-label">
                        <?php esc_html_e('Couleur principale', 'pratcom-connect-bridge'); ?>
                    </label>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <input type="color" id="pratcom_primary" name="primary"
                            value="<?php echo esc_attr($primary); ?>"
                            style="width:48px;height:40px;padding:0;border:1px solid #d0d7dc;border-radius:8px;cursor:pointer;" />
                        <input type="text" id="pratcom_primary_hex" class="pc-form-input"
                            value="<?php echo esc_attr($primary); ?>" style="max-width:140px;"
                            pattern="^#[0-9a-fA-F]{6}$" autocomplete="off" />
                        <span id="pratcom_preview"
                            style="display:inline-flex;align-items:center;justify-content:center;min-width:130px;height:40px;padding:0 16px;border-radius:10px;font-weight:600;font-size:13px;">
                            <?php esc_html_e('Tout accepter', 'pratcom-connect-bridge'); ?>
                        </span>
                    </div>
                    <p class="pc-form-help">
                        <?php esc_html_e('Format hexadecimal, ex : #99BF38.', 'pratcom-connect-bridge'); ?>
                    </p>
                </div>
            </div>

            <?php
            // Palette riche (primaryDark/secondary/text/font/radius/logoUrl) -
            // helper additif livre par la PR Bridge #3 (chantier Privacy).
            ThemePalette::render_fields($theme);
            ?>

            <div class="pc-actions">
                <button type="submit" class="pc-btn pc-btn--primary">
                    <?php esc_html_e('Enregistrer les couleurs', 'pratcom-connect-bridge'); ?>
                </button>
            </div>
        </form>

        <script>
        (function () {
            var picker = document.getElementById('pratcom_primary');
            var hex = document.getElementById('pratcom_primary_hex');
            var prev = document.getElementById('pratcom_preview');
            function lum(h) {
                h = h.replace('#', ''); if (h.length !== 6) return 1;
                var n = parseInt(h, 16), r = (n >> 16 & 255) / 255, g = (n >> 8 & 255) / 255, b = (n & 255) / 255;
                function c(v) { return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }
                return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b);
            }
            function apply(v) {
                if (!/^#[0-9a-fA-F]{6}$/.test(v)) return;
                prev.style.background = v;
                prev.style.color = lum(v) > 0.45 ? '#10222b' : '#ffffff';
            }
            if (picker && hex && prev) {
                picker.addEventListener('input', function () { hex.value = picker.value; apply(picker.value); });
                hex.addEventListener('input', function () { if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) { picker.value = hex.value; apply(hex.value); } });
                apply(picker.value);
            }
        })();
        </script>
        <?php
    }

    public function handle_save_theme(): void
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer(self::NONCE_THEME);

        $primary = isset($_POST['primary']) ? sanitize_hex_color(wp_unslash($_POST['primary'])) : '';
        if (!$primary) {
            $this->redirect_with_notice(self::PAGE_SLUG, 'error', __('Couleur invalide.', 'pratcom-connect-bridge'));
        }

        $theme = ['primary' => $primary];
        $on_primary = isset($_POST['on_primary']) ? sanitize_hex_color(wp_unslash($_POST['on_primary'])) : '';
        if ($on_primary) {
            $theme['onPrimary'] = $on_primary;
        }

        // Cablage ThemePalette (PR Bridge #3) : fusionne les cles additives de
        // la palette riche, sanitisees. Additif uniquement - les cles vides ne
        // sont pas ecrites (derivees cote resolveTheme).
        $theme = array_merge($theme, ThemePalette::sanitize($_POST));

        update_option(Plugin::OPTION_THEME, $theme);

        // Pousse immediatement au serveur (le handshake embarque le theme).
        if (Plugin::is_connected()) {
            $key = Plugin::get_api_key();
            if ($key) {
                $domain = wp_parse_url(home_url(), PHP_URL_HOST);
                $res = (new ApiClient())->handshake($key, $domain);
                if ($res['ok'] ?? false) {
                    update_option(Plugin::OPTION_FEATURE_PACKS, $res['feature_packs'] ?? get_option(Plugin::OPTION_FEATURE_PACKS, []));
                    update_option(Plugin::OPTION_LAST_HANDSHAKE, current_time('mysql'));
                }
            }
        }

        $this->redirect_with_notice(self::PAGE_SLUG, 'theme_saved', '');
    }
}
