/**
 * Pratcom Connect Bridge — admin-feedback.js (O5b)
 *
 * États de chargement UX sur les actions longues (form submit) dans les
 * pages admin Pratcom Connect :
 *   - Bouton désactivé pendant le traitement (anti double-clic)
 *   - Texte remplacé par spinner CSS + « Traitement… »
 *
 * Cible : tous les boutons submit dans .pc-content (shell admin PCP).
 * Aucun attribut data- supplémentaire requis sur les boutons existants.
 * Pas de framework. Compatible avec tous les navigateurs modernes.
 */
(function () {
    'use strict';

    /** Label localisé injecté par wp_localize_script, fallback FR. */
    var LABEL = (typeof pcFeedback !== 'undefined' && pcFeedback.processing)
        ? pcFeedback.processing
        : 'Traitement…';

    /** Attache le comportement de feedback à un formulaire donné. */
    function attachToForm(form) {
        form.addEventListener('submit', function () {
            /* Trouver le bouton submit qui a déclenché ce submit.
               On prend le premier bouton[type=submit] non-danger (exclure les
               boutons « Effacer »/« Se déconnecter » si on voulait, mais ici
               on les couvre tous — l'anti double-clic s'applique à tous). */
            var btn = form.querySelector(
                'button[type="submit"], button:not([type]), input[type="submit"]'
            );
            if (!btn || btn.disabled) return;

            /* Mémoriser le contenu original pour le garde-fou de restauration. */
            var originalHTML = btn.innerHTML;
            var originalValue = btn.value;

            btn.disabled = true;

            if (btn.tagName === 'BUTTON') {
                btn.innerHTML =
                    '<span class="pc-spinner" aria-hidden="true" style="' +
                    'display:inline-block;width:12px;height:12px;border:2px solid currentColor;' +
                    'border-top-color:transparent;border-radius:50%;' +
                    'animation:pc-spin .7s linear infinite;margin-right:6px;vertical-align:middle;' +
                    '"></span><span>' + LABEL + '</span>';
            } else {
                btn.value = LABEL;
            }

            /* Garde-fou : si la navigation est annulée (validation HTML, back…),
               restaurer le bouton après 8 s pour éviter un verrouillage permanent. */
            setTimeout(function () {
                if (!btn.disabled) return;
                btn.disabled = false;
                if (btn.tagName === 'BUTTON') {
                    btn.innerHTML = originalHTML;
                } else {
                    btn.value = originalValue;
                }
            }, 8000);
        });
    }

    function init() {
        /* Injecter le keyframe d'animation (une seule fois). */
        if (!document.getElementById('pc-feedback-style')) {
            var style = document.createElement('style');
            style.id = 'pc-feedback-style';
            style.textContent =
                '@keyframes pc-spin{to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }

        /* Attacher à tous les formulaires dans le conteneur Pratcom Connect. */
        var content = document.querySelector('.pc-content');
        if (!content) return;
        content.querySelectorAll('form').forEach(attachToForm);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
