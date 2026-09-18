<?php

namespace Pratcom\Connect\Bridge\Jobs;

use Pratcom\Connect\Bridge\FeaturePacks;
use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Privacy\Multilang;

/**
 * Module Emplois (front-end) : amorce, reglages partages et adresses.
 *
 * ─── LA BARRIERE D'ABONNEMENT ───────────────────────────────────────────────
 * Site connecte ET pack `jobs` actif (`FeaturePacks::is_active`, la source
 * unique). Sinon RIEN n'est enregistre, sauf le shortcode, qui doit rester
 * enregistre pour ne pas afficher `[pratcom_emplois]` en clair : il rend
 * alors une chaine vide (et un commentaire HTML pour un editeur connecte).
 *
 * ─── LES REGLES DE REECRITURE ───────────────────────────────────────────────
 * Elles dependent de reglages (pages hotes, case « reprendre /jobs/ ») et du
 * pack. Leur empreinte est gardee dans `pratcom_connect_jobs_regles` : quand
 * elle change (page hote renommee, pack active ou coupe, case cochee), les
 * permaliens sont regeneres UNE fois, au lieu d'attendre qu'un humain pense
 * a passer par Reglages > Permaliens.
 */
final class Module
{
    public const OPTION_PAGE_PREFIXE = 'pratcom_connect_jobs_page_';
    public const OPTION_REPRENDRE_JOBS = 'pratcom_connect_jobs_reprendre_jobs';
    public const OPTION_REGLES = 'pratcom_connect_jobs_regles';

    public static function boot(): void
    {
        new Shortcode();

        if (!self::actif()) {
            add_action('init', [self::class, 'synchroniser_regles'], 99);
            return;
        }

        new Fiche();
        new JsonLd();
        new Redirections();
        add_action('init', [self::class, 'synchroniser_regles'], 99);
    }

    /** Le module est-il servi sur ce site ? */
    public static function actif(): bool
    {
        return Plugin::is_connected() && FeaturePacks::is_active('jobs');
    }

    /** Identifiant de la page hote d'une langue (0 = non configuree). */
    public static function page_hote(string $lang): int
    {
        $id = (int) get_option(self::OPTION_PAGE_PREFIXE . Vocabulaire::langue($lang), 0);
        if ($id <= 0) {
            return 0;
        }
        $p = get_post($id);
        return ($p instanceof \WP_Post && $p->post_type === 'page' && $p->post_status === 'publish') ? $id : 0;
    }

    /**
     * Pages hotes configurees.
     *
     * @return array<string, int> langue => identifiant de page
     */
    public static function pages_hotes(): array
    {
        $out = [];
        foreach (Vocabulaire::LANGUES as $lang) {
            $id = self::page_hote($lang);
            if ($id > 0) {
                $out[$lang] = $id;
            }
        }
        return $out;
    }

    /** Langue portee par une page hote, ou vide si la page n'en est pas une. */
    public static function langue_de_page(int $page_id): string
    {
        $trouvees = array_keys(self::pages_hotes(), $page_id, true);
        if (count($trouvees) === 1) {
            return $trouvees[0];
        }
        // Meme page hote pour les deux langues (site unilingue qui sert les
        // deux) : c'est la langue courante qui tranche.
        return $trouvees === [] ? '' : self::langue_courante();
    }

    /** Langue courante du site (WPML/Polylang via `determine_locale`). */
    public static function langue_courante(): string
    {
        return (strpos(determine_locale(), 'en') === 0) ? 'en' : 'fr';
    }

    /** URL de la liste d'une langue, vide si aucune page hote. */
    public static function url_liste(string $lang): string
    {
        $id = self::page_hote($lang);
        if ($id <= 0) {
            return '';
        }
        $url = get_permalink($id);
        return is_string($url) ? $url : '';
    }

    /**
     * URL de la fiche : `{page hote}/{slug}/`. Le slug de l'offre DOIT etre
     * dans l'URL : c'est par lui que le CRM rattache une candidature a son
     * offre (`source_url`).
     */
    public static function url_fiche(string $lang, string $slug): string
    {
        $base = self::url_liste($lang);
        if ($base === '' || $slug === '') {
            return '';
        }
        return trailingslashit($base) . rawurlencode($slug) . '/';
    }

    /**
     * Chemin de la page hote relatif a la racine (ex. `carriere`,
     * `a-propos/carriere`). C'est lui que les regles de reecriture visent.
     */
    public static function chemin_page(int $page_id): string
    {
        $uri = get_page_uri($page_id);
        return is_string($uri) ? trim($uri, '/') : '';
    }

    /**
     * Les regles de reecriture voulues pour l'etat courant.
     *
     * @return array<string, string> motif => requete
     */
    public static function regles_voulues(): array
    {
        if (!self::actif()) {
            return [];
        }
        $regles = [];
        foreach (self::pages_hotes() as $id) {
            $chemin = self::chemin_page($id);
            if ($chemin === '') {
                continue;
            }
            $regles['^' . preg_quote($chemin, '#') . '/([^/]+)/?$'] =
                'index.php?pagename=' . $chemin . '&' . Fiche::QUERY_VAR . '=$matches[1]';
        }
        if (self::reprendre_jobs()) {
            $regles['^jobs/([^/]+)/?$'] = 'index.php?' . Redirections::QUERY_VAR . '=$matches[1]';
            $regles['^jobs/?$'] = 'index.php?' . Redirections::QUERY_VAR . '=' . Redirections::LISTE;
        }
        return $regles;
    }

    /** Enregistre les regles voulues (appele sur `init`, et avant un flush). */
    public static function ajouter_regles(): void
    {
        foreach (self::regles_voulues() as $motif => $requete) {
            add_rewrite_rule($motif, $requete, 'top');
        }
    }

    /**
     * Regenere les permaliens quand les regles voulues ont change depuis la
     * derniere fois. Un seul flush par changement, jamais a chaque requete.
     */
    public static function synchroniser_regles(): void
    {
        $voulues = self::regles_voulues();
        $empreinte = $voulues === [] ? '' : md5((string) wp_json_encode($voulues));
        if ((string) get_option(self::OPTION_REGLES, '') === $empreinte) {
            return;
        }
        update_option(self::OPTION_REGLES, $empreinte, true);
        flush_rewrite_rules(false);
    }

    /** La case « reprendre /jobs/ » est-elle cochee ? */
    public static function reprendre_jobs(): bool
    {
        return (bool) get_option(self::OPTION_REPRENDRE_JOBS, false);
    }

    /**
     * Langue d'une page selon WPML/Polylang, vide si inconnue. Sert a
     * l'onglet pour proposer les pages de la bonne langue.
     */
    public static function langue_page_multilingue(int $page_id): string
    {
        return Multilang::is_active() ? Multilang::post_language($page_id) : '';
    }

    /** Les offres d'une langue. */
    public static function offres_de(string $lang): array
    {
        $lang = Vocabulaire::langue($lang);
        return array_values(array_filter(
            Catalogue::offres(),
            static fn($o) => is_array($o) && ($o['lang'] ?? '') === $lang
        ));
    }

    /** Une offre par langue et slug, ou `null`. */
    public static function offre(string $lang, string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }
        foreach (self::offres_de($lang) as $o) {
            if (($o['slug'] ?? null) === $slug) {
                return $o;
            }
        }
        return null;
    }

    /** L'offre soeur (meme `group_key`) dans l'autre langue, ou `null`. */
    public static function offre_soeur(array $offre): ?array
    {
        $cle = $offre['group_key'] ?? null;
        if (!is_string($cle) || $cle === '') {
            return null;
        }
        foreach (Catalogue::offres() as $o) {
            if (is_array($o) && ($o['group_key'] ?? null) === $cle && ($o['lang'] ?? '') !== ($offre['lang'] ?? '')) {
                return $o;
            }
        }
        return null;
    }

    /** Commentaire HTML visible des seuls editeurs connectes. */
    public static function note_editeur(string $message): string
    {
        return current_user_can('edit_posts') ? '<!-- pratcom_emplois : ' . esc_html($message) . ' -->' : '';
    }
}
