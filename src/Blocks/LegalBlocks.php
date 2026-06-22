<?php

namespace Pratcom\Connect\Bridge\Blocks;

use Pratcom\Connect\Bridge\Privacy\Multilang;

/**
 * Blocs Gutenberg natifs des pages legales (item J, spec Privacy v2).
 *
 * Deux blocs DYNAMIQUES rendus 100 % cote serveur (render_callback) :
 *   - pratcom-connect/cookie-declaration : declaration de temoins, style
 *     Cookiebot (tableau groupe par categorie, bilingue, auto-genere depuis
 *     presets + scan + liste manuelle).
 *   - pratcom-connect/privacy-policy : politique de confidentialite (gabarit
 *     11 sections Loi 25, bilingue, tableau des temoins integre).
 *
 * ZERO DUPLICATION DE RENDU : chaque render_callback delegue au shortcode
 * existant correspondant via do_shortcode(). Toute la logique (local-first,
 * fallback serveur en premium, fusion des lignes, echappement) reste dans
 * CookieDeclaration / PolicyShortcode / LocalPolicy. Le bloc n'est qu'une
 * surface d'edition Gutenberg par-dessus le shortcode.
 *
 * BILINGUE : l'attribut `lang` du bloc peut forcer fr/en ; par defaut « auto »
 * suit la langue de la page (WPML/Polylang via Multilang, repli determine_locale,
 * puis langue du site). On transmet ensuite cette langue au shortcode.
 *
 * CONFORMITE CANAL (critique) : le rendu front est 100 % local en gratuit
 * (aucun appel serveur) — les shortcodes ne tentent le fragment serveur que
 * si le site est connecte ET le pack privacy actif (tier premium). Le script
 * d'editeur n'utilise que les paquets fournis par WordPress core (wp-blocks,
 * wp-element, wp-block-editor, wp-components, wp-i18n) : AUCUNE ressource
 * externe, ni en build .org ni en premium. Le bloc ne charge donc aucune
 * iframe ni script tiers — il n'a pas besoin de garde de canal et reste
 * conforme WordPress.org par construction.
 *
 * Zone chantier Privacy. Ne touche ni le shell admin, ni Chat/Forms, ni le
 * canal connecte.
 */
class LegalBlocks
{
    public const BLOCK_COOKIE = 'pratcom-connect/cookie-declaration';
    public const BLOCK_POLICY = 'pratcom-connect/privacy-policy';

    /** Handle du script d'editeur partage par les deux blocs. */
    public const EDITOR_HANDLE = 'pratcom-connect-legal-blocks-editor';

    public function __construct()
    {
        add_action('init', [$this, 'register']);
    }

    /**
     * Enregistre les deux blocs dynamiques (metadonnees via block.json) et
     * leur render_callback PHP. Idempotent : register_block_type ignore un
     * type deja enregistre (et journalise un _doing_it_wrong au pire), donc
     * un double-boot reste sans effet de bord visible.
     */
    public function register(): void
    {
        if (!function_exists('register_block_type')) {
            return; // WordPress < 5.0 : pas de blocs (les shortcodes restent).
        }

        // Script d'editeur partage : enregistre une seule fois, reference par
        // les deux block.json via "editorScript": "pratcom-connect-legal-blocks-editor".
        $this->register_editor_script();

        $base = PRATCOM_CONNECT_BRIDGE_DIR . 'src/Blocks/';

        register_block_type($base . 'cookie-declaration', [
            'render_callback' => [$this, 'render_cookie_declaration'],
        ]);

        register_block_type($base . 'privacy-policy', [
            'render_callback' => [$this, 'render_privacy_policy'],
        ]);
    }

    /**
     * Declare le script d'editeur (vanilla, dependances core uniquement).
     * Charge depuis assets/js — embarque dans le plugin, donc present aussi
     * dans le zip .org. Aucune URL externe.
     */
    private function register_editor_script(): void
    {
        if (wp_script_is(self::EDITOR_HANDLE, 'registered')) {
            return;
        }

        wp_register_script(
            self::EDITOR_HANDLE,
            PRATCOM_CONNECT_BRIDGE_URL . 'assets/js/legal-blocks-editor.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            PRATCOM_CONNECT_BRIDGE_VERSION,
            true
        );

        // Permet la traduction des chaines JS de l'editeur via les .po/.mo
        // du plugin (sans effet si wp-i18n n'a pas de catalogue charge).
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(self::EDITOR_HANDLE, 'pratcom-connect');
        }

        // Etiquettes localisees pour l'apercu editeur (titres + aide), passees
        // au JS sans dependre d'un catalogue de traduction JS.
        wp_localize_script(self::EDITOR_HANDLE, 'pratcomLegalBlocks', [
            'cookieTitle'  => __('Déclaration relative aux témoins', 'pratcom-connect'),
            'policyTitle'  => __('Politique de confidentialité', 'pratcom-connect'),
            'cookieHelp'   => __('Tableau des témoins généré automatiquement (presets, liste manuelle et scan local), groupé par catégorie et bilingue. L’aperçu réel s’affiche sur la page publiée.', 'pratcom-connect'),
            'policyHelp'   => __('Politique de confidentialité générée à partir du gabarit Pratcom Connect (Loi 25), avec le tableau des témoins intégré. L’aperçu réel s’affiche sur la page publiée.', 'pratcom-connect'),
            'langLabel'    => __('Langue du contenu', 'pratcom-connect'),
            'langAuto'     => __('Automatique (langue de la page)', 'pratcom-connect'),
            'langFr'       => __('Français', 'pratcom-connect'),
            'langEn'       => __('Anglais', 'pratcom-connect'),
            'category'     => __('Pratcom Connect', 'pratcom-connect'),
        ]);
    }

    /**
     * Resout la langue de rendu a partir de l'attribut `lang` du bloc.
     * 'fr' | 'en' forcent ; toute autre valeur (dont 'auto'/'') suit la page :
     * langue par defaut WPML/Polylang si disponible, sinon determine_locale().
     *
     * @param array<string, mixed> $attributes
     */
    private function resolve_lang(array $attributes): string
    {
        $lang = isset($attributes['lang']) ? strtolower((string) $attributes['lang']) : '';
        if (in_array($lang, ['fr', 'en'], true)) {
            return $lang;
        }

        // Suivre la page : WPML/Polylang d'abord (helper defensif), puis le
        // locale resolu (qui tient deja compte du switch de langue par requete).
        $detected = '';
        if (class_exists(Multilang::class) && Multilang::is_active()) {
            $detected = Multilang::default_language();
        }
        if ($detected === '') {
            $detected = determine_locale();
        }

        return (strpos((string) $detected, 'en') === 0) ? 'en' : 'fr';
    }

    /**
     * Rendu du bloc « declaration de temoins » : delegue au shortcode
     * [pratcom_cookie_declaration lang="…"] (reutilise toute la logique
     * existante, y compris le fallback serveur en premium).
     *
     * @param array<string, mixed> $attributes
     */
    public function render_cookie_declaration($attributes = []): string
    {
        $attributes = is_array($attributes) ? $attributes : [];
        $lang = $this->resolve_lang($attributes);

        $inner = do_shortcode('[pratcom_cookie_declaration lang="' . esc_attr($lang) . '"]');

        return $this->wrap('pratcom-connect-block pratcom-connect-block--cookie-declaration', $inner);
    }

    /**
     * Rendu du bloc « politique de confidentialite » : delegue au shortcode
     * [pratcom_privacy_policy lang="…"].
     *
     * @param array<string, mixed> $attributes
     */
    public function render_privacy_policy($attributes = []): string
    {
        $attributes = is_array($attributes) ? $attributes : [];
        $lang = $this->resolve_lang($attributes);

        $inner = do_shortcode('[pratcom_privacy_policy lang="' . esc_attr($lang) . '"]');

        return $this->wrap('pratcom-connect-block pratcom-connect-block--privacy-policy', $inner);
    }

    /**
     * Enveloppe le rendu dans un conteneur portant les attributs de bloc
     * standard (align, couleurs, etc.) via get_block_wrapper_attributes().
     * Le contenu interne provient des shortcodes, deja echappes a la source.
     */
    private function wrap(string $extra_class, string $inner): string
    {
        if (function_exists('get_block_wrapper_attributes')) {
            $wrapper = get_block_wrapper_attributes(['class' => $extra_class]);
            return '<div ' . $wrapper . '>' . $inner . '</div>';
        }
        return '<div class="' . esc_attr($extra_class) . '">' . $inner . '</div>';
    }
}
