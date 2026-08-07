<?php

namespace Pratcom\Connect\Bridge\Privacy;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Rendu « cartes » des témoins — remplace les <table> des pages légales
 * (politique de confidentialité + déclaration de témoins) par une grille de
 * cartes responsive (2-3 colonnes desktop, 1 colonne mobile, zéro scroll
 * horizontal).
 *
 * CAMÉLÉON : les cartes n'imposent presque aucune couleur. Fond et bordures
 * dérivent de `currentColor` via `color-mix()` → sur un thème sombre (texte
 * clair) elles sortent sombres, sur un thème clair elles sortent pâles, avec
 * le MÊME CSS. Repli `rgba` neutre pour les navigateurs sans color-mix.
 * L'accent (pastille de durée) = couleur primaire du thème local
 * (`Plugin::get_theme()['primary']`, onglet Apparence), filtrable via
 * `pratcom_connect_legal_accent`.
 *
 * Attribut `appearance="auto|light|dark"` sur les deux shortcodes : `auto`
 * (défaut) = caméléon ; `light`/`dark` = palette fixe forcée (filet pour les
 * thèmes aux couleurs ambiguës).
 *
 * Impression : les cartes se replient en blocs simples (@media print).
 *
 * Zone chantier Privacy. Aucune option nouvelle, aucun JS, aucun asset.
 */
class CookieCards
{
    public const MODES = ['auto', 'light', 'dark'];

    /** @param mixed $mode */
    public static function sanitize_mode($mode): string
    {
        $mode = strtolower(trim((string) $mode));
        return in_array($mode, self::MODES, true) ? $mode : 'auto';
    }

    /**
     * Grille de cartes pour une liste de témoins.
     *
     * @param array<int, array{name?:string, provider?:string, purpose?:string, expiry?:string, category?:string}> $rows
     * @param string $lang       fr|en
     * @param string $appearance auto|light|dark
     * @param bool   $show_category Affiche la catégorie dans le pied de carte
     *                              (false quand les cartes sont déjà groupées
     *                              sous un en-tête de catégorie).
     */
    public static function render(array $rows, string $lang, string $appearance = 'auto', bool $show_category = true): string
    {
        $lang = $lang === 'en' ? 'en' : 'fr';
        $appearance = self::sanitize_mode($appearance);
        $labels = CookieScan::category_label($lang);

        $classes = 'pratcom-cookiecards';
        if ($appearance !== 'auto') {
            $classes .= ' pratcom-cookiecards--' . $appearance;
        }

        $out = '<div class="' . esc_attr($classes) . '" role="list">';
        foreach ($rows as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name     = (string) ($c['name'] ?? '');
            $provider = (string) ($c['provider'] ?? '');
            $purpose  = (string) ($c['purpose'] ?? '');
            $expiry   = (string) ($c['expiry'] ?? '');
            $cat      = (string) ($c['category'] ?? '');
            $cat_label = isset($labels[$cat]) ? (string) $labels[$cat] : '';

            $meta = $provider;
            if ($show_category && $cat_label !== '') {
                $meta = $meta !== '' ? $meta . ' · ' . $cat_label : $cat_label;
            }

            $out .= '<article class="pratcom-cookiecard" role="listitem">';
            $out .= '<div class="pratcom-cookiecard-head">';
            $out .= '<code class="pratcom-cookiecard-name">' . esc_html($name) . '</code>';
            if ($expiry !== '') {
                $out .= '<span class="pratcom-cookiecard-expiry">' . esc_html($expiry) . '</span>';
            }
            $out .= '</div>';
            if ($purpose !== '') {
                $out .= '<p class="pratcom-cookiecard-purpose">' . esc_html($purpose) . '</p>';
            }
            if ($meta !== '') {
                $out .= '<p class="pratcom-cookiecard-meta">' . esc_html($meta) . '</p>';
            }
            $out .= '</article>';
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * CSS des cartes, à concaténer au style légal conditionnel
     * (PolicyShortcode::maybe_enqueue_style). Repli rgba d'abord, color-mix
     * ensuite (repli par déclaration, sans @supports).
     */
    public static function css(): string
    {
        $theme  = Plugin::get_theme();
        $accent = (string) apply_filters('pratcom_connect_legal_accent', (string) ($theme['primary'] ?? ''));
        if ($accent !== '' && function_exists('sanitize_hex_color')) {
            $accent = (string) sanitize_hex_color($accent);
        }

        $css = '.pratcom-cookiecards{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;margin:16px 0}'
            . '.pratcom-cookiecard{box-sizing:border-box;border-radius:12px;padding:14px 16px;'
            . 'border:1px solid rgba(128,128,128,.30);background:rgba(128,128,128,.07);'
            . 'border-color:color-mix(in srgb,currentColor 16%,transparent);background:color-mix(in srgb,currentColor 5%,transparent);'
            . 'transition:transform .15s ease,border-color .15s ease}'
            . '.pratcom-cookiecard:hover{transform:translateY(-2px);border-color:rgba(128,128,128,.55);border-color:color-mix(in srgb,currentColor 34%,transparent)}'
            . '.pratcom-cookiecard-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 10px;flex-wrap:wrap}'
            . '.pratcom-cookiecards .pratcom-cookiecard-name{font-size:14px;line-height:1.3;padding:2px 8px;border-radius:6px;word-break:break-all;'
            . 'background:rgba(128,128,128,.14);background:color-mix(in srgb,currentColor 10%,transparent)}'
            . '.pratcom-cookiecards .pratcom-cookiecard-expiry{font-size:13px;line-height:1;padding:5px 12px;border-radius:999px;white-space:nowrap;'
            . 'border:1px solid rgba(128,128,128,.35);background:rgba(128,128,128,.12)}'
            . '.pratcom-cookiecards .pratcom-cookiecard-purpose{margin:0 0 8px;font-size:15px;line-height:1.55}'
            . '.pratcom-cookiecards .pratcom-cookiecard-meta{margin:0;font-size:13px;line-height:1.4;'
            . 'color:rgba(128,128,128,.95);color:color-mix(in srgb,currentColor 62%,transparent)}';

        if ($accent !== '') {
            $css .= '.pratcom-cookiecards .pratcom-cookiecard-expiry{'
                . 'border-color:color-mix(in srgb,' . $accent . ' 55%,transparent);'
                . 'background:color-mix(in srgb,' . $accent . ' 16%,transparent)}'
                . '.pratcom-cookiecard:hover{border-color:color-mix(in srgb,' . $accent . ' 45%,transparent)}';
        }

        $css .= '.pratcom-cookiecards--light{color:#1d2b36}'
            . '.pratcom-cookiecards--light .pratcom-cookiecard{background:#fff;border-color:#dfe4e2}'
            . '.pratcom-cookiecards--dark{color:#e8ece9}'
            . '.pratcom-cookiecards--dark .pratcom-cookiecard{background:#181c1a;border-color:#2e3531}'
            . '@media print{.pratcom-cookiecards{display:block}'
            . '.pratcom-cookiecard{border:1px solid #999;border-radius:0;margin:0 0 8px;page-break-inside:avoid;transition:none}}';

        return $css;
    }
}
