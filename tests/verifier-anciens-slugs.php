<?php
/**
 * Banc CLI des anciens slugs d'offre (le dépôt n'a ni PHPUnit ni Pest).
 *
 *   php tests/verifier-anciens-slugs.php [--src=/autre/dossier/src]
 *
 * Joue le VRAI code de src/Jobs (Module, Fiche::resoudre, Redirections::cible,
 * Catalogue::etat) avec des doublures minimales des fonctions WordPress :
 * options et transients en mémoire, deux pages hôtes (FR 10, EN 20), et
 * wp_safe_redirect qui lève une exception portant l'URL et le code HTTP.
 *
 * sanitize_title est une approximation (minuscules, accents retirés, tout le
 * reste en tirets) : suffisante pour les slugs du banc, pas une copie de WP.
 *
 * --src : rejoue le banc sur une copie modifiée de src/ (bancs de mutation).
 *
 * Dossier tests/ : exclu des deux zips (release.yml, build-org.yml).
 */

namespace {

$options = getopt('', ['src:']);
$src = isset($options['src']) ? rtrim((string) $options['src'], '/\\') : dirname(__DIR__) . '/src';

define('ABSPATH', __DIR__ . '/');
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

// ─── Doublures WordPress ────────────────────────────────────────────────────

final class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'page';
    public string $post_status = 'publish';
}

final class Redirige extends \Exception
{
    public string $url;
    public int $statut;

    public function __construct(string $url, int $statut)
    {
        parent::__construct("$statut $url");
        $this->url = $url;
        $this->statut = $statut;
    }
}

$GLOBALS['banc'] = ['options' => [], 'transients' => [], 'qv' => [], 'page' => 0, 'statut' => 200];

function get_option($nom, $defaut = false) { return array_key_exists($nom, $GLOBALS['banc']['options']) ? $GLOBALS['banc']['options'][$nom] : $defaut; }
function update_option($nom, $valeur, $auto = null) { $GLOBALS['banc']['options'][$nom] = $valeur; return true; }
function get_transient($nom) { return $GLOBALS['banc']['transients'][$nom] ?? false; }
function set_transient($nom, $valeur, $duree = 0) { $GLOBALS['banc']['transients'][$nom] = $valeur; return true; }
function delete_transient($nom) { unset($GLOBALS['banc']['transients'][$nom]); return true; }
function apply_filters($crochet, $valeur, ...$args) { return $valeur; }
function get_post($id) { $p = new WP_Post(); $p->ID = (int) $id; return in_array((int) $id, [10, 20], true) ? $p : null; }
function get_permalink($id) { return [10 => 'https://exemple.test/carriere/', 20 => 'https://exemple.test/en/careers/'][(int) $id] ?? false; }
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function add_query_arg($cle, $valeur, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $cle . '=' . $valeur; }
function determine_locale() { return 'fr_CA'; }
function get_query_var($nom, $defaut = '') { return $GLOBALS['banc']['qv'][$nom] ?? $defaut; }
function is_page() { return $GLOBALS['banc']['page'] > 0; }
function get_queried_object_id() { return $GLOBALS['banc']['page']; }
function esc_url_raw($u, $p = null) { return (string) $u; }
function status_header($code) { $GLOBALS['banc']['statut'] = (int) $code; }
function nocache_headers() {}
function wp_safe_redirect($url, $statut = 302, $par = '') { throw new Redirige((string) $url, (int) $statut); }
function sanitize_title($titre)
{
    $t = strtolower(strtr((string) $titre, ['É' => 'e', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']));
    $t = preg_replace('/[^a-z0-9_\-]+/', '-', $t);
    return trim((string) preg_replace('/-+/', '-', $t), '-');
}

}

namespace Pratcom\Connect\Bridge\Jobs {

// Capte header() du code teste (Retry-After) au lieu de l'envoyer.
function header(string $ligne): void
{
    $GLOBALS['banc']['entetes'][] = $ligne;
}

}

namespace Banc {

use Pratcom\Connect\Bridge\Jobs\Catalogue;
use Pratcom\Connect\Bridge\Jobs\Fiche;
use Pratcom\Connect\Bridge\Jobs\Module;
use Pratcom\Connect\Bridge\Jobs\Redirections;
use Pratcom\Connect\Bridge\Plugin;

$journal = tempnam(sys_get_temp_dir(), 'banc');
ini_set('log_errors', '1');
ini_set('error_log', $journal);

$echecs = [];
$ok = 0;
function verifier(string $nom, bool $condition, string $detail = ''): void
{
    global $echecs, $ok;
    if ($condition) {
        $ok++;
        echo "OK     $nom\n";
    } else {
        $echecs[] = $nom;
        echo "ECHEC  $nom\n" . ($detail !== '' ? "       $detail\n" : '');
    }
}

const FR = 'https://exemple.test/carriere/';
const EN = 'https://exemple.test/en/careers/';

function offre(string $id, string $lang, string $slug, $anciens = null, string $maj = '2026-09-01T00:00:00Z'): array
{
    $o = ['id' => $id, 'lang' => $lang, 'slug' => $slug, 'title' => $id, 'updated_at' => $maj];
    if ($anciens !== null) {
        $o['anciens_slugs'] = $anciens;
    }
    return $o;
}

/** Pose un catalogue (et son état) et oublie le mémo. */
function catalogue(array $offres, bool $sain = true, bool $charge = true): void
{
    $GLOBALS['banc']['options'] = [
        Plugin::OPTION_WORKSPACE_SLUG     => 'espace-banc',
        Module::OPTION_PAGE_PREFIXE . 'fr' => 10,
        Module::OPTION_PAGE_PREFIXE . 'en' => 20,
        'permalink_structure'             => '/%postname%/',
    ];
    if ($charge) {
        $GLOBALS['banc']['options'][Catalogue::OPTION] = ['workspace' => 'espace-banc', 'etag' => 'W/"banc"', 'offres' => $offres];
    }
    if (!$sain) {
        $GLOBALS['banc']['options'][Catalogue::OPTION_ERREUR] = ['code' => 'http_503', 'http' => 503, 'resolue' => false];
    }
    // Fraicheur posee : aucun appel reseau.
    $GLOBALS['banc']['transients'] = [Catalogue::TRANSIENT_FRAIS => 'espace-banc'];
    Catalogue::oublier_memo();
}

/**
 * Joue Fiche::resoudre() pour `{page}/{slug}/`.
 *
 * @return array{issue: string, url: string, statut: int, offre: ?string}
 */
function fiche(int $page, string $slug): array
{
    Fiche::reinitialiser();
    $GLOBALS['banc']['qv'] = [Fiche::QUERY_VAR => $slug];
    $GLOBALS['banc']['page'] = $page;
    $GLOBALS['banc']['statut'] = 200;
    $GLOBALS['banc']['entetes'] = [];
    $f = (new \ReflectionClass(Fiche::class))->newInstanceWithoutConstructor();
    try {
        $f->resoudre();
    } catch (\Redirige $r) {
        return ['issue' => 'redirection', 'url' => $r->url, 'statut' => $r->statut, 'offre' => null];
    }
    $o = Fiche::offre_courante();
    return [
        'issue'  => Fiche::indisponible() ? 'indisponible' : ($o !== null ? 'fiche' : 'rien'),
        'url'    => '',
        'statut' => $GLOBALS['banc']['statut'],
        'offre'  => $o['id'] ?? null,
    ];
}

function montrer($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function redirige_vers(array $r, string $url): bool
{
    return $r['issue'] === 'redirection' && $r['statut'] === 301 && $r['url'] === $url;
}

// Catalogue type : un meme ancien slug dans les deux langues (erreur reelle
// d'un client : la version anglaise portait le slug francais).
$doublon_langues = 'chauffeur-euse-classe-1-ville-ville';
$type = [
    offre('fr-1', 'fr', 'chauffeur-euse-classe-1-ville', [$doublon_langues]),
    offre('en-1', 'en', 'class-1-driver-ville', [$doublon_langues, 'class-1-driver-ville-ville']),
    offre('fr-2', 'fr', 'mecanicien-ne', []),
    offre('fr-3', 'fr', 'repartiteur-trice'),
];

// 1. Ancien slug FR -> 301 vers la fiche FR actuelle.
catalogue($type);
$r = fiche(10, $doublon_langues);
verifier('1 ancien slug FR -> 301 fiche FR', redirige_vers($r, FR . 'chauffeur-euse-classe-1-ville/'), montrer($r));

// 2. Ancien slug EN -> fiche EN.
$r = fiche(20, 'class-1-driver-ville-ville');
verifier('2 ancien slug EN -> 301 fiche EN', redirige_vers($r, EN . 'class-1-driver-ville/'), montrer($r));

// 3. Meme ancien slug dans les deux langues : chacune mene a SA offre.
$fr = fiche(10, $doublon_langues);
$en = fiche(20, $doublon_langues);
verifier(
    '3 meme ancien slug FR et EN -> chaque langue sa fiche',
    redirige_vers($fr, FR . 'chauffeur-euse-classe-1-ville/') && redirige_vers($en, EN . 'class-1-driver-ville/'),
    montrer([$fr, $en])
);
// 3b. Ancien slug present seulement en EN, demande sous la page FR -> liste FR.
$r = fiche(10, 'class-1-driver-ville-ville');
verifier('3b ancien slug d\'une autre langue -> liste, pas l\'offre EN', redirige_vers($r, FR), montrer($r));

// 4. Slug inconnu -> liste (comme avant).
$r = fiche(10, 'offre-fermee-depuis-longtemps');
verifier('4 slug inconnu -> 301 liste', redirige_vers($r, FR), montrer($r));

// 5. Slug actuel -> la fiche, sans redirection (comme avant).
$r = fiche(10, 'mecanicien-ne');
verifier('5 slug actuel -> fiche servie', $r['issue'] === 'fiche' && $r['offre'] === 'fr-2', montrer($r));

// 6. Catalogue sans le champ, ou champ qui n'est pas un tableau -> comme avant.
catalogue([offre('fr-1', 'fr', 'nouveau-slug'), offre('fr-9', 'fr', 'autre', 'vieux-slug'), offre('fr-8', 'fr', 'encore', ['x' => ['vieux-slug']])]);
$a = fiche(10, 'nouveau-slug');
$b = fiche(10, 'vieux-slug');
verifier(
    '6 catalogue sans anciens_slugs (ou champ invalide) -> comme avant',
    $a['issue'] === 'fiche' && redirige_vers($b, FR) && Module::offre_par_ancien_slug('fr', 'vieux-slug') === null,
    montrer([$a, $b])
);

// 7. Catalogue en panne -> 503 (comme avant), meme pour un ancien slug connu.
catalogue($type, false);
$a = fiche(10, 'inconnu');
$b = fiche(10, $doublon_langues);
verifier(
    '7 catalogue en panne -> 503 (inconnu et ancien slug)',
    $a['issue'] === 'indisponible' && $a['statut'] === 503
        && in_array('Retry-After: 300', $GLOBALS['banc']['entetes'], true) && $b['issue'] === 'indisponible' && $b['statut'] === 503,
    montrer([$a, $b])
);
catalogue($type, false, false);
$c = fiche(10, $doublon_langues);
verifier('7b catalogue jamais charge -> 503', $c['issue'] === 'indisponible' && $c['statut'] === 503, montrer($c));

// 8. /jobs/{ancien}/ -> la fiche actuelle en UN saut.
catalogue($type);
$fr = Redirections::cible($doublon_langues, 'fr');
$en = Redirections::cible('class-1-driver-ville-ville', 'en');
$liste = Redirections::cible('inconnu', 'fr');
$actuel = Redirections::cible('mecanicien-ne', 'fr');
verifier(
    '8 /jobs/{ancien}/ -> fiche actuelle en un saut (et le reste comme avant)',
    $fr === FR . 'chauffeur-euse-classe-1-ville/' && $en === EN . 'class-1-driver-ville/'
        && $liste === FR && $actuel === FR . 'mecanicien-ne/'
        && fiche(10, 'chauffeur-euse-classe-1-ville')['issue'] === 'fiche',
    montrer([$fr, $en, $liste, $actuel])
);

// 9. Pas de boucle : ancien slug == slug actuel -> la fiche, jamais une
//    redirection vers elle-meme ; et la recherche l'ignore.
catalogue([offre('fr-1', 'fr', 'meme-slug', ['meme-slug', 'Meme Slug'])]);
$r = fiche(10, 'meme-slug');
verifier(
    '9 ancien == actuel -> fiche servie, aucune boucle',
    $r['issue'] === 'fiche' && Module::offre_par_ancien_slug('fr', 'meme-slug') === null
        && Redirections::cible('meme-slug', 'fr') === FR . 'meme-slug/',
    montrer($r)
);

// 10. Correspondance en double -> la plus recente, avertissement une fois.
catalogue([
    offre('fr-a', 'fr', 'offre-a', ['vieux'], '2026-08-01T00:00:00Z'),
    offre('fr-b', 'fr', 'offre-b', ['Vieux'], '2026-09-20T12:00:00Z'),
    offre('fr-c', 'fr', 'offre-c', ['vieux'], '2026-09-10T00:00:00Z'),
]);
file_put_contents($journal, '');
$r1 = fiche(10, 'vieux');
$r2 = fiche(10, 'vieux');
$lignes = array_values(array_filter(explode("\n", (string) file_get_contents($journal)), static fn($l) => strpos($l, 'ancien slug') !== false));
verifier(
    '10 doublon -> la plus recente, avertissement journalise une fois',
    redirige_vers($r1, FR . 'offre-b/') && redirige_vers($r2, FR . 'offre-b/') && count($lignes) === 1,
    montrer([$r1, $lignes])
);

// 11. Normalisation des deux cotes (majuscule, accent, encodage d'URL).
catalogue([offre('fr-1', 'fr', 'Opérateur-Nouveau', ['Opérateur Ancien'])]);
$r = fiche(10, rawurlencode('operateur-ancien'));
verifier('11 normalise des deux cotes', redirige_vers($r, FR . rawurlencode('Opérateur-Nouveau') . '/'), montrer($r));

@unlink($journal);
echo "\n$ok OK, " . count($echecs) . " ECHEC(S)\n";
exit($echecs === [] ? 0 : 1);

}
