<?php
/**
 * Banc CLI de la release reproductible (trouvaille E-16).
 *
 *   php tests/verifier-outillage-release.php [--racine=/autre/copie/du/depot]
 *
 * Echoue (code 1) si :
 *   - composer.lock est absent, illisible, ou ne correspond plus a composer.json
 *     (content-hash recalcule comme le fait Composer) ;
 *   - composer.lock est encore ignore par .gitignore ;
 *   - plugin-update-checker n'est pas epingle a la version exacte du verrou ;
 *   - une action de .github/workflows/*.yml n'est pas epinglee par SHA complet
 *     (40 caracteres hexadecimaux) suivi d'un commentaire de version ;
 *   - release.yml n'echoue pas sur un verrou absent ou divergent, ne calcule
 *     pas l'empreinte SHA-256 du zip, ou ne la publie pas comme asset ;
 *   - release.yml donne l'ecriture du depot a tout le fichier au lieu d'un
 *     seul travail.
 *
 * --racine : rejoue le banc sur une copie modifiee du depot (bancs de mutation).
 *
 * Dossier tests/ : exclu des deux zips (release.yml, build-org.yml).
 */

$options = getopt('', ['racine:']);
$racine = isset($options['racine']) ? rtrim((string) $options['racine'], '/\\') : dirname(__DIR__);

$echecs = 0;
$total = 0;

function verifier(string $libelle, bool $ok, string $detail = ''): void
{
    global $echecs, $total;
    $total++;
    if ($ok) {
        echo "  ok    $libelle\n";
        return;
    }
    $echecs++;
    echo "  ECHEC $libelle" . ($detail !== '' ? " ($detail)" : '') . "\n";
}

function lire(string $chemin): ?string
{
    if (!is_file($chemin)) {
        return null;
    }
    $contenu = file_get_contents($chemin);
    return $contenu === false ? null : str_replace("\r\n", "\n", $contenu);
}

/** Meme calcul que Composer\Package\Locker::getContentHash(). */
function empreinte_composer(array $composer): string
{
    $cles = ['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
        'minimum-stability', 'prefer-stable', 'repositories', 'extra'];
    $pertinent = [];
    foreach (array_intersect($cles, array_keys($composer)) as $cle) {
        $pertinent[$cle] = $composer[$cle];
    }
    if (isset($composer['config']['platform'])) {
        $pertinent['config']['platform'] = $composer['config']['platform'];
    }
    ksort($pertinent);
    return md5((string) json_encode($pertinent, 0));
}

// ─── 1. Verrou Composer ─────────────────────────────────────────────────────

echo "Verrou Composer\n";

$json = lire($racine . '/composer.json');
$verrou = lire($racine . '/composer.lock');
$composer = $json === null ? null : json_decode($json, true);
$lock = $verrou === null ? null : json_decode($verrou, true);

verifier('composer.json lisible', is_array($composer));
verifier('composer.lock present et lisible', is_array($lock));

if (is_array($composer) && is_array($lock)) {
    $attendu = empreinte_composer($composer);
    verifier(
        'composer.lock a jour (content-hash)',
        ($lock['content-hash'] ?? '') === $attendu,
        'verrou ' . ($lock['content-hash'] ?? '?') . ', attendu ' . $attendu
    );

    $contrainte = (string) ($composer['require']['yahnis-elsts/plugin-update-checker'] ?? '');
    $version = null;
    foreach ($lock['packages'] ?? [] as $paquet) {
        if (($paquet['name'] ?? '') === 'yahnis-elsts/plugin-update-checker') {
            $version = ltrim((string) ($paquet['version'] ?? ''), 'v');
            $ref = (string) ($paquet['dist']['reference'] ?? $paquet['source']['reference'] ?? '');
            verifier('plugin-update-checker verrouille sur un commit', (bool) preg_match('/^[0-9a-f]{40}$/', $ref), $ref);
        }
    }
    verifier('plugin-update-checker present dans le verrou', $version !== null);
    verifier(
        'plugin-update-checker epingle a la version exacte du verrou',
        $version !== null && ltrim($contrainte, 'v') === $version,
        "composer.json \"$contrainte\", verrou \"$version\""
    );
}

$ignore = lire($racine . '/.gitignore') ?? '';
verifier(
    'composer.lock non ignore par .gitignore',
    !preg_match('#^\s*/?composer\.lock\s*$#m', $ignore)
);

// ─── 2. Actions epinglees par SHA ───────────────────────────────────────────

echo "Actions epinglees par SHA\n";

$workflows = glob($racine . '/.github/workflows/*.yml') ?: [];
verifier('au moins un workflow trouve', $workflows !== []);

foreach ($workflows as $fichier) {
    $nom = basename($fichier);
    $lignes = explode("\n", lire($fichier) ?? '');
    $vues = 0;
    foreach ($lignes as $i => $ligne) {
        if (!preg_match('/^\s*(?:-\s*)?uses:\s*(\S+)(.*)$/', $ligne, $m)) {
            continue;
        }
        $vues++;
        $action = trim($m[1], '\'"');
        if (str_starts_with($action, './') || str_starts_with($action, 'docker://')) {
            continue;
        }
        $ok = (bool) preg_match('/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40}$/', $action)
            && (bool) preg_match('/^\s*#\s*v?\d+(\.\d+)*/', $m[2]);
        verifier("$nom:" . ($i + 1) . " $action", $ok, 'SHA complet et commentaire de version attendus');
    }
    verifier("$nom contient des actions", $vues > 0);
}

// ─── 3. release.yml ─────────────────────────────────────────────────────────

echo "release.yml\n";

$release = lire($racine . '/.github/workflows/release.yml') ?? '';
verifier('release.yml present', $release !== '');
// Lignes de commentaire retirees : une commande citee en commentaire ne compte pas.
$release = (string) preg_replace('/^\s*#.*$/m', '', $release);

verifier('echec si composer.lock absent', (bool) preg_match('/test\s+-f\s+composer\.lock\s*\|\|/', $release));
verifier('composer validate --strict avant installation', (bool) preg_match('/composer\s+validate\s+--strict/', $release)
    && strpos($release, 'composer validate') < strpos($release, 'composer install'));
verifier('composer install --no-dev --prefer-dist --no-interaction', (bool) preg_match(
    '/composer\s+install(?=[^\n]*--no-dev)(?=[^\n]*--prefer-dist)(?=[^\n]*--no-interaction)/',
    $release
));
verifier('aucun composer update dans la release', !preg_match('/composer\s+update/', $release));
verifier('empreinte calculee (sha256sum > .zip.sha256)', (bool) preg_match(
    '/sha256sum\s+pratcom-connect-bridge\.zip\s*>\s*pratcom-connect-bridge\.zip\.sha256/',
    $release
));
verifier('empreinte publiee comme asset', (bool) preg_match(
    '/files:\s*build\/pratcom-connect-bridge\.zip\.sha256\s*$/m',
    $release
));

// PUC prend le premier asset : le zip doit etre joint avant l'empreinte.
$posZip = preg_match('/files:\s*build\/pratcom-connect-bridge\.zip\s*$/m', $release, $mz, PREG_OFFSET_CAPTURE) ? $mz[0][1] : -1;
$posSha = preg_match('/files:\s*build\/pratcom-connect-bridge\.zip\.sha256\s*$/m', $release, $ms, PREG_OFFSET_CAPTURE) ? $ms[0][1] : -1;
verifier('zip joint seul, avant l\'empreinte (PUC prend le premier asset)', $posZip >= 0 && $posSha > $posZip);

// Permissions : rien au niveau du fichier, ecriture dans un seul travail.
$avantJobs = (string) strstr($release, "\njobs:", true);
verifier('aucune permission d\'ecriture au niveau du fichier', !preg_match('/contents:\s*write/', $avantJobs)
    && (bool) preg_match('/^permissions:\s*\{\s*\}\s*$/m', $avantJobs));
verifier('ecriture du depot accordee a un seul travail', preg_match_all('/contents:\s*write/', $release) === 1);
verifier('le travail qui lance Composer n\'a que la lecture', (bool) preg_match(
    '/\n  build:\n(?:(?!\n  \S).)*?contents:\s*read(?:(?!\n  \S).)*?composer install/s',
    $release
));

echo "\n" . ($total - $echecs) . "/$total controles verts\n";
exit($echecs === 0 ? 0 : 1);
