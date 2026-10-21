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

  // Two letters for an avatar, like the server's initials().
  function initials(name) {
    var parts = String(name).split(/[^\p{L}\p{N}]+/u).filter(Boolean);
    var s = parts.length > 1 ? parts[0].charAt(0) + parts[1].charAt(0) : (parts[0] || String(name)).slice(0, 2);
    return s.toUpperCase();
  }

  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (!r.ok) { var err = new Error('HTTP ' + r.status); err.status = r.status; throw err; }
        return r.json();
      });
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
    var signedOut = node.getAttribute('data-signed-out-url');
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
          // A restore signs everyone out: go to the sign-in form, unless this person has
          // already signed in again while its files were being written.
          if (signedOut && j.status === 'completed') {
            setTimeout(function () {
              getJSON(url).then(function () { location.reload(); }, function (err) {
                if (err && err.status === 401) location.assign(signedOut); else location.reload();
              });
            }, 1200);
          } else if (node.getAttribute('data-reload-on-finish') === '1') setTimeout(function () { location.reload(); }, 1200);
          return;
        }
        setTimeout(poll, 2000);
      }).catch(function (err) {
        if (signedOut && err && err.status === 401) { location.assign(signedOut); return; }
        setTimeout(poll, 5000);
      });
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

  // --- Live tool output on the job page ------------------------------------------------------
  document.querySelectorAll('[data-live-log]').forEach(function (box) {
    var dot = document.querySelector('[data-live-indicator]');
    box.scrollTop = box.scrollHeight;
    if (box.getAttribute('data-final') === '1') return;
    var poll = function () {
      getJSON(box.getAttribute('data-live-log')).then(function (d) {
        // Follow the end only when the reader is already there, so scrolling back stays put.
        var atEnd = box.scrollHeight - box.scrollTop - box.clientHeight < 24;
        if (d.log && d.log !== box.textContent) {
          box.textContent = d.log;
          if (atEnd) box.scrollTop = box.scrollHeight;
        }
        if (d.final) { if (dot) dot.classList.add('is-done'); return; }
        setTimeout(poll, 2000);
      }).catch(function () { setTimeout(poll, 5000); });
    };
    setTimeout(poll, 1000);
  });

  // --- Error markers: one open at a time, closed by a click elsewhere or Escape ----------------
  document.addEventListener('click', function (ev) {
    document.querySelectorAll('details.error-pop[open]').forEach(function (d) {
      if (!d.contains(ev.target)) d.open = false;
    });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') document.querySelectorAll('details.error-pop[open]').forEach(function (d) { d.open = false; });
  });

  // --- Documents being built in the background -----------------------------------------------
  document.querySelectorAll('[data-doc-status]').forEach(function (el) {
    var label = el.querySelector('[data-field="progress"]');
    var poll = function () {
      getJSON(el.getAttribute('data-doc-status')).then(function (d) {
        if (d.status !== 'building') { window.location.reload(); return; }
        if (label && d.total) label.textContent = d.percent + '% (' + d.done + ' of ' + d.total + ' questions)';
        setTimeout(poll, 2000);
      }).catch(function () { window.location.reload(); });
    };
    setTimeout(poll, 2000);
  });

  // --- Panels opened by a button (an answer form under a row, for example) -------------------
  document.querySelectorAll('[data-toggle-panel]').forEach(function (btn) {
    var panel = document.getElementById(btn.getAttribute('data-toggle-panel'));
    if (!panel) return;
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-controls', panel.id);
    btn.addEventListener('click', function () {
      var open = panel.hidden;
      panel.hidden = !open;
      btn.setAttribute('aria-expanded', String(open));
      btn.classList.toggle('active', open);
      if (open) { var f = panel.querySelector('textarea, input:not([type=hidden]), select'); if (f) f.focus(); }
    });
  });

  // --- Backup uploads, sent in pieces -------------------------------------------------------
  // One request carrying the whole file can outlast the web server's timeouts and size limits,
  // so the file goes in pieces (8 MB at most, halved whenever the server says a piece is too
  // large). The server keeps what has arrived, so choosing the same file again after a dropped
  // connection or a closed page carries on from there. Without JavaScript the form sends the
  // whole file in one request, as a plain form.
  function fmtBytes(n) {
    var units = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (i === 0 ? String(Math.round(n)) : n.toFixed(1)) + ' ' + units[i];
  }
  function fmtLeft(s) {
    s = Math.max(1, Math.round(s));
    if (s < 60) return s + ' s left';
    var m = Math.round(s / 60);
    return m < 60 ? m + ' min left' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min left';
  }

  function initChunkedUpload(form) {
    var input = form.querySelector('input[type="file"]');
    var box = form.querySelector('[data-upload-progress]');
    var submit = form.querySelector('[data-upload-submit]');
    var cancel = form.querySelector('[data-upload-cancel]');
    var startUrl = form.getAttribute('data-start-url');
    var bar = box.querySelector('[data-field="bar"]');
    var MIN_PIECE = 256 * 1024;
    var current = null;

    function field(name) { return box.querySelector('[data-field="' + name + '"]'); }
    function show(status, phase, message) {
      box.hidden = false;
      field('status').replaceChildren(statusChip(status));
      field('phase').textContent = phase;
      field('message').textContent = message;
    }
    function progress(u, sent) {
      setBar(bar, Math.floor(100 * sent / Math.max(1, u.size)), true);
      field('counts').textContent = fmtBytes(sent) + ' / ' + fmtBytes(u.size);
      var secs = (Date.now() - u.t0) / 1000;
      var rate = secs > 2 ? (sent - u.b0) / secs : 0;
      field('rate').textContent = rate > 0 ? fmtBytes(rate) + '/s, ' + fmtLeft((u.size - sent) / rate) : '';
    }
    function warnLeave(e) { e.preventDefault(); e.returnValue = ''; }
    function busy(on) {
      submit.disabled = on;
      input.disabled = on;
      cancel.hidden = !on;
      if (on) window.addEventListener('beforeunload', warnLeave);
      else window.removeEventListener('beforeunload', warnLeave);
    }
    function failure(status) {
      if (status === 0) return 'The server could not be reached.';
      if (status === 401) return 'You were signed out. Sign in again, then choose the same file to carry on.';
      if (status === 403) return 'The server refused the upload. Reload the page, then choose the same file to carry on.';
      if (status === 413) return 'The web server refuses even small pieces. Raise client_max_body_size in its configuration (see docs/INSTALL.md).';
      return 'The upload failed (status ' + status + ').';
    }
    function post(url) {
      var data = arguments.length > 1 ? arguments[1] : {};
      return fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf, Accept: 'application/json' },
        body: new URLSearchParams(data)
      }).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) { d.httpStatus = r.status; return d; });
      }, function () { return { httpStatus: 0 }; });
    }
    // One piece, by XMLHttpRequest because fetch() reports no upload progress. The offset is in
    // the address and the CSRF token in a header, so a piece too large for PHP still reads as
    // "too large" rather than as a form without its token.
    function sendPiece(u, offset, length) {
      return new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();
        u.xhr = xhr;
        xhr.open('POST', u.base + '?offset=' + offset);
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = function (e) { if (e.lengthComputable) progress(u, offset + Math.min(e.loaded, length)); };
        xhr.onload = function () {
          var body = {};
          try { body = JSON.parse(xhr.responseText); } catch (err) { /* an error page from the web server */ }
          resolve({ status: xhr.status, body: body });
        };
        xhr.onerror = function () { resolve({ status: 0, body: {} }); };
        xhr.onabort = function () { reject(new Error('cancelled')); };
        var fd = new FormData();
        fd.append('chunk', u.file.slice(offset, offset + length), 'piece');
        xhr.send(fd);
      });
    }
    function next(u) {
      if (u.cancelled) return Promise.reject(new Error('cancelled'));
      if (u.received >= u.size) return finish(u);
      var length = Math.min(u.piece, u.size - u.received);
      return sendPiece(u, u.received, length).then(function (r) {
        var received = r.body && typeof r.body.received === 'number' ? r.body.received : null;
        if ((r.status === 200 || r.status === 409) && received !== null) {
          // 409: the server has a different amount (another tab, a lost reply); go on from there.
          u.received = received;
          if (r.status === 200) { u.failures = 0; progress(u, received); }
          return next(u);
        }
        if (r.status === 413 && u.piece > MIN_PIECE) {
          u.piece = Math.max(MIN_PIECE, Math.floor(u.piece / 2));
          return next(u);
        }
        if (r.status === 0 || (r.status >= 500 && r.status !== 507)) {
          u.failures++;
          if (u.failures > 8) throw new Error(failure(r.status));
          // Some servers drop the connection rather than answer "too large": smaller pieces
          // help there, and on a poor connection too.
          if (r.status === 0 && u.failures >= 2) u.piece = Math.max(MIN_PIECE, Math.floor(u.piece / 2));
          var wait = Math.min(30, Math.pow(2, u.failures));
          show('running', 'waiting', 'The connection dropped. Trying again in ' + wait + ' s.');
          return new Promise(function (res) { setTimeout(res, wait * 1000); }).then(function () {
            if (!u.cancelled) show('running', 'uploading', 'Keep this page open until the upload finishes.');
            return next(u);
          });
        }
        throw new Error((r.body && r.body.error) || failure(r.status));
      });
    }
    function finish(u) {
      u.checking = true;
      show('running', 'checking', 'All of it arrived. Checking the file…');
      setBar(bar, null, true);
      return post(u.base + '/finish').then(function (d) {
        if (d.httpStatus === 200 && d.name) {
          busy(false);
          location.assign(form.getAttribute('data-done-url'));
          return;
        }
        if (d.httpStatus === 409 && typeof d.received === 'number') { u.checking = false; u.received = d.received; return next(u); }
        throw new Error(d.error || failure(d.httpStatus));
      });
    }

    form.addEventListener('submit', function (ev) {
      var file = input.files && input.files[0];
      if (!file || !window.fetch || !window.FormData || !window.Promise || !file.slice) return; // the plain form
      ev.preventDefault();
      if (current) return;
      var u = current = { file: file, size: file.size, received: 0, failures: 0, cancelled: false, checking: false };
      busy(true);
      show('running', 'starting', 'Starting…');
      setBar(bar, 0, true);
      field('counts').textContent = '';
      field('rate').textContent = '';
      post(startUrl, { name: file.name, size: String(file.size), modified: String(file.lastModified || 0) }).then(function (d) {
        if (d.httpStatus !== 200 || !d.id) throw new Error(d.error || failure(d.httpStatus));
        u.base = startUrl + '/' + d.id;
        if (u.cancelled) { post(u.base + '/discard'); throw new Error('cancelled'); }
        u.piece = d.chunk;
        u.received = d.received;
        u.t0 = Date.now();
        u.b0 = d.received;
        show('running', 'uploading', d.received > 0
          ? 'Carrying on from ' + fmtBytes(d.received) + ', where an earlier upload of this file stopped. Keep this page open until it finishes.'
          : 'Keep this page open until the upload finishes.');
        progress(u, d.received);
        return next(u);
      }).catch(function (err) {
        if (u.cancelled) return;
        current = null;
        busy(false);
        setBar(bar, Math.floor(100 * u.received / Math.max(1, u.size)), false);
        field('rate').textContent = '';
        show('failed', 'stopped', err.message + (u.base && !u.checking && u.received > 0
          ? ' What arrived is kept on the server: choose the same file again to carry on.' : ''));
      });
    });
    cancel.addEventListener('click', function () {
      var u = current;
      if (!u) return;
      u.cancelled = true;
      current = null;
      if (u.xhr) u.xhr.abort();
      busy(false);
      field('rate').textContent = '';
      show('cancelled', 'cancelled', 'Upload cancelled. What had arrived was deleted.');
      if (u.base) post(u.base + '/discard');
    });
    input.addEventListener('change', function () { if (!current) box.hidden = true; });
  }
  document.querySelectorAll('form[data-chunked-upload]').forEach(initChunkedUpload);

  // --- QR codes (setting up two-step sign-in) -----------------------------------------------
  // Drawn here from the vendored qrcode-generator, loaded only on the page that needs it.
  if (typeof window.qrcode === 'function') {
    document.querySelectorAll('img[data-qr]').forEach(function (img) {
      var qr = window.qrcode(0, 'M');
      qr.addData(img.getAttribute('data-qr'));
      qr.make();
      img.src = qr.createDataURL(5, 4);
    });
  }

  // --- Forms ------------------------------------------------------------------------------
  // Prevent duplicate submissions while a request is being initiated.
  document.querySelectorAll('form[data-once]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) return;
      if (form.dataset.submitted === '1') { ev.preventDefault(); return; }
      form.dataset.submitted = '1';
      // A disabled button is left out of what the form sends, so the clicked button's own
      // choice (such as "declined") is copied into a hidden field before buttons are disabled.
      var submitter = ev.submitter;
      if (submitter && submitter.name) {
        var keep = document.createElement('input');
        keep.type = 'hidden';
        keep.name = submitter.name;
        keep.value = submitter.value;
        form.appendChild(keep);
      }
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

  // --- Library shelves: the search box narrows the cards as you type -------------------------
  // Every word typed must appear in a card's names, codes or provider. A shelf with nothing
  // left is hidden; Enter still searches on the server.
  var shelfSearch = document.querySelector('[data-shelf-search]');
  if (shelfSearch) {
    var shelves = Array.prototype.slice.call(document.querySelectorAll('[data-shelf]'));
    var shelfEmpty = document.querySelector('[data-shelf-empty]');
    var filterShelves = function () {
      var words = shelfSearch.value.toLowerCase().split(/\s+/).filter(Boolean);
      var shown = 0;
      shelves.forEach(function (shelf) {
        var n = 0;
        shelf.querySelectorAll('[data-shelf-card]').forEach(function (card) {
          var hay = card.getAttribute('data-search') || '';
          var match = words.every(function (w) { return hay.indexOf(w) !== -1; });
          card.hidden = !match;
          if (match) n += Number(card.getAttribute('data-count')) || 1; // exams, editions included
        });
        shelf.hidden = n === 0;
        var count = shelf.querySelector('[data-shelf-count]');
        if (count) count.textContent = String(n);
        shown += n;
      });
      if (shelfEmpty) shelfEmpty.hidden = shown > 0;
    };
    shelfSearch.addEventListener('input', filterShelves);
    if (shelfSearch.value) filterShelves();
  }

  // --- Practice mode: reveal one question's answer on demand ---------------------------------
  // Text as paragraphs (blank lines separate them), keeping single line breaks, like the
  // server's QuestionView::paragraphs().
  function multiline(container, text) {
    String(text).trim().split(/\n\s*\n/).forEach(function (para) {
      if (!para.trim()) return;
      var p = document.createElement('p');
      para.trim().split('\n').forEach(function (line, i) {
        if (i > 0) p.appendChild(document.createElement('br'));
        p.appendChild(document.createTextNode(line));
      });
      container.appendChild(p);
    });
  }

  // The answer, explanation and discussion of one question, built from the reveal data.
  function renderAnswer(target, d) {
    target.replaceChildren();
    var box = el('div', 'answer-box');
    box.appendChild(icon('check-circle-fill'));
    var body = el('div');
    var line = el('div', 'answer-text');
    line.appendChild(el('strong', null, 'Answer: '));
    line.appendChild(document.createTextNode(d.answer || d.answer_note || 'not available'));
    body.appendChild(line);
    if (d.votes) body.appendChild(el('div', 'answer-votes', 'Community votes: ' + d.votes));
    if (d.note) {
      var note = el('div', 'answer-note');
      note.appendChild(el('span', 'answer-note-label', 'Note'));
      multiline(note, d.note);
      body.appendChild(note);
    }
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
      var top = Math.max.apply(null, d.comments.map(function (c) { return c.upvotes || 0; }));
      d.comments.forEach(function (c) {
        var cm = el('div', 'comment' + (c.reply ? ' is-reply' : '') + (top > 0 && c.upvotes === top ? ' is-top' : ''));
        if (c.author) {
          var av = el('span', 'comment-avatar', initials(c.author));
          av.setAttribute('aria-hidden', 'true');
          cm.appendChild(av);
        }
        var main = el('div', 'comment-main');
        if (c.author || c.timestamp) {
          var head = el('div', 'comment-head');
          if (c.author) head.appendChild(el('span', 'comment-author', c.author));
          if (c.badge === 'Highly Voted') {
            var hv = el('span', 'chip chip--warning');
            hv.appendChild(icon('star-fill'));
            hv.appendChild(document.createTextNode('highly voted'));
            head.appendChild(hv);
          }
          if (c.timestamp) head.appendChild(el('span', 'comment-time', c.timestamp));
          main.appendChild(head);
        }
        var text = el('div', 'comment-body');
        multiline(text, c.content);
        main.appendChild(text);
        if (c.selected || c.upvotes !== null) {
          var foot = el('div', 'comment-foot');
          if (c.selected) {
            var sel = el('span', 'chip chip--primary');
            sel.appendChild(icon('check2'));
            sel.appendChild(document.createTextNode('selected ' + c.selected));
            foot.appendChild(sel);
          }
          if (c.upvotes !== null) {
            var up = el('span', 'comment-votes');
            up.title = 'Upvotes';
            up.appendChild(icon('hand-thumbs-up'));
            up.appendChild(document.createTextNode(String(c.upvotes)));
            foot.appendChild(up);
          }
          main.appendChild(foot);
        }
        cm.appendChild(main);
        list.appendChild(cm);
      });
      det.appendChild(list);
      target.appendChild(det);
    }
  }

  // --- Practice mode: pick choices, reveal and check, clear ----------------------------------
  // The page holds no answers. They are fetched for the whole page in one request as soon as
  // it opens, so a reveal is instant. Picks and checked results are remembered in this browser
  // (per exam version), so they survive moving between pages.
  var practice = document.querySelector('[data-practice]');
  if (practice) {
    var storeKey = 'examtopics.practice.' + practice.getAttribute('data-practice');
    var store = {};
    try { store = JSON.parse(localStorage.getItem(storeKey) || '{}') || {}; } catch (e) { store = {}; }
    var save = function () {
      try {
        if (Object.keys(store).length) localStorage.setItem(storeKey, JSON.stringify(store));
        else localStorage.removeItem(storeKey);
      } catch (e) { /* storage unavailable: picks last for this page only */ }
      summary();
    };
    var cards = Array.prototype.slice.call(document.querySelectorAll('.question-card[data-q]'));
    var answers = {};
    var batchUrl = practice.getAttribute('data-answers-url');
    var batch = getJSON(batchUrl + (batchUrl.indexOf('?') === -1 ? '?' : '&') + 'numbers=' + cards.map(function (c) { return c.getAttribute('data-q'); }).join(','))
      .then(function (d) { answers = (d && d.answers) || {}; return answers; })
      .catch(function () { return null; });
    var answerFor = function (card) {
      var n = card.getAttribute('data-q');
      if (answers[n]) return Promise.resolve(answers[n]);
      return batch.then(function (a) {
        return a && a[n] ? a[n] : getJSON(card.querySelector('[data-reveal-url]').getAttribute('data-reveal-url'));
      });
    };

    var summary = function () {
      var bar = practice.querySelector('[data-field="score"]');
      var clearAll = practice.querySelector('[data-action="practice-clear-all"]');
      var keys = Object.keys(store);
      var checked = keys.filter(function (k) { return store[k].r && store[k].ok !== null && store[k].ok !== undefined; });
      var right = checked.filter(function (k) { return store[k].ok; }).length;
      if (bar) {
        bar.textContent = checked.length
          ? right + ' of ' + checked.length + ' checked answer' + (checked.length === 1 ? '' : 's') + ' correct'
          : 'Pick an answer, then reveal it to check yourself.';
      }
      if (clearAll) clearAll.hidden = keys.length === 0;
    };

    var choicesOf = function (card) { return Array.prototype.slice.call(card.querySelectorAll('.choices[data-pickable] .choice[data-letter]')); };
    var picksOf = function (card) {
      return choicesOf(card).filter(function (li) { return li.classList.contains('is-picked'); }).map(function (li) { return li.getAttribute('data-letter'); });
    };
    var setPicks = function (card, letters) {
      choicesOf(card).forEach(function (li) {
        var on = letters.indexOf(li.getAttribute('data-letter')) !== -1;
        li.classList.toggle('is-picked', on);
        li.setAttribute('aria-checked', String(on));
      });
    };
    var ungrade = function (card) {
      choicesOf(card).forEach(function (li) {
        li.classList.remove('is-answer', 'is-wrong');
        var tag = li.querySelector('.choice-tag');
        if (tag) tag.remove();
      });
      var verdict = card.querySelector('.practice-verdict');
      if (verdict) verdict.remove();
    };
    // Mark the right choices green and wrong picks red, and say how it went.
    var grade = function (card, d) {
      ungrade(card);
      var right = d.letters || [];
      var picked = picksOf(card);
      choicesOf(card).forEach(function (li) {
        var l = li.getAttribute('data-letter');
        var isRight = right.indexOf(l) !== -1;
        li.classList.toggle('is-answer', isRight);
        li.classList.toggle('is-wrong', picked.indexOf(l) !== -1 && right.length > 0 && !isRight);
        if (picked.indexOf(l) !== -1) li.appendChild(el('span', 'choice-tag', 'your choice'));
      });
      var ok = null;
      if (picked.length && right.length) {
        ok = picked.length === right.length && picked.every(function (l) { return right.indexOf(l) !== -1; });
        var v = el('div', 'practice-verdict ' + (ok ? 'is-right' : 'is-wrong'));
        v.appendChild(icon(ok ? 'check-circle-fill' : 'x-circle-fill'));
        v.appendChild(document.createTextNode(ok
          ? 'Correct.'
          : 'Not quite: you chose ' + picked.join(', ') + '; the answer is ' + right.join(', ') + '.'));
        var target = card.querySelector('.reveal-target');
        target.parentNode.insertBefore(v, target);
      }
      return ok;
    };

    var open = function (card, d, remember) {
      var btn = card.querySelector('[data-reveal-url]');
      var target = card.querySelector('.reveal-target');
      renderAnswer(target.querySelector('.reveal-inner'), d);
      var ok = grade(card, d);
      // On the next frame, so the height change animates from closed.
      requestAnimationFrame(function () { target.classList.add('is-open'); });
      target.setAttribute('aria-hidden', 'false');
      btn.querySelector('.label').textContent = 'Hide answer';
      btn.setAttribute('aria-expanded', 'true');
      card.classList.add('is-revealed');
      if (remember) {
        var n = card.getAttribute('data-q');
        store[n] = { p: picksOf(card), r: 1, ok: ok };
        save();
      }
      updateClear(card);
    };
    var close = function (card) {
      var btn = card.querySelector('[data-reveal-url]');
      var target = card.querySelector('.reveal-target');
      target.classList.remove('is-open');
      target.setAttribute('aria-hidden', 'true');
      ungrade(card);
      btn.querySelector('.label').textContent = 'Reveal answer';
      btn.setAttribute('aria-expanded', 'false');
      card.classList.remove('is-revealed');
      var n = card.getAttribute('data-q');
      if (store[n]) {
        store[n].r = 0;
        store[n].ok = null;
        if (!store[n].p.length) delete store[n];
        save();
      }
      updateClear(card);
    };
    var updateClear = function (card) {
      var b = card.querySelector('[data-action="practice-clear"]');
      if (b) b.hidden = !(picksOf(card).length || card.classList.contains('is-revealed'));
    };
    var reset = function (card) {
      if (card.classList.contains('is-revealed')) close(card);
      setPicks(card, []);
      delete store[card.getAttribute('data-q')];
      updateClear(card);
    };

    cards.forEach(function (card) {
      var n = card.getAttribute('data-q');
      var max = parseInt(card.getAttribute('data-picks'), 10);
      var btn = card.querySelector('[data-reveal-url]');
      choicesOf(card).forEach(function (li) {
        li.setAttribute('tabindex', '0');
        li.setAttribute('role', max === 1 ? 'radio' : 'checkbox');
        li.setAttribute('aria-checked', 'false');
        var toggle = function () {
          if (card.classList.contains('is-revealed')) return; // clear the choice to try again
          var l = li.getAttribute('data-letter');
          var picked = picksOf(card);
          var on = picked.indexOf(l) !== -1;
          if (on) picked = picked.filter(function (x) { return x !== l; });
          else if (max === 1) picked = [l];
          else picked.push(l);
          setPicks(card, picked);
          if (picked.length) store[n] = { p: picked, r: 0, ok: null };
          else delete store[n];
          save();
          updateClear(card);
        };
        li.addEventListener('click', function (e) { if (!e.target.closest('a')) toggle(); });
        li.addEventListener('keydown', function (e) {
          if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(); }
        });
      });
      btn.addEventListener('click', function () {
        if (card.classList.contains('is-revealed')) { close(card); return; }
        btn.disabled = true;
        answerFor(card).then(function (d) { open(card, d, true); })
          .catch(function () {
            var inner = card.querySelector('.reveal-inner');
            inner.replaceChildren(el('div', 'text-danger small', 'Could not load the answer. Reload the page and try again.'));
            card.querySelector('.reveal-target').classList.add('is-open');
          })
          .finally(function () { btn.disabled = false; });
      });
      var clear = card.querySelector('[data-action="practice-clear"]');
      if (clear) clear.addEventListener('click', function () { reset(card); save(); });

      // Restore what was picked (and revealed) here before.
      var saved = store[n];
      if (saved) {
        setPicks(card, saved.p || []);
        if (saved.r) answerFor(card).then(function (d) { open(card, d, false); }).catch(function () {});
        updateClear(card);
      }
    });

    var clearAll = practice.querySelector('[data-action="practice-clear-all"]');
    if (clearAll) {
      clearAll.addEventListener('click', function () {
        confirmDialog({
          title: 'Clear all your choices?',
          message: 'Your picks and checked answers for every question in this exam are cleared, on every page.',
          confirmText: 'Clear all', tone: 'danger'
        }).then(function (yes) {
          if (!yes) return;
          cards.forEach(reset);
          store = {};
          save();
        });
      });
    }
    summary();
  }
})();
