<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * Presets de plugins/outils populaires (Privacy Free, spec .org §4 / O3).
 *
 * DATA fournie par le chantier Privacy (presets.json embarqué, mis à jour
 * via les mises à jour du plugin — modèle Complianz gratuit). L'UI de
 * sélection (cases à cocher + suggestions) = onglet Confidentialité du
 * chantier Plugin .org (O3) ; elle consomme cette classe.
 *
 * Détection PASSIVE locale autorisée (.org-safe, zéro appel serveur) :
 * lecture de la liste des plugins actifs → suggestions seulement, jamais
 * d'activation automatique.
 */
class Presets
{
    public const OPTION_SELECTED = 'pratcom_connect_privacy_presets';

    /** @var array<string, mixed>|null */
    private static $cache = null;

    /** @return array<string, mixed> presets.json décodé (clé presets = liste) */
    public static function data(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $file = __DIR__ . '/presets.json';
        $raw = is_readable($file) ? (string) file_get_contents($file) : '';
        $decoded = json_decode($raw, true);
        self::$cache = is_array($decoded) ? $decoded : ['schemaVersion' => 0, 'presetsVersion' => '0', 'presets' => []];
        return self::$cache;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $presets = self::data()['presets'] ?? [];
        return is_array($presets) ? $presets : [];
    }

    /** @return array<int, array<string, mixed>> presets cochés par l'admin */
    public static function selected(): array
    {
        $ids = get_option(self::OPTION_SELECTED, []);
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_filter(self::all(), static function (array $p) use ($ids): bool {
            return in_array($p['id'] ?? '', $ids, true);
        }));
    }

    /**
     * Suggestions par détection passive : ids de presets dont un plugin
     * déclencheur est actif sur ce site.
     *
     * @return string[]
     */
    public static function suggested(): array
    {
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $network = get_site_option('active_sitewide_plugins', []);
            if (is_array($network)) {
                $active = array_merge($active, array_keys($network));
            }
        }
        $suggestions = [];
        foreach (self::all() as $preset) {
            $plugins = $preset['detect']['plugins'] ?? [];
            if (!is_array($plugins)) {
                continue;
            }
            foreach ($plugins as $slug) {
                if (in_array($slug, $active, true)) {
                    $suggestions[] = (string) $preset['id'];
                    break;
                }
            }
        }
        return $suggestions;
    }

    /**
     * Liste de trackers (format privacy.js : id/category/scriptPatterns)
     * dérivée des presets sélectionnés — sert à la bannière Free locale.
     *
     * @return array<int, array{id: string, category: string, scriptPatterns: string[]}>
     */
    public static function trackers_for_banner(): array
    {
        $trackers = [];
        foreach (self::selected() as $preset) {
            if (empty($preset['blockedByDefault'])) {
                continue; // nécessaires (Woo, reCAPTCHA) : jamais bloqués
            }
            $patterns = $preset['blocking']['scriptPatterns'] ?? [];
            if (!is_array($patterns) || !count($patterns)) {
                continue;
            }
            $trackers[] = [
                'id'             => (string) ($preset['id'] ?? ''),
                'category'       => (string) ($preset['category'] ?? 'marketing'),
                'scriptPatterns' => array_values(array_map('strval', $patterns)),
            ];
        }
        return $trackers;
    }

    /**
     * Lignes « témoins » pour la déclaration cookies locale (LocalPolicy) —
     * fusionne les cookies des presets sélectionnés dans la langue demandée.
     *
     * @return array<int, array{name: string, provider: string, purpose: string, expiry: string, category: string}>
     */
    public static function cookie_rows(string $lang): array
    {
        $lang = $lang === 'en' ? 'en' : 'fr';
        $rows = [];
        $seen = [];
        foreach (self::selected() as $preset) {
            $cookies = $preset['cookies'] ?? [];
            if (!is_array($cookies)) {
                continue;
            }
            foreach ($cookies as $c) {
                $name = (string) ($c['name'] ?? '');
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $rows[] = [
                    'name'     => $name,
                    'provider' => (string) ($preset['provider'] ?? ''),
                    'purpose'  => (string) ($c['description'][$lang] ?? ''),
                    'expiry'   => (string) ($c['expiry'][$lang] ?? ''),
                    'category' => (string) ($preset['category'] ?? 'unclassified'),
                ];
            }
        }
        return $rows;
    }
}
