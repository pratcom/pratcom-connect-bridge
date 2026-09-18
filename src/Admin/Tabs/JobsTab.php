<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\FeaturePacks;
use Pratcom\Connect\Bridge\Jobs\Catalogue;
use Pratcom\Connect\Bridge\Jobs\Fiche;
use Pratcom\Connect\Bridge\Jobs\Module;
use Pratcom\Connect\Bridge\Jobs\Shortcode;
use Pratcom\Connect\Bridge\Jobs\Vocabulaire;
use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Privacy\Multilang;

/**
 * Onglet Emplois : l'etat du catalogue lu sur Connect, les pages hotes (une
 * par langue), la reprise des anciennes adresses `/jobs/`, et le rappel du
 * shortcode.
 *
 * Cet onglet ne publie rien : les offres s'ecrivent au CRM Connect. Il lit
 * la route publique et regle ou WordPress les affiche.
 */
class JobsTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-jobs';

    private const NONCE_SAVE = 'pratcom_connect_jobs_save';
    private const NONCE_RELOAD = 'pratcom_connect_jobs_reload';

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Emplois', 'pratcom-connect');
    }

    public function icon(): string
    {
        return 'businessperson';
    }

    public function register(): void
    {
        add_action('admin_post_pratcom_connect_jobs_save', [$this, 'handle_save']);
        add_action('admin_post_pratcom_connect_jobs_reload', [$this, 'handle_reload']);
    }

    public function render(): void
    {
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Emplois', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Les offres publiées dans le CRM Connect, affichées sur ce site : une liste, une fiche par offre et le balisage JobPosting pour Google.', 'pratcom-connect'); ?>
        </p>
        <?php
        $this->render_notice();
        $this->render_state();
        $this->render_settings();
        $this->render_shortcode();
    }

    private function render_notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule d'un parametre d'affichage.
        $notice = isset($_GET['pce_notice']) ? sanitize_key(wp_unslash($_GET['pce_notice'])) : '';
        $map = [
            'saved'    => ['success', __('Réglages enregistrés. Les permaliens ont été régénérés.', 'pratcom-connect')],
            'reloaded' => ['success', __('Catalogue rechargé.', 'pratcom-connect')],
            'failed'   => ['error', __('Le rechargement a échoué : la dernière bonne réponse reste affichée sur le site.', 'pratcom-connect')],
        ];
        if (!isset($map[$notice])) {
            return;
        }
        [$type, $text] = $map[$notice];
        ?>
        <div class="pc-notice pc-notice--<?php echo esc_attr($type); ?>"><?php echo esc_html($text); ?></div>
        <?php
    }

    private function render_state(): void
    {
        $connected = Plugin::is_connected();
        $pack = FeaturePacks::is_active('jobs');
        $etat = ($connected && $pack) ? Catalogue::etat() : null;
        $erreur = get_option(Catalogue::OPTION_ERREUR, null);
        ?>
        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('État', 'pratcom-connect'); ?></h2>
            <table class="pc-table">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Module Emplois', 'pratcom-connect'); ?></th>
                        <td>
                            <?php
                            if (!$connected) {
                                esc_html_e('Inactif : ce site n\'est pas connecté.', 'pratcom-connect');
                            } elseif (!$pack) {
                                esc_html_e('Inactif : le pack « jobs » n\'est pas activé pour ce workspace (refaire la vérification de connexion après l\'activation).', 'pratcom-connect');
                            } else {
                                esc_html_e('Actif', 'pratcom-connect');
                            }
                            ?>
                        </td>
                    </tr>
                    <?php if ($etat !== null): ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Dernier chargement', 'pratcom-connect'); ?></th>
                            <td>
                                <?php
                                if ($etat['fetched_at'] > 0) {
                                    echo esc_html(sprintf(
                                        /* translators: %s: human-readable time difference. */
                                        __('il y a %s', 'pratcom-connect'),
                                        human_time_diff($etat['fetched_at'])
                                    ));
                                } else {
                                    esc_html_e('Jamais', 'pratcom-connect');
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Offres au catalogue', 'pratcom-connect'); ?></th>
                            <td>
                                <?php
                                $parts = [];
                                foreach (Vocabulaire::LANGUES as $lang) {
                                    $parts[] = strtoupper($lang) . ' : ' . count(Module::offres_de($lang));
                                }
                                echo esc_html(count($etat['offres']) . ' (' . implode(', ', $parts) . ')');
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Empreinte (ETag)', 'pratcom-connect'); ?></th>
                            <td><code><?php echo esc_html($etat['etag'] !== '' ? substr(trim($etat['etag'], '"'), 0, 10) : '-'); ?></code></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dernière erreur', 'pratcom-connect'); ?></th>
                        <td>
                            <?php
                            if (is_array($erreur) && !empty($erreur['code'])) {
                                echo esc_html(sprintf(
                                    /* translators: 1: error code, 2: HTTP status, 3: time difference, 4: resolved or not. */
                                    __('%1$s (HTTP %2$s), il y a %3$s, %4$s', 'pratcom-connect'),
                                    (string) $erreur['code'],
                                    (string) ($erreur['http'] ?? '0'),
                                    human_time_diff((int) ($erreur['at'] ?? time())),
                                    !empty($erreur['resolue']) ? __('résolue depuis', 'pratcom-connect') : __('en cours', 'pratcom-connect')
                                ));
                            } else {
                                esc_html_e('Aucune', 'pratcom-connect');
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php if ($connected && $pack): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pc-actions" style="margin-top: 12px;">
                    <input type="hidden" name="action" value="pratcom_connect_jobs_reload" />
                    <?php wp_nonce_field(self::NONCE_RELOAD); ?>
                    <button type="submit" class="pc-btn pc-btn--secondary"><?php esc_html_e('Recharger le catalogue', 'pratcom-connect'); ?></button>
                </form>
                <p class="pc-form-help"><?php esc_html_e('Le site relit Connect au plus toutes les 5 minutes. Une offre publiée au CRM apparaît donc ici en 5 minutes au plus, ou tout de suite avec ce bouton.', 'pratcom-connect'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_settings(): void
    {
        $multilang = Multilang::is_active();
        ?>
        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Pages des offres', 'pratcom-connect'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pratcom_connect_jobs_save" />
                <?php wp_nonce_field(self::NONCE_SAVE); ?>

                <?php foreach (Vocabulaire::LANGUES as $lang): ?>
                    <div class="pc-form-field">
                        <label for="pce_page_<?php echo esc_attr($lang); ?>" class="pc-form-label">
                            <?php
                            echo esc_html($lang === 'fr'
                                ? __('Page hôte, offres en français', 'pratcom-connect')
                                : __('Page hôte, offres en anglais', 'pratcom-connect'));
                            ?>
                        </label>
                        <select id="pce_page_<?php echo esc_attr($lang); ?>" name="pce_page_<?php echo esc_attr($lang); ?>" class="pc-form-input">
                            <option value="0"><?php esc_html_e('Aucune', 'pratcom-connect'); ?></option>
                            <?php foreach ($this->pages_for($lang, $multilang) as $id => $title): ?>
                                <option value="<?php echo esc_attr((string) $id); ?>" <?php selected((int) get_option(Module::OPTION_PAGE_PREFIXE . $lang, 0), $id); ?>>
                                    <?php echo esc_html($title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                <p class="pc-form-help">
                    <?php esc_html_e('La page hôte porte le shortcode [pratcom_emplois]. Chaque offre y reçoit sa fiche : adresse de la page, puis le slug de l\'offre.', 'pratcom-connect'); ?>
                    <?php if ($multilang): ?>
                        <?php esc_html_e('Seules les pages de la bonne langue sont proposées.', 'pratcom-connect'); ?>
                    <?php endif; ?>
                </p>

                <div class="pc-form-field">
                    <label class="pc-form-label">
                        <input type="checkbox" name="pce_reprendre_jobs" value="1" <?php checked(Module::reprendre_jobs()); ?> />
                        <?php esc_html_e('Reprendre les anciennes adresses /jobs/', 'pratcom-connect'); ?>
                    </label>
                    <p class="pc-form-help">
                        <?php esc_html_e('Redirige (301) /jobs/{slug}/ vers la fiche correspondante, ou vers la liste si l\'offre n\'existe plus. À cocher seulement après avoir désactivé l\'ancienne extension d\'offres d\'emploi : tant qu\'elle tourne, ses adresses gardent la main.', 'pratcom-connect'); ?>
                    </p>
                </div>

                <div class="pc-form-field">
                    <label class="pc-form-label">
                        <input type="checkbox" name="pce_titre_theme" value="1" <?php checked((bool) get_option(Fiche::OPTION_TITRE_THEME, false)); ?> />
                        <?php esc_html_e('Le thème affiche déjà le titre de la page (ne pas répéter le titre de l\'offre dans la fiche)', 'pratcom-connect'); ?>
                    </label>
                    <p class="pc-form-help">
                        <?php esc_html_e('Laissez décochée si la fiche s\'affiche sans titre.', 'pratcom-connect'); ?>
                    </p>
                </div>

                <div class="pc-actions">
                    <button type="submit" class="pc-btn pc-btn--primary"><?php esc_html_e('Enregistrer', 'pratcom-connect'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    private function render_shortcode(): void
    {
        ?>
        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Shortcode', 'pratcom-connect'); ?></h2>
            <p><code>[<?php echo esc_html(Shortcode::TAG); ?>]</code></p>
            <p class="pc-form-help">
                <?php esc_html_e('Attributs facultatifs : lang="fr|en" (défaut : langue de la page), filtres="categorie,lieu,type,horaire,etiquette" (un filtre sans valeur n\'est pas affiché), limite="50", vide="Texte si aucune offre".', 'pratcom-connect'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Pages publiees proposees pour une langue. Avec WPML ou Polylang, seules
     * les pages de cette langue (ou sans langue connue) sont proposees.
     *
     * @return array<int, string>
     */
    private function pages_for(string $lang, bool $multilang): array
    {
        $pages = get_posts([
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'posts_per_page'   => 500,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
        $out = [];
        foreach ($pages as $p) {
            if ($multilang) {
                $page_lang = Module::langue_page_multilingue((int) $p->ID);
                if ($page_lang !== '' && strpos($page_lang, $lang) !== 0) {
                    continue;
                }
            }
            $uri = Module::chemin_page((int) $p->ID);
            $out[(int) $p->ID] = ($p->post_title !== '' ? $p->post_title : '#' . $p->ID) . ' (/' . $uri . '/)';
        }
        return $out;
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès refusé.', 'pratcom-connect'));
        }
        check_admin_referer(self::NONCE_SAVE);

        foreach (Vocabulaire::LANGUES as $lang) {
            $id = isset($_POST['pce_page_' . $lang]) ? absint(wp_unslash($_POST['pce_page_' . $lang])) : 0;
            $p = $id > 0 ? get_post($id) : null;
            if (!$p instanceof \WP_Post || $p->post_type !== 'page') {
                $id = 0;
            }
            update_option(Module::OPTION_PAGE_PREFIXE . $lang, $id, true);
        }
        update_option(Module::OPTION_REPRENDRE_JOBS, !empty($_POST['pce_reprendre_jobs']) ? 1 : 0, true);
        update_option(Fiche::OPTION_TITRE_THEME, !empty($_POST['pce_titre_theme']) ? 1 : 0, true);

        // Pas de flush ICI : cette requete a deja pose les regles des ANCIENS
        // reglages sur `init`, et un flush les graverait avec les nouvelles.
        // On invalide l'empreinte ; la requete suivante (la redirection
        // ci-dessous) pose les regles a jour et regenere une seule fois.
        update_option(Module::OPTION_REGLES, 'a-regenerer', true);

        $this->back('saved');
    }

    public function handle_reload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès refusé.', 'pratcom-connect'));
        }
        check_admin_referer(self::NONCE_RELOAD);

        $etat = Catalogue::recharger();
        $erreur = $etat['erreur'];
        $ok = $etat['charge'] && !(is_array($erreur) && empty($erreur['resolue']));
        $this->back($ok ? 'reloaded' : 'failed');
    }

    private function back(string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'pce_notice' => $notice],
            admin_url('admin.php')
        ));
        exit;
    }
}
