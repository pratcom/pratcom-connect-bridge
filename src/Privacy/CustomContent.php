<?php

namespace Pratcom\Connect\Bridge\Privacy;

use Pratcom\Connect\Bridge\Admin\Tabs\PrivacyTab;

/**
 * Contenu personnalise ajoute a la politique de confidentialite generee
 * (Privacy v2 — chantier feat/privacy-custom-content).
 *
 * L'administrateur peut ajouter, A LA FIN de la politique, une serie de blocs
 * « sous-titre + contenu » bilingues (FR/EN), en TEXTE SIMPLE (aucun HTML).
 * Le rendu s'ajoute au corps de la politique pour les DEUX tiers :
 *   - Free / non connecte / repli : LocalPolicy::render() appelle render_section().
 *   - Connecte (pack privacy) : PolicyShortcode::render() ajoute render_section()
 *     au fragment HTML rendu par le serveur. Le serveur ignore cette option
 *     LOCALE — l'ajout cote Bridge est donc le seul mecanisme qui couvre le
 *     tier connecte sans toucher au serveur ni a la CIL (0 migration).
 *
 * Stockage 100 % LOCAL (options WP) :
 *   - OPTION_ENABLED (string '1'/'')  — etat de la case.
 *   - OPTION_BLOCKS  (array)          — [{id, subtitle:{fr,en}, content:{fr,en}}].
 * Chaque bloc porte un `id` interne court : adressage AJAX stable, insensible
 * au reordonnancement / a la suppression. Le rendu public l'ignore.
 *
 * Admin : carte « Contenu personnalise » dans l'onglet Confidentialite, avec
 * sauvegarde / suppression PAR BLOC via admin-ajax (pattern CookieScan :
 * check_ajax_referer + current_user_can('manage_options') + bornes). Assets JS
 * page-scoped (wp_enqueue_script + wp_localize_script, AUCUN inline ; globale
 * JS prefixee `pratcom`). Fonctionne dans les deux canaux (org/premium).
 *
 * Fichier neuf petit (lecon #4) — jamais d'edition inline du monolithe.
 */
class CustomContent
{
    public const OPTION_ENABLED = 'pratcom_connect_privacy_custom_enabled';
    public const OPTION_BLOCKS  = 'pratcom_connect_privacy_custom_blocks';

    public const AJAX_SAVE   = 'pratcom_privacy_block_save';
    public const AJAX_DELETE = 'pratcom_privacy_block_delete';
    public const AJAX_TOGGLE = 'pratcom_privacy_custom_toggle';
    public const NONCE       = 'pratcom_privacy_custom';
    public const HANDLE      = 'pratcom-connect-privacy-custom';

    /** Bornes de securite. */
    public const MAX_BLOCKS       = 20;
    public const MAX_SUBTITLE_LEN = 200;
    public const MAX_CONTENT_LEN  = 5000;

    public function __construct()
    {
        add_action('wp_ajax_' . self::AJAX_SAVE, [$this, 'handle_save']);
        add_action('wp_ajax_' . self::AJAX_DELETE, [$this, 'handle_delete']);
        add_action('wp_ajax_' . self::AJAX_TOGGLE, [$this, 'handle_toggle']);
        add_action('admin_enqueue_scripts', [$this, 'maybe_enqueue']);
    }

    // ─── Etat ───

    public static function is_enabled(): bool
    {
        return get_option(self::OPTION_ENABLED, '') === '1';
    }

    /**
     * Liste normalisee des blocs : [{id, subtitle:{fr,en}, content:{fr,en}}].
     *
     * @return array<int, array{id:string, subtitle:array{fr:string,en:string}, content:array{fr:string,en:string}}>
     */
    public static function blocks(): array
    {
        $raw = get_option(self::OPTION_BLOCKS, []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $b) {
            $norm = self::normalize_block($b);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    /**
     * Normalise un bloc brut issu de l'option. null si structurellement invalide.
     *
     * @param mixed $b
     * @return array{id:string, subtitle:array{fr:string,en:string}, content:array{fr:string,en:string}}|null
     */
    private static function normalize_block($b): ?array
    {
        if (!is_array($b)) {
            return null;
        }
        $sub = isset($b['subtitle']) && is_array($b['subtitle']) ? $b['subtitle'] : [];
        $con = isset($b['content']) && is_array($b['content']) ? $b['content'] : [];
        $id  = isset($b['id']) ? self::sanitize_id((string) $b['id']) : '';
        if ($id === '') {
            $id = self::new_id();
        }
        return [
            'id'       => $id,
            'subtitle' => [
                'fr' => isset($sub['fr']) ? (string) $sub['fr'] : '',
                'en' => isset($sub['en']) ? (string) $sub['en'] : '',
            ],
            'content'  => [
                'fr' => isset($con['fr']) ? (string) $con['fr'] : '',
                'en' => isset($con['en']) ? (string) $con['en'] : '',
            ],
        ];
    }

    private static function sanitize_id(string $id): string
    {
        $id = preg_replace('/[^a-z0-9]/', '', strtolower($id));
        return (string) substr((string) $id, 0, 24);
    }

    private static function new_id(): string
    {
        return 'b' . substr(md5(uniqid((string) wp_rand(), true)), 0, 11);
    }

    // ─── Admin AJAX ───

    /** Persiste l'etat de la case (active / desactive le rendu de la section). */
    public function handle_toggle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden'], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
        $enabled = !empty($_POST['enabled']) ? '1' : '';
        update_option(self::OPTION_ENABLED, $enabled);
        wp_send_json_success(['enabled' => $enabled === '1']);
    }

    /** Cree (id vide) ou met a jour (id connu) un bloc. */
    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden'], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');

        $id = isset($_POST['id']) ? self::sanitize_id((string) wp_unslash($_POST['id'])) : '';

        $subtitle = [
            'fr' => $this->clean_subtitle($_POST['subtitle_fr'] ?? ''),
            'en' => $this->clean_subtitle($_POST['subtitle_en'] ?? ''),
        ];
        $content = [
            'fr' => $this->clean_content($_POST['content_fr'] ?? ''),
            'en' => $this->clean_content($_POST['content_en'] ?? ''),
        ];

        $blocks = self::blocks();

        if ($id === '') {
            if (count($blocks) >= self::MAX_BLOCKS) {
                wp_send_json_error(['error' => 'max_blocks', 'max' => self::MAX_BLOCKS], 400);
            }
            $id       = self::new_id();
            $blocks[] = ['id' => $id, 'subtitle' => $subtitle, 'content' => $content];
        } else {
            $found = false;
            foreach ($blocks as $i => $b) {
                if ($b['id'] === $id) {
                    $blocks[$i]['subtitle'] = $subtitle;
                    $blocks[$i]['content']  = $content;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                // Bloc disparu (suppression concurrente) : recreer dans la borne.
                if (count($blocks) >= self::MAX_BLOCKS) {
                    wp_send_json_error(['error' => 'not_found'], 404);
                }
                $blocks[] = ['id' => $id, 'subtitle' => $subtitle, 'content' => $content];
            }
        }

        update_option(self::OPTION_BLOCKS, array_values($blocks));
        wp_send_json_success(['id' => $id, 'count' => count($blocks)]);
    }

    /** Supprime un bloc par id (idempotent). */
    public function handle_delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden'], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
        $id     = isset($_POST['id']) ? self::sanitize_id((string) wp_unslash($_POST['id'])) : '';
        $blocks = self::blocks();
        if ($id !== '') {
            $blocks = array_values(array_filter(
                $blocks,
                static function (array $b) use ($id): bool {
                    return $b['id'] !== $id;
                }
            ));
            update_option(self::OPTION_BLOCKS, $blocks);
        }
        wp_send_json_success(['count' => count($blocks)]);
    }

    /** @param mixed $v */
    private function clean_subtitle($v): string
    {
        $v = sanitize_text_field((string) wp_unslash($v));
        return self::truncate($v, self::MAX_SUBTITLE_LEN);
    }

    /** @param mixed $v */
    private function clean_content($v): string
    {
        $v = sanitize_textarea_field((string) wp_unslash($v));
        return self::truncate($v, self::MAX_CONTENT_LEN);
    }

    private static function truncate(string $v, int $max): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($v, 0, $max);
        }
        return (string) substr($v, 0, $max);
    }

    // ─── Assets admin (page-scoped, aucun inline) ───

    public function maybe_enqueue(string $hook): void
    {
        if (strpos($hook, PrivacyTab::PAGE_SLUG) === false) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_enqueue_script(
            self::HANDLE,
            PRATCOM_CONNECT_BRIDGE_URL . 'assets/js/privacy-custom-content.js',
            [],
            PRATCOM_CONNECT_BRIDGE_VERSION,
            true
        );
        wp_localize_script(self::HANDLE, 'pratcomPrivacyCustom', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'actions' => [
                'save'   => self::AJAX_SAVE,
                'delete' => self::AJAX_DELETE,
                'toggle' => self::AJAX_TOGGLE,
            ],
            'max' => [
                'blocks'   => self::MAX_BLOCKS,
                'subtitle' => self::MAX_SUBTITLE_LEN,
                'content'  => self::MAX_CONTENT_LEN,
            ],
            'i18n' => [
                'saving'        => __('Enregistrement…', 'pratcom-connect'),
                'saved'         => __('Bloc enregistré.', 'pratcom-connect'),
                'deleting'      => __('Suppression…', 'pratcom-connect'),
                'error'         => __('Une erreur est survenue. Réessayez.', 'pratcom-connect'),
                'forbidden'     => __('Permission refusée.', 'pratcom-connect'),
                'maxBlocks'     => __('Nombre maximal de blocs atteint.', 'pratcom-connect'),
                'confirmDelete' => __('Supprimer ce bloc ?', 'pratcom-connect'),
            ],
        ]);
    }

    // ─── Rendu admin (carte) ───

    /**
     * Carte « Contenu personnalise » — appelee par PrivacyTab::render().
     * Case a cocher + liste de blocs repetables + gabarit <template>.
     */
    public static function render_admin_card(): void
    {
        $enabled = self::is_enabled();
        $blocks  = self::blocks();
        $at_max  = count($blocks) >= self::MAX_BLOCKS;
        ?>
        <div class="pc-card" style="margin-top:24px;" id="pratcom-custom-content">
            <h2 class="pc-card__title">
                <?php esc_html_e('Contenu personnalisé de la politique', 'pratcom-connect'); ?>
            </h2>
            <p class="pc-form-help" style="margin-bottom:14px;">
                <?php esc_html_e('Ajoutez vos propres sections (texte simple, bilingue) à la fin de la politique de confidentialité générée. Chaque bloc se compose d\'un sous-titre et d\'un paragraphe, dans les deux langues, et s\'enregistre indépendamment.', 'pratcom-connect'); ?>
            </p>

            <label class="pc-form-toggle" style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                <input type="checkbox" id="pratcom-custom-enabled" value="1"
                       <?php checked($enabled); ?>
                       style="width:18px;height:18px;cursor:pointer;" />
                <span><?php esc_html_e('Ajouter du contenu personnalisé à la politique de confidentialité', 'pratcom-connect'); ?></span>
            </label>
            <span id="pratcom-custom-toggle-status" class="pc-form-help" style="margin-left:6px;" aria-live="polite"></span>

            <div id="pratcom-custom-blocks" style="margin-top:18px;<?php echo $enabled ? '' : 'display:none;'; ?>">
                <?php
                foreach ($blocks as $b) {
                    self::render_block_row($b);
                }
                ?>
            </div>

            <div class="pc-actions" id="pratcom-custom-add-wrap" style="margin-top:8px;<?php echo $enabled ? '' : 'display:none;'; ?>">
                <button type="button" class="pc-btn pc-btn--secondary" id="pratcom-custom-add" <?php disabled($at_max); ?>>
                    <?php esc_html_e('Ajouter un bloc', 'pratcom-connect'); ?>
                </button>
                <span class="pc-form-help" style="margin-left:8px;">
                    <?php
                    printf(
                        /* translators: %d: maximum number of custom blocks. */
                        esc_html__('Jusqu\'à %d blocs.', 'pratcom-connect'),
                        (int) self::MAX_BLOCKS
                    );
                    ?>
                </span>
            </div>

            <template id="pratcom-custom-block-tpl"><?php self::render_block_row(null); ?></template>
        </div>
        <?php
    }

    /**
     * Une ligne de bloc (sous-titre FR/EN + contenu FR/EN + actions).
     * $block null = gabarit vide (pour le <template>, clone cote JS).
     *
     * @param array{id:string, subtitle:array{fr:string,en:string}, content:array{fr:string,en:string}}|null $block
     */
    private static function render_block_row(?array $block): void
    {
        $id     = is_array($block) ? (string) ($block['id'] ?? '') : '';
        $sub_fr = is_array($block) ? (string) ($block['subtitle']['fr'] ?? '') : '';
        $sub_en = is_array($block) ? (string) ($block['subtitle']['en'] ?? '') : '';
        $con_fr = is_array($block) ? (string) ($block['content']['fr'] ?? '') : '';
        $con_en = is_array($block) ? (string) ($block['content']['en'] ?? '') : '';
        ?>
        <div class="pratcom-custom-block" data-block-id="<?php echo esc_attr($id); ?>"
             style="border:1px solid #d0d7dc;border-radius:8px;padding:14px;margin-bottom:14px;background:#fff;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;">
                <div style="display:flex;flex-direction:column;gap:14px;">
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Sous-titre (français)', 'pratcom-connect'); ?></span>
                        <input type="text" class="regular-text pratcom-custom-subtitle-fr"
                               maxlength="<?php echo (int) self::MAX_SUBTITLE_LEN; ?>"
                               style="width:100%;box-sizing:border-box;"
                               value="<?php echo esc_attr($sub_fr); ?>" />
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Contenu (français)', 'pratcom-connect'); ?></span>
                        <textarea rows="5" class="large-text pratcom-custom-content-fr"
                                  maxlength="<?php echo (int) self::MAX_CONTENT_LEN; ?>"><?php echo esc_textarea($con_fr); ?></textarea>
                    </label>
                </div>
                <div style="display:flex;flex-direction:column;gap:14px;">
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Sous-titre (anglais)', 'pratcom-connect'); ?></span>
                        <input type="text" class="regular-text pratcom-custom-subtitle-en"
                               maxlength="<?php echo (int) self::MAX_SUBTITLE_LEN; ?>"
                               style="width:100%;box-sizing:border-box;"
                               value="<?php echo esc_attr($sub_en); ?>" />
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">
                        <span><?php esc_html_e('Contenu (anglais)', 'pratcom-connect'); ?></span>
                        <textarea rows="5" class="large-text pratcom-custom-content-en"
                                  maxlength="<?php echo (int) self::MAX_CONTENT_LEN; ?>"><?php echo esc_textarea($con_en); ?></textarea>
                    </label>
                </div>
            </div>
            <div class="pc-actions" style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <button type="button" class="pc-btn pc-btn--primary pratcom-custom-save">
                    <?php esc_html_e('Sauvegarder ce bloc', 'pratcom-connect'); ?>
                </button>
                <button type="button" class="pc-btn pc-btn--ghost pratcom-custom-delete">
                    <?php esc_html_e('Supprimer', 'pratcom-connect'); ?>
                </button>
                <span class="pratcom-custom-status pc-form-help" aria-live="polite"></span>
            </div>
        </div>
        <?php
    }

    // ─── Rendu public (section ajoutee a la politique) ───

    /**
     * Section ajoutee A LA FIN de la politique. Retourne '' si desactive ou si
     * aucun bloc n'a de contenu pour la langue demandee. Texte simple
     * uniquement : esc_html() + nl2br(), jamais de HTML brut. Les sous-titres
     * sont des <h2> (meme niveau que les sections existantes de la politique).
     */
    public static function render_section(string $lang): string
    {
        if (!self::is_enabled()) {
            return '';
        }
        $lang = $lang === 'en' ? 'en' : 'fr';
        $out  = '';
        foreach (self::blocks() as $b) {
            $subtitle = trim((string) ($b['subtitle'][$lang] ?? ''));
            $content  = trim((string) ($b['content'][$lang] ?? ''));
            if ($subtitle === '' && $content === '') {
                continue;
            }
            $section = '<section class="pratcom-policy-extra">';
            if ($subtitle !== '') {
                $section .= '<h2>' . esc_html($subtitle) . '</h2>';
            }
            if ($content !== '') {
                $section .= '<p>' . nl2br(esc_html($content)) . '</p>';
            }
            $section .= '</section>';
            $out .= $section;
        }
        return $out;
    }
}
