<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * Controle du titre <h1> des pages de politique (confidentialite + temoins).
 *
 * Probleme SEO : les fragments de politique (template local OU serveur)
 * commencent par un <h1 class="pratcom-policy-title">. Sur un theme qui rend
 * AUSSI le titre de page en <h1> (Twenty Twenty-Five, bloc wp-block-post-title),
 * la page finit avec deux <h1> -> mauvais pour le SEO (un seul H1 par page).
 *
 * Ce helper agit sur le FRAGMENT du module (jamais sur le titre du theme, qui
 * est hors du shortcode) et normalise son PREMIER <h1> selon le mode :
 *   - none (defaut) : retire le <h1> du module -> le titre de page du theme
 *     reste le H1 unique. (Sur un gabarit SANS titre, ex. page-no-title,
 *     utiliser heading="h1" pour conserver un titre.)
 *   - h1 : comportement historique - conserve le <h1> du module.
 *   - h2 : retrograde le titre du module en <h2> (attributs/classe conserves).
 *
 * Le corps des politiques n'utilise que <h2>/<h3> -> cibler le premier <h1>
 * vise toujours le titre du module, jamais une section.
 */
class PolicyHeading
{
    /** Modes acceptes par l'attribut de shortcode heading="...". */
    public const MODES = ['none', 'h1', 'h2'];

    public const DEFAULT_MODE = 'none';

    /** Normalise la valeur brute de l'attribut heading. */
    public static function sanitize_mode($raw): string
    {
        $mode = strtolower(trim((string) $raw));
        return in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    /**
     * Applique le mode de titre au fragment HTML d'une politique.
     * N'agit que sur le PREMIER <h1> rencontre (le titre du module).
     */
    public static function apply(string $html, string $mode): string
    {
        $mode = self::sanitize_mode($mode);

        // 'h1' = comportement historique ; rien a transformer.
        if ($mode === 'h1' || $html === '' || stripos($html, '<h1') === false) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<h1\b([^>]*)>(.*?)<\/h1>/is',
            static function (array $m) use ($mode): string {
                // 'none' : retire le titre du module (le theme fournit le H1).
                if ($mode === 'none') {
                    return '';
                }
                // 'h2' : retrograde en conservant attributs + contenu.
                return '<h2' . $m[1] . '>' . $m[2] . '</h2>';
            },
            $html,
            1
        );
    }
}
