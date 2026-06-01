<?php

namespace Pratcom\Connect\Bridge;

/**
 * Injection du loader Pratcom Connect dans le <head>.
 * Charge uniquement si connecte (status=connected). Si status=revoked
 * ou error, le loader n'est PAS injecte (mode degrade).
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
        $loader_url = PRATCOM_CONNECT_BRIDGE_LOADER_URL;
        $w = esc_attr($workspace_id);

        printf(
            '<script src="%s?w=%s" data-client="%s" defer></script>' . "\n",
            esc_url($loader_url),
            $w,
            $w
        );
    }
}
