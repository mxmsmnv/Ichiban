/**
 * Ichiban ProcessIchiban admin panel JS
 * Score circles, GSC widget loader, bulk editor counters
 */
(function() {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    initScoreCircles();
    initScoreBadges();
    initGscCharts();
    initSortableTables();
    initSchemaBuilder();
    initAiWorkspace();
  });

  // Score circles: update conic-gradient dynamically since CSS attr() isn't widely supported
  function initScoreCircles() {
    document.querySelectorAll('.ichiban-score-circle').forEach(el => {
      const score = parseInt(el.dataset.score || 0);
      const colors = getThemeColors(el);
      const color = score >= 80 ? colors.success : score >= 50 ? colors.warning : colors.danger;
      el.style.background = `conic-gradient(${color} ${score}%, ${colors.track} 0)`;
      el.querySelector('span').textContent = score;
    });
  }

  // Score badges
  function initScoreBadges() {
    document.querySelectorAll('.ichiban-score-badge').forEach(el => {
      const score = parseInt(el.dataset.score || 0);
      const colors = getThemeColors(el);
      el.style.background = score >= 80 ? colors.success : score >= 50 ? colors.warning : colors.danger;
      el.style.color = score < 50 ? '#fff' : colors.text;
    });
  }

  function getThemeColors(el) {
    const styles = getComputedStyle(el);
    const css = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;
    return {
      success: css('--pw-alert-success', '#c1e7cd'),
      warning: css('--pw-alert-warning', '#fff0be'),
      danger: css('--pw-error-inline-text-color', '#cd0a0a'),
      track: css('--pw-main-background', '#eee'),
      text: css('--pw-text-color', '#111'),
    };
  }

  function initGscCharts() {
    const charts = Array.from(document.querySelectorAll('.ichiban-gsc-chart-canvas'));
    if (!charts.length) return;
    charts.forEach(canvas => {
      const wrap = canvas.closest('.ichiban-gsc-chart-canvas-wrap');
      const tooltip = wrap ? wrap.querySelector('.ichiban-gsc-chart-tooltip') : null;
      let points = [];
      try {
        points = JSON.parse(canvas.dataset.points || '[]');
      } catch (e) {
        points = [];
      }
      if (!points.length) return;

      const render = hoverX => drawGscChart(canvas, points, tooltip, hoverX);
      render(null);
      window.addEventListener('resize', () => render(null));
      canvas.addEventListener('mousemove', event => {
        const rect = canvas.getBoundingClientRect();
        render(event.clientX - rect.left);
      });
      canvas.addEventListener('mouseleave', () => {
        if (tooltip) tooltip.hidden = true;
        render(null);
      });
    });
  }

  function drawGscChart(canvas, rows, tooltip, hoverX) {
    const ctx = canvas.getContext('2d');
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    const cssWidth = Math.max(320, rect.width || canvas.parentElement.clientWidth || 960);
    const cssHeight = 340;
    canvas.width = Math.round(cssWidth * dpr);
    canvas.height = Math.round(cssHeight * dpr);
    canvas.style.height = `${cssHeight}px`;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssWidth, cssHeight);

    const styles = getComputedStyle(canvas);
    const isDark = document.body.classList.contains('dark-theme') || document.documentElement.classList.contains('dark-theme');
    const text = styles.color || (isDark ? '#f5f5f5' : '#666');
    const grid = styles.borderTopColor || (isDark ? 'rgba(255,255,255,0.22)' : '#d8d8d8');
    const clicksColor = resolveCanvasColor(canvas, '--ichiban-accent', '#eb1d61');
    const impressionsColor = '#2563eb';
    const pad = { top: 30, right: 34, bottom: rows.length <= 8 ? 46 : 64, left: 46 };
    const width = cssWidth - pad.left - pad.right;
    const height = cssHeight - pad.top - pad.bottom;
    const maxClicks = Math.max(1, ...rows.map(row => Number(row.clicks) || 0));
    const maxImpressions = Math.max(1, ...rows.map(row => Number(row.impressions) || 0));
    const xFor = i => pad.left + (rows.length > 1 ? width * i / (rows.length - 1) : width / 2);
    const yFor = (value, max) => pad.top + height - (Number(value) || 0) / max * height;

    ctx.font = '12px sans-serif';
    ctx.lineWidth = 1;
    ctx.strokeStyle = grid;
    ctx.fillStyle = text;
    for (let i = 0; i <= 4; i++) {
      const y = pad.top + height * i / 4;
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(cssWidth - pad.right, y);
      ctx.stroke();
    }
    ctx.fillText(`Clicks max ${maxClicks}`, pad.left, 18);
    const rightLabel = `Impressions max ${maxImpressions}`;
    ctx.fillText(rightLabel, cssWidth - pad.right - ctx.measureText(rightLabel).width, 18);

    drawLine(ctx, rows.map((row, i) => [xFor(i), yFor(row.impressions, maxImpressions)]), impressionsColor);
    drawLine(ctx, rows.map((row, i) => [xFor(i), yFor(row.clicks, maxClicks)]), clicksColor);

    const labelIndexes = getDateLabelIndexes(rows.length);
    ctx.font = rows.length <= 8 ? '12px sans-serif' : '10px sans-serif';
    labelIndexes.forEach(i => {
      const label = rows[i]?.date || '';
      const x = xFor(i);
      ctx.strokeStyle = grid;
      ctx.beginPath();
      ctx.moveTo(x, pad.top + height);
      ctx.lineTo(x, pad.top + height + 5);
      ctx.stroke();
      ctx.save();
      if (rows.length <= 8) {
        const measured = ctx.measureText(label).width;
        const textX = i === 0 ? x : i === rows.length - 1 ? x - measured : x - measured / 2;
        ctx.fillText(label, textX, cssHeight - 12);
      } else {
        ctx.translate(x, cssHeight - 12);
        ctx.rotate(-Math.PI / 4);
        ctx.fillText(label.slice(5), 0, 0);
      }
      ctx.restore();
    });

    if (hoverX == null) return;
    const index = Math.max(0, Math.min(rows.length - 1, Math.round((hoverX - pad.left) / width * (rows.length - 1))));
    const row = rows[index];
    const x = xFor(index);
    ctx.strokeStyle = grid;
    ctx.beginPath();
    ctx.moveTo(x, pad.top);
    ctx.lineTo(x, pad.top + height);
    ctx.stroke();
    drawPoint(ctx, x, yFor(row.impressions, maxImpressions), impressionsColor);
    drawPoint(ctx, x, yFor(row.clicks, maxClicks), clicksColor);
    if (tooltip) {
      tooltip.hidden = false;
      tooltip.innerHTML = `<strong>${escapeHtml(row.date)}</strong><span>Clicks: ${Number(row.clicks) || 0}</span><span>Impressions: ${Number(row.impressions) || 0}</span>`;
      tooltip.style.left = `${Math.min(Math.max(8, x + 12), cssWidth - tooltip.offsetWidth - 8)}px`;
      tooltip.style.top = `${pad.top + 10}px`;
    }
  }

  function getDateLabelIndexes(length) {
    if (length <= 8) {
      return Array.from({ length }, (_, i) => i);
    }
    const indexes = [];
    for (let i = 0; i < length; i += 7) indexes.push(i);
    if (!indexes.includes(length - 1)) indexes.push(length - 1);
    return indexes;
  }

  function drawLine(ctx, points, color) {
    ctx.strokeStyle = color;
    ctx.lineWidth = 3;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.beginPath();
    points.forEach(([x, y], i) => {
      if (i === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    });
    ctx.stroke();
  }

  function drawPoint(ctx, x, y, color) {
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(x, y, 4, 0, Math.PI * 2);
    ctx.fill();
  }

  function resolveCanvasColor(el, name, fallback) {
    const value = getComputedStyle(el).getPropertyValue(name).trim();
    if (!value || value.includes('var(')) return fallback;
    return value;
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
  }

  function initSortableTables() {
    document.querySelectorAll('.ichiban-sortable-table').forEach(table => {
      const headers = Array.from(table.querySelectorAll('thead th'));
      headers.forEach((th, index) => {
        const button = th.querySelector('button[data-sort-type]');
        if (!button) return;
        button.addEventListener('click', () => {
          const type = button.dataset.sortType || 'text';
          const current = th.dataset.sortDirection || '';
          const direction = current === 'asc' ? 'desc' : 'asc';
          headers.forEach(item => {
            item.removeAttribute('data-sort-direction');
            const otherButton = item.querySelector('button[data-sort-type]');
            if (otherButton) otherButton.removeAttribute('aria-sort');
          });
          th.dataset.sortDirection = direction;
          button.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
          sortTable(table, index, type, direction);
        });
      });
    });
  }

  function sortTable(table, columnIndex, type, direction) {
    const tbody = table.tBodies[0];
    if (!tbody) return;
    const factor = direction === 'asc' ? 1 : -1;
    const rows = Array.from(tbody.rows);
    rows.sort((a, b) => {
      const aValue = getSortValue(a.cells[columnIndex], type);
      const bValue = getSortValue(b.cells[columnIndex], type);
      if (type === 'number') return (aValue - bValue) * factor;
      return String(aValue).localeCompare(String(bValue), undefined, { numeric: true, sensitivity: 'base' }) * factor;
    });
    rows.forEach(row => tbody.appendChild(row));
  }

  function getSortValue(cell, type) {
    if (!cell) return type === 'number' ? 0 : '';
    const raw = cell.dataset.sort != null ? cell.dataset.sort : cell.textContent;
    if (type === 'number') {
      const parsed = parseFloat(String(raw).replace(/[^0-9.-]/g, ''));
      return Number.isFinite(parsed) ? parsed : 0;
    }
    return String(raw).trim();
  }

  function initSchemaBuilder() {
    const form = document.querySelector('.ichiban-schema-form');
    if (!form) return;
    const nav = form.querySelector('.ichiban-schema-nav');
    const stack = form.querySelector('.ichiban-schema-editor-stack');
    const addSchemaButton = form.querySelector('.ichiban-schema-add');
    const payloadInput = form.querySelector('.ichiban-schema-payload');
    const editorTemplate = document.getElementById('ichiban-schema-editor-template');
    const rowTemplate = document.getElementById('ichiban-schema-row-template');
    if (!nav || !stack || !addSchemaButton || !payloadInput || !editorTemplate || !rowTemplate) return;

    let nextIndex = parseInt(form.dataset.nextIndex || '0', 10);
    if (!Number.isFinite(nextIndex)) nextIndex = 0;

    const schemaPropertyPresets = {
      Thing: [
        ['name', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['url', 'url'],
        ['image', 'field:images'],
      ],
      CreativeWork: [
        ['headline', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['image', 'field:images'],
        ['author', ''],
        ['datePublished', 'field:created'],
        ['dateModified', 'field:modified'],
      ],
      Article: [
        ['headline', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['image', 'field:images'],
        ['author', ''],
        ['publisher', ''],
        ['datePublished', 'field:created'],
        ['dateModified', 'field:modified'],
      ],
      BlogPosting: [
        ['headline', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['image', 'field:images'],
        ['author', ''],
        ['datePublished', 'field:created'],
        ['dateModified', 'field:modified'],
      ],
      NewsArticle: [
        ['headline', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['image', 'field:images'],
        ['author', ''],
        ['publisher', ''],
        ['datePublished', 'field:created'],
        ['dateModified', 'field:modified'],
      ],
      WebPage: [
        ['name', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['url', 'url'],
        ['mainEntityOfPage', 'url'],
      ],
      WebSite: [
        ['name', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['url', 'url'],
      ],
      Product: [
        ['name', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['image', 'field:images'],
        ['sku', ''],
        ['brand', ''],
        ['offers', ''],
        ['aggregateRating', ''],
        ['review', ''],
      ],
      Organization: [
        ['name', 'field:title'],
        ['url', 'url'],
        ['logo', 'field:images'],
        ['sameAs', ''],
        ['telephone', ''],
        ['email', ''],
        ['address', ''],
      ],
      LocalBusiness: [
        ['name', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['url', 'url'],
        ['image', 'field:images'],
        ['telephone', ''],
        ['email', ''],
        ['address', ''],
        ['openingHours', ''],
        ['geo', ''],
        ['priceRange', ''],
      ],
      Person: [
        ['name', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['image', 'field:images'],
        ['jobTitle', ''],
        ['worksFor', ''],
        ['sameAs', ''],
      ],
      Event: [
        ['name', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['image', 'field:images'],
        ['startDate', ''],
        ['endDate', ''],
        ['location', ''],
        ['organizer', ''],
        ['offers', ''],
      ],
      Recipe: [
        ['name', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['image', 'field:images'],
        ['author', ''],
        ['prepTime', ''],
        ['cookTime', ''],
        ['totalTime', ''],
        ['recipeYield', ''],
        ['recipeIngredient', ''],
        ['recipeInstructions', ''],
        ['nutrition', ''],
      ],
      FAQPage: [
        ['mainEntity', ''],
      ],
      QAPage: [
        ['mainEntity', ''],
      ],
      JobPosting: [
        ['title', 'field:title'],
        ['description', 'field:summary'],
        ['datePosted', 'field:created'],
        ['validThrough', ''],
        ['employmentType', ''],
        ['hiringOrganization', ''],
        ['jobLocation', ''],
      ],
      Course: [
        ['name', 'field:title'],
        ['description', 'field:summary|truncate:160'],
        ['provider', ''],
      ],
      Review: [
        ['itemReviewed', ''],
        ['reviewRating', ''],
        ['author', ''],
        ['reviewBody', 'field:summary'],
      ],
      BreadcrumbList: [
        ['itemListElement', ''],
      ],
      ItemList: [
        ['itemListElement', ''],
      ],
    };

    const presetForType = type => {
      if (schemaPropertyPresets[type]) return schemaPropertyPresets[type];
      if (type.includes('Article')) return schemaPropertyPresets.Article;
      if (type.includes('Event')) return schemaPropertyPresets.Event;
      if (type.includes('Organization')) return schemaPropertyPresets.Organization;
      if (type.includes('Business')) return schemaPropertyPresets.LocalBusiness;
      if (type.includes('Product')) return schemaPropertyPresets.Product;
      if (type.includes('WebPage')) return schemaPropertyPresets.WebPage;
      if (type.includes('CreativeWork')) return schemaPropertyPresets.CreativeWork;
      return schemaPropertyPresets.Thing;
    };

    const activate = index => {
      nav.querySelectorAll('.ichiban-schema-nav-item').forEach(item => {
        item.classList.toggle('is-active', item.dataset.schemaIndex === String(index));
      });
      stack.querySelectorAll('.ichiban-schema-editor-panel').forEach(panel => {
        panel.classList.toggle('is-active', panel.dataset.schemaIndex === String(index));
      });
    };

    const navItemFor = index => Array.from(nav.querySelectorAll('.ichiban-schema-nav-item')).find(item => item.dataset.schemaIndex === String(index));

    const makeNavItem = index => {
      const button = document.createElement('button');
      button.className = 'ichiban-schema-nav-item';
      button.type = 'button';
      button.dataset.schemaIndex = String(index);
      button.innerHTML = '<span class="ichiban-schema-nav-title">New Schema</span><span class="ichiban-schema-nav-meta">Thing · No templates</span>';
      return button;
    };

    const updateNavItem = panel => {
      if (!panel) return;
      const index = panel.dataset.schemaIndex;
      const item = navItemFor(index);
      if (!item) return;
      const name = panel.querySelector('.ichiban-schema-name')?.value.trim();
      const type = panel.querySelector('.ichiban-schema-type')?.value.trim() || 'Thing';
      const templates = panel.querySelector('.ichiban-schema-templates')?.value.trim() || 'No templates';
      item.querySelector('.ichiban-schema-nav-title').textContent = name || type || 'New Schema';
      item.querySelector('.ichiban-schema-nav-meta').textContent = `${type} · ${templates}`;
    };

    const emptyRow = rows => Array.from(rows.querySelectorAll('.ichiban-schema-builder-row')).find(row => {
      const property = row.querySelector('select')?.value || '';
      const customProperty = row.querySelector('[name$="[custom_property]"]')?.value || '';
      const expression = row.querySelector('[name$="[expression]"]')?.value || '';
      return property === '' && customProperty.trim() === '' && expression.trim() === '';
    });

    const nextRowHtml = (panel, rowIndex) => rowTemplate.innerHTML
      .replaceAll('__INDEX__', panel.dataset.schemaIndex)
      .replaceAll('__ROW__', String(rowIndex));

    const appendPropertyRow = (panel, property = '', expression = '') => {
      const rows = panel.querySelector('.ichiban-schema-builder-rows');
      let nextRow = parseInt(panel.dataset.nextRow || '0', 10);
      if (!Number.isFinite(nextRow)) nextRow = rows.children.length;
      let row = emptyRow(rows);
      if (!row) {
        const template = document.createElement('template');
        template.innerHTML = nextRowHtml(panel, nextRow).trim();
        row = template.content.firstElementChild;
        rows.appendChild(row);
        panel.dataset.nextRow = String(nextRow + 1);
      }
      if (property) {
        const select = row.querySelector('select');
        const customProperty = row.querySelector('[name$="[custom_property]"]');
        if (select && Array.from(select.options).some(option => option.value === property)) {
          select.value = property;
          if (customProperty) customProperty.value = '';
        } else if (customProperty) {
          select.value = '';
          customProperty.value = property;
        }
      }
      const expressionInput = row.querySelector('[name$="[expression]"]');
      if (expressionInput && !expressionInput.value.trim()) expressionInput.value = expression;
      return row;
    };

    const applyTypePreset = panel => {
      if (!panel) return;
      const type = panel.querySelector('.ichiban-schema-type')?.value || 'Thing';
      const existing = new Set(Array.from(panel.querySelectorAll('.ichiban-schema-builder-row')).map(row => (
        row.querySelector('[name$="[custom_property]"]')?.value.trim()
        || row.querySelector('select')?.value
        || ''
      )).filter(Boolean));
      presetForType(type).forEach(([property, expression]) => {
        if (existing.has(property)) return;
        appendPropertyRow(panel, property, expression);
        existing.add(property);
      });
    };

    nav.addEventListener('click', event => {
      const button = event.target.closest('.ichiban-schema-nav-item');
      if (!button) return;
      activate(button.dataset.schemaIndex);
    });

    addSchemaButton.addEventListener('click', () => {
      const index = nextIndex++;
      form.dataset.nextIndex = String(nextIndex);
      const html = editorTemplate.innerHTML.replaceAll('__INDEX__', String(index));
      const template = document.createElement('template');
      template.innerHTML = html.trim();
      const panel = template.content.firstElementChild;
      panel.dataset.nextRow = '1';
      nav.appendChild(makeNavItem(index));
      stack.appendChild(panel);
      activate(index);
      applyTypePreset(panel);
    });

    form.addEventListener('click', event => {
      const addRowButton = event.target.closest('.ichiban-schema-add-row');
      if (addRowButton) {
        const panel = addRowButton.closest('.ichiban-schema-editor-panel');
        appendPropertyRow(panel);
        return;
      }

      const rowRemoveButton = event.target.closest('.ichiban-schema-row-remove');
      if (rowRemoveButton) {
        const rows = rowRemoveButton.closest('.ichiban-schema-builder-rows');
        if (rows && rows.children.length > 1) {
          rowRemoveButton.closest('.ichiban-schema-builder-row').remove();
        }
        return;
      }

      const schemaRemoveButton = event.target.closest('.ichiban-schema-remove');
      if (schemaRemoveButton) {
        const panel = schemaRemoveButton.closest('.ichiban-schema-editor-panel');
        const index = panel.dataset.schemaIndex;
        navItemFor(index)?.remove();
        panel.remove();
        const first = nav.querySelector('.ichiban-schema-nav-item');
        if (first) activate(first.dataset.schemaIndex);
      }
    });

    form.addEventListener('input', event => {
      const panel = event.target.closest('.ichiban-schema-editor-panel');
      if (panel) updateNavItem(panel);
    });

    form.addEventListener('change', event => {
      const panel = event.target.closest('.ichiban-schema-editor-panel');
      if (!panel) return;
      updateNavItem(panel);
      if (event.target.classList.contains('ichiban-schema-type')) applyTypePreset(panel);
    });

    form.addEventListener('submit', () => {
      payloadInput.value = JSON.stringify(Array.from(stack.querySelectorAll('.ichiban-schema-editor-panel')).map(panel => ({
        enabled: panel.querySelector('[name$="[enabled]"]')?.checked ? 1 : 0,
        name: panel.querySelector('.ichiban-schema-name')?.value || '',
        type: panel.querySelector('.ichiban-schema-type')?.value || 'Thing',
        custom_type: panel.querySelector('[name$="[custom_type]"]')?.value || '',
        templates: panel.querySelector('.ichiban-schema-templates')?.value || '',
        fields: Array.from(panel.querySelectorAll('.ichiban-schema-builder-row')).map(row => ({
          property: row.querySelector('select')?.value || '',
          custom_property: row.querySelector('[name$="[custom_property]"]')?.value || '',
          expression: row.querySelector('[name$="[expression]"]')?.value || '',
        })),
      })));
    });
  }

  function initAiWorkspace() {
    const form = document.querySelector('.ichiban-ai-test form');
    if (!form) return;
    const textarea = form.querySelector('textarea[name="ai_test_prompt"]');
    const modes = Array.from(form.querySelectorAll('.ichiban-ai-mode input[name="ai_mode"]'));
    if (!textarea || !modes.length) return;
    modes.forEach(input => {
      input.addEventListener('change', () => {
        modes.forEach(mode => {
          const label = mode.closest('.ichiban-ai-mode');
          if (label) label.classList.toggle('is-active', mode.checked);
        });
        if (input.checked && input.dataset.prompt) {
          textarea.value = input.dataset.prompt;
        }
      });
    });
  }

})();
