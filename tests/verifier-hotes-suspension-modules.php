<?php
/**
 * Banc CLI : hote unique, suspension, modules lus du handshake (mission Bridge 1).
 * Le depot n'a ni PHPUnit ni Pest.
 *
 *   php tests/verifier-hotes-suspension-modules.php [--src=/autre/dossier/src]
 *
 * Joue le VRAI code de src/ (Hotes, Suspension, HealthCheck, Loader,
 * FeaturePacks, Forms\Shortcode, Privacy\PolicyShortcode,
 * Privacy\CookieDeclaration, Jobs\Catalogue, Http\ApiClient) avec des
 * doublures minimales des fonctions WordPress : options en memoire, filtres
 * reels (add_filter / apply_filters), reponse HTTP du handshake fournie par
 * le banc.
 *
 * --src : rejoue le banc sur une copie modifiee de src/ (bancs de mutation).
 *
 * Dossier tests/ : exclu des deux zips (release.yml, build-org.yml).
 */

namespace {

$options = getopt('', ['src:']);
$src = isset($options['src']) ? rtrim((string) $options['src'], '/\\') : dirname(__DIR__) . '/src';

define('ABSPATH', __DIR__ . '/');
define('PRATCOM_CONNECT_BRIDGE_VERSION', 'banc');
define('PRATCOM_CONNECT_BRIDGE_API_BASE', 'https://api.connect.pratcom.net');
define('PRATCOM_CONNECT_BRIDGE_LOADER_URL', 'https://connect.pratcom.net/loader.js');

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

// --- Doublures WordPress ---------------------------------------------------

$GLOBALS['banc_options'] = [];
$GLOBALS['banc_filtres'] = [];
$GLOBALS['banc_cron'] = [];
$GLOBALS['banc_admin'] = false;
$GLOBALS['banc_reponse'] = null;
$GLOBALS['banc_requetes'] = [];

function get_option($nom, $defaut = false) { return array_key_exists($nom, $GLOBALS['banc_options']) ? $GLOBALS['banc_options'][$nom] : $defaut; }
function update_option($nom, $valeur) { $GLOBALS['banc_options'][$nom] = $valeur; return true; }
function delete_option($nom) { unset($GLOBALS['banc_options'][$nom]); return true; }
function add_filter($tag, $rappel, $prio = 10, $args = 1) { $GLOBALS['banc_filtres'][$tag][] = [$rappel, $args]; return true; }
function add_action($tag, $rappel, $prio = 10, $args = 1) { return add_filter($tag, $rappel, $prio, $args); }
function apply_filters($tag, $valeur, ...$autres)
{
    foreach ($GLOBALS['banc_filtres'][$tag] ?? [] as [$rappel, $n]) {
        $valeur = call_user_func_array($rappel, array_slice(array_merge([$valeur], $autres), 0, $n));
    }
    return $valeur;
}
function add_shortcode($tag, $rappel) { $GLOBALS['banc_codes'][$tag] = $rappel; }
/** Meme semantique que do_shortcode_tag : pre_do_shortcode_tag court-circuite si non false. */
function banc_code_court(string $tag): string
{
    $court = apply_filters('pre_do_shortcode_tag', false, $tag, [], []);
    if ($court !== false) {
        return (string) $court;
    }
    return 'RENDU:' . $tag;
}
function current_user_can($cap) { return $GLOBALS['banc_admin']; }
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return $t; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($u) { return (string) $u; }
function wp_json_encode($v, $o = 0) { return json_encode($v, $o); }
function wp_print_inline_script_tag($js) { echo '<script>' . $js . '</script>'; }
function wp_print_script_tag($a) { echo '<script src="' . $a['src'] . '"></script>'; }
function wp_next_scheduled($hook) { return $GLOBALS['banc_cron'][$hook] ?? false; }
function wp_schedule_single_event($ts, $hook) { $GLOBALS['banc_cron'][$hook] = $ts; return true; }
function wp_unschedule_event($ts, $hook) { unset($GLOBALS['banc_cron'][$hook]); return true; }
function home_url() { return 'https://site.exemple'; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function get_bloginfo($k) { return '6.8'; }
function current_time($t) { return '2026-11-04 12:00:00'; }
function is_wp_error($r) { return false; }
function wp_remote_request($url, $args)
{
    $GLOBALS['banc_requetes'][] = $url;
    return $GLOBALS['banc_reponse'];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

}

namespace Banc {

use Pratcom\Connect\Bridge\FeaturePacks;
use Pratcom\Connect\Bridge\Forms\Shortcode as FormsShortcode;
use Pratcom\Connect\Bridge\HealthCheck;
use Pratcom\Connect\Bridge\Hotes;
use Pratcom\Connect\Bridge\Jobs\Catalogue;
use Pratcom\Connect\Bridge\Loader;
use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Privacy\CookieDeclaration;
use Pratcom\Connect\Bridge\Privacy\PolicyShortcode;
use Pratcom\Connect\Bridge\Suspension;

$echecs = [];
$ok = 0;
function verifier(string $nom, bool $condition, string $detail = ''): void
{
    global $echecs, $ok;
    if ($condition) {
        $ok++;
        echo "  ok    $nom\n";
    } else {
        $echecs[] = $nom;
        echo "  ECHEC $nom" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

function repondre(array $corps, int $code = 200): void
{
    $GLOBALS['banc_reponse'] = ['code' => $code, 'body' => json_encode($corps)];
}

function connecter(): void
{
    update_option(Plugin::OPTION_API_KEY_PLAINTEXT, 'cle-du-banc');
    update_option(Plugin::OPTION_WORKSPACE_ID, 'ws-banc');
    update_option(Plugin::OPTION_WORKSPACE_SLUG, 'espace-banc');
    update_option(Plugin::OPTION_STATUS, 'connected');
}

function loader_html(): string
{
    ob_start();
    (new Loader())->inject_loader();
    return (string) ob_get_clean();
}

/** Les quatre usages des anciennes bases en dur. */
function usages(): array
{
    return [
        'forms'   => FormsShortcode::loader_url(),
        'policy'  => PolicyShortcode::endpoint_base(),
        'cookies' => CookieDeclaration::endpoint_base(),
        'jobs'    => Catalogue::base_url(),
        'loader'  => (function () { preg_match('/src="([^"]+)"/', loader_html(), $m); return $m[1] ?? ''; })(),
    ];
}

connecter();
new Suspension();

echo "1. Hote unique, sans `hosts` au handshake : rien ne change\n";
repondre(['workspace_id' => 'ws-banc', 'workspace_slug' => 'espace-banc', 'feature_packs' => ['forms' => ['enabled' => true]]]);
$r = (new HealthCheck())->run();
verifier('handshake ok', $r['ok'] === true);
verifier('handshake parti vers api.connect par defaut', end($GLOBALS['banc_requetes']) === 'https://api.connect.pratcom.net/api/bridge/handshake', (string) end($GLOBALS['banc_requetes']));
$u = usages();
verifier('forms : chatbot.pratcom.net/loader.js', $u['forms'] === 'https://chatbot.pratcom.net/loader.js', $u['forms']);
verifier('politique : chatbot.pratcom.net', $u['policy'] === 'https://chatbot.pratcom.net', $u['policy']);
verifier('temoins : chatbot.pratcom.net', $u['cookies'] === 'https://chatbot.pratcom.net', $u['cookies']);
verifier('emplois : connect.pratcom.net', $u['jobs'] === 'https://connect.pratcom.net', $u['jobs']);
verifier('loader : connect.pratcom.net/loader.js', $u['loader'] === 'https://connect.pratcom.net/loader.js', $u['loader']);
verifier('aucune option hosts posee', get_option(Hotes::OPTION, null) === null);

echo "2. Hote unique, `hosts` servi par le handshake : une seule source\n";
repondre(['workspace_id' => 'ws-banc', 'feature_packs' => [], 'hosts' => [
    'api' => 'https://api.staging.pratcom.net/', 'app' => 'https://app.staging.pratcom.net', 'chat' => 'https://chat.staging.pratcom.net',
]]);
(new HealthCheck())->run();
$u = usages();
verifier('forms suit chat', $u['forms'] === 'https://chat.staging.pratcom.net/loader.js', $u['forms']);
verifier('politique suit chat', $u['policy'] === 'https://chat.staging.pratcom.net', $u['policy']);
verifier('temoins suit chat', $u['cookies'] === 'https://chat.staging.pratcom.net', $u['cookies']);
verifier('politique et temoins : meme base', $u['policy'] === $u['cookies']);
verifier('emplois suit app', $u['jobs'] === 'https://app.staging.pratcom.net', $u['jobs']);
verifier('loader suit app', $u['loader'] === 'https://app.staging.pratcom.net/loader.js', $u['loader']);
(new HealthCheck())->run();
verifier('handshake suivant part vers api servi (barre finale retiree)', end($GLOBALS['banc_requetes']) === 'https://api.staging.pratcom.net/api/bridge/handshake', (string) end($GLOBALS['banc_requetes']));

echo "3. Garde des hotes : rien hors pratcom.net, rien qu'https, pas de chemin\n";
$refus = ['http://api.pratcom.net', 'https://pratcom.net.evil.example', 'https://evilpratcom.net', 'https://api.pratcom.net/chemin', 'https://u:p@api.pratcom.net', 'https://api.pratcom.net?x=1', 'javascript:alert(1)', ''];
foreach ($refus as $url) {
    verifier("refuse « $url »", Hotes::url_permise($url) === null);
}
verifier('accepte pratcom.net nu', Hotes::url_permise('https://pratcom.net') === 'https://pratcom.net');
verifier('accepte un port', Hotes::url_permise('https://API.pratcom.net:8443/') === 'https://api.pratcom.net:8443');
verifier('role inconnu et valeur non chaine ignores', Hotes::nettoyer(['autre' => 'https://x.pratcom.net', 'api' => ['x']]) === []);
verifier('role inconnu : base app', Hotes::base_dans([], 'inconnu') === 'https://connect.pratcom.net');
repondre(['workspace_id' => 'ws-banc', 'feature_packs' => [], 'hosts' => ['api' => 'https://attaquant.example']]);
(new HealthCheck())->run();
verifier('hosts refuse : option effacee, retour aux defauts', get_option(Hotes::OPTION, null) === null && Hotes::base('api') === 'https://api.connect.pratcom.net');
repondre(['workspace_id' => 'ws-banc', 'feature_packs' => [], 'hosts' => ['chat' => 'https://chat.staging.pratcom.net']]);
(new HealthCheck())->run();
verifier('hosts partiel : chat servi, api et app par defaut',
    Hotes::base('chat') === 'https://chat.staging.pratcom.net' && Hotes::base('api') === 'https://api.connect.pratcom.net' && Hotes::base('app') === 'https://connect.pratcom.net');
repondre(['workspace_id' => 'ws-banc', 'feature_packs' => []]);
(new HealthCheck())->run();
verifier('hosts retire du handshake : defauts', get_option(Hotes::OPTION, null) === null);

echo "4. Suspension\n";
$prochaine = gmdate('c', time() + 3600);
repondre(['workspace_id' => 'ws-banc', 'feature_packs' => ['forms' => ['enabled' => true], 'privacy' => ['enabled' => true], 'jobs' => ['enabled' => true]], 'suspension' => ['etat' => 'actif'], 'next_check_at' => $prochaine]);
(new HealthCheck())->run();
verifier('actif : loader insere', strpos(loader_html(), 'loader.js') !== false);
verifier('actif : codes courts rendus', banc_code_court('pratcom_form') === 'RENDU:pratcom_form');
verifier('next_check_at enregistre', get_option(Suspension::OPTION_PROCHAINE) === gmdate('c', strtotime($prochaine)));
verifier('reverification programmee a next_check_at', ($GLOBALS['banc_cron'][Suspension::CRON_RECHECK] ?? 0) === strtotime($prochaine));

repondre(['workspace_id' => 'ws-banc', 'feature_packs' => ['forms' => ['enabled' => true]], 'suspension' => ['etat' => 'suspendu', 'message_fr' => 'x', 'message_en' => 'y', 'contact' => 'z'], 'next_check_at' => $prochaine]);
(new HealthCheck())->run();
verifier('suspendu enregistre', Suspension::est_suspendu());
verifier('suspendu : loader absent', loader_html() === '', loader_html());
$GLOBALS['banc_admin'] = false;
// Liste ecrite ici, pas lue de Suspension::CODES_COURTS : un oubli dans la
// constante doit faire echouer le banc.
foreach (['pratcom_form', 'pratcom_emplois', 'pratcom_privacy_policy', 'pratcom_cookie_declaration'] as $tag) {
    verifier("suspendu : [$tag] vide pour le visiteur", banc_code_court($tag) === '');
}
verifier('suspendu : code court etranger intact', banc_code_court('gallery') === 'RENDU:gallery');
$GLOBALS['banc_admin'] = true;
verifier('suspendu : note sobre pour l\'admin', strpos(banc_code_court('pratcom_form'), 'suspendu') !== false);
$GLOBALS['banc_admin'] = false;
verifier('suspendu : seul l\'etat est garde', get_option(Suspension::OPTION) === ['etat' => 'suspendu']);

$plus_tard = gmdate('c', time() + 7200);
repondre(['workspace_id' => 'ws-banc', 'feature_packs' => [], 'suspension' => ['etat' => 'suspendu'], 'next_check_at' => $plus_tard]);
(new HealthCheck())->run();
verifier('reverification deplacee au nouveau next_check_at', ($GLOBALS['banc_cron'][Suspension::CRON_RECHECK] ?? 0) === strtotime($plus_tard));

repondre(['workspace_id' => 'ws-banc', 'feature_packs' => ['forms' => ['enabled' => true]], 'suspension' => ['etat' => 'actif']]);
Suspension::reverifier();
verifier('levee a la reverification : loader de retour', strpos(loader_html(), 'loader.js') !== false && !Suspension::est_suspendu());
verifier('levee : codes courts de retour', banc_code_court('pratcom_privacy_policy') === 'RENDU:pratcom_privacy_policy');

repondre(['workspace_id' => 'ws-banc', 'feature_packs' => []]);
(new HealthCheck())->run();
verifier('suspension absente du handshake : actif', !Suspension::est_suspendu());
verifier('etats inconnus : actif', !Suspension::suspendu_dans(['etat' => 'gele']) && !Suspension::suspendu_dans('suspendu') && !Suspension::suspendu_dans(null));
verifier('etat insensible a la casse', Suspension::suspendu_dans(['etat' => ' Suspendu ']));
verifier('next_check_at illisible : null', Suspension::horodatage('pas une date') === null && Suspension::horodatage(null) === null);

echo "5. Modules lus du handshake, jobs compris\n";
verifier('forme table : jobs actif', FeaturePacks::actifs_dans(['chat' => ['enabled' => false], 'jobs' => ['enabled' => true], 'forms' => []]) === ['forms', 'jobs']);
verifier('forme liste : jobs actif', FeaturePacks::actifs_dans(['privacy', 'jobs']) === ['privacy', 'jobs']);
verifier('jobs toujours presente, meme inactif', in_array('jobs', FeaturePacks::cles_dans([]), true));
verifier('module inconnu du plugin : presente quand meme', FeaturePacks::cles_dans(['inventaire' => ['enabled' => true]]) === ['chat', 'forms', 'privacy', 'jobs', 'inventaire']);
verifier('cle nettoyee', FeaturePacks::cles_dans(['<b>x</b>' => true]) === ['chat', 'forms', 'privacy', 'jobs', 'bxb']);
verifier('option illisible : modules connus, aucun actif', FeaturePacks::cles_dans('x') === FeaturePacks::CONNUS && FeaturePacks::actifs_dans('x') === []);
update_option(Plugin::OPTION_FEATURE_PACKS, ['jobs' => ['enabled' => true]]);
verifier('lecture de l\'option du handshake', FeaturePacks::actifs() === ['jobs']);

echo "\n$ok ok, " . count($echecs) . " echec(s)\n";
exit($echecs === [] ? 0 : 1);

}
