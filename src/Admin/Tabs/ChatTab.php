<?php

namespace Pratcom\Connect\Bridge\Admin\Tabs;

use Pratcom\Connect\Bridge\Plugin;
use Pratcom\Connect\Bridge\Http\ApiClient;
use Pratcom\Connect\Bridge\Admin\OrgManagePanel;
use Pratcom\Connect\Bridge\Admin\AdminShell;
use Pratcom\Connect\Bridge\Admin\ModuleShowcase;

/**
 * Onglet Chat (O5) : iframe du tableau de bord d'entraînement Chatbot.
 * Contenu = chantier Chatbot. Propriété shell = Plugin .org.
 *
 * Module inactif : vitrine verrouillée animée (W4, upsell conforme WordPress.org).
 * Module actif   : session signée (B1 HMAC) → iframe /embed/chat-training/{ws}.
 *
 * Fichier neuf (leçon #4) — jamais d'édition inline du monolithe.
 */
class ChatTab extends AbstractTab
{
    public const PAGE_SLUG = 'pratcom-connect-chat';

    // ─── AbstractTab ────────────────────────────────────

    public function slug(): string
    {
        return self::PAGE_SLUG;
    }

    public function label(): string
    {
        return __('Chat', 'pratcom-connect');
    }

    public function icon(): string
    {
        return 'format-chat';
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_showcase_assets']);
    }

    /**
     * Assets de la vitrine verrouillée (W4) — enqueue page-scoped : uniquement
     * sur l'onglet Chat. Aucun <style>/<script> inline (conformité WordPress.org).
     */
    public function enqueue_showcase_assets(string $hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        ModuleShowcase::enqueue();
    }

    // ─── Rendu ────────────────────────────────────────

    public function render(): void
    {
        ?>
        <h1 class="pc-content__title"><?php esc_html_e('Connect Chat', 'pratcom-connect'); ?></h1>
        <p class="pc-content__subtitle">
            <?php esc_html_e('Entraînez votre assistant virtuel, gérez les connaissances et consultez les conversations depuis votre tableau de bord.', 'pratcom-connect'); ?>
        </p>
        <?php

        if (!Plugin::is_connected() || !$this->chat_enabled()) {
            $this->render_locked();
            return;
        }

        $key = Plugin::get_api_key();
        if (!$key) {
            $this->render_no_key();
            return;
        }

        // Build .org : aucune iframe d'app tierce dans le wp-admin (revue
        // WordPress.org). Panneau natif (statut + cle) + lien sortant vers le
        // tableau de bord. Le build premium garde le miroir iframe ci-dessous.
        if (PRATCOM_CONNECT_BRIDGE_CHANNEL === 'org') {
            OrgManagePanel::render(
                __('Entraînement géré dans votre tableau de bord', 'pratcom-connect'),
                __("L'entraînement de l'assistant (connaissances, directives, conversations) se gère dans votre tableau de bord Pratcom Connect.", 'pratcom-connect'),
                'chat',
                __('Gérer dans le tableau de bord', 'pratcom-connect')
            );
            return;
        }

        $result = (new ApiClient())->get_chat_session($key);

        if (empty($result['ok']) || empty($result['url'])) {
            $this->render_fallback((string) ($result['error'] ?? 'unknown'));
            return;
        }

        $ws      = (string) ($result['workspace_slug'] ?? '');
        $crm_url = 'https://connect.pratcom.net/crm/' . rawurlencode($ws) . '/chat';
        $this->render_iframe(esc_url($result['url']), esc_url($crm_url));
    }

    // ─── Méthodes privées ──────────────────────────────────

    private function chat_enabled(): bool
    {
        $packs = get_option(Plugin::OPTION_FEATURE_PACKS, []);
        return is_array($packs) && !empty($packs['chat']);
    }

    /** Vitrine verrouillée animée (W4) : démo de chat + fonctions + CTA existants. */
    private function render_locked(): void
    {
        ModuleShowcase::render([
            'title'    => __('Connect Chat', 'pratcom-connect'),
            'subtitle' => __('Un assistant qui répond à vos visiteurs, qualifie vos prospects et passe la main à votre équipe au bon moment.', 'pratcom-connect'),
            'demo'     => 'chat',
            'tagline'  => __('Assistant virtuel intelligent entraînable, multi-tenant, avec détection de leads chauds et escalade humaine, sans aucun plugin supplémentaire.', 'pratcom-connect'),
            'features' => [
                __('Assistant IA multilingue, disponible 24/7', 'pratcom-connect'),
                __('Base de connaissances par client (RAG)', 'pratcom-connect'),
                __('Détection et scoring des leads chauds', 'pratcom-connect'),
                __('Escalade vers un humain', 'pratcom-connect'),
                __('Entraînement depuis votre site', 'pratcom-connect'),
                __('Multi-sites', 'pratcom-connect'),
            ],
            'note'     => __('Module fourni par le service Pratcom Connect (abonnement requis).', 'pratcom-connect'),
            'cta_html' => $this->locked_cta_html(),
        ]);
    }

    /** CTA de la vitrine (mêmes boutons que la vitrine historique). */
    private function locked_cta_html(): string
    {
        ob_start();
        if (Plugin::is_connected()) {
            ?>
            <a href="https://connect.pratcom.net/?utm_source=wp-plugin&utm_medium=chat-tab"
               target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                <?php esc_html_e('Activer ce module', 'pratcom-connect'); ?>
            </a>
            <?php
        } else {
            ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . ConnectionTab::PAGE_SLUG)); ?>"
               class="pc-btn pc-btn--primary">
                <?php esc_html_e('Connecter mon compte', 'pratcom-connect'); ?>
            </a>
            <a href="<?php echo esc_url(AdminShell::marketing_url('chat/')); ?>"
               target="_blank" rel="noopener" class="pc-btn pc-btn--secondary">
                <?php esc_html_e('Découvrir Connect Chat', 'pratcom-connect'); ?>
            </a>
            <?php
        }
        return (string) ob_get_clean();
    }

    private function render_no_key(): void
    {
        ?>
        <div class="pc-notice pc-notice--warning">
            <?php esc_html_e("Clé API non trouvée. Veuillez reconnecter le plugin depuis l'onglet Connexion.", 'pratcom-connect'); ?>
        </div>
        <?php
    }

    private function render_fallback(string $error_code): void
    {
        ?>
        <div class="pc-card">
            <h2 class="pc-card__title"><?php esc_html_e('Connect Chat', 'pratcom-connect'); ?></h2>
            <div class="pc-notice pc-notice--warning" style="margin-bottom: 14px;">
                <?php
                printf(
                    /* translators: %s: error code returned by the API. */
                    esc_html__("La session de l'interface Chat est momentanément indisponible (%s). Vous pouvez accéder à votre tableau de bord directement :", 'pratcom-connect'),
                    esc_html($error_code)
                );
                ?>
            </div>
            <div class="pc-actions">
                <a href="https://connect.pratcom.net/" target="_blank" rel="noopener" class="pc-btn pc-btn--primary">
                    <?php esc_html_e('Ouvrir Pratcom Connect', 'pratcom-connect'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    private function render_iframe(string $src, string $crm_url): void
    {
        ?>
        <div class="pc-embed-wrap">
            <div class="pc-embed-bar">
                <span class="pc-embed-bar__label">
                    <?php esc_html_e("Connect Chat — Tableau de bord d'entraînement", 'pratcom-connect'); ?>
                </span>
                <a href="<?php echo esc_url($crm_url); ?>" target="_blank" rel="noopener"
                   class="pc-embed-bar__link">
                    <?php esc_html_e('Ouvrir en plein écran ↗', 'pratcom-connect'); ?>
                </a>
            </div>
            <iframe
                src="<?php echo esc_url($src); ?>"
                class="pc-embed-frame"
                title="<?php esc_attr_e("Interface d'entraînement Connect Chat", 'pratcom-connect'); ?>"
                loading="lazy"
                referrerpolicy="same-origin"
                sandbox="allow-scripts allow-same-origin allow-forms"
            ></iframe>
        </div>
        <?php
    }
}
