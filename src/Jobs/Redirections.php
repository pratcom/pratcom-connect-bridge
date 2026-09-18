<?php

namespace Pratcom\Connect\Bridge\Jobs;

/**
 * Reprise des anciennes adresses `/jobs/{slug}/` (extension d'offres
 * precedente) : 301 vers la fiche si le slug existe au catalogue de la
 * langue, sinon 301 vers la liste.
 *
 * ─── ACTIVE SEULEMENT PAR LA CASE « REPRENDRE /jobs/ » ──────────────────────
 * Tant que l'ancienne extension tourne, SA reecriture `/jobs/` garde la main :
 * sans la case, aucune regle n'est posee ici. On la coche le jour du
 * basculement, apres avoir desactive l'ancienne extension ; les permaliens
 * se regenerent seuls (`Module::synchroniser_regles`).
 */
final class Redirections
{
    public const QUERY_VAR = 'pratcom_emploi_ancien';

    /** Valeur reservee : `/jobs/` sans slug => la liste. */
    public const LISTE = '__liste';

    public function __construct()
    {
        if (!Module::reprendre_jobs()) {
            return;
        }
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_action('template_redirect', [$this, 'rediriger'], 0);
    }

    public function rediriger(): void
    {
        $slug = (string) get_query_var(self::QUERY_VAR);
        if ($slug === '') {
            return;
        }
        $cible = self::cible($slug, Module::langue_courante());
        if ($cible === '') {
            return;
        }
        wp_safe_redirect($cible, 301, 'Pratcom Connect');
        exit;
    }

    /**
     * La destination d'une ancienne adresse. Vide si aucune page hote n'est
     * configuree (la requete finit alors en 404 WordPress, comme avant).
     */
    public static function cible(string $slug, string $lang): string
    {
        $slug = sanitize_title(rawurldecode($slug));
        if ($slug !== '' && $slug !== sanitize_title(self::LISTE)) {
            $offre = Module::offre($lang, $slug);
            if ($offre !== null) {
                $url = Module::url_fiche($lang, (string) $offre['slug']);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        $liste = Module::url_liste($lang);
        if ($liste !== '') {
            return $liste;
        }
        foreach (Vocabulaire::LANGUES as $autre) {
            $liste = Module::url_liste($autre);
            if ($liste !== '') {
                return $liste;
            }
        }
        return '';
    }
}
