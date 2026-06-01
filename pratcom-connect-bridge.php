<?php
/**
 * Plugin Name:       Pratcom Connect Bridge
 * Plugin URI:        https://github.com/pratcom/pratcom-connect-bridge
 * Description:       Connecte un site WordPress a l API Pratcom Connect. Permet d activer les modules Chat, Forms, Privacy, CRM via une seule cle API.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Pratcom Media
 * Author URI:        https://pratcom.net
 * License:           Proprietaire
 * Text Domain:       pratcom-connect-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PRATCOM_CONNECT_BRIDGE_VERSION', '0.1.1');
define('PRATCOM_CONNECT_BRIDGE_FILE', __FILE__);
define('PRATCOM_CONNECT_BRIDGE_DIR', plugin_dir_path(__FILE__));
define('PRATCOM_CONNECT_BRIDGE_URL', plugin_dir_url(__FILE__));
define('PRATCOM_CONNECT_BRIDGE_API_BASE', 'https://api.connect.pratcom.net');
define('PRATCOM_CONNECT_BRIDGE_LOADER_URL', 'https://connect.pratcom.net/loader.js');

if (file_exists(PRATCOM_CONNECT_BRIDGE_DIR . 'vendor/autoload.php')) {
    require_once PRATCOM_CONNECT_BRIDGE_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(function ($class) {
        $prefix = 'Pratcom\\Connect\\Bridge\\';
        $base_dir = PRATCOM_CONNECT_BRIDGE_DIR . 'src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) return;
        $file = $base_dir . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) require $file;
    });
}

add_action('plugins_loaded', function () {
    \Pratcom\Connect\Bridge\Plugin::boot();
    \Pratcom\Connect\Bridge\Updater::init();
});

register_activation_hook(__FILE__, function () {
    \Pratcom\Connect\Bridge\Plugin::on_activate();
    \Pratcom\Connect\Bridge\HealthCheck::schedule();
});

register_deactivation_hook(__FILE__, function () {
    \Pratcom\Connect\Bridge\HealthCheck::unschedule();
});

register_uninstall_hook(__FILE__, [\Pratcom\Connect\Bridge\Plugin::class, 'on_uninstall']);
