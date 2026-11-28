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
define('PRATCOM_CONNECT_BRIDGE_FILE', dirname(__DIR__) . '/pratcom-connect-bridge.php');

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
function wp_schedule_event($ts, $r, $hook) { $GLOBALS['banc_cron'][$hook] = $ts; $GLOBALS['banc_recurrence'][$hook] = $r; return true; }
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function home_url() { return 'https://site.exemple'; }
function site_url() { return $GLOBALS['banc_site_url'] ?? 'https://site.exemple'; }
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

// Par defaut, la requete vient d'une page du site (en-tete Origin du
// navigateur) ; les bancs d'origine (E-24, 27/11) passent leurs en-tetes.
class Banc_Requete
{
    private array $p;
    private array $h;
    public function __construct(array $p, ?array $h = null) { $this->p = $p; $this->h = $h ?? ['origin' => 'https://site.exemple']; }
    public function get_param($k) { return $this->p[$k] ?? null; }
    public function get_header($k) { return $this->h[strtolower($k)] ?? null; }
}

// Table du registre en memoire pour la purge : prepare() comme WordPress
// (%i, %s, %d), query() ne comprend que le DELETE borne de LocalRegistry.
class Banc_Wpdb
{
    public $prefix = 'wp_';
    public array $requetes = [];
    public function insert($table, $ligne) { $GLOBALS['banc_insertions'][] = $ligne; return 1; }
    public function prepare($sql, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%([isd])/', static function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return $m[1] === 'i' ? '`' . $v . '`' : ($m[1] === 'd' ? (string) (int) $v : "'" . addslashes((string) $v) . "'");
        }, $sql);
    }
    public function query($sql)
    {
        $this->requetes[] = $sql;
        if (!preg_match("/^DELETE FROM `wp_pratcom_consents` WHERE created_at < '([^']+)' LIMIT (\d+)$/", $sql, $m)) {
            return false;
        }
        $n = 0;
        foreach ($GLOBALS['banc_table'] ?? [] as $k => $ligne) {
            if ($n < (int) $m[2] && strcmp($ligne['created_at'], $m[1]) < 0) {
                unset($GLOBALS['banc_table'][$k]);
                $n++;
            }
        }
        return $n;
    }
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

echo "E-24 (27/11). Registre : consentement refuse sans origine du site\n";
// Mesure sur main au 27/11 : 0 nonce, 0 controle d'origine. Un nonce casse
// les pages en cache : c'est l'en-tete Origin (envoye par le navigateur sur
// tout POST de fetch) qui est exige.
$GLOBALS['banc_transients'] = [];
$GLOBALS['banc_insertions'] = [];
$GLOBALS['banc_filtres']['pratcom_connect_registre_plafond_global'] = [];
$reg = new LocalRegistry();
$corps = ['action' => 'accept_all', 'choices' => ['marketing' => true], 'anonymousId' => 'a-1', 'sourceUrl' => 'https://site.exemple/'];
$cas = [
    'sans en-tete Origin' => [[], 403],
    'Origin vide' => [['origin' => ''], 403],
    'Origin null (iframe isolee, fichier local)' => [['origin' => 'null'], 403],
    'Origin d\'un autre site' => [['origin' => 'https://autre.exemple'], 403],
    'Origin qui commence par le site' => [['origin' => 'https://site.exemple.attaque.net'], 403],
    'Origin du site en http' => [['origin' => 'http://site.exemple'], 403],
    'Origin du site sur un autre port' => [['origin' => 'https://site.exemple:8443'], 403],
    'Origin du site' => [['origin' => 'https://site.exemple'], 201],
    'Origin du site, casse et port par defaut' => [['origin' => 'https://SITE.Exemple:443'], 201],
];
$i = 0;
foreach ($cas as $libelle => [$entetes, $attendu]) {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.' . (100 + $i++);
    $avant = count($GLOBALS['banc_insertions']);
    $rep = $reg->handle_consent(new \Banc_Requete($corps, $entetes));
    $inscrit = count($GLOBALS['banc_insertions']) > $avant;
    verifier("$libelle : $attendu" . ($attendu === 201 ? ', inscrit' : ', rien inscrit'), $rep->status === $attendu && $inscrit === ($attendu === 201), (string) $rep->status);
}
verifier('refus : code invalid_origin', ($reg->handle_consent(new \Banc_Requete($corps, []))->data['error'] ?? '') === 'invalid_origin');
$GLOBALS['banc_site_url'] = 'https://wp.site.exemple';
$_SERVER['REMOTE_ADDR'] = '203.0.113.120';
verifier('origine de site_url() (WordPress dans un autre hote) : 201', $reg->handle_consent(new \Banc_Requete($corps, ['origin' => 'https://wp.site.exemple']))->status === 201);
unset($GLOBALS['banc_site_url']);
add_filter('pratcom_connect_registre_origines', static fn() => ['https://en.site.exemple/une/page']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.121';
verifier('origine ajoutee par le filtre (domaine par langue) : 201', $reg->handle_consent(new \Banc_Requete($corps, ['origin' => 'https://en.site.exemple']))->status === 201);
$GLOBALS['banc_filtres']['pratcom_connect_registre_origines'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.122';
verifier('filtre retire : cette origine est refusee', $reg->handle_consent(new \Banc_Requete($corps, ['origin' => 'https://en.site.exemple']))->status === 403);

// Les refus d'origine ne remplissent pas le plafond global.
$GLOBALS['banc_transients'] = [];
add_filter('pratcom_connect_registre_plafond_global', static fn() => 3);
for ($i = 0; $i < 10; $i++) {
    $_SERVER['REMOTE_ADDR'] = '198.18.2.' . $i;
    $reg->handle_consent(new \Banc_Requete($corps, ['origin' => 'https://autre.exemple']));
}
$codes = [];
for ($i = 0; $i < 4; $i++) {
    $_SERVER['REMOTE_ADDR'] = '198.18.3.' . $i;
    $codes[] = $reg->handle_consent(new \Banc_Requete($corps))->status;
}
verifier('10 requetes d\'un autre site ne comptent pas : 3 inscrits sur 3, puis 429', $codes === [201, 201, 201, 429], implode(',', $codes));
$GLOBALS['banc_filtres']['pratcom_connect_registre_plafond_global'] = [];
$GLOBALS['banc_transients'] = [];

echo "E-24 (27/11). Registre : duree de garde de 12 mois, purge bornee\n";
$aGarde = defined(LocalRegistry::class . '::DUREE_GARDE_MOIS');
verifier('duree de garde nommee : DUREE_GARDE_MOIS = 12', $aGarde && LocalRegistry::DUREE_GARDE_MOIS === 12);
$GLOBALS['banc_temps'] = gmmktime(12, 0, 0, 11, 27, 2026);
$seuil = method_exists(LocalRegistry::class, 'seuil_garde') ? LocalRegistry::seuil_garde($GLOBALS['banc_temps']) : '';
verifier('seuil au 27/11/2026 12 h UTC : 27/11/2025 12 h', $seuil === '2025-11-27 12:00:00', $seuil);
$GLOBALS['banc_table'] = [
    ['id' => 1, 'created_at' => '2024-05-01 08:00:00'],
    ['id' => 2, 'created_at' => '2025-11-27 11:59:59'],
    ['id' => 3, 'created_at' => '2025-11-27 12:00:00'],
    ['id' => 4, 'created_at' => '2026-01-15 09:30:00'],
    ['id' => 5, 'created_at' => '2026-11-27 11:00:00'],
];
$purgees = method_exists(LocalRegistry::class, 'purger') ? LocalRegistry::purger() : -1;
$restantes = array_column($GLOBALS['banc_table'], 'id');
sort($restantes);
verifier('purge : 2 lignes plus vieilles que 12 mois supprimees', $purgees === 2, (string) $purgees);
verifier('purge : la ligne d\'exactement 12 mois et les recentes restent', $restantes === [3, 4, 5], implode(',', $restantes));
$sql = (string) end($GLOBALS['wpdb']->requetes);
verifier('purge : DELETE borne (LIMIT ' . ($aGarde ? LocalRegistry::PURGE_LOT : '?') . ') sur created_at', $aGarde && strpos($sql, 'WHERE created_at < ') !== false && preg_match('/LIMIT ' . LocalRegistry::PURGE_LOT . '$/', $sql) === 1, $sql);
$GLOBALS['banc_table'] = [];
for ($i = 0; $i < 2500; $i++) {
    $GLOBALS['banc_table'][] = ['id' => $i, 'created_at' => '2024-01-01 00:00:00'];
}
$GLOBALS['banc_table'][] = ['id' => 9999, 'created_at' => '2026-11-01 00:00:00'];
$passages = [];
for ($i = 0; $i < 4 && method_exists(LocalRegistry::class, 'purger'); $i++) {
    $passages[] = LocalRegistry::purger();
}
verifier('2500 lignes vieilles : 1000, 1000, 500, 0 par appel', $passages === [1000, 1000, 500, 0], implode(',', $passages));
verifier('la ligne recente survit aux passages', array_column($GLOBALS['banc_table'], 'id') === [9999]);

echo "R2 (27/11). Purge sur une tache quotidienne propre au registre, qui survit a la deconnexion
";
$aCron = defined(LocalRegistry::class . '::CRON_PURGE');
$crochet = $aCron ? LocalRegistry::CRON_PURGE : '?';
verifier('tache nommee, distincte du passage horaire de HealthCheck', $aCron && $crochet !== HealthCheck::CRON_HOOK, $crochet);
$GLOBALS['banc_filtres'] = [];
$GLOBALS['banc_cron'] = [HealthCheck::CRON_HOOK => banc_maintenant() + 600];
$GLOBALS['banc_recurrence'] = [];
new LocalRegistry();
verifier('mise a jour (site deja installe, tache absente) : programmee au chargement', isset($GLOBALS['banc_cron'][$crochet]));
verifier('recurrence quotidienne', ($GLOBALS['banc_recurrence'][$crochet] ?? '') === 'daily', (string) ($GLOBALS['banc_recurrence'][$crochet] ?? ''));
$premier = $GLOBALS['banc_cron'][$crochet] ?? null;
new LocalRegistry();
verifier('chargement suivant : rien de reprogramme (meme echeance)', ($GLOBALS['banc_cron'][$crochet] ?? null) === $premier);
$branche = false;
foreach ($GLOBALS['banc_filtres'][$crochet] ?? [] as [$rappel, $n]) {
    $branche = $branche || ($rappel === [LocalRegistry::class, 'purger_passage'] && $n === 0);
}
verifier('purge branchee sur sa tache, sans argument', $branche);
$surHoraire = false;
foreach ($GLOBALS['banc_filtres'][HealthCheck::CRON_HOOK] ?? [] as [$rappel]) {
    $surHoraire = $surHoraire || (is_array($rappel) && ($rappel[0] ?? null) === LocalRegistry::class);
}
verifier('purge plus branchee sur le passage horaire de HealthCheck', !$surHoraire);

HealthCheck::unschedule();
verifier('HealthCheck::unschedule() : purge toujours programmee', isset($GLOBALS['banc_cron'][$crochet]));
verifier('HealthCheck::unschedule() : passage horaire retire', !isset($GLOBALS['banc_cron'][HealthCheck::CRON_HOOK]));
$GLOBALS['banc_cron'][HealthCheck::CRON_HOOK] = banc_maintenant() + 600;
\Pratcom\Connect\Bridge\Admin\Tabs\ConnectionTab::purger_connexion();
verifier('deconnexion : purge toujours programmee', isset($GLOBALS['banc_cron'][$crochet]));
verifier('deconnexion : passage horaire retire (inchange)', !isset($GLOBALS['banc_cron'][HealthCheck::CRON_HOOK]));
connecter();

$desactivation = 'deactivate_pratcom-connect-bridge/pratcom-connect-bridge.php';
$retrait = null;
foreach ($GLOBALS['banc_filtres'][$desactivation] ?? [] as [$rappel, $n]) {
    if ($rappel === [LocalRegistry::class, 'deprogrammer_purge'] && $n === 0) {
        $retrait = $rappel;
    }
}
verifier('desactivation : retrait branche sur ' . $desactivation, $retrait !== null);
if ($retrait !== null) {
    $retrait();
}
verifier('desactivation : purge retiree', !isset($GLOBALS['banc_cron'][$crochet]));
$plugin = str_replace("\r\n", "\n", (string) file_get_contents($GLOBALS['banc_src'] . '/Plugin.php'));
$corps = static fn(string $f): string => preg_match('/function ' . $f . '\(\).*?\n    }\n/s', $plugin, $mm) ? $mm[0] : '';
verifier('activation : Plugin::on_activate programme la purge', strpos($corps('on_activate'), 'LocalRegistry::programmer_purge()') !== false);
verifier('desinstallation : Plugin::on_uninstall retire la purge', strpos($corps('on_uninstall'), 'LocalRegistry::deprogrammer_purge()') !== false);

$GLOBALS['banc_table'] = [];
for ($i = 0; $i < 30500; $i++) {
    $GLOBALS['banc_table'][] = ['id' => $i, 'created_at' => '2024-01-01 00:00:00'];
}
$GLOBALS['banc_table'][] = ['id' => 99999, 'created_at' => '2026-11-01 00:00:00'];
$avant = count($GLOBALS['wpdb']->requetes);
$jour1 = method_exists(LocalRegistry::class, 'purger_passage') ? LocalRegistry::purger_passage() : -1;
$req1 = count($GLOBALS['wpdb']->requetes) - $avant;
verifier('passage quotidien : 24 lots au plus (24000 lignes, 24 requetes)', $jour1 === 24000 && $req1 === 24, "$jour1 lignes, $req1 requetes");
$avant = count($GLOBALS['wpdb']->requetes);
$jour2 = method_exists(LocalRegistry::class, 'purger_passage') ? LocalRegistry::purger_passage() : -1;
$req2 = count($GLOBALS['wpdb']->requetes) - $avant;
verifier('passage suivant : arret au premier lot incomplet (6500 lignes, 7 requetes)', $jour2 === 6500 && $req2 === 7, "$jour2 lignes, $req2 requetes");
verifier('la ligne recente survit aux passages quotidiens', array_column($GLOBALS['banc_table'], 'id') === [99999]);
$avant = count($GLOBALS['wpdb']->requetes);
$jour3 = method_exists(LocalRegistry::class, 'purger_passage') ? LocalRegistry::purger_passage() : -1;
verifier('table propre : une seule requete, 0 ligne', $jour3 === 0 && count($GLOBALS['wpdb']->requetes) - $avant === 1);
$code = (string) file_get_contents($GLOBALS['banc_src'] . '/Privacy/LocalRegistry.php');
verifier('index created_at present dans la table (purge sans balayage)', (bool) preg_match('/KEY\s+created_at\s*\(created_at\)/', $code));
$GLOBALS['banc_table'] = [];
unset($GLOBALS['banc_temps']);

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
