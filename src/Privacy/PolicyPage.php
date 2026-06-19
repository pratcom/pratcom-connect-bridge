<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * « Créer la page » — génère (ou détecte) la page WP contenant
 * [pratcom_privacy_policy], l'enregistre dans le réglage WP natif
 * « Page de politique de confidentialité » et expose un statut pour le
 * crochet vert des réglages (P4b, spec §6b).
 *
 * W5 — pages légales bilingues : si WPML ou Polylang est actif, ensure_page()
 * crée + lie automatiquement une page dans CHAQUE langue active (l'admin ne
 * fait rien). Repli inchangé : 1 page si aucun plugin multilingue. Toute la
 * logique multilingue est déléguée à Multilang (défensif, zéro dépendance
 * dure).
 *
 * Le rendu du bouton (render_admin_section) est conçu pour être appelé par
 * l'onglet Confidentialité du shell admin (refactor O0, chantier Plugin
 * .org) — cette classe ne touche PAS SettingsPage.php. Le handler
 * admin_post est autonome (enregistré ici), donc fonctionnel dès que le
 * bouton est affiché.
 */
class PolicyPage
{
    public const OPTION_PAGE_ID = 'pratcom_connect_privacy_policy_page_id';
    // Map code_langue => post_id des pages traduites (WPML / Polylang).
    // OPTION_PAGE_ID reste la page de la langue par défaut.
    public const OPTION_PAGE_IDS_BY_LANG = 'pratcom_connect_privacy_policy_page_ids';
    public const ACTION = 'pratcom_privacy_create_page';
    public const SHORTCODE = 'pratcom_privacy_policy';

    public function __construct()
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle_create']);
    }

    /** Page liée (option, sinon détection par contenu). null si absente. */
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
     * Statut pour le crochet vert : linked = page publiée ET enregistrée
     * dans le réglage WP natif wp_page_for_privacy_policy.
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
        $linked = (int) get_option('wp_page_for_privacy_policy', 0) === $page->ID;
        return [
            'page_id'   => $page->ID,
            'published' => $published,
            'linked'    => $published && $linked,
            'url'       => (string) get_permalink($page),
        ];
    }

    /**
     * Cree (ou publie) la page de politique si nécessaire ET l'enregistre
     * comme page de politique de confidentialité native de WordPress.
     * Idempotent : ne duplique jamais une page contenant déjà le shortcode.
     *
     * Multilingue (WPML / Polylang) : crée + lie une page dans CHAQUE langue
     * active ; OPTION_PAGE_ID = page de la langue par défaut. Repli mono-page
     * sinon. Renvoie l'ID de la page (langue par défaut) ou 0. Sert au
     * handler admin-post ET à l'auto-création à l'activation du plugin.
     */
    public static function ensure_page(): int
    {
        if (Multilang::is_active()) {
            $default_id = self::ensure_multilingual();
            if ($default_id > 0) {
                update_option('wp_page_for_privacy_policy', $default_id);
            }
            return $default_id;
        }

        $page = self::find_page();
        if (!$page) {
            $page_id = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => __('Politique de confidentialité', 'pratcom-connect'),
                'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
            ]);
            if (!is_wp_error($page_id) && $page_id) {
                update_option(self::OPTION_PAGE_ID, (int) $page_id);
                $page = get_post($page_id);
            }
        } elseif ($page->post_status !== 'publish') {
            wp_update_post(['ID' => $page->ID, 'post_status' => 'publish']);
            $page = get_post($page->ID);
        }

        if ($page instanceof \WP_Post) {
            // Enregistrement dans le réglage WP natif (Réglages > Confidentialité)
            update_option('wp_page_for_privacy_policy', $page->ID);
            return (int) $page->ID;
        }
        return 0;
    }

    /**
     * Cree / détecte / lie une page par langue active (WPML ou Polylang).
     * Idempotent : ne recrée jamais une page déjà présente dans une langue
     * (détection par traduction puis par shortcode+langue). Renvoie l'ID de
     * la page de la langue PAR DÉFAUT (ou 0).
     *
     * Appelé uniquement quand Multilang::is_active() est vrai.
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

        // 1) Page de la langue par défaut d'abord (source des traductions).
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

        // 2) Assigner langue + lier le groupe de traduction (idempotent).
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
     * Détecte une page existante pour une langue donnée (idempotence).
     * Ordre : traduction liée à la source (si connue) -> page publiée /
     * brouillon portant le shortcode ET déjà assignée à cette langue.
     * 0 si aucune.
     */
    private static function find_page_for_lang(string $lang, int $source_id): int
    {
        // a) Via le lien de traduction depuis la page source.
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

        // b) Balayage : page portant le shortcode déjà rattachée à $lang.
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

    /** Insère une page (publiée) pour une langue ; shortcode forcé sur $lang. */
    private static function insert_lang_page(string $lang): int
    {
        $page_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => Multilang::page_title('privacy', $lang),
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
            ['page' => 'pratcom-connect', 'pratcom-policy-created' => $page_id ? '1' : '0'],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Bloc « Politique de confidentialité » de l'onglet Confidentialité —
     * à appeler par le shell admin (O0). Autonome : formulaire admin-post.
     */
    public static function render_admin_section(): void
    {
        $status = self::status();
        echo '<h2>' . esc_html__('Page de politique de confidentialité', 'pratcom-connect') . '</h2>';

        if ($status['linked']) {
            echo '<p style="color:#1a7f37;font-weight:600;">✓ '
                . esc_html__('Page publiée et liée — la bannière y pointe automatiquement.', 'pratcom-connect')
                . ' <a href="' . esc_url($status['url']) . '" target="_blank" rel="noopener">'
                . esc_html__('Voir la page', 'pratcom-connect') . '</a></p>';
            return;
        }

        if ($status['page_id'] && !$status['published']) {
            echo '<p style="color:#9a6700;">⚠ '
                . esc_html__('Une page contenant le shortcode existe mais n\'est pas publiée.', 'pratcom-connect') . '</p>';
        } elseif ($status['page_id'] && !$status['linked']) {
            echo '<p style="color:#9a6700;">⚠ '
                . esc_html__('La page existe mais n\'est pas enregistrée comme page de politique de confidentialité de WordPress.', 'pratcom-connect') . '</p>';
        } else {
            echo '<p>' . esc_html__('Aucune page de politique de confidentialité détectée.', 'pratcom-connect') . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
        wp_nonce_field(self::ACTION);
        submit_button(
            $status['page_id']
                ? __('Publier et lier la page', 'pratcom-connect')
                : __('Créer la page', 'pratcom-connect'),
            'secondary',
            'submit',
            false
        );
        echo '</form>';
        echo '<p class="description">'
            . esc_html__('La page contient le shortcode [pratcom_privacy_policy] : contenu généré automatiquement, tableau des témoins inclus, mis à jour sans intervention.', 'pratcom-connect')
            . '</p>';
    }
}
