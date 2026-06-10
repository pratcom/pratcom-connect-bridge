<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * « Créer la page » — génère (ou détecte) la page WP contenant
 * [pratcom_privacy_policy], l'enregistre dans le réglage WP natif
 * « Page de politique de confidentialité » et expose un statut pour le
 * crochet vert des réglages (P4b, spec §6b).
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
    public const ACTION = 'pratcom_privacy_create_page';

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
                && has_shortcode($page->post_content, 'pratcom_privacy_policy')
            ) {
                return $page;
            }
        }

        $pages = get_pages(['number' => 200, 'post_status' => 'publish,draft']);
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, 'pratcom_privacy_policy')) {
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

    /** Handler admin-post (cap manage_options + nonce). */
    public function handle_create(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission refusée.', 'pratcom-connect-bridge'));
        }
        check_admin_referer(self::ACTION);

        $page = self::find_page();
        if (!$page) {
            $page_id = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => __('Politique de confidentialité', 'pratcom-connect-bridge'),
                'post_content' => "<!-- wp:shortcode -->[pratcom_privacy_policy]<!-- /wp:shortcode -->",
            ]);
            if (!is_wp_error($page_id) && $page_id) {
                update_option(self::OPTION_PAGE_ID, (int) $page_id);
                $page = get_post($page_id);
            }
        } elseif ($page->post_status !== 'publish') {
            wp_update_post(['ID' => $page->ID, 'post_status' => 'publish']);
            $page = get_post($page->ID);
        }

        // Enregistrement dans le réglage WP natif (Réglages > Confidentialité)
        if ($page instanceof \WP_Post) {
            update_option('wp_page_for_privacy_policy', $page->ID);
        }

        $redirect = add_query_arg(
            ['page' => 'pratcom-connect', 'pratcom-policy-created' => $page ? '1' : '0'],
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
        echo '<h2>' . esc_html__('Page de politique de confidentialité', 'pratcom-connect-bridge') . '</h2>';

        if ($status['linked']) {
            echo '<p style="color:#1a7f37;font-weight:600;">✓ '
                . esc_html__('Page publiée et liée — la bannière y pointe automatiquement.', 'pratcom-connect-bridge')
                . ' <a href="' . esc_url($status['url']) . '" target="_blank" rel="noopener">'
                . esc_html__('Voir la page', 'pratcom-connect-bridge') . '</a></p>';
            return;
        }

        if ($status['page_id'] && !$status['published']) {
            echo '<p style="color:#9a6700;">⚠ '
                . esc_html__('Une page contenant le shortcode existe mais n\'est pas publiée.', 'pratcom-connect-bridge') . '</p>';
        } elseif ($status['page_id'] && !$status['linked']) {
            echo '<p style="color:#9a6700;">⚠ '
                . esc_html__('La page existe mais n\'est pas enregistrée comme page de politique de confidentialité de WordPress.', 'pratcom-connect-bridge') . '</p>';
        } else {
            echo '<p>' . esc_html__('Aucune page de politique de confidentialité détectée.', 'pratcom-connect-bridge') . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
        wp_nonce_field(self::ACTION);
        submit_button(
            $status['page_id']
                ? __('Publier et lier la page', 'pratcom-connect-bridge')
                : __('Créer la page', 'pratcom-connect-bridge'),
            'secondary',
            'submit',
            false
        );
        echo '</form>';
        echo '<p class="description">'
            . esc_html__('La page contient le shortcode [pratcom_privacy_policy] : contenu généré automatiquement, tableau des témoins inclus, mis à jour sans intervention.', 'pratcom-connect-bridge')
            . '</p>';
    }
}
