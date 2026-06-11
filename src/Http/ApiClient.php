<?php

namespace Pratcom\Connect\Bridge\Http;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Client HTTP vers https://api.connect.pratcom.net.
 * Utilise wp_remote_request pour cooperer avec les filtres WP.
 */
class ApiClient
{
    private string $api_base;

    public function __construct(?string $api_base = null)
    {
        $this->api_base = $api_base ?? PRATCOM_CONNECT_BRIDGE_API_BASE;
    }

    /** @return array{ok: bool, workspace_id?: string, workspace_slug?: string, feature_packs?: array, loader_url?: string, error?: string, http_code?: int, details?: array} */
    public function handshake(string $api_key, string $domain): array
    {
        $body = [
            'api_key' => $api_key,
            'domain' => $domain,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugins_count' => count((array) get_option('active_plugins', [])),
        ];

        // Pousse la palette de marque riche au serveur (persistee workspace.settings.theme).
        // Liste blanche BrandTheme — identique au type resolveTheme de connect-core.
        // Le serveur fait un merge additif : les cles non envoyees ne sont jamais supprimees.
        $theme = Plugin::get_theme();
        if (!empty($theme['primary'])) {
            $whitelist = ['primary', 'onPrimary', 'primaryDark', 'secondary', 'text', 'font', 'radius', 'logoUrl'];
            $payload = [];
            foreach ($whitelist as $key) {
                if (!empty($theme[$key])) {
                    $payload[$key] = $theme[$key];
                }
            }
            $body['theme'] = $payload;
        }

        return $this->request('POST', '/api/bridge/handshake', $body);
    }

    public function get_config(string $api_key, string $workspace_id): array
    {
        return $this->request('GET', '/api/bridge/config?workspace_id=' . rawurlencode($workspace_id), null, $api_key);
    }

    /** @param array<int, array{module: string, event_type: string, payload?: array, occurred_at?: string}> $events */
    public function send_events(string $api_key, array $events): array
    {
        return $this->request('POST', '/api/bridge/events', ['events' => $events], $api_key);
    }

    /**
     * Liste les formulaires du workspace (onglet Formulaires, O2).
     * Endpoint attendu : GET /api/bridge/forms (Bearer pck_, lecture seule)
     * -> { ok: true, forms: [{ slug, name, type, status, updated_at }] }
     */
    public function get_forms(string $api_key): array
    {
        return $this->request('GET', '/api/bridge/forms', null, $api_key);
    }

    public function get_version(): array
    {
        return $this->request('GET', '/api/bridge/version');
    }

    // ─── O5 : sessions signées pour les iframes mirror ───────────────────────

    /**
     * Ouvre une session signée pour l'iframe du gestionnaire de formulaires (B1).
     * POST /api/bridge/forms-builder-session (Bearer pck_)
     * -> { ok, workspace_slug, url, expires_at }
     */
    public function get_builder_session(string $api_key): array
    {
        return $this->request('POST', '/api/bridge/forms-builder-session', null, $api_key);
    }

    /**
     * Ouvre une session signée pour l'iframe d'entraînement Chat.
     * POST /api/bridge/chat-session (Bearer pck_)
     * -> { ok, workspace_slug, url, expires_at }
     */
    public function get_chat_session(string $api_key): array
    {
        return $this->request('POST', '/api/bridge/chat-session', null, $api_key);
    }

    /**
     * Ouvre une session signée pour l'iframe de scan Privacy.
     * POST /api/bridge/privacy-session (Bearer pck_)
     * -> { ok, workspace_slug, url, expires_at }
     */
    public function get_privacy_session(string $api_key): array
    {
        return $this->request('POST', '/api/bridge/privacy-session', null, $api_key);
    }

    // ─── Transport ───────────────────────────────────────────────────────────

    private function request(string $method, string $path, ?array $body = null, ?string $bearer = null): array
    {
        $args = [
            'method' => $method,
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'PratcomConnectBridge/' . PRATCOM_CONNECT_BRIDGE_VERSION . '; +' . home_url(),
            ],
        ];

        if ($bearer !== null) {
            $args['headers']['Authorization'] = 'Bearer ' . $bearer;
        }

        if ($body !== null && $method !== 'GET') {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->api_base . $path, $args);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            return array_merge(['ok' => true], is_array($data) ? $data : []);
        }

        return [
            'ok' => false,
            'http_code' => $code,
            'error' => $data['error'] ?? 'http_error',
            'details' => $data,
        ];
    }
}
