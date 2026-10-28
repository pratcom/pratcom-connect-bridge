<?php

namespace Pratcom\Connect\Bridge;

/**
 * Page « Service suspendu » (mission M4, 28/10).
 *
 * L'etat vient de Pratcom Connect, a la poignee de main horaire (HealthCheck) :
 * champ `suspension` = { etat: "actif" } ou { etat: "suspendu", message_fr,
 * message_en, contact }. Il est garde dans une option (survit aux mises a jour
 * du plugin). Quand `suspendu`, tout visiteur recoit une page sobre en 503 avec
 * `Retry-After`, sauf les administrateurs WordPress connectes ; l'administration,
 * la connexion, l'API REST, AJAX et le cron restent ouverts. Rien n'est detruit :
 * le site reste intact derriere la page. Quand `actif`, l'option est supprimee.
 *
 * Aucun appel entrant : le site n'expose aucune route, il lit son etat.
 */
class Suspension
{
    public const OPTION = 'pratcom_connect_bridge_suspension';
    public const RETRY_AFTER = 3600;

    public function __construct()
    {
        add_action('template_redirect', [self::class, 'servir'], 0);
    }

    /** Ecrit l'etat rendu par la poignee de main. Tout ce qui n'est pas `suspendu` vaut `actif`. */
    public static function enregistrer($suspension): void
    {
        if (!is_array($suspension) || ($suspension['etat'] ?? '') !== 'suspendu') {
            delete_option(self::OPTION);
            return;
        }
        $contact = is_array($suspension['contact'] ?? null) ? $suspension['contact'] : [];
        update_option(self::OPTION, [
            'etat' => 'suspendu',
            'message_fr' => sanitize_text_field((string) ($suspension['message_fr'] ?? '')),
            'message_en' => sanitize_text_field((string) ($suspension['message_en'] ?? '')),
            'contact' => [
                'nom' => sanitize_text_field((string) ($contact['nom'] ?? 'Pratcom Media')),
                'courriel' => sanitize_email((string) ($contact['courriel'] ?? '')),
                'web' => esc_url_raw((string) ($contact['web'] ?? '')),
            ],
        ], false);
    }

    /** @return array|null l'etat garde, ou null si le site est actif */
    public static function etat(): ?array
    {
        $e = get_option(self::OPTION, null);
        return is_array($e) && ($e['etat'] ?? '') === 'suspendu' ? $e : null;
    }

    /** Vrai si la requete courante doit recevoir la page de suspension. */
    public static function doitSuspendre(): bool
    {
        if (self::etat() === null) {
            return false;
        }
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }
        if (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') {
            return false;
        }
        return !current_user_can('manage_options');
    }

    public static function servir(): void
    {
        if (!self::doitSuspendre()) {
            return;
        }
        status_header(503);
        nocache_headers();
        header('Retry-After: ' . self::RETRY_AFTER);
        header('Content-Type: text/html; charset=utf-8');
        echo self::page(self::etat(), get_locale());
        exit;
    }

    /** La page, FR ou EN selon la langue du site. Aucun detail de facturation. */
    public static function page(array $e, string $locale): string
    {
        $en = strpos($locale, 'en') === 0;
        $titre = $en ? 'Service suspended' : 'Service suspendu';
        $message = $en ? ($e['message_en'] ?? '') : ($e['message_fr'] ?? '');
        $c = $e['contact'] ?? [];
        $lignes = [];
        if (!empty($c['courriel'])) {
            $lignes[] = '<a href="mailto:' . esc_attr($c['courriel']) . '">' . esc_html($c['courriel']) . '</a>';
        }
        if (!empty($c['web'])) {
            $lignes[] = '<a href="' . esc_url($c['web']) . '">' . esc_html(preg_replace('#^https?://#', '', $c['web'])) . '</a>';
        }
        return '<!doctype html><html lang="' . ($en ? 'en' : 'fr') . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . esc_html($titre) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:36rem;margin:15vh auto;padding:0 1.5rem;color:#1f2937;line-height:1.6}h1{font-size:1.5rem}</style>'
            . '</head><body><main><h1>' . esc_html($titre) . '</h1><p>' . esc_html($message) . '</p>'
            . '<p>' . esc_html($c['nom'] ?? 'Pratcom Media') . ($lignes ? '<br>' . implode('<br>', $lignes) : '') . '</p>'
            . '</main></body></html>';
    }
}
