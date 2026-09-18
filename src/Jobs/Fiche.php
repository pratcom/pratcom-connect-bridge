<?php

namespace Pratcom\Connect\Bridge\Jobs;

use Pratcom\Connect\Bridge\FeaturePacks;
use Pratcom\Connect\Bridge\Forms\Shortcode as FormsShortcode;

/**
 * La fiche d'une offre : `{page hote}/{slug}/`.
 *
 * ─── LA PAGE HOTE GARDE SON GABARIT ─────────────────────────────────────────
 * La requete `{chemin}/{slug}` est reecrite vers la page hote (celle qui porte
 * `[pratcom_emplois]`, une par langue) avec `pratcom_emploi={slug}`. Le theme
 * rend donc sa page normalement ; seul le CONTENU est remplace par la fiche,
 * tard dans `the_content` (apres Elementor, qui y injecte son propre rendu).
 *
 * ─── LES TROIS ISSUES D'UN SLUG ─────────────────────────────────────────────
 *  1. connu au catalogue de la langue : la fiche ;
 *  2. inconnu, catalogue SAIN : 301 vers la liste de la langue (architecture
 *     §3.5 : un lien partage apres la fermeture mene aux autres offres, pas a
 *     une impasse) ;
 *  3. inconnu, catalogue jamais charge ou en panne : page « offres
 *     momentanement indisponibles » en 503 + `Retry-After`. JAMAIS une
 *     redirection sur une panne : une offre publiee pendant la panne n'est
 *     pas une offre fermee, et un 301 se met en cache chez le visiteur.
 *
 * Un vrai sous-page WordPress de la page hote (`/carriere/avantages/`) garde
 * la priorite : la reecriture ne l'avale pas.
 */
final class Fiche
{
    public const QUERY_VAR = 'pratcom_emploi';

    /** Case de l'onglet Emplois : le theme imprime deja le titre de la page. */
    public const OPTION_TITRE_THEME = 'pratcom_connect_jobs_titre_theme';

    private static ?array $offre = null;
    private static string $lang = '';
    private static bool $indisponible = false;
    private static bool $formulaire = false;
    private static bool $pont_pose = false;

    public function __construct()
    {
        add_action('init', [Module::class, 'ajouter_regles']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_filter('request', [$this, 'sous_page_reelle']);
        add_action('template_redirect', [$this, 'resoudre'], 1);

        add_filter('the_content', [$this, 'contenu'], 9999);
        // Elementor Theme Builder : le widget « Contenu de la publication »
        // passe par ce filtre-ci. Meme garde (page hote, boucle principale).
        add_filter('elementor/frontend/the_content', [$this, 'contenu'], 9999);
        add_filter('the_title', [$this, 'titre'], 10, 2);
        add_filter('document_title_parts', [$this, 'titre_document']);
        add_filter('get_canonical_url', [$this, 'canonique'], 10, 2);
        add_filter('redirect_canonical', [$this, 'pas_de_redirection_canonique']);
        add_action('wp_head', [$this, 'hreflang'], 2);

        // Yoast SEO : titre, description, canonique et Open Graph de l'offre.
        add_filter('wpseo_title', [$this, 'titre_seo']);
        add_filter('wpseo_opengraph_title', [$this, 'titre_seo']);
        add_filter('wpseo_metadesc', [$this, 'description_seo']);
        add_filter('wpseo_opengraph_desc', [$this, 'description_seo']);
        add_filter('wpseo_canonical', [$this, 'url_seo']);
        add_filter('wpseo_opengraph_url', [$this, 'url_seo']);

        // Les hreflang du plugin multilingue pointent vers les LISTES : sur
        // une fiche, ils seraient faux. On les retire ; les notres les
        // remplacent quand la paire existe.
        add_filter('wpml_hreflangs', [$this, 'retirer_hreflang_tiers']);
        add_filter('pll_rel_hreflang_attributes', [$this, 'retirer_hreflang_tiers']);
    }

    /** L'offre de la fiche courante, `null` hors fiche. */
    public static function offre_courante(): ?array
    {
        return self::$offre;
    }

    public static function langue(): string
    {
        return self::$lang;
    }

    /** La fiche courante est-elle la page « indisponible » ? */
    public static function indisponible(): bool
    {
        return self::$indisponible;
    }

    /** Le formulaire de candidature est-il sur la page (pour `directApply`) ? */
    public static function formulaire_sur_la_page(): bool
    {
        return self::$formulaire;
    }

    /** Remise a zero (bancs). */
    public static function reinitialiser(): void
    {
        self::$offre = null;
        self::$lang = '';
        self::$indisponible = false;
        self::$formulaire = false;
        self::$pont_pose = false;
    }

    public function query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * `/carriere/avantages/` est peut-etre une vraie sous-page : dans ce cas
     * on rend la sous-page et on oublie la fiche.
     */
    public function sous_page_reelle(array $qv): array
    {
        if (empty($qv[self::QUERY_VAR]) || empty($qv['pagename'])) {
            return $qv;
        }
        $chemin = trim((string) $qv['pagename'], '/') . '/' . sanitize_title((string) $qv[self::QUERY_VAR]);
        if (get_page_by_path($chemin) instanceof \WP_Post) {
            $qv['pagename'] = $chemin;
            unset($qv[self::QUERY_VAR]);
        }
        return $qv;
    }

    public function resoudre(): void
    {
        $slug = (string) get_query_var(self::QUERY_VAR);
        if ($slug === '' || !is_page()) {
            return;
        }
        $page_id = (int) get_queried_object_id();
        $lang = Module::langue_de_page($page_id);
        if ($lang === '') {
            return;
        }
        $slug = sanitize_title(rawurldecode($slug));

        self::$lang = $lang;
        $etat = Catalogue::etat();
        $offre = Module::offre($lang, $slug);

        if ($offre !== null) {
            self::$offre = $offre;
            self::$formulaire = !self::url_externe($offre) && FeaturePacks::is_active('forms');
            return;
        }

        if ($etat['sain']) {
            $liste = Module::url_liste($lang);
            if ($liste !== '') {
                wp_safe_redirect($liste, 301, 'Pratcom Connect');
                exit;
            }
        }

        self::$indisponible = true;
        status_header(503);
        header('Retry-After: 300');
        nocache_headers();
    }

    public function pas_de_redirection_canonique($url)
    {
        return (self::$offre !== null || self::$indisponible) ? false : $url;
    }

    // ─── Contenu ────────────────────────────────────────────────────────────

    public function contenu($contenu)
    {
        if (!$this->est_la_page_hote()) {
            return $contenu;
        }
        Shortcode::demander_style();
        if (self::$indisponible) {
            return '<div class="pce pce-fiche"><p class="pce-message">'
                . esc_html(Vocabulaire::texte('indisponible', self::$lang)) . '</p></div>';
        }
        return self::rendre(self::$offre, self::$lang, $this->titre_dans_le_contenu());
    }

    /**
     * Le HTML de la fiche. Statique et sans effet de bord hors mise en file
     * du script de formulaire : le banc l'appelle directement.
     */
    public static function rendre(array $o, string $lang, bool $avec_titre): string
    {
        $html = '<article class="pce pce-fiche" lang="' . esc_attr($lang) . '">';
        if ($avec_titre) {
            $html .= '<h1 class="pce-fiche__titre">' . esc_html((string) ($o['title'] ?? '')) . '</h1>';
        }

        $meta = [
            'meta_lieu'      => Shortcode::lieu_libelle($o, $lang),
            'meta_type'      => Vocabulaire::libelle('employment_type', $o['employment_type'] ?? null, $lang),
            'meta_horaire'   => Vocabulaire::libelle('schedule', $o['schedule'] ?? null, $lang),
            'meta_mode'      => Vocabulaire::libelle('work_mode', $o['work_mode'] ?? null, $lang),
            'meta_niveau'    => Vocabulaire::libelle('experience_level', $o['experience_level'] ?? null, $lang),
            'meta_salaire'   => Vocabulaire::salaire($o, $lang),
            'meta_postes'    => ((int) ($o['positions'] ?? 1)) > 1 ? (string) (int) $o['positions'] : '',
            'meta_date'      => Vocabulaire::date($o['posted_at'] ?? null, $lang),
            'meta_reference' => trim((string) ($o['reference'] ?? '')),
        ];
        $meta = array_filter($meta, static fn($v) => $v !== '');
        if ($meta !== []) {
            $html .= '<dl class="pce-fiche__meta">';
            foreach ($meta as $cle => $valeur) {
                $html .= '<div><dt>' . esc_html(Vocabulaire::texte($cle, $lang)) . '</dt><dd>' . esc_html($valeur) . '</dd></div>';
            }
            $html .= '</dl>';
        }

        $html .= '<div class="pce-fiche__description">' . self::description_html((string) ($o['description'] ?? '')) . '</div>';

        $externe = self::url_externe($o);
        if ($externe !== '') {
            $html .= '<p class="pce-fiche__postuler"><a class="pce-bouton wp-element-button" href="' . esc_url($externe)
                . '" target="_blank" rel="noopener">' . esc_html(Vocabulaire::texte('postuler', $lang)) . '</a></p>';
        } elseif (FeaturePacks::is_active('forms')) {
            $html .= '<section class="pce-fiche__postuler"><h2>' . esc_html(Vocabulaire::texte('postuler_titre', $lang)) . '</h2>'
                . self::formulaire_candidature($o, $lang) . '</section>';
        } else {
            $html .= Module::note_editeur('pack forms inactif : aucun formulaire de candidature sur la fiche');
        }

        $liste = Module::url_liste($lang);
        if ($liste !== '') {
            $html .= '<p class="pce-fiche__retour"><a href="' . esc_url($liste) . '">&larr; '
                . esc_html(Vocabulaire::texte('retour', $lang)) . '</a></p>';
        }

        return $html . '</article>';
    }

    /**
     * Le conteneur du formulaire systeme `candidature`, hydrate par le loader
     * Forms. `data-valeur-poste` porte le titre de l'offre : c'est l'attribut
     * que le loader v0.6.0 lit pour un champ `hidden` de cle `poste`
     * (`data-valeur-<cle>`, trim, 255 caracteres). Le rattachement au CRM,
     * lui, passe par l'URL de la page (`source_url`), qui contient le slug.
     */
    private static function formulaire_candidature(array $o, string $lang): string
    {
        wp_enqueue_script(
            FormsShortcode::HANDLE,
            FormsShortcode::loader_url(),
            [],
            PRATCOM_CONNECT_BRIDGE_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]
        );
        if (!self::$pont_pose) {
            // Une seule fois par page : `the_content` et le filtre Elementor
            // peuvent rendre la fiche tous les deux.
            wp_add_inline_script(FormsShortcode::HANDLE, self::PONT_POSTE, 'after');
            self::$pont_pose = true;
        }
        return sprintf(
            '<div data-pratcom-form="candidature" data-lang="%s" data-valeur-poste="%s"></div>',
            esc_attr($lang),
            esc_attr((string) ($o['title'] ?? ''))
        );
    }

    /**
     * PONT TRANSITOIRE, dette a retirer : quand le loader >= v0.6.0 est en
     * prod ET que le formulaire `candidature` de l'espace est en V3 (`poste`
     * de type `hidden`), ce script ne trouve plus rien a faire et doit partir.
     *
     * Un formulaire V2 rend `poste` comme un champ texte visible et requis.
     * Le script attend son bloc (`.pcf-field[data-key="poste"]`), y pose la
     * valeur de `data-valeur-poste`, le passe en lecture seule et le cache :
     * le loader v0.5.0 lit `input.value` a l'envoi, le titre part donc dans
     * `data.poste`. En V3 le bloc n'existe pas : l'observateur expire sans
     * rien toucher. Aucune donnee d'offre dans le script, aucun `innerHTML`.
     * Valeur vide : le champ reste visible (requis, il doit rester saisissable).
     */
    private const PONT_POSTE = <<<'JS'
(function () {
  'use strict';
  var MAX = 255;
  var conteneurs = document.querySelectorAll('[data-pratcom-form="candidature"][data-valeur-poste]');
  Array.prototype.forEach.call(conteneurs, function (c) {
    var valeur = (c.getAttribute('data-valeur-poste') || '').trim().slice(0, MAX);
    if (valeur === '') return;
    function poser() {
      var bloc = c.querySelector('.pcf-field[data-key="poste"]');
      if (!bloc) return false;
      var champ = bloc.querySelector('input[name="poste"]');
      if (champ) {
        champ.value = valeur;
        champ.readOnly = true;
      }
      bloc.hidden = true;
      bloc.style.display = 'none';
      return true;
    }
    if (poser() || typeof MutationObserver !== 'function') return;
    var garde;
    var obs = new MutationObserver(function () {
      if (poser()) {
        obs.disconnect();
        clearTimeout(garde);
      }
    });
    obs.observe(c, { childList: true, subtree: true });
    garde = setTimeout(function () { obs.disconnect(); }, 10000);
  });
})();
JS;

    /**
     * Description en paragraphes HTML. Le CRM peut livrer du texte brut :
     * sans balise de bloc, `wpautop` pose les paragraphes (Google rejette le
     * texte d'un seul bloc). Toujours assaini par `wp_kses_post`.
     */
    public static function description_html(string $brut): string
    {
        $brut = trim($brut);
        if ($brut === '') {
            return '';
        }
        if (!preg_match('/<(p|div|ul|ol|h[1-6]|br|table|blockquote)[\s>\/]/i', $brut)) {
            $brut = wpautop($brut);
        }
        return trim(wp_kses_post($brut));
    }

    /** `external_url` en `http(s)` seulement, sinon vide. */
    public static function url_externe(array $o): string
    {
        $u = trim((string) ($o['external_url'] ?? ''));
        if ($u === '' || !preg_match('#^https?://#i', $u)) {
            return '';
        }
        return (string) esc_url_raw($u, ['http', 'https']);
    }

    // ─── Titre, SEO, hreflang ───────────────────────────────────────────────

    public function titre($titre, $id = 0)
    {
        if (self::$offre !== null && (int) $id === (int) get_queried_object_id() && in_the_loop() && is_main_query()) {
            return esc_html((string) self::$offre['title']);
        }
        return $titre;
    }

    public function titre_document(array $parts): array
    {
        if (self::$offre !== null) {
            $parts['title'] = (string) self::$offre['title'];
        }
        return $parts;
    }

    public function titre_seo($titre)
    {
        if (self::$offre === null) {
            return $titre;
        }
        $sep = (string) apply_filters('document_title_separator', '-');
        return self::$offre['title'] . ' ' . $sep . ' ' . get_bloginfo('name');
    }

    public function description_seo($desc)
    {
        if (self::$offre === null) {
            return $desc;
        }
        $txt = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags((string) (self::$offre['description'] ?? ''))));
        return $txt === '' ? $desc : wp_html_excerpt($txt, 155, '…');
    }

    public function url_seo($url)
    {
        return self::$offre !== null ? self::url_courante() : $url;
    }

    public function canonique($url, $post = null)
    {
        return self::$offre !== null ? self::url_courante() : $url;
    }

    public static function url_courante(): string
    {
        return self::$offre !== null ? Module::url_fiche(self::$lang, (string) self::$offre['slug']) : '';
    }

    /**
     * `hreflang` entre les deux fiches d'un meme `group_key`, SEULEMENT si les
     * deux langues sont au catalogue ET que la page hote de l'autre langue
     * est configuree. Aucun sinon (decision du 25/08).
     */
    public function hreflang(): void
    {
        foreach (self::liens_hreflang() as $lang => $url) {
            echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($url) . '" />' . "\n";
        }
    }

    /** @return array<string, string> langue => URL (vide ou deux entrees). */
    public static function liens_hreflang(): array
    {
        if (self::$offre === null) {
            return [];
        }
        $soeur = Module::offre_soeur(self::$offre);
        if ($soeur === null) {
            return [];
        }
        $ici = Module::url_fiche(self::$lang, (string) (self::$offre['slug'] ?? ''));
        $la_bas = Module::url_fiche((string) $soeur['lang'], (string) ($soeur['slug'] ?? ''));
        if ($ici === '' || $la_bas === '') {
            return [];
        }
        return [self::$lang => $ici, (string) $soeur['lang'] => $la_bas];
    }

    public function retirer_hreflang_tiers($liens)
    {
        return (self::$offre !== null || self::$indisponible) ? [] : $liens;
    }

    // ─── Aides ──────────────────────────────────────────────────────────────

    private function est_la_page_hote(): bool
    {
        if (self::$offre === null && !self::$indisponible) {
            return false;
        }
        return in_the_loop() && is_main_query() && (int) get_the_ID() === (int) get_queried_object_id();
    }

    /**
     * Le `<h1>` de l'offre est-il rendu dans la fiche ? Oui par defaut : bien
     * des gabarits de page n'impriment pas le titre. La case de l'onglet
     * Emplois (« le theme affiche deja le titre ») le retire, sauf si
     * Elementor masque le titre de la page hote (`hide_title`) : la fiche
     * porte alors son propre `<h1>`. Le filtre a le dernier mot.
     */
    private function titre_dans_le_contenu(): bool
    {
        $reglages = get_post_meta((int) get_queried_object_id(), '_elementor_page_settings', true);
        $masque = is_array($reglages) && ($reglages['hide_title'] ?? '') === 'yes';
        return (bool) apply_filters(
            'pratcom_connect_jobs_titre_dans_fiche',
            self::titre_rendu($masque, (bool) get_option(self::OPTION_TITRE_THEME, false)),
            self::$offre
        );
    }

    /** Table de verite du `<h1>` (sans WordPress : le banc l'appelle). */
    public static function titre_rendu(bool $elementor_masque, bool $theme_affiche_titre): bool
    {
        return $elementor_masque || !$theme_affiche_titre;
    }
}
