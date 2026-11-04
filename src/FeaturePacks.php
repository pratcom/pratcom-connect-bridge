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
    /** Modules que le plugin sait presenter, dans l'ordre d'affichage. */
    public const CONNUS = ['chat', 'forms', 'privacy', 'jobs'];

    /**
     * Cles de modules a presenter : les modules connus, puis toute autre cle
     * que le handshake a renvoyee (forme table ou liste). Plus de liste figee
     * dans les onglets d'administration.
     *
     * @param mixed $packs Valeur brute de l'option (forme table ou liste).
     * @return array<int, string>
     */
    public static function cles_dans($packs): array
    {
        $cles = self::CONNUS;
        if (!is_array($packs)) {
            return $cles;
        }
        foreach ($packs as $index => $valeur) {
            $cle = is_string($index) ? $index : (is_string($valeur) ? $valeur : '');
            $cle = strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $cle));
            if ($cle !== '' && !in_array($cle, $cles, true)) {
                $cles[] = $cle;
            }
        }
        return $cles;
    }

    /** @return array<int, string> */
    public static function cles(): array
    {
        return self::cles_dans(get_option(Plugin::OPTION_FEATURE_PACKS, []));
    }

    /**
     * Modules actifs (memes regles que is_active).
     *
     * @param mixed $packs
     * @return array<int, string>
     */
    public static function actifs_dans($packs): array
    {
        return array_values(array_filter(
            self::cles_dans($packs),
            static fn (string $cle): bool => self::is_active_in($packs, $cle)
        ));
    }

    /** @return array<int, string> */
    public static function actifs(): array
    {
        return self::actifs_dans(get_option(Plugin::OPTION_FEATURE_PACKS, []));
    }

    /**
     * @param string $key Cle de module : `chat`, `forms`, `privacy`, `jobs`...
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
