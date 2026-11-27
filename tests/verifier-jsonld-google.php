<?php
/**
 * Banc CLI du JobPosting (`Jobs\JsonLd`) face a la doc Google for Jobs
 * relue le 26/11/2026. Meme patron que `verifier-spontanee.php` :
 * doublures WordPress minimales, aucune dependance.
 *
 *   php tests/verifier-jsonld-google.php
 *
 * Ce que le banc garde :
 *  1. `datePosted` : `posted_at`, sinon `updated_at` ; sans aucune des deux,
 *     AUCUN JobPosting (tableau vide), jamais une propriete requise absente.
 *  2. `TELECOMMUTE` n'est jamais emis sans `applicantLocationRequirements` :
 *     teletravail complet sans pays resolu = ni l'un ni l'autre.
 *  3. Logo de `hiringOrganization` : emis seulement si 112 x 112 px au moins
 *     et rapport largeur/hauteur entre 0,75 et 2,5 ; taille inconnue = omis.
 *  4. `alertes_titre` : lieu ou nom de l'employeur dans le titre signales,
 *     sans casse ni accents, sur des mots entiers ; le titre emis est intact.
 *
 * Dossier tests/ : exclu des deux zips (release.yml, build-org.yml).
 */

namespace {

define('ABSPATH', __DIR__ . '/');

spl_autoload_register(static function (string $classe): void {
    $prefixe = 'Pratcom\\Connect\\Bridge\\';
    if (strpos($classe, $prefixe) !== 0) {
        return;
    }
    $fichier = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($classe, strlen($prefixe))) . '.php';
    if (is_file($fichier)) {
        require $fichier;
    }
});

$GLOBALS['banc_icone'] = 0;
$GLOBALS['banc_image'] = false;

function apply_filters($nom, $valeur) { return $valeur; }
function get_bloginfo($cle) { return 'Entreprise Exemple'; }
function home_url($chemin = '') { return 'https://exemple.test' . $chemin; }
function get_option($cle, $defaut = false) { return $cle === 'site_icon' ? $GLOBALS['banc_icone'] : $defaut; }
function wp_get_attachment_image_src($id, $taille) { return $GLOBALS['banc_image']; }
function wpautop($t) { return '<p>' . $t . '</p>'; }
function wp_kses_post($t) { return $t; }
function esc_url_raw($u) { return $u; }
function remove_accents($t)
{
    return strtr($t, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'E', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u']);
}

$echecs = [];
$ok = 0;
function verifier(string $nom, bool $condition): void
{
    global $echecs, $ok;
    if ($condition) {
        $ok++;
        echo "  PASS  $nom\n";
    } else {
        $echecs[] = $nom;
        echo "  FAIL  $nom\n";
    }
}

use Pratcom\Connect\Bridge\Jobs\JsonLd;

$ctx = ['espace' => 'espace-test', 'url' => 'https://exemple.test/emplois/offre-test/', 'formulaire' => true];
$base = [
    'id'               => 'offre-1',
    'title'            => 'Technicien de maintenance',
    'description'      => 'Une offre de test.',
    'posted_at'        => '2026-11-20T09:00:00-05:00',
    'updated_at'       => '2026-11-21T09:00:00-05:00',
    'location_city'    => 'Ville Exemple',
    'location_region'  => 'QC',
    'location_country' => 'CA',
    'employment_type'  => 'full_time',
];

echo "1. datePosted\n";
$s = JsonLd::construire($base, $ctx);
verifier('posted_at present : datePosted = posted_at', ($s['datePosted'] ?? null) === $base['posted_at']);
$o = $base;
unset($o['posted_at']);
$s = JsonLd::construire($o, $ctx);
verifier('sans posted_at : datePosted = updated_at', ($s['datePosted'] ?? null) === $base['updated_at']);
$o['updated_at'] = '';
verifier('ni posted_at ni updated_at : aucun JobPosting', JsonLd::construire($o, $ctx) === []);
$o['posted_at'] = null;
verifier('posted_at null et updated_at vide : aucun JobPosting', JsonLd::construire($o, $ctx) === []);

echo "2. TELECOMMUTE\n";
$o = $base + [];
$o['is_remote'] = true;
$o['work_mode'] = 'remote';
$s = JsonLd::construire($o, $ctx);
verifier('teletravail complet avec pays : TELECOMMUTE', ($s['jobLocationType'] ?? null) === 'TELECOMMUTE');
verifier('teletravail complet avec pays : exigence de pays CA', ($s['applicantLocationRequirements']['name'] ?? null) === 'CA');
$o['location_country'] = '';
$s = JsonLd::construire($o, $ctx);
verifier('teletravail complet sans pays : pas de TELECOMMUTE', !isset($s['jobLocationType']));
verifier('teletravail complet sans pays : pas d exigence de lieu', !isset($s['applicantLocationRequirements']));
$o['location_country'] = 'Canada';
$s = JsonLd::construire($o, $ctx);
verifier('pays illisible (pas ISO-2) : pas de TELECOMMUTE', !isset($s['jobLocationType']));
$o = $base;
$o['is_remote'] = true;
$o['work_mode'] = 'hybrid';
$s = JsonLd::construire($o, $ctx);
verifier('hybride : jamais TELECOMMUTE', !isset($s['jobLocationType']));
$s = JsonLd::construire($base, $ctx);
verifier('sur place : jamais TELECOMMUTE', !isset($s['jobLocationType']));

echo "3. Logo\n";
$cas = [
    [512, 512, true], [112, 112, true], [111, 112, false], [280, 112, true],
    [281, 112, false], [300, 400, true], [300, 401, false], [335, 75, false],
    [0, 0, false], [600, 200, false],
];
foreach ($cas as [$l, $h, $attendu]) {
    verifier(sprintf('logo_admissible(%d, %d) = %s', $l, $h, $attendu ? 'oui' : 'non'), JsonLd::logo_admissible($l, $h) === $attendu);
}
$GLOBALS['banc_icone'] = 7;
$GLOBALS['banc_image'] = ['https://exemple.test/icone.png', 512, 512];
$s = JsonLd::construire($base, $ctx);
verifier('icone 512 x 512 : logo emis', ($s['hiringOrganization']['logo'] ?? null) === 'https://exemple.test/icone.png');
$GLOBALS['banc_image'] = ['https://exemple.test/bandeau.png', 335, 75];
$s = JsonLd::construire($base, $ctx);
verifier('image 335 x 75 (rapport 4,47) : logo omis', !isset($s['hiringOrganization']['logo']));
$GLOBALS['banc_image'] = ['https://exemple.test/icone.svg', 0, 0];
$s = JsonLd::construire($base, $ctx);
verifier('taille inconnue : logo omis', !isset($s['hiringOrganization']['logo']));
$GLOBALS['banc_image'] = false;
$s = JsonLd::construire($base, $ctx);
verifier('piece jointe introuvable : logo omis, employeur intact', !isset($s['hiringOrganization']['logo']) && $s['hiringOrganization']['name'] === 'Entreprise Exemple');
$GLOBALS['banc_icone'] = 0;

echo "4. Titre\n";
$t = function (string $titre, array $plus = []) use ($base): array {
    return JsonLd::alertes_titre(['title' => $titre] + $plus + $base, 'Entreprise Exemple');
};
verifier('titre propre : aucune alerte', $t('Technicien de maintenance') === []);
verifier('ville dans le titre : lieu', $t('Technicien, Ville Exemple') === ['lieu']);
verifier('ville sans accents ni casse : lieu', $t('Chauffeur RIVIERE EXEMPLE', ['location_city' => 'Rivière Exemple']) === ['lieu']);
verifier('ville dans un mot plus long : rien', $t('Chauffeur Lavallois', ['location_city' => 'Laval']) === []);
verifier('code de province de 2 lettres : rien', $t('Chauffeur QC') === []);
verifier('region en toutes lettres : lieu', $t('Chauffeur Quebec', ['location_region' => 'Québec']) === ['lieu']);
verifier('nom de l employeur : entreprise', $t('Soudeur chez Entreprise Exemple') === ['entreprise']);
verifier('lieu et employeur : les deux', $t('Entreprise Exemple recrute a Ville Exemple') === ['lieu', 'entreprise']);
verifier('titre vide : aucune alerte', $t('') === []);
$o = $base;
$o['title'] = 'Chauffeur, Ville Exemple';
$s = JsonLd::construire($o, $ctx);
verifier('titre emis tel quel malgre l alerte', $s['title'] === 'Chauffeur, Ville Exemple');

echo "\n$ok PASS, " . count($echecs) . " FAIL\n";
exit($echecs === [] ? 0 : 1);

}
