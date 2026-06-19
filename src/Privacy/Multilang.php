<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * Détection et pilotage multilingue (WPML / Polylang) — W5, pages légales
 * bilingues. Helper purement défensif : AUCUNE dépendance dure. Tous les
 * appels aux fonctions / filtres / actions WPML et Polylang sont gardés par
 * function_exists / defined / has_filter. Si ni WPML ni Polylang n'est
 * installé, is_active() renvoie false et le plugin retombe sur son
 * comportement mono-page d'origine (zéro fatale).
 *
 * Convention : un « code de langue » est la chaîne courte utilisée par le
 * plugin actif (ex. « fr », « en », « es »). On l'aligne sur l'attribut
 * lang="" du shortcode rendu dans chaque page.
 *
 * Zone chantier Privacy. Ne touche ni le shell admin ni SettingsPage.
 */
class Multilang
{
    public const PROVIDER_NONE = 'none';
    public const PROVIDER_POLYLANG = 'polylang';
    public const PROVIDER_WPML = 'wpml';

    /**
     * Titres de page traduits par défaut, par « rôle » de page légale.
     * Toute langue absente de la table retombe sur EN puis FR (fallback
     * générique demandé par la spec). Filtrable pour ajouter des langues
     * sans toucher au code.
     *
     * @var array<string, array<string, string>>
     */
    private const TITLES = [
        'privacy' => [
            'fr' => 'Politique de confidentialité',
            'en' => 'Privacy Policy',
            'es' => 'Política de privacidad',
            'de' => 'Datenschutzerklärung',
            'it' => 'Informativa sulla privacy',
            'pt' => 'Política de privacidade',
            'nl' => 'Privacybeleid',
        ],
        'cookie' => [
            'fr' => 'Politique relative aux témoins',
            'en' => 'Cookie Policy',
            'es' => 'Política de cookies',
            'de' => 'Cookie-Richtlinie',
            'it' => 'Informativa sui cookie',
            'pt' => 'Política de cookies',
            'nl' => 'Cookiebeleid',
        ],
    ];

    /** Fournisseur multilingue détecté (none / polylang / wpml). */
    public static function provider(): string
    {
        // Polylang : API publique de listing des langues.
        if (function_exists('pll_languages_list') && function_exists('pll_default_language')) {
            return self::PROVIDER_POLYLANG;
        }
        // WPML : constante de version + filtres de langues.
        if (defined('ICL_SITEPRESS_VERSION') && has_filter('wpml_active_languages')) {
            return self::PROVIDER_WPML;
        }
        return self::PROVIDER_NONE;
    }

    /** Un plugin multilingue géré est-il actif ? */
    public static function is_active(): bool
    {
        return self::provider() !== self::PROVIDER_NONE;
    }

    /**
     * Codes de langue actifs (ex. ['fr','en']). Tableau vide si aucun
     * plugin multilingue n'est actif (l'appelant retombe sur le mono-page).
     *
     * @return array<int, string>
     */
    public static function languages(): array
    {
        $provider = self::provider();

        if ($provider === self::PROVIDER_POLYLANG) {
            $list = pll_languages_list(); // codes par défaut
            if (is_array($list)) {
                return array_values(array_filter(array_map('strval', $list)));
            }
            return [];
        }

        if ($provider === self::PROVIDER_WPML) {
            $langs = apply_filters('wpml_active_languages', null, []);
            if (is_array($langs)) {
                // Les clés sont les codes de langue (ex. 'fr', 'en').
                return array_values(array_filter(array_map('strval', array_keys($langs))));
            }
            return [];
        }

        return [];
    }

    /**
     * Code de la langue par défaut du site (ex. 'fr'). Chaîne vide si
     * indéterminée ou aucun plugin multilingue actif.
     */
    public static function default_language(): string
    {
        $provider = self::provider();

        if ($provider === self::PROVIDER_POLYLANG) {
            $def = pll_default_language();
            return is_string($def) ? $def : '';
        }

        if ($provider === self::PROVIDER_WPML) {
            $def = apply_filters('wpml_default_language', null);
            return is_string($def) ? $def : '';
        }

        return '';
    }

    /**
     * Titre de page traduit pour un rôle ('privacy' | 'cookie') et un code
     * de langue. Fallback générique : langue demandée → EN → FR → premier
     * disponible. Filtrable via 'pratcom_connect_legal_page_title'.
     */
    public static function page_title(string $role, string $lang): string
    {
        $map = self::TITLES[$role] ?? self::TITLES['privacy'];
        $short = strtolower(substr($lang, 0, 2));

        if (isset($map[$short]) && $map[$short] !== '') {
            $title = $map[$short];
        } elseif (isset($map['en'])) {
            $title = $map['en'];
        } elseif (isset($map['fr'])) {
            $title = $map['fr'];
        } else {
            $title = (string) reset($map);
        }

        /**
         * Permet d'ajouter / surcharger un titre pour une langue non prévue
         * sans modifier le code.
         *
         * @param string $title Titre par défaut résolu.
         * @param string $role  'privacy' ou 'cookie'.
         * @param string $lang  Code de langue complet d'origine.
         */
        return (string) apply_filters('pratcom_connect_legal_page_title', $title, $role, $lang);
    }

    /**
     * Assigne la langue d'une page et la lie au groupe de traduction.
     * Idempotent et défensif. À appeler APRÈS l'insertion de chaque page de
     * langue.
     *
     * @param int                  $post_id      ID de la page à rattacher.
     * @param string               $lang         Code de langue de cette page.
     * @param array<string, int>   $translations Map code_langue => post_id de
     *                                            TOUTES les traductions déjà
     *                                            connues (incluant celle-ci).
     *                                            Utilisé par Polylang pour lier
     *                                            le groupe en une fois.
     * @param string               $default_lang Code de la langue par défaut
     *                                            (source) — utile à WPML.
     * @param int                  $source_id    ID de la page source (langue
     *                                            par défaut) ; 0 si celle-ci
     *                                            EST la source. Sert à WPML
     *                                            pour partager le même trid.
     */
    public static function link_translation(
        int $post_id,
        string $lang,
        array $translations,
        string $default_lang,
        int $source_id
    ): void {
        if ($post_id <= 0 || $lang === '') {
            return;
        }

        $provider = self::provider();

        if ($provider === self::PROVIDER_POLYLANG) {
            if (function_exists('pll_set_post_language')) {
                pll_set_post_language($post_id, $lang);
            }
            // Lier le groupe : map code => id, uniquement entrées valides.
            if (function_exists('pll_save_post_translations')) {
                $clean = [];
                foreach ($translations as $code => $id) {
                    $id = (int) $id;
                    if (is_string($code) && $code !== '' && $id > 0) {
                        $clean[$code] = $id;
                    }
                }
                if (count($clean) >= 1) {
                    pll_save_post_translations($clean);
                }
            }
            return;
        }

        if ($provider === self::PROVIDER_WPML) {
            // Récupérer le trid de la source (si elle existe déjà), sinon
            // WPML en crée un nouveau quand trid est null.
            $trid = null;
            if ($source_id > 0 && $source_id !== $post_id) {
                $existing = apply_filters('wpml_element_trid', null, $source_id, 'post_page');
                if (is_numeric($existing)) {
                    $trid = (int) $existing;
                }
            }

            do_action('wpml_set_element_language_details', [
                'element_id'           => $post_id,
                'element_type'         => 'post_page',
                'trid'                 => $trid,
                'language_code'        => $lang,
                'source_language_code' => ($source_id > 0 && $source_id !== $post_id && $default_lang !== '')
                    ? $default_lang
                    : null,
            ]);
            return;
        }
    }

    /**
     * Récupère le trid WPML d'une page (groupe de traduction), ou null.
     * Défensif : renvoie null hors WPML.
     */
    public static function wpml_trid(int $post_id): ?int
    {
        if ($post_id <= 0 || self::provider() !== self::PROVIDER_WPML) {
            return null;
        }
        $trid = apply_filters('wpml_element_trid', null, $post_id, 'post_page');
        return is_numeric($trid) ? (int) $trid : null;
    }

    /**
     * ID de la traduction d'une page dans une langue donnée, ou 0 si absente.
     * Utilisé pour l'idempotence (ne pas recréer une page déjà traduite).
     * Défensif : 0 hors plugin multilingue.
     */
    public static function translation_id(int $post_id, string $lang): int
    {
        if ($post_id <= 0 || $lang === '') {
            return 0;
        }
        $provider = self::provider();

        if ($provider === self::PROVIDER_POLYLANG) {
            if (function_exists('pll_get_post')) {
                $tid = pll_get_post($post_id, $lang);
                return is_numeric($tid) ? (int) $tid : 0;
            }
            return 0;
        }

        if ($provider === self::PROVIDER_WPML) {
            $trid = self::wpml_trid($post_id);
            if ($trid === null) {
                return 0;
            }
            $translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_page');
            if (is_array($translations) && isset($translations[$lang])) {
                $entry = $translations[$lang];
                if (is_object($entry) && isset($entry->element_id)) {
                    return (int) $entry->element_id;
                }
                if (is_array($entry) && isset($entry['element_id'])) {
                    return (int) $entry['element_id'];
                }
            }
            return 0;
        }

        return 0;
    }

    /**
     * Code de langue actuellement assigné à une page (ex. 'fr'), ou chaîne
     * vide si non assignée / hors plugin multilingue. Sert à l'idempotence
     * (retrouver une page déjà rattachée à une langue lors d'un balayage).
     * Défensif.
     */
    public static function post_language(int $post_id): string
    {
        if ($post_id <= 0) {
            return '';
        }
        $provider = self::provider();

        if ($provider === self::PROVIDER_POLYLANG) {
            if (function_exists('pll_get_post_language')) {
                $code = pll_get_post_language($post_id, 'slug');
                return is_string($code) ? $code : '';
            }
            return '';
        }

        if ($provider === self::PROVIDER_WPML) {
            $info = apply_filters(
                'wpml_element_language_details',
                null,
                ['element_id' => $post_id, 'element_type' => 'post_page']
            );
            if (is_object($info) && isset($info->language_code) && is_string($info->language_code)) {
                return $info->language_code;
            }
            if (is_array($info) && isset($info['language_code']) && is_string($info['language_code'])) {
                return $info['language_code'];
            }
            return '';
        }

        return '';
    }
}
