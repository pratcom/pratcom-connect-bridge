<?php

namespace Pratcom\Connect\Bridge\Admin;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Page admin Reglages > Pratcom Connect.
 * MVP V1: champ API key + bouton Connect. UI affinee en Phase 3.
 */
class SettingsPage
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_menu(): void
    {
        add_options_page(
            __('Pratcom Connect', 'pratcom-connect-bridge'),
            __('Pratcom Connect', 'pratcom-connect-bridge'),
            'manage_options',
            'pratcom-connect',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        // TODO Phase 3: Settings API + Settings Fields + sanitize callback qui appelle /handshake.
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $status = get_option(Plugin::OPTION_STATUS, 'disconnected');
        $prefix = get_option(Plugin::OPTION_API_KEY_PREFIX, '');
        $workspace_slug = get_option(Plugin::OPTION_WORKSPACE_SLUG, '');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pratcom Connect', 'pratcom-connect-bridge'); ?></h1>

            <?php if ($status === 'connected'): ?>
                <div class="notice notice-success"><p>
                    <?php
                    printf(
                        esc_html__('Connecte au workspace %1$s (cle %2$s).', 'pratcom-connect-bridge'),
                        '<strong>' . esc_html($workspace_slug) . '</strong>',
                        '<code>' . esc_html($prefix) . '...</code>'
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="pratcom_connect_api_key">
                                <?php esc_html_e('Cle API', 'pratcom-connect-bridge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="pratcom_connect_api_key"
                                name="pratcom_connect_api_key"
                                class="regular-text"
                                placeholder="pck_workspace-slug_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                autocomplete="off"
                            />
                            <p class="description">
                                <?php esc_html_e('Fournie par Pratcom Media.', 'pratcom-connect-bridge'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Connecter', 'pratcom-connect-bridge'); ?>
                    </button>
                </p>
            </form>

            <p class="description">
                <?php esc_html_e('Documentation : https://docs.pratcom.net/connect/bridge', 'pratcom-connect-bridge'); ?>
            </p>
        </div>
        <?php
    }
}
