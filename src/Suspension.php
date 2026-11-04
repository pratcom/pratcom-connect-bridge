<?php

namespace Pratcom\Connect\Bridge;

/**
 * Suspension d'un site, telle que le handshake la renvoie.
 *
 * L'API renvoie `suspension: { etat: "actif" | "suspendu", message_fr?,
 * message_en?, contact? }` et `next_check_at` (ISO 8601). Avant, les deux
 * etaient ignores : un site suspendu continuait de servir.
 *
 * Site suspendu : `loader.js` n'est plus insere et les codes courts du
 * plugin rendent une chaine vide pour le visiteur ; l'administrateur voit
 * une notice sobre dans wp-admin et une note discrete a la place du code
 * court. Le plugin reverifie a `next_check_at` (evenement cron unique), en
 * plus du handshake horaire.
 *
 * Etat absent ou inconnu : actif. Un serveur qui n'emet pas la cle ne doit
 * pas eteindre les sites (meme regle que `enabled` dans FeaturePacks).
 */
final class Suspension
{
    public const OPTION = 'pratcom_connect_bridge_suspension';
    public const OPTION_PROCHAINE = 'pratcom_connect_bridge_next_check_at';
    public const CRON_RECHECK = 'pratcom_connect_bridge_recheck';

    /** Codes courts du plugin, vides quand le site est suspendu. */
    public const CODES_COURTS = [
        'pratcom_form',
        'pratcom_emplois',
        'pratcom_privacy_policy',
        'pratcom_cookie_declaration',
    ];

    public function __construct()
    {
        add_filter('pre_do_shortcode_tag', [$this, 'filtrer_code_court'], 10, 2);
        add_action(self::CRON_RECHECK, [self::class, 'reverifier']);
    }

    public static function est_suspendu(): bool
    {
        return self::suspendu_dans(get_option(self::OPTION, []));
    }

    /** @param mixed $suspension */
    public static function suspendu_dans($suspension): bool
    {
        return is_array($suspension)
            && isset($suspension['etat'])
            && is_string($suspension['etat'])
            && strtolower(trim($suspension['etat'])) === 'suspendu';
    }

    /**
     * Enregistre ce que le handshake a renvoye et programme la reverification.
     * Seul l'etat est garde : les textes au visiteur ne sont pas affiches.
     *
     * @param mixed $suspension
     * @param mixed $prochaine  `next_check_at` brut.
     */
    public static function enregistrer($suspension, $prochaine): void
    {
        if (self::suspendu_dans($suspension)) {
            update_option(self::OPTION, ['etat' => 'suspendu']);
        } else {
            delete_option(self::OPTION);
        }

        $ts = self::horodatage($prochaine);
        if ($ts === null) {
            delete_option(self::OPTION_PROCHAINE);
            return;
        }
        update_option(self::OPTION_PROCHAINE, gmdate('c', $ts));
        self::programmer($ts);
    }

    /**
     * Horodatage de `next_check_at`, ou null s'il est absent ou illisible.
     *
     * @param mixed $prochaine
     */
    public static function horodatage($prochaine): ?int
    {
        if (!is_string($prochaine) || trim($prochaine) === '') {
            return null;
        }
        $ts = strtotime($prochaine);
        return $ts === false ? null : $ts;
    }

    private static function programmer(int $ts): void
    {
        $ts = max($ts, time() + 60);
        $existant = wp_next_scheduled(self::CRON_RECHECK);
        if ($existant) {
            if (abs($existant - $ts) < 60) {
                return;
            }
            wp_unschedule_event($existant, self::CRON_RECHECK);
        }
        wp_schedule_single_event($ts, self::CRON_RECHECK);
    }

    public static function deprogrammer(): void
    {
        $ts = wp_next_scheduled(self::CRON_RECHECK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_RECHECK);
        }
    }

    public static function reverifier(): void
    {
        (new HealthCheck())->run();
    }

    /**
     * @param false|string $retour
     * @param string       $tag
     * @return false|string
     */
    public function filtrer_code_court($retour, $tag)
    {
        if (!in_array($tag, self::CODES_COURTS, true) || !self::est_suspendu()) {
            return $retour;
        }
        return self::rendu_suspendu(current_user_can('manage_options'));
    }

    /** Rien pour le visiteur ; une note discrete pour l'administrateur. */
    public static function rendu_suspendu(bool $admin): string
    {
        if (!$admin) {
            return '';
        }
        return '<p class="pratcom-connect-suspendu">'
            . esc_html__('Pratcom Connect : ce site est suspendu, ce contenu n\'est pas affiché aux visiteurs.', 'pratcom-connect')
            . '</p>';
    }
}
