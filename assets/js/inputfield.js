/**
 * Ichiban InputfieldIchiban JS
 * SERP preview, social preview, character counters
 * Works with UIkit tabs — no Alpine dependency.
 */
(function() {
  'use strict';

  function ichibanBase() {
    const cfg = (typeof ProcessWire !== 'undefined' && ProcessWire.config && ProcessWire.config.Ichiban) || {};
    if (cfg.adminUrl) return cfg.adminUrl.replace(/\/$/, '');
    const baseHref = document.querySelector('base')?.href || window.location.origin + '/';
    return baseHref.replace(/\/$/, '') + '/ichiban';
  }

  // Init after DOM is ready (UIkit may load after DOMContentLoaded)
  function init() {
    document.querySelectorAll('.ichiban-wrap').forEach(wrap => {
      if (wrap.dataset.ichibanInit) return;
      wrap.dataset.ichibanInit = '1';
      initIchibanField(wrap);
    });
  }

  document.addEventListener('DOMContentLoaded', init);
  // Also fire after UIkit components render (tab switches inject content)
  document.addEventListener('UIkit:init', init);

  function initIchibanField(wrap) {
    ensureActiveSwitcher(wrap);

    // Character counters
    wrap.querySelectorAll('.ichiban-source-value').forEach(input => {
      const counter = input.closest('.uk-width-expand')?.querySelector('.ichiban-char-counter')
                   || input.parentElement.querySelector('.ichiban-char-counter');
      const warnAt = parseInt(input.dataset.warnAt || 0);
      const maxAt  = parseInt(input.dataset.maxAt  || 0);
      const update = () => {
        const key = input.dataset.key || '';
        const value = key ? getSeoValue(wrap, key) : input.value.trim();
        updateCounter(counter, value.length, warnAt, maxAt);
      };
      input.addEventListener('input', update);
      input.closest('.ichiban-source-field')?.querySelector('.ichiban-source-mode')?.addEventListener('change', update);
      update();
    });

    // SERP preview
    const serpWrap = wrap.querySelector('.ichiban-serp');
    if (serpWrap) initSerpPreview(wrap, serpWrap);

    // Social preview
    const socialWrap = wrap.querySelector('.ichiban-social-preview');
    if (socialWrap) initSocialPreview(wrap, socialWrap);

    initResolvedHints(wrap);

    const gscWidget = wrap.querySelector('.ichiban-gsc-widget');
    if (gscWidget) initGscWidget(gscWidget);

    // Desktop/Mobile SERP toggle
    if (serpWrap) {
      serpWrap.querySelectorAll('.ichiban-serp-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          serpWrap.querySelectorAll('.ichiban-serp-btn').forEach(b => b.classList.remove('ichiban-serp-btn--active'));
          btn.classList.add('ichiban-serp-btn--active');
          const card = serpWrap.querySelector('.ichiban-serp-card');
          if (card) card.classList.toggle('is-mobile', btn.dataset.mode === 'mobile');
        });
      });
    }

    // Revision restore
    wrap.querySelectorAll('.ichiban-rev-restore').forEach(btn => {
      btn.addEventListener('click', e => {
        e.preventDefault();
        restoreRevision(btn.dataset.revId, wrap);
      });
    });
  }

  function initGscWidget(widget) {
    const pageId = widget.dataset.pageId || '';
    if (!pageId) return;
    const endpoint = ichibanBase() + '/ajax-gsc-page/?page_id=' + encodeURIComponent(pageId);
    fetch(endpoint)
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(data => {
        if (!data.ok || !data.metrics) throw new Error('empty');
        const m = data.metrics;
        widget.innerHTML = '<h4 class="uk-card-title">Search Performance</h4>'
          + '<div class="uk-grid uk-grid-small uk-child-width-1-4@m" uk-grid>'
          + metricHtml('Clicks', m.clicks)
          + metricHtml('Impressions', m.impressions)
          + metricHtml('CTR', m.ctr)
          + metricHtml('Position', m.position)
          + '</div>';
      })
      .catch(() => {
        const text = widget.querySelector('p');
        if (text) text.textContent = 'No Search Console data available.';
      });
  }

  function metricHtml(label, value) {
    return '<div><span class="uk-text-bold">' + escapeHtml(String(value ?? 0)) + '</span>'
      + '<div class="uk-text-small uk-text-muted">' + escapeHtml(label) + '</div></div>';
  }

  function ensureActiveSwitcher(wrap) {
    const tabs = wrap.querySelectorAll('.uk-tab > li');
    const panels = wrap.querySelectorAll('.uk-switcher > li');
    if (!tabs.length || !panels.length) return;

    if (!wrap.querySelector('.uk-switcher > .uk-active')) {
      tabs[0].classList.add('uk-active');
      panels[0].classList.add('uk-active');
    }
  }

  // -------------------------------------------------------------------------
  // Character counter
  // -------------------------------------------------------------------------
  function updateCounter(el, len, warnAt, maxAt) {
    if (!el) return;
    el.textContent = len + ' chars';
    el.classList.remove('ichiban-counter--ok', 'ichiban-counter--warn', 'ichiban-counter--over');
    // Update the length bar fill (sibling in .ichiban-counter-row)
    const row = el.closest('.ichiban-counter-row');
    const fill = row ? row.querySelector('.ichiban-len-fill') : null;
    if (fill) {
      const pct = maxAt ? Math.min(100, (len / maxAt) * 100) : 0;
      fill.style.width = pct + '%';
      fill.classList.remove('ichiban-len-fill--warn', 'ichiban-len-fill--over');
      if (maxAt && len > maxAt)        fill.classList.add('ichiban-len-fill--over');
      else if (warnAt && len > warnAt) fill.classList.add('ichiban-len-fill--warn');
    }
    if (maxAt && len > maxAt)        el.classList.add('ichiban-counter--over');
    else if (warnAt && len > warnAt) el.classList.add('ichiban-counter--warn');
    else if (len > 0)                el.classList.add('ichiban-counter--ok');
  }

  // -------------------------------------------------------------------------
  // SERP preview
  // -------------------------------------------------------------------------
  function initSerpPreview(wrap, serpEl) {
    const titleEl   = serpEl.querySelector('.ichiban-serp-title-text');
    const snippetEl = serpEl.querySelector('.ichiban-serp-snippet');

    const getVal = key => getSeoValue(wrap, key);

    const update = () => {
      const title = getVal('meta_title');
      const desc  = getVal('meta_description');
      if (titleEl) {
        titleEl.textContent = title || 'Untitled page';
        titleEl.classList.toggle('is-empty', !title);
      }
      if (snippetEl) snippetEl.textContent = truncate(desc || serpEl.dataset.fallbackDesc || '', 160);
    };

    wrap.querySelectorAll('input[type=text], textarea').forEach(el => el.addEventListener('input', update));
    wrap.querySelectorAll('.ichiban-source-mode').forEach(el => el.addEventListener('change', update));
    update();
  }

  // -------------------------------------------------------------------------
  // Social preview
  // -------------------------------------------------------------------------
  function initSocialPreview(wrap, box) {
    const getVal = key => getSeoValue(wrap, key);

    const update = () => {
      const title = getVal('og_title') || getVal('meta_title') || '';
      const desc  = truncate(getVal('og_description') || getVal('meta_description'), 100);
      const img   = getVal('og_image');

      const fbTitle = box.querySelector('.ichiban-fb-title');
      const fbDesc  = box.querySelector('.ichiban-fb-desc');
      const twTitle = box.querySelector('.ichiban-tw-title');
      const liTitle = box.querySelector('.ichiban-li-title');
      if (fbTitle) fbTitle.textContent = title;
      if (fbDesc)  fbDesc.textContent  = desc;
      if (twTitle) twTitle.textContent = title;
      if (liTitle) liTitle.textContent = title;

      if (img) {
        // Wrap URL in quotes for safe CSS url() usage — don't use CSS.escape (it's for identifiers, not URLs)
        const safeUrl = img.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
        ['.ichiban-fb-image', '.ichiban-tw-image', '.ichiban-li-image'].forEach(sel => {
          const el = box.querySelector(sel);
          if (el) { el.style.backgroundImage = `url("${safeUrl}")`; el.style.backgroundSize = 'cover'; }
        });
      } else {
        ['.ichiban-fb-image', '.ichiban-tw-image', '.ichiban-li-image'].forEach(sel => {
          const el = box.querySelector(sel);
          if (el) el.style.backgroundImage = '';
        });
      }
    };

    wrap.querySelectorAll('input[type=text], textarea').forEach(el => el.addEventListener('input', update));
    wrap.querySelectorAll('.ichiban-source-mode').forEach(el => el.addEventListener('change', update));
    update();
  }

  // -------------------------------------------------------------------------
  // Revision restore — AJAX
  // -------------------------------------------------------------------------
  function restoreRevision(revId, wrap) {
    if (!confirm('Restore to this revision? The page will reload.')) return;

    // PW CSRF token — find from form or ProcessWire config JS variable
    const csrf = document.querySelector('input[name^="TOKEN"], input[name^="_post_token"]');
    const csrfName  = csrf ? csrf.name  : (typeof ProcessWire !== 'undefined' && ProcessWire.config ? ProcessWire.config.csrfTokenName : '_post_token');
    const csrfValue = csrf ? csrf.value : (typeof ProcessWire !== 'undefined' && ProcessWire.config ? ProcessWire.config.csrfTokenValue : '');

    const endpoint = ichibanBase() + '/ajax-restore-revision/';

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `rev_id=${encodeURIComponent(revId)}&${encodeURIComponent(csrfName)}=${encodeURIComponent(csrfValue)}`,
    })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(data => {
      if (data.ok) {
        window.location.reload();
      } else {
        alert('Failed to restore revision.');
      }
    })
    .catch(() => alert('Network error during restore.'));
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------
  function truncate(str, max) {
    if (!str) return '';
    if (str.length <= max) return str;
    return str.slice(0, max - 1) + '…';
  }

  function getSeoValue(wrap, key) {
    const sourceInput = wrap.querySelector(`[name$="[${key}_value]"], [name$="[${key}][value]"]`);
    if (sourceInput) {
      const field = sourceInput.closest('.ichiban-source-field');
      const mode = field ? field.querySelector('.ichiban-source-mode')?.value : '';
      if (mode === 'custom') return sourceInput.value.trim();
      return (sourceInput.dataset.resolved || sourceInput.value || '').trim();
    }
    const el = wrap.querySelector(`[name$="[${key}]"]`);
    if (!el) return '';
    const value = el.value.trim();
    if (el.dataset.resolved && isSourceExpression(value)) {
      return el.dataset.resolved.trim();
    }
    return value;
  }

  function isSourceExpression(value) {
    if (!value) return false;
    const fieldPath = '[A-Za-z0-9_][A-Za-z0-9_:.]*(?:\\|[A-Za-z0-9_:-]+)*';
    return new RegExp('^\\{' + fieldPath + '\\}$').test(value)
      || new RegExp('^field:' + fieldPath + '$').test(value)
      || new RegExp('^[A-Za-z0-9_]+[.:][A-Za-z0-9_:.]*(?:\\|[A-Za-z0-9_:-]+)*$').test(value);
  }

  function initResolvedHints(wrap) {
    wrap.querySelectorAll('[data-resolved][data-key]').forEach(input => {
      const hint = input.closest('.uk-form-controls')?.querySelector('.ichiban-resolved-value');
      if (!hint) return;
      const update = () => {
        const value = input.value.trim();
        const resolved = input.dataset.resolved || '';
        const field = input.closest('.ichiban-source-field');
        const mode = field ? field.querySelector('.ichiban-source-mode')?.value : '';
        const show = resolved && resolved !== value && (mode === 'field' || mode === 'inherit' || isSourceExpression(value));
        hint.hidden = !show;
        const link = hint.querySelector('a');
        if (link && show) {
          link.href = resolved;
          link.textContent = resolved;
        }
        const code = hint.querySelector('code');
        if (code && show) code.textContent = resolved;
      };
      input.addEventListener('input', update);
      input.closest('.ichiban-source-field')?.querySelector('.ichiban-source-mode')?.addEventListener('change', update);
      update();
    });
  }

  function escapeHtml(str) {
    return str.replace(/[&<>"']/g, ch => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    })[ch]);
  }

})();
