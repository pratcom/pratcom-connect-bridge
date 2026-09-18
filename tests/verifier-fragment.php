<?php
/**
 * Banc CLI de Privacy\FragmentDistant (le dépôt n'a ni PHPUnit ni Pest).
 *
 *   php tests/verifier-fragment.php --wp=/chemin/vers/wordpress
 *
 * --wp : racine d'un WordPress (dossier contenant wp-includes/), requise pour
 *        le cas 8 (wp_kses_post réel) et pour is_email. Sans elle, le cas 8
 *        est sauté et is_email est remplacé par filter_var.
 * --fragment=fichier.html --courriels=a@x,b@y : rejoue le cas 8 sur un
 *        fragment mesuré localement (jamais versionné : dépôt public).
 *
 * Dossier tests/ : exclu des deux zips (release.yml, build-org.yml).
 */

use Pratcom\Connect\Bridge\Privacy\FragmentDistant;

$options = getopt('', ['wp:', 'fragment:', 'courriels:']);

$wp = isset($options['wp']) ? rtrim((string) $options['wp'], '/\\') . '/' : null;
if ($wp !== null) {
    if (!is_file($wp . 'wp-includes/kses.php')) {
        fwrite(STDERR, "wp-includes/kses.php introuvable sous $wp\n");
        exit(2);
    }
    define('ABSPATH', $wp);
    define('WPINC', 'wp-includes');
    spl_autoload_register(static function (string $classe): void {
        $fichier = 'class-' . strtolower(str_replace('_', '-', $classe)) . '.php';
        foreach (['', 'html-api/'] as $dossier) {
            $chemin = ABSPATH . WPINC . '/' . $dossier . $fichier;
            if (is_file($chemin)) {
                require $chemin;
                return;
            }
        }
    });
    require ABSPATH . WPINC . '/plugin.php';
    require ABSPATH . WPINC . '/formatting.php';
    require ABSPATH . WPINC . '/functions.php';
    require ABSPATH . WPINC . '/kses.php';
}

require dirname(__DIR__) . '/src/Privacy/FragmentDistant.php';

/** Encodeur de référence (inverse du schéma Cloudflare), pour fabriquer les cas. */
function encoderCf(string $courriel, int $cle): string
{
    $hex = sprintf('%02x', $cle);
    foreach (str_split($courriel) as $c) {
        $hex .= sprintf('%02x', ord($c) ^ $cle);
    }
    return $hex;
}

$echecs = [];
$ok = 0;
$sautes = 0;
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
function montrer($valeur): string
{
    return json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$n = static fn(string $h): string => FragmentDistant::nettoyer($h);

// Script anti-bot tel que mesuré en fin de fragment (structure intacte ; les
// deux jetons r/t sont remplacés par des valeurs neutres de même forme).
$scriptCf = '<script>(function(){function c(){var b=a.contentDocument||(a.contentWindow&&a.contentWindow.document);if(b){var d=b.createElement(\'script\');d.innerHTML="window.__CF$cv$params={r:\'0000000000000000\',t:\'MDAwMDAwMDAwMA==\'};var a=document.createElement(\'script\');a.src=\'/cdn-cgi/challenge-platform/scripts/jsd/main.js\';document.getElementsByTagName(\'head\')[0].appendChild(a);";b.getElementsByTagName(\'head\')[0].appendChild(d)}}if(document.body){var a=document.createElement(\'iframe\');a.height=1;a.width=1;a.style.position=\'absolute\';a.style.top=0;a.style.left=0;a.style.border=\'none\';a.style.visibility=\'hidden\';document.body.appendChild(a);if(\'loading\'!==document.readyState)c();else if(window.addEventListener)document.addEventListener(\'DOMContentLoaded\',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);\'loading\'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script>';
$scriptDecode = '<script data-cfasync="false" src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script>';

$courriel = 'rh@exemple.ca';
$hex = encoderCf($courriel, 0x5a);
$ancre = static fn(string $h): string => '<a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="' . $h . '">[email&#160;protected]</a>';

// 1. Fragment propre → identique octet pour octet.
$propre = "<article class=\"pratcom-policy\"><h2>Nous joindre</h2>\n<p>Écrivez à <a href=\"mailto:rh@exemple.ca\">rh@exemple.ca</a>.</p>\n<table><tr><td>_ga</td></tr></table></article>";
verifier('1 fragment propre identique', $n($propre) === $propre, montrer($n($propre)));

// 2. <script> simple en fin de corps → retiré avec son texte. Idem <style> et
//    <noscript> : mesuré sur WordPress 7.1.1, wp_kses_post en garde le texte aussi.
$e2 = '<p>Fin.</p><script>alert("x")</script>';
$e2b = '<p>Fin.</p><style media="all">.x{display:none}</style><noscript>Activez JS</noscript>';
verifier(
    '2 script simple retiré (et style, noscript)',
    $n($e2) === '<p>Fin.</p>' && $n($e2b) === '<p>Fin.</p>',
    montrer([$n($e2), $n($e2b)])
);

// 3. Scripts réels mesurés (décodeur data-cfasync + anti-bot) → retirés.
$e3 = "<p>Fin.</p></article>" . $scriptDecode . $scriptCf;
$e3multi = "<p>Fin.</p><script type=\"text/javascript\" data-cfasync=\"false\">\nvar x = 1;\n</SCRIPT >";
verifier(
    '3 scripts réels (et variante multi-ligne) retirés',
    $n($e3) === '<p>Fin.</p></article>' && $n($e3multi) === '<p>Fin.</p>',
    montrer([$n($e3), $n($e3multi)])
);

// 4. Ancre email-protection valide → lien mailto (hex du banc ET hex encodé à la main).
//    Hex manuel : clé 0x42 ; a=61^42=23, @=40^42=02, b=62^42=20, .=2e^42=6c, c=63^42=21, o=6f^42=2d.
$e4 = '<p>RH : ' . $ancre($hex) . '.</p>';
verifier(
    '4 ancre data-cfemail décodée en mailto',
    $n($e4) === '<p>RH : <a href="mailto:rh@exemple.ca">rh@exemple.ca</a>.</p>'
        && FragmentDistant::decoderCfEmail('422302206c212d') === 'a@b.co',
    montrer([$n($e4), FragmentDistant::decoderCfEmail('422302206c212d')])
);

// 5. data-cfemail impair / non hexadécimal / décodé non-courriel → ancre retirée.
$mauvais = [substr($hex, 0, -1), 'zz' . substr($hex, 2), encoderCf('pas un courriel', 0x13), ''];
$sorties5 = array_map(static fn(string $h): string => $n('<p>A ' . $ancre($h) . ' B</p>'), $mauvais);
verifier(
    '5 hex invalide → ancre retirée, aucun [email résiduel',
    $sorties5 === array_fill(0, 4, '<p>A  B</p>'),
    montrer($sorties5)
);

// 6. <span class="__cf_email__"> → courriel nu ; variante lien mailto obfusqué (#HEX).
$e6 = '<p>Écrire : <span class="__cf_email__" data-cfemail="' . $hex . '">[email&#160;protected]</span></p>';
$e6b = '<p><a href="/cdn-cgi/l/email-protection#' . $hex . '"><span class="__cf_email__" data-cfemail="' . $hex . '">[email&#160;protected]</span></a></p>';
verifier(
    '6 span __cf_email__ → courriel nu (et lien #HEX → mailto)',
    $n($e6) === '<p>Écrire : rh@exemple.ca</p>'
        && $n($e6b) === '<p><a href="mailto:rh@exemple.ca">rh@exemple.ca</a></p>',
    montrer([$n($e6), $n($e6b)])
);

// 7. Idempotence sur les cas 2 à 6.
$entrees7 = array_merge([$e2, $e2b, $e3, $e3multi, $e4, $e6, $e6b], array_map(static fn(string $h): string => '<p>A ' . $ancre($h) . ' B</p>', $mauvais));
$idempotent = true;
foreach ($entrees7 as $x) {
    $idempotent = $idempotent && $n($n($x)) === $n($x);
}
verifier('7 idempotence nettoyer(nettoyer(x)) === nettoyer(x)', $idempotent);

// 8. Enchaînement réel wp_kses_post(nettoyer(fragment)).
if (!function_exists('wp_kses_post')) {
    $sautes++;
    echo "SAUTÉ  8 enchaînement wp_kses_post (passer --wp=)\n";
} else {
    if (isset($options['fragment'])) {
        $fragment = (string) file_get_contents((string) $options['fragment']);
        $attendus = array_filter(explode(',', (string) ($options['courriels'] ?? '')));
        $etiquette = '8 enchaînement réel (' . basename((string) $options['fragment']) . ')';
    } else {
        $attendus = ['rh@exemple.ca', 'droits@exemple.ca', 'info@exemple.ca'];
        $fragment = '<article class="pratcom-policy"><section><h2>Responsable</h2><p>Courriel : ' . $ancre(encoderCf($attendus[0], 0x12)) . '</p></section>'
            . '<section><h2>Vos droits</h2><p>Écrivez à ' . $ancre(encoderCf($attendus[1], 0xf9)) . '</p></section>'
            . '<section><h2>Nous joindre</h2><p>' . $ancre(encoderCf($attendus[2], 0xfe)) . '. Téléphone : 1-800-000-0000.</p></section>'
            . '</article>' . $scriptDecode . $scriptCf;
        $etiquette = '8 enchaînement wp_kses_post(nettoyer(fragment))';
    }
    $sortie = wp_kses_post($n($fragment));
    $manques = [];
    foreach (['cdn-cgi', '__CF$cv$params', '[email', 'function c()'] as $interdit) {
        if (strpos($sortie, $interdit) !== false) {
            $manques[] = "contient $interdit";
        }
    }
    foreach ($attendus as $attendu) {
        if (strpos($sortie, '<a href="mailto:' . $attendu . '">' . $attendu . '</a>') === false) {
            $manques[] = "mailto absent : $attendu";
        }
    }
    verifier($etiquette, $attendus !== [] && $manques === [], implode(' ; ', $manques));
}

$total = $ok + count($echecs) + $sautes;
echo "\n$ok/$total OK" . ($sautes ? ", $sautes sauté" : '') . ($echecs ? ' ; échecs : ' . implode(', ', $echecs) : '') . "\n";
exit($echecs ? 1 : ($sautes ? 2 : 0));
