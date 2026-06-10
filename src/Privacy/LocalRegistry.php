<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * Registre LOCAL de consentements (Privacy Free) — preuve Loi 25 art. 14
 * sans aucun appel serveur Pratcom (exigence .org §5 + zéro coût marginal).
 *
 * - Table dédiée {prefix}pratcom_consents (install via dbDelta, idempotent).
 * - REST POST /wp-json/pratcom-connect/v1/consent : enregistré par la
 *   bannière en mode local (privacy.js data-mode="local"). Public (les
 *   visiteurs ne sont pas connectés), rate-limité par IP, IP jamais en
 *   clair (hash SHA-256 + sel du site).
 * - Export CSV (admin_post, manage_options + nonce, BOM UTF-8 — même
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

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
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
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_consent($request)
    {
        if ($this->rate_limited()) {
            return new \WP_REST_Response(['error' => 'rate_limited'], 429);
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

        $anonymous = substr(sanitize_text_field((string) $request->get_param('anonymousId')), 0, 64);
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
            fputcsv($out, [
                $row['id'] ?? '', $row['created_at'] ?? '', $row['anonymous_id'] ?? '',
                $row['action'] ?? '', $row['choices'] ?? '', $row['banner_version'] ?? '',
                $row['template_version'] ?? '', $row['language'] ?? '', $row['source_url'] ?? '',
                $row['ip_hash'] ?? '', $row['user_agent'] ?? '',
            ]);
        }
        exit;
    }
}
