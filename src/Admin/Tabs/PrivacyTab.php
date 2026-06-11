<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;
use Pratcom\Connect\Bridge\Privacy\FreeBanner;
use Pratcom\Connect\Bridge\Privacy\LocalRegistry;
use Pratcom\Connect\Bridge\Privacy\PolicyPage;
use Pratcom\Connect\Bridge\Privacy\Presets;

/**
 * Onglet Confidentialité — Privacy Free (spec .org §3-4, O3).
 *
 * CONTENU : zone du chantier Privacy / Plugin .org.
 * DÉPENDANCES : FreeBanner, Presets, PolicyPage, LocalRegistry (tous mergés).
 * PATTERN : fichier neuf petit (leçon #4) — jamais d'édition inline du monolithe.
 *
 * Sections :
 *   ① Toggle FreeBanner::OPTION_ENABLED + avertissement module Connect actif.
 *   ② Bandeau de suggestions Presets::suggested() (détection passive locale).
 *   ③ Cases à cocher Presets::all() groupées par provenance (kind) puis catégorie.
 *   ④ Bouton « Créer la page » — PolicyPage::render_admin_section().
 *   ⑤ Export CSV consentements — LocalRegistry::EXPORT_ACTION.
 *   ⑥ (O5b) Section « Privacy Connect » — iframe scan si pack privacy actif.
 */
class PrivacyTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-privacy';

    private const NONCE_SETTINGS = 'pratcom_privacy_free_save_settings';
    private const ACTION_SAVE    = 'pratcom_connect_bridge_save_privacy_free';

    // ─── AbstractTab ─────────────────────────────────────────────────────────

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Confidentialité', 'pratcom-connect');
    }

    public function icon(): string
    {
        return 'shield';
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handle_save']);
    }

    // ─── Handler admin-post ──────────────────────────────────────────────────

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission refusée.', 'pratcom-connect'));
        }
        check_admin_referer(self::NONCE_SETTINGS);

        // ① Toggle bannière Free.
        $enabled = !empty($_POST['privacy_free_enabled']) ? '1' : '';
        update_option(FreeBanner::OPTION_ENABLED, $enabled);

        // ② Presets sélectionnés : valider contre la liste connue.
        $valid_ids = array_map(
            static fn(array $p): string => (string) ($p['id'] ?? ''),
            Presets::all()
        );
        $raw = (isset($_POST['privacy_presets']) && is_array($_POST['privacy_presets']))
            ? array_map('sanitize_key', array_map('sanitize_text_field', wp_unslash((array) $_POST['privacy_presets'])))
            : [];
        $selected = array_values(array_filter(
            $raw,
            static fn(string $id): bool => $id !== '' && in_array($id, $valid_ids, true)
        ));
        update_option(Presets::OPTION_SELECTED, $selected);

        $this->redirect_with_notice(self::PAGE_SLUG, 'privacy_saved', '');
    }

    // ─── Rendu ──────────────────────────────────────────────────────────────

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $banner_enabled = (bool) get_option(FreeBanner::OPTION_ENABLED);
        $selected_ids   = (array) get_option(Presets::OPTION_SELECTED, []);
        $all_presets    = Presets::all();
        $suggestions    = Presets::suggested();   // string[] — ids détectés passivement
        $is_connected   = Plugin::is_connected();

        // O5b : vérifier si le pack privacy est actif.
        $packs               = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        $privacy_pack_active = $is_connected && is_array($packs) && !empty($packs['privacy']['enabled']);

        // Grouper par provenance (kind) puis catégorie.
        // kind absent = 'service' (rétrocompatible).
        $by_kind = [];
        foreach ($all_presets as $preset) {
            $kind = (string) ($preset['kind'] ?? 'service');
            $cat  = (string) ($preset['category'] ?? 'unclassified');
            $by_kind[$kind][$cat][] = $preset;
        }
        // Ordre fixe : extensions WordPress d'abord, services externes ensuite.
        $by_kind_ordered = [];
        foreach (['plugin', 'service'] as $k) {
            if (!empty($by_kind[$k])) {
                $by_kind_ordered[$k] = $by_kind[$k];
            }
        }
        // Conserver d'éventuels kinds futurs non prévus.
        foreach ($by_kind as $k => $v) {
            if (!array_key_exists($k, $by_kind_ordered)) {
                $by_kind_ordered[$k] = $v;
            }
        }

        // Notice de sauvegarde : AdminShell::render_notices() ne connaît pas
        // 'privacy_saved' → on la gère ici (il passe silencieusement).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture seule d'un parametre de notice (affichage).
        $notice = isset($_GET['pratcom_notice'])
            ? sanitize_key(wp_unslash($_GET['pratcom_notice']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Confidentialité', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Privacy Free : bannière de consentement locale (Loi 25), presets de plugins populaires, registre de consentements et page de politique.', 'pratcom-connect'); ?>
        </p>

        <?php if ($notice === 'privacy_saved'): ?>
        <div class="pc-notice pc-notice--success">
            <?php esc_html_e('Réglages de confidentialité enregistrés.', 'pratcom-connect'); ?>
        </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SAVE); ?>" />
            <?php wp_nonce_field(self::NONCE_SETTINGS); ?>

            <!-- ① Bannière Free ------------------------------------------------->
            <div class="pc-card">
                <h2 class="pc-card__title">
                    <?php esc_html_e('Bannière de consentement Free', 'pratcom-connect'); ?>
                </h2>

                <?php if ($is_connected): ?>
                <div class="pc-notice pc-notice--info" style="margin-bottom:14px;">
                    ⚠&nbsp;<?php esc_html_e(
                        'Ne pas activer si le module Privacy Connect est déjà actif sur ce site — les deux bannières ne doivent pas coexister.',
                        'pratcom-connect'
                    ); ?>
                </div>
                <?php endif; ?>

                <label class="pc-form-toggle" style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                    <input type="checkbox" name="privacy_free_enabled" value="1"
                           <?php checked($banner_enabled); ?>
                           style="width:18px;height:18px;cursor:pointer;" />
                    <span><?php esc_html_e('Activer la bannière Privacy Free (100 % locale, zéro appel serveur)', 'pratcom-connect'); ?></span>
                </label>
                <p class="pc-form-help">
                    <?php esc_html_e('La bannière utilise les presets cochés ci-dessous pour bloquer les scripts non essentiels avant le consentement du visiteur.', 'pratcom-connect'); ?>
                </p>
            </div><!-- /.pc-card -->

            <!-- ② Suggestions (détection passive) -------------------------------->
            <?php
            // Filtrer : seulement les presets détectés qui ne sont pas déjà cochés.
            $unseen_suggestions = array_values(array_filter(
                $suggestions,
                static fn(string $id): bool => !in_array($id, $selected_ids, true)
            ));
            if (!empty($unseen_suggestions)):
                // Résoudre les noms.
                $preset_names = [];
                foreach ($all_presets as $p) {
                    $preset_names[(string) ($p['id'] ?? '')] = (string) ($p['name'] ?? '');
                }
            ?>
            <div class="pc-card pc-card--highlight" style="border-left:4px solid var(--pc-color-primary, #377ba6);">
                <h2 class="pc-card__title">
                    💡&nbsp;<?php esc_html_e('Presets détectés sur ce site', 'pratcom-connect'); ?>
                </h2>
                <p style="margin:0 0 10px;">
                    <?php esc_html_e('Ces plugins sont actifs — leurs presets ne sont pas encore activés :', 'pratcom-connect'); ?>
                </p>
                <ul style="margin:0 0 10px;padding-left:20px;">
                    <?php foreach ($unseen_suggestions as $sid): ?>
                    <li><strong><?php echo esc_html($preset_names[$sid] ?? $sid); ?></strong></li>
                    <?php endforeach; ?>
                </ul>
                <p class="pc-form-help">
                    <?php esc_html_e('Cochez les presets correspondants dans la section ci-dessous, puis enregistrez.', 'pratcom-connect'); ?>
                </p>
            </div><!-- /.pc-card.pc-card--highlight -->
            <?php endif; ?>

            <!-- ③ Presets groupés par provenance (kind) puis catégorie ----------->
            <div class="pc-card">
                <h2 class="pc-card__title">
                    <?php esc_html_e('Presets de plugins populaires', 'pratcom-connect'); ?>
                </h2>
                <p class="pc-form-help" style="margin-bottom:18px;">
                    <?php esc_html_e('Cochez les outils que vous utilisez. Le plugin configure automatiquement les catégories de cookies, les règles de blocage et la déclaration des témoins.', 'pratcom-connect'); ?>
                </p>

                <?php
                $kind_labels = [
                    'plugin'  => __('Extensions WordPress populaires', 'pratcom-connect'),
                    'service' => __('Services et scripts externes', 'pratcom-connect'),
                ];

                $category_labels = [
                    'statistics'   => __('Statistiques', 'pratcom-connect'),
                    'marketing'    => __('Marketing', 'pratcom-connect'),
                    'necessary'    => __('Nécessaires (non bloqués par défaut)', 'pratcom-connect'),
                    'preferences'  => __('Préférences', 'pratcom-connect'),
                    'functional'   => __('Fonctionnel', 'pratcom-connect'),
                    'unclassified' => __('Autre', 'pratcom-connect'),
                ];

                foreach ($by_kind_ordered as $kind => $by_category):
                    $kind_label = $kind_labels[$kind] ?? ucfirst($kind);
                ?>
                <div class="pc-kind-group" style="margin-bottom:28px;">
                    <h3 style="font-size:14px;font-weight:700;color:var(--pc-text,#1d2b36);border-bottom:2px solid var(--pc-color-primary,#377ba6);padding-bottom:6px;margin:0 0 14px;">
                        <?php echo esc_html($kind_label); ?>
                    </h3>

                    <?php foreach ($by_category as $cat => $presets):
                        $cat_label = $category_labels[$cat] ?? ucfirst($cat);
                    ?>
                    <fieldset class="pc-preset-group" style="border:none;padding:0;margin:0 0 20px;">
                        <legend style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--pc-text-muted,#5a6a75);padding:0;margin:0 0 8px;">
                            <?php echo esc_html($cat_label); ?>
                        </legend>
                        <div class="pc-preset-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;">
                            <?php foreach ($presets as $preset):
                                $pid          = (string) ($preset['id'] ?? '');
                                $pname        = (string) ($preset['name'] ?? $pid);
                                $desc_fr      = (string) ($preset['description']['fr'] ?? $preset['description']['en'] ?? '');
                                $is_checked   = in_array($pid, $selected_ids, true);
                                $is_suggested = in_array($pid, $suggestions, true);
                                $is_necessary = !($preset['blockedByDefault'] ?? true);
                            ?>
                            <label class="pc-preset-item <?php echo $is_suggested ? 'pc-preset-item--suggested' : ''; ?>"
                                   style="display:flex;flex-direction:column;gap:3px;padding:10px 12px;border:1px solid <?php echo $is_checked ? 'var(--pc-color-primary,#377ba6)' : '#d0d7dc'; ?>;border-radius:8px;cursor:pointer;background:<?php echo $is_checked ? 'rgba(55,123,166,.07)' : '#fff'; ?>;">
                                <span style="display:flex;align-items:center;gap:7px;">
                                    <input type="checkbox"
                                           name="privacy_presets[]"
                                           value="<?php echo esc_attr($pid); ?>"
                                           <?php checked($is_checked); ?>
                                           style="width:15px;height:15px;cursor:pointer;flex-shrink:0;" />
                                    <strong style="font-size:13px;line-height:1.3;">
                                        <?php echo esc_html($pname); ?>
                                    </strong>
                                    <?php if ($is_suggested): ?>
                                    <span style="font-size:10px;padding:1px 6px;border-radius:10px;background:var(--pc-color-primary,#377ba6);color:#fff;white-space:nowrap;">
                                        <?php esc_html_e('Actif ici', 'pratcom-connect'); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($is_necessary): ?>
                                    <span style="font-size:10px;padding:1px 6px;border-radius:10px;background:#e8f5e9;color:#1a7f37;white-space:nowrap;">
                                        <?php esc_html_e('Nécessaire', 'pratcom-connect'); ?>
                                    </span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($desc_fr): ?>
                                <span style="font-size:11px;color:var(--pc-text-muted,#5a6a75);line-height:1.4;padding-left:22px;">
                                    <?php echo esc_html($desc_fr); ?>
                                </span>
                                <?php endif; ?>
                            </label>
                            <?php endforeach; ?>
                        </div><!-- /.pc-preset-grid -->
                    </fieldset>
                    <?php endforeach; ?>

                </div><!-- /.pc-kind-group -->
                <?php endforeach; ?>

            </div><!-- /.pc-card (presets) -->

            <div class="pc-actions" style="margin-bottom:24px;">
                <button type="submit" class="pc-btn pc-btn--primary">
                    <?php esc_html_e('Enregistrer les réglages', 'pratcom-connect'); ?>
                </button>
            </div>
        </form>

        <!-- ④ Page de politique ─────────────────────────────────────────────-->
        <div class="pc-card" style="margin-top:0;">
            <?php PolicyPage::render_admin_section(); ?>
        </div>

        <!-- ⑤ Export CSV registre ───────────────────────────────────────────-->
        <div class="pc-card" style="margin-top:24px;">
            <h2 class="pc-card__title">
                <?php esc_html_e('Registre de consentements', 'pratcom-connect'); ?>
            </h2>
            <p>
                <?php esc_html_e(
                    "Exportez la preuve d'audit des consentements enregistrés (Loi 25, art. 14). Le fichier CSV contient tous les enregistrements depuis l'activation de la bannière.",
                    'pratcom-connect'
                ); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(LocalRegistry::EXPORT_ACTION); ?>" />
                <?php wp_nonce_field(LocalRegistry::EXPORT_ACTION); ?>
                <button type="submit" class="pc-btn pc-btn--secondary">
                    <?php esc_html_e('Exporter CSV', 'pratcom-connect'); ?>
                </button>
            </form>
        </div><!-- /.pc-card (export) -->

        <?php
        // ⑥ O5b : section Privacy Connect (iframe scan) — additif, pack requis.
        if ($privacy_pack_active) {
            $this->render_connected_section();
        }
    }

    // ─── O5b : section Privacy Connect iframe (additif) ──────────────────────

    /**
     * Section « Privacy Connect » — scan de confidentialité dans une iframe signée.
     * Visible uniquement si le site est connecté ET le pack privacy est actif.
     * Privacy Free (sections ①-⑤) reste intouché.
     */
    private function render_connected_section(): void
    {
        $key = Plugin::get_api_key();
        if (!$key) {
            return;
        }

        $res = (new ApiClient())->get_privacy_session($key);

        if (!($res['ok'] ?? false) || empty($res['url'])) {
            $code = $res['error'] ?? 'unavailable';
            ?>
            <div class="pc-card" style="margin-top:24px;">
                <h2 class="pc-card__title"><?php esc_html_e('Privacy Connect — Scan de confidentialité', 'pratcom-connect'); ?></h2>
                <div class="pc-notice pc-notice--warning" style="margin-bottom:12px;">
                    <?php echo esc_html(
                        sprintf(
                            /* translators: %s: error code */
                            __('Le tableau de bord Privacy Connect est momentanement indisponible. (%s)', 'pratcom-connect'),
                            $code
                        )
                    ); ?>
                </div>
                <div class="pc-actions">
                    <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=privacy-scan"
                       target="_blank" rel="noopener" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e("Ouvrir l'interface web", 'pratcom-connect'); ?>
                    </a>
                </div>
            </div>
            <?php
            return;
        }

        $src = esc_url($res['url']);
        $crm_url = '';
        if (!empty($res['workspace_slug'])) {
            $crm_url = 'https://connect.pratcom.net/crm/' . rawurlencode((string) $res['workspace_slug']) . '/privacy';
        }
        ?>
        <div class="pc-card pc-embed-card" style="margin-top:24px;padding:0;overflow:hidden;">
            <div class="pc-embed-header">
                <span class="pc-embed-header__label">
                    <?php esc_html_e('Privacy Connect — Scan de confidentialité', 'pratcom-connect'); ?>
                </span>
                <?php if ($crm_url): ?>
                <a href="<?php echo esc_url($crm_url); ?>" target="_blank" rel="noopener"
                   class="pc-embed-header__link">
                    <?php esc_html_e("Ouvrir en plein ecran \u{2197}", 'pratcom-connect'); ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="pc-embed-wrap">
                <iframe
                    src="<?php echo esc_url($src); ?>"
                    class="pc-embed-frame pc-embed-frame--short"
                    sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
                    allow="clipboard-write"
                    loading="lazy"
                    title="<?php esc_attr_e('Scan de confidentialité Pratcom Connect', 'pratcom-connect'); ?>"
                ></iframe>
            </div>
        </div>
        <?php
    }
}
