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
 * Le rendu du bouton (render_admin_section) est appele par l'onglet
 * Confidentialite du shell admin — cette classe ne touche pas SettingsPage /
 * AdminShell. Le handler admin_post est autonome (enregistre ici).
 */
class CookiePolicyPage
{
    public const OPTION_PAGE_ID = 'pratcom_connect_privacy_cookie_page_id';
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
     * ne duplique jamais une page contenant deja le shortcode. Renvoie l'ID
     * de page (ou 0). Sert au handler ET a l'auto-creation a l'activation.
     */
    public static function ensure_page(): int
    {
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
