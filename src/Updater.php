<?php

namespace Pratcom\Connect\Bridge;

/**
 * Plugin Update Checker (YahnisElsts/plugin-update-checker v5) branche sur
 * les releases GitHub (tags) du repo pratcom/pratcom-connect-bridge, avec
 * l'asset .zip de la release (enableReleaseAssets).
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

        // Mises a jour par releases (tags), jamais par la tete d'une branche.
        // Sans setBranch, PUC v5 cherche la derniere release GitHub, puis le
        // dernier tag. L'ancien setBranch('main') ajoutait la branche `main`
        // en dernier recours : un commit de version sur main pouvait etre
        // propose aux sites sans tag. La branche par defaut de PUC (`master`)
        // n'existe pas ici : ce dernier recours ne propose donc plus rien.
    }
}
