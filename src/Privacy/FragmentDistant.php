<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * Nettoyage du fragment HTML servi par le serveur Privacy (politique,
 * déclaration de témoins) AVANT wp_kses_post et avant la mise en transient.
 *
 * Un CDN placé devant le serveur peut réécrire une réponse text/html :
 * obfuscation des courriels (ancre /cdn-cgi/l/email-protection + data-cfemail)
 * et script anti-bot injecté en fin de corps. wp_kses_post retire les balises
 * <script>/<style>/<noscript> mais garde leur TEXTE (le code s'affiche en clair
 * sur la page), et retire data-cfemail, qui seul permettait de retrouver le
 * courriel. On nettoie donc ici, en trois passes :
 *
 *  1. <script>, <noscript> et <style> retirés avec leur contenu ;
 *  2. courriels obfusqués décodés en lien mailto: (ou retirés si illisibles) ;
 *  3. résidus : liens /cdn-cgi/ restants et libellés « [email protected] ».
 *
 * Idempotent, et un fragment déjà propre ressort identique octet pour octet.
 * wp_kses_post reste appliqué ensuite : c'est la dernière ligne de défense.
 */
final class FragmentDistant
{
    /** Libellés laissés par l'obfuscation quand le décodage JS n'a pas eu lieu. */
    private const LIBELLES_RESIDUELS = [
        '[email&#160;protected]',
        '[email&nbsp;protected]',
        "[email\u{00A0}protected]",
        '[email protected]',
    ];

    /**
     * Rend le fragment nettoyé, ou '' si une expression régulière a échoué
     * (l'appelant traite alors le fragment comme absent et bascule sur le
     * rendu local plutôt que d'afficher un fragment à moitié nettoyé).
     */
    public static function nettoyer(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Passe 1 : blocs exécutables ou de style, contenu compris.
        $html = preg_replace('#<(script|noscript|style)\b[^>]*>.*?</\1\s*>#is', '', $html);
        if ($html === null) {
            return '';
        }

        // Passe 2a : ancre obfusquée portant elle-même data-cfemail.
        $html = preg_replace_callback(
            '#<a\b[^>]*\bdata-cfemail\s*=\s*(["\'])([^"\']*)\1[^>]*>.*?</a\s*>#is',
            static function (array $m): string {
                $courriel = self::decoderCfEmail($m[2]);
                return $courriel === null ? '' : self::lien($courriel, self::echapper($courriel));
            },
            $html
        );
        if ($html === null) {
            return '';
        }

        // Passe 2b : lien mailto: obfusqué (href="/cdn-cgi/l/email-protection#HEX"),
        // dont le libellé peut contenir un <span class="__cf_email__">.
        $html = preg_replace_callback(
            '#<a\b[^>]*\bhref\s*=\s*(["\'])/cdn-cgi/l/email-protection\#([0-9a-f]*)\1[^>]*>(.*?)</a\s*>#is',
            static function (array $m): string {
                $courriel = self::decoderCfEmail($m[2]);
                if ($courriel === null) {
                    return '';
                }
                $libelle = trim(self::remplacerSpans($m[3]));
                return self::lien($courriel, $libelle !== '' ? $libelle : self::echapper($courriel));
            },
            $html
        );
        if ($html === null) {
            return '';
        }

        // Passe 2c : <span class="__cf_email__" data-cfemail="HEX"> isolé → courriel nu.
        $html = self::remplacerSpans($html);

        // Passe 3 : résidus.
        $html = preg_replace('#<(a|span)\b[^>]*\bhref\s*=\s*(["\'])/cdn-cgi/[^>]*>.*?</\1\s*>#is', '', $html);
        if ($html === null) {
            return '';
        }

        return str_replace(self::LIBELLES_RESIDUELS, '', $html);
    }

    /**
     * Décode la valeur data-cfemail (schéma public de Cloudflare) : le premier
     * octet est la clé, chaque octet suivant XOR la clé donne un octet UTF-8.
     * Rend null sur hex vide, impair ou invalide, ou si le résultat n'est pas
     * un courriel plausible.
     */
    public static function decoderCfEmail(string $hex): ?string
    {
        $longueur = strlen($hex);
        if ($longueur < 4 || $longueur % 2 !== 0 || !ctype_xdigit($hex)) {
            return null;
        }

        $cle = hexdec(substr($hex, 0, 2));
        $octets = '';
        for ($i = 2; $i < $longueur; $i += 2) {
            $octets .= chr(hexdec(substr($hex, $i, 2)) ^ $cle);
        }

        if (preg_match('//u', $octets) !== 1 || !self::courrielPlausible($octets)) {
            return null;
        }
        return $octets;
    }

    private static function remplacerSpans(string $html): string
    {
        $resultat = preg_replace_callback(
            '#<span\b[^>]*\bdata-cfemail\s*=\s*(["\'])([^"\']*)\1[^>]*>.*?</span\s*>#is',
            static function (array $m): string {
                $courriel = self::decoderCfEmail($m[2]);
                return $courriel === null ? '' : self::echapper($courriel);
            },
            $html
        );
        return $resultat ?? '';
    }

    private static function courrielPlausible(string $valeur): bool
    {
        if (function_exists('is_email')) {
            return is_email($valeur) !== false;
        }
        return filter_var($valeur, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function lien(string $courriel, string $libelle): string
    {
        return '<a href="mailto:' . self::echapper($courriel) . '">' . $libelle . '</a>';
    }

    private static function echapper(string $valeur): string
    {
        return htmlspecialchars($valeur, ENT_QUOTES, 'UTF-8');
    }
}
