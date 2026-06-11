<?php

namespace Pratcom\Connect\Bridge\Privacy;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Bannière Privacy Free — mode 100 % LOCAL (spec .org §1/§4, O3).
 *
 * Enqueue privacy.js en mode local (data-mode="local") avec une config
 * inline construite depuis les presets sélectionnés : aucune requête vers
 * Pratcom en runtime. Les consentements sont POSTés au REST local
 * (LocalRegistry). Nécessite privacy.js >= v0.4 (mode local — PR
 * connect-chat jumelle).
 *
 * Actif seulement si : option activée ET pas déjà en tier Connect (le
 * Loader connecté charge déjà la bannière via loader.js — jamais les deux).
 */
class FreeBanner
{
    public const OPTION_ENABLED = 'pratcom_connect_privacy_free_enabled';
    public const OPTION_BADGE_ENABLED = 'pratcom_connect_privacy_free_badge';
    public const HANDLE = 'pratcom-connect-privacy-free';

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue'], 1);
    }

    public static function is_active(): bool
    {
        if (!get_option(self::OPTION_ENABLED)) {
            return false;
        }
        // Tier Connect : la bannière vient du loader connecté, pas du mode Free.
        if (Plugin::is_connected()) {
            $packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
            if (is_array($packs)
                && (array_key_exists('privacy', $packs) || in_array('privacy', $packs, true))
            ) {
                return false;
            }
        }
        return true;
    }

    /** URL de privacy.js (filtrable — même convention que Forms). */
    public static function script_url(): string
    {
        return (string) apply_filters(
            'pratcom_connect_privacy_script_url',
            'https://chatbot.pratcom.net/privacy.js'
        );
    }

    public function maybe_enqueue(): void
    {
        if (!self::is_active()) {
            return;
        }

        wp_enqueue_script(self::HANDLE, self::script_url(), [], PRATCOM_CONNECT_BRIDGE_VERSION, false);
        wp_script_add_data(self::HANDLE, 'strategy', 'defer');

        $policy = PolicyPage::status();
        $config = [
            'version'  => (int) get_option(LocalRegistry::OPTION_BANNER_VERSION, 1),
            'texts'    => (object) [], // défauts FR/EN de privacy.js
            'settings' => [
                'categories'      => ['statistics', 'marketing', 'preferences'],
                'reconsentMonths' => 12,
                'policyUrl'       => $policy['linked'] ? $policy['url'] : '',
                'defaultLang'     => (strpos(get_locale(), 'en') === 0) ? 'en' : 'fr',
            ],
            'theme'    => Plugin::get_theme() ?: null,
            'trackers' => Presets::trackers_for_banner(),
        ];

        // Badge « Propulsé par Pratcom Connect » (spec Badge §5.4) — affiché par
        // défaut, retirable via la case de l'onglet Confidentialité OU le filtre
        // pratcom_connect_branding (conformité guideline WP.org : retrait
        // documenté, voir readme « Attribution Notice »). privacy.js lit
        // config.showBadge.privacy (=== false pour masquer).
        $badge_enabled = get_option(self::OPTION_BADGE_ENABLED, '1') === '1';
        $show_badge = (bool) apply_filters('pratcom_connect_branding', $badge_enabled, 'privacy', null);
        $config['showBadge'] = ['privacy' => $show_badge];

        $local = [
            'config'          => $config,
            'consentEndpoint' => rest_url('pratcom-connect/v1/consent'),
        ];

        wp_add_inline_script(
            self::HANDLE,
            'window.__pratcomPrivacyLocal = ' . wp_json_encode($local) . ';',
            'before'
        );
        add_filter('script_loader_tag', [$this, 'filter_tag'], 10, 2);
    }

    /** Ajoute data-mode="local" + data-client sur le tag de privacy.js. */
    public function filter_tag(string $tag, string $handle): string
    {
        if ($handle !== self::HANDLE || strpos($tag, 'data-mode=') !== false) {
            return $tag;
        }
        $site = sanitize_title((string) get_bloginfo('name'));
        return str_replace(
            ' src=',
            ' data-mode="local" data-client="' . esc_attr($site !== '' ? $site : 'local') . '" src=',
            $tag
        );
    }
}
