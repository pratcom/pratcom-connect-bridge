<?php

namespace Pratcom\Connect\Bridge\Privacy;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Google Consent Mode v2 — signal « consent default » inline (Privacy v2).
 *
 * Emet un snippet INLINE SYNCHRONE dans le <head> a la priorite 0 (avant le
 * loader Pratcom et, surtout, avant le conteneur Google Tag Manager / gtag),
 * de sorte que les balises Google demarrent en mode « denied » tant que le
 * visiteur n'a pas consenti (exigence opt-in de la Loi 25 du Quebec).
 *
 * Le snippet :
 *   - pousse gtag('consent','default', { ... denied ... }) avec wait_for_update ;
 *   - lit le temoin `pratcom_consent` pour refleter le choix d'un visiteur de
 *     retour (aucune banniere re-affichee, pas de scintillement) ;
 *   - pose ads_data_redaction + url_passthrough.
 *
 * privacy.js (charge ensuite) emet le `consent update` a chaque choix et au
 * retour d'un visiteur deja consenti. Le mapping categorie -> signaux Google
 * est IDENTIQUE a celui de privacy.js (CM_DEFAULT_MAP).
 *
 * 100 % cote client : aucune donnee serveur interpolee, aucun appel externe
 * (compatible canal WordPress.org).
 *
 * COUPLAGE — OFF par defaut (deploiement sur). A n'activer que la ou privacy.js
 * emet aussi les `update` :
 *   - tier Connect : activer « Consent Mode » dans les reglages Privacy du
 *     compte (cle serveur privacy_configs.settings.consentModeV2) ;
 *   - mode Free : FreeBanner peuple consentModeV2 dans la config locale.
 * Activation : option `pratcom_connect_consent_mode` = '1', ou filtre
 * `pratcom_connect_consent_mode`.
 */
class ConsentMode
{
    public const OPTION_ENABLED = 'pratcom_connect_consent_mode';

    public function __construct()
    {
        // Priorite 0 : avant le Loader (5) et avant GTM/gtag du theme.
        add_action('wp_head', [$this, 'print_default_signal'], 0);
    }

    /**
     * Le Consent Mode est-il actif pour ce site ? OFF par defaut ; surchargeable
     * via le filtre `pratcom_connect_consent_mode`.
     */
    public static function is_enabled(): bool
    {
        $enabled = get_option(self::OPTION_ENABLED, '0') === '1';
        return (bool) apply_filters('pratcom_connect_consent_mode', $enabled);
    }

    /** Le module Privacy est-il actif (tier Connect OU banniere Free) ? */
    private static function privacy_active(): bool
    {
        if (Plugin::is_connected()) {
            $packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
            if (is_array($packs)
                && (array_key_exists('privacy', $packs) || in_array('privacy', $packs, true))
            ) {
                return true;
            }
        }
        return FreeBanner::is_active();
    }

    public function print_default_signal(): void
    {
        if (is_admin() || !self::is_enabled() || !self::privacy_active()) {
            return;
        }
        wp_print_inline_script_tag($this->snippet());
    }

    /**
     * Snippet JS statique (aucune donnee serveur interpolee).
     * Mapping IDENTIQUE a privacy.js CM_DEFAULT_MAP.
     */
    private function snippet(): string
    {
        return <<<'JS'
/* Pratcom Connect — Google Consent Mode v2 (consent default, Loi 25) */
(function (w, d) {
  w.dataLayer = w.dataLayer || [];
  function gtag() { w.dataLayer.push(arguments); }
  var MAP = {
    necessary: ['security_storage', 'functionality_storage'],
    preferences: ['personalization_storage'],
    statistics: ['analytics_storage'],
    marketing: ['ad_storage', 'ad_user_data', 'ad_personalization']
  };
  var SIG = ['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage', 'functionality_storage', 'personalization_storage', 'security_storage'];
  function stored() {
    try {
      var m = d.cookie.match(/(?:^|;\s*)pratcom_consent=([^;]+)/);
      if (!m) return null;
      var raw = decodeURIComponent(m[1]);
      var i = raw.indexOf(':');
      var flags = i < 0 ? raw : raw.slice(i + 1);
      var out = {};
      flags.split('&').forEach(function (p) {
        var kv = p.split('=');
        if (kv[0]) out[kv[0]] = (kv[1] === '1');
      });
      return out;
    } catch (e) { return null; }
  }
  var choice = stored();
  var state = {};
  for (var i = 0; i < SIG.length; i++) state[SIG[i]] = 'denied';
  for (var cat in MAP) {
    if (!MAP.hasOwnProperty(cat)) continue;
    var ok = cat === 'necessary' ? true : !!(choice && choice[cat]);
    if (ok) { for (var j = 0; j < MAP[cat].length; j++) state[MAP[cat][j]] = 'granted'; }
  }
  state.wait_for_update = 500;
  gtag('consent', 'default', state);
  gtag('set', 'ads_data_redaction', true);
  gtag('set', 'url_passthrough', true);
}(window, document));
JS;
    }
}
