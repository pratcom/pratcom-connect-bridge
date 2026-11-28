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
 *     (R1 du 27/11 : aucune release publique n'est jamais vue sans son zip) ;
 *   - RC (28/11) : les valeurs sont lues, pas le texte. Toute valeur de
 *     --draft (ou -d) est lue comme gh la lit : a la creation, toutes doivent
 *     etre vraies ; a l'edition, toute valeur non vraie compte comme une
 *     publication. Le garde n'a aucun if: (travail ni etape) et son script
 *     suit la forme attendue ligne a ligne, chaque commande en tete de ligne.
 *     Aucun ${{ dans un run:. Les permissions de chaque fichier et de chaque
 *     travail sont egales a une liste exacte (PERMISSIONS_ATTENDUES).
 *   - RH (28/11) : sur tag, release ou workflow_run, tout uses: hors de
 *     ACTIONS_AUTORISEES_RELEASE est refuse, meme epingle par SHA. Un appel
 *     de release par une variable shell est refuse. Les cles run citees
 *     sont lues comme run. Chaque mutation prouve sa categorie de defaut.
 *   - Correction 1 de la PR #83 (28/11) : aucune etape ni aucun travail en
 *     flux YAML ({uses: ...}, steps: [...]), aucune cle echappee, ancre,
 *     alias ou etiquette YAML (categorie forme_yaml, tous workflows). La
 *     liste d'actions vaut pour TOUS les workflows. Tout ${...}, $( ) ou
 *     `...` en position de commande devant release est refuse, comme
 *     gh alias ; gh et release cites sont lus comme gh release. L'API des
 *     releases par GITHUB_API_URL compte comme un appel brut.
 *   - Correction 2 de la PR #83 (28/11), regle de structure : seuls deux
 *     endroits ont le droit d'ecrire sur une release, le travail publier de
 *     release.yml et garde-release.yml (son unique travail garde, avec le
 *     fichier entier). Leur texte est fige par empreinte SHA-256 du texte
 *     normalise (TRAVAUX_FIGES, fins de ligne indifferentes) ; ailleurs,
 *     aucune expression secrets, aucun GH_TOKEN, GITHUB_TOKEN ni github.token,
 *     et aucune cle de premier niveau hors de name, on, permissions,
 *     concurrency, jobs (env: ou defaults: changeraient les travaux figes).
 *     Cles citees ou avec un blanc avant le deux-points refusees (forme_yaml),
 *     travail dont l'en-tete porte un commentaire lu pour ses permissions,
 *     uses: sans valeur sur sa ligne refuse, et tout mot de commande qui
 *     CONTIENT $ ou un accent grave devant release refuse (${g}h, g${v}h).
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
        // Correction 2 (constat 2) : [ \t]* et non \s*, et un uses: sans valeur
        // sur sa ligne (valeur sur la suivante) est refuse, cite ou non.
        if (!preg_match('/^[ \t]*(?:-[ \t]*)?(?:uses|"uses"|\'uses\')[ \t]*:[ \t]*(.*)$/', $ligne, $m)) {
            continue;
        }
        $vues++;
        if (!preg_match('/^([^\s#]\S*)(.*)$/', $m[1], $m)) {
            verifier("$nom:" . ($i + 1) . ' uses: sans valeur sur sa ligne', false, 'la valeur de uses: doit tenir sur la meme ligne');
            continue;
        }
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
    // Correction 1 (R1) : un guillemet autour de gh, de release ou du verbe ne
    // change rien pour le shell ("gh" release, gh 'release' edit).
    if (preg_match_all('/\bgh["\']?\s+["\']?release["\']?\s+["\']?(upload|create|edit)\b["\']?([^\n]*)/', sans_commentaires($yml), $mm, PREG_SET_ORDER)) {
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

/**
 * RC : valeur booleenne d'un texte, comme strconv.ParseBool (pflag, donc gh).
 * Rend true, false, ou null si la valeur n'est pas lisible (variable, autre).
 */
function booleen_gh(string $v): ?bool
{
    if (in_array($v, ['1', 't', 'T', 'TRUE', 'true', 'True'], true)) {
        return true;
    }
    if (in_array($v, ['0', 'f', 'F', 'FALSE', 'false', 'False'], true)) {
        return false;
    }
    return null;
}

/**
 * RC : valeurs de --draft (et de -d, seul ou groupe, ex. -pd) d'un appel
 * `gh release`, dans l'ordre. `--draft` seul vaut true ; `--draft=X` vaut
 * booleen_gh(X) ; `--draft X` (X lisible comme booleen) est lu comme X, par
 * prudence, meme si gh y verrait un argument de plus.
 *
 * @param array<int, string> $jetons
 * @return array<int, ?bool>
 */
function valeurs_draft(array $jetons): array
{
    $valeurs = [];
    $n = count($jetons);
    for ($i = 0; $i < $n; $i++) {
        $j = $jetons[$i];
        if (preg_match('/^(?:--draft|-[a-zA-Z]*d)=(.*)$/s', $j, $m)) {
            $valeurs[] = booleen_gh($m[1]);
            continue;
        }
        if ($j === '--draft' || preg_match('/^-[a-zA-Z]*d[a-zA-Z]*$/', $j)) {
            $suivant = $jetons[$i + 1] ?? null;
            if ($suivant !== null && booleen_gh($suivant) !== null) {
                $valeurs[] = booleen_gh($suivant);
                $i++;
                continue;
            }
            $valeurs[] = true;
        }
    }
    return $valeurs;
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
        if ($verbe === 'create') {
            // RC : chaque valeur de --draft est lue comme gh la lit ; toutes
            // doivent etre vraies (chez gh, la derniere gagne).
            $valeurs = valeurs_draft($jetons);
            if ($valeurs === []) {
                $trouvees[] = 'gh release create sans --draft';
            } elseif (array_filter($valeurs, static fn(?bool $v): bool => $v !== true) !== []) {
                $trouvees[] = 'gh release create avec une valeur de --draft autre que vraie';
            }
        }
    }
    foreach (etapes($yml) as $etape) {
        $action = action_etape($etape);
        if ($action !== null && in_array($action, ACTIONS_RELEASE, true) && !preg_match('/^\s+draft:\s*[\'"]?true[\'"]?\s*$/m', $etape)) {
            $trouvees[] = $action . ' sans draft: true';
        }
    }
    // Correction 1 (R2) : l'adresse de l'API peut venir de GITHUB_API_URL
    // (ou de github.api_url) sans que le texte api.github.com apparaisse.
    if (preg_match('/\bgh\s+api\b[^\n]*\breleases\b|(?:api\.github\.com|GITHUB_API_URL|github\.api_url)[^\n]*\/releases\b/', sans_commentaires($yml))) {
        $trouvees[] = 'appel brut a l\'API des releases';
    }
    return $trouvees;
}

/** Nombre de `gh release edit ... --draft=false` (publication d'un brouillon). */
function publications(string $yml): int
{
    $n = 0;
    foreach (appels_gh_release($yml) as [$verbe, $jetons]) {
        // RC : toute valeur de --draft qui n'est pas vraie (fausse, variable,
        // illisible) compte comme une publication.
        if ($verbe === 'edit' && array_filter(valeurs_draft($jetons), static fn(?bool $v): bool => $v !== true) !== []) {
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

// ─── 9. Lire les valeurs, pas le texte (RC, relecture de la PR #81) ─────────
//
// Formes detournees qui passaient vertes au 28/11 : --draft=0, --draft="$D",
// --draft puis --draft=false sur la meme creation, garde neutralise par un
// if: ou par un `:` devant sa commande, garde limite a zero asset, ${{ }}
// dans un run:, permission ajoutee a un travail. Chacune est rejouee plus bas
// sur une copie en memoire des vrais workflows et doit rougir.

echo "Valeurs lues, pas le texte (RC)\n";

/** Scripts de chaque run, cle citee ou non (commentaires du script compris). */
function blocs_run(string $yml): array
{
    $lignes = explode("\n", str_replace("\r\n", "\n", $yml));
    $blocs = [];
    $n = count($lignes);
    for ($i = 0; $i < $n; $i++) {
        if (!preg_match('/^([ \t]*(?:-[ \t]+)?)(?:run|"run"|\'run\')[ \t]*:(.*)$/', $lignes[$i], $m)) {
            continue;
        }
        $retrait = strlen($m[1]);
        $bloc = [$m[2]];
        while ($i + 1 < $n && (trim($lignes[$i + 1]) === '' || strspn($lignes[$i + 1], " \t") > $retrait)) {
            $bloc[] = $lignes[++$i];
        }
        $blocs[] = implode("\n", $bloc);
    }
    return $blocs;
}

// Noms exacts des seules actions presentes dans les workflows. Correction 1
// (R3, R4) : la liste vaut pour TOUS les workflows, quel que soit le
// declencheur ; plus de lecture du bloc on: (on :, on: push, on: create...).
// Le controle des SHA et commentaires de version reste independant (section 2).
// Ni action de script arbitraire, ni action locale, Docker ou workflow reutilise.
const ACTIONS_AUTORISEES = [
    'actions/checkout',
    'shivammathur/setup-php',
    'actions/upload-artifact',
    'actions/download-artifact',
];

/** Tous les uses: d'un workflow, y compris les cles citees. */
function actions_hors_liste(string $yml): array
{
    $refusees = [];
    // Correction 2 (constat 2) : la valeur doit tenir sur la ligne de uses: ;
    // une valeur vide (ou un seul commentaire) cache l'action a la lecture.
    preg_match_all('/^[ \t]*(?:-[ \t]+)?(?:uses|"uses"|\'uses\')[ \t]*:[ \t]*([^\n]*)$/m', sans_commentaires($yml), $usages);
    foreach ($usages[1] as $usage) {
        $usage = trim($usage);
        if ($usage === '' || $usage[0] === '#') {
            $refusees[] = '<uses: sans valeur sur sa ligne>';
            continue;
        }
        $usage = (string) strtok($usage, " \t");
        $action = (string) preg_replace('/@.*$/', '', trim($usage, '"\''));
        if (!in_array($action, ACTIONS_AUTORISEES, true)) {
            $refusees[] = $action;
        }
    }
    return $refusees;
}

/**
 * Commande release appelee par une variable : l'executable echappe a la lecture
 * de gh. Refus conservateur, sans essayer de resoudre les affectations shell.
 * Correction 1 : tout contenu entre accolades (${g:-gh}, ${G[0]}), toute
 * substitution de commande ($(echo gh), `echo gh`) en position de commande,
 * precedee ou non d'affectations (X=1 $g release) ; release cite compris.
 * gh alias est refuse : un alias rend gh <alias> illisible comme gh release.
 */
function appels_release_variables(string $yml): array
{
    $appels = [];
    $affectations = '(?:[A-Za-z_][A-Za-z0-9_]*=\S*[ \t]+)*';
    // Correction 2 (constat 3) : un mot de commande est lu en entier (chaines
    // citees, ${...}, $( ), `...` compris) ; il est refuse des qu'il CONTIENT
    // $ ou un accent grave (${g}h, $(printf g)h, g${vide}h), pas seulement
    // s'il en est fait. Une affectation (x=$(gh ...)) n'est pas une commande.
    $mot = '(?:"[^"\n]*"|\'[^\'\n]*\'|\$\([^)\n]*\)|`[^`\n]*`|\$\{[^}\n]*\}|[^\s;&|()`"\'<>])+';
    $motif = '/(?:^|[;&|(`{])[ \t]*' . $affectations
        . '(?:(?:if|then|do|elif|else|exec|command|builtin|env|time|nohup|!)[ \t]+' . $affectations . ')*'
        . '(?![A-Za-z_][A-Za-z0-9_]*=)(' . $mot . ')[ \t]+["\']?release\b/m';
    foreach (blocs_run($yml) as $bloc) {
        $script = sans_commentaires($bloc);
        preg_match_all($motif, $script, $m, PREG_SET_ORDER);
        foreach ($m as $appel) {
            if (strpbrk($appel[1], '$`') !== false) {
                $appels[] = trim($appel[0]);
            }
        }
        preg_match_all('/\bgh["\']?[ \t]+["\']?alias\b[^\n]*/', $script, $a);
        foreach ($a[0] as $alias) {
            $appels[] = trim($alias);
        }
    }
    return $appels;
}

/**
 * Correction 1 : lignes d'un workflow hors commentaires et hors contenu des
 * scalaires en bloc (| ou >), numerotees a partir de 1. Le script d'un run:
 * (ex. [ "$x" = y ] en tete de ligne) n'est pas du YAML a lire.
 *
 * @return array<int, string>
 */
function lignes_yaml(string $yml): array
{
    $lignes = explode("\n", str_replace("\r\n", "\n", $yml));
    $sortie = [];
    $n = count($lignes);
    for ($i = 0; $i < $n; $i++) {
        if (preg_match('/^[ \t]*#/', $lignes[$i])) {
            continue;
        }
        $sortie[$i + 1] = $lignes[$i];
        // Correction 2 (constat 1) : la cle ne contient ni # ni deux-points ;
        // `steps: # forme: |` n'ouvre aucun scalaire en bloc (commentaire).
        if (!preg_match('/^([ \t]*(?:-[ \t]+)*)(?:(?:[^\s#\'"-][^\n:#]*|"[^"\n]*"|\'[^\'\n]*\')[ \t]*:[ \t]+)?[|>][1-9+-]{0,2}[ \t]*(?:#[^\n]*)?$/', $lignes[$i], $m)) {
            continue;
        }
        $colonne = strlen($m[1]);
        while ($i + 1 < $n && (trim($lignes[$i + 1]) === '' || strspn($lignes[$i + 1], " \t") > $colonne)) {
            $i++;
        }
    }
    return $sortie;
}

/**
 * Correction 1 (constat 1, R5) : formes YAML que la lecture ligne a ligne du
 * banc ne voit pas, refusees dans tous les workflows (aucune n'y sert) :
 *   - etape, travail, jobs: ou steps: ecrits en flux ({uses: ...}, [ ... ]) ;
 *   - cle citee qui contient une barre oblique inverse ("\x72un" vaut run) ;
 *   - cle complexe (?), fusion (<<), ancre (&), alias (*) ou etiquette (!).
 * Les collections en flux en valeur d'une cle simple (tags: ['v*'],
 * types: [published], permissions: {}) restent permises.
 *
 * @return array<int, string>
 */
function formes_yaml_refusees(string $yml): array
{
    $refusees = [];
    $dansJobs = false;
    foreach (lignes_yaml($yml) as $no => $l) {
        if (preg_match('/^\S/', $l)) {
            $dansJobs = (bool) preg_match('/^(?:jobs|"jobs"|\'jobs\')[ \t]*:/', $l);
        }
        $motif = null;
        if (preg_match('/^[ \t]*-[ \t]*[{\[]/', $l)) {
            $motif = 'element de liste en flux';
        } elseif (preg_match('/^[ \t]*[{\[]/', $l)) {
            $motif = 'collection en flux en tete de ligne';
        } elseif (preg_match('/^[ \t]*(?:-[ \t]+)?(?:jobs|steps|"jobs"|\'jobs\'|"steps"|\'steps\')[ \t]*:[ \t]*[{\[]/', $l)) {
            $motif = 'jobs ou steps en flux';
        } elseif ($dansJobs && preg_match('/^  [^ \t#][^\n]*?:[ \t]*[{\[]/', $l)) {
            $motif = 'travail en flux';
        } elseif (preg_match('/^[ \t]*(?:-[ \t]+)?"[^"\n]*\\\\[^"\n]*"[ \t]*:/', $l)) {
            $motif = 'cle echappee';
        } elseif (preg_match('/^[ \t]*(?:-[ \t]+)?(?:"[^"\n]*"|\'[^\'\n]*\')[ \t]*:(?:[ \t]|$)/', $l)) {
            // Correction 2 : aucune cle citee ("permissions": write-all
            // echapperait a PERMISSIONS_ATTENDUES) ; aucun workflow n'en a.
            $motif = 'cle citee';
        } elseif (preg_match('/^[ \t]*(?:-[ \t]+)?[^\s#"\'{\[-][^:\n#]*?[ \t]+:(?:[ \t]|$)/', $l)) {
            $motif = 'blanc avant le deux-points d\'une cle';
        } elseif (preg_match('/^[ \t]*(?:-[ \t]+)?(?:\?|<<|[&*!])/', $l) || preg_match('/^[ \t]*(?:-[ \t]+)?[^\s#][^\n]*?:[ \t]+[&*!]/', $l)) {
            $motif = 'cle complexe, fusion, ancre, alias ou etiquette';
        }
        if ($motif !== null) {
            $refusees[] = "ligne $no, $motif : " . trim($l);
        }
    }
    return $refusees;
}

/** Permissions d'un bloc (`permissions:` au retrait donne), normalisees, ou null. */
function permissions_bloc(string $texte, int $retrait): ?string
{
    $r = str_repeat(' ', $retrait);
    if (!preg_match('/^' . $r . 'permissions:[ \t]*([^\n]*)\n((?:' . $r . '[ \t]+[^\n]*\n?)*)/m', $texte . "\n", $m)) {
        return null;
    }
    $tete = trim($m[1]);
    $paires = [];
    if ($tete !== '' && $tete[0] !== '{') {
        return $tete; // read-all, write-all
    }
    $corps = $tete !== '' ? trim($tete, '{} ') : $m[2];
    foreach (preg_split('/[\n,]+/', $corps) ?: [] as $p) {
        if (trim($p) === '') {
            continue;
        }
        [$cle, $val] = array_map('trim', explode(':', $p, 2) + [1 => '']);
        $paires[$cle] = $val;
    }
    ksort($paires);
    $sortie = [];
    foreach ($paires as $cle => $val) {
        $sortie[] = "$cle=$val";
    }
    return $sortie === [] ? '{}' : implode(',', $sortie);
}

/** Travaux d'un workflow : nom => corps (sans commentaires). */
function travaux(string $yml): array
{
    $sans = sans_commentaires($yml);
    $jobs = preg_match('/^jobs:[ \t]*\n(.*)$/ms', $sans, $m) ? $m[1] : '';
    $liste = [];
    // Correction 2 : un commentaire sur la ligne du travail (`  autre: # x`)
    // ne le cache plus de la liste exacte des permissions.
    if (preg_match_all('/^  ([A-Za-z0-9_-]+):[ \t]*(?:#[^\n]*)?\n((?:(?!  \S|\S)[^\n]*\n?)*)/m', $jobs, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $t) {
            $liste[$t[1]] = $t[2];
        }
    }
    return $liste;
}

// Liste exacte : permissions du fichier, puis de chaque travail (null : le
// travail n'en declare pas et prend celles du fichier).
const PERMISSIONS_ATTENDUES = [
    'build-org.yml' => ['<fichier>' => '{}', 'build-org' => 'contents=read'],
    'ci.yml' => ['<fichier>' => 'contents=read', 'lint' => null],
    'garde-release.yml' => ['<fichier>' => '{}', 'garde' => 'contents=write'],
    'release.yml' => ['<fichier>' => '{}', 'build' => 'contents=read', 'publier' => 'contents=write'],
];

// Le garde, ligne a ligne : chaque commande ouvre sa ligne, rien ne la precede.
const GARDE_SCRIPT = [
    'set -euo pipefail',
    'assets=$(gh release view "$TAG" --json assets --jq \'.assets | map(.name) | join(",")\')',
    'echo "assets=$assets"',
    'if [ "$assets" != "pratcom-connect-bridge.zip" ]; then',
    'gh release edit "$TAG" --draft=true',
];

// Correction 2 (regle de structure) : les deux seuls endroits qui ecrivent
// sur une release ont leur texte fige. Travail null : le fichier entier
// (garde-release.yml n'a qu'un travail ; son en-tete on, permissions,
// concurrency le commande aussi). Empreinte : SHA-256 du texte normalise
// (texte_normalise : fins de ligne LF, blancs de fin de ligne et lignes vides
// finales retires). Commentaires compris : un commentaire n'est pas toujours
// un commentaire (suite d'une chaine citee sur plusieurs lignes).
//
// CHANGEMENT VOULU d'un de ces travaux : relire son diff ligne a ligne, puis
// reporter ici l'empreinte que le banc affiche, dans la meme PR. C'est une PR
// d'outillage : label relue pose par Martin seulement.
const TRAVAUX_FIGES = [
    'release.yml' => ['travail' => 'publier', 'empreinte' => '4c7c7d64fab5218b060d2ed299eb646e4e586e8b0436e8b1471a6afe52721e87'],
    'garde-release.yml' => ['travail' => null, 'empreinte' => 'bd088405e46beb829fc406427aed560cc28333822587b80e59b2ba4af84a6cd5'],
];

// Cles de premier niveau permises dans tout workflow : env: et defaults:
// (shell, BASH_ENV...) changeraient l'execution des travaux figes.
const CLES_PREMIER_NIVEAU = ['name', 'on', 'permissions', 'concurrency', 'jobs'];

/** Texte normalise : LF, blancs de fin de ligne retires, lignes vides finales retirees. */
function texte_normalise(array $lignes): string
{
    $lignes = array_map(static fn(string $l): string => rtrim($l, " \t\r"), $lignes);
    while ($lignes !== [] && end($lignes) === '') {
        array_pop($lignes);
    }
    return implode("\n", $lignes);
}

/**
 * Decoupe un workflow en [texte fige normalise (null si le travail est
 * introuvable ou en double), lignes hors du travail fige].
 * Le travail va de sa ligne d'en-tete a la premiere ligne non vide, hors
 * commentaire, retiree de 2 colonnes au plus : un commentaire en colonne 0
 * suivi d'etapes reste dans le travail, comme pour YAML.
 *
 * @return array{0: ?string, 1: array<int, string>}
 */
function decouper_fige(string $yml, ?string $travail): array
{
    $lignes = explode("\n", str_replace(["\r\n", "\r"], "\n", $yml));
    if ($travail === null) {
        return [texte_normalise($lignes), []];
    }
    $q = preg_quote($travail, '/');
    $entetes = array_keys(preg_grep('/^[ \t]*(?:"' . $q . '"|\'' . $q . '\'|' . $q . ')[ \t]*:/', $lignes) ?: []);
    if (count($entetes) !== 1 || !preg_match('/^  ' . $q . ':[ \t]*(?:#.*)?$/', $lignes[$entetes[0]])) {
        return [null, $lignes];
    }
    $debut = $entetes[0];
    $fin = $debut + 1;
    $n = count($lignes);
    while ($fin < $n && (trim($lignes[$fin]) === '' || preg_match('/^[ \t]*#/', $lignes[$fin]) || strspn($lignes[$fin], " \t") > 2)) {
        $fin++;
    }
    return [texte_normalise(array_slice($lignes, $debut, $fin - $debut)),
        array_merge(array_slice($lignes, 0, $debut), array_slice($lignes, $fin))];
}

/**
 * Correction 2 (regle de structure, a) : jetons hors des travaux figes.
 * Texte brut, commentaires COMPRIS (voir TRAVAUX_FIGES). Toute mention de
 * secrets (secrets.X, secrets['X'], toJSON(secrets), secrets: inherit), de
 * github.token (ou github[...], toJSON(github)), et des variables GH_TOKEN,
 * GITHUB_TOKEN, GH_ENTERPRISE_TOKEN, GITHUB_ENTERPRISE_TOKEN. Casse ignoree,
 * comme les expressions de GitHub.
 *
 * @param array<int, string> $lignes
 * @return array<int, string>
 */
function jetons_hors_figes(array $lignes): array
{
    $trouves = [];
    $motif = '/\bsecrets\b|\bgithub[ \t]*(?:\.[ \t]*token\b|\[|\))|\b(?:GH|GITHUB)_(?:ENTERPRISE_)?TOKEN\b/i';
    foreach ($lignes as $l) {
        if (preg_match($motif, $l, $m)) {
            $trouves[] = $m[0] . ' dans : ' . trim($l);
        }
    }
    return $trouves;
}

/** Cles de premier niveau hors de CLES_PREMIER_NIVEAU (lignes YAML hors scalaires en bloc). */
function cles_premier_niveau_refusees(string $yml): array
{
    $refusees = [];
    foreach (lignes_yaml($yml) as $no => $l) {
        if (!preg_match('/^\S/', $l)) {
            continue;
        }
        if (!preg_match('/^([A-Za-z][\w-]*):(?:[ \t]|$)/', $l, $k) || !in_array($k[1], CLES_PREMIER_NIVEAU, true)) {
            $refusees[] = "ligne $no : " . trim($l);
        }
    }
    return $refusees;
}

/**
 * Defauts RC et RH d'un jeu de workflows (nom => texte brut), par categorie.
 *
 * @param array<string, string> $textes
 * @return array<string, array<int, string>>
 */
function defauts_rc(array $textes): array
{
    $d = ['creation' => [], 'publication' => [], 'garde' => [], 'expression' => [], 'permissions' => [], 'actions' => [], 'gh_variable' => [], 'forme_yaml' => [], 'jeton' => [], 'travail_fige' => []];
    $pub = [];
    foreach (TRAVAUX_FIGES as $nom => ['travail' => $travail, 'empreinte' => $attendue]) {
        $quoi = $travail === null ? "$nom (fichier entier)" : "$nom, travail $travail";
        if (!isset($textes[$nom])) {
            $d['travail_fige'][] = "$quoi : fichier absent";
            continue;
        }
        [$fige] = decouper_fige($textes[$nom], $travail);
        if ($fige === null) {
            $d['travail_fige'][] = "$quoi : en-tete introuvable, en double ou ecrit autrement que `  $travail:`";
        } elseif (($vue = hash('sha256', $fige)) !== $attendue) {
            $d['travail_fige'][] = "$quoi : empreinte $vue au lieu de $attendue. Ce texte ecrit sur les releases et il est fige."
                . ' Changement voulu : relire son diff ligne a ligne, puis reporter l\'empreinte vue dans TRAVAUX_FIGES'
                . ' (tests/verifier-outillage-release.php) dans la meme PR, qui devient une PR d\'outillage (label relue par Martin)';
        }
    }
    foreach ($textes as $nom => $texte) {
        $hors = isset(TRAVAUX_FIGES[$nom]) ? decouper_fige($texte, TRAVAUX_FIGES[$nom]['travail'])[1]
            : explode("\n", str_replace("\r\n", "\n", $texte));
        foreach (jetons_hors_figes($hors) as $jeton) {
            $d['jeton'][] = "$nom : $jeton (hors des travaux figes de TRAVAUX_FIGES)";
        }
        foreach (cles_premier_niveau_refusees($texte) as $cle) {
            $d['travail_fige'][] = "$nom : cle de premier niveau hors de " . implode(', ', CLES_PREMIER_NIVEAU) . ", $cle";
        }
        foreach (formes_yaml_refusees($texte) as $forme) {
            $d['forme_yaml'][] = "$nom : $forme";
        }
        foreach (actions_hors_liste($texte) as $action) {
            $d['actions'][] = "$nom : $action hors ACTIONS_AUTORISEES";
        }
        foreach (appels_release_variables($texte) as $appel) {
            $d['gh_variable'][] = "$nom : $appel";
        }
        foreach (creations_publiques($texte) as $c) {
            $d['creation'][] = "$nom : $c";
        }
        if (($p = publications($texte)) > 0) {
            $pub[$nom] = $p;
        }
        foreach (blocs_run($texte) as $bloc) {
            if (strpos($bloc, '${{') !== false) {
                $d['expression'][] = "$nom : " . (preg_match('/^[^\n]*\$\{\{[^\n]*$/m', $bloc, $l) ? trim($l[0]) : '');
            }
        }
        $avant = (string) strstr(sans_commentaires($texte), "\njobs:", true);
        $vues = ['<fichier>' => permissions_bloc($avant . "\n", 0)];
        foreach (travaux($texte) as $travail => $corps) {
            $vues[$travail] = permissions_bloc($corps, 4);
        }
        $attendues = PERMISSIONS_ATTENDUES[$nom] ?? null;
        if ($vues !== $attendues) {
            $d['permissions'][] = "$nom : " . json_encode($vues) . ' au lieu de ' . json_encode($attendues);
        }
    }
    foreach (array_diff(array_keys(PERMISSIONS_ATTENDUES), array_keys($textes)) as $absent) {
        $d['permissions'][] = "$absent absent";
    }
    if ($pub !== ['release.yml' => 1]) {
        $d['publication'][] = json_encode($pub);
    }

    $garde = travaux($textes['garde-release.yml'] ?? '')['garde'] ?? '';
    if ($garde === '') {
        $d['garde'][] = 'travail garde absent';
    }
    if (preg_match('/^[ \t]*(?:-[ \t]+)?(if|shell|continue-on-error):/m', $garde, $k)) {
        $d['garde'][] = $k[1] . ': sur le travail du garde ou une de ses etapes';
    }
    if (!preg_match('/^      TAG: \$\{\{ github\.event\.release\.tag_name \}\}[ \t]*$/m', $garde)) {
        $d['garde'][] = 'TAG ne vient pas de la release publiee';
    }
    $runs = blocs_run($garde);
    $lignes = $runs === [] ? [] : array_values(array_filter(array_map('trim', explode("\n", $runs[0])), static fn(string $l): bool => $l !== '' && $l !== '|'));
    if (count($runs) !== 1 || array_slice($lignes, 0, count(GARDE_SCRIPT)) !== GARDE_SCRIPT) {
        $d['garde'][] = 'script du garde different de la forme attendue : ' . implode(' / ', array_slice($lignes, 0, 5));
    } elseif (!str_starts_with($lignes[5] ?? '', 'echo "::error::') || ($lignes[6] ?? '') !== 'exit 1' || ($lignes[7] ?? '') !== 'fi') {
        $d['garde'][] = 'le garde ne finit pas en echec apres la remise en brouillon';
    }
    return $d;
}

$reels = [];
foreach ($workflows as $fichier) {
    $reels[basename($fichier)] = lire($fichier) ?? '';
}
$defauts = defauts_rc($reels);
verifier('RC : toute creation de release a toutes ses valeurs de --draft vraies', $defauts['creation'] === [], implode('; ', $defauts['creation']));
verifier('RC : une seule publication (--draft lu comme booleen), dans release.yml', $defauts['publication'] === [], implode('; ', $defauts['publication']));
verifier('RC : garde sans if:, commande en tete de ligne, sur tout asset autre que le seul zip', $defauts['garde'] === [], implode('; ', $defauts['garde']));
verifier('RC : aucune expression ${{ dans un run: (tous workflows)', $defauts['expression'] === [], implode('; ', $defauts['expression']));
verifier('RC : permissions de chaque fichier et de chaque travail egales a la liste exacte', $defauts['permissions'] === [], implode('; ', $defauts['permissions']));
verifier('RH : seuls les noms de ACTIONS_AUTORISEES, dans tous les workflows (correction 1, R3)', $defauts['actions'] === [], implode('; ', $defauts['actions']));
verifier('RH : aucun appel de release par une variable shell, une substitution ou gh alias', $defauts['gh_variable'] === [], implode('; ', $defauts['gh_variable']));
verifier('Correction 1 : aucune etape ni travail en flux YAML, aucune cle echappee, ancre ou alias (tous workflows)', $defauts['forme_yaml'] === [], implode('; ', $defauts['forme_yaml']));
verifier('Correction 2 : aucun secrets, GH_TOKEN, GITHUB_TOKEN ni github.token hors de publier (release.yml) et garde-release.yml', $defauts['jeton'] === [], implode('; ', $defauts['jeton']));
verifier('Correction 2 : texte de publier (release.yml) et de garde-release.yml egal a TRAVAUX_FIGES, cles de premier niveau permises', $defauts['travail_fige'] === [], implode('; ', $defauts['travail_fige']));
// Fins de ligne indifferentes : la meme empreinte en LF et en CRLF.
$crlf = array_map(static fn(string $t): string => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $t)), $reels);
verifier('Correction 2 : empreintes des travaux figes identiques en CRLF', defauts_rc($crlf)['travail_fige'] === [], implode('; ', defauts_rc($crlf)['travail_fige']));

// Lecture des booleens, comme gh.
verifier('RC : valeurs de --draft lues comme gh', valeurs_draft(['v1', '--draft']) === [true]
    && valeurs_draft(['v1', '--draft=0']) === [false]
    && valeurs_draft(['v1', '--draft=F']) === [false]
    && valeurs_draft(['v1', '--draft=$D']) === [null]
    && valeurs_draft(['v1', '--draft', 'false']) === [false]
    && valeurs_draft(['v1', '-d', '--verify-tag']) === [true]
    && valeurs_draft(['v1', '-pd=false']) === [false]
    && valeurs_draft(['v1', '--draft', '--verify-tag', '--draft=false']) === [true, false]
    && valeurs_draft(['v1', '--notes-file', 'x']) === []);

// Les formes detournees de la relecture, rejouees sur les vrais workflows.
// La categorie attendue doit rougir : un autre defaut ne prouve rien.
$avantJoindre = "      - name: Joindre le zip au brouillon (seul asset)\n";
$detournees = [
    'edit --draft=0 avant la jonction' => ['release.yml', $avantJoindre, "      - name: x\n        run: gh release edit \"\$TAG\" --draft=0\n\n" . $avantJoindre, 'publication'],
    'create --draft --verify-tag --draft=false' => ['release.yml', 'gh release create "$TAG" --draft --verify-tag', 'gh release create "$TAG" --draft --verify-tag --draft=false', 'creation'],
    'edit --draft="$D" (D=false) avant la jonction' => ['release.yml', $avantJoindre, "      - name: x\n        run: |\n          D=false\n          gh release edit \"\$TAG\" --draft=\"\$D\"\n\n" . $avantJoindre, 'publication'],
    'create -d=false' => ['release.yml', 'gh release create "$TAG" --draft --verify-tag', 'gh release create "$TAG" -d=false --verify-tag', 'creation'],
    'garde neutralise par if: false sur son travail' => ['garde-release.yml', "  garde:\n    runs-on: ubuntu-latest\n", "  garde:\n    if: false\n    runs-on: ubuntu-latest\n", 'garde'],
    'garde limite a zero asset' => ['garde-release.yml', 'if [ "$assets" != "pratcom-connect-bridge.zip" ]; then', 'if [ -z "$assets" ]; then', 'garde'],
    'commande du garde precedee de :' => ['garde-release.yml', '            gh release edit "$TAG" --draft=true', '            : gh release edit "$TAG" --draft=true', 'garde'],
    'garde sur une etape avec if:' => ['garde-release.yml', "      - name: Remettre en brouillon", "      - if: false\n        name: Remettre en brouillon", 'garde'],
    '${{ github.ref_name }} dans un run:' => ['release.yml', 'run: gh release upload "$TAG"', 'run: gh release upload "${{ github.ref_name }}"', 'expression'],
    '${{ }} dans un commentaire de script' => ['ci.yml', "          echec=0\n", "          echec=0\n          # \${{ github.head_ref }}\n", 'expression'],
    'actions: write sur le travail du garde' => ['garde-release.yml', "    permissions:\n      contents: write\n", "    permissions:\n      contents: write\n      actions: write\n", 'permissions'],
    'permissions: write-all sur publier' => ['release.yml', "    permissions:\n      contents: write\n", "    permissions: write-all\n", 'permissions'],
    'travail de plus dans ci.yml' => ['ci.yml', "\njobs:\n", "\njobs:\n  autre:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo x\n", 'permissions'],
];

// RH : une action epinglee peut publier sans passer par gh. Aucun nom hors
// liste ne passe, meme sans le mot release, meme local ou Docker.
$shaMutation = str_repeat('a', 40);
foreach (['actions/github-script@' . $shaMutation . ' # v7', 'actions/github-script@v7',
    'autre/action@' . $shaMutation . ' # v1', './.github/actions/script', 'docker://image:1'] as $usage) {
    $detournees["uses hors liste : $usage"] = ['release.yml', $avantJoindre,
        "      - uses: $usage\n        with:\n          script: github.rest.repos.updateRelease({ draft: false })\n\n" . $avantJoindre, 'actions'];
}
// Correction 1 (constat 2) : tout ${...}, $( ) ou `...` en position de
// commande, avec ou sans affectation devant.
foreach (['$g', '"$g"', '${g}', '"${g}"', '${g:-gh}', '"${g:=gh}"', '${G[0]}', '$(echo gh)', '`echo gh`', 'X=1 $g'] as $commande) {
    $detournees["gh par variable : $commande release"] = ['release.yml', $avantJoindre,
        "      - run: |\n          g=gh\n          $commande release edit \"\$TAG\" --draft=false\n\n" . $avantJoindre, 'gh_variable'];
}
foreach (["'run'", '"run"'] as $cle) {
    $detournees["expression dans $cle en ligne"] = ['release.yml', 'run: gh release upload "$TAG"',
        $cle . ': gh release upload "${{ github.ref_name }}"', 'expression'];
    $detournees["expression dans $cle en bloc"] = ['ci.yml', "        run: |\n          echec=0\n",
        "        $cle: |\n          echec=0\n          # \${{ github.head_ref }}\n", 'expression'];
}
foreach (["'uses'", '"uses"'] as $cle) {
    $detournees["action inconnue avec $cle"] = ['release.yml', $avantJoindre,
        "      - $cle: actions/github-script@$shaMutation # v7\n        with:\n          script: github.rest.repos.updateRelease({ draft: false })\n\n" . $avantJoindre, 'actions'];
}
// Correction 1 (R1) : gh et release cites, alias de gh.
$detournees['gh par variable : $g "release"'] = ['release.yml', $avantJoindre,
    "      - run: |\n          g=gh\n          \$g \"release\" edit \"\$TAG\" --draft=false\n\n" . $avantJoindre, 'gh_variable'];
$detournees['gh alias set puis appel par alias'] = ['release.yml', $avantJoindre,
    "      - run: |\n          gh alias set pub 'release edit'\n          gh pub \"\$TAG\" --draft=false\n\n" . $avantJoindre, 'gh_variable'];
foreach (['"gh" release edit', 'gh "release" edit', "gh 'release' 'edit'"] as $commande) {
    $detournees["publication par $commande"] = ['release.yml', $avantJoindre,
        "      - run: |\n          $commande \"\$TAG\" --draft=false\n\n" . $avantJoindre, 'publication'];
}
// Correction 1 (R2) : API des releases par GITHUB_API_URL.
$detournees['curl PATCH par $GITHUB_API_URL'] = ['release.yml', $avantJoindre,
    "      - run: |\n          curl -X PATCH \"\$GITHUB_API_URL/repos/\$GH_REPO/releases/1\" -d '{\"draft\":false}'\n\n" . $avantJoindre, 'creation'];
// Correction 1 (R3) : la liste d'actions vaut aussi sur pull_request et push.
$detournees['action hors liste dans ci.yml'] = ['ci.yml', 'uses: shivammathur/setup-php@', 'uses: actions/github-script@', 'actions'];
// Correction 1 (constat 1, R5) : etapes et travaux en flux YAML, cle
// echappee, alias. Chaque forme rougit en forme_yaml, quel que soit le reste.
$scriptPublier = "{script: 'github.rest.repos.updateRelease({draft: false})'}";
$detournees['etape en flux, uses en tete, dans publier'] = ['release.yml', $avantJoindre,
    "      - {uses: actions/github-script@$shaMutation, with: $scriptPublier}\n" . $avantJoindre, 'forme_yaml'];
$detournees['etape en flux, name puis uses, dans publier'] = ['release.yml', $avantJoindre,
    "      - {name: x, uses: actions/github-script@$shaMutation, with: $scriptPublier}\n" . $avantJoindre, 'forme_yaml'];
$detournees['etape en flux, uses en tete, dans le garde'] = ['garde-release.yml', "    steps:\n",
    "    steps:\n      - {uses: actions/github-script@$shaMutation, with: $scriptPublier}\n", 'forme_yaml'];
$detournees['etape en flux, run avec ${{ }}, dans ci.yml'] = ['ci.yml', "    steps:\n",
    "    steps:\n      - {name: x, run: 'echo \${{ github.head_ref }}'}\n", 'forme_yaml'];
$detournees['etape en flux, run par variable, dans publier'] = ['release.yml', $avantJoindre,
    "      - {name: x, run: 'g=gh; \"\$g\" release edit \"\$TAG\" --draft=false'}\n" . $avantJoindre, 'forme_yaml'];
$detournees['etape en flux sur la ligne apres le tiret'] = ['release.yml', $avantJoindre,
    "      -\n        {uses: actions/github-script@$shaMutation}\n" . $avantJoindre, 'forme_yaml'];
$detournees['steps: en liste en flux'] = ['ci.yml', "\njobs:\n",
    "\njobs:\n  autre:\n    runs-on: ubuntu-latest\n    steps: [{run: echo x}]\n", 'forme_yaml'];
$detournees['travail entier en flux'] = ['ci.yml', "\njobs:\n",
    "\njobs:\n  autre: {runs-on: ubuntu-latest, steps: [{uses: actions/github-script@$shaMutation}]}\n", 'forme_yaml'];
$detournees['cle run echappee ("\\x72un") avec ${{ }}'] = ['release.yml', 'run: gh release upload "$TAG"',
    '"\\x72un": gh release upload "${{ github.ref_name }}"', 'forme_yaml'];
$detournees['uses par alias YAML'] = ['release.yml', $avantJoindre,
    "      - name: x\n        uses: *action\n\n" . $avantJoindre, 'forme_yaml'];
// Le meme contournement doit rougir sur chacun des trois declencheurs,
// en bloc, scalaire, liste ou objet inline, y compris avec une cle on citee.
$onOrg = "on:\n  workflow_dispatch:\n  push:\n    tags: ['v*']\n";
$original = $reels['build-org.yml'] ?? '';
verifier('RH : ancrages uniques pour les mutations des declencheurs', substr_count($original, $onOrg) === 1
    && substr_count($original, 'uses: actions/checkout@') === 1);
foreach (["on:\n  release:\n    types: [published]\n", "on: release\n", "on: [release]\n",
    "\"on\":\n  workflow_run:\n    workflows: [build]\n    types: [completed]\n", "on: [workflow_run]\n",
    "'on': {push: {tags: ['v*']}}\n",
    // Correction 1 (R4) : declencheurs que l'ancienne lecture du bloc on: ne voyait pas.
    "on :\n  workflow_dispatch:\n", "on: push\n", "on: create\n", "on:\n  push:\n    tags-ignore: ['x']\n"] as $declencheur) {
    $mute = str_replace($onOrg, $declencheur, $original);
    $mute = str_replace('uses: actions/checkout@', 'uses: actions/github-script@', $mute);
    $detournees['action hors liste sur ' . trim(str_replace("\n", ' ', $declencheur))] = [
        'build-org.yml', $original, $mute, 'actions',
    ];
}

// Correction 2 (constat 1) : un commentaire qui finit par « : | » n'ouvre
// aucun scalaire en bloc. Dans build-org.yml, ou aucune autre mutation ne tombe.
$detournees['commentaire « : | » apres steps:, uses en flux (build-org.yml)'] = ['build-org.yml', "    steps:\n",
    "    steps: # forme: |\n      - {uses: actions/github-script@$shaMutation, with: $scriptPublier}\n", 'forme_yaml'];
$detournees['commentaire « : | » apres steps:, run en flux (build-org.yml)'] = ['build-org.yml', "    steps:\n",
    "    steps: # lire: |\n      - {name: x, run: 'echo \${{ github.head_ref }}'}\n", 'forme_yaml'];
// Correction 2 (constat 2) : uses: dont la valeur est sur la ligne suivante.
foreach (["actions/github-script@$shaMutation # v7", "\"actions/github-script@$shaMutation\" # v7"] as $valeur) {
    $detournees["uses: seul, valeur $valeur en dessous, dans publier"] = ['release.yml', $avantJoindre,
        "      - name: x\n        uses:\n          $valeur\n        with:\n          script: github.rest.repos.updateRelease({ draft: false })\n\n" . $avantJoindre, 'actions'];
}
$detournees['uses: seul, valeur en dessous (build-org.yml)'] = ['build-org.yml', '- uses: actions/checkout@',
    "- uses:\n          actions/checkout@", 'actions'];
// Correction 2 (constat 3) : expansion ou substitution collee au nom de commande.
foreach (['${g}h', '$(printf g)h', 'g${vide}h'] as $commande) {
    $detournees["gh par expansion collee : $commande release"] = ['release.yml', $avantJoindre,
        "      - run: |\n          g=g\n          $commande release edit \"\$TAG\" --draft=false\n\n" . $avantJoindre, 'gh_variable'];
}
// Correction 2 (regle de structure, a) : aucun jeton hors des travaux figes.
$detournees['GH_TOKEN par secrets.PAT dans build (release.yml)'] = ['release.yml', "      - name: Create zip\n",
    "      - name: x\n        env:\n          GH_TOKEN: \${{ secrets.PAT }}\n        run: echo x\n\n      - name: Create zip\n", 'jeton'];
$detournees['token: secrets[\'PAT\'] dans build-org.yml'] = ['build-org.yml', "          php-version: '8.1'\n",
    "          php-version: '8.1'\n          token: \${{ secrets['PAT'] }}\n", 'jeton'];
$detournees['SECRETS.PAT en capitales dans build-org.yml'] = ['build-org.yml', "          php-version: '8.1'\n",
    "          php-version: '8.1'\n          token: \${{ SECRETS.PAT }}\n", 'jeton'];
$detournees['toJSON(secrets) dans ci.yml'] = ['ci.yml', "      - name: Bancs CLI (tests/*.php)\n",
    "      - name: Bancs CLI (tests/*.php)\n        env:\n          S: \${{ toJSON(secrets) }}\n", 'jeton'];
$detournees['GITHUB_TOKEN par github.token dans ci.yml'] = ['ci.yml', "      - name: Bancs CLI (tests/*.php)\n",
    "      - name: Bancs CLI (tests/*.php)\n        env:\n          GITHUB_TOKEN: \${{ github.token }}\n", 'jeton'];
$detournees['secrets: inherit sur un travail de ci.yml'] = ['ci.yml', "\njobs:\n",
    "\njobs:\n  autre:\n    uses: ./.github/workflows/x.yml\n    secrets: inherit\n", 'jeton'];
$detournees['secret dans la suite d\'une chaine citee qui commence par #'] = ['build-org.yml', "          php-version: '8.1'\n",
    "          php-version: '8.1'\n        env:\n          X: \"a\n            # \${{ secrets.PAT }}\"\n", 'jeton'];
$detournees['GH_TOKEN au premier niveau de release.yml'] = ['release.yml', "\nconcurrency:\n",
    "\nenv:\n  GH_TOKEN: \${{ github.token }}\n\nconcurrency:\n", 'jeton'];
// Correction 2 (regle de structure, b) : texte fige de publier et du garde.
$detournees['etape ajoutee a publier'] = ['release.yml', $avantJoindre,
    "      - name: x\n        run: echo x\n\n" . $avantJoindre, 'travail_fige'];
$detournees['commentaire change dans publier'] = ['release.yml', '      # E-34 : aucun second asset.',
    '      # E-34 : aucun second asset (modifie).', 'travail_fige'];
$detournees['etape apres un commentaire en colonne 0, a la fin de publier'] = ['release.yml',
    'release publiee dans un etat inattendu ($etat)"; exit 1; }',
    "release publiee dans un etat inattendu (\$etat)\"; exit 1; }\n# fin\n      - name: y\n        run: echo y", 'travail_fige'];
$detournees['second travail publier'] = ['release.yml', "\njobs:\n",
    "\njobs:\n  publier:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo x\n", 'travail_fige'];
$detournees['message d\'erreur du garde change'] = ['garde-release.yml', 'Relancez release.yml sur le tag',
    'Relancez release.yml sur son tag', 'travail_fige'];
$detournees['en-tete de garde-release.yml change (concurrency)'] = ['garde-release.yml', 'cancel-in-progress: false',
    'cancel-in-progress: true', 'travail_fige'];
$detournees['defaults: run: shell: au premier niveau de release.yml'] = ['release.yml', "\nconcurrency:\n",
    "\ndefaults:\n  run:\n    shell: bash -c 'echo x; bash {0}'\n\nconcurrency:\n", 'travail_fige'];
$detournees['env: BASH_ENV au premier niveau de ci.yml'] = ['ci.yml', "\njobs:\n",
    "\nenv:\n  BASH_ENV: ./x.sh\n\njobs:\n", 'travail_fige'];
// Correction 2 : PERMISSIONS_ATTENDUES, sur laquelle repose la regle, lit
// toutes les ecritures d'une cle de permissions et de travail.
$detournees['"permissions": write-all cite sur lint (ci.yml)'] = ['ci.yml', "  lint:\n    runs-on: ubuntu-latest\n",
    "  lint:\n    runs-on: ubuntu-latest\n    \"permissions\": write-all\n", 'forme_yaml'];
$detournees['permissions : write-all (blanc avant :) sur lint (ci.yml)'] = ['ci.yml', "  lint:\n    runs-on: ubuntu-latest\n",
    "  lint:\n    runs-on: ubuntu-latest\n    permissions : write-all\n", 'forme_yaml'];
$detournees['travail de plus, commentaire sur son en-tete (ci.yml)'] = ['ci.yml', "\njobs:\n",
    "\njobs:\n  autre: # x\n    runs-on: ubuntu-latest\n    permissions: write-all\n    steps:\n      - run: echo x\n", 'permissions'];

foreach ($detournees as $libelle => [$cible, $avant, $apres, $categorie]) {
    $copie = $reels;
    $texte = str_replace("\r\n", "\n", $copie[$cible] ?? '');
    $applique = $avant !== '' && substr_count($texte, $avant) === 1 && $avant !== $apres;
    $copie[$cible] = str_replace($avant, $apres, $texte);
    $vus = defauts_rc($copie);
    verifier("RC/RH : forme detournee rouge [$categorie] : $libelle", $applique && ($vus[$categorie] ?? []) !== [],
        $applique ? "categorie $categorie attendue, vues : " . implode(', ', array_keys(array_filter($vus))) : 'mutation non appliquee une seule fois');
}

// Controles positifs : la regle porte sur les commandes et evenements vises.
verifier('Correction 1 (R3) : un workflow manuel est aussi soumis a la liste d\'actions',
    actions_hors_liste("on: workflow_dispatch\njobs:\n  j:\n    steps:\n      - uses: actions/github-script@$shaMutation # v7\n") === ['actions/github-script']);
verifier('RH : commentaires de declencheur et de uses ignores',
    actions_hors_liste("on:\n  push:\n  # release:\njobs:\n  j:\n    steps:\n      # - uses: actions/github-script@$shaMutation # v7\n      - run: echo ok\n") === []);
verifier('RH : variable en argument, commande gh directe et commentaire de script acceptes',
    appels_release_variables("      - run: |\n          echo \"\$TAG\" release\n          gh release view \"\$TAG\"\n          # \"\$g\" release edit \"\$TAG\" --draft=false\n") === []);
verifier('Correction 1 : expansion ou substitution en argument acceptee (echo "${TAG:-x}" release)',
    appels_release_variables("      - run: |\n          echo \"\${TAG:-x}\" release\n          echo \$(date) release\n          x=\$(gh release view \"\$TAG\" --json isDraft)\n") === []);
verifier('Correction 1 : collections en flux en valeur et scripts lus comme du shell acceptes',
    formes_yaml_refusees("on:\n  push:\n    tags: ['v*']\n  release:\n    types: [published]\npermissions: {}\njobs:\n  j:\n    steps:\n      - name: a & b\n        with: {php-version: '8.1'}\n        run: |\n          [ \"\$x\" = y ] || exit 1\n          { echo a; } > f\n          - {pas: du yaml}\n      - run: >\n          [ x ]\n") === []);
foreach (["'run'", '"run"'] as $cle) {
    $copie = $reels;
    $copie['garde-release.yml'] = str_replace('run: |', "$cle: |", $copie['garde-release.yml'] ?? '');
    // Correction 1 (R6) : seules les categories visees sont lues, pour que
    // le controle positif ne depende pas des autres defauts.
    $vus = defauts_rc($copie);
    verifier("RH : garde avec cle $cle sans expression accepte", $copie['garde-release.yml'] !== $reels['garde-release.yml']
        && $vus['expression'] === [] && $vus['garde'] === [], implode('; ', array_merge($vus['expression'], $vus['garde'])));
}

// Correction 2 : controles positifs, toutes categories lues.
$positifs = [
    'runs-on: ubuntu-latest # os: > (build-org.yml)' => ['build-org.yml', "    runs-on: ubuntu-latest\n", "    runs-on: ubuntu-latest # os: >\n"],
    'nom d\'etape change dans build, hors travail fige (release.yml)' => ['release.yml', "      - name: Create zip\n", "      - name: Create the zip\n"],
    'blancs de fin de ligne dans publier (release.yml)' => ['release.yml', "    needs: build\n", "    needs: build  \n"],
];
foreach ($positifs as $libelle => [$cible, $avant, $apres]) {
    $copie = $reels;
    $texte = $copie[$cible] ?? '';
    $applique = substr_count($texte, $avant) === 1;
    $copie[$cible] = str_replace($avant, $apres, $texte);
    $vus = array_filter(defauts_rc($copie));
    verifier("Correction 2 : forme permise acceptee : $libelle", $applique && $vus === [],
        $applique ? implode('; ', array_map(static fn(array $v): string => implode(', ', $v), $vus)) : 'mutation non appliquee une seule fois');
}

echo "\n" . ($total - $echecs) . "/$total controles verts\n";
exit($echecs === 0 ? 0 : 1);
