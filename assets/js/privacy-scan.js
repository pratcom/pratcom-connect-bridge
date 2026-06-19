/**
 * Pratcom Connect — mini-scan local des temoins (Privacy Free).
 *
 * Charge UNIQUEMENT pour un administrateur connecte (gate cote PHP :
 * current_user_can('manage_options')). Lit les NOMS des temoins presents
 * dans document.cookie cote navigateur et les transmet a une action
 * admin-ajax du site lui-meme, protegee par nonce. Aucune donnee ne quitte
 * le site, aucun appel a un service tiers, aucun suivi de visiteur.
 *
 * Config injectee via wp_localize_script sous window.pratcomPrivacyScan :
 *   { ajaxUrl, action, nonce }
 */
(function () {
  'use strict';

  var cfg = window.pratcomPrivacyScan;
  if (!cfg || !cfg.ajaxUrl || !cfg.action || !cfg.nonce) {
    return;
  }

  // N'envoyer qu'une fois par session de navigation pour ne pas marteler
  // l'endpoint a chaque page vue (cle propre au site).
  var GUARD = 'pratcomPrivacyScanSent';
  try {
    if (window.sessionStorage && window.sessionStorage.getItem(GUARD)) {
      return;
    }
  } catch (e) {
    // sessionStorage indisponible (mode prive strict) : on continue, sans garde.
  }

  function cookieNames() {
    var raw = document.cookie ? document.cookie.split(';') : [];
    var names = [];
    var seen = {};
    for (var i = 0; i < raw.length; i++) {
      var pair = raw[i];
      var eq = pair.indexOf('=');
      var name = (eq > -1 ? pair.slice(0, eq) : pair).trim();
      if (name && !Object.prototype.hasOwnProperty.call(seen, name)) {
        seen[name] = true;
        names.push(name);
      }
    }
    return names;
  }

  function send() {
    var names = cookieNames();
    if (!names.length) {
      return;
    }

    var body = new FormData();
    body.append('action', cfg.action);
    body.append('nonce', cfg.nonce);
    for (var i = 0; i < names.length; i++) {
      body.append('cookies[]', names[i]);
    }

    fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    })
      .then(function () {
        try {
          if (window.sessionStorage) {
            window.sessionStorage.setItem(GUARD, '1');
          }
        } catch (e) {
          // ignore
        }
      })
      .catch(function () {
        // Silencieux : le scan est un confort, jamais bloquant.
      });
  }

  // Laisser le temps aux scripts tiers (analytics, chat, etc.) de deposer
  // leurs temoins avant de lire document.cookie.
  function schedule() {
    window.setTimeout(send, 2500);
  }

  if (document.readyState === 'complete') {
    schedule();
  } else {
    window.addEventListener('load', schedule);
  }
})();
