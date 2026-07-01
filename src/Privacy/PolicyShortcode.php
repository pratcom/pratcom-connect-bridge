<?php

namespace Pratcom\Connect\Bridge\Privacy;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Shortcode [pratcom_privacy_policy lang="fr" heading="none"] — politique de
 * confidentialité dynamique (P4b, spec Privacy §6b, modèle Complianz).
 *
 * - Tier Connect (connecté + pack privacy) : contenu rendu côté serveur
 *   (GET /api/privacy/{slug}/policy?format=html), template maintenu par
 *   Pratcom + tableau des témoins auto-généré depuis le scan. Cache
 *   transient 1 h. En cas de panne API → fallback template local (la
 *   politique doit TOUJOURS rester affichable).
 * - Tier Free / non connecté : template embarqué (LocalPolicy), variables
 *   saisies localement, liste de témoins manuelle. Zéro appel serveur
 *   (exigence WP.org : aucun appel sans action utilisateur en gratuit).
 *
 * Attribut heading="none|h1|h2" (défaut « none ») : contrôle le <h1> du
 * module pour éviter un double <h1> quand le thème rend déjà le titre de page
 * en <h1> (cf. PolicyHeading). « none » = le titre du thème reste le H1
 * unique ; « h1 » = comportement historique (gabarits sans titre de page).
 *
 * Zone chantier Privacy (contenu Confidentialité). Shell admin non touché.
 */
class PolicyShortcode
{
    private const TRANSIENT_PREFIX = 'pratcom_policy_html_';
    private const CACHE_TTL = HOUR_IN_SECONDS;

    public function __construct()
    {
        add_shortcode('pratcom_privacy_policy', [$this, 'render']);
    }

    /** Base des endpoints publics Privacy (filtrable pour staging). */
    public static function endpoint_base(): string
    {
        return (string) apply_filters(
            'pratcom_connect_policy_base_url',
            'https://chatbot.pratcom.net'
        );
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
        $atts = shortcode_atts(['lang' => '', 'heading' => PolicyHeading::DEFAULT_MODE], $atts, 'pratcom_privacy_policy');

        $lang = strtolower((string) $atts['lang']);
        if (!in_array($lang, ['fr', 'en'], true)) {
            $lang = (strpos(get_locale(), 'en') === 0) ? 'en' : 'fr';
        }

        $heading = PolicyHeading::sanitize_mode($atts['heading']);

        if (Plugin::is_connected() && $this->privacy_pack_active()) {
            // Option B (fix tier connecte, 2026-06-30) : si l'admin a saisi les
            // informations d'entreprise EN LOCAL, le rendu local est plus complet
            // que le serveur (qui n'a que legalName -> les autres champs affichent
            // "a completer") et son tableau de temoins est enrichi par les
            // presets/scan locaux. LocalPolicy::render() inclut DEJA CustomContent
            // -> ne PAS re-appender (contrairement au chemin serveur ci-dessous).
            if (LocalPolicy::has_company_info()) {
                return PolicyHeading::apply(LocalPolicy::render($lang), $heading);
            }

            $html = $this->fetch_remote($lang);
            if ($html !== null) {
                // Le serveur ignore le contenu personnalise LOCAL : on l'ajoute
                // ici pour que le tier connecte (pro) en beneficie aussi.
                return PolicyHeading::apply($html . CustomContent::render_section($lang), $heading);
            }
        }

        return PolicyHeading::apply(LocalPolicy::render($lang), $heading);
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
            . '/policy?lang=' . rawurlencode($lang) . '&format=html';
        $response = wp_remote_get($url, ['timeout' => 8]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return null;
        }

        // Le serveur est de confiance, mais on assainit quand même (défense
        // en profondeur) — wp_kses_post conserve sections/titres/tableaux.
        $html = wp_kses_post($body);
        set_transient($key, $html, self::CACHE_TTL);
        return $html;
    }
}
