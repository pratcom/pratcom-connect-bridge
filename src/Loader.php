<?php

namespace Pratcom\Connect\Bridge;

/**
 * Injection du loader Pratcom Connect dans le <head>.
 *
 * Pattern V2 :
 *   1. Inline script window.__pratcomConnect = { workspaceId, workspaceSlug, featurePacks }
 *   2. <script src="connect.pratcom.net/loader.js" defer>
 *
 * Le loader.js cote serveur lit window.__pratcomConnect au boot et charge
 * dynamiquement les bundles selon les feature_packs actifs (chat, privacy, etc.).
 */
class Loader
{
    public function __construct()
    {
        add_action('wp_head', [$this, 'inject_loader'], 5);
    }

    public function inject_loader(): void
    {
        if (!Plugin::is_connected()) {
            return;
        }

        $workspace_id = Plugin::get_workspace_id();
        $workspace_slug = get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
        $feature_packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        if (!is_array($feature_packs)) {
            $feature_packs = [];
        }

        $config = [
            'workspaceId' => $workspace_id,
            'workspaceSlug' => $workspace_slug,
            'featurePacks' => $feature_packs,
        ];
        $config_json = wp_json_encode($config, JSON_UNESCAPED_SLASHES);

        $loader_url = PRATCOM_CONNECT_BRIDGE_LOADER_URL;
        $w = esc_attr($workspace_id);

        echo '<script>window.__pratcomConnect=' . $config_json . ';</script>' . "\n";
        printf(
            '<script src="%s" data-client="%s" defer></script>' . "\n",
            esc_url($loader_url),
            $w
        );
    }
}
