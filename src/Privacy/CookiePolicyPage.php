<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * « Creer la page » — genere (ou detecte) la page WP contenant
 * [pratcom_cookie_declaration] : declaration de temoins autonome, style
 * Cookiebot (Privacy Free, spec legal pages org).
 *
 * Calque de PolicyPage. Difference : il n'existe PAS de reglage WordPress
 * natif pour une « page de politique des temoins » (contrairement a
 * wp_page_for_privacy_policy). On se contente donc de creer/publier la page
 * et de memoriser son ID ; le statut « linked » = page simplement publiee.
 *
 * W5 — pages legales bilingues : si WPML ou Polylang est actif, ensure_page()
 * cree + lie automatiquement une page dans CHAQUE langue active (l'admin ne
 * fait rien). Repli inchange : 1 page si aucun plugin multilingue. Logique
 * multilingue deleguee a Multilang (defensif, zero dependance dure).
 *
 * Le rendu du bouton (render_admin_section) est appele par l'onglet
 * Confidentialite du shell admin — cette classe ne touche pas SettingsPage /
 * AdminShell. Le handler admin_post est autonome (enregistre ici).
 */
class CookiePolicyPage
{
    public const OPTION_PAGE_ID = 'pratcom_connect_privacy_cookie_page_id';
    // Map code_langue => post_id des pages traduites (WPML / Polylang).
    public const OPTION_PAGE_IDS_BY_LANG = 'pratcom_connect_privacy_cookie_page_ids';
    public const ACTION = 'pratcom_privacy_create_cookie_page';
    public const SHORTCODE = 'pratcom_cookie_declaration';

    public function __construct()
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle_create']);
    }

    /** Page liee (option, sinon detection par contenu). null si absente. */
    public static function find_page(): ?\WP_Post
    {
        $saved = (int) get_option(self::OPTION_PAGE_ID, 0);
        if ($saved) {
            $page = get_post($saved);
            if ($page instanceof \WP_Post
                && $page->post_type === 'page'
                && $page->post_status !== 'trash'
                && has_shortcode($page->post_content, self::SHORTCODE)
            ) {
                return $page;
            }
        }

        $pages = get_pages(['number' => 200, 'post_status' => 'publish,draft']);
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, self::SHORTCODE)) {
                update_option(self::OPTION_PAGE_ID, $page->ID);
                return $page;
            }
        }
        return null;
    }

    /**
     * Statut pour le crochet vert : linked = page detectee ET publiee.
     *
     * @return array{page_id: int, published: bool, linked: bool, url: string}
     */
    public static function status(): array
    {
        $page = self::find_page();
        if (!$page) {
            return ['page_id' => 0, 'published' => false, 'linked' => false, 'url' => ''];
        }
        $published = $page->post_status === 'publish';
        return [
            'page_id'   => $page->ID,
            'published' => $published,
            'linked'    => $published,
            'url'       => (string) get_permalink($page),
        ];
    }

    /**
     * Cree (et publie) la page si elle n'existe pas encore. Idempotent :
     * ne duplique jamais une page contenant deja le shortcode.
     *
     * Multilingue (WPML / Polylang) : cree + lie une page dans CHAQUE langue
     * active ; OPTION_PAGE_ID = page de la langue par defaut. Repli mono-page
     * sinon. Renvoie l'ID de page (langue par defaut) ou 0. Sert au handler
     * ET a l'auto-creation a l'activation.
     */
    public static function ensure_page(): int
    {
        if (Multilang::is_active()) {
            return self::ensure_multilingual();
        }

        $page = self::find_page();
        if ($page instanceof \WP_Post) {
            if ($page->post_status !== 'publish') {
                wp_update_post(['ID' => $page->ID, 'post_status' => 'publish']);
            }
            update_option(self::OPTION_PAGE_ID, (int) $page->ID);
            return (int) $page->ID;
        }

        $page_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __('Politique relative aux témoins', 'pratcom-connect'),
            'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
        ]);
        if (!is_wp_error($page_id) && $page_id) {
            update_option(self::OPTION_PAGE_ID, (int) $page_id);
            return (int) $page_id;
        }
        return 0;
    }

    /**
     * Cree / detecte / lie une page par langue active (WPML ou Polylang).
     * Idempotent : ne recree jamais une page deja presente dans une langue.
     * Renvoie l'ID de la page de la langue PAR DEFAUT (ou 0).
     *
     * Appele uniquement quand Multilang::is_active() est vrai.
     */
    private static function ensure_multilingual(): int
    {
        $languages = Multilang::languages();
        $default   = Multilang::default_language();

        if (empty($languages)) {
            return 0;
        }
        if ($default === '' || !in_array($default, $languages, true)) {
            $default = (string) reset($languages);
        }

        $ordered = array_merge([$default], array_values(array_diff($languages, [$default])));

        $by_lang   = [];
        $source_id = 0;

        foreach ($ordered as $lang) {
            $existing = self::find_page_for_lang($lang, $source_id);
            if ($existing > 0) {
                $post = get_post($existing);
                if ($post instanceof \WP_Post && $post->post_status !== 'publish') {
                    wp_update_post(['ID' => $existing, 'post_status' => 'publish']);
                }
                $page_id = $existing;
            } else {
                $page_id = self::insert_lang_page($lang);
            }

            if ($page_id > 0) {
                $by_lang[$lang] = $page_id;
                if ($lang === $default) {
                    $source_id = $page_id;
                }
            }
        }

        foreach ($by_lang as $lang => $page_id) {
            Multilang::link_translation($page_id, $lang, $by_lang, $default, $source_id);
        }

        if (!empty($by_lang)) {
            update_option(self::OPTION_PAGE_IDS_BY_LANG, array_map('intval', $by_lang));
        }

        $default_id = isset($by_lang[$default]) ? (int) $by_lang[$default] : 0;
        if ($default_id > 0) {
            update_option(self::OPTION_PAGE_ID, $default_id);
        }
        return $default_id;
    }

    /**
     * Detecte une page existante pour une langue donnee (idempotence).
     * Ordre : traduction liee a la source (si connue) -> page portant le
     * shortcode ET deja assignee a cette langue. 0 si aucune.
     */
    private static function find_page_for_lang(string $lang, int $source_id): int
    {
        if ($source_id > 0) {
            $tid = Multilang::translation_id($source_id, $lang);
            if ($tid > 0) {
                $post = get_post($tid);
                if ($post instanceof \WP_Post
                    && $post->post_type === 'page'
                    && $post->post_status !== 'trash'
                    && has_shortcode($post->post_content, self::SHORTCODE)
                ) {
                    return (int) $tid;
                }
            }
        }

        $pages = get_pages(['number' => 200, 'post_status' => 'publish,draft']);
        foreach ($pages as $page) {
            if (!has_shortcode($page->post_content, self::SHORTCODE)) {
                continue;
            }
            if (Multilang::post_language((int) $page->ID) === $lang) {
                return (int) $page->ID;
            }
        }
        return 0;
    }

    /** Insere une page (publiee) pour une langue ; shortcode force sur $lang. */
    private static function insert_lang_page(string $lang): int
    {
        $page_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => Multilang::page_title('cookie', $lang),
            'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ' lang="' . esc_attr($lang) . '"]<!-- /wp:shortcode -->',
        ]);
        return (!is_wp_error($page_id) && $page_id) ? (int) $page_id : 0;
    }

    /** Handler admin-post (cap manage_options + nonce). */
    public function handle_create(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission refusée.', 'pratcom-connect'));
        }
        check_admin_referer(self::ACTION);

        $page_id = self::ensure_page();

        $redirect = add_query_arg(
            ['page' => 'pratcom-connect-privacy', 'pratcom-cookie-page-created' => $page_id ? '1' : '0'],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Bloc « Politique relative aux temoins » de l'onglet Confidentialite —
     * a appeler par le shell admin. Autonome : formulaire admin-post.
     */
    public static function render_admin_section(): void
    {
        $status = self::status();
        echo '<h2>' . esc_html__('Page de politique relative aux témoins', 'pratcom-connect') . '</h2>';

        if ($status['linked']) {
            echo '<p style="color:#1a7f37;font-weight:600;">✓ '
                . esc_html__('Page publiée — déclaration de témoins autonome (mise à jour automatiquement).', 'pratcom-connect')
                . ' <a href="' . esc_url($status['url']) . '" target="_blank" rel="noopener">'
                . esc_html__('Voir la page', 'pratcom-connect') . '</a></p>';
            return;
        }

        if ($status['page_id'] && !$status['published']) {
            echo '<p style="color:#9a6700;">⚠ '
                . esc_html__('Une page contenant le shortcode existe mais n\'est pas publiée.', 'pratcom-connect') . '</p>';
        } else {
            echo '<p>' . esc_html__('Aucune page de politique relative aux témoins détectée.', 'pratcom-connect') . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
        wp_nonce_field(self::ACTION);
        submit_button(
            $status['page_id']
                ? __('Publier la page', 'pratcom-connect')
                : __('Créer la page', 'pratcom-connect'),
            'secondary',
            'submit',
            false
        );
        echo '</form>';
        echo '<p class="description">'
            . esc_html__('La page contient le shortcode [pratcom_cookie_declaration] : tableau des témoins groupé par catégorie, bilingue, généré automatiquement à partir de vos presets, de votre liste manuelle et du scan local.', 'pratcom-connect')
            . '</p>';
    }
}
