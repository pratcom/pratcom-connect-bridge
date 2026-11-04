<?php

namespace Pratcom\Connect\Bridge;

use Pratcom\Connect\Bridge\Http\ApiClient;

/**
 * Health check periodique via WP cron.
 *
 * - WP cron 'pratcom_connect_bridge_check' toutes les heures
 * - Re-fait handshake avec la cle stockee
 * - Si 401 (cle revoquee) : status -> 'revoked' + last_error stocke
 * - Sinon : refresh feature_packs + last_handshake, hotes (`hosts`),
 *   suspension, et reverification a `next_check_at` si elle tombe avant le
 *   prochain passage horaire (un seul handshake par heure)
 *
 * La connexion (ConnectionTab) applique la meme reponse par appliquer() :
 * un site suspendu qui se connecte ne sert rien, sans attendre le cron.
 */
class HealthCheck
{
    public const CRON_HOOK = 'pratcom_connect_bridge_check';

    public function __construct()
    {
        add_action(self::CRON_HOOK, [$this, 'run']);
    }

    /**
     * Programme le cron horaire s'il ne l'est pas. Premier passage dans la
     * minute par defaut (activation : le site est verifie des l'installation),
     * puis toutes les heures. La connexion passe HOUR_IN_SECONDS : son
     * handshake vient d'avoir lieu.
     */
    public static function schedule(int $premier = MINUTE_IN_SECONDS): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + $premier, 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Repousse le passage horaire d'une heure. Appele quand la reverification
     * a `next_check_at` fait un handshake : le cron horaire n'en refait pas
     * un dans la foulee.
     */
    public static function decaler(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if (!$ts) {
            return;
        }
        wp_unschedule_event($ts, self::CRON_HOOK);
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
    }

    /**
     * Applique une reponse de handshake reussie : modules, hotes, suspension
     * (et sa reverification), horodatage, statut.
     *
     * @param array<string, mixed> $res
     */
    public static function appliquer(array $res): void
    {
        update_option(Plugin::OPTION_FEATURE_PACKS, $res['feature_packs'] ?? []);
        Hotes::enregistrer($res['hosts'] ?? null);
        Suspension::enregistrer($res['suspension'] ?? null, $res['next_check_at'] ?? null);
        update_option(Plugin::OPTION_LAST_HANDSHAKE, current_time('mysql'));
        update_option(Plugin::OPTION_STATUS, 'connected');
        delete_option(Plugin::OPTION_LAST_ERROR);
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
            self::appliquer($res);
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
