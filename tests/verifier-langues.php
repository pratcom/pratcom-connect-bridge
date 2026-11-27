<?php
/**
 * Banc CLI des langues, sans WordPress, reseau ni base.
 *
 *   php tests/verifier-langues.php [--src=/copie/avant/src]
 *
 * La table fige les sorties historiques, puis joue les vrais points
 * d'entree du plugin. --src permet de rejouer ces memes assertions sur
 * les sources precedant la centralisation (le helper neuf reste local).
 * La CI joue deja tous les tests/*.php. Exclu des deux zips du plugin.
 */

use Pratcom\Connect\Bridge\Admin\AdminShell;
use Pratcom\Connect\Bridge\Blocks\LegalBlocks;
use Pratcom\Connect\Bridge\Forms\Shortcode as FormsShortcode;
use Pratcom\Connect\Bridge\Jobs\Catalogue;
use Pratcom\Connect\Bridge\Jobs\Fiche;
use Pratcom\Connect\Bridge\Jobs\Module;
use Pratcom\Connect\Bridge\Jobs\Shortcode as JobsShortcode;
use Pratcom\Connect\Bridge\Jobs\Vocabulaire;
use Pratcom\Connect\Bridge\Langues;
use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Privacy\CookieCards;
use Pratcom\Connect\Bridge\Privacy\CookieDeclaration;
use Pratcom\Connect\Bridge\Privacy\CookieScan;
use Pratcom\Connect\Bridge\Privacy\CustomContent;
use Pratcom\Connect\Bridge\Privacy\FreeBanner;
use Pratcom\Connect\Bridge\Privacy\LocalPolicy;
use Pratcom\Connect\Bridge\Privacy\Multilang;
use Pratcom\Connect\Bridge\Privacy\PolicyShortcode;
use Pratcom\Connect\Bridge\Privacy\Presets;

$options = getopt('', ['src:']);
$src = isset($options['src']) ? rtrim((string) $options['src'], '/\\') : dirname(__DIR__) . '/src';
require dirname(__DIR__) . '/src/Langues.php';
spl_autoload_register(static function (string $classe) use ($src): void {
    $prefixe = 'Pratcom\\Connect\\Bridge\\';
    if (strpos($classe, $prefixe) !== 0) {
        return;
    }
    $fichier = $src . '/' . str_replace('\\', '/', substr($classe, strlen($prefixe))) . '.php';
    if (is_file($fichier)) {
        require $fichier;
    }
});

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('PRATCOM_CONNECT_BRIDGE_VERSION', 'banc');
define('PRATCOM_CONNECT_BRIDGE_FILE', dirname(__DIR__) . '/pratcom-connect-bridge.php');
define('ICL_SITEPRESS_VERSION', 'banc');

// Options et locales en memoire. Un appel HTTP est toujours une erreur.
$GLOBALS['banc_options'] = [];
$GLOBALS['banc_locale'] = 'fr_CA';
$GLOBALS['banc_locale_page'] = 'fr_CA';
$GLOBALS['banc_locale_admin'] = 'fr_CA';
$GLOBALS['banc_multilang'] = false;
$GLOBALS['banc_langue_wpml'] = '';
$GLOBALS['banc_inline'] = '';
function get_option($cle, $defaut = false) { return $GLOBALS['banc_options'][$cle] ?? $defaut; }
function get_locale() { return $GLOBALS['banc_locale']; }
function determine_locale() { return $GLOBALS['banc_locale_page']; }
function get_user_locale() { return $GLOBALS['banc_locale_admin']; }
function has_filter($tag) { return $GLOBALS['banc_multilang'] && str_starts_with($tag, 'wpml_'); }
function apply_filters($tag, $valeur, ...$args)
{
    if ($GLOBALS['banc_multilang'] && $tag === 'wpml_default_language') {
        return $GLOBALS['banc_langue_wpml'];
    }
    if ($GLOBALS['banc_multilang'] && $tag === 'wpml_active_languages') {
        return ['es' => [], 'fr' => [], 'en' => []];
    }
    return $valeur;
}
function add_action(...$args) {}
function add_filter(...$args) {}
function add_shortcode(...$args) {}
function shortcode_atts($defauts, $atts, $tag = '') { return array_merge($defauts, is_array($atts) ? $atts : []); }
function do_shortcode($texte) { return $texte; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($t, $protocoles = null) { return esc_attr($t); }
function esc_url_raw($t) { return (string) $t; }
function get_bloginfo($cle) { return 'Site exemple'; }
function home_url($chemin = '') { return 'https://exemple.test' . $chemin; }
function rest_url($chemin) { return 'https://exemple.test/wp-json/' . $chemin; }
function date_i18n($format) { return $format; }
function get_pages($args = []) { return []; }
function plugins_url($chemin, $plugin = '') { return 'https://exemple.test/' . $chemin; }
function wp_enqueue_script(...$args) {}
function wp_script_add_data(...$args) {}
function wp_json_encode($v, $options = 0) { return json_encode($v, $options); }
function wp_add_inline_script($handle, $script, $position = 'after') { $GLOBALS['banc_inline'] = $script; }
function wp_remote_get(...$args) { throw new RuntimeException('Appel HTTP interdit dans ce banc'); }
function wp_remote_request(...$args) { throw new RuntimeException('Appel HTTP interdit dans ce banc'); }

$echecs = 0;
$ok = 0;
function verifier(string $nom, bool $condition): void
{
    global $echecs, $ok;
    if ($condition) {
        $ok++;
    } else {
        $echecs++;
        echo "ECHEC $nom\n";
    }
}

verifier('liste partagee', Langues::PRISES_EN_CHARGE === ['fr', 'en']);
verifier('langue par defaut', Langues::PAR_DEFAUT === 'fr');
verifier('alias public Emplois conserve', Vocabulaire::LANGUES === Langues::PRISES_EN_CHARGE);

// Entree, Emplois (trim + prefixe minuscule), code exact, attribut, locale WP.
$table = [
    ['fr',    'fr', 'fr', 'fr', 'fr'],
    ['en',    'en', 'en', 'en', 'en'],
    ['FR',    'fr', 'fr', 'fr', 'fr'],
    ['en_CA', 'en', 'fr', '',   'en'],
    ['en-CA', 'en', 'fr', '',   'en'],
    ['es',    'fr', 'fr', '',   'fr'],
    ['',      'fr', 'fr', '',   'fr'],
    [null,    'fr', 'fr', '',   'fr'],
    ['EN',    'en', 'fr', 'en', 'fr'],
    ['EN_CA', 'en', 'fr', '',   'fr'],
    [' en ',  'en', 'fr', '',   'fr'],
    ['enough','en', 'fr', '',   'en'],
    ['fr_CA', 'fr', 'fr', '',   'fr'],
    ['auto',  'fr', 'fr', '',   'fr'],
];

$form = new FormsShortcode();
$cookies = new CookieDeclaration();
$policy = new PolicyShortcode();
$blocks = new LegalBlocks();
$banner = new FreeBanner();
$section = ['form_slug' => 'test', 'title' => ['fr' => 'Titre FR', 'en' => 'Title EN'], 'text' => ['fr' => 'Texte FR', 'en' => 'Text EN']];
$bouton = ['phone' => '450-555-0123', 'href' => 'tel:+14505550123', 'label' => ['fr' => 'Appel FR', 'en' => 'Call EN']];
$rows = [['name' => 'test', 'category' => 'necessary', 'purpose' => 'Test']];
$groupes = ['necessary' => $rows];

$GLOBALS['banc_options'][CustomContent::OPTION_ENABLED] = '1';
$GLOBALS['banc_options'][CustomContent::OPTION_BLOCKS] = [
    ['id' => 'test', 'subtitle' => ['fr' => 'Titre FR', 'en' => 'Title EN'], 'content' => ['fr' => 'Texte FR', 'en' => 'Text EN']],
];
$GLOBALS['banc_options'][CookieScan::OPTION_SCANNED] = ['pratcom_consent' => '', 'test_inconnu' => ''];
$presets = Presets::all();
$GLOBALS['banc_options'][Presets::OPTION_SELECTED] = array_column($presets, 'id');
verifier('presets exerces', count($presets) > 0);

// Chaque rendu strict doit rester identique octet pour octet a sa langue historique.
$rendus = [
    'candidature spontanee' => static fn($l) => JobsShortcode::section_spontanee($section, $l),
    'bouton appel' => static fn($l) => Fiche::bouton_appel($bouton, $l),
    'declaration locale' => static fn($l) => CookieDeclaration::render_local($l),
    'groupes temoins' => static fn($l) => CookieDeclaration::render_groups($groupes, $l),
    'cartes temoins' => static fn($l) => CookieCards::render($rows, $l),
    'politique locale' => static fn($l) => LocalPolicy::render($l),
    'contenu personnalise' => static fn($l) => CustomContent::render_section($l),
    'temoin interne' => static fn($l) => CookieScan::first_party_row('pratcom_consent', $l),
    'temoins groupes' => static fn($l) => CookieScan::grouped_rows($l),
    'temoins fusionnes' => static fn($l) => CookieScan::merged_rows($l),
    'presets temoins' => static fn($l) => Presets::cookie_rows($l),
];
foreach ($rendus as $nom => $rendu) {
    verifier("$nom distingue FR/EN", $rendu('fr') !== $rendu('en'));
}

echo "entree | Emplois | rendu exact | attribut | locale WP\n";
foreach ($table as [$entree, $emploi, $exact, $attribut, $locale]) {
    $nom = json_encode($entree);
    echo "$nom | $emploi | $exact | " . ($attribut === '' ? '(vide)' : $attribut) . " | $locale\n";
    verifier("$nom normalisation", Langues::normaliser($entree) === $emploi);
    verifier("$nom Emplois", Vocabulaire::langue($entree) === $emploi);
    verifier("$nom code exact", Langues::normaliser($entree, code_exact: true) === $exact);
    verifier("$nom attribut", Langues::normaliser(strtolower((string) $entree), code_exact: true, repli: '') === $attribut);
    verifier("$nom locale", Langues::depuis_locale($entree) === $locale);

    $GLOBALS['banc_locale_admin'] = $entree;
    $GLOBALS['banc_locale_page'] = $entree;
    verifier("$nom admin", AdminShell::admin_lang() === $locale);
    // WordPress retourne une chaine pour determine_locale().
    $GLOBALS['banc_locale_page'] = (string) $entree;
    verifier("$nom page Emplois", Module::langue_courante() === $locale);
    foreach ($rendus as $contexte => $rendu) {
        verifier("$nom $contexte", $rendu((string) $entree) === $rendu($exact));
    }

    // Les locales volontairement differentes gardent la source de chaque chemin.
    foreach (['fr_CA', 'en_CA', 'EN_CA', 'es_ES'] as $locale_site) {
        $repli = $locale_site === 'en_CA' ? 'en' : 'fr';
        $GLOBALS['banc_locale'] = $locale_site;
        $GLOBALS['banc_locale_page'] = $locale_site === 'en_CA' ? 'fr_CA' : 'en_CA';
        $repli_page = $locale_site === 'en_CA' ? 'fr' : 'en';

        $GLOBALS['banc_options'][Plugin::OPTION_STATUS] = 'connected';
        $GLOBALS['banc_options'][Plugin::OPTION_WORKSPACE_ID] = 'banc';
        $GLOBALS['banc_options'][Plugin::OPTION_WORKSPACE_SLUG] = 'banc';
        $GLOBALS['banc_options'][Plugin::OPTION_FEATURE_PACKS] = ['forms'];
        $html = $form->render(['slug' => 'test', 'lang' => $entree]);
        $attendu = '<div data-pratcom-form="test"' . ($attribut !== '' ? ' data-lang="' . $attribut . '"' : '') . '></div>';
        verifier("$nom Forms $locale_site", $html === $attendu);

        $GLOBALS['banc_options'][Plugin::OPTION_STATUS] = 'disconnected';
        $choisie = $attribut !== '' ? $attribut : $repli;
        $choisie_page = $attribut !== '' ? $attribut : $repli_page;
        verifier("$nom politique $locale_site", str_contains($policy->render(['lang' => $entree]), 'lang="' . $choisie . '"'));
        verifier("$nom declaration $locale_site", str_contains($cookies->render(['lang' => $entree]), 'lang="' . $choisie_page . '"'));
        verifier("$nom bloc $locale_site", str_contains($blocks->render_cookie_declaration(['lang' => $entree]), 'lang="' . $choisie_page . '"'));

        $GLOBALS['banc_options'][FreeBanner::OPTION_ENABLED] = '1';
        $banner->maybe_enqueue();
        $prefixe = 'window.__pratcomPrivacyLocal = ';
        $json = substr($GLOBALS['banc_inline'], strlen($prefixe), -1);
        $local = json_decode($json, true);
        verifier("$nom banniere $locale_site", ($local['config']['settings']['defaultLang'] ?? null) === $repli);
    }
}

// Le multilingue declare par le site reste plus large que celui du plugin.
$GLOBALS['banc_multilang'] = true;
$GLOBALS['banc_langue_wpml'] = 'es';
verifier('WPML conserve es', Multilang::languages() === ['es', 'fr', 'en']);
verifier('titre espagnol conserve', Multilang::page_title('privacy', 'es') === 'Política de privacidad');
verifier('bloc WPML inconnu garde le repli FR', str_contains($blocks->render_privacy_policy(), 'lang="fr"'));
$GLOBALS['banc_langue_wpml'] = 'en';
verifier('bloc WPML EN', str_contains($blocks->render_privacy_policy(), 'lang="en"'));

// Le contrat du catalogue exige toujours les deux traductions.
$spontanee = ['form_slug' => 'test', 'title' => $section['title'], 'text' => $section['text']];
verifier('catalogue bilingue conserve', Catalogue::lire_spontanee($spontanee) === $spontanee);
unset($spontanee['title']['en']);
verifier('catalogue incomplet refuse', Catalogue::lire_spontanee($spontanee) === null);

echo "\nLangues : $ok verifications OK, $echecs echec(s).\n";
exit($echecs === 0 ? 0 : 1);
