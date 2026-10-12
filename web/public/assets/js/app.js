// Examtopics front-end. Vanilla JavaScript, no framework and no Bootstrap JS. Every dynamic
// value is inserted with textContent or DOM APIs (never innerHTML), and sizes are set through
// the CSSOM because the CSP forbids inline styles.
(function () {
  'use strict';

  var root = document.documentElement;
  var app = document.querySelector('.app');
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }

  function icon(name, extra) {
    var i = el('i', 'bi bi-' + name + (extra ? ' ' + extra : ''));
    i.setAttribute('aria-hidden', 'true');
    return i;
  }

  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }

  // A message in the flash area, like the server's flash messages.
  function showFlash(type, text) {
    var stack = document.querySelector('.flash-stack');
    if (!stack) return;
    var icons = { success: 'check-circle', info: 'info-circle', warning: 'exclamation-triangle', danger: 'exclamation-octagon' };
    var alert = el('div', 'alert alert-' + type);
    alert.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    alert.appendChild(icon(icons[type] || 'info-circle'));
    alert.appendChild(el('div', 'alert-body', text));
    var close = el('button', 'alert-close');
    close.type = 'button';
    close.setAttribute('data-dismiss', 'alert');
    close.setAttribute('aria-label', 'Dismiss');
    close.appendChild(icon('x-lg'));
    alert.appendChild(close);
    stack.appendChild(alert);
    stack.hidden = false;
  }

  function store(key, value) {
    try { localStorage.setItem(key, value); } catch (e) { /* storage unavailable */ }
  }

  // --- Theme ------------------------------------------------------------------------------
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function applyTheme(choice) {
    root.setAttribute('data-theme-choice', choice);
    root.setAttribute('data-theme', choice === 'system' ? (mq && mq.matches ? 'dark' : 'light') : choice);
  }
  if (mq && mq.addEventListener) {
    mq.addEventListener('change', function () {
      if (root.getAttribute('data-theme-choice') === 'system') applyTheme('system');
    });
  }
  function mirror(key, value) {
    var saved = {};
    try { saved = JSON.parse(localStorage.getItem('examtopics.appearance') || '{}') || {}; } catch (e) { saved = {}; }
    saved[key] = value;
    store('examtopics.appearance', JSON.stringify(saved));
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action="theme-toggle"]');
    if (!btn) return;
    // Flip what is on screen, whatever the stored choice ("system" resolving to dark goes light).
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    mirror('theme', next);
    var url = btn.getAttribute('data-save-url');
    if (url) {
      // keepalive: the save still completes if the user navigates away straight after.
      fetch(url, {
        method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf, Accept: 'application/json' },
        body: new URLSearchParams({ theme: next })
      }).then(function (r) {
        if (r.ok) return;
        return r.json().catch(function () { return {}; }).then(function (d) {
          showFlash('danger', (d && d.error) || 'Your theme could not be saved, so it will reset on the next page.');
        });
      }).catch(function () {
        showFlash('warning', 'Your theme could not be saved because the server did not respond. It may reset on the next page.');
      });
    }
  });

  // Live preview on the appearance form; saving is a normal form submit.
  var appearanceForm = document.querySelector('form[data-appearance]');
  if (appearanceForm) {
    appearanceForm.addEventListener('change', function (e) {
      var t = e.target;
      if (!t.name) return;
      if (t.name === 'theme') applyTheme(t.value);
      else if (t.name === 'motion') { if (t.value === 'reduced') root.setAttribute('data-motion', 'reduced'); else root.removeAttribute('data-motion'); }
      else if (['accent', 'density', 'surface'].indexOf(t.name) !== -1) root.setAttribute('data-' + t.name, t.value);
    });
  }

  // --- Sidebar: collapse on desktop, drawer below 992px ---------------------------------------
  var narrow = window.matchMedia('(max-width: 991.98px)');
  if (app) {
    if (root.getAttribute('data-sidebar-init') === 'collapsed') app.setAttribute('data-sidebar', 'collapsed');
    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-action="sidebar-toggle"]')) {
        if (narrow.matches) {
          app.setAttribute('data-drawer', app.getAttribute('data-drawer') === 'open' ? 'closed' : 'open');
          return;
        }
        var next = app.getAttribute('data-sidebar') === 'collapsed' ? 'expanded' : 'collapsed';
        app.setAttribute('data-sidebar', next);
        if (next === 'collapsed') root.setAttribute('data-sidebar-init', 'collapsed');
        else root.removeAttribute('data-sidebar-init');
        store('examtopics.sidebar', next);
        return;
      }
      if (e.target.classList && e.target.classList.contains('sidebar-backdrop')) app.setAttribute('data-drawer', 'closed');
      if (app.getAttribute('data-drawer') === 'open' && e.target.closest('.sidebar-nav a')) app.setAttribute('data-drawer', 'closed');
    });
    var onNarrowChange = function () { if (!narrow.matches) app.setAttribute('data-drawer', 'closed'); };
    if (narrow.addEventListener) narrow.addEventListener('change', onNarrowChange);
  }

  // Shadow under the top bar once the page scrolls.
  var topbar = document.querySelector('.app-topbar');
  if (topbar) {
    var onScroll = function () { topbar.classList.toggle('is-scrolled', window.scrollY > 4); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // --- User menu --------------------------------------------------------------------------
  var menu = document.querySelector('.user-menu');
  function setMenu(open) {
    if (!menu) return;
    menu.setAttribute('data-open', open ? 'true' : 'false');
    menu.querySelector('.user-menu-panel').hidden = !open;
    menu.querySelector('[data-action="user-menu-toggle"]').setAttribute('aria-expanded', String(open));
  }
  document.addEventListener('click', function (e) {
    if (!menu) return;
    if (e.target.closest('[data-action="user-menu-toggle"]')) { setMenu(menu.getAttribute('data-open') !== 'true'); return; }
    if (menu.getAttribute('data-open') === 'true' && !e.target.closest('.user-menu')) setMenu(false);
  });

  // --- Dismissible alerts -----------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-dismiss="alert"]');
    if (b && b.closest('.alert')) b.closest('.alert').remove();
  });

  // --- Command palette (Ctrl K / Cmd K) ------------------------------------------------------
  var palette = document.getElementById('palette');
  var paletteInput = document.getElementById('palette-input');
  var paletteList = document.getElementById('palette-list');
  var paletteEmpty = palette ? palette.querySelector('.palette-empty') : null;
  var paletteLoaded = false;
  var paletteOpener = null;

  function paletteItems() { return paletteList ? Array.prototype.slice.call(paletteList.querySelectorAll('.palette-item')) : []; }
  function loadPalette() {
    if (paletteLoaded || !palette) return;
    paletteLoaded = true;
    getJSON(palette.getAttribute('data-source')).then(function (d) {
      (d.items || []).forEach(function (it) {
        var li = el('li', 'palette-item');
        li.setAttribute('role', 'option');
        li.setAttribute('data-path', it.path);
        li.setAttribute('data-label', (it.label + ' ' + it.section).toLowerCase());
        li.appendChild(icon(it.icon || 'dot'));
        li.appendChild(el('span', 'palette-label', it.label));
        li.appendChild(el('span', 'palette-section', it.section));
        paletteList.appendChild(li);
      });
      filterPalette(paletteInput.value);
    }).catch(function () { paletteLoaded = false; });
  }
  function filterPalette(q) {
    var words = q.trim().toLowerCase().split(/\s+/).filter(Boolean);
    var shown = 0;
    paletteItems().forEach(function (li) {
      var label = li.getAttribute('data-label') || '';
      var match = words.every(function (w) { return label.indexOf(w) !== -1; });
      // With no query, list the pages only; exams appear as soon as something is typed.
      if (words.length === 0) match = (li.querySelector('.palette-section') || {}).textContent === 'Page';
      li.hidden = !match;
      li.classList.remove('is-active');
      if (match) shown++;
    });
    if (paletteEmpty) paletteEmpty.hidden = shown > 0;
    var first = paletteItems().filter(function (li) { return !li.hidden; })[0];
    if (first) first.classList.add('is-active');
  }
  function openPalette() {
    if (!palette) return;
    paletteOpener = document.activeElement;
    palette.hidden = false;
    document.body.classList.add('dialog-open');
    filterPalette('');
    loadPalette();
    setTimeout(function () { paletteInput.focus(); }, 10);
  }
  function closePalette() {
    if (!palette || palette.hidden) return;
    palette.hidden = true;
    document.body.classList.remove('dialog-open');
    paletteInput.value = '';
    if (paletteOpener && paletteOpener.focus) paletteOpener.focus();
  }
  function movePalette(dir) {
    var visible = paletteItems().filter(function (li) { return !li.hidden; });
    if (!visible.length) return;
    var cur = visible.findIndex(function (li) { return li.classList.contains('is-active'); });
    var next = (cur + dir + visible.length) % visible.length;
    if (cur >= 0) visible[cur].classList.remove('is-active');
    visible[next].classList.add('is-active');
    visible[next].scrollIntoView({ block: 'nearest' });
  }
  if (palette) {
    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-action="palette-open"]')) { e.preventDefault(); openPalette(); return; }
      if (e.target.closest('[data-action="palette-close"]')) { closePalette(); return; }
      var item = e.target.closest('.palette-item');
      if (item && !palette.hidden) window.location.href = item.getAttribute('data-path');
    });
    paletteInput.addEventListener('input', function () { filterPalette(paletteInput.value); });
    palette.addEventListener('mousemove', function (e) {
      var item = e.target.closest('.palette-item');
      if (!item || item.classList.contains('is-active')) return;
      paletteItems().forEach(function (li) { li.classList.remove('is-active'); });
      item.classList.add('is-active');
    });
  }

  // --- Confirmation dialog (replaces window.confirm) ------------------------------------------
  // <form data-confirm="Message" data-confirm-title="Title" data-confirm-button="Delete" data-confirm-tone="danger">
  var openDialog = null;
  function confirmDialog(opts) {
    return new Promise(function (resolve) {
      var opener = document.activeElement;
      var wrap = el('div', 'dialog');
      wrap.setAttribute('data-tone', opts.tone === 'danger' ? 'danger' : 'primary');
      var backdrop = el('div', 'dialog-backdrop');
      var panel = el('div', 'dialog-panel');
      panel.setAttribute('role', 'alertdialog');
      panel.setAttribute('aria-modal', 'true');
      var title = el('h2', 'dialog-title', opts.title);
      title.id = 'dialog-title';
      panel.setAttribute('aria-labelledby', title.id);
      var iconWrap = el('div', 'dialog-icon');
      iconWrap.appendChild(icon(opts.tone === 'danger' ? 'exclamation-triangle' : 'question-circle'));
      var msg = el('p', 'dialog-message', opts.message);
      var actions = el('div', 'dialog-actions');
      var cancel = el('button', 'btn btn-outline-secondary', opts.cancelText || 'Cancel');
      cancel.type = 'button';
      var ok = el('button', 'btn ' + (opts.tone === 'danger' ? 'btn-danger' : 'btn-primary'), opts.confirmText || 'Continue');
      ok.type = 'button';
      actions.appendChild(cancel);
      actions.appendChild(ok);
      panel.appendChild(iconWrap);
      panel.appendChild(title);
      panel.appendChild(msg);
      panel.appendChild(actions);
      wrap.appendChild(backdrop);
      wrap.appendChild(panel);
      document.body.appendChild(wrap);
      document.body.classList.add('dialog-open');

      function done(result) {
        wrap.remove();
        document.body.classList.remove('dialog-open');
        openDialog = null;
        if (opener && opener.focus) opener.focus();
        resolve(result);
      }
      openDialog = { close: function () { done(false); }, panel: panel };
      cancel.addEventListener('click', function () { done(false); });
      backdrop.addEventListener('click', function () { done(false); });
      ok.addEventListener('click', function () { done(true); });
      (opts.tone === 'danger' ? cancel : ok).focus();
    });
  }
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (form.dataset.confirmed === '1') { form.dataset.confirmed = ''; return; }
      ev.preventDefault();
      var submitter = ev.submitter || null;
      confirmDialog({
        title: form.getAttribute('data-confirm-title') || 'Are you sure?',
        message: form.getAttribute('data-confirm'),
        confirmText: form.getAttribute('data-confirm-button') || 'Continue',
        tone: form.getAttribute('data-confirm-tone') || 'primary'
      }).then(function (yes) {
        if (!yes) return;
        form.dataset.confirmed = '1';
        if (!form.requestSubmit) form.submit();
        else if (submitter && submitter.form === form) form.requestSubmit(submitter);
        else form.requestSubmit();
      });
    });
  });

  // --- Keyboard: Escape closes, Tab stays inside a dialog, Ctrl K opens the palette ------------
  document.addEventListener('keydown', function (e) {
    var mod = e.ctrlKey || e.metaKey;
    if (mod && (e.key === 'k' || e.key === 'K') && palette) { e.preventDefault(); if (palette.hidden) openPalette(); else closePalette(); return; }
    if (openDialog) {
      if (e.key === 'Escape') { e.preventDefault(); openDialog.close(); return; }
      if (e.key === 'Tab') {
        var nodes = openDialog.panel.querySelectorAll('button');
        var first = nodes[0], last = nodes[nodes.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
      return;
    }
    if (palette && !palette.hidden) {
      if (e.key === 'Escape') { e.preventDefault(); closePalette(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); movePalette(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); movePalette(-1); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        var active = paletteItems().filter(function (li) { return !li.hidden && li.classList.contains('is-active'); })[0];
        if (active) window.location.href = active.getAttribute('data-path');
      }
      return;
    }
    if (e.key === 'Escape') {
      setMenu(false);
      if (app && app.getAttribute('data-drawer') === 'open') app.setAttribute('data-drawer', 'closed');
    }
  });

  // --- Status chips (mirror status_badge() in helpers.php) ----------------------------------------
  var STATUS = {
    completed: ['success', 'check2-circle'], complete: ['success', 'check2-circle'], ready: ['success', 'check2-circle'],
    running: ['primary', 'arrow-repeat'], queued: ['neutral', 'hourglass-split'], partial: ['warning', 'exclamation-triangle'],
    failed: ['danger', 'x-circle'], cancelled: ['neutral', 'slash-circle']
  };
  function statusChip(status) {
    var s = STATUS[status] || ['neutral', 'dot'];
    var span = el('span', 'chip chip--' + s[0]);
    span.appendChild(icon(s[1], status === 'running' ? 'spin' : ''));
    span.appendChild(document.createTextNode(status || 'unknown'));
    return span;
  }

  function fmtElapsed(s) {
    if (s === null || s === undefined || s === '' || isNaN(s)) return '';
    s = Math.max(0, Math.floor(s));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h ? h + 'h ' + String(m).padStart(2, '0') + 'm' : m + 'm ' + String(sec).padStart(2, '0') + 's') + ' elapsed';
  }

  // --- Job progress widgets -------------------------------------------------------------
  function setBar(bar, percent, active) {
    if (percent === null || percent === undefined || percent === '') {
      // Unknown total: indeterminate, never an invented percentage.
      bar.classList.add('progress-bar-striped', 'indeterminate');
      bar.classList.toggle('progress-bar-animated', !!active);
      bar.style.width = '100%';
      bar.parentElement.removeAttribute('aria-valuenow');
    } else {
      bar.classList.remove('progress-bar-striped', 'progress-bar-animated', 'indeterminate');
      bar.style.width = Math.max(0, Math.min(100, Number(percent))) + '%';
      bar.parentElement.setAttribute('aria-valuenow', String(percent));
    }
  }

  function initJob(node) {
    var url = node.getAttribute('data-status-url');
    var bar = node.querySelector('[data-field="bar"]');
    var elapsedEl = node.querySelector('[data-field="elapsed"]');
    var final = node.getAttribute('data-final') === '1';
    var elapsed = parseInt(elapsedEl.getAttribute('data-elapsed'), 10);
    var running = !final;
    setBar(bar, bar.getAttribute('data-percent'), running);
    elapsedEl.textContent = fmtElapsed(isNaN(elapsed) ? null : elapsed);
    if (final) return;

    var tick = setInterval(function () {
      if (!isNaN(elapsed) && running) { elapsed++; elapsedEl.textContent = fmtElapsed(elapsed); }
    }, 1000);

    function poll() {
      getJSON(url).then(function (j) {
        node.querySelector('[data-field="status"]').replaceChildren(statusChip(j.status));
        node.querySelector('[data-field="phase"]').textContent = j.phase ? j.phase.replace(/_/g, ' ') : '';
        node.querySelector('[data-field="counts"]').textContent =
          j.done === null ? '' : (j.total === null ? String(j.done) : j.done + ' / ' + j.total);
        node.querySelector('[data-field="message"]').textContent = j.message || '';
        if (j.elapsed_seconds !== null) elapsed = j.elapsed_seconds;
        running = j.status === 'running';
        setBar(bar, j.percent, running);
        if (j.final) {
          clearInterval(tick);
          if (node.getAttribute('data-reload-on-finish') === '1') setTimeout(function () { location.reload(); }, 1200);
          return;
        }
        setTimeout(poll, 2000);
      }).catch(function () { setTimeout(poll, 5000); });
    }
    setTimeout(poll, 1500);
  }
  document.querySelectorAll('[data-job-progress]').forEach(initJob);

  // --- Top bar "job running" chip and the sidebar worker line ---------------------------------
  var indicator = document.getElementById('active-indicator');
  var workerDot = document.querySelector('[data-worker-dot]');
  var workerText = document.querySelector('[data-worker-text]');
  if (indicator) {
    var pollActive = function () {
      getJSON(indicator.getAttribute('data-active-url')).then(function (d) {
        var jobs = d.jobs || [];
        var running = jobs.filter(function (j) { return j.status === 'running'; });
        var queued = jobs.length - running.length;
        indicator.hidden = jobs.length === 0;
        if (jobs.length) {
          var text = running.length ? running[0].label + (running[0].percent !== null ? ' · ' + running[0].percent + '%' : '') : 'Job queued';
          if (queued > 0 && running.length) text += ' (+' + queued + ' queued)';
          indicator.querySelector('.label').textContent = text;
          indicator.title = text;
        }
        if (workerDot) workerDot.classList.toggle('is-live', running.length > 0);
        if (workerText) {
          workerText.textContent = running.length ? 'Worker busy' + (queued ? ', ' + queued + ' queued' : '')
            : (queued ? queued + ' job' + (queued === 1 ? '' : 's') + ' queued' : 'No jobs running');
        }
        setTimeout(pollActive, jobs.length ? 4000 : 15000);
      }).catch(function () { setTimeout(pollActive, 15000); });
    };
    pollActive();
  }

  // --- Forms ------------------------------------------------------------------------------
  // Prevent duplicate submissions while a request is being initiated.
  document.querySelectorAll('form[data-once]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) return;
      if (form.dataset.submitted === '1') { ev.preventDefault(); return; }
      form.dataset.submitted = '1';
      form.querySelectorAll('button[type="submit"]').forEach(function (b) {
        b.disabled = true;
        var busy = form.getAttribute('data-busy');
        if (busy) b.textContent = busy;
        else b.insertAdjacentElement('afterbegin', el('span', 'spinner-border spinner-border-sm'));
      });
    });
  });
  // A page restored from the back/forward cache must not keep forms locked.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted && document.querySelector('form[data-once][data-submitted="1"]')) location.reload();
  });

  document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
    sel.addEventListener('change', function () { if (sel.form) sel.form.submit(); });
  });

  // --- Practice mode: reveal one question's answer on demand ---------------------------------
  function multiline(container, text) {
    String(text).split('\n').forEach(function (line, i) {
      if (i > 0) container.appendChild(document.createElement('br'));
      container.appendChild(document.createTextNode(line));
    });
  }

  document.querySelectorAll('[data-reveal-url]').forEach(function (btn) {
    var label = btn.querySelector('.label') || btn;
    btn.addEventListener('click', function () {
      var target = btn.closest('.question-card').querySelector('.reveal-target');
      if (!target.hidden && target.childElementCount) {
        target.hidden = true;
        label.textContent = 'Reveal answer';
        btn.setAttribute('aria-expanded', 'false');
        return;
      }
      btn.disabled = true;
      getJSON(btn.getAttribute('data-reveal-url')).then(function (d) {
        target.replaceChildren();
        var box = el('div', 'answer-box');
        box.appendChild(icon('check-circle-fill'));
        var body = el('div');
        var line = el('div', 'answer-text');
        line.appendChild(el('strong', null, 'Answer: '));
        line.appendChild(document.createTextNode(d.answer || 'not available'));
        body.appendChild(line);
        if (d.votes) body.appendChild(el('div', 'answer-votes', 'Community votes: ' + d.votes));
        box.appendChild(body);
        target.appendChild(box);
        (d.answer_images || []).forEach(function (u, i) {
          var img = el('img', 'q-image');
          img.src = u; img.alt = 'Answer image ' + (i + 1); img.referrerPolicy = 'no-referrer'; img.loading = 'lazy';
          target.appendChild(img);
        });
        if (d.explanation) {
          var ex = el('div', 'explanation');
          ex.appendChild(el('div', 'section-heading', 'Explanation'));
          multiline(ex, d.explanation);
          target.appendChild(ex);
        }
        if ((d.comments || []).length) {
          var det = el('details', 'discussion');
          var sum = el('summary');
          sum.appendChild(icon('chevron-right'));
          sum.appendChild(icon('chat-left-text'));
          sum.appendChild(document.createTextNode('Discussion (' + d.comments.length + ')'));
          det.appendChild(sum);
          var list = el('div', 'discussion-body');
          d.comments.forEach(function (c) {
            var cm = el('div', 'comment' + (c.reply ? ' is-reply' : ''));
            var parts = [c.timestamp, c.upvotes === null ? null : c.upvotes + (c.upvotes === 1 ? ' upvote' : ' upvotes')].filter(Boolean);
            if (c.author || parts.length || c.selected) {
              var head = el('div', 'comment-head');
              if (c.author) head.appendChild(el('strong', null, c.author));
              if (c.selected) head.appendChild(el('span', 'chip chip--primary', 'selected ' + c.selected));
              parts.forEach(function (p) { head.appendChild(el('span', null, p)); });
              cm.appendChild(head);
            }
            var text = el('div');
            multiline(text, c.content);
            cm.appendChild(text);
            list.appendChild(cm);
          });
          det.appendChild(list);
          target.appendChild(det);
        }
        target.hidden = false;
        label.textContent = 'Hide answer';
        btn.setAttribute('aria-expanded', 'true');
      }).catch(function () {
        target.replaceChildren(el('div', 'text-danger small', 'Could not load the answer. Reload the page and try again.'));
        target.hidden = false;
      }).finally(function () { btn.disabled = false; });
    });
  });
})();
