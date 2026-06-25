<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;
use Pratcom\Connect\Bridge\Admin\OrgManagePanel;
use Pratcom\Connect\Bridge\Admin\AdminShell;
use Pratcom\Connect\Bridge\Admin\ModuleShowcase;
use Pratcom\Connect\Bridge\Privacy\FreeBanner;
use Pratcom\Connect\Bridge\Privacy\LocalRegistry;
use Pratcom\Connect\Bridge\Privacy\LocalPolicy;
use Pratcom\Connect\Bridge\Privacy\PolicyPage;
use Pratcom\Connect\Bridge\Privacy\CookiePolicyPage;
use Pratcom\Connect\Bridge\Privacy\CookieScan;
use Pratcom\Connect\Bridge\Privacy\Presets;
use Pratcom\Connect\Bridge\Privacy\ConsentMode;

/**
 * Onglet Confidentialité — Privacy Free (spec .org §3-4, O3 + legal pages org).
 *
 * CONTENU : zone du chantier Privacy / Plugin .org.
 * DÉPENDANCES : FreeBanner, Presets, PolicyPage, CookiePolicyPage, CookieScan,
 *               LocalPolicy, LocalRegistry (tous mergés).
 * PATTERN : fichier neuf petit (leçon #4) — jamais d'édition inline du monolithe.
 *
 * Sections :
 *   ① Toggle FreeBanner::OPTION_ENABLED + badge + avertissement module Connect.
 *   ② Bandeau de suggestions Presets::suggested() (détection passive locale).
 *   ③ Cases à cocher Presets::all() groupées par provenance (kind) puis catégorie.
 *   ④ Informations entreprise (LocalPolicy::OPTION_VARS) — handler dédié.
 *   ⑤ Liste manuelle de témoins (LocalPolicy::OPTION_COOKIES) + revue du scan local.
 *   ⑥ Bouton « Créer la page » Politique de confidentialité — PolicyPage.
 *   ⑦ Bouton « Créer la page » Politique relative aux témoins — CookiePolicyPage.
 *   ⑧ Export CSV consentements — LocalRegistry::EXPORT_ACTION.
 *   ⑨ (O5b) Section « Privacy Connect » — iframe scan si pack privacy actif,
 *      sinon vitrine verrouillée animée (W4) en upsell. Privacy Free intacte.
 */
class PrivacyTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-privacy';

    private const NONCE_SETTINGS = 'pratcom_privacy_free_save_settings';
    private const ACTION_SAVE    = 'pratcom_connect_bridge_save_privacy_free';

    private const NONCE_COMPANY  = 'pratcom_privacy_save_company';
    private const ACTION_COMPANY = 'pratcom_connect_bridge_save_privacy_company';

    private const NONCE_COOKIES  = 'pratcom_privacy_save_cookies';
    private const ACTION_COOKIES = 'pratcom_connect_bridge_save_privacy_cookies';

    private const NONCE_SCAN     = 'pratcom_privacy_scan_admin';
    private const ACTION_SCAN    = 'pratcom_connect_bridge_privacy_scan_admin';

    // ─── AbstractTab ───

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
        add_action('admin_post_' . self::ACTION_COMPANY, [$this, 'handle_save_company']);
        add_action('admin_post_' . self::ACTION_COOKIES, [$this, 'handle_save_cookies']);
        add_action('admin_post_' . self::ACTION_SCAN, [$this, 'handle_scan_action']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_showcase_assets']);
    }

    /**
     * Assets de la vitrine verrouillée Privacy Connect (W4) — enqueue
     * page-scoped : uniquement sur l'onglet Confidentialité. Aucun
     * <style>/<script> inline (conformité WordPress.org).
     */
    public function enqueue_showcase_assets(string $hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        ModuleShowcase::enqueue();
    }

    // ─── Handler admin-post : bannière + presets ───

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission refusée.', 'pratcom-connect'));
        }
        check_admin_referer(self::NONCE_SETTINGS);

        // ① Toggle bannière Free.
        $enabled = !empty($_POST['privacy_free_enabled']) ? '1' : '';
        update_option(FreeBanner::OPTION_ENABLED, $enabled);

        // ①b Badge « Propulsé par Pratcom Connect » — défaut ON ; case décochée = retrait.
        $badge = !empty($_POST['privacy_free_badge']) ? '1' : '0';
        update_option(FreeBanner::OPTION_BADGE_ENABLED, $badge);

        // ①c Google Consent Mode v2 (Privacy v2) — emet le `consent default`
        // inline avant GTM. OFF par defaut.
        $consent_mode = !empty($_POST['privacy_consent_mode']) ? '1' : '0';
        update_option(ConsentMode::OPTION_ENABLED, $consent_mode);

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

    // ─── Handler admin-post : informations entreprise (OPTION_VARS) ───

    public function handle_save_company(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission refusée.', 'pratcom-connect'));
        }
        check_admin_referer(self::NONCE_COMPANY);

        $in = isset($_POST['company']) && is_array($_POST['company'])
            ? wp_unslash((array) $_POST['company'])
            : [];

        $vars = [
            'legalName'    => sanitize_text_field((string) ($in['legalName'] ?? '')),
            'websiteUrl'   => esc_url_raw((string) ($in['websiteUrl'] ?? '')),
            'contactEmail' => sanitize_email((string) ($in['contactEmail'] ?? '')),
            'contactPhone' => sanitize_text_field((string) ($in['contactPhone'] ?? '')),
            'address'      => sanitize_text_field((string) ($in['address'] ?? '')),
            'officerName'  => sanitize_text_field((string) ($in['officerName'] ?? '')),
            'officerEmail' => sanitize_email((string) ($in['officerEmail'] ?? '')),
        ];
        // Ne stocker que les clés réellement renseignées (les vides retombent
        // sur les défauts calculés par LocalPolicy::variables()).
        $vars = array_filter($vars, static function (string $v): bool {
            return trim($v) !== '';
        });

        update_option(LocalPolicy::OPTION_VARS, $vars);
        $this->redirect_with_notice(self::PAGE_SLUG, 'privacy_company_saved', '');
    }

    // ─── Handler admin-post : liste manuelle de témoins (OPTION_COOKIES) ───

    public function handle_save_cookies(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission refusée.', 'pratcom-connect'));
        }
        check_admin_referer(self::NONCE_COOKIES);

        $raw = isset($_POST['cookies_text'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['cookies_text']))
            : '';

        $allowed_cats = CookieScan::CATEGORY_ORDER;
        $rows = [];
        foreach (preg_split('/\\r\\n|\\r|\\n/', $raw) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            // Format : nom | fournisseur | finalité | durée | catégorie
            $parts = array_map('trim', explode('|', $line));
            $name = $parts[0] ?? '';
            if ($name === '') {
                continue;
            }
            $cat = strtolower($parts[4] ?? 'unclassified');
            if (!in_array($cat, $allowed_cats, true)) {
                $cat = 'unclassified';
            }
            $rows[] = [
                'name'     => sanitize_text_field($name),
                'provider' => sanitize_text_field($parts[1] ?? ''),
                'purpose'  => sanitize_text_field($parts[2] ?? ''),
                'expiry'   => sanitize_text_field($parts[3] ?? ''),
                'category' => $cat,
            ];
        }

        update_option(LocalPolicy::OPTION_COOKIES, $rows);
        $this->redirect_with_notice(self::PAGE_SLUG, 'privacy_cookies_saved', '');
    }

    // ─── Handler admin-post : actions sur le scan local ───

    public function handle_scan_action(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission refusée.', 'pratcom-connect'));
        }
        check_admin_referer(self::NONCE_SCAN);

        $op = isset($_POST['scan_op']) ? sanitize_key(wp_unslash((string) $_POST['scan_op'])) : '';

        if ($op === 'clear') {
            CookieScan::clear_scanned();
            $this->redirect_with_notice(self::PAGE_SLUG, 'privacy_scan_cleared', '');
            return;
        }

        if ($op === 'import') {
            // Ajoute les noms scannés non classés à la liste manuelle (catégorie
            // « non classés »), pour que l'admin les complète ensuite.
            $existing = get_option(LocalPolicy::OPTION_COOKIES, []);
            if (!is_array($existing)) {
                $existing = [];
            }
            $known = [];
            foreach ($existing as $c) {
                if (is_array($c) && isset($c['name'])) {
                    $known[(string) $c['name']] = true;
                }
            }
            foreach (CookieScan::unclassified_scanned() as $name) {
                if (isset($known[$name])) {
                    continue;
                }
                $existing[] = [
                    'name'     => sanitize_text_field($name),
                    'provider' => '',
                    'purpose'  => '',
                    'expiry'   => '',
                    'category' => 'unclassified',
                ];
            }
            update_option(LocalPolicy::OPTION_COOKIES, $existing);
            $this->redirect_with_notice(self::PAGE_SLUG, 'privacy_scan_imported', '');
            return;
        }

        $this->redirect_with_notice(self::PAGE_SLUG, 'privacy_saved', '');
    }

    // ─── Rendu ───

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $banner_enabled = (bool) get_option(FreeBanner::OPTION_ENABLED);
        $badge_enabled  = get_option(FreeBanner::OPTION_BADGE_ENABLED, '1') === '1';
        $consent_mode_enabled = get_option(ConsentMode::OPTION_ENABLED, '0') === '1';
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
        // ces notices → on les gère ici (il passe silencieusement).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture seule d'un parametre de notice (affichage).
        $notice = isset($_GET['pratcom_notice'])
            ? sanitize_key(wp_unslash($_GET['pratcom_notice']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Confidentialité', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Privacy Free : bannière de consentement locale (Loi 25), presets de plugins populaires, registre de consentements et pages légales (politique + déclaration de témoins).', 'pratcom-connect'); ?>
        </p>

        <?php
        $notice_messages = [
            'privacy_saved'          => __('Réglages de confidentialité enregistrés.', 'pratcom-connect'),
            'privacy_company_saved'  => __('Informations de l\'entreprise enregistrées.', 'pratcom-connect'),
            'privacy_cookies_saved'  => __('Liste des témoins enregistrée.', 'pratcom-connect'),
            'privacy_scan_cleared'   => __('Témoins détectés effacés.', 'pratcom-connect'),
            'privacy_scan_imported'  => __('Témoins détectés ajoutés à la liste manuelle (à classer).', 'pratcom-connect'),
        ];
        if (isset($notice_messages[$notice])): ?>
        <div class="pc-notice pc-notice--success">
            <?php echo esc_html($notice_messages[$notice]); ?>
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

                <label class="pc-form-toggle" style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-top:18px;">
                    <input type="checkbox" name="privacy_free_badge" value="1"
                           <?php checked($badge_enabled); ?>
                           style="width:18px;height:18px;cursor:pointer;" />
                    <span><?php esc_html_e('Afficher le badge « Propulsé par Pratcom Connect » dans la bannière', 'pratcom-connect'); ?></span>
                </label>
                <p class="pc-form-help">
                    <?php esc_html_e('Crédit discret, affiché par défaut. Décochez pour le retirer. Les développeurs peuvent aussi le retirer par code via le filtre pratcom_connect_branding.', 'pratcom-connect'); ?>
                </p>
            </div><!-- /.pc-card -->

            <!-- ①bis Google Consent Mode v2 ---------------------------------->
            <div class="pc-card">
                <h2 class="pc-card__title">
                    <?php esc_html_e('Google Consent Mode v2', 'pratcom-connect'); ?>
                </h2>
                <label class="pc-form-toggle" style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                    <input type="checkbox" name="privacy_consent_mode" value="1"
                           <?php checked($consent_mode_enabled); ?>
                           style="width:18px;height:18px;cursor:pointer;" />
                    <span><?php esc_html_e('Activer Google Consent Mode v2 (signaux de consentement pour Google Ads et Google Analytics)', 'pratcom-connect'); ?></span>
                </label>
                <p class="pc-form-help">
                    <?php esc_html_e('Émet le signal « consent default » (refusé par défaut, Loi 25) en ligne avant Google Tag Manager, puis met à jour le consentement selon le choix du visiteur. À activer seulement si votre site utilise des balises Google. Sur un compte Connect, activez aussi le Consent Mode dans les réglages Privacy de votre tableau de bord.', 'pratcom-connect'); ?>
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

        <!-- ④ Informations entreprise (OPTION_VARS) ───-->
        <?php
        $company = get_option(LocalPolicy::OPTION_VARS, []);
        if (!is_array($company)) {
            $company = [];
        }
        $cval = static function (string $k) use ($company): string {
            return isset($company[$k]) && is_string($company[$k]) ? $company[$k] : '';
        };
        ?>
        <div class="pc-card" style="margin-top:0;">
            <h2 class="pc-card__title"><?php esc_html_e('Informations de l\'entreprise (pages légales)', 'pratcom-connect'); ?></h2>
            <p class="pc-form-help" style="margin-bottom:16px;">
                <?php esc_html_e('Ces informations remplissent la politique de confidentialité et la déclaration de témoins. Laissez un champ vide pour utiliser la valeur par défaut du site.', 'pratcom-connect'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_COMPANY); ?>" />
                <?php wp_nonce_field(self::NONCE_COMPANY); ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;">
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Nom légal de l\'entreprise', 'pratcom-connect'); ?></span>
                        <input type="text" name="company[legalName]" value="<?php echo esc_attr($cval('legalName')); ?>" class="regular-text" />
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Adresse du site web', 'pratcom-connect'); ?></span>
                        <input type="url" name="company[websiteUrl]" value="<?php echo esc_attr($cval('websiteUrl')); ?>" class="regular-text" placeholder="https://" />
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Courriel de contact', 'pratcom-connect'); ?></span>
                        <input type="email" name="company[contactEmail]" value="<?php echo esc_attr($cval('contactEmail')); ?>" class="regular-text" />
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Téléphone de contact', 'pratcom-connect'); ?></span>
                        <input type="text" name="company[contactPhone]" value="<?php echo esc_attr($cval('contactPhone')); ?>" class="regular-text" />
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Adresse postale', 'pratcom-connect'); ?></span>
                        <input type="text" name="company[address]" value="<?php echo esc_attr($cval('address')); ?>" class="regular-text" />
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Responsable de la protection des renseignements', 'pratcom-connect'); ?></span>
                        <input type="text" name="company[officerName]" value="<?php echo esc_attr($cval('officerName')); ?>" class="regular-text" />
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Courriel du responsable', 'pratcom-connect'); ?></span>
                        <input type="email" name="company[officerEmail]" value="<?php echo esc_attr($cval('officerEmail')); ?>" class="regular-text" />
                    </label>
                </div>
                <div class="pc-actions" style="margin-top:16px;">
                    <button type="submit" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e('Enregistrer les informations', 'pratcom-connect'); ?>
                    </button>
                </div>
            </form>
        </div><!-- /.pc-card (entreprise) -->

        <!-- ⑤ Liste manuelle de témoins (OPTION_COOKIES) + revue du scan ───-->
        <?php
        $manual_cookies = get_option(LocalPolicy::OPTION_COOKIES, []);
        if (!is_array($manual_cookies)) {
            $manual_cookies = [];
        }
        $lines = [];
        foreach ($manual_cookies as $c) {
            if (!is_array($c)) {
                continue;
            }
            $lines[] = implode(' | ', [
                (string) ($c['name'] ?? ''),
                (string) ($c['provider'] ?? ''),
                (string) ($c['purpose'] ?? ''),
                (string) ($c['expiry'] ?? ''),
                (string) ($c['category'] ?? 'unclassified'),
            ]);
        }
        $cookies_text = implode("\n", $lines);
        $scanned_unclassified = CookieScan::unclassified_scanned();
        ?>
        <div class="pc-card" style="margin-top:24px;">
            <h2 class="pc-card__title"><?php esc_html_e('Témoins supplémentaires (liste manuelle)', 'pratcom-connect'); ?></h2>
            <p class="pc-form-help" style="margin-bottom:12px;">
                <?php esc_html_e('Une ligne par témoin, champs séparés par une barre verticale ( | ) :', 'pratcom-connect'); ?>
                <code>nom | fournisseur | finalité | durée | catégorie</code>.
                <?php esc_html_e('Catégories acceptées : necessary, preferences, functional, statistics, marketing, unclassified.', 'pratcom-connect'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_COOKIES); ?>" />
                <?php wp_nonce_field(self::NONCE_COOKIES); ?>
                <textarea name="cookies_text" rows="6" class="large-text code" spellcheck="false"
                    placeholder="<?php /* translators: example row for the manual cookie list (name | provider | purpose | duration | category). */ esc_attr_e('my_cookie | Mon outil | Mesure d\'audience | 1 an | statistics', 'pratcom-connect'); ?>"><?php echo esc_textarea($cookies_text); ?></textarea>
                <div class="pc-actions" style="margin-top:12px;">
                    <button type="submit" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e('Enregistrer la liste', 'pratcom-connect'); ?>
                    </button>
                </div>
            </form>

            <?php if (!empty($scanned_unclassified)): ?>
            <hr style="margin:18px 0;border:none;border-top:1px solid #e2e8ec;" />
            <h3 style="font-size:13px;margin:0 0 8px;"><?php esc_html_e('Témoins détectés sur ce site (scan local)', 'pratcom-connect'); ?></h3>
            <p class="pc-form-help" style="margin-bottom:10px;">
                <?php esc_html_e('Détectés dans votre navigateur (administrateur uniquement), non encore couverts par un preset ou la liste manuelle :', 'pratcom-connect'); ?>
            </p>
            <p style="display:flex;flex-wrap:wrap;gap:6px;margin:0 0 14px;">
                <?php foreach ($scanned_unclassified as $sn): ?>
                <code style="background:#f3f5f7;padding:2px 8px;border-radius:10px;font-size:12px;"><?php echo esc_html($sn); ?></code>
                <?php endforeach; ?>
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SCAN); ?>" />
                    <input type="hidden" name="scan_op" value="import" />
                    <?php wp_nonce_field(self::NONCE_SCAN); ?>
                    <button type="submit" class="pc-btn pc-btn--secondary">
                        <?php esc_html_e('Ajouter à la liste manuelle (à classer)', 'pratcom-connect'); ?>
                    </button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SCAN); ?>" />
                    <input type="hidden" name="scan_op" value="clear" />
                    <?php wp_nonce_field(self::NONCE_SCAN); ?>
                    <button type="submit" class="pc-btn pc-btn--ghost">
                        <?php esc_html_e('Effacer les témoins détectés', 'pratcom-connect'); ?>
                    </button>
                </form>
            </div>
            <?php else: ?>
            <hr style="margin:18px 0;border:none;border-top:1px solid #e2e8ec;" />
            <p class="pc-form-help" style="margin:0;">
                <?php esc_html_e('Scan local : aucun témoin non classé détecté pour le moment. Le scan s\'exécute en arrière-plan lorsqu\'un administrateur visite le site public (aucune donnée n\'est transmise).', 'pratcom-connect'); ?>
            </p>
            <?php endif; ?>
        </div><!-- /.pc-card (cookies manuels) -->

        <!-- ⑥ Page de politique de confidentialité ───-->
        <div class="pc-card" style="margin-top:24px;">
            <?php PolicyPage::render_admin_section(); ?>
        </div>

        <!-- ⑦ Page de politique relative aux témoins ───-->
        <div class="pc-card" style="margin-top:24px;">
            <?php CookiePolicyPage::render_admin_section(); ?>
        </div>

        <!-- ⑧ Export CSV registre ───-->
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
        // ⑨ O5b : section Privacy Connect.
        //   - Pack actif  : iframe scan signée (render_connected_section).
        //   - Sinon       : vitrine verrouillée animée (W4) en upsell.
        // Privacy Free (sections ①-⑧) reste intouché dans les deux cas.
        if ($privacy_pack_active) {
            $this->render_connected_section();
        } else {
            $this->render_locked_upsell();
        }
    }

    // ─── W4 : vitrine verrouillée Privacy Connect (upsell animé) ───

    /**
     * Vitrine verrouillée « Privacy Connect » — affichée sous Privacy Free
     * quand le module connecté n'est pas actif (non connecté OU pack privacy
     * inactif). Démo animée de bannière de consentement + fonctions + CTA.
     * N'altère JAMAIS Privacy Free (sections ①-⑧ ci-dessus).
     */
    private function render_locked_upsell(): void
    {
        ?>
        <div style="margin-top:28px;">
        <?php
        ModuleShowcase::render([
            'title'    => __('Privacy Connect', 'pratcom-connect'),
            'subtitle' => __('La conformité Loi 25 en pilote automatique : consentement, blocage des traceurs, registre et tableau de bord, au-delà de Privacy Free.', 'pratcom-connect'),
            'demo'     => 'privacy',
            'tagline'  => __('Bannière de consentement, blocage automatique des traceurs et registre vérifiable, avec dossier self-service et tableau de bord des consentements.', 'pratcom-connect'),
            'features' => [
                __('Bannière de consentement Loi 25', 'pratcom-connect'),
                __('Blocage des traceurs', 'pratcom-connect'),
                __('Registre de consentements', 'pratcom-connect'),
                __('Dossier self-service avec PDF', 'pratcom-connect'),
                __('Tableau de bord des consentements', 'pratcom-connect'),
                __('Google Consent Mode v2', 'pratcom-connect'),
                __('Alertes nouveau traceur', 'pratcom-connect'),
            ],
            'note'     => __('Module fourni par le service Pratcom Connect (abonnement requis). Privacy Free ci-dessus reste disponible gratuitement.', 'pratcom-connect'),
            'cta_html' => $this->upsell_cta_html(),
        ]);
        ?>
        </div>
        <?php
    }

    /** CTA de la vitrine Privacy Connect (connecté = activer ; sinon = connecter + découvrir). */
    private function upsell_cta_html(): string
    {
        ob_start();
        if (Plugin::is_connected()) {
            ?>
            <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=privacy-tab" target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                <?php esc_html_e('Activer ce module', 'pratcom-connect'); ?>
            </a>
            <?php
        } else {
            ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . ConnectionTab::PAGE_SLUG)); ?>" class="pc-btn pc-btn--primary">
                <?php esc_html_e('Connecter mon compte', 'pratcom-connect'); ?>
            </a>
            <a href="<?php echo esc_url(AdminShell::marketing_url('privacy/')); ?>" target="_blank" rel="noopener" class="pc-btn pc-btn--secondary">
                <?php esc_html_e('Découvrir Connect Privacy', 'pratcom-connect'); ?>
            </a>
            <?php
        }
        return (string) ob_get_clean();
    }

    // ─── O5b : section Privacy Connect iframe (additif) ───

    /**
     * Section « Privacy Connect » — scan de confidentialité dans une iframe signée.
     * Visible uniquement si le site est connecté ET le pack privacy est actif.
     * Privacy Free (sections ①-⑧) reste intouché.
     */
    private function render_connected_section(): void
    {
        $key = Plugin::get_api_key();
        if (!$key) {
            return;
        }

        // Build .org : pas d'iframe de scan dans le wp-admin (revue WordPress.org).
        // Privacy Free reste 100% local au-dessus ; le scan Privacy Connect se
        // gere via un lien sortant. Le build premium garde l'iframe miroir.
        if (PRATCOM_CONNECT_BRIDGE_CHANNEL === 'org') {
            OrgManagePanel::render(
                __('Privacy Connect — Scan de confidentialité', 'pratcom-connect'),
                __('Le scan automatique des témoins et traceurs se gère dans votre tableau de bord Pratcom Connect.', 'pratcom-connect'),
                'privacy',
                __('Ouvrir Privacy Connect', 'pratcom-connect')
            );
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
