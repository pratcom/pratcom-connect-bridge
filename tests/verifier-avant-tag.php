<?php
/**
 * Banc CLI : lot securite avant le tag 2.3.x (E-14, E-15, E-9, E-20, E-21,
 * E-4, E-23, E-24 ; E-29, I-25 et liste fermee des hotes le 19/11). Le depot n'a ni PHPUnit ni Pest.
 *
 *   php tests/verifier-avant-tag.php [--src=/autre/dossier/src]
 *
 * Joue le VRAI code de src/ avec des doublures minimales des fonctions
 * WordPress (options, transients et cron en memoire, reponse HTTP fournie).
 * --src : rejoue le banc sur une copie modifiee de src/ (bancs de mutation).
 *
 * Dossier tests/ : exclu des deux zips (release.yml, build-org.yml).
 */

namespace {

$options = getopt('', ['src:']);
$src = isset($options['src']) ? rtrim((string) $options['src'], '/\\') : dirname(__DIR__) . '/src';
$GLOBALS['banc_src'] = $src;

define('ABSPATH', __DIR__ . '/');
define('PRATCOM_CONNECT_BRIDGE_VERSION', 'banc');
define('PRATCOM_CONNECT_BRIDGE_API_BASE', 'https://api.connect.pratcom.net');
define('PRATCOM_CONNECT_BRIDGE_LOADER_URL', 'https://connect.pratcom.net/loader.js');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

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

$GLOBALS['banc_options'] = [];
$GLOBALS['banc_transients'] = [];
$GLOBALS['banc_filtres'] = [];
$GLOBALS['banc_cron'] = [];
$GLOBALS['banc_reponse'] = null;
$GLOBALS['banc_args'] = [];
$GLOBALS['banc_insertions'] = [];

function get_option($nom, $defaut = false) { return array_key_exists($nom, $GLOBALS['banc_options']) ? $GLOBALS['banc_options'][$nom] : $defaut; }
function update_option($nom, $valeur, $auto = null) { $GLOBALS['banc_options'][$nom] = $valeur; return true; }
function delete_option($nom) { unset($GLOBALS['banc_options'][$nom]); return true; }
// Transients avec expiration, sur l'horloge du banc (E-29) : comme WordPress,
// chaque set_transient repose l'expiration a maintenant + duree.
function banc_maintenant(): int { return $GLOBALS['banc_temps'] ?? time(); }
function get_transient($nom)
{
    $t = $GLOBALS['banc_transients'][$nom] ?? null;
    if (!is_array($t) || ($t[1] > 0 && $t[1] <= banc_maintenant())) {
        return false;
    }
    return $t[0];
}
function set_transient($nom, $v, $d = 0) { $GLOBALS['banc_transients'][$nom] = [$v, $d > 0 ? banc_maintenant() + (int) $d : 0]; $GLOBALS['banc_durees'][$nom] = (int) $d; return true; }
function flush_rewrite_rules($dur = true) { $GLOBALS['banc_flush'] = ($GLOBALS['banc_flush'] ?? 0) + 1; }
function get_post($id) { return null; }
function add_filter($tag, $rappel, $prio = 10, $args = 1) { $GLOBALS['banc_filtres'][$tag][] = [$rappel, $args]; return true; }
function add_action($tag, $rappel, $prio = 10, $args = 1) { return add_filter($tag, $rappel, $prio, $args); }
function apply_filters($tag, $valeur, ...$autres)
{
    foreach ($GLOBALS['banc_filtres'][$tag] ?? [] as [$rappel, $n]) {
        $valeur = call_user_func_array($rappel, array_slice(array_merge([$valeur], $autres), 0, $n));
    }
    return $valeur;
}
function current_user_can($cap) { return false; }
function is_admin() { return false; }
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return $t; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($u) { return (string) $u; }
function esc_url_raw($u) { return (string) $u; }
function sanitize_text_field($t) { return trim((string) $t); }
function sanitize_key($t) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $t)); }
function wp_unslash($v) { return $v; }
function wp_salt($s = '') { return 'sel-du-banc'; }
function wp_json_encode($v, $o = 0) { return json_encode($v, $o); }
function wp_print_inline_script_tag($js) { echo '<script>' . $js . '</script>'; }
function wp_print_script_tag($a) { echo '<script src="' . $a['src'] . '"></script>'; }
function wp_next_scheduled($hook) { return $GLOBALS['banc_cron'][$hook] ?? false; }
function wp_schedule_single_event($ts, $hook) { $GLOBALS['banc_cron'][$hook] = $ts; return true; }
function wp_unschedule_event($ts, $hook) { unset($GLOBALS['banc_cron'][$hook]); return true; }
function wp_schedule_event($ts, $r, $hook) { $GLOBALS['banc_cron'][$hook] = $ts; return true; }
function home_url() { return 'https://site.exemple'; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function get_bloginfo($k) { return '6.8'; }
function current_time($t, $gmt = 0) { return '2026-11-12 12:00:00'; }
function is_wp_error($r) { return false; }
function wp_remote_request($url, $args)
{
    $GLOBALS['banc_args'] = $args;
    return $GLOBALS['banc_reponse'];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

class WP_REST_Response
{
    public $data;
    public $status;
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
}

class Banc_Requete
{
    private array $p;
    public function __construct(array $p) { $this->p = $p; }
    public function get_param($k) { return $this->p[$k] ?? null; }
}

class Banc_Wpdb
{
    public $prefix = 'wp_';
    public function insert($table, $ligne) { $GLOBALS['banc_insertions'][] = $ligne; return 1; }
}
$GLOBALS['wpdb'] = new Banc_Wpdb();

}

namespace Pratcom\Connect\Bridge\Privacy {

// Horloge du banc pour LocalRegistry (appel non qualifie de time()).
function time(): int
{
    return \banc_maintenant();
}

}

namespace Banc {

use Pratcom\Connect\Bridge\FeaturePacks;
use Pratcom\Connect\Bridge\HealthCheck;
use Pratcom\Connect\Bridge\Hotes;
use Pratcom\Connect\Bridge\Http\ApiClient;
use Pratcom\Connect\Bridge\Jobs\Module;
use Pratcom\Connect\Bridge\Loader;
use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Privacy\LocalRegistry;
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

function connecter(): void
{
    update_option(Plugin::OPTION_API_KEY_PLAINTEXT, 'cle-du-banc');
    update_option(Plugin::OPTION_WORKSPACE_ID, 'ws-banc');
    update_option(Plugin::OPTION_WORKSPACE_SLUG, 'espace-banc');
    update_option(Plugin::OPTION_STATUS, 'connected');
}

connecter();

echo "E-14. Emplois eteint sur un site suspendu\n";
update_option(Plugin::OPTION_FEATURE_PACKS, ['jobs' => ['enabled' => true]]);
verifier('actif : connecte, pack jobs, non suspendu', Module::actif() === true);
update_option(Suspension::OPTION, ['etat' => 'suspendu']);
verifier('inactif : site suspendu', Module::actif() === false);
verifier('aucune regle de reecriture sur un site suspendu', Module::regles_voulues() === []);
delete_option(Suspension::OPTION);
verifier('actif a nouveau apres levee', Module::actif() === true);
update_option(Suspension::OPTION, ['etat' => 'actif']);
verifier('etat actif explicite : actif', Module::actif() === true);
delete_option(Suspension::OPTION);

echo "E-15. Transport : aucune redirection, 2xx sans JSON refuse\n";
$api = new ApiClient('https://api.connect.pratcom.net');
$GLOBALS['banc_reponse'] = ['code' => 200, 'body' => json_encode(['forms' => []])];
$r = $api->get_forms('cle');
verifier('2xx JSON : ok', ($r['ok'] ?? null) === true);
verifier('redirection = 0', ($GLOBALS['banc_args']['redirection'] ?? null) === 0, var_export($GLOBALS['banc_args']['redirection'] ?? null, true));
$GLOBALS['banc_reponse'] = ['code' => 200, 'body' => '<html>portail</html>'];
$r = $api->get_forms('cle');
verifier('2xx HTML : pas ok', ($r['ok'] ?? null) === false);
verifier('2xx HTML : erreur invalid_json', ($r['error'] ?? '') === 'invalid_json');
$GLOBALS['banc_reponse'] = ['code' => 204, 'body' => ''];
verifier('2xx vide : pas ok', ($api->get_forms('cle')['ok'] ?? null) === false);
$GLOBALS['banc_reponse'] = ['code' => 200, 'body' => json_encode(['ok' => false, 'error' => 'x'])];
verifier('2xx JSON ok:false : reste pas ok (comme avant)', ($api->get_forms('cle')['ok'] ?? null) === false);
$GLOBALS['banc_reponse'] = ['code' => 500, 'body' => '"chaine"'];
$r = $api->get_forms('cle');
verifier('5xx corps non objet : http_error sans avertissement', ($r['error'] ?? '') === 'http_error');

echo "E-15 suite. Un handshake HTML n'efface pas l'etat\n";
update_option(Plugin::OPTION_FEATURE_PACKS, ['jobs' => ['enabled' => true]]);
$GLOBALS['banc_reponse'] = ['code' => 200, 'body' => 'maintenance'];
$r = (new HealthCheck())->run();
verifier('handshake HTML : pas ok', $r['ok'] === false);
verifier('modules conserves', FeaturePacks::is_active('jobs'));
connecter();

echo "E-9. Desactivation et 401 retirent la reverification\n";
$GLOBALS['banc_cron'] = [HealthCheck::CRON_HOOK => time() + 3600, Suspension::CRON_RECHECK => time() + 600];
HealthCheck::unschedule();
verifier('desactivation : passage horaire retire', !isset($GLOBALS['banc_cron'][HealthCheck::CRON_HOOK]));
verifier('desactivation : reverification retiree', !isset($GLOBALS['banc_cron'][Suspension::CRON_RECHECK]));
$GLOBALS['banc_cron'] = [Suspension::CRON_RECHECK => time() + 600];
$GLOBALS['banc_reponse'] = ['code' => 401, 'body' => json_encode(['error' => 'invalid_key'])];
$r = (new HealthCheck())->run();
verifier('401 : revoked', $r['status'] === 'revoked');
verifier('401 : reverification retiree', !isset($GLOBALS['banc_cron'][Suspension::CRON_RECHECK]));
connecter();

echo "E-20. Enregistrer le theme applique tout le handshake\n";
$source = str_replace("\r\n", "\n", (string) file_get_contents($GLOBALS['banc_src'] . '/Admin/Tabs/AppearanceTab.php'));
preg_match('/function handle_save_theme\(\).*?\n    }\n/s', $source, $m);
$corps = $m[0] ?? '';
verifier('handle_save_theme appelle HealthCheck::appliquer', strpos($corps, 'HealthCheck::appliquer($res)') !== false);
verifier('plus d\'ecriture partielle des modules', strpos($corps, 'OPTION_FEATURE_PACKS') === false);

echo "E-21. Hotes : port autre que 443 refuse\n";
verifier('sans port : permis', Hotes::url_permise('https://api.connect.pratcom.net') === 'https://api.connect.pratcom.net');
verifier('port 443 : permis, sans port', Hotes::url_permise('https://api.connect.pratcom.net:443') === 'https://api.connect.pratcom.net');
verifier('port 8443 : refuse', Hotes::url_permise('https://api.connect.pratcom.net:8443') === null);
verifier('port 22 : refuse', Hotes::url_permise('https://api.pratcom.net:22') === null);
verifier('autre domaine : refuse (inchange)', Hotes::url_permise('https://pratcom.net.exemple') === null);

echo "E-4. Configuration en ligne : aucune balise ne sort du script\n";
update_option(Plugin::OPTION_FEATURE_PACKS, ['chat' => ['nom' => '</script><script>alert(1)</script>', 'apo' => "l'&\""]]);
ob_start();
(new Loader())->inject_loader();
$html = (string) ob_get_clean();
preg_match('/<script>(.*?)<\/script>/s', $html, $m2);
$inline = $m2[1] ?? '';
verifier('script en ligne present', strpos($inline, 'window.__pratcomConnect=') === 0, $html);
verifier('aucun < ni > dans la config', strpos($inline, '<') === false && strpos($inline, '>') === false, $inline);
$json = substr($inline, strlen('window.__pratcomConnect='), -1);
$dec = json_decode($json, true);
verifier('config relue a l\'identique', ($dec['featurePacks']['chat']['nom'] ?? '') === '</script><script>alert(1)</script>');
verifier('barres obliques des URL gardees', strpos(json_encode(['u' => 'https://a/b'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG), 'https://a/b') !== false);
update_option(Plugin::OPTION_FEATURE_PACKS, ['jobs' => ['enabled' => true]]);

echo "E-23. Export CSV : cellules neutralisees\n";
foreach (['=HYPERLINK("x")', '+1', '-2+3', '@SUM(A1)', "\tx", "\rx"] as $piege) {
    verifier('prefixe : ' . json_encode($piege), LocalRegistry::cellule_csv($piege) === "'" . $piege);
}
foreach (['', 'a-l2x', 'accept_all', 'https://site.exemple/', '{"marketing":true}', '2026-11-12 12:00:00'] as $sain) {
    verifier('inchange : ' . json_encode($sain), LocalRegistry::cellule_csv($sain) === $sain);
}
$ligne = LocalRegistry::ligne_csv(['id' => 7, 'anonymous_id' => '=1+1', 'user_agent' => '@x', 'source_url' => 'https://s/']);
verifier('ligne : 11 colonnes', count($ligne) === 11);
verifier('ligne : id en premier', $ligne[0] === '7');
verifier('ligne : anonymous_id neutralise', $ligne[2] === "'=1+1");
verifier('ligne : user_agent neutralise', $ligne[10] === "'@x");
verifier('ligne : source_url inchangee', $ligne[8] === 'https://s/');

echo "E-24. Registre : identifiant borne, plafond global\n";
verifier('identifiant de privacy.js garde', LocalRegistry::identifiant_anonyme('a-mh3k2x-4f9z0q1abc') === 'a-mh3k2x-4f9z0q1abc');
verifier('formule videe de ses signes', LocalRegistry::identifiant_anonyme('=cmd|x') === 'cmdx');
verifier('64 caracteres au plus', strlen(LocalRegistry::identifiant_anonyme(str_repeat('a', 200))) === 64);
verifier('non chaine : vide', LocalRegistry::identifiant_anonyme(['x']) === '');

$reg = new LocalRegistry();
$req = new \Banc_Requete(['action' => 'accept_all', 'choices' => ['marketing' => true], 'anonymousId' => '=evil', 'sourceUrl' => 'https://site.exemple/']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
$rep = $reg->handle_consent($req);
verifier('consentement inscrit : 201', $rep->status === 201);
verifier('identifiant inscrit sans formule', ($GLOBALS['banc_insertions'][0]['anonymous_id'] ?? '') === 'evil');

$GLOBALS['banc_transients'] = [];
$GLOBALS['banc_insertions'] = [];
add_filter('pratcom_connect_registre_plafond_global', static fn() => 5);
$codes = [];
for ($i = 0; $i < 8; $i++) {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i; // une IP par requete : le plafond par IP ne joue pas
    $codes[] = $reg->handle_consent($req)->status;
}
verifier('5 premiers inscrits', array_slice($codes, 0, 5) === [201, 201, 201, 201, 201], implode(',', $codes));
verifier('au-dela du plafond global : 429', array_slice($codes, 5) === [429, 429, 429], implode(',', $codes));
verifier('5 lignes seulement', count($GLOBALS['banc_insertions']) === 5);
verifier('plafond par defaut : 1000 par heure', LocalRegistry::PLAFOND_GLOBAL === 1000);

$GLOBALS['banc_transients'] = [];
$GLOBALS['banc_filtres']['pratcom_connect_registre_plafond_global'] = [];
$_SERVER['REMOTE_ADDR'] = '192.0.2.9';
$codes = [];
for ($i = 0; $i < 21; $i++) {
    $codes[] = $reg->handle_consent($req)->status;
}
verifier('plafond par IP inchange : 20 puis 429', count(array_filter($codes, static fn($c) => $c === 201)) === 20 && end($codes) === 429);

echo "E-29. Plafond global fenetre : apres l'IP et la validation, tranche horaire fixe\n";
$GLOBALS['banc_transients'] = [];
$GLOBALS['banc_insertions'] = [];
$GLOBALS['banc_filtres']['pratcom_connect_registre_plafond_global'] = [];
$GLOBALS['banc_temps'] = gmmktime(10, 15, 0, 11, 19, 2026);
$reg = new LocalRegistry();
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
$codesA = [];
for ($i = 0; $i < 1000; $i++) {
    $codesA[] = $reg->handle_consent($req)->status;
}
verifier('IP A : 20 inscrits puis 429', count(array_filter($codesA, static fn($c) => $c === 201)) === 20 && end($codesA) === 429);
$_SERVER['REMOTE_ADDR'] = '203.0.113.51';
$rep = $reg->handle_consent($req);
verifier('IP A a 1000 requetes : IP B inscrite (201)', $rep->status === 201, (string) $rep->status);

$GLOBALS['banc_transients'] = [];
add_filter('pratcom_connect_registre_plafond_global', static fn() => 3);
$invalide = new \Banc_Requete(['action' => 'pirate', 'choices' => []]);
$sansChoix = new \Banc_Requete(['action' => 'accept_all', 'choices' => 'x']);
$codes = [];
for ($i = 0; $i < 10; $i++) {
    $_SERVER['REMOTE_ADDR'] = '198.18.0.' . $i;
    $codes[] = $reg->handle_consent($i % 2 ? $invalide : $sansChoix)->status;
}
verifier('requetes invalides : 400', array_unique($codes) === [400], implode(',', array_unique($codes)));
$codes = [];
for ($i = 0; $i < 4; $i++) {
    $_SERVER['REMOTE_ADDR'] = '198.18.1.' . $i;
    $codes[] = $reg->handle_consent($req)->status;
}
verifier('10 requetes invalides ne comptent pas : 3 inscrits sur un plafond de 3, puis 429', $codes === [201, 201, 201, 429], implode(',', $codes));
$GLOBALS['banc_filtres']['pratcom_connect_registre_plafond_global'] = [];

// Compteur a 999 a 10 h 59 : il vaut 0 a 11 h 00.
$GLOBALS['banc_transients'] = [];
$GLOBALS['banc_temps'] = gmmktime(10, 0, 0, 11, 19, 2026);
for ($i = 0; $i < 999; $i++) {
    $_SERVER['REMOTE_ADDR'] = '10.' . intdiv($i, 250) . '.' . ($i % 250) . '.1';
    $reg->handle_consent($req);
}
$GLOBALS['banc_temps'] = gmmktime(10, 59, 30, 11, 19, 2026);
$cle1059 = method_exists(LocalRegistry::class, 'cle_globale') ? LocalRegistry::cle_globale($GLOBALS['banc_temps']) : '';
verifier('cle par tranche (suffixe AAAAMMJJHH)', $cle1059 === 'pratcom_consent_rl_global_2026111910', $cle1059);
verifier('compteur a 999 a 10 h 59', $cle1059 !== '' && get_transient($cle1059) === 999, var_export($cle1059 === '' ? null : get_transient($cle1059), true));
verifier('expiration fixe : fin de la tranche (30 s a 10 h 59 min 30)', method_exists(LocalRegistry::class, 'fin_de_tranche') && LocalRegistry::fin_de_tranche($GLOBALS['banc_temps']) === 30);
$_SERVER['REMOTE_ADDR'] = '10.9.9.1';
verifier('1000e consentement a 10 h 59 : 201', $reg->handle_consent($req)->status === 201);
$_SERVER['REMOTE_ADDR'] = '10.9.9.2';
verifier('1001e a 10 h 59 : 429', $reg->handle_consent($req)->status === 429);
verifier('l\'ecriture ne repousse pas l\'expiration', ($GLOBALS['banc_durees'][$cle1059] ?? 0) <= 30, (string) ($GLOBALS['banc_durees'][$cle1059] ?? '?'));
$GLOBALS['banc_temps'] = gmmktime(11, 0, 0, 11, 19, 2026);
$cle1100 = method_exists(LocalRegistry::class, 'cle_globale') ? LocalRegistry::cle_globale($GLOBALS['banc_temps']) : '';
verifier('a 11 h 00 le compteur vaut 0', $cle1100 !== '' && $cle1100 !== $cle1059 && (int) get_transient($cle1100) === 0);
$_SERVER['REMOTE_ADDR'] = '10.9.9.3';
verifier('a 11 h 00 un consentement est inscrit', $reg->handle_consent($req)->status === 201);
unset($GLOBALS['banc_temps']);
$GLOBALS['banc_transients'] = [];

echo "I-25. Un 5xx passager au handshake laisse regles et fiches en place\n";
connecter();
update_option(Plugin::OPTION_FEATURE_PACKS, ['jobs' => ['enabled' => true]]);
update_option(Module::OPTION_REPRENDRE_JOBS, true);
$GLOBALS['banc_flush'] = 0;
Module::synchroniser_regles();
$regles = Module::regles_voulues();
verifier('regles posees au depart (un flush)', $regles !== [] && $GLOBALS['banc_flush'] === 1);
foreach ([['code' => 503, 'body' => json_encode(['error' => 'indisponible'])], ['code' => 500, 'body' => 'erreur'], ['code' => 502, 'body' => '<html>passerelle</html>'], ['code' => 200, 'body' => '<html>maintenance</html>'], ['code' => 429, 'body' => '{}']] as $rep) {
    $GLOBALS['banc_reponse'] = $rep;
    $r = (new HealthCheck())->run();
    $libelle = 'HTTP ' . $rep['code'];
    verifier("$libelle : pas ok", $r['ok'] === false);
    verifier("$libelle : site toujours connecte", Plugin::is_connected(), (string) get_option(Plugin::OPTION_STATUS));
    verifier("$libelle : erreur notee", (string) get_option(Plugin::OPTION_LAST_ERROR, '') !== '');
    verifier("$libelle : module Emplois actif (fiches servies)", Module::actif());
    Module::synchroniser_regles();
    verifier("$libelle : regles identiques", Module::regles_voulues() === $regles);
    verifier("$libelle : aucun flush_rewrite_rules", $GLOBALS['banc_flush'] === 1, (string) $GLOBALS['banc_flush']);
}
$GLOBALS['banc_reponse'] = ['code' => 403, 'body' => json_encode(['error' => 'domain_not_allowed'])];
$r = (new HealthCheck())->run();
verifier('403 (refus durable) : statut error, comme avant', $r['status'] === 'error' && get_option(Plugin::OPTION_STATUS) === 'error');
update_option(Plugin::OPTION_STATUS, 'error');
$GLOBALS['banc_reponse'] = ['code' => 503, 'body' => '{}'];
(new HealthCheck())->run();
verifier('5xx sur un site deja en erreur : reste en erreur', get_option(Plugin::OPTION_STATUS) === 'error');
delete_option(Module::OPTION_REPRENDRE_JOBS);
connecter();

echo "E-21. Hotes : liste fermee de 3 hotes\n";
foreach (['https://api.connect.pratcom.net', 'https://connect.pratcom.net', 'https://chatbot.pratcom.net'] as $permis) {
    verifier("permis : $permis", Hotes::url_permise($permis) === $permis);
}
verifier('casse ramenee en minuscules', Hotes::url_permise('https://API.Connect.Pratcom.net') === 'https://api.connect.pratcom.net');
foreach (['https://autre.pratcom.net', 'https://pratcom.net', 'https://app.connect.pratcom.net', 'https://x.api.connect.pratcom.net', 'https://connect.pratcom.net.exemple'] as $refuse) {
    verifier("refuse : $refuse", Hotes::url_permise($refuse) === null);
}
add_filter('pratcom_connect_hotes_domaines', static fn() => ['pratcom.net', 'exemple.org']);
verifier('le filtre ne rouvre plus la liste', Hotes::url_permise('https://autre.pratcom.net') === null && Hotes::url_permise('https://x.exemple.org') === null);
$GLOBALS['banc_filtres']['pratcom_connect_hotes_domaines'] = [];
verifier('handshake : hote hors liste ignore, defaut garde', Hotes::nettoyer(['api' => 'https://autre.pratcom.net', 'chat' => 'https://chatbot.pratcom.net']) === ['chat' => 'https://chatbot.pratcom.net']);

echo "\n$ok verification(s) reussie(s), " . count($echecs) . " echec(s).\n";
exit($echecs === [] ? 0 : 1);

}
