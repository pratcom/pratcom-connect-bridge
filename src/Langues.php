<?php

namespace Pratcom\Connect\Bridge;

/** Langues du plugin, independantes de celles declarees par WPML/Polylang. */
final class Langues
{
    public const PRISES_EN_CHARGE = ['fr', 'en'];
    public const PAR_DEFAUT = 'fr';

    /**
     * Normalise une langue ou une locale comme le faisait Emplois.
     *
     * code_exact conserve les contrats des rendus et des attributs de
     * shortcode : ni decoupe de locale, ni casse, ni espaces corriges.
     * L'appelant choisit un repli vide quand l'absence de langue est utile
     * (Forms delegue alors le choix au renderer).
     */
    public static function normaliser(
        ?string $lang,
        bool $code_exact = false,
        string $repli = self::PAR_DEFAUT
    ): string {
        if (!$code_exact) {
            $lang = strtolower(substr(trim((string) $lang), 0, 2));
        }

        return in_array($lang, self::PRISES_EN_CHARGE, true) ? $lang : $repli;
    }

    /** Les locales WordPress gardent leur ancien test de prefixe sensible a la casse. */
    public static function depuis_locale(?string $locale): string
    {
        return self::normaliser(substr((string) $locale, 0, 2), code_exact: true);
    }
}
