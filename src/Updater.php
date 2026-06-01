<?php

namespace Pratcom\Connect\Bridge;

/**
 * Plugin Update Checker (YahnisElsts/plugin-update-checker v5) branche sur
 * les releases GitHub du repo pratcom/pratcom-connect-bridge.
 *
 * Cette classe ne fait que setup le checker, il s'auto-active a chaque admin
 * page load WP.
 */
class Updater
{
    public static function init(): void
    {
        $puc_path = PRATCOM_CONNECT_BRIDGE_DIR . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
        if (!file_exists($puc_path)) {
            // puc pas installe (composer install pas fait). En dev local sans build,
            // skip silencieusement plutot que de planter.
            return;
        }
        require_once $puc_path;

        if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
            return;
        }

        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/pratcom/pratcom-connect-bridge/',
            PRATCOM_CONNECT_BRIDGE_FILE,
            'pratcom-connect-bridge'
        );

        // Lookup release asset .zip plutot que tarball auto-genere
        if (method_exists($checker, 'getVcsApi')) {
            $checker->getVcsApi()->enableReleaseAssets();
        }

        // Brancher sur tags (releases) plutot que sur le branch HEAD
        if (method_exists($checker, 'setBranch')) {
            $checker->setBranch('main');
        }
    }
}
