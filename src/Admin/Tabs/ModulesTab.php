<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\FeaturePacks;
use Pratcom\Connect\Bridge\Hotes;
use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Admin\AdminShell;

/**
 * Onglet Modules. Contenu iso-fonctionnel au monolithe (O0).
 * La vitrine freemium (modules verrouilles + bouton Activer) arrive en O2.
 * Propriete : chantier Plugin .org.
 */
class ModulesTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-modules';

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Modules', 'pratcom-connect');
    }

    public function icon(): string
    {
        return 'admin-plugins';
    }

    public function render(): void
    {
        $connected = Plugin::is_connected();

        // Presentation des modules connus. La LISTE vient du handshake
        // (FeaturePacks::cles) : un module que le serveur renvoie et que ce
        // tableau ne decrit pas s'affiche quand meme, avec sa cle.
        $fiches = [
            'chat' => [
                'label'    => __('Connect Chat', 'pratcom-connect'),
                'short'    => 'C',
                'desc'     => __('Chatbot IA multilingue 24/7 avec base de connaissances RAG par client.', 'pratcom-connect'),
                'tab_slug' => 'pratcom-connect-chat',
            ],
            'forms' => [
                'label'    => __('Connect Forms', 'pratcom-connect'),
                'short'    => 'F',
                'desc'     => __('Formulaires intelligents avec scoring de leads et routage automatique.', 'pratcom-connect'),
                'tab_slug' => 'pratcom-connect-forms',
            ],
            'privacy' => [
                'label'    => __('Connect Privacy', 'pratcom-connect'),
                'short'    => 'P',
                'desc'     => __('Bannière de consentement Loi 25 et catalogue de témoins automatique.', 'pratcom-connect'),
                'tab_slug' => 'pratcom-connect-privacy',
            ],
            'jobs' => [
                'label'    => __('Connect Emplois', 'pratcom-connect'),
                'short'    => 'E',
                'desc'     => __('Offres d\'emploi publiées sur vos pages, avec fiches et données structurées pour Google.', 'pratcom-connect'),
                'tab_slug' => JobsTab::PAGE_SLUG,
            ],
        ];
        $modules = [];
        foreach (FeaturePacks::cles() as $cle) {
            $modules[$cle] = $fiches[$cle] ?? [
                'label'    => 'Connect ' . ucfirst($cle),
                'short'    => strtoupper(substr($cle, 0, 1)),
                'desc'     => '',
                'tab_slug' => '',
            ];
        }
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Modules', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Modules Pratcom Connect disponibles sur ce site WordPress.', 'pratcom-connect'); ?>
        </p>

        <div class="pc-modules-grid">
            <?php foreach ($modules as $key => $mod):
                $is_active = FeaturePacks::is_active($key);

                if ($is_active) {
                    $badge_class = 'active';
                    $badge_label = __('Actif', 'pratcom-connect');
                    $note = __('Géré via votre compte Pratcom Connect.', 'pratcom-connect');
                } else {
                    // Vitrine O2 : module verrouille, upsell conforme .org
                    // (dans NOS pages uniquement, jamais de notice globale).
                    $badge_class = 'locked';
                    $badge_label = __('Verrouillé', 'pratcom-connect');
                    $note = $connected
                        ? __('Disponible avec un abonnement Pratcom Connect.', 'pratcom-connect')
                        : __('Connectez votre compte pour activer ce module.', 'pratcom-connect');
                }
                ?>
                <article class="pc-module-card <?php echo $is_active ? '' : 'pc-module-card--locked'; ?>">
                    <div class="pc-module-card__header">
                        <div class="pc-module-card__icon"><?php echo esc_html($mod['short']); ?></div>
                        <h3 class="pc-module-card__title"><?php echo esc_html($mod['label']); ?></h3>
                        <span class="pc-module-card__badge pc-module-card__badge--<?php echo esc_attr($badge_class); ?>">
                            <?php echo esc_html($badge_label); ?>
                        </span>
                    </div>
                    <p class="pc-module-card__desc"><?php echo esc_html($mod['desc']); ?></p>
                    <p class="pc-module-card__note"><?php echo esc_html($note); ?></p>
                    <?php if ($is_active && $mod['tab_slug'] !== ''): ?>
                        <div class="pc-actions pc-module-card__cta">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $mod['tab_slug'])); ?>" class="pc-btn pc-btn--secondary">
                                <?php esc_html_e('Ouvrir', 'pratcom-connect'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if (!$is_active): ?>
                        <div class="pc-actions pc-module-card__cta">
                            <?php if ($connected): ?>
                                <a href="<?php echo esc_url(Hotes::base('app') . '/?utm_source=wp-plugin&utm_medium=modules&module=' . $key); ?>"
                                   target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                                    <?php esc_html_e('Activer ce module', 'pratcom-connect'); ?>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . ConnectionTab::PAGE_SLUG)); ?>" class="pc-btn pc-btn--primary">
                                    <?php esc_html_e('Connecter mon compte', 'pratcom-connect'); ?>
                                </a>
                                <?php if ($mod['tab_slug'] !== ''): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $mod['tab_slug'])); ?>" class="pc-btn pc-btn--secondary">
                                    <?php
                                    /* translators: %s: module name, e.g. "Connect Chat". */
                                    echo esc_html(sprintf(__('C\'est quoi %s', 'pratcom-connect'), $mod['label']));
                                    ?>
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(AdminShell::marketing_url($key . '/')); ?>"
                               target="_blank" rel="noopener" class="pc-btn pc-btn--ghost">
                                <?php
                                /* translators: %s: module name, e.g. "Connect Chat". */
                                echo esc_html(sprintf(__('Découvrir %s', 'pratcom-connect'), $mod['label']));
                                ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
