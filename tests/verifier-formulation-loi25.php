<?php
/**
 * Banc CLI de la formulation « Loi 25 » (mission 20261123-1616).
 *
 *   php tests/verifier-formulation-loi25.php
 *
 * Regle : aucun texte servi ne promet la conformite (« conforme a la Loi 25 »,
 * « conformite Loi 25 », « Law 25 compliant », « compliant with Law 25 »,
 * « Law 25 compliance »). On decrit ce que l'outil fait et on dit qu'il
 * « aide a respecter les obligations de la Loi 25 ». Les mentions neutres
 * (« la Loi 25 », « vos obligations ») restent permises.
 *
 * Ce que le banc garde :
 *  1. Aucune formule interdite dans src/, languages/, docs/ et assets/
 *     (chaines .po/.pot recollees avant la recherche, pour attraper une
 *     formule coupee sur deux lignes).
 *  2. Le sous-titre de l'onglet Privacy existe dans PrivacyTab.php, dans le
 *     .pot, et il est traduit (meme anglais) dans chaque .po et .l10n.php.
 *
 * readme.txt n'est pas balaye : il porte la version et sa reecriture attend
 * la prochaine release (geste de Martin). Dossier tests/ : exclu des zips.
 */

$racine = dirname(__DIR__);
$echecs = [];
$ok = 0;
function verifier(string $nom, bool $condition): void
{
    global $echecs, $ok;
    if ($condition) {
        $ok++;
    } else {
        $echecs[] = $nom;
    }
}

$interdits = [
    '/conformes?\s+(?:a|à)\s+(?:la\s+)?loi\s*25/iu',
    '/conformit(?:e|é)\s+(?:(?:a|à)\s+(?:la\s+)?)?loi\s*25/iu',
    '/law\s*25\s+complian(?:t|ce)/iu',
    '/compliant\s+with\s+(?:quebec\'?s\s+)?law\s*25/iu',
];

$fichiers = [];
foreach (['src', 'languages', 'docs', 'assets'] as $dossier) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/' . $dossier, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && preg_match('/\.(php|po|pot|html|js|json|css|txt|md)$/', $f->getFilename())) {
            $fichiers[] = $f->getPathname();
        }
    }
}
verifier('des fichiers sont balayes', count($fichiers) > 10);

$recoller = static function (string $texte): string {
    // "abc "\n"def" (.po/.pot) devient "abc def".
    return preg_replace('/"\r?\n"/', '', $texte);
};

foreach ($fichiers as $chemin) {
    $texte = file_get_contents($chemin);
    if (preg_match('/\.pot?$/', $chemin)) {
        $texte = $recoller($texte);
    }
    $relatif = substr($chemin, strlen($racine) + 1);
    foreach ($interdits as $motif) {
        if (preg_match($motif, $texte, $m)) {
            echo "  formule interdite dans $relatif : {$m[0]}\n";
            verifier("aucune formule interdite dans $relatif", false);
            continue 2;
        }
    }
    $ok++;
}

// 2. Sous-titre de l'onglet Privacy et ses traductions.
$fr = 'Vous aide à respecter vos obligations de la Loi 25 : consentement, blocage des traceurs, registre et tableau de bord, au-delà de Privacy Free.';
$en = 'Helps you meet your Law 25 obligations: consent, tracker blocking, registry and dashboard, beyond Privacy Free.';

verifier('PrivacyTab.php porte le sous-titre FR', str_contains(file_get_contents($racine . '/src/Admin/Tabs/PrivacyTab.php'), "__('" . $fr . "'"));
$pot = $recoller(file_get_contents($racine . '/languages/pratcom-connect.pot'));
verifier('.pot porte le msgid', str_contains($pot, 'msgid "' . $fr . '"'));

foreach (glob($racine . '/languages/*.po') as $po) {
    $t = $recoller(file_get_contents($po));
    $t = preg_replace('/msg(id|str) ""\r?\n"/', 'msg$1 "', $t);
    verifier(basename($po) . ' traduit le sous-titre', str_contains($t, 'msgid "' . $fr . "\"\r\nmsgstr \"" . $en . '"') || str_contains($t, 'msgid "' . $fr . "\"\nmsgstr \"" . $en . '"'));
}
foreach (glob($racine . '/languages/*.l10n.php') as $l10n) {
    $table = require $l10n;
    verifier(basename($l10n) . ' traduit le sous-titre', ($table['messages'][$fr] ?? null) === $en);
}

echo "verifier-formulation-loi25 : $ok verifications reussies, " . count($echecs) . " echec(s)\n";
foreach ($echecs as $e) {
    echo "  ECHEC : $e\n";
}
exit($echecs ? 1 : 0);
