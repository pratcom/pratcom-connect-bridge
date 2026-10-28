<?php
/**
 * Banc CLI de Suspension (mission M4), sans WordPress : fonctions WP simulees.
 *
 *   php tests/verifier-suspension.php
 *
 * Dossier tests/ : exclu des deux zips (release.yml, build-org.yml).
 */

namespace {
    $GLOBALS['opts'] = [];
    $GLOBALS['admin'] = false;
    $GLOBALS['peut'] = false;
    function add_action(...$a) {}
    function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
    function update_option($k, $v, $auto = null) { $GLOBALS['opts'][$k] = $v; return true; }
    function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
    function sanitize_text_field($s) { return trim(strip_tags($s)); }
    function sanitize_email($s) { return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : ''; }
    function esc_url_raw($s) { return preg_match('#^https?://#', $s) ? $s : ''; }
    function esc_url($s) { return htmlspecialchars($s, ENT_QUOTES); }
    function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES); }
    function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
    function is_admin() { return $GLOBALS['admin']; }
    function wp_doing_ajax() { return false; }
    function wp_doing_cron() { return false; }
    function current_user_can($c) { return $GLOBALS['peut']; }
    require __DIR__ . '/../src/Suspension.php';
}

namespace Banc {
    use Pratcom\Connect\Bridge\Suspension;

    $n = 0;
    $ok = function (bool $c, string $m) use (&$n) {
        if (!$c) { fwrite(STDERR, "ECHEC : $m\n"); exit(1); }
        $n++;
        echo "  PASS  $m\n";
    };

    $ok(!Suspension::doitSuspendre(), 'sans etat : le site est actif');
    Suspension::enregistrer(['etat' => 'suspendu', 'message_fr' => 'Ce site est suspendu.', 'message_en' => 'This site is suspended.', 'contact' => ['nom' => 'Pratcom Media', 'courriel' => 'facturation@pratcom.net', 'web' => 'https://pratcom.net']]);
    $ok(Suspension::etat() !== null, 'suspendu : l\'etat est garde dans une option');
    $ok(Suspension::doitSuspendre(), 'suspendu : un visiteur recoit la page');
    $GLOBALS['peut'] = true;
    $ok(!Suspension::doitSuspendre(), 'un administrateur connecte voit le site');
    $GLOBALS['peut'] = false;
    $GLOBALS['admin'] = true;
    $ok(!Suspension::doitSuspendre(), 'l\'administration WordPress reste ouverte');
    $GLOBALS['admin'] = false;
    $GLOBALS['pagenow'] = 'wp-login.php';
    $ok(!Suspension::doitSuspendre(), 'la page de connexion reste ouverte');
    unset($GLOBALS['pagenow']);
    $fr = Suspension::page(Suspension::etat(), 'fr_CA');
    $en = Suspension::page(Suspension::etat(), 'en_US');
    $ok(strpos($fr, 'Service suspendu') !== false && strpos($fr, 'lang="fr"') !== false, 'page en francais selon la langue du site');
    $ok(strpos($en, 'Service suspended') !== false && strpos($en, 'lang="en"') !== false, 'page en anglais selon la langue du site');
    $ok(strpos($fr, 'facturation@pratcom.net') !== false && strpos($fr, 'noindex') !== false, 'coordonnees de Pratcom, noindex');
    $ok(!preg_match('/PC-|\$|montant|amount/i', $fr . $en), 'aucun detail de facturation');
    $ok(!preg_match('/\x{2013}|\x{2014}/u', $fr . $en), 'aucun tiret cadratin ni demi-cadratin');
    Suspension::enregistrer(['etat' => 'suspendu', 'message_fr' => '<script>x</script>Texte', 'message_en' => 'T', 'contact' => ['courriel' => 'pas un courriel', 'web' => 'javascript:alert(1)']]);
    $p = Suspension::page(Suspension::etat(), 'fr_CA');
    $ok(strpos($p, '<script>') === false && strpos($p, 'javascript:') === false, 'contenu recu nettoye');
    Suspension::enregistrer(['etat' => 'actif']);
    $ok(Suspension::etat() === null && !Suspension::doitSuspendre(), 'actif : tout redevient normal');
    Suspension::enregistrer(['etat' => 'suspendu', 'message_fr' => 'a', 'message_en' => 'b']);
    Suspension::enregistrer(null);
    $ok(Suspension::etat() === null, 'poignee de main sans champ (ancienne API) : actif');
    echo "\n$n controles verts.\n";
}
