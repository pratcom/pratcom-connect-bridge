/**
 * Pratcom Connect — Apparence : synchro bidirectionnelle picker ↔ champ HEX.
 *
 * Charge uniquement sur admin.php?page=pratcom-connect-appearance
 * (AppearanceTab::enqueue_appearance_scripts).
 *
 * Remplace le <script> inline de AppearanceTab::render() et les oninput
 * inline de ThemePalette::render_fields() pour la direction picker → texte.
 *
 * Couvre tous les <input type="color"> de l'onglet Apparence dont le sibling
 * immédiat est un <input type="text"> (structure flex créée par AppearanceTab
 * et ThemePalette). Écoute 'input' ET 'change' pour couvrir tous les
 * scénarios Chrome / Firefox / Safari (drag = input ; fermeture popup = change).
 */
(function () {
    'use strict';

    /**
     * Luminance relative WCAG 2.1 → retourne une couleur de texte accessible.
     * @param {string} hex - Couleur HEX '#rrggbb' (minuscules ou majuscules).
     * @returns {string} '#10222b' (sombre) ou '#ffffff' (clair).
     */
    function wcagTextColor(hex) {
        hex = hex.replace('#', '');
        if (hex.length !== 6) { return '#10222b'; }
        var n = parseInt(hex, 16);
        var r = (n >> 16 & 255) / 255;
        var g = (n >> 8  & 255) / 255;
        var b = (n       & 255) / 255;
        function lin(v) {
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        }
        var L = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
        return L > 0.45 ? '#10222b' : '#ffffff';
    }

    /**
     * Normalise n'importe quelle valeur couleur en HEX minuscules (#rrggbb).
     * Supporte : #RRGGBB, #rrggbb, rgb(r, g, b).
     * Retourne '' si la valeur n'est pas reconnue.
     * @param {string} val
     * @returns {string}
     */
    function toHex(val) {
        if (!val) { return ''; }
        val = val.trim();
        if (/^#[0-9a-fA-F]{6}$/.test(val)) {
            return val.toLowerCase();
        }
        var rgb = val.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/);
        if (rgb) {
            return '#' + [rgb[1], rgb[2], rgb[3]].map(function (x) {
                return ('0' + parseInt(x, 10).toString(16)).slice(-2);
            }).join('');
        }
        return '';
    }

    /**
     * Met à jour l'aperçu de la couleur principale (#pratcom_preview).
     * @param {string} hex
     */
    function updatePreview(hex) {
        var prev = document.getElementById('pratcom_preview');
        if (!prev) { return; }
        prev.style.background = hex;
        prev.style.color = wcagTextColor(hex);
    }

    /**
     * Câble les événements bidirectionnels sur une paire (picker, textInput).
     * @param {HTMLInputElement} picker    - <input type="color">
     * @param {HTMLInputElement} textInput - <input type="text"> adjacent
     */
    function bindPair(picker, textInput) {
        // Normalisation initiale : assure que le champ texte affiche toujours
        // du HEX (#rrggbb en minuscules), même si une valeur rgb() était stockée.
        var initial = toHex(picker.value);
        if (initial) {
            picker.value    = initial;
            textInput.value = initial;
            if (picker.id === 'pratcom_primary') { updatePreview(initial); }
        }

        /**
         * Picker → texte + aperçu.
         * 'input'  : déclenché pendant le drag dans le popup natif du navigateur.
         * 'change' : déclenché à la fermeture du popup (saisie hex native Chrome, etc.).
         * Les deux sont nécessaires car le comportement varie selon le navigateur
         * et la méthode de saisie (pipette, roue, champ hex intégré au popup).
         */
        function onPickerUpdate() {
            var hex = toHex(picker.value);
            if (!hex) { return; }
            textInput.value = hex;
            if (picker.id === 'pratcom_primary') { updatePreview(hex); }
        }
        picker.addEventListener('input',  onPickerUpdate);
        picker.addEventListener('change', onPickerUpdate);

        /**
         * Texte → picker + aperçu.
         * Complément (non remplacement) du oninput inline de ThemePalette,
         * qui ne couvre pas la direction inverse.
         */
        textInput.addEventListener('input', function () {
            var hex = toHex(this.value);
            if (!hex) { return; }
            picker.value = hex;
            if (picker.id === 'pratcom_primary') { updatePreview(hex); }
        });
    }

    // Point d'entrée : câble toutes les paires après le rendu du DOM.
    document.addEventListener('DOMContentLoaded', function () {
        // Cible chaque .pc-form-field qui contient un picker de couleur.
        // Structure attendue (AppearanceTab + ThemePalette) :
        //   <div class="pc-form-field">
        //     ...
        //     <div style="display:flex;...">
        //       <input type="color" ...>      ← picker
        //       <input type="text"  ...>      ← champ HEX (nextElementSibling)
        //     </div>
        //     ...
        //   </div>
        var fields = document.querySelectorAll('.pc-form-field');
        fields.forEach(function (field) {
            var picker    = field.querySelector('input[type="color"]');
            var textInput = picker ? picker.nextElementSibling : null;
            if (picker && textInput && textInput.type === 'text') {
                bindPair(picker, textInput);
            }
        });
    });
}());
