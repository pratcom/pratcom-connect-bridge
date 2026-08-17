<?php

namespace Pratcom\Connect\Bridge\Jobs;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;

/**
 * Feature pack « Emplois » — pont entre le plugin gratuit Connect Jobs et la CIL.
 *
 * Ecoute les hooks d'extensibilite du plugin gratuit (connect_jobs_*) et
 * synchronise vers l'API Pratcom Connect :
 *   - offres publiees / mises a jour -> POST   /api/bridge/offers  (table job_offers, RAG chatbot)
 *   - offres depubliees / supprimees  -> DELETE /api/bridge/offers
 *   - candidatures soumises           -> POST   /api/bridge/events (module='jobs' -> CRM)
 *
 * No-op si le Bridge n'est pas connecte, si le pack Emplois n'est pas actif,
 * ou si le plugin gratuit est absent.
 * Best-effort : un echec d'API n'interrompt jamais l'enregistrement WordPress
 * (ApiClient retourne un tableau, ne leve jamais d'exception).
 * Propriete : chantier Connect Jobs Pro.
 */
class OfferSync
{
    public function __construct()
    {
        add_action('connect_jobs_offer_saved', [$this, 'on_offer_saved'], 10, 2);
        add_action('connect_jobs_offer_deleted', [$this, 'on_offer_deleted'], 10, 1);
        add_action('connect_jobs_application_submitted', [$this, 'on_application_submitted'], 10, 2);
    }

    private function client(): ApiClient
    {
        return new ApiClient();
    }

    /**
     * Le feature pack Emplois est-il actif pour ce site ?
     *
     * Evalue A CHAQUE evenement, jamais dans le constructeur : l'abonnement
     * change au handshake ou au HealthCheck, sans redemarrage de WordPress —
     * une garde posee au boot resterait figee sur l'etat du chargement.
     * Sans cette barriere, tout site connecte ayant le plugin gratuit pousserait
     * ses offres en production, abonnement ou pas.
     *
     * Forme de reference : $feature_packs['jobs']['enabled'] — la meme que
     * ModulesTab et FormsTab. NE PAS copier celle de ChatTab
     * (`!empty($packs['chat'])`), qui renvoie vrai meme quand le serveur repond
     * `['enabled' => false]`, un tableau non vide passant le test.
     * Arbitrage Super Vigie du 2026-08-06 (voie A).
     */
    private function jobs_enabled(): bool
    {
        $packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        return is_array($packs) && !empty($packs['jobs']['enabled']);
    }

    /**
     * @param int   $job_id Identifiant de l'offre.
     * @param mixed $offer  Payload normalise fourni par le plugin gratuit (offer_payload()).
     */
    public function on_offer_saved($job_id, $offer): void
    {
        $api_key = Plugin::get_api_key();
        if (!Plugin::is_connected() || !$this->jobs_enabled() || !$api_key || !is_array($offer)) {
            return;
        }
        $mapped = $this->map_offer((int) $job_id, $offer);
        $this->client()->upsert_offers($api_key, [$mapped]);
    }

    /** @param int $job_id Identifiant de l'offre. */
    public function on_offer_deleted($job_id): void
    {
        $api_key = Plugin::get_api_key();
        if (!Plugin::is_connected() || !$this->jobs_enabled() || !$api_key) {
            return;
        }
        $this->client()->delete_offers($api_key, [(string) $job_id]);
    }

    /**
     * @param int   $application_id Identifiant de la candidature.
     * @param mixed $data           Donnees sanitisees (jamais le binaire CV).
     */
    public function on_application_submitted($application_id, $data): void
    {
        $api_key = Plugin::get_api_key();
        if (!Plugin::is_connected() || !$this->jobs_enabled() || !$api_key) {
            return;
        }
        $payload = is_array($data) ? $data : [];
        // Filet de securite : ne jamais transmettre de chemin/binaire de CV.
        foreach (['cv', 'cv_path', 'cv_file', 'file', 'attachment', 'resume'] as $k) {
            unset($payload[$k]);
        }
        $payload['application_id'] = (int) $application_id;

        $this->client()->send_events($api_key, [[
            'module' => 'jobs',
            'event_type' => 'application_submitted',
            'payload' => $payload,
        ]]);
    }

    /**
     * Transforme le payload du plugin gratuit vers le contrat de l'API /offers.
     * Les cles a valeur null sont retirees (l'API attend des champs absents,
     * pas null, pour les optionnels).
     *
     * MAPPING TOLERANT (contrat fige par la Super Vigie le 2026-08-06, voie C) :
     * le plugin gratuit >= 0.7.0 emet un lieu structure (`city`, `region`,
     * `country`, `location_label`, `is_remote`, `street_address`) deja resolu de
     * son cote par Support\JobLocation — champs dedies, puis texte libre, puis
     * taxonomie. On lit ces cles EN PRIORITE. Les sites encore en 0.6.0 ne les
     * emettent pas : on retombe alors sur l'heuristique de taxonomie d'origine.
     * Aucun site ne fait tourner la 0.7.0 aujourd'hui — sans ce repli, le
     * connecteur serait casse pour la totalite du parc installe.
     *
     * @param int                  $job_id Identifiant de l'offre.
     * @param array<string, mixed> $o      Payload du plugin gratuit.
     * @return array<string, mixed>
     */
    private function map_offer(int $job_id, array $o): array
    {
        $taxonomies = (isset($o['taxonomies']) && is_array($o['taxonomies'])) ? $o['taxonomies'] : [];

        // Categories : termes de connect_job_category.
        $categories = [];
        $category = null;
        if (!empty($taxonomies['connect_job_category']) && is_array($taxonomies['connect_job_category'])) {
            foreach ($taxonomies['connect_job_category'] as $term) {
                if (isset($term['slug'], $term['name'])) {
                    $categories[] = ['slug' => (string) $term['slug'], 'name' => (string) $term['name']];
                }
            }
            if (!empty($categories)) {
                $category = $categories[0]['slug'];
            }
        }

        // ─── Lieu : champs structures d'abord, taxonomie en repli ───────────
        $city   = $this->non_empty_string($o, 'city');
        $region = $this->non_empty_string($o, 'region');

        $tax_loc = (!empty($taxonomies['connect_job_location']) && is_array($taxonomies['connect_job_location']))
            ? array_values($taxonomies['connect_job_location'])
            : [];

        if (null === $city && $tax_loc !== []) {
            // Taxonomie hierarchique connect_job_location : dernier terme = ville.
            $last = end($tax_loc);
            if (is_array($last) && isset($last['name'])) {
                $city = (string) $last['name'];
            }
        }
        if (null === $region && count($tax_loc) >= 2 && isset($tax_loc[0]['name'])) {
            // Premier terme = region, seulement si la hierarchie en compte deux :
            // un terme unique est une ville, pas une province.
            $region = (string) $tax_loc[0]['name'];
        }

        // Le pays n'est jamais devine : 'CA' reste le defaut assume du produit
        // (marche canadien), comme cote plugin gratuit.
        $country = $this->non_empty_string($o, 'country');
        $country = (null !== $country) ? strtoupper($country) : 'CA';

        $label = $this->non_empty_string($o, 'location_label') ?? $this->non_empty_string($o, 'location');

        $salary = (isset($o['salary']) && is_array($o['salary'])) ? $o['salary'] : [];
        $work_mode = isset($o['work_mode']) ? (string) $o['work_mode'] : '';

        // is_remote explicite si le gratuit le fournit, sinon deduit du mode de travail.
        $is_remote = array_key_exists('is_remote', $o) ? (bool) $o['is_remote'] : ('remote' === $work_mode);

        $status_map = ['ouvert' => 'open', 'ferme' => 'closed', 'pourvu' => 'filled'];
        $raw_status = isset($o['status']) ? (string) $o['status'] : 'ouvert';
        $status = $status_map[$raw_status] ?? 'open';

        $posted_at = get_post_time('c', true, $job_id);

        // Meta : cles historiques inchangees. Les ajouts de la 0.7.0 ne sont
        // presents que s'ils sont emis — un site en 0.6.0 obtient exactement
        // le meme meta qu'avant. `street_address` et les deux mentions de
        // conformite ontariennes n'ont pas de champ dedie cote API et voyagent
        // ici : aucune migration de base requise.
        $meta = [
            'recruiter_id' => isset($o['recruiter_id']) ? (int) $o['recruiter_id'] : null,
            'post_status' => isset($o['post_status']) ? (string) $o['post_status'] : null,
        ];
        $street = $this->non_empty_string($o, 'street_address');
        if (null !== $street) {
            $meta['street_address'] = $street;
        }
        if (array_key_exists('ai_screening', $o)) {
            $meta['ai_screening'] = (bool) $o['ai_screening'];
        }
        $vacancy = $this->non_empty_string($o, 'existing_vacancy');
        if (null !== $vacancy) {
            $meta['existing_vacancy'] = $vacancy;
        }

        $mapped = [
            'external_id' => (string) ($o['id'] ?? $job_id),
            'reference' => isset($o['reference']) && '' !== $o['reference'] ? (string) $o['reference'] : null,
            'status' => $status,
            'featured' => !empty($o['featured']),
            'title' => isset($o['title']) ? (string) $o['title'] : '',
            'description' => isset($o['description']) ? (string) $o['description'] : '',
            'url' => !empty($o['url']) ? (string) $o['url'] : null,
            'external_url' => !empty($o['external_url']) ? (string) $o['external_url'] : null,
            'lang' => !empty($o['language']) ? (string) $o['language'] : null,
            'category' => $category,
            'categories' => $categories,
            'employment_type' => !empty($o['employment_type']) ? (string) $o['employment_type'] : null,
            'work_mode' => '' !== $work_mode ? $work_mode : null,
            'experience_level' => !empty($o['experience_level']) ? (string) $o['experience_level'] : null,
            'schedule' => !empty($o['schedule']) ? (string) $o['schedule'] : null,
            'positions' => max(1, (int) ($o['positions'] ?? 1)),
            'location_city' => $city,
            'location_region' => $region,
            'location_country' => $country,
            'location_postal' => !empty($o['postal']) ? (string) $o['postal'] : null,
            'location_label' => $label,
            'is_remote' => $is_remote,
            'salary_min' => (isset($salary['min']) && '' !== $salary['min']) ? (float) $salary['min'] : null,
            'salary_max' => (isset($salary['max']) && '' !== $salary['max']) ? (float) $salary['max'] : null,
            'salary_currency' => !empty($salary['currency']) ? (string) $salary['currency'] : null,
            'salary_period' => !empty($salary['unit']) ? (string) $salary['unit'] : null,
            'taxonomies' => $taxonomies,
            'meta' => $meta,
            'posted_at' => $posted_at ? (string) $posted_at : null,
            'expires_at' => !empty($o['expires_at']) ? (string) $o['expires_at'] : null,
        ];

        // Retire les cles de premier niveau a null (l'API attend l'absence, pas null).
        return array_filter($mapped, static function ($v) {
            return null !== $v;
        });
    }

    /**
     * Lit une cle du payload comme chaine non vide, ou null si absente/vide.
     * Sert a distinguer « le gratuit n'emet pas cette cle » (0.6.0) de
     * « le gratuit l'emet mais elle est vide » — les deux declenchent le repli.
     *
     * @param array<string, mixed> $o   Payload du plugin gratuit.
     * @param string               $key Cle a lire.
     */
    private function non_empty_string(array $o, string $key): ?string
    {
        if (!isset($o[$key]) || !is_scalar($o[$key])) {
            return null;
        }
        $value = trim((string) $o[$key]);
        return '' !== $value ? $value : null;
    }
}
