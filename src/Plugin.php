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
    // Palette de marque (presentation) : { primary: '#hex', onPrimary?: '#hex' }
    // Injectee dans window.__pratcomConnect.theme et poussee au serveur au handshake.
    public const OPTION_THEME = 'pratcom_connect_bridge_theme';

    public static function boot(): void
    {
        if (is_admin()) {
            new Admin\SettingsPage();
            new Admin\Notices();

            // Lien "Reglages" a cote de "Desactiver" dans la liste des plugins
            $basename = plugin_basename(PRATCOM_CONNECT_BRIDGE_FILE);
            add_filter("plugin_action_links_{$basename}", [self::class, 'add_settings_link']);
        }

        new Loader();
        new HealthCheck();
    }

    public static function add_settings_link(array $links): array
    {
        $url = admin_url('admin.php?page=pratcom-connect');
        $settings_link = '<a href="' . esc_url($url) . '">'
            . esc_html__('Reglages', 'pratcom-connect-bridge')
            . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public static function on_activate(): void
    {
        if (false === get_option(self::OPTION_STATUS)) {
            update_option(self::OPTION_STATUS, 'disconnected');
        }
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
        ];
        foreach ($options as $opt) {
            delete_option($opt);
        }
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

    /** @return array{primary?: string, onPrimary?: string} */
    public static function get_theme(): array
    {
        $v = get_option(self::OPTION_THEME, []);
        return is_array($v) ? $v : [];
    }
}
