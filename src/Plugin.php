<?php

namespace Pratcom\Connect\Bridge;

class Plugin
{
    public const OPTION_API_KEY_PLAINTEXT = 'pratcom_connect_bridge_api_key';
    public const OPTION_KEY_PREFIX = 'pratcom_connect_bridge_key_prefix';
    public const OPTION_KEY_LAST_FOUR = 'pratcom_connect_bridge_key_last_four';
    public const OPTION_WORKSPACE_ID = 'pratcom_connect_bridge_workspace_id';
    public const OPTION_WORKSPACE_SLUG = 'pratcom_connect_bridge_workspace_slug';
    public const OPTION_FEATURE_PACKS = 'pratcom_connect_bridge_feature_packs';
    public const OPTION_LAST_HANDSHAKE = 'pratcom_connect_bridge_last_handshake';
    public const OPTION_STATUS = 'pratcom_connect_bridge_status'; // connected / disconnected / error / revoked
    public const OPTION_LAST_ERROR = 'pratcom_connect_bridge_last_error';
    // Palette de marque riche (BrandTheme) :
    // { primary, onPrimary?, primaryDark?, secondary?, text?, font?, radius?, logoUrl? }
    // Injectee dans window.__pratcomConnect.theme et poussee au serveur au handshake.
    // Stockage via ThemePalette::sanitize() depuis AppearanceTab::handle_save_theme().
    public const OPTION_THEME = 'pratcom_connect_bridge_theme';

    public static function boot(): void
    {
        Privacy\LocalRegistry::maybe_install();

        if (is_admin()) {
            new Admin\AdminShell();
            new Admin\Notices();
            new Privacy\PolicyPage();
            new Privacy\CookiePolicyPage();

            // Lien "Reglages" a cote de "Desactiver" dans la liste des plugins
            $basename = plugin_basename(PRATCOM_CONNECT_BRIDGE_FILE);
            add_filter("plugin_action_links_{$basename}", [self::class, 'add_settings_link']);
        }

        new Loader();
        new HealthCheck();
        new Forms\Shortcode();
        new Privacy\PolicyShortcode();
        new Privacy\CookieDeclaration();
        new Privacy\CookieScan();
        new Privacy\LocalRegistry();
        new Privacy\FreeBanner();
        new Privacy\ConsentMode();
        new Privacy\CustomContent();
        new Http\PagesController();
        // Blocs Gutenberg natifs des pages legales (item J) : enregistres
        // au front ET dans l'editeur (register sur 'init'). Rendu dynamique
        // 100 % cote serveur, delegue aux shortcodes Privacy existants.
        new Blocks\LegalBlocks();
    }

    public static function add_settings_link(array $links): array
    {
        $url = admin_url('admin.php?page=pratcom-connect');
        $settings_link = '<a href="' . esc_url($url) . '">'
            . esc_html__('Reglages', 'pratcom-connect')
            . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public static function on_activate(): void
    {
        if (false === get_option(self::OPTION_STATUS)) {
            update_option(self::OPTION_STATUS, 'disconnected');
        }
        Privacy\LocalRegistry::maybe_install();

        // Auto-creation des pages legales (idempotent : ne duplique jamais une
        // page contenant deja le shortcode). L'utilisateur active le plugin :
        // creation acceptable, conforme au modele Complianz / Cookiebot.
        Privacy\PolicyPage::ensure_page();
        Privacy\CookiePolicyPage::ensure_page();
    }

    public static function on_uninstall(): void
    {
        $options = [
            self::OPTION_API_KEY_PLAINTEXT,
            self::OPTION_KEY_PREFIX,
            self::OPTION_KEY_LAST_FOUR,
            self::OPTION_WORKSPACE_ID,
            self::OPTION_WORKSPACE_SLUG,
            self::OPTION_FEATURE_PACKS,
            self::OPTION_LAST_HANDSHAKE,
            self::OPTION_STATUS,
            self::OPTION_LAST_ERROR,
            self::OPTION_THEME,
            Privacy\PolicyPage::OPTION_PAGE_ID,
            Privacy\CookiePolicyPage::OPTION_PAGE_ID,
            Privacy\LocalPolicy::OPTION_VARS,
            Privacy\LocalPolicy::OPTION_COOKIES,
            Privacy\Presets::OPTION_SELECTED,
            Privacy\CookieScan::OPTION_SCANNED,
            Privacy\LocalRegistry::OPTION_DB_VERSION,
            Privacy\LocalRegistry::OPTION_BANNER_VERSION,
            Privacy\FreeBanner::OPTION_ENABLED,
            Privacy\ConsentMode::OPTION_ENABLED,
            Privacy\CustomContent::OPTION_ENABLED,
            Privacy\CustomContent::OPTION_BLOCKS,
        ];
        foreach ($options as $opt) {
            delete_option($opt);
        }
        // NB : la table {prefix}pratcom_consents est volontairement CONSERVÉE
        // (preuve légale Loi 25 du client — même modèle que Complianz).
        HealthCheck::unschedule();
    }

    public static function is_connected(): bool
    {
        return get_option(self::OPTION_STATUS) === 'connected'
            && !empty(get_option(self::OPTION_WORKSPACE_ID));
    }

    public static function get_workspace_id(): ?string
    {
        $v = get_option(self::OPTION_WORKSPACE_ID);
        return $v ? (string) $v : null;
    }

    public static function get_api_key(): ?string
    {
        $v = get_option(self::OPTION_API_KEY_PLAINTEXT);
        return $v ? (string) $v : null;
    }

    /**
     * Retourne la palette de marque stockee localement (BrandTheme).
     * Toutes les cles sont optionnelles — seul `primary` est requis pour
     * que le handshake pousse le theme au serveur.
     *
     * @return array{primary?: string, onPrimary?: string, primaryDark?: string, secondary?: string, text?: string, font?: string, radius?: string, logoUrl?: string}
     */
    public static function get_theme(): array
    {
        $v = get_option(self::OPTION_THEME, []);
        return is_array($v) ? $v : [];
    }
}
