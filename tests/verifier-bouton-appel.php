<?php
/**
 * Banc CLI du bouton d'appel de la fiche d'une offre (le depot n'a ni PHPUnit
 * ni Pest).
 *
 *   php tests/verifier-bouton-appel.php
 *
 * Joue le VRAI `Fiche::bouton_appel` avec des doublures minimales des
 * fonctions d'echappement de WordPress, puis verifie par lecture des sources
 * que le catalogue garde `call_button` et que la fiche rend le bouton AVANT
 * la description et le formulaire.
 *
 * Dossier tests/ : exclu des deux zips (release.yml, build-org.yml).
 */

namespace {

define('ABSPATH', __DIR__ . '/');

function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u, $protocoles = null)
{
    $u = (string) $u;
    $schema = strtolower((string) strtok($u, ':'));
    $permis = is_array($protocoles) ? $protocoles : ['http', 'https'];
    return in_array($schema, $permis, true) ? htmlspecialchars($u, ENT_QUOTES, 'UTF-8') : '';
}

require dirname(__DIR__) . '/src/Jobs/Fiche.php';

use Pratcom\Connect\Bridge\Jobs\Fiche;

$echecs = 0;
$verts = 0;
function verifier(bool $cond, string $msg): void
{
    global $echecs, $verts;
    if ($cond) {
        $verts++;
        echo "  PASS  $msg\n";
    } else {
        $echecs++;
        echo "  FAIL  $msg\n";
    }
}

$bon = ['phone' => '450-555-0123', 'href' => 'tel:+14505550123', 'label' => ['fr' => 'Appelez-nous', 'en' => 'Call us']];

// 1. Rien servi = rien rendu.
verifier(Fiche::bouton_appel(null, 'fr') === '', 'call_button absent (null) : aucun bouton');
verifier(Fiche::bouton_appel('x', 'fr') === '', 'call_button illisible : aucun bouton');
verifier(Fiche::bouton_appel([], 'fr') === '', 'call_button vide : aucun bouton');

// 2. Rendu FR et EN.
$fr = Fiche::bouton_appel($bon, 'fr');
verifier(strpos($fr, 'href="tel:+14505550123"') !== false, 'FR : lien tel:+1');
verifier(strpos($fr, '>Appelez-nous<') !== false && strpos($fr, '>450-555-0123<') !== false, 'FR : libelle et numero affiches');
verifier(strpos($fr, 'wp-element-button') !== false && strpos($fr, 'pce-bouton--appel') !== false, 'FR : couleurs du theme (wp-element-button)');
$en = Fiche::bouton_appel($bon, 'en');
verifier(strpos($en, '>Call us<') !== false, 'EN : libelle anglais');
$sans_libelle = Fiche::bouton_appel(['phone' => '450-555-0123', 'href' => 'tel:+14505550123'], 'en');
verifier(strpos($sans_libelle, '>Call us<') !== false, 'EN : libelle absent = defaut Call us');
$perso = Fiche::bouton_appel(['label' => ['fr' => 'Parlez au recruteur', 'en' => '']] + $bon, 'fr');
verifier(strpos($perso, '>Parlez au recruteur<') !== false, 'FR : libelle saisi dans le CRM');

// 3. Valeurs hostiles venues du reseau : rien ne sort, ou echappe.
verifier(Fiche::bouton_appel(['href' => 'javascript:alert(1)'] + $bon, 'fr') === '', 'href javascript: refuse');
verifier(Fiche::bouton_appel(['href' => 'tel:+14505550123" onclick="x'] + $bon, 'fr') === '', 'href avec guillemet refuse');
verifier(Fiche::bouton_appel(['href' => 'tel:+33123456789'] + $bon, 'fr') === '', 'href hors +1 refuse');
verifier(Fiche::bouton_appel(['phone' => '<b>450</b>'] + $bon, 'fr') === '', 'numero au mauvais format refuse');
$hostile = Fiche::bouton_appel(['label' => ['fr' => '<script>x</script>']] + $bon, 'fr');
verifier(strpos($hostile, '<script>') === false && strpos($hostile, '&lt;script&gt;') !== false, 'libelle a chevrons echappe');
$long = Fiche::bouton_appel(['label' => ['fr' => str_repeat('x', 41)]] + $bon, 'fr');
verifier(strpos($long, '>Appelez-nous<') !== false, 'libelle trop long = defaut');

// 4. Sources : le catalogue garde call_button, la fiche le rend en haut.
$catalogue = (string) file_get_contents(dirname(__DIR__) . '/src/Jobs/Catalogue.php');
verifier(strpos($catalogue, "\$corps['call_button']") !== false, 'Catalogue lit call_button de la route publique');
verifier(substr_count($catalogue, "'bouton_appel' =>") === 2, 'Catalogue garde et sert bouton_appel');
$fiche = (string) file_get_contents(dirname(__DIR__) . '/src/Jobs/Fiche.php');
$rendre = substr($fiche, (int) strpos($fiche, 'public static function rendre('));
$p_bouton = strpos($rendre, 'self::bouton_appel(');
$p_desc = strpos($rendre, 'pce-fiche__description');
$p_form = strpos($rendre, 'self::formulaire_candidature(');
verifier($p_bouton !== false && $p_desc !== false && $p_form !== false && $p_bouton < $p_desc && $p_bouton < $p_form, 'Fiche : bouton rendu AVANT la description et le formulaire');
$css = (string) file_get_contents(dirname(__DIR__) . '/src/Jobs/Shortcode.php');
verifier((bool) preg_match('/\.pce-bouton--appel\{[^}]*min-height:(4[4-9]|[5-9][0-9])px/', $css), 'CSS : cible tactile de 44 px au moins');

echo "\nBouton d'appel : $verts verts, $echecs rouges.\n";
exit($echecs === 0 ? 0 : 1);

}
