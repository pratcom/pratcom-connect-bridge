<?php

namespace Pratcom\Connect\Bridge\Jobs;

/**
 * Le `JobPosting` (JSON-LD) d'une fiche, dans la SOURCE de la page.
 *
 * Regles transposees de `Integrations/Schema.php` (pratcom-connect-jobs
 * 0.7.2), architecture Jobs HTML §3.6 :
 *
 *  - obligatoires : `datePosted`, `description`, `hiringOrganization`,
 *    `jobLocation`, `title` (`jobLocation` peut manquer : voir lieu partiel) ;
 *  - `datePosted` : `posted_at`, sinon `updated_at`. Sans aucune des deux,
 *    AUCUN JobPosting n'est emis (26/11) : la route ne sert pas de date de
 *    creation, et prendre le jour du rendu inventerait une date. Une offre
 *    sans JobPosting reste visible sur le site ; un JobPosting sans propriete
 *    requise est invalide pour Google ;
 *  - `baseSalary` : montant, periode ET devise du vocabulaire, sinon omis ;
 *  - `employmentType` ferme a 8 valeurs, `seasonal` => TEMPORARY, valeur non
 *    mappable => propriete OMISE ;
 *  - lieu : ville ET pays ISO-2, sinon AUCUN `jobLocation` (une adresse
 *    partielle est rejetee par Google) ; `addressRegion` en code court ;
 *  - `jobLocationType: TELECOMMUTE` seulement si `is_remote` ET mode
 *    `remote` : l'hybride n'est pas du teletravail. Google exige alors au
 *    moins un pays (`applicantLocationRequirements`). Sans pays ISO-2
 *    resolu, repli (26/11) : ni `TELECOMMUTE` ni exigence de lieu, l'offre
 *    est balisee comme une offre sans lieu (meme cas que le lieu partiel) ;
 *  - logo de `hiringOrganization` : icone du site, emise seulement si sa
 *    taille est lisible, d'au moins 112 x 112 px et d'un rapport
 *    largeur/hauteur entre 0,75 et 2,5 (doc Google relue le 26/11/2026) ;
 *    sinon omis (le logo est recommande, pas requis) ;
 *  - `title` emis TEL QUEL (donnee du client) ; `alertes_titre()` signale a
 *    l'onglet Emplois un titre qui porte le lieu ou le nom de l'employeur,
 *    ce que Google demande d'eviter ;
 *  - `validThrough` = `expires_at` TEL QUEL (deja 23:59:59 local depuis T2) ;
 *  - `identifier` = `PropertyValue{name: espace, value: id}` ;
 *  - `directApply` vrai si le formulaire est sur la page, faux sinon ;
 *  - un seul `JobPosting` par page.
 *
 * ⚠️ Un ecart assume avec §3.6 : sans periode de salaire connue,
 * `baseSalary` est OMIS au lieu de prendre `YEAR` par defaut. Le CRM exige
 * la periode avec le montant ; si elle manque quand meme, « 27 par annee »
 * serait une valeur inventee, et la liste refuse deja d'afficher ce chiffre.
 */
final class JsonLd
{
    private static bool $emis = false;

    public function __construct()
    {
        add_action('wp_head', [$this, 'emettre'], 90);
    }

    public function emettre(): void
    {
        $offre = Fiche::offre_courante();
        if ($offre === null || self::$emis) {
            return;
        }
        /**
         * Desactive le JobPosting du Bridge (un autre plugin l'emet deja).
         *
         * @param bool  $desactive
         * @param array $offre
         */
        if (apply_filters('pratcom_connect_jobs_desactiver_jsonld', false, $offre)) {
            return;
        }

        $schema = self::construire($offre, [
            'espace'     => Catalogue::espace(),
            'url'        => Fiche::url_courante(),
            'formulaire' => Fiche::formulaire_sur_la_page(),
        ]);
        if ($schema === []) {
            return;
        }
        $json = wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        if (!is_string($json)) {
            return;
        }
        self::$emis = true;
        echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    /** Remise a zero (bancs). */
    public static function reinitialiser(): void
    {
        self::$emis = false;
    }

    /**
     * Le balisage d'une offre. Pur : le banc l'appelle sans WordPress rendu.
     * Tableau vide quand l'offre n'a aucune date reelle (aucun JobPosting).
     *
     * @param array<string, mixed>                              $o
     * @param array{espace: string, url: string, formulaire: bool} $ctx
     * @return array<string, mixed>
     */
    public static function construire(array $o, array $ctx): array
    {
        // Jamais un `datePosted` vide ni absent : sans date reelle, aucun
        // JobPosting (voir l'en-tete). Le filtre n'est pas appele.
        $publiee = self::date_publication($o);
        if ($publiee === '') {
            return [];
        }

        $schema = [
            '@context'           => 'https://schema.org/',
            '@type'              => 'JobPosting',
            'title'              => (string) ($o['title'] ?? ''),
            'description'        => Fiche::description_html((string) ($o['description'] ?? '')),
            'hiringOrganization' => self::employeur($o),
            'identifier'         => [
                '@type' => 'PropertyValue',
                'name'  => (string) $ctx['espace'],
                'value' => (string) ($o['id'] ?? ''),
            ],
            'datePosted'         => $publiee,
        ];

        if (($ctx['url'] ?? '') !== '') {
            $schema['url'] = (string) $ctx['url'];
        }

        $expire = $o['expires_at'] ?? null;
        if (is_string($expire) && $expire !== '') {
            $schema['validThrough'] = $expire;
        }

        $type = Vocabulaire::google_type_emploi($o['employment_type'] ?? null);
        if ($type !== '') {
            $schema['employmentType'] = $type;
        }

        $adresse = self::adresse($o);
        if ($adresse !== []) {
            $schema['jobLocation'] = ['@type' => 'Place', 'address' => $adresse];
        }

        // Jamais `TELECOMMUTE` sans pays : Google l'exige (voir l'en-tete).
        if (!empty($o['is_remote']) && ($o['work_mode'] ?? null) === 'remote') {
            $pays = Vocabulaire::google_pays($o['location_country'] ?? '');
            if ($pays !== '') {
                $schema['jobLocationType'] = 'TELECOMMUTE';
                $schema['applicantLocationRequirements'] = ['@type' => 'Country', 'name' => $pays];
            }
        }

        $salaire = self::salaire($o);
        if ($salaire !== []) {
            $schema['baseSalary'] = $salaire;
        }

        $schema['directApply'] = Fiche::url_externe($o) === '' && !empty($ctx['formulaire']);

        /**
         * Filtre le JobPosting avant encodage.
         *
         * @param array $schema
         * @param array $offre
         */
        return (array) apply_filters('pratcom_connect_jobs_jsonld', $schema, $o);
    }

    /** `posted_at`, a defaut `updated_at` : deux dates reelles de l'offre. */
    private static function date_publication(array $o): string
    {
        foreach (['posted_at', 'updated_at'] as $cle) {
            if (is_string($o[$cle] ?? null) && $o[$cle] !== '') {
                return $o[$cle];
            }
        }
        return '';
    }

    /** Nom de l'employeur declare a Google (defaut : nom du site). */
    public static function nom_employeur(array $o): string
    {
        /**
         * Nom de l'employeur declare a Google (defaut : nom du site).
         *
         * @param string $nom
         * @param array  $offre
         */
        return (string) apply_filters('pratcom_connect_jobs_employeur', get_bloginfo('name'), $o);
    }

    private static function employeur(array $o): array
    {
        $org = [
            '@type'  => 'Organization',
            'name'   => self::nom_employeur($o),
            'sameAs' => home_url('/'),
        ];
        $logo_id = (int) get_option('site_icon');
        if ($logo_id > 0) {
            $image = wp_get_attachment_image_src($logo_id, 'full');
            if (is_array($image) && is_string($image[0] ?? null) && $image[0] !== ''
                && self::logo_admissible((int) ($image[1] ?? 0), (int) ($image[2] ?? 0))) {
                $org['logo'] = $image[0];
            }
        }
        return $org;
    }

    /**
     * Le logo respecte-t-il la doc Google ? Taille lisible, 112 x 112 px au
     * moins, rapport largeur/hauteur entre 0,75 et 2,5 (bornes comprises).
     * Taille inconnue (0, par exemple un SVG sans dimensions) : non.
     */
    public static function logo_admissible(int $largeur, int $hauteur): bool
    {
        if ($largeur < 112 || $hauteur < 112) {
            return false;
        }
        $rapport = $largeur / $hauteur;
        return $rapport >= 0.75 && $rapport <= 2.5;
    }

    /**
     * Ce que Google demande d'eviter dans `title` : le lieu ou le nom de
     * l'entreprise. Le titre n'est JAMAIS modifie (donnee du client) ; la
     * liste sert seulement a l'avertissement de l'onglet Emplois.
     *
     * Comparaison sans casse ni accents, sur des mots entiers : la ville et
     * la region de l'offre (3 lettres au moins, pour qu'un code de province
     * ne declenche rien), puis le nom de l'employeur.
     *
     * @return list<string> `lieu`, `entreprise`, ou liste vide
     */
    public static function alertes_titre(array $o, string $employeur): array
    {
        $titre = self::plier((string) ($o['title'] ?? ''));
        if ($titre === '') {
            return [];
        }
        $alertes = [];
        foreach (['location_city', 'location_region'] as $cle) {
            if (self::contient_mots($titre, self::plier((string) ($o[$cle] ?? '')))) {
                $alertes[] = 'lieu';
                break;
            }
        }
        if (self::contient_mots($titre, self::plier($employeur))) {
            $alertes[] = 'entreprise';
        }
        return $alertes;
    }

    /** Minuscules, sans accents ; tout ce qui n'est ni lettre ni chiffre devient une espace. */
    private static function plier(string $texte): string
    {
        if (function_exists('remove_accents')) {
            $texte = remove_accents($texte);
        }
        $texte = function_exists('mb_strtolower') ? mb_strtolower($texte, 'UTF-8') : strtolower($texte);
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texte));
    }

    /** La suite de mots `$aiguille` apparait-elle, entiere, dans `$botte` ? */
    private static function contient_mots(string $botte, string $aiguille): bool
    {
        // Compte en caracteres sans exiger mbstring.
        if ((int) preg_match_all('/./us', $aiguille) < 3) {
            return false;
        }
        return str_contains(' ' . $botte . ' ', ' ' . $aiguille . ' ');
    }

    /** `PostalAddress` complete, ou tableau vide (lieu partiel = aucun lieu). */
    private static function adresse(array $o): array
    {
        $ville = trim((string) ($o['location_city'] ?? ''));
        $pays = Vocabulaire::google_pays($o['location_country'] ?? '');
        if ($ville === '' || $pays === '') {
            return [];
        }
        $a = ['@type' => 'PostalAddress', 'addressLocality' => $ville];
        $region = Vocabulaire::google_region($o['location_region'] ?? '');
        if ($region !== '') {
            $a['addressRegion'] = $region;
        }
        $postal = trim((string) ($o['location_postal'] ?? ''));
        if ($postal !== '') {
            $a['postalCode'] = $postal;
        }
        $a['addressCountry'] = $pays;
        return $a;
    }

    /** `MonetaryAmount`, ou tableau vide. */
    private static function salaire(array $o): array
    {
        $min = Vocabulaire::montant($o['salary_min'] ?? null);
        $max = Vocabulaire::montant($o['salary_max'] ?? null);
        $unite = Vocabulaire::google_unite_salaire($o['salary_period'] ?? null);
        $devise = strtoupper(trim((string) ($o['salary_currency'] ?? '')));
        // Comme `Vocabulaire::salaire()` : montant, periode ET devise du
        // vocabulaire, sinon rien. La devise n'est jamais inventee.
        if (($min === null && $max === null) || $unite === '' || !Vocabulaire::dans('salary_currency', $devise)) {
            return [];
        }
        $valeur = ['@type' => 'QuantitativeValue', 'unitText' => $unite];
        if ($min !== null && $max !== null) {
            $valeur['minValue'] = $min;
            $valeur['maxValue'] = $max;
        } else {
            $valeur['value'] = $min ?? $max;
        }
        return [
            '@type'    => 'MonetaryAmount',
            'currency' => $devise,
            'value'    => $valeur,
        ];
    }
}
