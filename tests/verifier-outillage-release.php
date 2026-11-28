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
 *     pas l'empreinte SHA-256 du zip, ou ne l'ecrit pas dans les notes ;
 *   - release.yml donne l'ecriture du depot a tout le fichier au lieu d'un
 *     seul travail ;
 *   - Updater.php appelle enableReleaseAssets sans un motif qui ne prend que
 *     pratcom-connect-bridge.zip, ou les deux noms de zip se recoupent (E-28) ;
 *   - le zip premium garde CLAUDE.md, docs/, composer.json ou un .md, ou
 *     release.yml n'a pas les exclusions de build-org.yml (M-41) ;
 *   - un workflow, QUEL QUE SOIT son declencheur (push de tag, workflow_run,
 *     release, autre), joint le zip .org a une release, ou la release publique
 *     porte un autre asset que pratcom-connect-bridge.zip, joint une seule
 *     fois par release.yml (E-34, R3 du 27/11 : Updater.php de v2.2.2, sans
 *     motif, prend le premier asset quel qu'il soit) ;
 *   - une release est creee ou modifiee hors brouillon, un autre workflow que
 *     release.yml publie, ou release.yml ne suit pas le chemin : tag pousse,
 *     brouillon, zip joint, asset telecharge et empreinte reverifiee, puis
 *     publication ; ou le garde des releases publiees a la main manque
 *     (R1 du 27/11 : aucune release publique n'est jamais vue sans son zip).
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

$vuesTotal = 0;
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
    $vuesTotal += $vues;
}
// R3 : un workflow sans action (garde-release.yml, que du gh) est permis ;
// le controle porte sur l'ensemble, pour que la lecture des `uses:` reste prouvee.
verifier('des actions lues dans les workflows', $vuesTotal > 0, (string) $vuesTotal);

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
verifier('empreinte reverifiee avant publication (sha256sum -c)', (bool) preg_match(
    '/\n  publier:\n(?:(?!\n  \S).)*?sha256sum\s+-c\s+pratcom-connect-bridge\.zip\.sha256/s',
    $release
));
// E-34 : l'empreinte va dans les notes de la release, plus comme asset (R1 :
// notes ecrites dans build/notes.md, donnees a la creation et a la publication).
verifier('empreinte ecrite dans les notes (pas un asset)', strpos($release, 'printf \'\nSHA-256 : `%s`\n\' "$ligne"; } > build/notes.md') !== false
    && (bool) preg_match('/gh release create "\$TAG"[^\n]*--notes-file build\/notes\.md/', $release)
    && (bool) preg_match('/gh release edit "\$TAG" --draft=false[^\n]*--notes-file build\/notes\.md/', $release)
    && strpos($release, 'pratcom-connect-bridge.zip.sha256 --clobber') === false);
verifier('empreinte controlee par motif avant d\'entrer dans les notes', (bool) preg_match('/grep -Eq \'\^\[0-9a-f\]\{64\}  pratcom-connect-bridge\\\.zip\$\'/', $release));
verifier('zip premium joint par release.yml', (bool) preg_match('/gh release upload "\$TAG" build\/pratcom-connect-bridge\.zip --clobber\s*$/m', $release));

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
// E-34 (27/11) : le zip .org n'est plus joint a la release, aucun travail
// n'ecrit (le travail joindre-release, seul en ecriture le 12/11, est retire).
verifier('build-org.yml : aucun travail en ecriture (E-34)', !preg_match('/:\s*write/', $org));
verifier('build-org.yml : le travail qui lance wp-cli n\'a que la lecture', (bool) preg_match(
    '/\n  build-org:\n(?:(?!\n  \S).)*?contents:\s*read(?:(?!\n  \S).)*?php wp-cli\.phar/s',
    $org
));
verifier('build-org.yml : le zip .org part en artefact du workflow', (bool) preg_match(
    '/uses:\s*actions\/upload-artifact@\S+[^\n]*\n\s+with:\n(?:\s+[\w-]+:[^\n]*\n)*?\s+path:\s*build\/pratcom-connect-org\.zip/',
    $org
));
verifier('build-org.yml : wp-cli a version figee (pas de gh-pages)', !preg_match('/gh-pages/', $org)
    && (bool) preg_match('/WP_CLI_VERSION=\d+\.\d+\.\d+\s*$/m', $org));
$posSha = preg_match('/sha256sum\s+-c/', $org, $m1, PREG_OFFSET_CAPTURE) ? $m1[0][1] : -1;
$posExec = strpos($org, 'php wp-cli.phar');
verifier('build-org.yml : empreinte sha256 de wp-cli.phar (64 hex) verifiee avant toute execution', (bool) preg_match('/WP_CLI_SHA256=[0-9a-f]{64}\s*$/m', $org)
    && $posSha >= 0 && $posExec !== false && $posSha < $posExec);

// ─── 5. Les 3 workflows : permissions et jeton hors de .git/config ──────────

echo "Permissions et extraction de tous les workflows\n";

$nomsWorkflows = array_map('basename', $workflows);
foreach (['ci.yml', 'release.yml', 'build-org.yml', 'garde-release.yml'] as $attendu) {
    verifier("$attendu present", in_array($attendu, $nomsWorkflows, true));
}
foreach ($nomsWorkflows as $nom) {
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
$zipOrg = preg_match('/zip\s+-r\w*\s+(\S+\.zip)\s/', $orgBrut, $z2) ? $z2[1] : '';
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

// ─── 8. Release publique : un seul asset, jamais sans lui (E-34, R1, R3) ────
//
// La release qui suit 2.2.2 est choisie par le Updater.php de 2.2.2 deja
// installe, qui appelle enableReleaseAssets() sans motif : PUC y prend le
// premier asset, quel qu'il soit, et zipball_url (la source sans vendor/)
// s'il n'y en a aucun. R3 (27/11) : la regle vaut pour TOUS les workflows,
// quel que soit le declencheur (push de tag, workflow_run, release, autre).
// R1 (27/11) : aucune release n'est creee publique ; seul release.yml publie.

echo "Release publique : un seul asset, jamais sans lui (E-34, R1, R3)\n";

/** Texte d'un workflow sans commentaires, lignes continuees par \ recollees. */
function sans_commentaires(string $yml): string
{
    $sans = (string) preg_replace('/^\s*#.*$/m', '', str_replace("\r\n", "\n", $yml));
    return (string) preg_replace('/\\\\\n\s*/', ' ', $sans);
}

/** Le workflow est-il declenche par une release ? (bloc `on:` sans commentaires) */
function declenche_par_release(string $yml): bool
{
    $sans = sans_commentaires($yml);
    if (!preg_match('/^(?:on|"on"|\'on\'):(.*?)(?=^\S|\z)/ms', $sans, $m)) {
        return false;
    }
    return (bool) preg_match('/(?<![\w-])release(?![\w-])/', $m[1]);
}

/** Jetons d'une ligne de shell : guillemets respectes, guillemets retires. */
function jetons_shell(string $ligne): array
{
    preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^\s]+/', $ligne, $m);
    return array_map(static fn(string $j): string => (strlen($j) > 1 && ($j[0] === '"' || $j[0] === '\'')) ? substr($j, 1, -1) : $j, $m[0]);
}

/** Appels `gh release <verbe>` : liste de [verbe, jetons apres le verbe]. */
function appels_gh_release(string $yml): array
{
    $appels = [];
    if (preg_match_all('/\bgh\s+release\s+(upload|create|edit)\b([^\n]*)/', sans_commentaires($yml), $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $jetons = [];
            foreach (jetons_shell($m[2]) as $j) {
                if ($j !== '' && (in_array($j, ['|', '||', '&&', ';', '&'], true) || $j[0] === ';' || $j[0] === ')')) {
                    break; // fin de la commande
                }
                $jetons[] = $j;
            }
            $appels[] = [$m[1], $jetons];
        }
    }
    return $appels;
}

/** Pas d'etape : chaque ligne qui commence par `- ` ouvre une etape. */
function etapes(string $yml): array
{
    return preg_split('/\n(?=[ \t]*-[ \t])/', sans_commentaires($yml)) ?: [];
}

const ACTIONS_RELEASE = ['softprops/action-gh-release', 'ncipollo/release-action', 'svenstaro/upload-release-action',
    'actions/upload-release-asset', 'actions/create-release', 'shogo82148/actions-upload-release-asset',
    'abutler/upload-release-assets', 'xresloader/upload-to-github-release'];

/** Action utilisee par une etape (minuscules, sans @ref), ou null. */
function action_etape(string $etape): ?string
{
    if (!preg_match('/^[ \t]*(?:-[ \t]+)?uses:[ \t]*(\S+)/m', $etape, $u)) {
        return null;
    }
    return strtolower((string) preg_replace('/@.*$/', '', trim($u[1], '"\'')));
}

/**
 * Fichiers joints a une release par un workflow : `gh release upload|create`,
 * actions de publication (files, artifacts, file, asset_path), envoi brut vers
 * uploads.github.com, action inconnue dont le nom parle de release.
 *
 * @return array<int, string> noms de fichier (basename), ou marqueur entre < >
 */
function assets_joints(string $yml): array
{
    $assets = [];
    foreach (appels_gh_release($yml) as [$verbe, $jetons]) {
        if ($verbe === 'edit') {
            continue;
        }
        array_shift($jetons); // le tag, premier argument de upload comme de create
        $sauter = false;
        foreach ($jetons as $j) {
            if ($sauter) {
                $sauter = false;
                continue;
            }
            if (preg_match('/^--?(repo|R|notes|n|notes-file|F|title|t|target|discussion-category|notes-start-tag)$/', $j)) {
                $sauter = true; // option suivie d'une valeur
                continue;
            }
            if ($j === '' || $j[0] === '-') {
                continue;
            }
            $j = trim((string) preg_replace('/#.*$/', '', $j));
            if ($j !== '') {
                $assets[] = basename($j);
            }
        }
    }

    foreach (etapes($yml) as $etape) {
        $action = action_etape($etape);
        if ($action === null) {
            continue;
        }
        if (!in_array($action, ACTIONS_RELEASE, true)) {
            if (strpos($action, 'release') !== false) {
                $assets[] = '<action de release inconnue : ' . $action . '>';
            }
            continue;
        }
        if (preg_match_all('/^([ \t]+)(files|artifacts|file|asset_path):[ \t]*([^\n]*)\n((?:\1[ \t]+[^\n]*\n?)*)/m', $etape . "\n", $cles, PREG_SET_ORDER)) {
            foreach ($cles as $c) {
                $tete = trim($c[3]);
                $valeur = ($tete === '' || $tete[0] === '|' || $tete[0] === '>') ? $c[4] : $c[3];
                foreach (preg_split('/[\n,]+/', $valeur) ?: [] as $v) {
                    $v = trim($v, " \t\"'");
                    if ($v !== '') {
                        $assets[] = basename($v);
                    }
                }
            }
        }
    }

    if (strpos(sans_commentaires($yml), 'uploads.github.com') !== false) {
        $assets[] = '<envoi brut vers uploads.github.com>';
    }
    return $assets;
}

/**
 * R1 : creations ou publications de release qui rendent une release publique
 * hors du chemin verifie. Rend une liste de descriptions (vide = rien).
 *   - `gh release create` sans --draft ;
 *   - action de release connue sans `draft: true` (elle cree ou modifie une
 *     release publique) ;
 *   - appel brut a l'API des releases (`gh api`, curl).
 */
function creations_publiques(string $yml): array
{
    $trouvees = [];
    foreach (appels_gh_release($yml) as [$verbe, $jetons]) {
        if ($verbe === 'create' && !in_array('--draft', $jetons, true) && !in_array('--draft=true', $jetons, true)) {
            $trouvees[] = 'gh release create sans --draft';
        }
    }
    foreach (etapes($yml) as $etape) {
        $action = action_etape($etape);
        if ($action !== null && in_array($action, ACTIONS_RELEASE, true) && !preg_match('/^\s+draft:\s*[\'"]?true[\'"]?\s*$/m', $etape)) {
            $trouvees[] = $action . ' sans draft: true';
        }
    }
    if (preg_match('/\bgh\s+api\b[^\n]*\breleases\b|api\.github\.com[^\n]*\/releases\b/', sans_commentaires($yml))) {
        $trouvees[] = 'appel brut a l\'API des releases';
    }
    return $trouvees;
}

/** Nombre de `gh release edit ... --draft=false` (publication d'un brouillon). */
function publications(string $yml): int
{
    $n = 0;
    foreach (appels_gh_release($yml) as [$verbe, $jetons]) {
        if ($verbe === 'edit' && (in_array('--draft=false', $jetons, true) || preg_match('/(^| )--draft false( |$)/', implode(' ', $jetons)))) {
            $n++;
        }
    }
    return $n;
}

$joints = [];
$publiees = [];
$gardes = [];
foreach ($workflows as $fichier) {
    $nom = basename($fichier);
    $texte = lire($fichier) ?? '';
    $a = assets_joints($texte);
    $org = array_values(array_filter($a, static fn(string $x): bool => stripos($x, 'pratcom-connect-org') !== false));
    verifier("$nom : aucun zip .org joint a une release (tout declencheur)", $org === [], implode(', ', $org));
    foreach ($a as $x) {
        $joints[] = "$nom:$x";
    }
    $c = creations_publiques($texte);
    verifier("$nom : aucune release creee ou modifiee hors brouillon (R1)", $c === [], implode(', ', $c));
    $p = publications($texte);
    if ($p > 0) {
        $publiees[] = "$nom:$p";
    }
    $sans = sans_commentaires($texte);
    if ($nom !== 'release.yml' && declenche_par_release($texte)
        && preg_match('/gh\s+release\s+edit\s+"\$TAG"\s+--draft=true/', $sans)
        && preg_match('/\[\s*"\$assets"\s*!=\s*"pratcom-connect-bridge\.zip"\s*\]/', $sans)
        && preg_match('/^on:\s*\n\s+release:\s*\n\s+types:\s*\[\s*published\s*\]/m', $sans)) {
        $gardes[] = $nom;
    }
}
$noms = array_map(static fn(string $x): string => substr($x, strpos($x, ':') + 1), $joints);
verifier(
    'release publique : un seul asset, pratcom-connect-bridge.zip, joint une fois par release.yml (tous workflows)',
    $joints === ['release.yml:pratcom-connect-bridge.zip'],
    $joints === [] ? 'aucun asset' : implode(', ', $joints)
);
verifier('un seul workflow publie une release, release.yml, une fois (R1)', $publiees === ['release.yml:1'], implode(', ', $publiees));
verifier('garde : un workflow sur release publiee remet en brouillon une release sans son seul zip (R1)', $gardes !== [], 'garde-release.yml attendu');

// R1 : le chemin de release.yml, dans l'ordre, dans le travail publier.
$rel = sans_commentaires(lire($racine . '/.github/workflows/release.yml') ?? '');
verifier('release.yml : declenche par le push d\'un tag v*, jamais par une release (R1)', !declenche_par_release($rel)
    && (bool) preg_match('/^on:\s*\n\s+push:\s*\n\s+tags:\s*\[\s*\'v\*\'\s*\]/m', $rel));
verifier('build-org.yml : plus declenche par une release (GITHUB_TOKEN ne declenche rien)', !declenche_par_release($orgBrut));
verifier('release.yml : refuse de tourner hors d\'un tag', (bool) preg_match('/\[\s*"\$GITHUB_REF_TYPE"\s*=\s*"tag"\s*\]\s*\|\|/', $rel));
$publier = preg_match('/\n  publier:\n(.*?)(?=\n  \S|\z)/s', $rel, $pm) ? $pm[1] : '';
$pos = static function (string $motif) use ($publier): int {
    return preg_match($motif, $publier, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : -1;
};
$pPublique = $pos('/brouillon=\$\(gh release view "\$TAG" --json isDraft[^\n]*\n\s+if \[ "\$brouillon" != "true" \]; then\n[^\n]*\n\s+exit 1/');
$pCreer = $pos('/gh release create "\$TAG" --draft\b/');
$pJoindre = $pos('/gh release upload "\$TAG" build\/pratcom-connect-bridge\.zip\b/');
$pEtat = $pos('/\[ "\$etat" = "true,pratcom-connect-bridge\.zip" \] \|\|/');
$pTelech = $pos('/gh release download "\$TAG" --pattern pratcom-connect-bridge\.zip --dir verif\b/');
$pSha = $pos('/cd verif\n\s+sha256sum -c \.\.\/build\/pratcom-connect-bridge\.zip\.sha256/');
$pPublie = $pos('/gh release edit "\$TAG" --draft=false\b/');
$pApres = $pos('/\[ "\$etat" = "false,pratcom-connect-bridge\.zip" \] \|\|/');
$ordre = "publique $pPublique, creer $pCreer, joindre $pJoindre, etat $pEtat, telecharger $pTelech, sha $pSha, publier $pPublie, apres $pApres";
verifier('publier : une release deja publique arrete tout', $pPublique >= 0 && $pPublique < $pCreer, $ordre);
verifier('publier : brouillon cree, puis zip joint', $pCreer >= 0 && $pCreer < $pJoindre, $ordre);
verifier('publier : brouillon verifie (un seul asset) avant publication', $pJoindre < $pEtat && $pEtat < $pPublie, $ordre);
verifier('publier : empreinte de l\'asset TELECHARGE reverifiee avant publication', $pEtat < $pTelech && $pTelech < $pSha && $pSha < $pPublie, $ordre);
verifier('publier : etat relu apres publication (public, un seul asset)', $pPublie >= 0 && $pPublie < $pApres, $ordre);
verifier('publier : chaque etape gh en set -e (une erreur arrete la publication)', substr_count($publier, 'set -euo pipefail') >= 4, (string) substr_count($publier, 'set -euo pipefail'));

// Auto-verification : les formes refusees le sont, la forme voulue passe.
$ancienOrg = "on:\n  workflow_dispatch:\n  release:\n    types: [published]\njobs:\n  joindre-release:\n    steps:\n      - name: x\n        run: |\n          gh release upload \"\$TAG\" build/pratcom-connect-org.zip --repo \"\$GITHUB_REPOSITORY\" --clobber\n";
verifier('auto-verification : build-org.yml de main au 26/11 joint le zip .org', declenche_par_release($ancienOrg) && assets_joints($ancienOrg) === ['pratcom-connect-org.zip'], implode(', ', assets_joints($ancienOrg)));
$ancienRelease = "on:\n  release:\n    types: [published]\njobs:\n  publier:\n    steps:\n      - name: a\n        uses: softprops/action-gh-release@abc # v2\n        with:\n          files: build/pratcom-connect-bridge.zip\n\n      - name: b\n        uses: softprops/action-gh-release@abc # v2\n        with:\n          files: build/pratcom-connect-bridge.zip.sha256\n";
verifier('auto-verification : l\'empreinte en asset fait deux assets', assets_joints($ancienRelease) === ['pratcom-connect-bridge.zip', 'pratcom-connect-bridge.zip.sha256'], implode(', ', assets_joints($ancienRelease)));
verifier('auto-verification : release.yml de main au 27/11 (softprops sans brouillon) refuse', creations_publiques($ancienRelease) !== []);
$bloc = "on: [release]\njobs:\n  p:\n    steps:\n      - uses: softprops/action-gh-release@abc # v2\n        with:\n          files: |\n            build/pratcom-connect-bridge.zip\n            build/pratcom-connect-org.zip\n";
verifier('auto-verification : liste en bloc lue en entier', declenche_par_release($bloc) && assets_joints($bloc) === ['pratcom-connect-bridge.zip', 'pratcom-connect-org.zip'], implode(', ', assets_joints($bloc)));
$autres = "on:\n  release:\njobs:\n  p:\n    steps:\n      - uses: ncipollo/release-action@abc # v1\n        with:\n          artifacts: \"build/*.zip\"\n      - run: gh release upload v1 \\\n          build/a.zip build/b.zip#etiquette --clobber\n      - run: curl -X POST https://uploads.github.com/repos/x/y/releases/1/assets\n      - uses: autre/publish-release@abc # v1\n";
verifier('auto-verification : artifacts, gh sur deux lignes, curl brut, action inconnue', assets_joints($autres) === ['a.zip', 'b.zip', '*.zip', '<action de release inconnue : autre/publish-release>', '<envoi brut vers uploads.github.com>'], implode(', ', assets_joints($autres)));
$manuel = "on:\n  workflow_dispatch:\njobs:\n  b:\n    steps:\n      - uses: actions/upload-artifact@abc # v4\n        with:\n          path: build/pratcom-connect-org.zip\n";
verifier('auto-verification : artefact seul accepte', assets_joints($manuel) === [] && creations_publiques($manuel) === []);
$commente = "on:\n  push:\n  # release:\njobs:\n  b:\n    steps:\n      # - run: gh release upload v1 build/pratcom-connect-org.zip\n      - run: echo ok\n";
verifier('auto-verification : commentaires ignores', !declenche_par_release($commente) && assets_joints($commente) === []);
// R3 : les deux mutations de la relecture de la PR #80, hors declencheur release.
$surTag = "on:\n  push:\n    tags: ['v*']\njobs:\n  j:\n    steps:\n      - run: gh release upload \"\$GITHUB_REF_NAME\" build/pratcom-connect-org.zip --clobber\n";
verifier('auto-verification R3 : push de tag qui joint le zip .org, vu', !declenche_par_release($surTag) && assets_joints($surTag) === ['pratcom-connect-org.zip'], implode(', ', assets_joints($surTag)));
$apres = "on:\n  workflow_run:\n    workflows: [\"Build premium & attach plugin zip (canal GitHub/PUC)\"]\n    types: [completed]\njobs:\n  j:\n    steps:\n      - uses: softprops/action-gh-release@abc # v2\n        with:\n          tag_name: v2.3.0\n          files: build/pratcom-connect-org.zip\n";
verifier('auto-verification R3 : workflow_run qui joint le zip .org par softprops, vu', !declenche_par_release($apres) && assets_joints($apres) === ['pratcom-connect-org.zip'] && creations_publiques($apres) !== [], implode(', ', assets_joints($apres)));
$guillemets = "on: push\njobs:\n  j:\n    steps:\n      - run: gh release create \"\$TAG\" --draft --title \"Version \$TAG\" --notes \"deux mots\" build/x.zip && echo fini\n      - run: gh release create v1 build/y.zip\n      - run: gh release edit v1 --draft=false --latest\n      - run: gh api -X POST repos/x/y/releases -f tag_name=v1\n";
verifier('auto-verification R1 : guillemets, tag ecarte, create sans --draft, edit, gh api', assets_joints($guillemets) === ['x.zip', 'y.zip']
    && creations_publiques($guillemets) === ['gh release create sans --draft', 'appel brut a l\'API des releases']
    && publications($guillemets) === 1, implode(', ', assets_joints($guillemets)) . ' / ' . implode(', ', creations_publiques($guillemets)));
$brouillonAction = "on: push\njobs:\n  j:\n    steps:\n      - uses: softprops/action-gh-release@abc # v2\n        with:\n          draft: true\n          files: build/pratcom-connect-bridge.zip\n";
verifier('auto-verification R1 : action de release en brouillon acceptee', creations_publiques($brouillonAction) === []);

echo "\n" . ($total - $echecs) . "/$total controles verts\n";
exit($echecs === 0 ? 0 : 1);
