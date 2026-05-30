<?php

namespace Pratcom\Connect\Bridge;

/**
 * Bootstrap principal du plugin Pratcom Connect Bridge.
 */
class Plugin
{
    public const OPTION_API_KEY_HASH = 'pratcom_connect_bridge_api_key_hash';
    public const OPTION_API_KEY_PREFIX = 'pratcom_connect_bridge_api_key_prefix';
    public const OPTION_WORKSPACE_ID = 'pratcom_connect_bridge_workspace_id';
    public const OPTION_WORKSPACE_SLUG = 'pratcom_connect_bridge_workspace_slug';
    public const OPTION_FEATURE_PACKS = 'pratcom_connect_bridge_feature_packs';
    public const OPTION_LAST_HANDSHAKE = 'pratcom_connect_bridge_last_handshake';
    public const OPTION_STATUS = 'pratcom_connect_bridge_status'; // connected/disconnected/revoked

    public static function boot(): void
    {
        if (is_admin()) {
            new Admin\SettingsPage();
        }

        new Loader();
    }

    public static function on_activate(): void
    {
        // Initialiser les options par defaut si pas existantes
        if (false === get_option(self::OPTION_STATUS)) {
            update_option(self::OPTION_STATUS, 'disconnected');
        }
    }

    public static function on_uninstall(): void
    {
        // Cleanup des options (pas des donnees client cote API: c'est par revoke cote serveur)
        $options = [
            self::OPTION_API_KEY_HASH,
            self::OPTION_API_KEY_PREFIX,
            self::OPTION_WORKSPACE_ID,
            self::OPTION_WORKSPACE_SLUG,
            self::OPTION_FEATURE_PACKS,
            self::OPTION_LAST_HANDSHAKE,
            self::OPTION_STATUS,
        ];
        foreach ($options as $opt) {
            delete_option($opt);
        }
    }
}
