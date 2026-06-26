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
 * No-op si le Bridge n'est pas connecte ou si le plugin gratuit est absent.
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
     * @param int   $job_id Identifiant de l'offre.
     * @param mixed $offer  Payload normalise fourni par le plugin gratuit (offer_payload()).
     */
    public function on_offer_saved($job_id, $offer): void
    {
        $api_key = Plugin::get_api_key();
        if (!Plugin::is_connected() || !$api_key || !is_array($offer)) {
            return;
        }
        $mapped = $this->map_offer((int) $job_id, $offer);
        $this->client()->upsert_offers($api_key, [$mapped]);
    }

    /** @param int $job_id Identifiant de l'offre. */
    public function on_offer_deleted($job_id): void
    {
        $api_key = Plugin::get_api_key();
        if (!Plugin::is_connected() || !$api_key) {
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
        if (!Plugin::is_connected() || !$api_key) {
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

        // Lieu : taxonomie hierarchique connect_job_location (region > ville).
        $city = null;
        $region = null;
        if (!empty($taxonomies['connect_job_location']) && is_array($taxonomies['connect_job_location'])) {
            $loc = array_values($taxonomies['connect_job_location']);
            $last = end($loc);
            if (is_array($last) && isset($last['name'])) {
                $city = (string) $last['name'];
            }
            if (count($loc) >= 2 && isset($loc[0]['name'])) {
                $region = (string) $loc[0]['name'];
            }
        }

        $salary = (isset($o['salary']) && is_array($o['salary'])) ? $o['salary'] : [];
        $work_mode = isset($o['work_mode']) ? (string) $o['work_mode'] : '';

        $status_map = ['ouvert' => 'open', 'ferme' => 'closed', 'pourvu' => 'filled'];
        $raw_status = isset($o['status']) ? (string) $o['status'] : 'ouvert';
        $status = $status_map[$raw_status] ?? 'open';

        $posted_at = get_post_time('c', true, $job_id);

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
            'location_country' => 'CA',
            'location_postal' => !empty($o['postal']) ? (string) $o['postal'] : null,
            'location_label' => !empty($o['location']) ? (string) $o['location'] : null,
            'is_remote' => ('remote' === $work_mode),
            'salary_min' => (isset($salary['min']) && '' !== $salary['min']) ? (float) $salary['min'] : null,
            'salary_max' => (isset($salary['max']) && '' !== $salary['max']) ? (float) $salary['max'] : null,
            'salary_currency' => !empty($salary['currency']) ? (string) $salary['currency'] : null,
            'salary_period' => !empty($salary['unit']) ? (string) $salary['unit'] : null,
            'taxonomies' => $taxonomies,
            'meta' => [
                'recruiter_id' => isset($o['recruiter_id']) ? (int) $o['recruiter_id'] : null,
                'post_status' => isset($o['post_status']) ? (string) $o['post_status'] : null,
            ],
            'posted_at' => $posted_at ? (string) $posted_at : null,
            'expires_at' => !empty($o['expires_at']) ? (string) $o['expires_at'] : null,
        ];

        // Retire les cles de premier niveau a null (l'API attend l'absence, pas null).
        return array_filter($mapped, static function ($v) {
            return null !== $v;
        });
    }
}
