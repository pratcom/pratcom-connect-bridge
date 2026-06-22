/**
 * Pratcom Connect — editeur des blocs legaux (item J).
 *
 * Enregistre cote EDITEUR les deux blocs dynamiques :
 *   - pratcom-connect/cookie-declaration
 *   - pratcom-connect/privacy-policy
 *
 * Les blocs sont rendus 100 % cote serveur (render_callback PHP qui delegue
 * aux shortcodes existants). Ce script ne fait que fournir l'experience
 * d'edition : un apercu informatif (titre + aide + choix de langue). Le rendu
 * reel apparait sur la page publiee.
 *
 * DEPENDANCES : uniquement les paquets fournis par WordPress core
 * (wp.blocks, wp.element, wp.blockEditor, wp.components, wp.i18n). AUCUNE
 * ressource externe, aucun framework a builder — conforme WordPress.org et
 * identique en premium.
 *
 * Etiquettes localisees injectees via wp_localize_script -> window.pratcomLegalBlocks.
 */
(function (blocks, element, blockEditor, components, i18n) {
    'use strict';

    if (!blocks || !element || !blockEditor || !components) {
        return;
    }

    var el = element.createElement;
    var Fragment = element.Fragment;
    var registerBlockType = blocks.registerBlockType;
    var useBlockProps = blockEditor.useBlockProps;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var Placeholder = components.Placeholder;

    // Etiquettes (repli en dur si la localisation n'est pas presente).
    var L = window.pratcomLegalBlocks || {};
    function t(key, fallback) {
        return (typeof L[key] === 'string' && L[key] !== '') ? L[key] : fallback;
    }

    var langOptions = [
        { label: t('langAuto', 'Automatique (langue de la page)'), value: 'auto' },
        { label: t('langFr', 'Français'), value: 'fr' },
        { label: t('langEn', 'Anglais'), value: 'en' }
    ];

    /**
     * Fabrique une fonction edit pour un bloc donne.
     * @param {string} title Titre affiche dans l'apercu.
     * @param {string} help  Phrase d'aide sous le titre.
     * @param {string} icon  Dashicon de l'apercu.
     */
    function makeEdit(title, help, icon) {
        return function (props) {
            var attributes = props.attributes || {};
            var setAttributes = props.setAttributes;
            var lang = attributes.lang || 'auto';

            var inspector = el(
                InspectorControls,
                null,
                el(
                    PanelBody,
                    { title: t('langLabel', 'Langue du contenu'), initialOpen: true },
                    el(SelectControl, {
                        label: t('langLabel', 'Langue du contenu'),
                        value: lang,
                        options: langOptions,
                        onChange: function (value) {
                            setAttributes({ lang: value });
                        }
                    })
                )
            );

            var preview = el(
                Placeholder,
                {
                    icon: icon,
                    label: title,
                    instructions: help
                }
            );

            return el(
                Fragment,
                null,
                inspector,
                el('div', useBlockProps(), preview)
            );
        };
    }

    // save = null : rendu dynamique cote serveur (pas de markup persiste).
    function saveNull() {
        return null;
    }

    registerBlockType('pratcom-connect/cookie-declaration', {
        edit: makeEdit(
            t('cookieTitle', 'Déclaration relative aux témoins'),
            t('cookieHelp', 'Tableau des témoins généré automatiquement. L’aperçu réel s’affiche sur la page publiée.'),
            'shield'
        ),
        save: saveNull
    });

    registerBlockType('pratcom-connect/privacy-policy', {
        edit: makeEdit(
            t('policyTitle', 'Politique de confidentialité'),
            t('policyHelp', 'Politique générée à partir du gabarit Pratcom Connect. L’aperçu réel s’affiche sur la page publiée.'),
            'privacy'
        ),
        save: saveNull
    });
}(
    window.wp && window.wp.blocks,
    window.wp && window.wp.element,
    window.wp && window.wp.blockEditor,
    window.wp && window.wp.components,
    window.wp && window.wp.i18n
));
