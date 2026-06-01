<?php

namespace Pratcom\Connect\Bridge;

use Pratcom\Connect\Bridge\Http\ApiClient;

/**
 * Health check periodique via WP cron.
 *
 * - WP cron 'pratcom_connect_bridge_check' toutes les heures
 * - Re-fait handshake avec la cle stockee
 * - Si 401 (cle revoquee) : status -> 'revoked' + last_error stocke
 * - Sinon : refresh feature_packs + last_handshake
 */
class HealthCheck
{
    public const CRON_HOOK = 'pratcom_connect_bridge_check';

    public function __construct()
    {
        add_action(self::CRON_HOOK, [$this, 'run']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    /**
     * Execute le handshake et update le status local.
     * @return array{ok: bool, status: string, error?: string}
     */
    public function run(): array
    {
        $api_key = Plugin::get_api_key();
        if (!$api_key) {
            return ['ok' => false, 'status' => 'disconnected', 'error' => 'no_api_key'];
        }

        $domain = wp_parse_url(home_url(), PHP_URL_HOST);
        $client = new ApiClient();
        $res = $client->handshake($api_key, $domain);

        if ($res['ok'] ?? false) {
            update_option(Plugin::OPTION_FEATURE_PACKS, $res['feature_packs'] ?? []);
            update_option(Plugin::OPTION_LAST_HANDSHAKE, current_time('mysql'));
            update_option(Plugin::OPTION_STATUS, 'connected');
            delete_option(Plugin::OPTION_LAST_ERROR);
            return ['ok' => true, 'status' => 'connected'];
        }

        $http = (int) ($res['http_code'] ?? 0);
        $err = (string) ($res['error'] ?? 'http_error');

        // 401 = cle invalide ou revoquee
        if ($http === 401) {
            update_option(Plugin::OPTION_STATUS, 'revoked');
            update_option(Plugin::OPTION_LAST_ERROR, 'Cle revoquee ou invalide. Contacter Pratcom Media pour une nouvelle cle.');
            return ['ok' => false, 'status' => 'revoked', 'error' => $err];
        }

        // Autres erreurs (network, 5xx, etc) : marquer en erreur mais garder connected si on l'etait avant
        update_option(Plugin::OPTION_STATUS, 'error');
        update_option(Plugin::OPTION_LAST_ERROR, sprintf('%s (HTTP %s)', $err, $http));
        return ['ok' => false, 'status' => 'error', 'error' => $err];
    }
}
