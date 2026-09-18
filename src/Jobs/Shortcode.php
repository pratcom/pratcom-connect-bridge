<?php

namespace Pratcom\Connect\Bridge\Jobs;

/**
 * Shortcode `[pratcom_emplois lang="" filtres="categorie,lieu,type,horaire,etiquette" limite="50" vide=""]`.
 *
 * La liste des offres, rendue COTE SERVEUR : un robot et un visiteur sans
 * JavaScript voient les memes offres, et chaque carte maille sa fiche.
 *
 * ─── LES FILTRES ────────────────────────────────────────────────────────────
 * Un `<form method="get">` ordinaire, parametres `pce_categorie`, `pce_lieu`,
 * `pce_type`, `pce_horaire`, `pce_etiquette`. Chaque liste deroulante ne
 * propose que les valeurs REELLEMENT portees par les offres de la langue ;
 * un filtre sans aucune valeur n'est pas rendu (c'est ainsi que le filtre
 * Categorie disparait tant que le CRM ne sert pas de categories). Une valeur
 * recue qui n'est pas dans la liste proposee est ignoree.
 *
 * ─── L'ORDRE ────────────────────────────────────────────────────────────────
 * La route transporte en `id ASC`, ce qui n'est pas un ordre editorial.
 * L'affichage : en vedette d'abord, puis la plus recente, puis le titre.
 */
final class Shortcode
{
    public const TAG = 'pratcom_emplois';

    /** Filtres connus, dans l'ordre d'affichage. */
    public const FILTRES = ['categorie', 'lieu', 'type', 'horaire', 'etiquette'];

    /** Cle de lieu reservee au travail a distance. */
    private const LIEU_DISTANCE = 'a-distance';

    private static bool $style_requis = false;

    public function __construct()
    {
        add_shortcode(self::TAG, [$this, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue_style']);
        add_action('wp_footer', [self::class, 'style_tardif'], 1);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function render($atts): string
    {
        $atts = shortcode_atts(
            [
                'lang'    => '',
                'filtres' => implode(',', self::FILTRES),
                'limite'  => '50',
                'vide'    => '',
            ],
            $atts,
            self::TAG
        );

        if (!Module::actif()) {
            return Module::note_editeur('module inactif');
        }

        $lang = trim((string) $atts['lang']) !== '' ? Vocabulaire::langue($atts['lang']) : Module::langue_courante();
        self::demander_style();

        $etat = Catalogue::etat();
        if (!$etat['charge']) {
            return '<div class="pce pce-liste-bloc"><p class="pce-message">'
                . esc_html(Vocabulaire::texte('indisponible', $lang)) . '</p></div>';
        }

        $offres = Module::offres_de($lang);
        $vide = trim((string) $atts['vide']) !== '' ? (string) $atts['vide'] : Vocabulaire::texte('vide', $lang);

        if ($offres === []) {
            return '<div class="pce pce-liste-bloc"><p class="pce-message">' . esc_html($vide) . '</p></div>';
        }

        $demandes = array_values(array_intersect(
            self::FILTRES,
            array_map('trim', explode(',', strtolower((string) $atts['filtres'])))
        ));
        $options = self::options_filtres($offres, $lang);
        $choix = self::choix_filtres($options, $demandes);

        $retenues = self::trier(self::filtrer($offres, $choix));
        $limite = max(1, min(500, (int) $atts['limite']));
        $affichees = array_slice($retenues, 0, $limite);

        $html = '<div class="pce pce-liste-bloc" lang="' . esc_attr($lang) . '">';
        $html .= self::formulaire($options, $demandes, $choix, $lang);
        $html .= '<p class="pce-compte" role="status">' . esc_html(Vocabulaire::nombre(count($retenues), $lang)) . '</p>';

        if ($affichees === []) {
            $html .= '<p class="pce-message">' . esc_html(Vocabulaire::texte('aucun_resultat', $lang)) . '</p>';
        } else {
            if (Module::page_hote($lang) === 0) {
                $html .= Module::note_editeur('aucune page hote configuree pour la langue ' . $lang . ' : les cartes ne sont pas cliquables');
            }
            $html .= '<ul class="pce-cartes">';
            foreach ($affichees as $offre) {
                $html .= self::carte($offre, $lang);
            }
            $html .= '</ul>';
        }

        return $html . '</div>';
    }

    // ─── Filtres ────────────────────────────────────────────────────────────

    /**
     * Les options de chaque filtre, tirees des offres elles-memes.
     *
     * @return array<string, array<string, string>> filtre => (cle => libelle)
     */
    public static function options_filtres(array $offres, string $lang): array
    {
        $out = array_fill_keys(self::FILTRES, []);

        // Categories : parent › feuille, a partir de l'instantane `categories`
        // (parent d'abord, feuille ensuite ; une seule entree sans parent).
        $parents = [];
        $feuilles = [];
        foreach ($offres as $o) {
            $chaine = self::categories($o);
            if ($chaine === []) {
                continue;
            }
            $racine = $chaine[0];
            $parents[$racine['slug']] = self::nom($racine, $lang);
            if (count($chaine) > 1) {
                $feuille = $chaine[count($chaine) - 1];
                $feuilles[$racine['slug']][$feuille['slug']] = self::nom($feuille, $lang);
            }
        }
        asort($parents, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($parents as $slug => $nom) {
            $out['categorie'][$slug] = $nom;
            $enfants = $feuilles[$slug] ?? [];
            asort($enfants, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($enfants as $fslug => $fnom) {
                $out['categorie'][$fslug] = $nom . ' › ' . $fnom;
            }
        }

        foreach ($offres as $o) {
            foreach (self::lieux($o, $lang) as $cle => $libelle) {
                $out['lieu'][$cle] = $libelle;
            }
            if (Vocabulaire::dans('employment_type', $o['employment_type'] ?? null)) {
                $out['type'][$o['employment_type']] = Vocabulaire::libelle('employment_type', $o['employment_type'], $lang);
            }
            if (Vocabulaire::dans('schedule', $o['schedule'] ?? null)) {
                $out['horaire'][$o['schedule']] = Vocabulaire::libelle('schedule', $o['schedule'], $lang);
            }
            foreach (self::etiquettes($o) as $e) {
                $out['etiquette'][$e['slug']] = self::nom($e, $lang);
            }
        }

        foreach (['lieu', 'etiquette'] as $f) {
            asort($out[$f], SORT_NATURAL | SORT_FLAG_CASE);
        }
        // Type et horaire : l'ordre du vocabulaire, pas l'alphabet.
        $out['type'] = self::ordre_vocabulaire($out['type'], Vocabulaire::TYPES_EMPLOI);
        $out['horaire'] = self::ordre_vocabulaire($out['horaire'], Vocabulaire::HORAIRES);

        return $out;
    }

    /**
     * Les valeurs demandees en GET, validees contre les options. Une valeur
     * inconnue est ignoree, sans message : c'est une URL bricolee ou une
     * offre fermee depuis, pas une erreur du visiteur.
     *
     * @return array<string, string>
     */
    public static function choix_filtres(array $options, array $demandes, ?array $get = null): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre public en lecture seule.
        $get = $get ?? $_GET;
        $choix = [];
        foreach ($demandes as $f) {
            $brut = isset($get['pce_' . $f]) && is_string($get['pce_' . $f]) ? sanitize_title(wp_unslash($get['pce_' . $f])) : '';
            if ($brut !== '' && isset($options[$f][$brut])) {
                $choix[$f] = $brut;
            }
        }
        return $choix;
    }

    /** Applique les choix. Un parent de categorie selectionne toutes ses feuilles. */
    public static function filtrer(array $offres, array $choix): array
    {
        return array_values(array_filter($offres, static function ($o) use ($choix) {
            if (isset($choix['categorie'])) {
                $slugs = array_column(self::categories($o), 'slug');
                if (!in_array($choix['categorie'], $slugs, true)) {
                    return false;
                }
            }
            if (isset($choix['lieu']) && !isset(self::lieux($o, 'fr')[$choix['lieu']])) {
                return false;
            }
            if (isset($choix['type']) && ($o['employment_type'] ?? null) !== $choix['type']) {
                return false;
            }
            if (isset($choix['horaire']) && ($o['schedule'] ?? null) !== $choix['horaire']) {
                return false;
            }
            if (isset($choix['etiquette']) && !in_array($choix['etiquette'], array_column(self::etiquettes($o), 'slug'), true)) {
                return false;
            }
            return true;
        }));
    }

    /** En vedette, puis la plus recente, puis le titre. */
    public static function trier(array $offres): array
    {
        usort($offres, static function ($a, $b) {
            $va = !empty($a['featured']) ? 1 : 0;
            $vb = !empty($b['featured']) ? 1 : 0;
            if ($va !== $vb) {
                return $vb <=> $va;
            }
            $da = Vocabulaire::horodatage($a['posted_at'] ?? null) ?? 0;
            $db = Vocabulaire::horodatage($b['posted_at'] ?? null) ?? 0;
            if ($da !== $db) {
                return $db <=> $da;
            }
            return strnatcasecmp(remove_accents((string) ($a['title'] ?? '')), remove_accents((string) ($b['title'] ?? '')));
        });
        return $offres;
    }

    // ─── Lecture defensive de la charge utile ───────────────────────────────

    /**
     * Instantane de categories : `[{slug, name, name_en}, …]`, parent d'abord.
     * `null`, `[]` ou une forme inattendue rendent une liste vide.
     *
     * @return array<int, array{slug: string, name: string, name_en: string}>
     */
    public static function categories(array $o): array
    {
        return self::entrees($o['categories'] ?? null);
    }

    /** `taxonomies.etiquettes`, meme forme. */
    public static function etiquettes(array $o): array
    {
        $tax = $o['taxonomies'] ?? null;
        return self::entrees(is_array($tax) ? ($tax['etiquettes'] ?? null) : null);
    }

    private static function entrees($brut): array
    {
        if (!is_array($brut)) {
            return [];
        }
        $out = [];
        foreach ($brut as $e) {
            if (!is_array($e)) {
                continue;
            }
            $slug = sanitize_title((string) ($e['slug'] ?? ''));
            $nom = trim((string) ($e['name'] ?? ''));
            if ($slug === '' || $nom === '') {
                continue;
            }
            $out[] = ['slug' => $slug, 'name' => $nom, 'name_en' => trim((string) ($e['name_en'] ?? ''))];
        }
        return $out;
    }

    /** Nom d'une entree dans la langue, repli sur le nom francais. */
    public static function nom(array $e, string $lang): string
    {
        return ($lang === 'en' && ($e['name_en'] ?? '') !== '') ? $e['name_en'] : $e['name'];
    }

    /**
     * Les lieux d'une offre, cle => libelle. Une offre en ville ET a distance
     * apparait sous les deux.
     *
     * @return array<string, string>
     */
    public static function lieux(array $o, string $lang): array
    {
        $out = [];
        $ville = trim((string) ($o['location_city'] ?? ''));
        if ($ville !== '') {
            $region = trim((string) ($o['location_region'] ?? ''));
            $cle = sanitize_title($ville . ($region !== '' ? '-' . $region : ''));
            $out[$cle] = $ville . ($region !== '' ? ' (' . strtoupper($region) . ')' : '');
        }
        if (!empty($o['is_remote'])) {
            $out[self::LIEU_DISTANCE] = Vocabulaire::texte('a_distance', $lang);
        }
        return $out;
    }

    /** Libelle court du lieu pour une carte ou une fiche. */
    public static function lieu_libelle(array $o, string $lang): string
    {
        $parties = array_values(self::lieux($o, $lang));
        $precision = trim((string) ($o['location_label'] ?? ''));
        if ($precision !== '') {
            array_unshift($parties, $precision);
        }
        return implode(' · ', array_unique($parties));
    }

    private static function ordre_vocabulaire(array $options, array $ordre): array
    {
        $out = [];
        foreach ($ordre as $cle) {
            if (isset($options[$cle])) {
                $out[$cle] = $options[$cle];
            }
        }
        return $out;
    }

    // ─── Gabarits ───────────────────────────────────────────────────────────

    private static function formulaire(array $options, array $demandes, array $choix, string $lang): string
    {
        $visibles = array_values(array_filter($demandes, static fn($f) => $options[$f] !== []));
        if ($visibles === []) {
            return '';
        }

        $action = self::url_courante();
        $html = '<form class="pce-filtres" method="get" action="' . esc_url($action) . '" role="search" aria-label="'
            . esc_attr(Vocabulaire::texte('filtres_titre', $lang)) . '">';

        foreach ($visibles as $f) {
            $id = 'pce-' . $f . '-' . wp_unique_id();
            $html .= '<div class="pce-filtre"><label for="' . esc_attr($id) . '">'
                . esc_html(Vocabulaire::texte('filtre_' . $f, $lang)) . '</label>'
                . '<select id="' . esc_attr($id) . '" name="pce_' . esc_attr($f) . '">'
                . '<option value="">' . esc_html(Vocabulaire::texte('filtre_tous', $lang)) . '</option>';
            foreach ($options[$f] as $cle => $libelle) {
                $html .= '<option value="' . esc_attr((string) $cle) . '"' . selected($choix[$f] ?? '', (string) $cle, false) . '>'
                    . esc_html($libelle) . '</option>';
            }
            $html .= '</select></div>';
        }

        $html .= '<div class="pce-filtre pce-filtre--actions">'
            . '<button type="submit" class="pce-bouton wp-element-button">' . esc_html(Vocabulaire::texte('filtrer', $lang)) . '</button>';
        if ($choix !== []) {
            $html .= ' <a class="pce-reinitialiser" href="' . esc_url($action) . '">' . esc_html(Vocabulaire::texte('reinitialiser', $lang)) . '</a>';
        }
        return $html . '</div></form>';
    }

    private static function carte(array $o, string $lang): string
    {
        $titre = (string) ($o['title'] ?? '');
        $slug = is_string($o['slug'] ?? null) ? $o['slug'] : '';
        $url = Module::url_fiche($lang, $slug);

        $html = '<li class="pce-carte' . (!empty($o['featured']) ? ' pce-carte--vedette' : '') . '">';
        if (!empty($o['featured'])) {
            $html .= '<span class="pce-pastille pce-pastille--vedette">' . esc_html(Vocabulaire::texte('vedette', $lang)) . '</span>';
        }

        $cats = self::categories($o);
        if ($cats !== []) {
            $html .= '<p class="pce-carte__categorie">' . esc_html(implode(' › ', array_map(static fn($c) => self::nom($c, $lang), $cats))) . '</p>';
        }

        $html .= '<h3 class="pce-carte__titre">'
            . ($url !== '' ? '<a href="' . esc_url($url) . '">' . esc_html($titre) . '</a>' : esc_html($titre))
            . '</h3>';

        $meta = array_filter([
            self::lieu_libelle($o, $lang),
            Vocabulaire::libelle('employment_type', $o['employment_type'] ?? null, $lang),
            Vocabulaire::libelle('schedule', $o['schedule'] ?? null, $lang),
            Vocabulaire::salaire($o, $lang),
        ], static fn($v) => $v !== '');
        if ($meta !== []) {
            $html .= '<ul class="pce-meta">';
            foreach ($meta as $m) {
                $html .= '<li>' . esc_html($m) . '</li>';
            }
            $html .= '</ul>';
        }

        $etiquettes = self::etiquettes($o);
        if ($etiquettes !== []) {
            $html .= '<ul class="pce-etiquettes">';
            foreach ($etiquettes as $e) {
                $html .= '<li class="pce-pastille">' . esc_html(self::nom($e, $lang)) . '</li>';
            }
            $html .= '</ul>';
        }

        $date = Vocabulaire::date($o['posted_at'] ?? null, $lang);
        if ($date !== '') {
            $html .= '<p class="pce-carte__date">' . esc_html(Vocabulaire::texte('publiee_le', $lang, $date)) . '</p>';
        }

        return $html . '</li>';
    }

    /** L'URL de la page courante, sans les parametres de filtre. */
    private static function url_courante(): string
    {
        $id = get_queried_object_id();
        $url = $id ? get_permalink($id) : '';
        return is_string($url) && $url !== '' ? $url : home_url('/');
    }

    // ─── Style ──────────────────────────────────────────────────────────────

    public static function demander_style(): void
    {
        self::$style_requis = true;
        if (did_action('wp_enqueue_scripts')) {
            // Rendu apres le <head> (constructeur de page, widget) : le style
            // part dans le pied de page plutot que pas du tout.
            self::enfiler_style();
        }
    }

    /**
     * Style charge seulement sur une page qui rend la liste ou une fiche
     * (meme patron que `PolicyShortcode::maybe_enqueue_style`). Elementor
     * garde son contenu en meta : on regarde aussi `_elementor_data`.
     */
    public static function maybe_enqueue_style(): void
    {
        if (!Module::actif() || !is_singular()) {
            return;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return;
        }
        $elementor = (string) get_post_meta($post->ID, '_elementor_data', true);
        if (
            has_shortcode($post->post_content, self::TAG)
            || strpos($elementor, '[' . self::TAG) !== false
            || Fiche::offre_courante() !== null
            || Fiche::indisponible()
        ) {
            self::enfiler_style();
        }
    }

    public static function style_tardif(): void
    {
        if (self::$style_requis) {
            self::enfiler_style();
        }
    }

    private static function enfiler_style(): void
    {
        $handle = 'pratcom-connect-jobs';
        if (wp_style_is($handle, 'enqueued')) {
            return;
        }
        wp_register_style($handle, false, [], PRATCOM_CONNECT_BRIDGE_VERSION);
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, self::css());
    }

    /**
     * Couleurs HERITEES du theme (`currentColor`, transparences) : aucune
     * palette Connect sur le site d'un client. Colonne centree, largeur
     * filtrable, cartes lisibles a 360 px.
     */
    public static function css(): string
    {
        $max = (int) apply_filters('pratcom_connect_jobs_max_width', 1020);
        return '.pce{max-width:' . $max . 'px;margin-inline:auto;padding-inline:clamp(0px,2vw,16px);box-sizing:border-box}'
            . '.pce *,.pce *::before,.pce *::after{box-sizing:border-box}'
            . '.pce-filtres{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:0 0 16px}'
            . '.pce-filtre{display:flex;flex-direction:column;gap:4px;flex:1 1 150px;min-width:0}'
            . '.pce-filtre label{font-size:.875em;font-weight:600}'
            . '.pce-filtre select{width:100%;max-width:100%;min-height:44px}'
            . '.pce-filtre--actions{flex:0 0 auto;flex-direction:row;align-items:center;gap:12px}'
            . '.pce-bouton{display:inline-block;min-height:44px;padding:10px 20px;cursor:pointer;text-decoration:none}'
            . '.pce-compte{margin:0 0 12px;font-size:.9em;opacity:.8}'
            . '.pce-cartes{list-style:none;margin:0;padding:0;display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr))}'
            . '.pce-carte{margin:0;padding:18px;border:1px solid rgba(127,127,127,.35);border-radius:8px;display:flex;flex-direction:column;gap:8px;min-width:0;overflow-wrap:anywhere}'
            . '.pce-carte--vedette{border-width:2px;border-color:currentColor}'
            . '.pce-carte__titre{margin:0;font-size:1.2em;line-height:1.3}'
            . '.pce-carte__categorie,.pce-carte__date{margin:0;font-size:.875em;opacity:.8}'
            . '.pce-meta{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:4px 14px;font-size:.95em}'
            . '.pce-etiquettes{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px}'
            . '.pce-pastille{display:inline-block;align-self:flex-start;padding:2px 10px;border-radius:999px;background:rgba(127,127,127,.15);font-size:.8em;line-height:1.6}'
            . '.pce-pastille--vedette{font-weight:700;border:1px solid currentColor;background:transparent}'
            . '.pce-message{padding:16px;border:1px dashed rgba(127,127,127,.5);border-radius:8px}'
            . '.pce-fiche__titre{margin:0 0 12px}'
            . '.pce-fiche__meta{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:8px 20px;margin:0 0 24px;padding:16px;border:1px solid rgba(127,127,127,.35);border-radius:8px}'
            . '.pce-fiche__meta div{min-width:0}'
            . '.pce-fiche__meta dt{font-size:.8em;font-weight:600;opacity:.8;margin:0}'
            . '.pce-fiche__meta dd{margin:0}'
            . '.pce-fiche__description{margin:0 0 28px}'
            . '.pce-fiche__postuler{margin:0 0 28px}'
            . '.pce-fiche__retour{margin:0}';
    }
}
