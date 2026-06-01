<?php

namespace Pratcom\Connect\Bridge\Admin;

use Pratcom\Connect\Bridge\Plugin;

class Notices
{
    public function __construct()
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) return;

        $status = get_option(Plugin::OPTION_STATUS);
        if ($status === 'connected' || $status === 'disconnected') return;

        $last_error = get_option(Plugin::OPTION_LAST_ERROR, '');
        $settings_url = admin_url('options-general.php?page=pratcom-connect');

        if ($status === 'revoked') {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Pratcom Connect : cle revoquee.', 'pratcom-connect-bridge'); ?></strong><br>
                    <?php echo esc_html($last_error ?: __('La cle API n\'est plus valide. Le loader Pratcom Connect ne se charge plus sur le site.', 'pratcom-connect-bridge')); ?><br>
                    <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Reglages > Pratcom Connect', 'pratcom-connect-bridge'); ?></a>
                </p>
            </div>
            <?php
        } elseif ($status === 'error') {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e('Pratcom Connect : erreur de connexion.', 'pratcom-connect-bridge'); ?></strong>
                    <?php echo esc_html($last_error); ?>
                    <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Reverifier la connexion', 'pratcom-connect-bridge'); ?></a>
                </p>
            </div>
            <?php
        }
    }
}
