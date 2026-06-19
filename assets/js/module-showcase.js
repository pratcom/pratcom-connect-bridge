/**
 * Pratcom Connect Bridge — Vitrines de modules verrouilles (W4).
 *
 * Sequence les demos animees (Chat / Forms / Privacy) en boucle douce, sans
 * aucune dependance. Charge page-scoped par l'onglet concerne. Respecte
 * prefers-reduced-motion : si l'utilisateur l'a active, on ne joue rien et le
 * CSS affiche l'etat final fige. Pose des classes sur l'element .pcw-demo :
 *   .pcw-anim        une fois la demo prete a etre animee
 *   .pcw-step-N      revele les elements [data-step] jusqu'a N
 *   (specifiques)    .pcw-typing-N / .pcw-pressed / .pcw-blocked / .pcw-minimized
 *
 * Aucune balise inline (revue WordPress.org) : tout le JS vit ici.
 */
(function () {
    'use strict';

    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function lang(demo) {
        var host = demo.closest ? demo.closest('.pcw-showcase') : null;
        var l = host && host.getAttribute('data-pcw-lang');
        return l === 'en' ? 'en' : 'fr';
    }

    /* Bascule le texte FR/EN des elements porteurs de data-fr / data-en. */
    function applyLang(demo, l) {
        var nodes = demo.querySelectorAll('[data-fr],[data-en]');
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            var val = n.getAttribute('data-' + l);
            if (val !== null) {
                n.textContent = val;
            }
        }
    }

    function clearSteps(demo, max) {
        demo.classList.remove(
            'pcw-step-1', 'pcw-step-2', 'pcw-step-3',
            'pcw-step-4', 'pcw-step-5', 'pcw-step-6',
            'pcw-typing-1', 'pcw-typing-2', 'pcw-typing-3',
            'pcw-pressed', 'pcw-blocked', 'pcw-minimized'
        );
    }

    /* Promesse-timer simple. */
    function wait(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    /* Effet machine a ecrire dans un .pcw-typed du champ a l'index donne. */
    function typeField(demo, stepEl, l) {
        var typed = stepEl.querySelector('.pcw-typed');
        if (!typed) { return wait(300); }
        var text = typed.getAttribute('data-text-' + l) || typed.getAttribute('data-text') || '';
        typed.setAttribute('data-typing', '1'); // neutralise le ::after statique
        typed.textContent = '';
        var i = 0;
        return new Promise(function (resolve) {
            (function step() {
                if (i > text.length) { resolve(); return; }
                typed.textContent = text.slice(0, i);
                i++;
                setTimeout(step, 38);
            }());
        });
    }

    /* --- Sequences par type de demo ----------------------------------- */

    function playChat(demo) {
        // Tours : visiteur, saisie, bot, visiteur, pastille lead.
        return Promise.resolve()
            .then(function () { demo.classList.add('pcw-step-1'); return wait(1100); })
            .then(function () { demo.classList.add('pcw-step-2'); return wait(900); })   // typing
            .then(function () { demo.classList.add('pcw-step-3'); return wait(1300); })  // reponse bot
            .then(function () { demo.classList.add('pcw-step-4'); return wait(1200); })  // visiteur
            .then(function () { demo.classList.add('pcw-step-5'); return wait(2400); }); // lead
    }

    function playForms(demo, l) {
        var fields = demo.querySelectorAll('.pcw-field');
        return Promise.resolve()
            .then(function () { demo.classList.add('pcw-step-1', 'pcw-typing-1'); return typeField(demo, fields[0], l); })
            .then(function () { demo.classList.remove('pcw-typing-1'); return wait(200); })
            .then(function () { demo.classList.add('pcw-step-2', 'pcw-typing-2'); return typeField(demo, fields[1], l); })
            .then(function () { demo.classList.remove('pcw-typing-2'); return wait(200); })
            .then(function () { demo.classList.add('pcw-step-3', 'pcw-typing-3'); return typeField(demo, fields[2], l); })
            .then(function () { demo.classList.remove('pcw-typing-3'); return wait(350); })
            .then(function () { demo.classList.add('pcw-step-4'); return wait(600); })    // case cochee
            .then(function () { demo.classList.add('pcw-step-5', 'pcw-pressed'); return wait(260); }) // bouton enfonce
            .then(function () { demo.classList.remove('pcw-pressed'); return wait(450); })
            .then(function () { demo.classList.add('pcw-step-6'); return wait(2200); });  // lead
    }

    function playPrivacy(demo) {
        return Promise.resolve()
            .then(function () { demo.classList.add('pcw-step-1'); return wait(1100); })    // banniere
            .then(function () { demo.classList.add('pcw-step-2', 'pcw-blocked'); return wait(1900); }) // traceur bloque
            .then(function () { demo.classList.add('pcw-step-3'); return wait(900); })     // prepare la FAB
            .then(function () { demo.classList.add('pcw-minimized'); return wait(2200); }); // reduction en icone
    }

    function runLoop(demo) {
        var kind = demo.getAttribute('data-pcw-demo');
        var l = lang(demo);
        applyLang(demo, l);
        demo.classList.add('pcw-anim');

        function cycle() {
            clearSteps(demo);
            // Petit temps mort entre deux boucles pour que le reset soit propre.
            wait(500)
                .then(function () {
                    if (kind === 'chat') { return playChat(demo); }
                    if (kind === 'forms') { return playForms(demo, l); }
                    if (kind === 'privacy') { return playPrivacy(demo); }
                    return wait(1000);
                })
                .then(function () { return wait(900); })
                .then(cycle);
        }
        cycle();
    }

    function init() {
        var demos = document.querySelectorAll('.pcw-demo[data-pcw-demo]');
        for (var i = 0; i < demos.length; i++) {
            var demo = demos[i];
            // Toujours appliquer la langue (meme en reduced-motion).
            applyLang(demo, lang(demo));
            if (REDUCED) {
                continue; // le CSS affiche l'etat final fige, pas d'animation.
            }
            runLoop(demo);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
