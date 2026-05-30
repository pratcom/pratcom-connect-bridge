<?php
/**
 * Plugin Name:       Pratcom Connect Bridge
 * Plugin URI:        https://github.com/pratcom/pratcom-connect-bridge
 * Description:       Connecte un site WordPress a l API Pratcom Connect. Permet d activer les modules Chat, Forms, Privacy, CRM via une seule cle API.
 * Version:           0.1.0
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

define('PRATCOM_CONNECT_BRIDGE_VERSION', '0.1.0');
define('PRATCOM_CONNECT_BRIDGE_FILE', __FILE__);
define('PRATCOM_CONNECT_BRIDGE_DIR', plugin_dir_path(__FILE__));
define('PRATCOM_CONNECT_BRIDGE_URL', plugin_dir_url(__FILE__));
define('PRATCOM_CONNECT_BRIDGE_API_BASE', 'https://api.connect.pratcom.net');
define('PRATCOM_CONNECT_BRIDGE_LOADER_URL', 'https://connect.pratcom.net/loader.js');

// Autoloader Composer
if (file_exists(PRATCOM_CONNECT_BRIDGE_DIR . 'vendor/autoload.php')) {
    require_once PRATCOM_CONNECT_BRIDGE_DIR . 'vendor/autoload.php';
} else {
    // Fallback PSR-4 minimal si composer install pas fait
    spl_autoload_register(function ($class) {
        $prefix = 'Pratcom\\Connect\\Bridge\\';
        $base_dir = PRATCOM_CONNECT_BRIDGE_DIR . 'src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    });
}

// Bootstrap
add_action('plugins_loaded', function () {
    \Pratcom\Connect\Bridge\Plugin::boot();
});

// Activation / uninstall hooks
register_activation_hook(__FILE__, function () {
    \Pratcom\Connect\Bridge\Plugin::on_activate();
});

register_uninstall_hook(__FILE__, [\Pratcom\Connect\Bridge\Plugin::class, 'on_uninstall']);
