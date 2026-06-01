<?php

namespace Pratcom\Connect\Bridge\Http;

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

    public function get_version(): array
    {
        return $this->request('GET', '/api/bridge/version');
    }

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
