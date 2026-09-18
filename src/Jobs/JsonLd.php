<?php

namespace Pratcom\Connect\Bridge\Jobs;

/**
 * Le `JobPosting` (JSON-LD) d'une fiche, dans la SOURCE de la page.
 *
 * Regles transposees de `Integrations/Schema.php` (pratcom-connect-jobs
 * 0.7.2), architecture Jobs HTML §3.6 :
 *
 *  - obligatoires : `datePosted`, `description`, `hiringOrganization`,
 *    `jobLocation`, `title` (`jobLocation` peut manquer : voir lieu partiel ;
 *    `datePosted` aussi, sans date reelle : jamais une valeur vide) ;
 *  - `baseSalary` : montant, periode ET devise du vocabulaire, sinon omis ;
 *  - `employmentType` ferme a 8 valeurs, `seasonal` => TEMPORARY, valeur non
 *    mappable => propriete OMISE ;
 *  - lieu : ville ET pays ISO-2, sinon AUCUN `jobLocation` (une adresse
 *    partielle est rejetee par Google) ; `addressRegion` en code court ;
 *  - `jobLocationType: TELECOMMUTE` seulement si `is_remote` ET mode
 *    `remote` : l'hybride n'est pas du teletravail ;
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
     *
     * @param array<string, mixed>                              $o
     * @param array{espace: string, url: string, formulaire: bool} $ctx
     * @return array<string, mixed>
     */
    public static function construire(array $o, array $ctx): array
    {
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
        ];

        // Jamais un `datePosted` vide : sans date reelle, la propriete est omise.
        $publiee = self::date_publication($o);
        if ($publiee !== '') {
            $schema['datePosted'] = $publiee;
        }

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

        if (!empty($o['is_remote']) && ($o['work_mode'] ?? null) === 'remote') {
            $schema['jobLocationType'] = 'TELECOMMUTE';
            $pays = Vocabulaire::google_pays($o['location_country'] ?? '');
            if ($pays !== '') {
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

    private static function employeur(array $o): array
    {
        /**
         * Nom de l'employeur declare a Google (defaut : nom du site).
         *
         * @param string $nom
         * @param array  $offre
         */
        $org = [
            '@type'  => 'Organization',
            'name'   => (string) apply_filters('pratcom_connect_jobs_employeur', get_bloginfo('name'), $o),
            'sameAs' => home_url('/'),
        ];
        $logo_id = (int) get_option('site_icon');
        if ($logo_id > 0) {
            $logo = wp_get_attachment_image_url($logo_id, 'full');
            if ($logo) {
                $org['logo'] = $logo;
            }
        }
        return $org;
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
