<?php

namespace Pratcom\Connect\Bridge\Forms;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Shortcode [pratcom_form slug="contact" lang="fr"].
 *
 * Imprime le conteneur que le renderer Connect Forms (loader.js) hydrate :
 *   <div data-pratcom-form="contact" data-lang="fr"></div>
 * et enfile loader.js avec data-client = SLUG du workspace.
 *
 * Pourquoi le slug et non le workspace_id : loader.js utilise data-client
 * dans l'URL /api/forms/{workspace}/{slug}, resolue par SLUG cote serveur
 * (loadActiveForm WHERE w.slug = ...). Le loader injecte par wp_head
 * (src/Loader.php) passe le workspace_id (UUID) — adapte au chat, pas aux
 * formulaires. Le shortcode enfile donc son propre tag avec le bon slug.
 *
 * Zone chantier Forms (matrice de propriete : « onglet Formulaires +
 * shortcode [pratcom_form] »). Ne touche pas l'onglet Apparence (Privacy).
 */
class Shortcode
{
    public const HANDLE = 'pratcom-connect-forms-loader';

    public function __construct()
    {
        add_shortcode('pratcom_form', [$this, 'render']);
        add_filter('script_loader_tag', [$this, 'filter_loader_tag'], 10, 3);
    }

    /** URL du renderer Connect Forms (filtrable pour staging / self-host). */
    public static function loader_url(): string
    {
        return (string) apply_filters(
            'pratcom_connect_forms_loader_url',
            'https://chatbot.pratcom.net/loader.js'
        );
    }

    /** Le feature pack `forms` est-il actif pour ce workspace ? */
    private function forms_pack_active(): bool
    {
        $packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        if (!is_array($packs)) {
            return false;
        }
        // feature_packs peut etre une map { forms: {...} } ou une liste [ "forms" ].
        return array_key_exists('forms', $packs) || in_array('forms', $packs, true);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function render($atts): string
    {
        $atts = shortcode_atts(
            [
                'slug' => '',
                'id'   => '', // alias de slug
                'lang' => '',
            ],
            $atts,
            'pratcom_form'
        );

        if (!Plugin::is_connected()) {
            return $this->maybe_admin_notice('Pratcom Connect n\'est pas connecte.');
        }
        if (!$this->forms_pack_active()) {
            return $this->maybe_admin_notice('Le module Formulaires n\'est pas active pour ce site.');
        }

        $slug = $atts['slug'] !== '' ? $atts['slug'] : $atts['id'];
        $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', (string) $slug));
        if ($slug === '') {
            return $this->maybe_admin_notice('Shortcode [pratcom_form] : attribut slug manquant.');
        }

        $lang = strtolower((string) $atts['lang']);
        $lang = in_array($lang, ['fr', 'en'], true) ? $lang : '';

        $workspace_slug = (string) get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
        if ($workspace_slug === '') {
            return $this->maybe_admin_notice('Workspace Pratcom Connect introuvable.');
        }

        // Enfile le renderer une seule fois, peu importe le nombre de shortcodes.
        wp_enqueue_script(
            self::HANDLE,
            self::loader_url(),
            [],
            null,
            ['strategy' => 'defer', 'in_footer' => true]
        );

        return sprintf(
            '<div data-pratcom-form="%s"%s></div>',
            esc_attr($slug),
            $lang !== '' ? ' data-lang="' . esc_attr($lang) . '"' : ''
        );
    }

    /**
     * Injecte data-client (slug du workspace) sur le tag du renderer.
     */
    public function filter_loader_tag(string $tag, string $handle, string $src): string
    {
        if ($handle !== self::HANDLE) {
            return $tag;
        }
        $workspace_slug = (string) get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
        if ($workspace_slug === '' || strpos($tag, 'data-client=') !== false) {
            return $tag;
        }
        return str_replace(
            ' src=',
            ' data-client="' . esc_attr($workspace_slug) . '" src=',
            $tag
        );
    }

    /** Indice visible des editeurs uniquement (commentaire HTML), jamais des visiteurs. */
    private function maybe_admin_notice(string $message): string
    {
        if (current_user_can('edit_posts')) {
            return '<!-- pratcom_form: ' . esc_html($message) . ' -->';
        }
        return '';
    }
}
