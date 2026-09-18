<?php

namespace Pratcom\Connect\Bridge\Jobs;

use Pratcom\Connect\Bridge\Plugin;

/**
 * Le catalogue des offres publiques : la LECTURE de
 * `GET {base}/api/public/offers/{espace}` (route J4, contrat v1).
 *
 * ─── LE SENS EST UNIQUE ─────────────────────────────────────────────────────
 * Le CRM ecrit, WordPress lit. Cette classe ne fait que des GET ; elle
 * n'envoie jamais rien a l'API.
 *
 * ─── CE QUI EST GARDE, ET OU ────────────────────────────────────────────────
 *  - option `pratcom_connect_jobs_catalogue` (non autochargee) : la DERNIERE
 *    BONNE REPONSE, toutes pages et toutes langues reunies, avec `etag`,
 *    `generated_at` et `fetched_at` ;
 *  - transient `…_frais` (5 min) : tant qu'il vit, on ne rappelle pas la route ;
 *  - option `…_derniere_erreur` : la derniere panne, pour l'onglet Emplois.
 *    Elle n'est JAMAIS affichee au public.
 *
 * ─── LA REGLE QUI COMMANDE LE RESTE ─────────────────────────────────────────
 * Jamais une liste vide a cause d'une panne. Une liste vide ne sort d'ici que
 * si la route a rendu `count: 0` en 200. Sur 429, 5xx, 4xx ou erreur reseau,
 * la derniere bonne reponse continue d'etre servie, et le catalogue est dit
 * « pas sain » : la fiche d'une offre inconnue ne redirige alors pas, parce
 * qu'une offre publiee pendant la panne n'est pas une offre fermee.
 *
 * ─── L'ETAG ET LA PAGINATION ────────────────────────────────────────────────
 * L'empreinte de la route couvre `max(updated_at)` et le COMPTE de tout
 * l'ensemble servi (pas seulement de la page), plus `lang/limit/cursor`. Un
 * 304 sur la PREMIERE page dit donc que l'ensemble entier n'a pas bouge :
 * `If-None-Match` n'est envoye que sur elle. Le curseur est opaque et
 * renvoye TEL QUEL.
 */
final class Catalogue
{
    public const OPTION = 'pratcom_connect_jobs_catalogue';
    public const OPTION_ERREUR = 'pratcom_connect_jobs_catalogue_derniere_erreur';
    public const TRANSIENT_FRAIS = 'pratcom_connect_jobs_catalogue_frais';
    private const TRANSIENT_VERROU = 'pratcom_connect_jobs_catalogue_verrou';

    /** Duree de fraicheur (contrat de la mission : 5 min). */
    public const TTL = 300;

    /** Pause apres une panne, pour ne pas marteler une route qui souffre. */
    private const PAUSE_ERREUR = 60;

    /** Plafond de `Retry-After` honore (un 429 ne doit pas figer le site une heure). */
    private const PAUSE_MAX = 900;

    /** Taille de page demandee : le plafond du contrat. */
    private const LIMITE = 200;

    /** Garde-fou de pagination : 20 x 200 = 4 000 offres. */
    private const PAGES_MAX = 20;

    /** Memo par requete HTTP WordPress : une seule lecture, quoi qu'il arrive. */
    private static ?array $memo = null;

    /** Base de l'API (l'api, pas `chatbot.`), filtrable pour le staging. */
    public static function base_url(): string
    {
        return rtrim((string) apply_filters('pratcom_connect_jobs_base_url', 'https://connect.pratcom.net'), '/');
    }

    /** Slug de l'espace, tel que le handshake l'a pose. */
    public static function espace(): string
    {
        return (string) get_option(Plugin::OPTION_WORKSPACE_SLUG, '');
    }

    /**
     * L'etat du catalogue pour cette requete.
     *
     * @return array{offres: array<int, array<string, mixed>>, charge: bool, sain: bool, generated_at: string, fetched_at: int, etag: string, erreur: array<string, mixed>|null}
     */
    public static function etat(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $espace = self::espace();
        if ($espace !== '' && get_transient(self::TRANSIENT_FRAIS) !== $espace) {
            // Un seul rafraichissement a la fois : les requetes concurrentes
            // servent la derniere bonne reponse pendant ce temps. Sans
            // reponse gardee, il n'y a rien a servir : on lit quand meme.
            $garde = self::stockee($espace);
            if ($garde === null || get_transient(self::TRANSIENT_VERROU) === false) {
                set_transient(self::TRANSIENT_VERROU, 1, 30);
                self::rafraichir($espace, true);
                delete_transient(self::TRANSIENT_VERROU);
            }
        }

        return self::$memo = self::composer($espace);
    }

    /** Toutes les offres gardees (toutes langues). */
    public static function offres(): array
    {
        return self::etat()['offres'];
    }

    /**
     * Rechargement manuel (bouton de l'onglet Emplois) : oublie la fraicheur
     * et refait un GET complet, sans `If-None-Match`.
     */
    public static function recharger(): array
    {
        delete_transient(self::TRANSIENT_FRAIS);
        delete_transient(self::TRANSIENT_VERROU);
        self::$memo = null;
        $espace = self::espace();
        if ($espace !== '') {
            self::rafraichir($espace, false);
        } else {
            self::noter_erreur('workspace_absent', 0);
        }
        return self::$memo = self::composer($espace);
    }

    /** Remet le memo a zero (bancs, et apres un enregistrement de reglages). */
    public static function oublier_memo(): void
    {
        self::$memo = null;
    }

    /**
     * La derniere bonne reponse, si elle appartient a CET espace. Un
     * catalogue d'un autre espace (site reconnecte ailleurs) n'est jamais
     * servi.
     */
    private static function stockee(string $espace): ?array
    {
        $v = get_option(self::OPTION, null);
        if (!is_array($v) || $espace === '' || ($v['workspace'] ?? null) !== $espace || !is_array($v['offres'] ?? null)) {
            return null;
        }
        return $v;
    }

    private static function composer(string $espace): array
    {
        $garde = self::stockee($espace);
        $erreur = get_option(self::OPTION_ERREUR, null);
        $erreur = is_array($erreur) ? $erreur : null;
        $en_panne = $erreur !== null && empty($erreur['resolue']);

        return [
            'offres'       => $garde['offres'] ?? [],
            'charge'       => $garde !== null,
            'sain'         => $garde !== null && !$en_panne,
            'generated_at' => (string) ($garde['generated_at'] ?? ''),
            'fetched_at'   => (int) ($garde['fetched_at'] ?? 0),
            'etag'         => (string) ($garde['etag'] ?? ''),
            'erreur'       => $erreur,
        ];
    }

    /**
     * Un passage sur la route. Ne leve rien : toute panne est notee et la
     * derniere bonne reponse reste en place.
     */
    private static function rafraichir(string $espace, bool $conditionnel): void
    {
        $garde = self::stockee($espace);
        $etag = ($conditionnel && $garde !== null) ? (string) ($garde['etag'] ?? '') : '';

        $offres = [];
        $curseur = null;
        $premier_etag = '';
        $generated_at = '';

        for ($page = 0; $page < self::PAGES_MAX; $page++) {
            $url = self::base_url() . '/api/public/offers/' . rawurlencode($espace)
                . '?limit=' . self::LIMITE
                . ($curseur !== null ? '&cursor=' . rawurlencode($curseur) : '');

            $entetes = ['Accept' => 'application/json'];
            if ($page === 0 && $etag !== '') {
                $entetes['If-None-Match'] = $etag;
            }

            $rep = wp_remote_get($url, [
                'timeout'     => 6,
                'redirection' => 0,
                'headers'     => $entetes,
            ]);

            if (is_wp_error($rep)) {
                self::noter_erreur('reseau', 0, $rep->get_error_message());
                self::pause(self::PAUSE_ERREUR, $espace);
                return;
            }

            $code = (int) wp_remote_retrieve_response_code($rep);

            if ($code === 304 && $page === 0 && $garde !== null) {
                // Rien n'a bouge : on prolonge la fraicheur, les donnees restent.
                $garde['fetched_at'] = time();
                update_option(self::OPTION, $garde, false);
                self::resoudre_erreur();
                set_transient(self::TRANSIENT_FRAIS, $espace, self::TTL);
                return;
            }

            if ($code !== 200) {
                $corps = json_decode((string) wp_remote_retrieve_body($rep), true);
                $nom = (is_array($corps) && is_string($corps['error'] ?? null)) ? $corps['error'] : '';
                self::noter_erreur($nom !== '' ? $nom : 'http_' . $code, $code);
                $pause = self::PAUSE_ERREUR;
                if ($code === 429) {
                    $apres = (int) wp_remote_retrieve_header($rep, 'retry-after');
                    $pause = max(self::PAUSE_ERREUR, min(self::PAUSE_MAX, $apres));
                }
                self::pause($pause, $espace);
                return;
            }

            $corps = json_decode((string) wp_remote_retrieve_body($rep), true);
            if (!is_array($corps) || !is_array($corps['offers'] ?? null)) {
                self::noter_erreur('reponse_illisible', 200);
                self::pause(self::PAUSE_ERREUR, $espace);
                return;
            }

            if ($page === 0) {
                $premier_etag = (string) wp_remote_retrieve_header($rep, 'etag');
                $generated_at = (string) ($corps['generated_at'] ?? '');
            }

            foreach ($corps['offers'] as $o) {
                if (is_array($o) && is_string($o['id'] ?? null) && is_string($o['lang'] ?? null)) {
                    $offres[] = $o;
                }
            }

            $suivant = $corps['next_cursor'] ?? null;
            if (!is_string($suivant) || $suivant === '') {
                update_option(self::OPTION, [
                    'workspace'    => $espace,
                    'generated_at' => $generated_at,
                    'etag'         => $premier_etag,
                    'fetched_at'   => time(),
                    'count'        => count($offres),
                    'offres'       => $offres,
                ], false);
                self::resoudre_erreur();
                set_transient(self::TRANSIENT_FRAIS, $espace, self::TTL);
                return;
            }
            $curseur = $suivant;
        }

        // Plus de pages que le garde-fou : on garde l'ancienne reponse plutot
        // qu'une liste tronquee que rien ne distinguerait d'une vraie.
        self::noter_erreur('trop_de_pages', 200);
        self::pause(self::PAUSE_ERREUR, $espace);
    }

    private static function pause(int $secondes, string $espace): void
    {
        // Meme cle que la fraicheur : pendant la pause, on sert ce qu'on a.
        set_transient(self::TRANSIENT_FRAIS, $espace, $secondes);
    }

    private static function noter_erreur(string $code, int $http, string $detail = ''): void
    {
        update_option(self::OPTION_ERREUR, [
            'code'    => $code,
            'http'    => $http,
            'detail'  => substr($detail, 0, 200),
            'at'      => time(),
            'resolue' => false,
        ], false);
    }

    private static function resoudre_erreur(): void
    {
        $e = get_option(self::OPTION_ERREUR, null);
        if (is_array($e) && empty($e['resolue'])) {
            $e['resolue'] = true;
            update_option(self::OPTION_ERREUR, $e, false);
        }
    }
}
