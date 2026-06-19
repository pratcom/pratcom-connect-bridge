<?php

namespace Pratcom\Connect\Bridge\Privacy;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Declaration de temoins autonome, style Cookiebot — shortcode
 * [pratcom_cookie_declaration lang=""] (Privacy Free, spec legal pages org).
 *
 * Rend un tableau GROUPE PAR CATEGORIE (necessaires, preferences,
 * fonctionnels, statistiques, marketing, non classes) : chaque categorie a
 * un en-tete + une courte description, puis les colonnes Nom · Fournisseur ·
 * Finalite · Duree, le tout bilingue (fr/en).
 *
 * SOURCE des lignes = CookieScan::grouped_rows() : fusion dedupliquee des
 * presets selectionnes + liste manuelle (LocalPolicy::OPTION_COOKIES) + noms
 * detectes par le mini-scan local. Point unique de fusion partage avec le
 * tableau integre a la politique.
 *
 * LOCAL-FIRST (miroir de PolicyShortcode) : si le site est connecte ET que le
 * pack privacy est actif, on PEUT tenter le fragment serveur ; sinon (et par
 * defaut en gratuit) rendu 100 % local, zero appel serveur (exigence WP.org).
 *
 * Zone chantier Privacy. Ne touche pas le shell admin ni le canal connecte.
 */
class CookieDeclaration
{
    private const TRANSIENT_PREFIX = 'pratcom_cookiedecl_html_';
    private const CACHE_TTL = HOUR_IN_SECONDS;

    public function __construct()
    {
        add_shortcode('pratcom_cookie_declaration', [$this, 'render']);
    }

    private function privacy_pack_active(): bool
    {
        $packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        if (!is_array($packs)) {
            return false;
        }
        return array_key_exists('privacy', $packs) || in_array('privacy', $packs, true);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function render($atts): string
    {
        $atts = shortcode_atts(['lang' => ''], $atts, 'pratcom_cookie_declaration');

        $lang = strtolower((string) $atts['lang']);
        if (!in_array($lang, ['fr', 'en'], true)) {
            // Suit la langue de la page (WPML/Polylang via determine_locale).
            $lang = (strpos(determine_locale(), 'en') === 0) ? 'en' : 'fr';
        }

        if (Plugin::is_connected() && $this->privacy_pack_active()) {
            $html = $this->fetch_remote($lang);
            if ($html !== null) {
                return $html;
            }
        }

        return self::render_local($lang);
    }

    /**
     * Rendu LOCAL de la declaration complete (titre + intro + tableaux par
     * categorie + note de mise a jour). Aucun appel serveur.
     */
    public static function render_local(string $lang): string
    {
        $lang = $lang === 'en' ? 'en' : 'fr';
        $groups = CookieScan::grouped_rows($lang);

        $title = $lang === 'en' ? 'Cookie Declaration' : 'Déclaration relative aux témoins';
        $intro = $lang === 'en'
            ? 'This declaration lists the cookies and similar technologies used on this website, grouped by category. Non-necessary cookies are blocked until you give your consent through the cookie management window.'
            : 'Cette déclaration présente les témoins (cookies) et technologies similaires utilisés sur ce site, regroupés par catégorie. Les témoins non nécessaires sont bloqués tant que vous n\'avez pas donné votre consentement au moyen de la fenêtre de gestion des témoins.';
        $updated = $lang === 'en'
            ? 'Last updated: ' . date_i18n('F j, Y')
            : 'Dernière mise à jour : ' . date_i18n('j F Y');

        $out  = '<div class="pratcom-cookie-declaration" lang="' . esc_attr($lang) . '">';
        $out .= '<h2 class="pratcom-cookiedecl-title">' . esc_html($title) . '</h2>';
        $out .= '<p class="pratcom-cookiedecl-intro">' . esc_html($intro) . '</p>';

        if (!count($groups)) {
            $empty = $lang === 'en'
                ? 'No cookies have been declared yet. Select the presets matching the tools you use, or add them manually, from the Pratcom Connect privacy settings.'
                : 'Aucun témoin n\'a encore été déclaré. Sélectionnez les presets correspondant aux outils que vous utilisez, ou ajoutez-les manuellement, depuis les réglages de confidentialité Pratcom Connect.';
            $out .= '<p class="pratcom-cookiedecl-empty"><em>' . esc_html($empty) . '</em></p>';
            $out .= '</div>';
            return $out;
        }

        $out .= self::render_groups($groups, $lang);
        $out .= '<p class="pratcom-cookiedecl-updated"><small><em>' . esc_html($updated) . '</em></small></p>';
        $out .= '</div>';
        return $out;
    }

    /**
     * Rend les sections par categorie (en-tete + description + tableau).
     * Reutilisable : meme balisage que le tableau integre a la politique.
     *
     * @param array<string, array<int, array{name:string, provider:string, purpose:string, expiry:string, category:string}>> $groups
     */
    public static function render_groups(array $groups, string $lang): string
    {
        $lang = $lang === 'en' ? 'en' : 'fr';
        $labels = CookieScan::category_label($lang);
        $descs  = CookieScan::category_description($lang);
        $head = $lang === 'en'
            ? ['Name', 'Provider', 'Purpose', 'Expiry']
            : ['Nom', 'Fournisseur', 'Finalité', 'Durée'];

        $out = '';
        foreach ($groups as $cat => $list) {
            if (!is_array($list) || !count($list)) {
                continue;
            }
            $label = $labels[$cat] ?? ucfirst((string) $cat);
            $desc  = $descs[$cat] ?? '';

            $out .= '<section class="pratcom-cookiedecl-group pratcom-cookiedecl-group--' . esc_attr((string) $cat) . '">';
            $out .= '<h3 class="pratcom-cookiedecl-cat">' . esc_html($label) . '</h3>';
            if ($desc !== '') {
                $out .= '<p class="pratcom-cookiedecl-catdesc">' . esc_html($desc) . '</p>';
            }
            $out .= '<table class="pratcom-policy-table pratcom-cookiedecl-table"><thead><tr>';
            foreach ($head as $h) {
                $out .= '<th>' . esc_html($h) . '</th>';
            }
            $out .= '</tr></thead><tbody>';
            foreach ($list as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $out .= '<tr><td><code>' . esc_html((string) ($c['name'] ?? '')) . '</code></td>'
                    . '<td>' . esc_html((string) ($c['provider'] ?? '')) . '</td>'
                    . '<td>' . esc_html((string) ($c['purpose'] ?? '')) . '</td>'
                    . '<td>' . esc_html((string) ($c['expiry'] ?? '')) . '</td></tr>';
            }
            $out .= '</tbody></table>';
            $out .= '</section>';
        }
        return $out;
    }

    /** Base des endpoints publics Privacy (alignee sur PolicyShortcode). */
    public static function endpoint_base(): string
    {
        return (string) apply_filters(
            'pratcom_connect_policy_base_url',
            'https://chatbot.pratcom.net'
        );
    }

    /** Fragment HTML du serveur, cache transient 1 h. null = indisponible. */
    private function fetch_remote(string $lang): ?string
    {
        $slug = (string) get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
        if ($slug === '') {
            return null;
        }

        $key = self::TRANSIENT_PREFIX . md5($slug . '|' . $lang . '|' . PRATCOM_CONNECT_BRIDGE_VERSION);
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = self::endpoint_base()
            . '/api/privacy/' . rawurlencode($slug)
            . '/cookies?lang=' . rawurlencode($lang) . '&format=html';
        $response = wp_remote_get($url, ['timeout' => 8]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return null;
        }

        $html = wp_kses_post($body);
        set_transient($key, $html, self::CACHE_TTL);
        return $html;
    }
}
