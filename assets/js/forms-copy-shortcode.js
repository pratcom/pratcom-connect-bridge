/**
 * Pratcom Connect — Formulaires : copie du shortcode + sélection au focus.
 *
 * Chargé uniquement sur admin.php?page=pratcom-connect-forms
 * (FormsTab::enqueue_forms_scripts). Remplace le <script> inline de
 * FormsTab::render_list() et l'attribut onfocus inline du champ shortcode
 * (revue WordPress.org : tout JS admin passe par wp_enqueue_script).
 *
 * Le libellé « Copié ! » est fourni via wp_localize_script (objet pratcomFormsCopy).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var copiedLabel = (window.pratcomFormsCopy && window.pratcomFormsCopy.copied) || 'Copied!';

        // Sélection automatique du shortcode au focus (ex-attribut onfocus inline).
        document.querySelectorAll('.pc-shortcode input[readonly]').forEach(function (input) {
            input.addEventListener('focus', function () {
                input.select();
            });
        });

        // Copie du shortcode dans le presse-papiers.
        document.querySelectorAll('.pc-copy-shortcode').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var sc = btn.getAttribute('data-shortcode');
                var done = function () {
                    var prev = btn.textContent;
                    btn.textContent = copiedLabel;
                    setTimeout(function () { btn.textContent = prev; }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(sc).then(done);
                } else {
                    var input = btn.parentNode.querySelector('input');
                    input.select();
                    document.execCommand('copy');
                    done();
                }
            });
        });
    });
}());
