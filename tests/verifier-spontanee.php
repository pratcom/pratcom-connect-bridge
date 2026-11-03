<?php
/**
 * Banc CLI de la section « Candidature spontanee » sous la liste des offres
 * (03/11). Le depot n'a ni PHPUnit ni Pest : meme patron que
 * `verifier-anciens-slugs.php`, doublures WordPress minimales.
 *
 *   php tests/verifier-spontanee.php
 *
 * Ce que le banc garde :
 *  1. `Catalogue::lire_spontanee` : la cle `spontaneous` de la route est lue,
 *     bornee, et une forme illisible donne `null` (rien d'affiche).
 *  2. `Shortcode::section_spontanee` : activee, titre, texte et conteneur du
 *     formulaire `candidature-spontanee`, en FR et en EN selon la page ;
 *     desactivee, chaine vide.
 *  3. Les textes venus du reseau sont ECHAPPES.
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

function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }

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

use Pratcom\Connect\Bridge\Jobs\Catalogue;
use Pratcom\Connect\Bridge\Jobs\Shortcode;

$servie = [
    'form_slug' => 'candidature-spontanee',
    'title'     => ['fr' => 'Candidature spontanée', 'en' => 'Unsolicited application'],
    'text'      => [
        'fr' => 'Transport Grayson est toujours à la recherche de talents.',
        'en' => 'Transport Grayson is always looking for talent.',
    ],
];

// ─── 1. Lecture de la route ────────────────────────────────────────────────
$lue = Catalogue::lire_spontanee($servie);
verifier('lecture : une section servie est gardee telle quelle', $lue === $servie);
verifier('lecture : `null` (desactivee) donne null', Catalogue::lire_spontanee(null) === null);
verifier('lecture : absente du corps (API d\'avant) donne null', Catalogue::lire_spontanee(($corps ?? [])['spontaneous'] ?? null) === null);
verifier('lecture : un slug de formulaire douteux est refuse', Catalogue::lire_spontanee(['form_slug' => '"><x'] + $servie) === null);
verifier('lecture : une langue manquante est refusee', Catalogue::lire_spontanee(['title' => ['fr' => 'T']] + $servie) === null);
$long = Catalogue::lire_spontanee(['text' => ['fr' => str_repeat('a', 2000), 'en' => 'b']] + $servie);
verifier('lecture : un texte trop long est borne a 1000', $long !== null && strlen($long['text']['fr']) === 1000);

// ─── 2. Rendu ──────────────────────────────────────────────────────────────
$fr = Shortcode::section_spontanee($lue, 'fr');
verifier('rendu FR : titre', strpos($fr, '>Candidature spontanée</h2>') !== false);
verifier('rendu FR : texte avec le nom de l\'espace', strpos($fr, 'Transport Grayson est toujours') !== false);
verifier('rendu FR : conteneur du formulaire, langue fr', strpos($fr, '<div data-pratcom-form="candidature-spontanee" data-lang="fr"></div>') !== false);

$en = Shortcode::section_spontanee($lue, 'en');
verifier('rendu EN : titre', strpos($en, '>Unsolicited application</h2>') !== false);
verifier('rendu EN : texte', strpos($en, 'Transport Grayson is always looking for talent.') !== false);
verifier('rendu EN : conteneur du formulaire, langue en', strpos($en, 'data-lang="en"') !== false);
verifier('rendu EN : aucun texte FR', strpos($en, 'Candidature') === false);

verifier('desactivee : rien sous les offres', Shortcode::section_spontanee(null, 'fr') === '' && Shortcode::section_spontanee(null, 'en') === '');

// ─── 3. Echappement ────────────────────────────────────────────────────────
$piege = $servie;
$piege['title']['fr'] = '<script>alert(1)</script>';
$html = Shortcode::section_spontanee($piege, 'fr');
verifier('echappement : aucune balise venue du reseau', strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false);

echo "\n";
if ($echecs !== []) {
    echo count($echecs) . " echec(s)\n";
    exit(1);
}
echo "$ok verifications PASS - OK\n";

}
