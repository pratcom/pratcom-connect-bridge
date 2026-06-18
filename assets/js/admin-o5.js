/**
 * Pratcom Connect Bridge — admin-o5.js
 *
 * Coquille iframe intégrée (canal PREMIUM uniquement). Enqueue par AdminShell
 * seulement si PRATCOM_CONNECT_BRIDGE_CHANNEL === 'premium'. Sur le canal .org
 * (OrgManagePanel, liens sortants) ce script n'est jamais chargé.
 *
 * Rôle : RÉCEPTEUR du contrat postMessage émis par les pages embed du service
 * (connect.pratcom.net) affichées dans les iframes des onglets Chat / Forms /
 * Privacy. Il rend l'iframe « comme un champ natif » :
 *   - kind 'resize'  → ajuste la hauteur de l'iframe (plus de scroll interne) ;
 *   - kind 'changed' (scope 'forms') → rafraîchit la liste des formulaires
 *     sans rechargement manuel (soumission du formulaire de refresh masqué ;
 *     repli : window.location.reload()).
 *
 * CONTRAT (référence — à honorer à l'identique côté embed) :
 *   { source: 'pratcom-connect', kind: 'resize',  height: <number> }
 *   { source: 'pratcom-connect', kind: 'changed', scope:  <string> }
 *
 * SÉCURITÉ : tout message dont e.origin n'est PAS dans la liste blanche
 * (pcEmbed.origins, dérivée des hôtes du service) OU dont
 * e.data.source !== 'pratcom-connect' est ignoré silencieusement.
 *
 * Pas de framework, pas de dépendance. No-harm tant qu'aucun émetteur n'envoie
 * de message : l'iframe garde sa hauteur initiale (CSS) et la liste se
 * rafraîchit via le mécanisme de refresh existant.
 */
(function () {
    'use strict';

    /** Constante du contrat — valeur de e.data.source attendue. */
    var SOURCE = 'pratcom-connect';

    /** Bornes de sécurité pour la hauteur appliquée (px). */
    var MIN_H = 200;
    var MAX_H = 4000;

    /**
     * Liste blanche d'origines autorisées (https://host), injectée par
     * wp_localize_script (pcEmbed.origins). Filet de sécurité : si l'objet
     * localisé est absent, on retombe sur les hôtes publics connus du service.
     */
    var ALLOWED = (typeof window.pcEmbed !== 'undefined' && Array.isArray(window.pcEmbed.origins))
        ? window.pcEmbed.origins
        : ['https://connect.pratcom.net', 'https://api.connect.pratcom.net'];

    /** true si l'origine d'un message fait partie de la liste blanche. */
    function isAllowedOrigin(origin) {
        for (var i = 0; i < ALLOWED.length; i++) {
            if (ALLOWED[i] && origin === ALLOWED[i]) {
                return true;
            }
        }
        return false;
    }

    /** Toutes les iframes premium intégrées de la page (au plus une visible). */
    function embedFrames() {
        return document.querySelectorAll('.pc-embed-wrapper iframe');
    }

    /**
     * Retrouve l'iframe dont le contentWindow a posté le message.
     * Méthode canonique multi-iframes ; repli : l'unique iframe de la page.
     */
    function frameForSource(srcWindow) {
        var frames = embedFrames();
        for (var i = 0; i < frames.length; i++) {
            try {
                if (frames[i].contentWindow === srcWindow) {
                    return frames[i];
                }
            } catch (e) {
                /* accès cross-origin à contentWindow : ignoré, on continue. */
            }
        }
        // Repli : une seule iframe intégrée sur la page → c'est forcément elle.
        return frames.length === 1 ? frames[0] : null;
    }

    /** Applique une hauteur bornée à l'iframe (pas de scroll interne). */
    function applyResize(frame, rawHeight) {
        var h = parseInt(rawHeight, 10);
        if (isNaN(h)) {
            return;
        }
        h = Math.min(Math.max(h, MIN_H), MAX_H);
        frame.style.height = h + 'px';
    }

    /**
     * Rafraîchit la liste des formulaires sans action manuelle.
     * Préfère soumettre le formulaire de refresh masqué (#pc-forms-refresh-form)
     * — son handler purge le cache transient côté serveur puis recharge avec la
     * liste à jour. Repli si absent : rechargement simple de la page.
     */
    function refreshFormsList() {
        var form = document.getElementById('pc-forms-refresh-form');
        if (form && typeof form.submit === 'function') {
            form.submit();
            return;
        }
        window.location.reload();
    }

    /** Gestionnaire unique de message postMessage. */
    function onMessage(e) {
        // 1) Origine sur liste blanche.
        if (!isAllowedOrigin(e.origin)) {
            return;
        }
        // 2) Forme du message conforme au contrat.
        var data = e.data;
        if (!data || typeof data !== 'object' || data.source !== SOURCE) {
            return;
        }

        if (data.kind === 'resize') {
            var frame = frameForSource(e.source);
            if (frame) {
                applyResize(frame, data.height);
            }
            return;
        }

        if (data.kind === 'changed' && data.scope === 'forms') {
            refreshFormsList();
            return;
        }
        // Tout autre kind : ignoré (extension future).
    }

    window.addEventListener('message', onMessage, false);
})();
