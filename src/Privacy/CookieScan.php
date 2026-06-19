<?php

namespace Pratcom\Connect\Bridge\Privacy;

/**
 * Mini-scan local des temoins + agregation des lignes de la declaration
 * (Privacy Free, spec legal pages org §1/§5, chantier feat/org-legal-pages).
 *
 * 100 % LOCAL — zero appel serveur Pratcom :
 *   - Un petit script front (privacy-scan.js) est enqueue UNIQUEMENT pour les
 *     administrateurs connectes (current_user_can manage_options). Il lit les
 *     NOMS de document.cookie et les POST a une action admin-ajax protegee par
 *     nonce. Aucun visiteur n'est trace ; aucune donnee ne quitte le site.
 *   - Les noms detectes sont stockes dans OPTION_SCANNED ; l'admin les revoit /
 *     classe dans l'onglet Confidentialite.
 *
 * Cette classe est AUSSI le point unique de fusion des lignes de temoins,
 * partage par CookieDeclaration (shortcode autonome) et LocalPolicy (tableau
 * integre a la politique) : presets selectionnes + liste manuelle + scan,
 * dedupliques par nom, groupes par categorie. Ainsi les deux rendus restent
 * coherents sans dupliquer la logique.
 */
class CookieScan
{
    /** Noms de temoins detectes par le mini-scan admin : array<string,string> name => ''. */
    public const OPTION_SCANNED = 'pratcom_connect_privacy_scanned';

    public const AJAX_ACTION = 'pratcom_privacy_scan';
    public const NONCE = 'pratcom_privacy_scan';
    public const HANDLE = 'pratcom-connect-privacy-scan';

    /** Ordre d'affichage fixe des categories (style Cookiebot). */
    public const CATEGORY_ORDER = ['necessary', 'preferences', 'functional', 'statistics', 'marketing', 'unclassified'];

    /**
     * Categories effectivement classees, montrees sur la page PUBLIQUE.
     * « unclassified » en est volontairement exclue : un temoin non classe
     * ne doit jamais paraitre cote visiteur (il reste visible en admin).
     */
    public const PUBLIC_CATEGORIES = ['necessary', 'preferences', 'functional', 'statistics', 'marketing'];

    /**
     * Cookies internes de WordPress (authentification / admin) que les
     * VISITEURS non connectes n'ont jamais. Le mini-scan tourne dans le
     * navigateur d'un administrateur connecte : il capterait donc ces cookies,
     * qui apparaitraient alors « non classes » sur la declaration publique.
     * On les exclut a la capture ET, defensivement, a la fusion.
     *
     * Prefixes (comparaison insensible a la casse, par debut de chaine) :
     *   - 'wp-settings-'           reglages d'ecran d'admin (wp-settings-1, ...)
     *   - 'wp-settings-time-'      horodatage des reglages d'admin
     *   - 'wordpress_logged_in_'   session « connecte »
     *   - 'wordpress_sec_'         cookie d'authentification securise (admin/https)
     *   - 'wordpress_'             prefixe d'authentification generique
     * Exacts :
     *   - 'wordpress_test_cookie'  test de support des cookies
     *
     * NB : ne couvre PAS les cookies de commentaire (comment_author*) ni
     * 'wp-wpml_*' (preference de langue visiteur), qui restent legitimes.
     *
     * @var string[]
     */
    private const WP_INTERNAL_PREFIXES = [
        'wp-settings-time-',
        'wp-settings-',
        'wordpress_logged_in_',
        'wordpress_sec_',
        'wordpress_',
    ];

    /** @var string[] */
    private const WP_INTERNAL_EXACT = [
        'wordpress_test_cookie',
    ];

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_scan']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle_scan']);
    }

    /**
     * Le nom donne est-il un cookie interne WordPress (auth/admin) ?
     * Comparaison insensible a la casse. Voir WP_INTERNAL_PREFIXES / _EXACT.
     */
    public static function is_wp_internal(string $name): bool
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return false;
        }
        if (in_array($name, self::WP_INTERNAL_EXACT, true)) {
            return true;
        }
        foreach (self::WP_INTERNAL_PREFIXES as $prefix) {
            if (strncmp($name, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Libelles de categorie bilingues (en-tete de groupe de la declaration).
     *
     * @return array<string, string>
     */
    public static function category_label(string $lang): array
    {
        if ($lang === 'en') {
            return [
                'necessary'    => 'Strictly necessary',
                'preferences'  => 'Preferences',
                'functional'   => 'Functional',
                'statistics'   => 'Statistics',
                'marketing'    => 'Marketing',
                'unclassified' => 'Unclassified',
            ];
        }
        return [
            'necessary'    => 'Strictement nécessaires',
            'preferences'  => 'Préférences',
            'functional'   => 'Fonctionnels',
            'statistics'   => 'Statistiques',
            'marketing'    => 'Marketing',
            'unclassified' => 'Non classés',
        ];
    }

    /**
     * Courte description par categorie (sous l'en-tete de groupe).
     *
     * @return array<string, string>
     */
    public static function category_description(string $lang): array
    {
        if ($lang === 'en') {
            return [
                'necessary'    => 'These cookies are required for the site to function and cannot be switched off. They are usually set in response to actions you take, such as logging in or filling in forms.',
                'preferences'  => 'These cookies let the site remember choices you make (such as your language) to provide a more personal experience.',
                'functional'   => 'These cookies enable enhanced functionality, such as live chat or embedded content. They may be set by us or by third-party providers.',
                'statistics'   => 'These cookies help us understand how visitors interact with the site by collecting information anonymously, so we can improve it.',
                'marketing'    => 'These cookies are used to track visitors across sites in order to display advertising that is relevant and engaging.',
                'unclassified' => 'These cookies have been detected on the site but not yet assigned to a category.',
            ];
        }
        return [
            'necessary'    => 'Ces témoins sont indispensables au fonctionnement du site et ne peuvent pas être désactivés. Ils sont généralement déposés en réponse à vos actions (connexion, remplissage d\'un formulaire).',
            'preferences'  => 'Ces témoins permettent au site de mémoriser vos choix (comme votre langue) afin d\'offrir une expérience plus personnalisée.',
            'functional'   => 'Ces témoins activent des fonctionnalités enrichies, comme le clavardage en direct ou le contenu intégré. Ils peuvent être déposés par nous ou par des fournisseurs tiers.',
            'statistics'   => 'Ces témoins nous aident à comprendre comment les visiteurs utilisent le site en recueillant des renseignements de façon anonyme, afin de l\'améliorer.',
            'marketing'    => 'Ces témoins servent à suivre les visiteurs d\'un site à l\'autre afin d\'afficher de la publicité pertinente et attrayante.',
            'unclassified' => 'Ces témoins ont été détectés sur le site mais ne sont pas encore classés dans une catégorie.',
        ];
    }

    /**
     * Lignes fusionnees pour la declaration, groupees par categorie.
     * Source = presets selectionnes + liste manuelle (OPTION_COOKIES) + scan.
     * Deduplique par nom ; la premiere source qui declare un nom gagne
     * (presets, puis manuel, puis scan).
     *
     * @return array<string, array<int, array{name:string, provider:string, purpose:string, expiry:string, category:string}>>
     */
    public static function grouped_rows(string $lang): array
    {
        $lang = $lang === 'en' ? 'en' : 'fr';
        $rows = self::merged_rows($lang);

        $groups = [];
        foreach (self::CATEGORY_ORDER as $cat) {
            $groups[$cat] = [];
        }
        foreach ($rows as $row) {
            $cat = $row['category'];
            if (!isset($groups[$cat])) {
                $cat = 'unclassified';
                $row['category'] = $cat;
            }
            $groups[$cat][] = $row;
        }
        // Ne garder que les categories non vides, dans l'ordre fixe.
        return array_filter($groups, static function (array $list): bool {
            return count($list) > 0;
        });
    }

    /**
     * Variante PUBLIQUE de grouped_rows() : identique mais SANS la categorie
     * « unclassified ». Un temoin non classe ne doit jamais paraitre cote
     * visiteur. Utilise par CookieDeclaration et LocalPolicy (rendus publics).
     *
     * @return array<string, array<int, array{name:string, provider:string, purpose:string, expiry:string, category:string}>>
     */
    public static function grouped_rows_public(string $lang): array
    {
        $groups = self::grouped_rows($lang);
        unset($groups['unclassified']);
        return $groups;
    }

    /**
     * Liste fusionnee a plat, dedupliquee par nom (sert au tableau integre a
     * la politique et de base a grouped_rows).
     *
     * @return array<int, array{name:string, provider:string, purpose:string, expiry:string, category:string}>
     */
    public static function merged_rows(string $lang): array
    {
        $lang = $lang === 'en' ? 'en' : 'fr';
        $rows = [];
        $seen = [];

        // 1) Presets selectionnes (deja bilingues).
        foreach (Presets::cookie_rows($lang) as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $rows[] = [
                'name'     => $name,
                'provider' => (string) ($row['provider'] ?? ''),
                'purpose'  => (string) ($row['purpose'] ?? ''),
                'expiry'   => (string) ($row['expiry'] ?? ''),
                'category' => self::normalize_category((string) ($row['category'] ?? 'unclassified')),
            ];
        }

        // 2) Liste manuelle (OPTION_COOKIES) — lignes {name,provider,purpose,expiry,category}.
        $manual = get_option(LocalPolicy::OPTION_COOKIES, []);
        if (is_array($manual)) {
            foreach ($manual as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $name = trim((string) ($c['name'] ?? ''));
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $rows[] = [
                    'name'     => $name,
                    'provider' => (string) ($c['provider'] ?? ''),
                    'purpose'  => (string) ($c['purpose'] ?? ''),
                    'expiry'   => (string) ($c['expiry'] ?? ''),
                    'category' => self::normalize_category((string) ($c['category'] ?? 'unclassified')),
                ];
            }
        }

        // 3) Scan local : noms detectes non encore couverts -> "unclassified".
        // Defense en profondeur : ignorer tout cookie interne WordPress qui
        // aurait pu etre stocke avant ce correctif (scanned_names() le filtre
        // deja, mais on le re-verifie ici).
        $unknown = $lang === 'en' ? 'To be classified' : 'À classer';
        foreach (self::scanned_names() as $name) {
            $name = trim((string) $name);
            if ($name === '' || isset($seen[$name]) || self::is_wp_internal($name)) {
                continue;
            }
            $seen[$name] = true;
            $rows[] = [
                'name'     => $name,
                'provider' => '',
                'purpose'  => $unknown,
                'expiry'   => '',
                'category' => 'unclassified',
            ];
        }

        return $rows;
    }

    /** Normalise une categorie inconnue vers une valeur d'affichage connue. */
    private static function normalize_category(string $cat): string
    {
        $cat = strtolower(trim($cat));
        return in_array($cat, self::CATEGORY_ORDER, true) ? $cat : 'unclassified';
    }

    /**
     * Noms de temoins memorises par le scan admin.
     * Les cookies internes WordPress (auth/admin) sont filtres a la lecture,
     * meme s'ils ont ete stockes avant l'introduction du filtre.
     *
     * @return string[]
     */
    public static function scanned_names(): array
    {
        $saved = get_option(self::OPTION_SCANNED, []);
        if (!is_array($saved)) {
            return [];
        }
        // Stockage = map name => '' (clefs uniques) ; on retourne les clefs.
        $names = array_keys($saved);
        return array_values(array_filter(array_map('strval', $names), static function (string $n): bool {
            return $n !== '' && !self::is_wp_internal($n);
        }));
    }

    /**
     * Noms detectes par le scan qui ne sont couverts NI par un preset
     * selectionne NI par la liste manuelle — ce sont ceux a classer.
     *
     * @return string[]
     */
    public static function unclassified_scanned(string $lang = 'fr'): array
    {
        $covered = [];
        foreach (Presets::cookie_rows($lang) as $row) {
            $n = (string) ($row['name'] ?? '');
            if ($n !== '') {
                $covered[$n] = true;
            }
        }
        $manual = get_option(LocalPolicy::OPTION_COOKIES, []);
        if (is_array($manual)) {
            foreach ($manual as $c) {
                if (is_array($c)) {
                    $n = trim((string) ($c['name'] ?? ''));
                    if ($n !== '') {
                        $covered[$n] = true;
                    }
                }
            }
        }
        $out = [];
        foreach (self::scanned_names() as $name) {
            if (!isset($covered[$name]) && !self::matches_pattern($name, array_keys($covered))) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Un nom concret correspond-il a l'un des motifs connus (gere les
     * jokers "*" des presets, ex. _ga_* couvre _ga_ABC123) ?
     *
     * @param string[] $patterns
     */
    private static function matches_pattern(string $name, array $patterns): bool
    {
        foreach ($patterns as $pat) {
            if (strpos($pat, '*') === false) {
                continue;
            }
            $regex = '/^' . str_replace('\\*', '.*', preg_quote($pat, '/')) . '$/';
            if (preg_match($regex, $name) === 1) {
                return true;
            }
        }
        return false;
    }

    // ─── Mini-scan front (admin uniquement) ───

    /**
     * Enqueue le script de scan UNIQUEMENT pour un administrateur connecte.
     * Aucun effet pour les visiteurs : aucune charge, aucun appel.
     */
    public function maybe_enqueue_scan(): void
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            PRATCOM_CONNECT_BRIDGE_URL . 'assets/js/privacy-scan.js',
            [],
            PRATCOM_CONNECT_BRIDGE_VERSION,
            true // footer
        );
        wp_localize_script(self::HANDLE, 'pratcomPrivacyScan', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce(self::NONCE),
        ]);
    }

    /**
     * Action admin-ajax : recoit les noms de temoins lus cote front par
     * l'admin, les assainit et fusionne dans OPTION_SCANNED. Protege par
     * nonce + capability. Reponse JSON minimaliste.
     */
    public function handle_scan(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden'], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');

        $raw = isset($_POST['cookies']) ? wp_unslash($_POST['cookies']) : [];
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }
        if (!is_array($raw)) {
            wp_send_json_error(['error' => 'invalid'], 400);
        }

        $saved = get_option(self::OPTION_SCANNED, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $added = 0;
        foreach ($raw as $name) {
            if (!is_scalar($name)) {
                continue;
            }
            $name = sanitize_text_field((string) $name);
            // Noms de temoins : alphanum, tirets, underscores, points, etoiles.
            $name = preg_replace('/[^A-Za-z0-9_\-.*]/', '', $name);
            $name = (string) substr((string) $name, 0, 128);
            // Ignorer les cookies internes WordPress (auth/admin) : ils ne
            // concernent que l'admin connecte qui execute le scan et ne
            // doivent jamais finir dans la declaration publique.
            if ($name === '' || isset($saved[$name]) || self::is_wp_internal($name)) {
                continue;
            }
            // Borne de securite : ne pas laisser l'option enfler indefiniment.
            if (count($saved) >= 200) {
                break;
            }
            $saved[$name] = '';
            $added++;
        }
        if ($added > 0) {
            update_option(self::OPTION_SCANNED, $saved, false);
        }

        wp_send_json_success(['added' => $added, 'total' => count($saved)]);
    }

    /** Vide la liste des temoins scannes (bouton admin). */
    public static function clear_scanned(): void
    {
        update_option(self::OPTION_SCANNED, [], false);
    }
}
