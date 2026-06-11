<?php

namespace Pratcom\Connect\Bridge\Http;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Route REST LECTURE SEULE exposant les pages/articles PUBLIÉS du site au
 * scanner d'entraînement Chat (Connect « Entraîner depuis mon site », S4).
 *
 * GET /wp-json/pratcom-connect/v1/pages
 *   En-tête : Authorization: Bearer pck_…  — la clé du workspace, stockée
 *   localement par le plugin. MÊME garde que /api/bridge/forms : on valide la
 *   clé pck_ (comparaison à temps constant contre Plugin::get_api_key()).
 *
 * Retour :
 *   { ok, site, generated_at, total, truncated, pages: [
 *       { id, type: 'page'|'post', title, url, slug,
 *         content (HTML rendu via the_content), modified (ISO 8601 UTC) } ] }
 *
 * Lecture seule, contenu PUBLIC uniquement : jamais de brouillon, de page
 * protégée par mot de passe ni de contenu derrière authentification. Le
 * contenu arrive « propre » (corps de l'article rendu, sans chrome de thème) —
 * le scanner n'a plus qu'à extraire le texte et catégoriser. ADDITIF, aucun
 * tag (revue WP.org en cours).
 *
 * Zone : chantier Chatbot (S4 Site Scan). Ne touche aucune zone Forms/Privacy.
 */
class PagesController
{
    public const REST_NS    = 'pratcom-connect/v1';
    public const REST_ROUTE = '/pages';

    /** Plafond dur (le scanner passe ?limit=, borné ici). */
    private const MAX_LIMIT     = 100;
    private const DEFAULT_LIMIT = 50;

    /**
     * Slugs exclus par défaut (panier, compte, paiement, connexion) — le
     * scanner applique aussi ses propres exclusions ; cette liste évite
     * d'exposer du bruit transactionnel.
     */
    private const EXCLUDED_SLUGS = [
        'cart', 'panier', 'checkout', 'commande', 'paiement',
        'my-account', 'mon-compte', 'account', 'compte',
        'wishlist', 'liste-de-souhaits', 'login', 'connexion',
    ];

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::REST_NS, self::REST_ROUTE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [$this, 'authorize'],
            'args'                => [
                'limit' => [
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                ],
                'types' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Garde pck_ — identique à /api/bridge/forms : Bearer = clé du workspace.
     * Comparaison à temps constant contre la clé stockée localement.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function authorize($request)
    {
        if (!Plugin::is_connected()) {
            return new \WP_Error(
                'pratcom_not_connected',
                'Site non connecté à Pratcom Connect.',
                ['status' => 403]
            );
        }

        $stored = (string) Plugin::get_api_key();
        $bearer = self::bearer_from($request);

        if ($stored === '' || $bearer === '' || !hash_equals($stored, $bearer)) {
            return new \WP_Error(
                'pratcom_unauthorized',
                'Clé Pratcom Connect invalide.',
                ['status' => 401]
            );
        }
        return true;
    }

    /**
     * @param \WP_REST_Request $request
     * @return string
     */
    private static function bearer_from($request): string
    {
        $header = '';
        if (is_object($request) && method_exists($request, 'get_header')) {
            $header = (string) $request->get_header('authorization');
        }
        if ($header === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_AUTHORIZATION']));
        }
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }
        return '';
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle($request)
    {
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::MAX_LIMIT);

        $types = self::parse_types((string) $request->get_param('types'));

        // On sur-récupère un peu pour absorber les exclusions, puis on coupe.
        $query = new \WP_Query([
            'post_type'              => $types,
            'post_status'            => 'publish',
            'posts_per_page'         => $limit + count(self::EXCLUDED_SLUGS) + 5,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'has_password'           => false,
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $pages = [];
        foreach ($query->posts as $item) {
            if (count($pages) >= $limit) {
                break;
            }
            if (!($item instanceof \WP_Post)) {
                continue;
            }
            $slug = strtolower((string) $item->post_name);
            if (in_array($slug, self::EXCLUDED_SLUGS, true)) {
                continue;
            }
            // Double garde : jamais de contenu protégé par mot de passe.
            if (post_password_required($item)) {
                continue;
            }

            $pages[] = [
                'id'       => (int) $item->ID,
                'type'     => (string) $item->post_type,
                'title'    => wp_strip_all_tags(get_the_title($item)),
                'url'      => (string) get_permalink($item),
                'slug'     => $slug,
                'content'  => self::render_content($item),
                'modified' => (string) get_post_modified_time('c', true, $item),
            ];
        }

        return new \WP_REST_Response([
            'ok'           => true,
            'site'         => home_url('/'),
            'generated_at' => gmdate('c'),
            'total'        => count($pages),
            'truncated'    => count($query->posts) > $limit,
            'pages'        => $pages,
        ], 200);
    }

    /**
     * Rend le corps d'un article via the_content (shortcodes + blocs Gutenberg)
     * en préservant/restaurant le post global — on ne casse pas l'état WP.
     */
    private static function render_content(\WP_Post $item): string
    {
        global $post;
        $previous = $post;
        $post     = $item;
        setup_postdata($item);
        $html = apply_filters('the_content', $item->post_content);
        wp_reset_postdata();
        $post = $previous;
        return is_string($html) ? $html : '';
    }

    /**
     * Types demandés (défaut : page + post). On n'autorise que des post types
     * publics — jamais de CPT privé.
     *
     * @return string[]
     */
    private static function parse_types(string $raw): array
    {
        $default = ['page', 'post'];
        $raw = trim($raw);
        if ($raw === '') {
            return $default;
        }
        $candidates = array_filter(array_map('sanitize_key', explode(',', $raw)));
        $allowed = [];
        foreach ($candidates as $type) {
            $obj = get_post_type_object($type);
            if ($obj && !empty($obj->public)) {
                $allowed[] = $type;
            }
        }
        return $allowed !== [] ? $allowed : $default;
    }
}
