/* Vendored from pratcom/connect-chat public/privacy.js v0.4.1 — keep in sync (W7 doc-sync). */
/**
 * Pratcom Connect Privacy v0.4.1 — bannière de consentement Loi 25.
 *
 * Chargé par loader.js (Bridge WP) avec data-client=<workspace_slug>, ou
 * directement : <script src="https://cdn.pratcom.net/privacy.js" data-client="slug" defer></script>
 *
 * v0.4 (2026-06-09) — MODE LOCAL (Privacy Free, plugin .org) :
 * - data-mode="local" + window.__pratcomPrivacyLocal = { config, consentEndpoint }
 *   → aucune requête vers Pratcom : config inline (presets du plugin),
 *   consentements POSTés au REST WordPress local (registre Loi 25 local),
 *   rapport runtime désactivé. data-client facultatif en mode local.
 *
 * v0.4.1 (2026-06-18) — le badge « Propulsé par » utilise config.showBadge.url
 *   comme base du lien (chemin gratuit Bridge : https://pratcom.net/connect/).
 *   Repli inchangé sur https://connect.pratcom.net si aucune url fournie.
 *
 * v0.3 (2026-06-06) :
 * - Toutes les règles CSS préfixées #pratcom-privacy-overlay (spécificité ID)
 *   pour que le padding du card s'applique et résister aux resets du thème hôte.
 * - Réouverture via l'icône = panneau replié (bouton « Personnaliser »).
 * - Aucune fermeture au clic sur le fond (uniquement X en réouverture, ou choix).
 *
 * v0.2 : modal centré bloquant jusqu'au choix (Loi 25 : refus = accepter),
 * panneau perso masqué par défaut, theming de marque (contraste WCAG auto),
 * gate chatbot (le chat ne charge qu'après le choix).
 */
(function () {
  'use strict';
  if (window.__pratcomPrivacyLoaded) return;
  window.__pratcomPrivacyLoaded = true;

  var script = document.currentScript;
  var slug = script && (script.getAttribute('data-client') || script.getAttribute('data-workspace-slug'));

  // ── Mode local (Privacy Free) : config inline + registre WP local ──
  var LOCAL = null;
  try {
    if (script && script.getAttribute('data-mode') === 'local'
      && window.__pratcomPrivacyLocal && window.__pratcomPrivacyLocal.config) {
      LOCAL = window.__pratcomPrivacyLocal;
    }
  } catch (e) {}

  if (!slug) {
    if (LOCAL) { slug = 'local'; }
    else { console.warn('[Pratcom Privacy] attribut data-client manquant'); return; }
  }
  var API;
  try { API = new URL(script.src).origin; } catch (e) { API = 'https://cdn.pratcom.net'; }

  // ── Gate chatbot/popup : signal de résolution du consentement ──
  window.__pratcomPrivacy = window.__pratcomPrivacy || {};
  window.__pratcomPrivacy.present = true;
  window.__pratcomPrivacy.gateResolved = false;
  function resolveGate() {
    if (window.__pratcomPrivacy.gateResolved) return;
    window.__pratcomPrivacy.gateResolved = true;
    try { window.dispatchEvent(new CustomEvent('pratcom:privacy:resolved')); } catch (e) {}
  }

  // ── Baseline trackers (raffinée par la config serveur) ──
  var TRACKERS = [
    { id: 'ga4', category: 'statistics', patterns: ['googletagmanager.com/gtag/js', 'google-analytics.com'] },
    { id: 'gtm', category: 'marketing', patterns: ['googletagmanager.com/gtm.js'] },
    { id: 'google-ads', category: 'marketing', patterns: ['googleadservices.com', 'doubleclick.net', 'googlesyndication.com'] },
    { id: 'meta-pixel', category: 'marketing', patterns: ['connect.facebook.net'] },
    { id: 'hotjar', category: 'statistics', patterns: ['static.hotjar.com', 'script.hotjar.com'] },
    { id: 'clarity', category: 'statistics', patterns: ['clarity.ms'] },
    { id: 'linkedin-insight', category: 'marketing', patterns: ['snap.licdn.com'] },
    { id: 'tiktok-pixel', category: 'marketing', patterns: ['analytics.tiktok.com'] }
  ];

  // ── Fournisseurs d'intégrations tierces (item E) — déposent des témoins ──
  // Catégorie requise par défaut = marketing (publicité/suivi inter-sites).
  // Surchargeable via config.embeds. Tag explicite : data-pratcom-embed.
  var EMBEDS = [
    { id: 'youtube', category: 'marketing', label: 'YouTube', patterns: ['youtube.com/embed/', 'youtube-nocookie.com/embed/', 'youtu.be/'] },
    { id: 'google-maps', category: 'marketing', label: 'Google Maps', patterns: ['google.com/maps/embed', 'maps.google.', '/maps/embed'] },
    { id: 'vimeo', category: 'marketing', label: 'Vimeo', patterns: ['player.vimeo.com'] },
    { id: 'facebook', category: 'marketing', label: 'Facebook', patterns: ['facebook.com/plugins/', 'facebook.com/v'] },
    { id: 'instagram', category: 'marketing', label: 'Instagram', patterns: ['instagram.com/embed'] },
    { id: 'twitter', category: 'marketing', label: 'X (Twitter)', patterns: ['platform.twitter.com', 'twitter.com/widgets', 'twitframe.com'] },
    { id: 'tiktok', category: 'marketing', label: 'TikTok', patterns: ['tiktok.com/embed', 'tiktok.com/player'] },
    { id: 'spotify', category: 'marketing', label: 'Spotify', patterns: ['open.spotify.com/embed'] }
  ];

  // ── État du consentement ──
  var LS_CONSENT = 'pratcom_consent_' + slug;
  var LS_ANON = 'pratcom_anon_id';
  var LS_CFG = 'pratcom_privacy_cfg_' + slug;
  var consent = null;
  try { consent = JSON.parse(localStorage.getItem(LS_CONSENT) || 'null'); } catch (e) {}

  function consentValid(c) { return !!(c && c.expiresAt && Date.now() < c.expiresAt); }
  function isGranted(cat) {
    if (cat === 'necessary') return true;
    return !!(consentValid(consent) && consent.choices && consent.choices[cat] === true);
  }
  function anonId() {
    var id = null;
    try { id = localStorage.getItem(LS_ANON); } catch (e) {}
    if (!id) {
      id = 'a-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
      try { localStorage.setItem(LS_ANON, id); } catch (e) {}
    }
    return id;
  }

  // ── Blocage des trackers ──
  var nativeCreate = document.createElement.bind(document);
  var blockedScripts = [];
  function categoryFor(src) {
    if (!src) return null;
    for (var i = 0; i < TRACKERS.length; i++) {
      for (var j = 0; j < TRACKERS[i].patterns.length; j++) {
        if (src.indexOf(TRACKERS[i].patterns[j]) !== -1) return TRACKERS[i].category;
      }
    }
    return null;
  }
  function shouldBlock(src) {
    var cat = categoryFor(String(src || ''));
    return cat && !isGranted(cat) ? cat : null;
  }
  var mo = new MutationObserver(function (muts) {
    for (var i = 0; i < muts.length; i++) {
      var nodes = muts[i].addedNodes;
      for (var j = 0; j < nodes.length; j++) {
        var n = nodes[j];
        if (n.tagName === 'SCRIPT' && n.src) {
          var cat = shouldBlock(n.src);
          if (cat) {
            blockedScripts.push({ src: n.src, category: cat });
            n.type = 'text/plain';
            n.setAttribute('data-pratcom-blocked', cat);
            try { n.removeAttribute('src'); } catch (e) {}
            if (n.parentNode) n.parentNode.removeChild(n);
          }
        } else if (n.tagName === 'IFRAME') {
          maybeBlockEmbed(n);
        }
      }
    }
  });
  mo.observe(document.documentElement, { childList: true, subtree: true });
  document.createElement = function (tag) {
    var el = nativeCreate.apply(document, arguments);
    if (String(tag).toLowerCase() === 'script') {
      try {
        var proto = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, 'src');
        Object.defineProperty(el, 'src', {
          configurable: true,
          get: function () { return proto.get.call(el); },
          set: function (v) {
            var cat = shouldBlock(v);
            if (cat) {
              blockedScripts.push({ src: String(v), category: cat });
              el.type = 'text/plain';
              el.setAttribute('data-pratcom-blocked', cat);
              return;
            }
            proto.set.call(el, v);
          }
        });
      } catch (e) {}
    }
    return el;
  };
  function release(categories) {
    var remaining = [];
    for (var i = 0; i < blockedScripts.length; i++) {
      var b = blockedScripts[i];
      if (categories.indexOf(b.category) !== -1) {
        var s = nativeCreate('script');
        s.async = true;
        s.src = b.src;
        (document.head || document.documentElement).appendChild(s);
      } else { remaining.push(b); }
    }
    blockedScripts = remaining;
    var tagged = document.querySelectorAll('script[type="text/plain"][data-pratcom-consent]');
    for (var k = 0; k < tagged.length; k++) {
      var t = tagged[k];
      if (categories.indexOf(t.getAttribute('data-pratcom-consent')) !== -1) {
        var ns = nativeCreate('script');
        var dsrc = t.getAttribute('data-src') || t.getAttribute('src');
        if (dsrc) { ns.async = true; ns.src = dsrc; } else { ns.text = t.text || t.textContent; }
        t.parentNode.replaceChild(ns, t);
      }
    }
    try { window.dispatchEvent(new CustomEvent('pratcom:consent', { detail: { granted: categories } })); } catch (e) {}
    restoreEmbeds();
  }

  // ── Google Consent Mode v2 (Privacy v2, item C) ────────────────────────
  // Émet les signaux Google « gtag('consent', …) » selon les catégories
  // Connect, pour les clients qui utilisent Google Ads / GA4 (gtag ou GTM).
  // Activé par config.settings.consentModeV2 (défaut OFF — tier Connect, ou
  // plugin Free qui peuple la config locale). Forme acceptée : true, ou
  // { enabled, mapping, waitForUpdate, adsDataRedaction, urlPassthrough }.
  // 100 % côté client (dataLayer) — aucune dépendance serveur. Le shim cmGtag
  // pousse un objet arguments (comme gtag) pour que GTM/GA le reconnaisse.
  // Doc d'intégration : docs/consent-mode-v2-gtm.md.
  var CM_SIGNALS = ['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage', 'functionality_storage', 'personalization_storage', 'security_storage'];
  var CM_DEFAULT_MAP = {
    necessary: ['security_storage', 'functionality_storage'],
    preferences: ['personalization_storage'],
    statistics: ['analytics_storage'],
    marketing: ['ad_storage', 'ad_user_data', 'ad_personalization']
  };
  var cmDefaultSent = false;
  function cmOptions() {
    var s = config && config.settings && config.settings.consentModeV2;
    if (!s) return null;
    if (s === true) return {};
    if (typeof s === 'object') return s.enabled === false ? null : s;
    return null;
  }
  function cmMap() {
    var m = {};
    Object.keys(CM_DEFAULT_MAP).forEach(function (k) { m[k] = CM_DEFAULT_MAP[k].slice(); });
    var o = cmOptions();
    if (o && o.mapping && typeof o.mapping === 'object') {
      Object.keys(o.mapping).forEach(function (k) { if (Array.isArray(o.mapping[k])) m[k] = o.mapping[k]; });
    }
    return m;
  }
  function cmGtag() { try { (window.dataLayer = window.dataLayer || []).push(arguments); } catch (e) {} }
  function cmState(grantedCat) {
    var map = cmMap(), state = {};
    for (var i = 0; i < CM_SIGNALS.length; i++) state[CM_SIGNALS[i]] = 'denied';
    Object.keys(map).forEach(function (cat) {
      var ok = cat === 'necessary' ? true : !!grantedCat(cat);
      if (ok) { for (var j = 0; j < map[cat].length; j++) state[map[cat][j]] = 'granted'; }
    });
    return state;
  }
  function cmSendDefault() {
    var o = cmOptions();
    if (!o || cmDefaultSent) return;
    cmDefaultSent = true;
    var state = cmState(function () { return false; });
    state.wait_for_update = typeof o.waitForUpdate === 'number' ? o.waitForUpdate : 500;
    cmGtag('consent', 'default', state);
    if (o.adsDataRedaction !== false) cmGtag('set', 'ads_data_redaction', true);
    if (o.urlPassthrough !== false) cmGtag('set', 'url_passthrough', true);
    if (typeof window.gtag !== 'function') { try { window.gtag = cmGtag; } catch (e) {} }
  }
  function cmSendUpdate(choices) {
    if (!cmOptions()) return;
    if (!cmDefaultSent) cmSendDefault();
    cmGtag('consent', 'update', cmState(function (cat) { return !!(choices && choices[cat]); }));
  }

  // ── API ──
  function post(path, body) {
    try {
      var url;
      if (LOCAL) {
        // Mode local : seul le consentement est enregistré (registre WP),
        // le rapport runtime est désactivé (zéro appel serveur Pratcom).
        if (path !== '/consent' || !LOCAL.consentEndpoint) return;
        url = LOCAL.consentEndpoint;
      } else {
        url = API + '/api/privacy/' + encodeURIComponent(slug) + path;
      }
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        keepalive: true
      }).catch(function () {});
    } catch (e) {}
  }

  // ── Textes par défaut (écrasés par config.texts) ──
  var DEFAULT_TEXTS = {
    fr: {
      title: 'Votre vie privée',
      description: 'Ce site utilise des témoins (cookies) : certains sont nécessaires au fonctionnement du site, d’autres servent à mesurer l’audience ou à personnaliser le contenu. Vous pouvez accepter, refuser ou personnaliser ces choix — et les modifier à tout moment.',
      acceptAll: 'Tout accepter', refuseAll: 'Tout refuser', customize: 'Personnaliser',
      confirm: 'Confirmer mes choix', manage: 'Gérer mes témoins', policyLabel: 'Politique de confidentialité',
      close: 'Fermer', footer: '',
      recordLabel: 'Demander mon dossier de consentement', recordIdLabel: 'ID de consentement',
      cookieTable: { show: 'Afficher les témoins ({n})', hide: 'Masquer les témoins', headName: 'Nom', headDomain: 'Domaine', headExpiry: 'Expiration', headDesc: 'Description' },
      embed: { blocked: 'Contenu {provider} bloqué', desc: 'Ce contenu est masqué tant que vous n’avez pas accepté les témoins « {cat} ».', activate: 'Activer et afficher' },
      categories: {
        necessary: { label: 'Nécessaires', desc: 'Indispensables au fonctionnement du site. Toujours actifs — ils ne servent jamais à vous suivre.' },
        statistics: { label: 'Analytiques', desc: 'Nous aident à comprendre comment le site est utilisé afin de l’améliorer. Données agrégées.' },
        marketing: { label: 'Marketing', desc: 'Permettent d’afficher des publicités pertinentes et d’en mesurer l’efficacité. Peuvent vous suivre d’un site à l’autre.' },
        preferences: { label: 'Préférences', desc: 'Mémorisent vos préférences (langue, région) pour personnaliser votre expérience.' }
      }
    },
    en: {
      title: 'Your privacy',
      description: 'This site uses cookies: some are necessary for the site to work, others help us measure traffic or personalize content. You can accept, decline or customize these choices — and change them anytime.',
      acceptAll: 'Accept all', refuseAll: 'Decline all', customize: 'Customize',
      confirm: 'Confirm my choices', manage: 'Manage cookies', policyLabel: 'Privacy Policy',
      close: 'Close', footer: '',
      recordLabel: 'Request my consent record', recordIdLabel: 'Consent ID',
      cookieTable: { show: 'Show cookies ({n})', hide: 'Hide cookies', headName: 'Name', headDomain: 'Domain', headExpiry: 'Expiry', headDesc: 'Description' },
      embed: { blocked: '{provider} content blocked', desc: 'This content stays hidden until you accept “{cat}” cookies.', activate: 'Enable and show' },
      categories: {
        necessary: { label: 'Necessary', desc: 'Essential for the site to function. Always on — never used to track you.' },
        statistics: { label: 'Analytics', desc: 'Help us understand how the site is used so we can improve it. Aggregated data.' },
        marketing: { label: 'Marketing', desc: 'Used to show relevant ads and measure their performance. May track you across websites.' },
        preferences: { label: 'Preferences', desc: 'Remember your preferences (language, region) to personalize your experience.' }
      }
    }
  };

  var config = null;
  var lang = 'fr';
  var T = DEFAULT_TEXTS.fr;

  function resolveLang() {
    var l = (script.getAttribute('data-lang') || document.documentElement.lang || '').slice(0, 2).toLowerCase();
    if (l !== 'fr' && l !== 'en') l = (config && config.settings && config.settings.defaultLang) || 'fr';
    return l === 'en' ? 'en' : 'fr';
  }
  function deepMerge(base, extra) {
    if (!extra) return base;
    var out = {};
    Object.keys(base).forEach(function (k) { out[k] = base[k]; });
    Object.keys(extra).forEach(function (k) {
      if (extra[k] && typeof extra[k] === 'object' && base[k] && typeof base[k] === 'object') {
        out[k] = deepMerge(base[k], extra[k]);
      } else if (extra[k] != null && extra[k] !== '') { out[k] = extra[k]; }
    });
    return out;
  }
  function loadConfig(cb) {
    if (LOCAL) { cb(LOCAL.config); return; }
    var cached = null;
    try { cached = JSON.parse(localStorage.getItem(LS_CFG) || 'null'); } catch (e) {}
    if (cached && cached.fetchedAt && Date.now() - cached.fetchedAt < 3600000) { cb(cached.data); return; }
    fetch(API + '/api/privacy/' + encodeURIComponent(slug) + '/config')
      .then(function (r) { if (!r.ok) throw new Error('config ' + r.status); return r.json(); })
      .then(function (data) {
        try { localStorage.setItem(LS_CFG, JSON.stringify({ fetchedAt: Date.now(), data: data })); } catch (e) {}
        cb(data);
      })
      .catch(function (err) {
        console.warn('[Pratcom Privacy] config indisponible — ' + err.message);
        if (cached) cb(cached.data); else resolveGate();
      });
  }

  // ── Theming : couleur de marque + contraste auto (WCAG) ──
  function hexToRgb(h) {
    h = String(h || '').replace('#', '');
    if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
    if (h.length !== 6) return null;
    var n = parseInt(h, 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  function lum(rgb) {
    var a = rgb.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
  }
  function contrast(l1, l2) { var a = Math.max(l1, l2), b = Math.min(l1, l2); return (a + 0.05) / (b + 0.05); }
  function toHex(rgb) { return '#' + rgb.map(function (v) { return ('0' + Math.max(0, Math.min(255, Math.round(v))).toString(16)).slice(-2); }).join(''); }
  function darken(hex, f) { var c = hexToRgb(hex); if (!c) return hex; return toHex(c.map(function (v) { return v * (1 - f); })); }
  function rgba(hex, a) { var c = hexToRgb(hex); if (!c) return 'rgba(55,123,166,' + a + ')'; return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
  function onColor(hex) { var c = hexToRgb(hex); if (!c) return '#ffffff'; return contrast(lum(c), lum([255, 255, 255])) >= 3.2 ? '#ffffff' : '#10222b'; }
  function readableInk(hex) {
    var c = hexToRgb(hex); if (!c) return hex;
    var white = lum([255, 255, 255]); var h = hex;
    for (var i = 0; i < 9; i++) { var cc = hexToRgb(h); if (contrast(lum(cc), white) >= 4.5) return h; h = darken(h, 0.16); }
    return h;
  }
  function resolveTheme() {
    var t = {};
    try { if (window.__pratcomConnect && window.__pratcomConnect.theme) t = window.__pratcomConnect.theme; } catch (e) {}
    if ((!t || !t.primary) && config && config.theme) t = config.theme;
    var primary = (t && t.primary) || '#377ba6';
    if (!hexToRgb(primary)) primary = '#377ba6';
    return {
      primary: primary,
      primaryStrong: darken(primary, 0.12),
      onPrimary: (t && t.onPrimary && hexToRgb(t.onPrimary)) ? t.onPrimary : onColor(primary),
      ink: readableInk(primary),
      tint: rgba(primary, 0.08)
    };
  }

  // ── UI ──
  var overlayEl = null, cardEl = null, blockingActive = false, prevOverflow = '';
  var lastTrigger = null; // élément à re-focuser à la fermeture (WCAG 2.4.3)
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function el(tag, cls, html) { var e = nativeCreate(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }

  // ── Badge « Propulsé par Pratcom Connect » (Shadow DOM closed) ──────────
  function mountBadge(parentEl, moduleName, slugVal, langVal, align) {
    var host = nativeCreate('div');
    host.setAttribute('data-pratcom-badge', moduleName);
    var text = langVal === 'en'
      ? 'Powered by Pratcom Connect'
      : 'Propulsé par Pratcom Connect';
    // Base du lien : url fournie par le Bridge (config.showBadge.url, p. ex.
    // https://pratcom.net/connect/) sinon repli historique connect.pratcom.net.
    var badgeBase = (config && config.showBadge && typeof config.showBadge.url === 'string'
      && config.showBadge.url) ? config.showBadge.url : 'https://connect.pratcom.net';
    var href = badgeBase + (badgeBase.indexOf('?') !== -1 ? '&' : '?')
      + 'utm_source=badge&utm_medium=' + encodeURIComponent(moduleName)
      + '&utm_content=' + encodeURIComponent(slugVal || '');
    var justify = align === 'flex-end' ? 'flex-end'
      : (align === 'center' ? 'center' : 'flex-start');
    var css = '.pc-badge{display:flex;justify-content:' + justify + ';align-items:center;'
      + 'padding:6px 8px 0;font-family:system-ui,-apple-system,sans-serif;'
      + 'font-size:10px;line-height:1.3}'
      + '.pc-badge a{color:#64748b;text-decoration:none;opacity:.7;'
      + 'transition:opacity .15s;letter-spacing:.02em;white-space:nowrap}'
      + '.pc-badge a:hover{opacity:1;text-decoration:underline}';
    var link = nativeCreate('a');
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = text;
    var wrap = nativeCreate('div');
    wrap.className = 'pc-badge';
    wrap.appendChild(link);
    try {
      var shadow = host.attachShadow({ mode: 'closed' });
      var st = nativeCreate('style');
      st.textContent = css;
      shadow.appendChild(st);
      shadow.appendChild(wrap);
    } catch (e) {
      link.style.cssText = 'color:#64748b;text-decoration:none;font:10px system-ui,-apple-system,sans-serif';
      host.appendChild(link);
    }
    parentEl.appendChild(host);
  }

  // ── Item B (Privacy v2) : tableau par témoin par catégorie ──
  // Sous chaque catégorie de la bannière, liste les témoins catalogués
  // (Nom · Domaine · Expiration · Description) fournis par /config
  // (config.cookies[cat], depuis cookies_catalog). Repliable et accessible
  // (bouton aria-expanded). Aucun rendu si la catégorie n'a aucun témoin —
  // donc zéro impact en mode local / Privacy Free (pas de scan serveur).
  function appendCookieTable(panel, cat) {
    var list = config && config.cookies && config.cookies[cat];
    if (!list || !list.length) return;
    var CT = (T && T.cookieTable) || {};
    var wrap = el('div', 'ppc-cookies');
    var btn = nativeCreate('button');
    btn.type = 'button';
    btn.className = 'ppc-cookies-toggle';
    btn.setAttribute('aria-expanded', 'false');
    var catLbl = (T.categories && T.categories[cat] && T.categories[cat].label) || cat;
    var showLabel = String(CT.show || 'Témoins ({n})').replace('{n}', list.length);
    btn.setAttribute('aria-label', showLabel + ' — ' + catLbl);
    btn.innerHTML = '<span class="ppc-caret">▶</span><span class="ppc-cookies-lbl">' + esc(showLabel) + '</span>';
    var tableWrap = el('div', 'ppc-cookies-wrap');
    var head = '<thead><tr>'
      + '<th>' + esc(CT.headName || 'Nom') + '</th>'
      + '<th>' + esc(CT.headDomain || 'Domaine') + '</th>'
      + '<th>' + esc(CT.headExpiry || 'Expiration') + '</th>'
      + '<th>' + esc(CT.headDesc || 'Description') + '</th>'
      + '</tr></thead>';
    var body = '<tbody>';
    for (var i = 0; i < list.length; i++) {
      var ck = list[i] || {};
      body += '<tr>'
        + '<td><code>' + esc(ck.name) + '</code></td>'
        + '<td class="ppc-ck-domain">' + esc(ck.domain) + '</td>'
        + '<td class="ppc-ck-exp">' + esc(ck.expiry || '—') + '</td>'
        + '<td>' + esc(ck.description || '—') + '</td>'
        + '</tr>';
    }
    body += '</tbody>';
    tableWrap.innerHTML = '<table class="ppc-cookie-table">' + head + body + '</table>';
    btn.onclick = function () {
      var open = tableWrap.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      var lbl = btn.querySelector('.ppc-cookies-lbl');
      if (lbl) lbl.textContent = open ? (CT.hide || 'Masquer') : showLabel;
    };
    wrap.appendChild(btn);
    wrap.appendChild(tableWrap);
    panel.appendChild(wrap);
  }

  // ── Item E (Privacy v2) : placeholders de contenu bloqué ──
  // Remplace les intégrations tierces (YouTube, Maps, réseaux, Vimeo…) par un
  // placeholder tant que la catégorie requise n'est pas accordée. Déblocage
  // inline (bouton) OU automatique au consentement (via release → restoreEmbeds).
  // Détection auto par fournisseur + tag explicite data-pratcom-embed (propre :
  // l'iframe sans src ne charge rien avant consentement). Bloquer = retirer src
  // (stoppe le chargement) puis remplacer par le placeholder.
  var blockedEmbeds = [];
  function resolveEmbedProvider(iframe) {
    var explicit = iframe.getAttribute('data-pratcom-embed');
    if (explicit) {
      return { id: 'custom', category: explicit, label: iframe.getAttribute('data-pratcom-embed-label') || 'Contenu externe', patterns: [] };
    }
    var src = iframe.getAttribute('src') || iframe.getAttribute('data-src') || '';
    if (!src) return null;
    for (var i = 0; i < EMBEDS.length; i++) {
      for (var j = 0; j < EMBEDS[i].patterns.length; j++) {
        if (src.indexOf(EMBEDS[i].patterns[j]) !== -1) return EMBEDS[i];
      }
    }
    return null;
  }
  function buildEmbedPlaceholder(provider, cat, onActivate) {
    var EE = (T && T.embed) || {};
    var catLabel = (T.categories && T.categories[cat] && T.categories[cat].label) || cat;
    var ph = el('div', 'ppc-embed');
    var title = String(EE.blocked || '{provider}').replace('{provider}', provider.label);
    var desc = String(EE.desc || '{cat}').replace('{cat}', catLabel);
    var inner = el('div', 'ppc-embed-in');
    inner.appendChild(el('div', 'ppc-embed-ico', '▶'));
    inner.appendChild(el('div', 'ppc-embed-title', esc(title)));
    inner.appendChild(el('div', 'ppc-embed-desc', esc(desc)));
    var btn = nativeCreate('button');
    btn.type = 'button';
    btn.className = 'ppc-embed-btn';
    btn.textContent = EE.activate || 'Activer';
    btn.onclick = onActivate;
    inner.appendChild(btn);
    ph.appendChild(inner);
    return ph;
  }
  function blockOneEmbed(iframe, provider) {
    if (iframe.__pratcomEmbedBlocked) return;
    var cat = provider.category;
    var realSrc = iframe.getAttribute('src') || iframe.getAttribute('data-src') || '';
    iframe.__pratcomEmbedBlocked = true;
    try { iframe.removeAttribute('src'); } catch (e) {}
    var ph = buildEmbedPlaceholder(provider, cat, function () { grantCategoryInline(cat); });
    blockedEmbeds.push({ iframe: iframe, ph: ph, cat: cat, realSrc: realSrc });
    if (iframe.parentNode) iframe.parentNode.replaceChild(ph, iframe);
  }
  function maybeBlockEmbed(iframe) {
    try {
      if (config && config.settings && config.settings.embedPlaceholders === false) return;
      if (iframe.__pratcomEmbedBlocked) return;
      var p = resolveEmbedProvider(iframe);
      if (p && !isGranted(p.category)) blockOneEmbed(iframe, p);
    } catch (e) {}
  }
  function scanEmbeds() {
    if (config && config.settings && config.settings.embedPlaceholders === false) return;
    injectStyles();
    var list = document.querySelectorAll('iframe[src],iframe[data-src],iframe[data-pratcom-embed]');
    for (var i = 0; i < list.length; i++) {
      var f = list[i];
      if (f.__pratcomEmbedBlocked) continue;
      var p = resolveEmbedProvider(f);
      if (!p) continue;
      if (isGranted(p.category)) {
        var ds = f.getAttribute('data-src');
        if (ds && !f.getAttribute('src')) { try { f.setAttribute('src', ds); } catch (e) {} }
        continue;
      }
      blockOneEmbed(f, p);
    }
  }
  function restoreEmbeds() {
    var remaining = [];
    for (var i = 0; i < blockedEmbeds.length; i++) {
      var b = blockedEmbeds[i];
      if (isGranted(b.cat)) {
        if (b.realSrc) { try { b.iframe.setAttribute('src', b.realSrc); } catch (e) {} }
        b.iframe.__pratcomEmbedBlocked = false;
        if (b.ph.parentNode) b.ph.parentNode.replaceChild(b.iframe, b.ph);
      } else { remaining.push(b); }
    }
    blockedEmbeds = remaining;
  }
  function grantCategoryInline(cat) {
    var current = (consentValid(consent) && consent.choices) ? consent.choices : {};
    var choices = {};
    activeCategories().forEach(function (k) { choices[k] = k === cat ? true : !!current[k]; });
    applyChoice('custom', choices);
  }
  function runEmbeds() {
    if (document.body) scanEmbeds();
    else document.addEventListener('DOMContentLoaded', scanEmbeds);
  }

  function injectStyles() {
    if (document.getElementById('pratcom-privacy-style')) return;
    var c = resolveTheme();
    var O = '#pratcom-privacy-overlay';
    var css = '' +
      O + '{position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1c2b33;line-height:1.5}' +
      O + ' *{box-sizing:border-box;margin:0;padding:0}' +
      O + '.ppc-dim{background:rgba(15,23,28,.55)}' +
      O + ' .ppc-card{pointer-events:auto;width:100%;max-width:468px;background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(16,35,47,.30);padding:32px 32px 26px;position:relative;animation:ppc-in .22s ease-out;max-height:calc(100vh - 40px);overflow-y:auto}' +
      '@keyframes ppc-in{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}' +
      O + ' .ppc-title{font-size:19px;font-weight:700;margin:0 0 12px;letter-spacing:-.01em;padding-right:28px}' +
      O + ' .ppc-desc{font-size:14.5px;line-height:1.55;color:#46565f;margin:0 0 22px}' +
      O + ' .ppc-desc a{color:' + c.ink + ';text-decoration:underline}' +
      O + ' .ppc-actions{display:flex;gap:10px}' +
      O + ' .ppc-btn{flex:1;min-height:46px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;border:1.5px solid ' + c.primary + ';background:#fff;color:' + c.ink + ';transition:background .15s,border-color .15s;font-family:inherit;line-height:1.2}' +
      O + ' .ppc-btn:hover{background:' + c.tint + '}' +
      O + ' .ppc-btn--primary{background:' + c.primary + ';border-color:' + c.primary + ';color:' + c.onPrimary + '}' +
      O + ' .ppc-btn--primary:hover{background:' + c.primaryStrong + ';border-color:' + c.primaryStrong + '}' +
      O + ' .ppc-panel{margin:4px 0 22px}' +
      O + ' .ppc-cat{display:flex;gap:14px;align-items:flex-start;padding:14px 0;border-top:1px solid #edf1f4}' +
      O + ' .ppc-cat:first-child{border-top:none;padding-top:4px}' +
      O + ' .ppc-cat-info{flex:1}' +
      O + ' .ppc-cat-label{font-weight:600;font-size:14px;margin:0 0 3px}' +
      O + ' .ppc-cat-desc{color:#5a6b75;font-size:13px;line-height:1.45}' +
      O + ' .ppc-cookies{margin:8px 0 2px}' +
      O + ' .ppc-cookies-toggle{appearance:none;border:none;background:none;padding:2px 0;min-height:24px;font:inherit;font-size:12px;font-weight:600;color:' + c.ink + ';cursor:pointer;display:inline-flex;align-items:center;gap:6px;border-radius:6px}' +
      O + ' .ppc-cookies-lbl{text-decoration:underline;text-underline-offset:2px}' +
      O + ' .ppc-cookies-toggle:hover{opacity:.8}' +
      O + ' .ppc-caret{display:inline-block;font-size:9px;transition:transform .15s;text-decoration:none}' +
      O + ' .ppc-cookies-toggle[aria-expanded="true"] .ppc-caret{transform:rotate(90deg)}' +
      O + ' .ppc-cookies-wrap{display:none;margin:8px 0 2px;overflow-x:auto;border:1px solid #e6ebef;border-radius:8px}' +
      O + ' .ppc-cookies-wrap.open{display:block}' +
      O + ' .ppc-cookie-table{width:100%;border-collapse:collapse;font-size:11.5px;line-height:1.4;min-width:380px}' +
      O + ' .ppc-cookie-table th{text-align:left;font-weight:600;color:#46565f;background:' + c.tint + ';padding:6px 9px;white-space:nowrap;border-bottom:1px solid #e6ebef}' +
      O + ' .ppc-cookie-table td{padding:6px 9px;border-bottom:1px solid #eef2f4;color:#5a6b75;vertical-align:top}' +
      O + ' .ppc-cookie-table tr:last-child td{border-bottom:none}' +
      O + ' .ppc-cookie-table code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:#10222b;word-break:break-all}' +
      O + ' .ppc-ck-domain{color:#64727c;word-break:break-all}' +
      O + ' .ppc-ck-exp{white-space:nowrap}' +
      O + ' .ppc-switch{position:relative;width:42px;height:24px;flex:0 0 42px;margin-top:2px}' +
      O + ' .ppc-switch input{opacity:0;width:0;height:0}' +
      O + ' .ppc-slider{position:absolute;inset:0;border-radius:24px;background:#cdd7dd;border:1px solid #7f8d96;box-sizing:border-box;transition:.15s;cursor:pointer}' +
      O + ' .ppc-slider:before{content:"";position:absolute;width:18px;height:18px;border-radius:50%;background:#fff;top:3px;left:3px;transition:.15s;box-shadow:0 1px 2px rgba(0,0,0,.2)}' +
      O + ' .ppc-switch input:checked+.ppc-slider{background:' + c.primary + '}' +
      O + ' .ppc-switch input:checked+.ppc-slider:before{transform:translateX(18px)}' +
      O + ' .ppc-switch input:disabled+.ppc-slider{background:' + rgba(c.primary, 0.45) + ';cursor:not-allowed}' +
      O + ' .ppc-footer{color:#64727c;font-size:12px;margin:14px 0 0;line-height:1.45}' +
      O + ' .ppc-record{margin:16px 0 0;padding-top:14px;border-top:1px solid #edf1f4;font-size:11.5px;line-height:1.55;color:#64727c;text-align:center;word-break:break-word}' +
      O + ' .ppc-record code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:#5a6b75}' +
      O + ' .ppc-record a{color:' + c.ink + ';text-decoration:underline;cursor:pointer}' +
      O + ' .ppc-close{position:absolute;top:14px;right:14px;border:none;background:transparent;cursor:pointer;color:#64727c;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0}' +
      O + ' .ppc-close:hover{background:#f1f5f7;color:#46565f}' +
      O + ' .ppc-close svg{width:16px;height:16px}' +
      '.ppc-manage{pointer-events:auto;position:fixed;left:16px;bottom:16px;z-index:2147483647;width:46px;height:46px;border-radius:50%;border:1px solid #dde5ea;background:#fff;box-shadow:0 4px 14px rgba(16,35,47,.22);cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;box-sizing:border-box}' +
      '.ppc-manage:hover{background:' + c.tint + '}' +
      '.ppc-embed{min-height:200px;display:flex;align-items:center;justify-content:center;background:#f3f6f8;border:1px solid #dce3e7;border-radius:12px;padding:24px;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}' +
      '.ppc-embed-in{max-width:380px;text-align:center;color:#46565f}' +
      '.ppc-embed-ico{width:46px;height:46px;border-radius:50%;background:' + c.tint + ';color:' + c.ink + ';display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:18px}' +
      '.ppc-embed-title{font-weight:600;font-size:15px;margin:0 0 6px;color:#1c2b33}' +
      '.ppc-embed-desc{font-size:13px;line-height:1.5;margin:0 0 14px}' +
      '.ppc-embed-btn{border:0;border-radius:10px;background:' + c.primary + ';color:' + c.onPrimary + ';font:inherit;font-size:14px;font-weight:600;padding:10px 18px;cursor:pointer}' +
      '.ppc-embed-btn:hover{background:' + c.primaryStrong + '}' +
      // Accessibilité (WCAG 2.2 AA) : focus clavier visible sur tous les contrôles
      O + ' .ppc-btn:focus-visible,' + O + ' .ppc-cookies-toggle:focus-visible,' + O + ' .ppc-close:focus-visible,' + O + ' .ppc-record a:focus-visible,' + O + ' .ppc-desc a:focus-visible{outline:2px solid #1c2b33;outline-offset:2px;border-radius:8px}' +
      O + ' .ppc-switch input:focus-visible + .ppc-slider{outline:2px solid #1c2b33;outline-offset:2px}' +
      '.ppc-manage:focus-visible,.ppc-embed-btn:focus-visible{outline:2px solid #1c2b33;outline-offset:2px}' +
      // Respect de prefers-reduced-motion (WCAG 2.3.3) : neutralise l'animation d'entrée
      '@media(prefers-reduced-motion:reduce){' + O + ' .ppc-card{animation:none}' + O + ' .ppc-slider,' + O + ' .ppc-slider:before,' + O + ' .ppc-btn,' + O + ' .ppc-caret{transition:none}}' +
      '@media(max-width:520px){' + O + ' .ppc-card{padding:24px 22px 20px}' + O + ' .ppc-actions{flex-direction:column}}' +
      '@media(max-width:480px){html.pratcom-chat-open .ppc-manage{display:none}}';
    var style = el('style'); style.id = 'pratcom-privacy-style'; style.textContent = css;
    (document.head || document.documentElement).appendChild(style);
  }

  function activeCategories() {
    var cats = (config && config.settings && config.settings.categories) || ['statistics', 'marketing'];
    return cats.filter(function (x) { return x !== 'necessary'; });
  }
  function policyUrl() {
    return (config && config.settings && (config.settings.policyUrl || config.settings.policyPageUrl)) || '';
  }

  function lockScroll() { try { var de = document.documentElement; prevOverflow = de.style.overflow || ''; de.style.overflow = 'hidden'; } catch (e) {} }
  function unlockScroll() { try { document.documentElement.style.overflow = prevOverflow || ''; } catch (e) {} }

  function removeOverlay() {
    if (overlayEl && overlayEl.parentNode) overlayEl.parentNode.removeChild(overlayEl);
    overlayEl = null; cardEl = null;
    unlockScroll();
    document.removeEventListener('keydown', onKeydown, true);
    blockingActive = false;
    // Restitution du focus à l'élément déclencheur (WCAG 2.4.3 Focus Order).
    if (lastTrigger && typeof lastTrigger.focus === 'function' && document.contains(lastTrigger)) {
      try { lastTrigger.focus(); } catch (e) {}
    }
    lastTrigger = null;
  }

  function focusableEls() {
    if (!cardEl) return [];
    var all = cardEl.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),[tabindex]:not([tabindex="-1"])');
    var out = [];
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      // Exclure les contrôles masqués (panneau replié display:none, etc.).
      if (el.offsetParent === null && el.getClientRects().length === 0) continue;
      out.push(el);
    }
    return out;
  }
  function onKeydown(e) {
    if (!cardEl) return;
    if (e.key === 'Tab') {
      var f = focusableEls();
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      // Si le focus est hors de la carte (ou nulle part), le ramener dedans.
      if (cardEl && !cardEl.contains(document.activeElement)) { e.preventDefault(); first.focus(); return; }
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    } else if (e.key === 'Escape') {
      if (blockingActive) { e.preventDefault(); }
      else { removeOverlay(); showManageButton(); }
    }
  }

  function showBanner(panelOpen, blocking) {
    injectStyles();
    // Mémoriser l'élément déclencheur pour restituer le focus à la fermeture
    // (sauf si l'appel vient de la carte elle-même).
    var trigger = document.activeElement;
    removeOverlay();
    if (trigger && trigger !== document.body && (!cardEl || !cardEl.contains(trigger))) lastTrigger = trigger;
    blockingActive = !!blocking;

    overlayEl = el('div'); overlayEl.id = 'pratcom-privacy-overlay';
    overlayEl.className = 'ppc-dim';
    var card = el('div', 'ppc-card');
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');
    card.setAttribute('aria-labelledby', 'ppc-title');
    card.setAttribute('aria-describedby', 'ppc-desc');
    card.setAttribute('aria-label', T.title); // repli si le titre est masqué
    cardEl = card;

    if (!blocking) {
      var closeBtn = el('button', 'ppc-close');
      closeBtn.setAttribute('aria-label', T.close);
      closeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="6" y1="18" x2="18" y2="6"/></svg>';
      closeBtn.onclick = function () { removeOverlay(); showManageButton(); };
      card.appendChild(closeBtn);
    }

    var titleEl = el('div', 'ppc-title', esc(T.title));
    titleEl.id = 'ppc-title';
    card.appendChild(titleEl);
    var policy = policyUrl();
    var descHtml = esc(T.description) + (policy ? ' <a href="' + esc(policy) + '" target="_blank" rel="noopener">' + esc(T.policyLabel) + '</a>' : '');
    var descEl = el('div', 'ppc-desc', descHtml);
    descEl.id = 'ppc-desc';
    card.appendChild(descEl);

    var panel = el('div', 'ppc-panel');
    panel.style.display = panelOpen ? 'block' : 'none';
    var cats = ['necessary'].concat(activeCategories());
    var toggles = {};
    cats.forEach(function (cat) {
      var ct = (T.categories && T.categories[cat]) || { label: cat, desc: '' };
      var row = el('div', 'ppc-cat');
      var info = el('div', 'ppc-cat-info');
      info.appendChild(el('div', 'ppc-cat-label', esc(ct.label)));
      info.appendChild(el('div', 'ppc-cat-desc', esc(ct.desc)));
      var sw = el('label', 'ppc-switch');
      var input = nativeCreate('input');
      input.type = 'checkbox';
      input.setAttribute('aria-label', ct.label); // libellé programmatique du toggle
      if (cat === 'necessary') { input.checked = true; input.disabled = true; }
      else { input.checked = isGranted(cat); toggles[cat] = input; }
      sw.appendChild(input); sw.appendChild(el('span', 'ppc-slider'));
      row.appendChild(info); row.appendChild(sw);
      panel.appendChild(row);
      appendCookieTable(panel, cat);
    });
    if (T.footer) panel.appendChild(el('div', 'ppc-footer', esc(T.footer)));
    card.appendChild(panel);

    var actions = el('div', 'ppc-actions');
    var btnCustom = el('button', 'ppc-btn', esc(T.customize));
    var btnRefuse = el('button', 'ppc-btn', esc(T.refuseAll));
    var btnAccept = el('button', 'ppc-btn ppc-btn--primary', esc(T.acceptAll));
    var btnConfirm = el('button', 'ppc-btn ppc-btn--primary', esc(T.confirm));

    btnAccept.onclick = function () { var c = {}; activeCategories().forEach(function (k) { c[k] = true; }); applyChoice('accept_all', c); };
    btnRefuse.onclick = function () { var c = {}; activeCategories().forEach(function (k) { c[k] = false; }); applyChoice('refuse_all', c); };
    btnConfirm.onclick = function () { var c = {}; activeCategories().forEach(function (k) { c[k] = !!(toggles[k] && toggles[k].checked); }); applyChoice('custom', c); };
    btnCustom.onclick = function () { panel.style.display = 'block'; renderActions(true); try { (toggles[activeCategories()[0]] || btnConfirm).focus(); } catch (e) {} };

    function renderActions(expanded) {
      actions.innerHTML = '';
      if (expanded) { actions.appendChild(btnRefuse); actions.appendChild(btnAccept); actions.appendChild(btnConfirm); }
      else { actions.appendChild(btnCustom); actions.appendChild(btnRefuse); actions.appendChild(btnAccept); }
    }
    renderActions(panelOpen);
    card.appendChild(actions);

    if (!LOCAL && (!config || !config.settings || config.settings.recordSelfServe !== false)) {
      var recId = anonId();
      var recUrl = API + '/privacy-record/' + encodeURIComponent(slug) + '?id=' + encodeURIComponent(recId) + '&lang=' + lang;
      var rec = el('div', 'ppc-record');
      rec.innerHTML = esc(T.recordIdLabel) + ' : <code>' + esc(recId) + '</code><br><a href="' + esc(recUrl) + '" target="_blank" rel="noopener">' + esc(T.recordLabel) + '</a>';
      card.appendChild(rec);
    }

    if (!config || !config.showBadge || config.showBadge.privacy !== false) {
      mountBadge(card, 'privacy', slug, lang, 'center');
    }

    overlayEl.appendChild(card);
    (document.body || document.documentElement).appendChild(overlayEl);

    if (blocking) { lockScroll(); }
    document.addEventListener('keydown', onKeydown, true);
    try { card.setAttribute('tabindex', '-1'); card.focus(); } catch (e) {}
  }

  var manageBtn = null;
  function showManageButton() {
    if (!config || !config.settings || config.settings.floatingIcon === false) return;
    if (manageBtn) return;
    injectStyles();
    manageBtn = el('button', 'ppc-manage');
    manageBtn.setAttribute('aria-label', T.manage);
    manageBtn.title = T.manage;
    manageBtn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#46565f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="8.5" cy="10" r="1"/><circle cx="14.5" cy="8.5" r="1"/><circle cx="15" cy="14.5" r="1"/><circle cx="10" cy="15" r="1"/></svg>';
    manageBtn.onclick = function () { showBanner(false, false); };
    (document.body || document.documentElement).appendChild(manageBtn);
  }

  function applyChoice(action, choices) {
    var months = (config && config.settings && config.settings.reconsentMonths) || 12;
    consent = { version: config ? config.version : 0, choices: choices, ts: Date.now(), expiresAt: Date.now() + months * 2628000000 };
    try { localStorage.setItem(LS_CONSENT, JSON.stringify(consent)); } catch (e) {}
    try {
      var flags = activeCategories().map(function (c) { return c + '=' + (choices[c] ? 1 : 0); }).join('&');
      document.cookie = 'pratcom_consent=v' + consent.version + ':' + encodeURIComponent(flags) + ';path=/;max-age=' + (months * 2628000) + ';SameSite=Lax';
    } catch (e) {}
    var granted = Object.keys(choices).filter(function (c) { return choices[c]; });
    if (granted.length) release(granted);
    cmSendUpdate(choices);
    post('/consent', { anonymousId: anonId(), action: action, choices: choices, configVersion: config ? config.version : 1, language: lang, sourceUrl: location.href });
    removeOverlay();
    showManageButton();
    resolveGate();
    maybeReport();
  }

  var reported = false;
  function maybeReport() {
    if (LOCAL || reported || Math.random() > 0.05) return;
    reported = true;
    setTimeout(function () {
      try {
        var cookies = document.cookie.split(';').map(function (c) { return { name: c.split('=')[0].trim() }; }).filter(function (c) { return c.name; }).slice(0, 100);
        var scripts = [];
        var all = document.querySelectorAll('script[src]');
        for (var i = 0; i < all.length && scripts.length < 40; i++) { var src = all[i].src; if (src && src.indexOf(location.hostname) === -1) scripts.push(src); }
        for (var k = 0; k < blockedScripts.length && scripts.length < 50; k++) scripts.push(blockedScripts[k].src);
        post('/report', { cookies: cookies, scripts: scripts, pageUrl: location.href });
      } catch (e) {}
    }, 3000);
  }

  window.pratcomPrivacy = {
    open: function () { showBanner(false, false); },
    getConsent: function () { return consentValid(consent) ? consent.choices : null; }
  };
  document.addEventListener('click', function (ev) {
    var t = ev.target && ev.target.closest && ev.target.closest('[data-pratcom-manage]');
    if (t) { ev.preventDefault(); showBanner(false, false); }
  });

  function boot() {
    loadConfig(function (data) {
      config = data;
      cmSendDefault();
      if (config && config.trackers && config.trackers.length) {
        TRACKERS = config.trackers.map(function (t) { return { id: t.id, category: t.category, patterns: t.scriptPatterns || [] }; });
      }
      lang = resolveLang();
      T = deepMerge(DEFAULT_TEXTS[lang] || DEFAULT_TEXTS.fr, config && config.texts && config.texts[lang]);
      runEmbeds();
      var needsBanner = !consentValid(consent) || (config && consent && config.version > consent.version);
      if (needsBanner) {
        if (document.body) showBanner(false, true);
        else document.addEventListener('DOMContentLoaded', function () { showBanner(false, true); });
      } else {
        resolveGate();
        if (consent && consent.choices) cmSendUpdate(consent.choices);
        if (document.body) showManageButton();
        else document.addEventListener('DOMContentLoaded', showManageButton);
        maybeReport();
      }
    });
  }
  boot();
})();
