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

    public static function boot(): void
    {
        if (is_admin()) {
            new Admin\SettingsPage();
            new Admin\Notices();
        }

        new Loader();
        new HealthCheck();
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
}
