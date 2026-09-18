<?php

namespace Pratcom\Connect\Bridge\Jobs;

/**
 * Les vocabulaires fermes du module Emplois, leurs libelles FR/EN, et la
 * traduction vers schema.org a la SORTIE.
 *
 * ─── D'OU VIENNENT CES LISTES ────────────────────────────────────────────────
 * Recopie de `src/lib/crm/jobs-vocab.ts` (pratcom-connect-api) : c'est le
 * contrat. L'ecran CRM ne permet de saisir que ces cles ; la route publique
 * les sert telles quelles. Une cle absente d'ici est IGNOREE (filtre) ou
 * OMISE (JSON-LD), jamais devinee. Le jour ou le CRM ajoute une valeur, elle
 * s'ajoute ici, au meme commit que la table de sortie.
 *
 * ─── POURQUOI DES TABLES FR/EN ET PAS `__()` ────────────────────────────────
 * Un libelle public suit la langue de L'OFFRE, pas la locale de WordPress :
 * `[pratcom_emplois lang="en"]` sur une page francaise doit afficher « Full
 * time ». `__()` suit la locale ; il donnerait « Temps plein ». Les textes
 * publics sont donc ici, par langue, et tous passent par le filtre
 * `pratcom_connect_jobs_texte` pour qu'un site puisse les reformuler sans
 * toucher au code. L'administration, elle, reste en `__()` comme le reste du
 * plugin.
 *
 * ─── LA SORTIE GOOGLE ────────────────────────────────────────────────────────
 * Regles transposees de `Integrations/Schema.php` + `Support/Vocab.php` de
 * pratcom-connect-jobs 0.7.2 (architecture Jobs HTML §3.6). La base garde la
 * donnee du client ; la conformite vit ici, a la sortie.
 */
final class Vocabulaire
{
    /** `employment_type`, varchar(32). */
    public const TYPES_EMPLOI = ['full_time', 'part_time', 'contractor', 'temporary', 'intern', 'seasonal', 'per_diem'];

    /** `work_mode`, varchar(16). */
    public const MODES_TRAVAIL = ['on_site', 'hybrid', 'remote'];

    /** `experience_level`, varchar(32). */
    public const NIVEAUX_EXPERIENCE = ['entry', 'intermediate', 'senior', 'manager'];

    /** `schedule`, varchar(32). */
    public const HORAIRES = ['day', 'evening', 'night', 'rotating', 'weekend'];

    /** `salary_period`, varchar(16). */
    public const PERIODES_SALAIRE = ['hour', 'day', 'week', 'month', 'year'];

    /** `salary_currency`, varchar(3). */
    public const DEVISES = ['CAD', 'USD'];

    /** Le modele bilingue du CRM : une entree EST une langue. */
    public const LANGUES = ['fr', 'en'];

    /** Champ de l'offre => liste fermee. */
    private const CHAMPS = [
        'employment_type'  => self::TYPES_EMPLOI,
        'work_mode'        => self::MODES_TRAVAIL,
        'experience_level' => self::NIVEAUX_EXPERIENCE,
        'schedule'         => self::HORAIRES,
        'salary_period'    => self::PERIODES_SALAIRE,
        'salary_currency'  => self::DEVISES,
    ];

    /**
     * Libelles publics des valeurs de vocabulaire. Memes sens que les cles
     * `empVoc_*` du CRM, accents retablis (le CRM les ecrit sans accents).
     */
    private const LIBELLES = [
        'employment_type' => [
            'full_time'  => ['fr' => 'Temps plein', 'en' => 'Full time'],
            'part_time'  => ['fr' => 'Temps partiel', 'en' => 'Part time'],
            'contractor' => ['fr' => 'Contractuel', 'en' => 'Contract'],
            'temporary'  => ['fr' => 'Temporaire', 'en' => 'Temporary'],
            'intern'     => ['fr' => 'Stage', 'en' => 'Internship'],
            'seasonal'   => ['fr' => 'Saisonnier', 'en' => 'Seasonal'],
            'per_diem'   => ['fr' => 'Sur appel', 'en' => 'On call'],
        ],
        'work_mode' => [
            'on_site' => ['fr' => 'Sur place', 'en' => 'On site'],
            'hybrid'  => ['fr' => 'Hybride', 'en' => 'Hybrid'],
            'remote'  => ['fr' => 'À distance', 'en' => 'Remote'],
        ],
        'experience_level' => [
            'entry'        => ['fr' => 'Débutant accepté', 'en' => 'Entry level'],
            'intermediate' => ['fr' => 'Intermédiaire', 'en' => 'Intermediate'],
            'senior'       => ['fr' => 'Expérimenté', 'en' => 'Senior'],
            'manager'      => ['fr' => 'Gestion', 'en' => 'Management'],
        ],
        'schedule' => [
            'day'      => ['fr' => 'Quart de jour', 'en' => 'Day shift'],
            'evening'  => ['fr' => 'Quart de soir', 'en' => 'Evening shift'],
            'night'    => ['fr' => 'Quart de nuit', 'en' => 'Night shift'],
            'rotating' => ['fr' => 'Horaire rotatif', 'en' => 'Rotating shift'],
            'weekend'  => ['fr' => 'Fins de semaine', 'en' => 'Weekends'],
        ],
        'salary_period' => [
            'hour'  => ['fr' => "de l'heure", 'en' => 'per hour'],
            'day'   => ['fr' => 'par jour', 'en' => 'per day'],
            'week'  => ['fr' => 'par semaine', 'en' => 'per week'],
            'month' => ['fr' => 'par mois', 'en' => 'per month'],
            'year'  => ['fr' => 'par année', 'en' => 'per year'],
        ],
        'salary_currency' => [
            'CAD' => ['fr' => '$ CA', 'en' => 'CAD'],
            'USD' => ['fr' => '$ US', 'en' => 'USD'],
        ],
    ];

    /** Textes des gabarits publics. Aucun texte public n'est ecrit ailleurs. */
    private const TEXTES = [
        'filtre_categorie'   => ['fr' => 'Catégorie', 'en' => 'Category'],
        'filtre_lieu'        => ['fr' => 'Lieu', 'en' => 'Location'],
        'filtre_type'        => ['fr' => 'Type', 'en' => 'Type'],
        'filtre_horaire'     => ['fr' => 'Horaire', 'en' => 'Schedule'],
        'filtre_etiquette'   => ['fr' => 'Étiquette', 'en' => 'Tag'],
        'filtre_tous'        => ['fr' => 'Tous', 'en' => 'All'],
        'filtrer'            => ['fr' => 'Filtrer', 'en' => 'Filter'],
        'reinitialiser'      => ['fr' => 'Réinitialiser', 'en' => 'Reset'],
        'filtres_titre'      => ['fr' => 'Filtrer les offres', 'en' => 'Filter job offers'],
        'nombre_un'          => ['fr' => '%d offre', 'en' => '%d job'],
        'nombre_plusieurs'   => ['fr' => '%d offres', 'en' => '%d jobs'],
        'vedette'            => ['fr' => 'En vedette', 'en' => 'Featured'],
        'a_distance'         => ['fr' => 'À distance', 'en' => 'Remote'],
        'publiee_le'         => ['fr' => 'Publiée le %s', 'en' => 'Posted %s'],
        'vide'               => ['fr' => 'Aucune offre pour le moment.', 'en' => 'No openings at the moment.'],
        'aucun_resultat'     => ['fr' => 'Aucune offre ne correspond à ces filtres.', 'en' => 'No job matches these filters.'],
        'indisponible'       => ['fr' => 'Les offres sont momentanément indisponibles. Revenez dans quelques minutes.', 'en' => 'Job openings are temporarily unavailable. Please check back in a few minutes.'],
        'voir_offre'         => ['fr' => "Voir l'offre", 'en' => 'View job'],
        'retour'             => ['fr' => 'Toutes les offres', 'en' => 'All job openings'],
        'postuler'           => ['fr' => 'Postuler', 'en' => 'Apply'],
        'postuler_titre'     => ['fr' => 'Postuler à ce poste', 'en' => 'Apply for this job'],
        'meta_lieu'          => ['fr' => 'Lieu', 'en' => 'Location'],
        'meta_type'          => ['fr' => "Type d'emploi", 'en' => 'Job type'],
        'meta_horaire'       => ['fr' => 'Horaire', 'en' => 'Schedule'],
        'meta_mode'          => ['fr' => 'Mode de travail', 'en' => 'Work mode'],
        'meta_niveau'        => ['fr' => "Niveau d'expérience", 'en' => 'Experience level'],
        'meta_salaire'       => ['fr' => 'Salaire', 'en' => 'Salary'],
        'meta_postes'        => ['fr' => 'Postes', 'en' => 'Openings'],
        'meta_date'          => ['fr' => 'Publication', 'en' => 'Posted'],
        'meta_reference'     => ['fr' => 'Référence', 'en' => 'Reference'],
        'salaire_a_partir'   => ['fr' => 'À partir de %s', 'en' => 'From %s'],
        'salaire_jusqu_a'    => ['fr' => "Jusqu'à %s", 'en' => 'Up to %s'],
        'offre_introuvable'  => ['fr' => 'Offre introuvable', 'en' => 'Job not found'],
    ];

    private const MOIS = [
        'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    /** Provinces et territoires : abreviation postale (ce que veut `addressRegion`). */
    private const PROVINCES = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'];

    /** Noms longs frequents => abreviation (repli seulement ; le CRM sert deja le code). */
    private const PROVINCES_NOMS = [
        'alberta' => 'AB', 'colombie britannique' => 'BC', 'british columbia' => 'BC',
        'manitoba' => 'MB', 'nouveau brunswick' => 'NB', 'new brunswick' => 'NB',
        'terre neuve et labrador' => 'NL', 'newfoundland and labrador' => 'NL',
        'nouvelle ecosse' => 'NS', 'nova scotia' => 'NS',
        'territoires du nord ouest' => 'NT', 'northwest territories' => 'NT',
        'nunavut' => 'NU', 'ontario' => 'ON', 'ile du prince edouard' => 'PE',
        'prince edward island' => 'PE', 'quebec' => 'QC', 'saskatchewan' => 'SK', 'yukon' => 'YT',
    ];

    /** Langue publique : `fr` ou `en`, `fr` par defaut (langue source du produit). */
    public static function langue(?string $lang): string
    {
        $lang = strtolower(substr(trim((string) $lang), 0, 2));
        return in_array($lang, self::LANGUES, true) ? $lang : 'fr';
    }

    /** La valeur appartient-elle a la liste fermee du champ ? */
    public static function dans(string $champ, $valeur): bool
    {
        return is_string($valeur) && isset(self::CHAMPS[$champ]) && in_array($valeur, self::CHAMPS[$champ], true);
    }

    /** Libelle public d'une valeur de vocabulaire. Chaine vide si hors liste. */
    public static function libelle(string $champ, $valeur, string $lang): string
    {
        if (!self::dans($champ, $valeur)) {
            return '';
        }
        $lang = self::langue($lang);
        return self::filtrer('libelle_' . $champ . '_' . $valeur, self::LIBELLES[$champ][$valeur][$lang], $lang);
    }

    /**
     * Texte public, avec arguments `sprintf` eventuels.
     *
     * @param mixed ...$args
     */
    public static function texte(string $cle, string $lang, ...$args): string
    {
        $lang = self::langue($lang);
        $brut = self::TEXTES[$cle][$lang] ?? $cle;
        $txt = self::filtrer($cle, $brut, $lang);
        return $args === [] ? $txt : vsprintf($txt, $args);
    }

    /** « 3 offres » / « 1 offre ». */
    public static function nombre(int $n, string $lang): string
    {
        return self::texte($n === 1 ? 'nombre_un' : 'nombre_plusieurs', $lang, $n);
    }

    /** Date longue dans la langue de l'offre, fuseau du site. Vide si illisible. */
    public static function date(?string $iso, string $lang): string
    {
        $ts = self::horodatage($iso);
        if ($ts === null) {
            return '';
        }
        $lang = self::langue($lang);
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone());
        $mois = self::MOIS[$lang][(int) $d->format('n') - 1];
        $jour = (int) $d->format('j');
        if ($lang === 'en') {
            return $mois . ' ' . $jour . ', ' . $d->format('Y');
        }
        return ($jour === 1 ? '1er' : (string) $jour) . ' ' . $mois . ' ' . $d->format('Y');
    }

    /** Horodatage Unix d'une date ISO 8601, `null` si illisible. */
    public static function horodatage(?string $iso): ?int
    {
        if (!is_string($iso) || trim($iso) === '') {
            return null;
        }
        $ts = strtotime($iso);
        return $ts === false ? null : $ts;
    }

    /**
     * Le salaire, JAMAIS en chiffre nu (regle CRM « aucun chiffre nu »).
     *
     * `salary_label` gagne quand il existe. Sinon il faut un montant, une
     * devise ET une periode connues : « 27 » seul ne veut rien dire, « 27 $ CA
     * de l'heure » veut dire quelque chose. Il manque l'une des trois ? Rien
     * n'est affiche, plutot qu'un chiffre qui induit en erreur.
     *
     * @param array<string, mixed> $offre
     */
    public static function salaire(array $offre, string $lang): string
    {
        $libelle = trim((string) ($offre['salary_label'] ?? ''));
        if ($libelle !== '') {
            return $libelle;
        }

        $min = self::montant($offre['salary_min'] ?? null);
        $max = self::montant($offre['salary_max'] ?? null);
        $devise = strtoupper(trim((string) ($offre['salary_currency'] ?? '')));
        $periode = (string) ($offre['salary_period'] ?? '');

        if (($min === null && $max === null) || !self::dans('salary_currency', $devise) || !self::dans('salary_period', $periode)) {
            return '';
        }

        $lang = self::langue($lang);
        $suffixe = self::libelle('salary_currency', $devise, $lang) . ' ' . self::libelle('salary_period', $periode, $lang);

        if ($min !== null && $max !== null && $min !== $max) {
            return self::nombre_format($min, $lang) . ' - ' . self::nombre_format($max, $lang) . ' ' . $suffixe;
        }
        if ($min !== null && $max !== null) {
            return self::nombre_format($min, $lang) . ' ' . $suffixe;
        }
        if ($min !== null) {
            return self::texte('salaire_a_partir', $lang, self::nombre_format($min, $lang) . ' ' . $suffixe);
        }
        return self::texte('salaire_jusqu_a', $lang, self::nombre_format((float) $max, $lang) . ' ' . $suffixe);
    }

    /** Un montant numerique positif, ou `null`. */
    public static function montant($v): ?float
    {
        if ($v === null || $v === '' || is_bool($v) || !is_numeric($v)) {
            return null;
        }
        $n = (float) $v;
        return ($n >= 0 && is_finite($n)) ? $n : null;
    }

    private static function nombre_format(float $n, string $lang): string
    {
        $decimales = (abs($n - round($n)) < 0.005) ? 0 : 2;
        return $lang === 'en'
            ? number_format($n, $decimales, '.', ',')
            : number_format($n, $decimales, ',', "\u{00A0}");
    }

    // ─── Sortie schema.org (JSON-LD) ────────────────────────────────────────

    /** Les 8 `employmentType` que Google accepte. */
    public const GOOGLE_TYPES = ['FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER'];

    /**
     * `employment_type` du CRM => `employmentType` Google.
     *
     * `seasonal` n'a pas d'equivalent : TEMPORARY, la valeur la plus proche et
     * la seule que Google indexe correctement (Vocab.php 0.7.2, §3.6). Une
     * valeur deja normalisee (`FULL_TIME`) passe telle quelle, comme dans
     * Vocab.php. Tout le reste (`SEASONAL`, faute de frappe, valeur d'un autre
     * producteur) rend une chaine vide : la propriete sera OMISE.
     */
    public static function google_type_emploi($valeur): string
    {
        if (!is_string($valeur) || $valeur === '') {
            return '';
        }
        if (in_array($valeur, self::GOOGLE_TYPES, true)) {
            return $valeur;
        }
        $table = [
            'full_time' => 'FULL_TIME', 'part_time' => 'PART_TIME', 'contractor' => 'CONTRACTOR',
            'temporary' => 'TEMPORARY', 'intern' => 'INTERN', 'seasonal' => 'TEMPORARY', 'per_diem' => 'PER_DIEM',
        ];
        return $table[$valeur] ?? '';
    }

    /** `salary_period` du CRM => `unitText` Google (5 valeurs). Vide si non mappable. */
    public static function google_unite_salaire($valeur): string
    {
        $table = ['hour' => 'HOUR', 'day' => 'DAY', 'week' => 'WEEK', 'month' => 'MONTH', 'year' => 'YEAR'];
        return (is_string($valeur) && isset($table[$valeur])) ? $table[$valeur] : '';
    }

    /** Code pays ISO 3166-1 alpha-2, ou vide. Jamais devine. */
    public static function google_pays($valeur): string
    {
        $v = strtoupper(trim((string) $valeur));
        return preg_match('/^[A-Z]{2}$/', $v) ? $v : '';
    }

    /**
     * `location_region` => abreviation postale courte (`QC`, `ON`).
     *
     * Le CRM sert deja le code (selecteur de lieu). Un nom long connu est
     * ramene a son code ; un code court inconnu (etat americain, par exemple)
     * passe s'il a la forme d'un code ; tout le reste rend vide et
     * `addressRegion` est omis.
     */
    public static function google_region($valeur): string
    {
        $brut = trim((string) $valeur);
        if ($brut === '') {
            return '';
        }
        $haut = strtoupper($brut);
        if (in_array($haut, self::PROVINCES, true) || preg_match('/^[A-Z]{2,3}$/', $haut)) {
            return $haut;
        }
        $plie = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(remove_accents($brut))));
        return self::PROVINCES_NOMS[$plie] ?? '';
    }

    private static function filtrer(string $cle, string $texte, string $lang): string
    {
        /**
         * Filtre un texte public du module Emplois (liste, fiche, libelles).
         *
         * @param string $texte Texte par defaut.
         * @param string $cle   Cle du texte (ex. `postuler`, `libelle_schedule_day`).
         * @param string $lang  `fr` ou `en`.
         */
        return (string) apply_filters('pratcom_connect_jobs_texte', $texte, $cle, $lang);
    }
}
