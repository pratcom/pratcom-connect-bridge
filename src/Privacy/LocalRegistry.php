<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * Registre LOCAL de consentements (Privacy Free), preuve Loi 25 art. 14
 * sans aucun appel serveur Pratcom (exigence .org §5 + zéro coût marginal).
 *
 * - Table dédiée {prefix}pratcom_consents (install via dbDelta, idempotent).
 * - REST POST /wp-json/pratcom-connect/v1/consent : enregistré par la
 *   bannière en mode local (privacy.js data-mode="local"). Public (les
 *   visiteurs ne sont pas connectés), rate-limité par IP, IP jamais en
 *   clair (hash SHA-256 + sel du site). E-24 (27/11) : refusé sans en-tête
 *   Origin du site (le navigateur l'envoie sur tout POST de privacy.js).
 *   Pas de nonce : il casserait les pages mises en cache.
 * - Durée de garde : DUREE_GARDE_MOIS (12 mois, décision du 27/11). Les
 *   lignes plus vieilles sont purgées par lots, sur l'index created_at, par
 *   une tâche quotidienne PROPRE au registre (CRON_PURGE, R2 du 27/11) :
 *   la déconnexion retire le passage horaire de HealthCheck, pas celle-ci.
 *   Programmée à l'activation et, si elle manque (mise à jour depuis une
 *   version qui ne la connaissait pas), au premier chargement ; retirée
 *   seulement à la désactivation et à la désinstallation.
 * - Export CSV (admin_post, manage_options + nonce, BOM UTF-8, même
 *   convention que l'export P3 côté Connect).
 *
 * À la désinstallation, la TABLE est volontairement conservée : c'est une
 * preuve légale du client (même modèle que Complianz). Les options sont
 * nettoyées normalement.
 */
class LocalRegistry
{
    public const DB_VERSION = '1';
    public const OPTION_DB_VERSION = 'pratcom_connect_privacy_registry_db_version';
    public const OPTION_BANNER_VERSION = 'pratcom_connect_privacy_banner_version';
    public const EXPORT_ACTION = 'pratcom_privacy_export_csv';
    private const RATE_LIMIT = 20;          // requêtes max…
    private const RATE_WINDOW = HOUR_IN_SECONDS; // …par IP par heure
    /**
     * E-24, E-29 : plafond pour tout le site, par tranche horaire UTC fixe
     * (filtre `pratcom_connect_registre_plafond_global`).
     */
    public const PLAFOND_GLOBAL = 1000;
    private const CLE_GLOBALE = 'pratcom_consent_rl_global';
    /** Durée de garde d'une preuve de consentement (décision du 27/11). */
    public const DUREE_GARDE_MOIS = 12;
    /** Lignes purgées au plus par requête (borne du DELETE). */
    public const PURGE_LOT = 1000;
    /** Requêtes au plus par passage quotidien : 24 lots, le débit de l'ancien passage horaire. */
    public const PURGE_LOTS_PAR_PASSAGE = 24;
    /** Tâche quotidienne de purge, propre au registre (indépendante de la connexion). */
    public const CRON_PURGE = 'pratcom_connect_registre_purge';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        // Purge sur sa propre tâche quotidienne ; 0 argument : le rappel ne
        // reçoit pas la chaîne vide que do_action passe sinon.
        add_action(self::CRON_PURGE, [self::class, 'purger_passage'], 10, 0);
        // Mise à jour : ni l'activation ni la mise à jour automatique ne
        // relancent le crochet d'activation. La tâche se programme donc ici
        // si elle manque (coût : une lecture du tableau cron, déjà chargé).
        self::programmer_purge();
        // Hors is_admin() : WP-CLI désactive aussi l'extension.
        add_action('deactivate_' . plugin_basename(PRATCOM_CONNECT_BRIDGE_FILE), [self::class, 'deprogrammer_purge'], 10, 0);
        if (is_admin()) {
            add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'handle_export']);
        }
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pratcom_consents';
    }

    /** Création/migration de table, idempotent (appelé au boot, coût = 1 get_option). */
    public static function maybe_install(): void
    {
        if (get_option(self::OPTION_DB_VERSION) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            anonymous_id varchar(64) NOT NULL DEFAULT '',
            action varchar(20) NOT NULL DEFAULT '',
            choices text NOT NULL,
            banner_version varchar(32) NOT NULL DEFAULT '',
            template_version varchar(32) NOT NULL DEFAULT '',
            language varchar(8) NOT NULL DEFAULT '',
            source_url text NOT NULL,
            ip_hash varchar(64) NOT NULL DEFAULT '',
            user_agent varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY anonymous_id (anonymous_id)
        ) {$charset};");
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    public function register_routes(): void
    {
        register_rest_route('pratcom-connect/v1', '/consent', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_consent'],
            'permission_callback' => '__return_true', // public : visiteurs anonymes
        ]);
    }

    private static function ip_hash(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        return hash('sha256', wp_salt('auth') . '|' . $ip);
    }

    /** Plafond par IP (20 par heure). Le plafond global ne joue pas ici. */
    private function rate_limited(): bool
    {
        $key = 'pratcom_consent_rl_' . substr(self::ip_hash(), 0, 32);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return true;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);
        return false;
    }

    /**
     * E-29 : cle du plafond global pour la tranche horaire UTC de `$t`
     * (suffixe AAAAMMJJHH). Une tranche neuve repart a zero.
     */
    public static function cle_globale(int $t): string
    {
        return self::CLE_GLOBALE . '_' . gmdate('YmdH', $t);
    }

    /** E-29 : secondes jusqu'a la fin de la tranche (jamais repoussee). */
    public static function fin_de_tranche(int $t): int
    {
        return HOUR_IN_SECONDS - ($t % HOUR_IN_SECONDS);
    }

    /**
     * E-29 : plafond global, par tranche horaire fixe. Appele APRES le
     * plafond par IP et la validation : une IP bloquee ou une requete
     * invalide ne remplit pas le compteur de tout le site.
     */
    private function plafond_global_atteint(): bool
    {
        $plafond = (int) apply_filters('pratcom_connect_registre_plafond_global', self::PLAFOND_GLOBAL);
        if ($plafond <= 0) {
            return false;
        }
        $t = time();
        $cle = self::cle_globale($t);
        $global = (int) get_transient($cle);
        if ($global >= $plafond) {
            return true;
        }
        set_transient($cle, $global + 1, self::fin_de_tranche($t));
        return false;
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_consent($request)
    {
        if ($this->rate_limited()) {
            return new \WP_REST_Response(['error' => 'rate_limited'], 429);
        }

        // E-24 : avant la validation et le plafond global, pour qu'une
        // requete forgee hors du site ne remplisse pas le compteur commun.
        if (!self::origine_permise($request->get_header('origin'))) {
            return new \WP_REST_Response(['error' => 'invalid_origin'], 403);
        }

        $action = (string) $request->get_param('action');
        if (!in_array($action, ['accept_all', 'refuse_all', 'custom'], true)) {
            return new \WP_REST_Response(['error' => 'invalid_action'], 400);
        }

        $choices = $request->get_param('choices');
        if (!is_array($choices)) {
            return new \WP_REST_Response(['error' => 'invalid_choices'], 400);
        }
        $clean_choices = [];
        foreach ($choices as $cat => $granted) {
            $cat = sanitize_key((string) $cat);
            if (in_array($cat, ['preferences', 'statistics', 'marketing'], true)) {
                $clean_choices[$cat] = (bool) $granted;
            }
        }

        if ($this->plafond_global_atteint()) {
            return new \WP_REST_Response(['error' => 'rate_limited'], 429);
        }

        $anonymous = self::identifiant_anonyme($request->get_param('anonymousId'));
        $lang = substr(sanitize_key((string) $request->get_param('language')), 0, 8);
        $source = esc_url_raw((string) $request->get_param('sourceUrl'));
        $banner_version = substr(sanitize_text_field((string) $request->get_param('configVersion')), 0, 32);
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])), 0, 255)
            : '';

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- ecriture dans la table de registre dediee du plugin.
        $wpdb->insert(self::table(), [
            'created_at'       => current_time('mysql', true),
            'anonymous_id'     => $anonymous,
            'action'           => $action,
            'choices'          => wp_json_encode($clean_choices),
            'banner_version'   => $banner_version !== '' ? $banner_version : (string) get_option(self::OPTION_BANNER_VERSION, '1'),
            'template_version' => LocalPolicy::TEMPLATE_VERSION,
            'language'         => $lang,
            'source_url'       => $source,
            'ip_hash'          => self::ip_hash(),
            'user_agent'       => $ua,
        ]);

        return new \WP_REST_Response(['ok' => true], 201);
    }

    /**
     * E-24 : origine normalisee `schema://hote[:port]` (minuscules, port par
     * defaut retire), ou '' si l'URL n'en a pas.
     */
    public static function origine(string $url): string
    {
        $p = wp_parse_url(trim($url));
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        $schema = strtolower((string) $p['scheme']);
        if ($schema !== 'https' && $schema !== 'http') {
            return '';
        }
        $port = isset($p['port']) ? (int) $p['port'] : 0;
        $defaut = $schema === 'https' ? 443 : 80;
        return $schema . '://' . strtolower((string) $p['host']) . ($port > 0 && $port !== $defaut ? ':' . $port : '');
    }

    /**
     * Origines du site : home_url() et site_url(), plus celles qu'ajoute le
     * filtre `pratcom_connect_registre_origines` (un domaine par langue).
     *
     * @return array<int, string>
     */
    public static function origines_permises(): array
    {
        $liste = [self::origine((string) home_url()), self::origine((string) site_url())];
        $ajout = apply_filters('pratcom_connect_registre_origines', []);
        foreach (is_array($ajout) ? $ajout : [] as $o) {
            $liste[] = self::origine((string) $o);
        }
        return array_values(array_unique(array_filter($liste, static fn(string $o): bool => $o !== '')));
    }

    /**
     * E-24 : le consentement vient d'une page du site. Le navigateur envoie
     * l'en-tete Origin sur tout POST de fetch() ; absent, `null` ou d'un
     * autre site : refuse.
     *
     * @param mixed $entete valeur de l'en-tete Origin
     */
    public static function origine_permise($entete): bool
    {
        if (!is_string($entete) || $entete === '' || strtolower(trim($entete)) === 'null') {
            return false;
        }
        $o = self::origine($entete);
        return $o !== '' && in_array($o, self::origines_permises(), true);
    }

    /** Date UTC (format de created_at) avant laquelle une ligne est purgee. */
    public static function seuil_garde(int $t): string
    {
        return (new \DateTimeImmutable('@' . $t))
            ->modify('-' . self::DUREE_GARDE_MOIS . ' months')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Purge les lignes plus vieilles que la duree de garde : un lot borne par
     * appel (PURGE_LOT), sur l'index created_at. Appele par purger_passage() ;
     * rend le nombre de lignes supprimees.
     */
    public static function purger(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- purge de la table dediee du plugin.
        $n = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE created_at < %s LIMIT %d',
            self::table(),
            self::seuil_garde(time()),
            self::PURGE_LOT
        ));
        return is_int($n) ? $n : 0;
    }

    /**
     * Passage quotidien : purge des lots jusqu'à un lot incomplet, au plus
     * PURGE_LOTS_PAR_PASSAGE requêtes. Rend le total supprimé.
     */
    public static function purger_passage(): int
    {
        $total = 0;
        for ($i = 0; $i < self::PURGE_LOTS_PAR_PASSAGE; $i++) {
            $n = self::purger();
            $total += $n;
            if ($n < self::PURGE_LOT) {
                break;
            }
        }
        return $total;
    }

    /** Programme la purge quotidienne si elle ne l'est pas (idempotent). */
    public static function programmer_purge(): void
    {
        if (!wp_next_scheduled(self::CRON_PURGE)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE);
        }
    }

    /** Retire la purge quotidienne (désactivation, désinstallation seulement). */
    public static function deprogrammer_purge(): void
    {
        $ts = wp_next_scheduled(self::CRON_PURGE);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_PURGE);
        }
    }

    /** Export CSV complet (preuve d'audit), BOM UTF-8 pour Excel. */
    public function handle_export(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission refusée.', 'pratcom-connect'));
        }
        check_admin_referer(self::EXPORT_ACTION);

        global $wpdb;
        $table = self::table();
        // Export d'audit complet sur la table dediee du plugin (nom interne, placeholder %i).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY created_at ASC', $table), ARRAY_A);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="consentements-' . gmdate('Ymd-His') . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel.
        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions -- flux de sortie CSV, pas le systeme de fichiers.
        fputcsv($out, [
            'id', 'created_at_utc', 'anonymous_id', 'action', 'choices',
            'banner_version', 'template_version', 'language', 'source_url',
            'ip_hash', 'user_agent',
        ]);
        foreach ((array) $rows as $row) {
            fputcsv($out, self::ligne_csv((array) $row));
        }
        exit;
    }

    /**
     * Une ligne de l'export, cellules neutralisees (E-23).
     *
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    public static function ligne_csv(array $row): array
    {
        $colonnes = [
            'id', 'created_at', 'anonymous_id', 'action', 'choices', 'banner_version',
            'template_version', 'language', 'source_url', 'ip_hash', 'user_agent',
        ];
        $ligne = [];
        foreach ($colonnes as $c) {
            $ligne[] = self::cellule_csv((string) ($row[$c] ?? ''));
        }
        return $ligne;
    }

    /**
     * E-23 : une cellule qui commence par `=`, `+`, `-`, `@`, une tabulation
     * ou un retour chariot est prefixee d'une apostrophe, pour qu'un tableur
     * la lise comme du texte et jamais comme une formule.
     */
    public static function cellule_csv(string $valeur): string
    {
        if ($valeur !== '' && in_array($valeur[0], ['=', '+', '-', '@', chr(9), chr(13)], true)) {
            return "'" . $valeur;
        }
        return $valeur;
    }

    /**
     * E-24 : l'identifiant anonyme est celui que privacy.js produit (lettres,
     * chiffres, tiret, soulignement, 64 au plus). Toute autre forme est
     * ramenee a ces seuls caracteres : plus de formule, plus de texte libre.
     *
     * @param mixed $brut
     */
    public static function identifiant_anonyme($brut): string
    {
        if (!is_string($brut)) {
            return '';
        }
        return substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $brut), 0, 64);
    }
}
