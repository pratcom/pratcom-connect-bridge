<?php

namespace Pratcom\Connect\Bridge\Admin;

/**
 * Vitrine reutilisable d'un module Pratcom Connect verrouille (W4).
 *
 * But produit : quand un module n'est pas connecte / pas actif, son onglet
 * affiche une vitrine soignee (demo animee legere + description enrichie +
 * liste de fonctions + les CTA existants) pour donner envie de l'activer.
 *
 * Composant DONNEES -> RENDU : chaque onglet appelle ModuleShowcase::render()
 * avec un tableau descriptif. Pour ajouter un module, on remplit juste les
 * donnees, aucun nouveau gabarit a ecrire.
 *
 * Contraintes : aucune balise <style>/<script> inline (conformite WordPress.org),
 * zero appel externe, zero image externe (demos 100% CSS/SVG). Les classes
 * sont prefixees pcw- pour eviter toute collision avec le theme ou l'admin.
 * Le CSS/JS vit dans assets/css/module-showcase.css + assets/js/module-showcase.js
 * (enqueue page-scoped par chaque onglet). prefers-reduced-motion est gere
 * dans le CSS (animations coupees/reduites).
 *
 * Fichier neuf petit (lecon #4) — jamais d'edition inline d'un monolithe.
 */
final class ModuleShowcase
{
    /**
     * Enqueue les assets de la vitrine (CSS + JS). A appeler depuis l'enqueue
     * page-scoped de l'onglet concerne (uniquement quand le module est
     * verrouille / sur sa page). Assets autonomes : aucun appel externe.
     */
    public static function enqueue(): void
    {
        wp_enqueue_style(
            'pratcom-connect-bridge-module-showcase',
            PRATCOM_CONNECT_BRIDGE_URL . 'assets/css/module-showcase.css',
            ['pratcom-connect-bridge-admin'],
            PRATCOM_CONNECT_BRIDGE_VERSION
        );
        wp_enqueue_script(
            'pratcom-connect-bridge-module-showcase',
            PRATCOM_CONNECT_BRIDGE_URL . 'assets/js/module-showcase.js',
            [],
            PRATCOM_CONNECT_BRIDGE_VERSION,
            true
        );
    }

    /**
     * Rend une vitrine complete a partir de donnees.
     *
     * @param array{
     *   title?: string,
     *   subtitle?: string,
     *   badge?: string,
     *   demo?: string,
     *   tagline?: string,
     *   features?: array<int, string>,
     *   note?: string,
     *   cta_html?: string
     * } $data Donnees deja traduites/echappees cote appelant pour les libelles.
     *         `demo` = identifiant de demo ('chat' | 'forms' | 'privacy').
     *         `cta_html` = bloc HTML des boutons d'action (deja construit/echappe
     *         par l'onglet, pour conserver les CTA existants tels quels).
     */
    public static function render(array $data): void
    {
        $title    = (string) ($data['title'] ?? '');
        $subtitle = (string) ($data['subtitle'] ?? '');
        $badge    = (string) ($data['badge'] ?? __('Verrouillé', 'pratcom-connect'));
        $demo     = (string) ($data['demo'] ?? '');
        $tagline  = (string) ($data['tagline'] ?? '');
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        $note     = (string) ($data['note'] ?? '');
        $cta_html = (string) ($data['cta_html'] ?? '');

        $lang = AdminShell::admin_lang();
        ?>
        <section class="pcw-showcase" data-pcw-lang="<?php echo esc_attr($lang); ?>">
            <div class="pcw-showcase__head">
                <h2 class="pcw-showcase__title">
                    <?php echo esc_html($title); ?>
                    <span class="pcw-badge pcw-badge--locked"><?php echo esc_html($badge); ?></span>
                </h2>
                <?php if ($subtitle !== ''): ?>
                <p class="pcw-showcase__subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php endif; ?>
            </div>

            <div class="pcw-showcase__grid">
                <div class="pcw-showcase__demo">
                    <?php self::render_demo($demo); ?>
                </div>

                <div class="pcw-showcase__info">
                    <?php if ($tagline !== ''): ?>
                    <p class="pcw-showcase__tagline"><?php echo esc_html($tagline); ?></p>
                    <?php endif; ?>

                    <?php if ($features !== []): ?>
                    <ul class="pcw-feature-list">
                        <?php foreach ($features as $feature): ?>
                        <li class="pcw-feature-list__item">
                            <span class="pcw-feature-list__check" aria-hidden="true"></span>
                            <span><?php echo esc_html((string) $feature); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if ($note !== ''): ?>
                    <p class="pcw-showcase__note"><?php echo esc_html($note); ?></p>
                    <?php endif; ?>

                    <?php if ($cta_html !== ''): ?>
                    <div class="pcw-showcase__cta">
                        <?php
                        // CTA deja construits et echappes par l'onglet appelant
                        // (memes boutons que la vitrine historique). On les rend
                        // tels quels pour ne pas dupliquer la logique connecte/non.
                        echo $cta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Aiguille vers la demo animee demandee. Toute demo inconnue ne rend rien
     * (la vitrine reste valable sans l'animation).
     */
    private static function render_demo(string $demo): void
    {
        switch ($demo) {
            case 'chat':
                self::render_demo_chat();
                break;
            case 'forms':
                self::render_demo_forms();
                break;
            case 'privacy':
                self::render_demo_privacy();
                break;
            default:
                // Pas de demo : on n'affiche rien de plus.
                break;
        }
    }

    /**
     * Demo Connect Chat : mini-widget au look reel. Les bulles et l'indicateur
     * de saisie sont sequences par module-showcase.js (classe .is-playing posee
     * par etapes). Contenu FR/EN porte par data-fr / data-en, bascule par le JS
     * selon data-pcw-lang. Tout statique sans JS (les bulles restent visibles).
     */
    private static function render_demo_chat(): void
    {
        ?>
        <div class="pcw-demo pcw-demo--chat" data-pcw-demo="chat" aria-hidden="true">
            <div class="pcw-chat">
                <div class="pcw-chat__header">
                    <span class="pcw-chat__avatar">PC</span>
                    <span class="pcw-chat__name">Connect Chat</span>
                    <span class="pcw-chat__status"><span class="pcw-chat__dot"></span><span data-fr="En ligne" data-en="Online">En ligne</span></span>
                </div>
                <div class="pcw-chat__body">
                    <div class="pcw-bubble pcw-bubble--visitor" data-step="1">
                        <span data-fr="Nous cherchons un fournisseur de pièces sur mesure."
                              data-en="We are looking for a custom parts supplier.">Nous cherchons un fournisseur de pièces sur mesure.</span>
                    </div>
                    <div class="pcw-typing" data-step="2">
                        <span></span><span></span><span></span>
                    </div>
                    <div class="pcw-bubble pcw-bubble--bot" data-step="3">
                        <span data-fr="Bonjour ! Quel volume mensuel et quelle tolérance visez-vous ?"
                              data-en="Hello! What monthly volume and tolerance are you aiming for?">Bonjour ! Quel volume mensuel et quelle tolérance visez-vous ?</span>
                    </div>
                    <div class="pcw-bubble pcw-bubble--visitor" data-step="4">
                        <span data-fr="200 pièces/mois, tolérance ±0,05 mm."
                              data-en="200 parts/month, tolerance ±0.05 mm.">200 pièces/mois, tolérance ±0,05 mm.</span>
                    </div>
                    <div class="pcw-pill pcw-pill--lead" data-step="5">
                        <span class="pcw-pill__spark" aria-hidden="true"></span>
                        <span data-fr="Lead qualifié · Score 88" data-en="Qualified lead · Score 88">Lead qualifié · Score 88</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Demo Connect Forms : mini-formulaire stylise Connect dont les champs se
     * remplissent seuls (texte tape par le JS), une case se coche, le bouton
     * s'enfonce, puis une pastille de lead apparait.
     */
    private static function render_demo_forms(): void
    {
        ?>
        <div class="pcw-demo pcw-demo--forms" data-pcw-demo="forms" aria-hidden="true">
            <div class="pcw-form">
                <div class="pcw-form__title" data-fr="Demande de soumission" data-en="Request a quote">Demande de soumission</div>
                <div class="pcw-field" data-step="1">
                    <label class="pcw-field__label" data-fr="Nom" data-en="Name">Nom</label>
                    <div class="pcw-field__input"><span class="pcw-typed" data-text="Jean Tremblay"></span><span class="pcw-caret"></span></div>
                </div>
                <div class="pcw-field" data-step="2">
                    <label class="pcw-field__label" data-fr="Courriel" data-en="Email">Courriel</label>
                    <div class="pcw-field__input"><span class="pcw-typed" data-text="jean@exemple.com"></span><span class="pcw-caret"></span></div>
                </div>
                <div class="pcw-field" data-step="3">
                    <label class="pcw-field__label" data-fr="Message" data-en="Message">Message</label>
                    <div class="pcw-field__input pcw-field__input--area"><span class="pcw-typed" data-text-fr="Demande de soumission pour 200 pièces." data-text-en="Quote request for 200 parts."></span><span class="pcw-caret"></span></div>
                </div>
                <label class="pcw-consent" data-step="4">
                    <span class="pcw-check" aria-hidden="true"></span>
                    <span data-fr="J'accepte d'être contacté." data-en="I agree to be contacted.">J'accepte d'être contacté.</span>
                </label>
                <button type="button" class="pcw-submit" data-step="5" tabindex="-1">
                    <span data-fr="Envoyer" data-en="Send">Envoyer</span>
                </button>
                <div class="pcw-pill pcw-pill--lead" data-step="6">
                    <span class="pcw-pill__spark" aria-hidden="true"></span>
                    <span data-fr="Lead qualifié · Score 76" data-en="Qualified lead · Score 76">Lead qualifié · Score 76</span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Demo Connect Privacy : mini-banniere de consentement Connect qui apparait,
     * un traceur (Google Analytics) se fait barrer/bloquer, trois boutons
     * d'action, puis la banniere se reduit en icone flottante.
     */
    private static function render_demo_privacy(): void
    {
        ?>
        <div class="pcw-demo pcw-demo--privacy" data-pcw-demo="privacy" aria-hidden="true">
            <div class="pcw-privacy">
                <div class="pcw-privacy__banner" data-step="1">
                    <div class="pcw-privacy__text">
                        <strong data-fr="Votre vie privée" data-en="Your privacy">Votre vie privée</strong>
                        <span data-fr="Nous utilisons des témoins pour améliorer votre expérience."
                              data-en="We use cookies to improve your experience.">Nous utilisons des témoins pour améliorer votre expérience.</span>
                    </div>
                    <div class="pcw-privacy__trackers">
                        <span class="pcw-tracker pcw-tracker--blocked" data-step="2">
                            <span class="pcw-tracker__name">Google Analytics</span>
                            <span class="pcw-tracker__tag" data-fr="Bloqué" data-en="Blocked">Bloqué</span>
                        </span>
                    </div>
                    <div class="pcw-privacy__actions">
                        <span class="pcw-privacy__btn pcw-privacy__btn--primary" data-fr="Tout accepter" data-en="Accept all">Tout accepter</span>
                        <span class="pcw-privacy__btn" data-fr="Tout refuser" data-en="Reject all">Tout refuser</span>
                        <span class="pcw-privacy__btn pcw-privacy__btn--ghost" data-fr="Personnaliser" data-en="Customize">Personnaliser</span>
                    </div>
                </div>
                <div class="pcw-privacy__fab" data-step="3" aria-hidden="true">
                    <span class="pcw-privacy__fab-icon"></span>
                </div>
            </div>
        </div>
        <?php
    }
}
