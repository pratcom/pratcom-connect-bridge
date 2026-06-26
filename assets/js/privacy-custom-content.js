/**
 * Pratcom Connect — editeur de contenu personnalise de la politique de
 * confidentialite (onglet Confidentialite, Privacy v2).
 *
 * Sauvegarde / suppression PAR BLOC via admin-ajax (nonce + capability cote
 * PHP). AUCUN script inline : config injectee par wp_localize_script sous
 * window.pratcomPrivacyCustom = { ajaxUrl, nonce, actions:{save,delete,toggle},
 * max:{blocks,subtitle,content}, i18n:{...} }.
 *
 * « Ajouter » insere une ligne cote client (clone du <template>) ;
 * « Sauvegarder » persiste le bloc (creation si pas d'id, sinon mise a jour) ;
 * « Supprimer » retire le bloc (cote client si non enregistre, sinon AJAX).
 */
(function () {
  'use strict';

  var cfg = window.pratcomPrivacyCustom;
  if (!cfg || !cfg.ajaxUrl || !cfg.nonce || !cfg.actions) {
    return;
  }
  var i18n = cfg.i18n || {};
  var maxBlocks = (cfg.max && cfg.max.blocks) ? cfg.max.blocks : 20;

  var root = document.getElementById('pratcom-custom-content');
  if (!root) {
    return;
  }
  var enabledBox   = document.getElementById('pratcom-custom-enabled');
  var blocksWrap   = document.getElementById('pratcom-custom-blocks');
  var addWrap      = document.getElementById('pratcom-custom-add-wrap');
  var addBtn       = document.getElementById('pratcom-custom-add');
  var tpl          = document.getElementById('pratcom-custom-block-tpl');
  var toggleStatus = document.getElementById('pratcom-custom-toggle-status');

  function post(action, data) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', cfg.nonce);
    Object.keys(data).forEach(function (k) {
      body.append(k, data[k]);
    });
    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    }).then(function (r) {
      return r.json().catch(function () { return { success: false }; });
    });
  }

  function setStatus(el, msg, isError) {
    if (!el) { return; }
    el.textContent = msg || '';
    el.style.color = isError ? '#b32d2e' : '#1a7f37';
  }

  function blockCount() {
    return blocksWrap ? blocksWrap.querySelectorAll('.pratcom-custom-block').length : 0;
  }

  function refreshAddState() {
    if (addBtn) {
      addBtn.disabled = blockCount() >= maxBlocks;
    }
  }

  function fieldVal(row, sel) {
    var el = row.querySelector(sel);
    return el ? el.value : '';
  }

  // ─── Case a cocher (toggle persiste) ───
  if (enabledBox) {
    enabledBox.addEventListener('change', function () {
      var on = enabledBox.checked;
      if (blocksWrap) { blocksWrap.style.display = on ? '' : 'none'; }
      if (addWrap) { addWrap.style.display = on ? '' : 'none'; }
      setStatus(toggleStatus, i18n.saving || '', false);
      post(cfg.actions.toggle, { enabled: on ? '1' : '' }).then(function (res) {
        if (res && res.success) {
          setStatus(toggleStatus, i18n.saved || '', false);
          window.setTimeout(function () { setStatus(toggleStatus, '', false); }, 1500);
        } else {
          setStatus(toggleStatus, i18n.error || 'Error', true);
        }
      }).catch(function () {
        setStatus(toggleStatus, i18n.error || 'Error', true);
      });
    });
  }

  // ─── Ajouter un bloc (client) ───
  if (addBtn && tpl && blocksWrap) {
    addBtn.addEventListener('click', function () {
      if (blockCount() >= maxBlocks) { return; }
      var frag = tpl.content ? tpl.content.cloneNode(true) : null;
      if (!frag) { return; }
      blocksWrap.appendChild(frag);
      refreshAddState();
      var rows = blocksWrap.querySelectorAll('.pratcom-custom-block');
      var last = rows[rows.length - 1];
      if (last) {
        var f = last.querySelector('.pratcom-custom-subtitle-fr');
        if (f) { f.focus(); }
      }
    });
  }

  // ─── Delegation : Sauvegarder / Supprimer ───
  if (blocksWrap) {
    blocksWrap.addEventListener('click', function (e) {
      var target = e.target;
      if (!target || !target.closest) { return; }
      var row = target.closest('.pratcom-custom-block');
      if (!row) { return; }
      if (target.classList.contains('pratcom-custom-save')) {
        saveRow(row);
      } else if (target.classList.contains('pratcom-custom-delete')) {
        deleteRow(row);
      }
    });
  }

  function saveRow(row) {
    var status = row.querySelector('.pratcom-custom-status');
    var id = row.getAttribute('data-block-id') || '';
    setStatus(status, i18n.saving || '', false);
    post(cfg.actions.save, {
      id: id,
      subtitle_fr: fieldVal(row, '.pratcom-custom-subtitle-fr'),
      subtitle_en: fieldVal(row, '.pratcom-custom-subtitle-en'),
      content_fr: fieldVal(row, '.pratcom-custom-content-fr'),
      content_en: fieldVal(row, '.pratcom-custom-content-en')
    }).then(function (res) {
      if (res && res.success && res.data && res.data.id) {
        row.setAttribute('data-block-id', res.data.id);
        setStatus(status, i18n.saved || '', false);
        window.setTimeout(function () { setStatus(status, '', false); }, 1800);
      } else {
        var msg = i18n.error || 'Error';
        if (res && res.data && res.data.error === 'max_blocks') { msg = i18n.maxBlocks || msg; }
        if (res && res.data && res.data.error === 'forbidden') { msg = i18n.forbidden || msg; }
        setStatus(status, msg, true);
      }
    }).catch(function () {
      setStatus(status, i18n.error || 'Error', true);
    });
  }

  function deleteRow(row) {
    var status = row.querySelector('.pratcom-custom-status');
    var id = row.getAttribute('data-block-id') || '';
    if (!id) {
      // Bloc non encore enregistre : retrait cote client uniquement.
      if (row.parentNode) { row.parentNode.removeChild(row); }
      refreshAddState();
      return;
    }
    if (i18n.confirmDelete && !window.confirm(i18n.confirmDelete)) {
      return;
    }
    setStatus(status, i18n.deleting || '', false);
    post(cfg.actions.delete, { id: id }).then(function (res) {
      if (res && res.success) {
        if (row.parentNode) { row.parentNode.removeChild(row); }
        refreshAddState();
      } else {
        setStatus(status, i18n.error || 'Error', true);
      }
    }).catch(function () {
      setStatus(status, i18n.error || 'Error', true);
    });
  }

  refreshAddState();
})();
