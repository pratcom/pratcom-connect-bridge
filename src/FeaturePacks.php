<?php

namespace Pratcom\Connect\Bridge;

/**
 * Test d'abonnement partage : un feature pack est-il ACTIF pour ce site ?
 *
 * Source unique de verite pour les cinq points de gating du plugin (ChatTab,
 * Forms\Shortcode, Privacy\PolicyShortcode, Privacy\CookieDeclaration,
 * Privacy\ConsentMode + Privacy\FreeBanner). Avant cette classe, chacun
 * portait sa propre copie du test, et aucune ne lisait `enabled` : en PHP,
 * `!empty(['enabled' => false])` vaut TRUE, parce qu'un tableau non vide est
 * truthy. Un abonnement coupe cote serveur restait donc actif cote client.
 *
 * DEUX FORMES DE DONNEES, toutes deux supportees :
 *
 *   1. Forme table (celle que l'API sert aujourd'hui) :
 *        { "chat": { "enabled": true }, "forms": { "enabled": false } }
 *   2. Forme liste (heritee, toleree par les routes de l'API) :
 *        [ "chat", "forms" ]
 *
 * REGLE : `enabled` ABSENT de la forme table vaut ACTIF. C'est volontaire —
 * un serveur qui n'emettrait pas encore la cle couperait sinon tous les
 * modules d'un coup. Seul un `enabled` explicitement faux desactive.
 *
 * Le contrat de la forme table est documente cote API dans
 * `pratcom-connect-api/src/lib/billing/plans.ts` (section « FORME des
 * feature_packs »), qui prescrit exactement ce test.
 */
final class FeaturePacks
{
    /**
     * @param string $key Cle de module : `chat`, `forms` ou `privacy`.
     */
    public static function is_active(string $key): bool
    {
        return self::is_active_in(get_option(Plugin::OPTION_FEATURE_PACKS, []), $key);
    }

    /**
     * Variante testable : meme regle, sur un tableau fourni.
     *
     * @param mixed  $packs Valeur brute de l'option (forme table ou liste).
     * @param string $key   Cle de module.
     */
    public static function is_active_in($packs, string $key): bool
    {
        if (!is_array($packs)) {
            return false;
        }

        // Forme table : la cle est un index associatif.
        if (array_key_exists($key, $packs)) {
            $pack = $packs[$key];

            if (is_array($pack)) {
                // `enabled` absent => actif (voir REGLE ci-dessus).
                if (!array_key_exists('enabled', $pack)) {
                    return true;
                }
                return self::truthy($pack['enabled']);
            }

            // Forme aplatie eventuelle : { "forms": true } / { "forms": false }.
            return self::truthy($pack);
        }

        // Forme liste : [ "forms" ]. Comparaison stricte, comme avant.
        return in_array($key, $packs, true);
    }

    /**
     * Interpretation defensive d'un drapeau d'abonnement.
     *
     * `(bool)` seul ferait passer la chaine "false" pour vraie — un echec dans
     * la mauvaise direction pour une barriere d'abonnement. Les valeurs JSON
     * decodees sont de vrais booleens ; ce filet ne sert qu'aux options
     * heritees ou serialisees en chaine.
     *
     * @param mixed $value
     */
    private static function truthy($value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }
        return (bool) $value;
    }
}
