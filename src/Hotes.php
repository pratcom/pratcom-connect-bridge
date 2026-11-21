<?php

namespace Pratcom\Connect\Bridge;

/**
 * Source unique des hotes Pratcom que le plugin appelle.
 *
 * Avant cette classe, trois bases etaient codees en dur a plusieurs endroits :
 * `chatbot.` (formulaires, politique, temoins), `connect.` (offres d'emploi,
 * loader) et `api.connect.` (handshake). Elles passent toutes par ici.
 *
 * Trois roles :
 *   - `api`  : l'API Bridge (handshake, formulaires, sessions) ;
 *   - `app`  : l'application Connect (loader.js, catalogue des offres) ;
 *   - `chat` : le service chat (rendu des formulaires, politique, temoins).
 *
 * Les valeurs par defaut sont celles d'avant. Le handshake peut renvoyer
 * `hosts: { api, app, chat }` : elles sont alors enregistrees et remplacent
 * les defauts. Tant que le handshake ne renvoie rien, rien ne change.
 * Les filtres existants (`pratcom_connect_forms_loader_url`,
 * `pratcom_connect_policy_base_url`, `pratcom_connect_jobs_base_url`)
 * restent appliques par-dessus.
 *
 * Garde : seule une URL `https://` sans chemin ni port autre que 443 dont
 * l'hote est l'un des trois de DEFAUTS est retenue (la cle API part vers
 * `api` : une reponse alteree ne doit pas pouvoir l'envoyer ailleurs). Liste
 * fermee depuis E-21 : ni suffixe, ni filtre.
 */
final class Hotes
{
    public const OPTION = 'pratcom_connect_bridge_hosts';

    public const DEFAUTS = [
        'api'  => 'https://api.connect.pratcom.net',
        'app'  => 'https://connect.pratcom.net',
        'chat' => 'https://chatbot.pratcom.net',
    ];

    /**
     * E-21 : les seuls hotes permis, tires de DEFAUTS.
     *
     * @return array<int, string>
     */
    public static function hotes_permis(): array
    {
        $hotes = [];
        foreach (self::DEFAUTS as $url) {
            $hotes[] = strtolower((string) parse_url($url, PHP_URL_HOST));
        }
        return $hotes;
    }

    /** Base d'un role, sans barre finale. Role inconnu : base `app`. */
    public static function base(string $role): string
    {
        return self::base_dans(self::nettoyer(get_option(self::OPTION, [])), $role);
    }

    /**
     * Variante testable : meme regle, sur un tableau deja nettoye.
     *
     * @param array<string, string> $hotes
     */
    public static function base_dans(array $hotes, string $role): string
    {
        if (!isset(self::DEFAUTS[$role])) {
            $role = 'app';
        }
        return $hotes[$role] ?? self::DEFAUTS[$role];
    }

    /**
     * Enregistre les hotes recus du handshake. Rien de valide : l'option est
     * effacee et les defauts reprennent la main.
     *
     * @param mixed $brut Valeur `hosts` du handshake (absente : null).
     */
    public static function enregistrer($brut): void
    {
        $hotes = self::nettoyer($brut);
        if ($hotes === []) {
            delete_option(self::OPTION);
            return;
        }
        update_option(self::OPTION, $hotes);
    }

    /**
     * Garde les seuls roles connus dont l'URL est permise.
     *
     * @param mixed $brut
     * @return array<string, string>
     */
    public static function nettoyer($brut): array
    {
        if (!is_array($brut)) {
            return [];
        }
        $sortie = [];
        foreach (array_keys(self::DEFAUTS) as $role) {
            if (!isset($brut[$role]) || !is_string($brut[$role])) {
                continue;
            }
            $url = self::url_permise($brut[$role]);
            if ($url !== null) {
                $sortie[$role] = $url;
            }
        }
        return $sortie;
    }

    /**
     * `https://hote` si permise, sinon null. Chemin, requete, fragment et
     * identifiants sont refuses ; un port autre que 443 aussi (E-21).
     */
    public static function url_permise(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $parties = parse_url($url);
        if (!is_array($parties) || ($parties['scheme'] ?? '') !== 'https' || empty($parties['host'])) {
            return null;
        }
        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $interdit) {
            if (isset($parties[$interdit])) {
                return null;
            }
        }
        if (isset($parties['port']) && (int) $parties['port'] !== 443) {
            return null;
        }
        // E-21 : liste fermee, les trois hotes de DEFAUTS (ceux que sert le
        // handshake). Plus de suffixe `.pratcom.net`, plus de filtre : un
        // autre sous-domaine ne recoit jamais la cle API.
        $hote = strtolower($parties['host']);
        if (in_array($hote, self::hotes_permis(), true)) {
            return 'https://' . $hote;
        }
        return null;
    }
}
