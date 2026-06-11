<?php
/**
 * Plugin Name:       Pratcom Connect
 * Plugin URI:        https://pratcom.net/connect
 * Description:       Connecte un site WordPress a l API Pratcom Connect. Permet d activer les modules Chat, Forms, Privacy via une seule cle API.
 * Version:           2.0.4
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Pratcom Media
 * Author URI:        https://pratcom.net
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pratcom-connect
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PRATCOM_CONNECT_BRIDGE_VERSION', '2.0.4');
define('PRATCOM_CONNECT_BRIDGE_FILE', __FILE__);
define('PRATCOM_CONNECT_BRIDGE_DIR', plugin_dir_path(__FILE__));
define('PRATCOM_CONNECT_BRIDGE_URL', plugin_dir_url(__FILE__));
define('PRATCOM_CONNECT_BRIDGE_API_BASE', 'https://api.connect.pratcom.net');
define('PRATCOM_CONNECT_BRIDGE_LOADER_URL', 'https://connect.pratcom.net/loader.js');

// Canal de distribution : 'premium' (releases GitHub + Plugin Update Checker)
// ou 'org' (catalogue WordPress.org, MAJ via SVN — PUC interdit).
// Le job CI build-org reecrit la ligne ci-dessous ('premium' -> 'org').
define('PRATCOM_CONNECT_BRIDGE_CHANNEL', 'premium');

// Charge l'autoloader Composer (vendor) si present : sert au Plugin Update
// Checker (mises a jour automatiques).
if (file_exists(PRATCOM_CONNECT_BRIDGE_DIR . 'vendor/autoload.php')) {
    require_once PRATCOM_CONNECT_BRIDGE_DIR . 'vendor/autoload.php';
}

// Autoloader PSR-4 maison pour nos propres classes (src/) — TOUJOURS enregistre,
// en filet de securite. Si l'autoloader optimise de Composer rate une classe
// (classmap incomplet au build), celui-ci la resout par chemin. Corrige le
// fatal "Class Pratcom\Connect\Bridge\Admin\SettingsPage not found".
spl_autoload_register(function ($class) {
    $prefix = 'Pratcom\\Connect\\Bridge\\';
    $base_dir = PRATCOM_CONNECT_BRIDGE_DIR . 'src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $file = $base_dir . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

add_action('plugins_loaded', function () {
    \Pratcom\Connect\Bridge\Plugin::boot();
    if (PRATCOM_CONNECT_BRIDGE_CHANNEL === 'premium') {
        \Pratcom\Connect\Bridge\Updater::init();
    }
});

register_activation_hook(__FILE__, function () {
    \Pratcom\Connect\Bridge\Plugin::on_activate();
    \Pratcom\Connect\Bridge\HealthCheck::schedule();
});

register_deactivation_hook(__FILE__, function () {
    \Pratcom\Connect\Bridge\HealthCheck::unschedule();
});

register_uninstall_hook(__FILE__, [\Pratcom\Connect\Bridge\Plugin::class, 'on_uninstall']);
