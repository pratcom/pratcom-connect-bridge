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
 *     seul travail ;
 *   - Updater.php appelle enableReleaseAssets sans un motif qui ne prend que
 *     pratcom-connect-bridge.zip, ou les deux noms de zip se recoupent (E-28) ;
 *   - le zip premium garde CLAUDE.md, docs/, composer.json ou un .md, ou
 *     release.yml n'a pas les exclusions de build-org.yml (M-41).
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


// ─── 4. build-org.yml (E-22, 12/11) ─────────────────────────────────────────

echo "build-org.yml\n";

$org = lire($racine . '/.github/workflows/build-org.yml') ?? '';
verifier('build-org.yml present', $org !== '');
$org = (string) preg_replace('/^\s*#.*$/m', '', $org);
$avantJobsOrg = (string) strstr($org, "\njobs:", true);
verifier('build-org.yml : permissions: {} au niveau du fichier', (bool) preg_match('/^permissions:\s*\{\s*\}\s*$/m', $avantJobsOrg)
    && !preg_match('/contents:\s*write/', $avantJobsOrg));
verifier('build-org.yml : ecriture accordee a un seul travail', preg_match_all('/contents:\s*write/', $org) === 1);
verifier('build-org.yml : le travail qui lance wp-cli n\'a que la lecture', (bool) preg_match(
    '/\n  build-org:\n(?:(?!\n  \S).)*?contents:\s*read(?:(?!\n  \S).)*?php wp-cli\.phar/s',
    $org
));
// Le travail en ecriture : ni extraction, ni action tierce, ni telechargement.
$travailEcriture = preg_match('/\n  ([\w-]+):\n((?:(?!\n  \S).)*?contents:\s*write(?:(?!\n  \S).)*)/s', $org, $mw) ? $mw[2] : '';
verifier('build-org.yml : le travail en ecriture ne lance ni action, ni checkout, ni curl, ni php', $travailEcriture !== ''
    && !preg_match('/uses:|curl|wget|php\s/', $travailEcriture));
verifier('build-org.yml : wp-cli a version figee (pas de gh-pages)', !preg_match('/gh-pages/', $org)
    && (bool) preg_match('/WP_CLI_VERSION=\d+\.\d+\.\d+\s*$/m', $org));
$posSha = preg_match('/sha256sum\s+-c/', $org, $m1, PREG_OFFSET_CAPTURE) ? $m1[0][1] : -1;
$posExec = strpos($org, 'php wp-cli.phar');
verifier('build-org.yml : empreinte sha256 de wp-cli.phar (64 hex) verifiee avant toute execution', (bool) preg_match('/WP_CLI_SHA256=[0-9a-f]{64}\s*$/m', $org)
    && $posSha >= 0 && $posExec !== false && $posSha < $posExec);

// ─── 5. Les 3 workflows : permissions et jeton hors de .git/config ──────────

echo "Permissions et extraction des 3 workflows\n";

foreach (['ci.yml', 'release.yml', 'build-org.yml'] as $nom) {
    $texte = (string) preg_replace('/^\s*#.*$/m', '', lire($racine . '/.github/workflows/' . $nom) ?? '');
    $avant = (string) strstr($texte, "\njobs:", true);
    verifier("$nom : permissions declarees au fichier, sans ecriture", (bool) preg_match('/^permissions:/m', $avant)
        && !preg_match('/:\s*write/', $avant));
    $extractions = preg_match_all('/uses:\s*actions\/checkout@/', $texte);
    $sansJeton = preg_match_all('/uses:\s*actions\/checkout@\S+[^\n]*\n\s+with:\n(?:\s+[\w-]+:[^\n]*\n)*?\s+persist-credentials:\s*false/', $texte);
    verifier("$nom : chaque actions/checkout porte persist-credentials: false", $extractions === $sansJeton, "$sansJeton/$extractions");
}

// Auto-verification : l'ancien build-org.yml doit tomber.
$ancien = "permissions:\n  contents: write\n\njobs:\n  build-org:\n    steps:\n      - uses: actions/checkout@x\n      - run: |\n          curl -sSfL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar\n          php wp-cli.phar i18n make-mo languages languages\n";
$avantAncien = (string) strstr($ancien, "\njobs:", true);
verifier('auto-verification : l\'ancien build-org.yml est refuse', !preg_match('/^permissions:\s*\{\s*\}\s*$/m', $avantAncien)
    && (bool) preg_match('/gh-pages/', $ancien) && !preg_match('/sha256sum\s+-c/', $ancien));

// ─── 6. Asset premium designe (E-28, 19/11) ────────────────────────────────

echo "Asset premium designe (E-28)\n";

$updater = (string) preg_replace('#^\s*//.*$#m', '', lire($racine . '/src/Updater.php') ?? '');
$motif = preg_match("/MOTIF_ASSET\s*=\s*'([^']+)'/", $updater, $mm) ? $mm[1] : null;
verifier('Updater.php declare un motif d\'asset', $motif !== null);
verifier(
    'enableReleaseAssets appele avec ce motif et une preference',
    (bool) preg_match('/enableReleaseAssets\(\s*self::MOTIF_ASSET\s*,\s*\$\w+\s*\)/', $updater)
);
verifier('aucun appel a enableReleaseAssets sans motif', !preg_match('/enableReleaseAssets\(\s*\)/', $updater));
verifier('preference REQUIRE_RELEASE_ASSETS (pas de repli sur l\'archive source)', strpos($updater, 'REQUIRE_RELEASE_ASSETS') !== false);

$releaseBrut = lire($racine . '/.github/workflows/release.yml') ?? '';
$orgBrut = lire($racine . '/.github/workflows/build-org.yml') ?? '';
$zipPremium = preg_match('/zip\s+-r\s+(\S+\.zip)\s/', $releaseBrut, $z1) ? $z1[1] : '';
$zipOrg = preg_match('/gh\s+release\s+upload\s+\S+\s+build\/(\S+\.zip)\s/', $orgBrut, $z2) ? $z2[1] : '';
verifier('nom du zip premium lu dans release.yml', $zipPremium === 'pratcom-connect-bridge.zip', $zipPremium);
verifier('nom du zip .org lu dans build-org.yml', $zipOrg !== '', $zipOrg);
verifier('les deux noms de zip different', $zipPremium !== '' && $zipOrg !== '' && $zipPremium !== $zipOrg);
$m = static fn(string $nom): bool => $motif !== null && @preg_match($motif, $nom) === 1;
verifier('le motif accepte le zip premium', $m($zipPremium));
verifier('le motif refuse le zip .org', $zipOrg !== '' && !$m($zipOrg));
foreach (['pratcom-connect-bridge.zip.sha256', 'pratcom-connect-org.zip', 'pratcom-connect.zip', 'pratcom-connect-bridge-2.3.0.zip', 'x-pratcom-connect-bridge.zip'] as $autre) {
    verifier("le motif refuse $autre", !$m($autre));
}
verifier('le motif .org ne prend pas le zip premium (noms disjoints)', $zipOrg === '' || strpos($zipPremium, pathinfo($zipOrg, PATHINFO_FILENAME)) === false);

// ─── 7. Contenu du zip premium (M-41, 19/11) ────────────────────────────────

echo "Contenu du zip premium (M-41)\n";

/** @return array<int, string> motifs --exclude du premier rsync du fichier */
function exclusions_rsync(string $yml): array
{
    $sans = (string) preg_replace('/^\s*#.*$/m', '', $yml);
    $debut = strpos($sans, 'rsync -a');
    $fin = $debut === false ? false : strpos($sans, './ build/', $debut);
    if ($debut === false || $fin === false) {
        return [];
    }
    preg_match_all("/--exclude='([^']+)'/", substr($sans, $debut, $fin - $debut), $e);
    return $e[1];
}

/** Regle d'rsync pour un motif sans barre : il vise tout composant du chemin. */
function exclu(string $chemin, array $motifs): bool
{
    foreach (explode('/', $chemin) as $composant) {
        foreach ($motifs as $motif) {
            if (fnmatch($motif, $composant)) {
                return true;
            }
        }
    }
    return false;
}

$exclPremium = exclusions_rsync($releaseBrut);
$exclOrg = exclusions_rsync($orgBrut);
verifier('exclusions lues dans les deux workflows', $exclPremium !== [] && $exclOrg !== [], count($exclPremium) . ' / ' . count($exclOrg));
$manquantes = array_values(array_diff($exclOrg, $exclPremium, ['vendor']));
verifier('release.yml reprend toutes les exclusions de build-org.yml sauf vendor', $manquantes === [], implode(', ', $manquantes));
verifier('release.yml garde vendor/ (Plugin Update Checker)', !in_array('vendor', $exclPremium, true));

foreach (['CLAUDE.md', 'README.md', 'docs/consent-mode-v2-bridge.md', 'composer.json', 'composer.lock', 'tests/verifier-avant-tag.php', '.distignore', '.github/workflows/ci.yml'] as $absent) {
    verifier("zip premium sans $absent", exclu($absent, $exclPremium));
}
foreach (['pratcom-connect-bridge.php', 'readme.txt', 'src/Updater.php', 'vendor/autoload.php', 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php', 'languages/pratcom-connect-fr_FR.po', 'assets/js/appearance-color-sync.js'] as $present) {
    verifier("zip premium avec $present", !exclu($present, $exclPremium));
}
// Les fichiers reels du depot : aucun .md ni docs/ ne passe.
$fuites = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr((string) $f, strlen($racine) + 1));
    if (strpos($rel, '.git/') === 0 || strpos($rel, 'vendor/') === 0) {
        continue;
    }
    if (!exclu($rel, $exclPremium) && (preg_match('/\.md$/', $rel) || strpos($rel, 'docs/') === 0 || $rel === 'composer.json')) {
        $fuites[] = $rel;
    }
}
verifier('aucun fichier .md, docs/ ou composer.json du depot dans le zip premium', $fuites === [], implode(', ', array_slice($fuites, 0, 5)));

echo "\n" . ($total - $echecs) . "/$total controles verts\n";
exit($echecs === 0 ? 0 : 1);
