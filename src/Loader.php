<?php

namespace Pratcom\Connect\Bridge;

/**
 * Injection du loader Pratcom Connect dans le <head>.
 * Charge uniquement si un workspace_id est present (handshake reussi).
 */
class Loader
{
    public function __construct()
    {
        add_action('wp_head', [$this, 'inject_loader'], 5);
    }

    public function inject_loader(): void
    {
        $workspace_id = get_option(Plugin::OPTION_WORKSPACE_ID, '');
        $status = get_option(Plugin::OPTION_STATUS, 'disconnected');

        if (empty($workspace_id) || $status !== 'connected') {
            return;
        }

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
