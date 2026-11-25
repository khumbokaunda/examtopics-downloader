// Pages and saves in place, inside the signed-in app.
//
// Moving between pages and saving a form used to reload the whole window: a white flash, the
// sidebar drawn again, the scroll position lost. This keeps the shell (sidebar, top bar) and
// replaces only the content:
//
//   - A link to another page fetches it and puts its content in, with a bar along the top at
//     once and, if it takes a moment, an outline of the page that is coming (skeleton.js).
//   - A form that saves something is sent in the background. Its button spins and says what it
//     is doing. When the server answers, the page is refreshed where the reader was and the
//     server's message slides in at the top right. A refusal keeps everything typed and says
//     why beside the form. A row being deleted fades at once and comes back if refused.
//
// Nothing here changes what the server does or checks: it reads the same HTML the browser
// would, after the same redirects. Anything out of the ordinary (a file, a print, the sign-in
// page, a page with its own script or a widget that runs for the whole visit) is an ordinary
// page load, as before. data-no-live on a link, a form or any ancestor opts it out.
//
// Scripts that set things up on the page listen for "examlib:load" (detail.root is the new
// content) as well as running once at start.
(function () {
  'use strict';

  var main = document.querySelector('main.app-content');
  if (!main || !window.fetch || !window.DOMParser || !window.history.pushState || !window.AbortController) return;

  var ExamLib = (window.ExamLib = window.ExamLib || {});

  // Addresses that are files, prints or another layout: fetching them first would only
  // download them twice.
  var SKIP = /^\/(logout|login|join|setup|assets|branding|api)(\/|$)|\/(sitting|sittings|pdf|print|download|export|csv|json|file|files|media|documents)(\/|$|\?)|[?&]format=(pdf|csv|json)|\.(pdf|csv|zip|json|txt|png|jpe?g|gif|webp|svg|docx?|xlsx?)(\?|$)/i;
  // The app can live under a folder (meta app-base); SKIP reads the address inside it.
  var base = (document.querySelector('meta[name="app-base"]') || {}).content || '';
  try { base = base ? new URL(base + '/', location.href).pathname.replace(/\/+$/, '') : ''; } catch (e) { base = ''; }
  var skipped = function (url) {
    var path = url.pathname;
    if (base && path.indexOf(base) === 0) path = path.slice(base.length) || '/';
    return SKIP.test(path + url.search);
  };

  // Widgets that run for the whole visit (a timed exam, practice, an upload sent in pieces, the
  // registration steps, the live invigilation page). A page holding one loads in full, and
  // leaving it does too, so nothing of it keeps running behind the next page.
  var WHOLE = 'form[data-mock], [data-practice], form[data-chunked-upload], [data-wizard], [data-live], [data-no-swap]';

  var optedOut = function (node) { return !!(node && node.closest && node.closest('[data-no-live]')); };
  var holdsWhole = function () { return !!main.querySelector(WHOLE); };

  // Pages found to need a full load are loaded in full from then on, in this tab.
  var FULL = 'examlib.swap.full';
  var fullPaths = (function () { try { return JSON.parse(sessionStorage.getItem(FULL) || '[]'); } catch (e) { return []; } })();
  var rememberFull = function (url) {
    try {
      var p = new URL(url, location.href).pathname;
      if (fullPaths.indexOf(p) === -1) fullPaths.push(p);
      fullPaths = fullPaths.slice(-200);
      sessionStorage.setItem(FULL, JSON.stringify(fullPaths));
    } catch (e) { /* storage unavailable */ }
  };
  var scrollTop = function () { return window.scrollY || document.documentElement.scrollTop || 0; };
  var escapeHtml = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };

  // ---- The bar along the top, and the outline of the coming page --------------------------
  var bar = document.createElement('div');
  bar.className = 'nav-progress';
  bar.setAttribute('aria-hidden', 'true');
  document.body.appendChild(bar);
  var barTimer = 0;
  var progressStart = function () {
    clearTimeout(barTimer);
    bar.classList.remove('is-done');
    void bar.offsetWidth;
    bar.classList.add('is-loading');
  };
  var progressDone = function () {
    bar.classList.remove('is-loading');
    bar.classList.add('is-done');
    barTimer = setTimeout(function () { bar.classList.remove('is-done'); }, 600);
  };

  var skTimer = 0;
  var skeletonLater = function (url) {
    clearTimeout(skTimer);
    skTimer = setTimeout(function () {
      if (main.querySelector('.live-skeleton')) return;
      var html = '';
      try { html = ExamLib.skeleton ? ExamLib.skeleton.forUrl(url) : ''; } catch (e) { html = ''; }
      if (!html) return;
      main.classList.add('is-loading');
      main.setAttribute('aria-busy', 'true');
      main.insertAdjacentHTML('afterbegin', html);
    }, 220);
  };
  var skeletonOff = function () {
    clearTimeout(skTimer);
    main.classList.remove('is-loading');
    main.removeAttribute('aria-busy');
    main.querySelectorAll('.live-skeleton').forEach(function (n) { n.remove(); });
  };

  // ---- Messages: the server's own, docked at the top right --------------------------------
  var dock = document.createElement('div');
  dock.className = 'live-dock';
  dock.setAttribute('aria-live', 'polite');
  document.body.appendChild(dock);
  var ICONS = { success: 'check-circle', warning: 'exclamation-triangle', danger: 'exclamation-octagon', info: 'info-circle' };
  var typeOf = function (a) { return (a.className.match(/alert-(success|warning|danger|info)/) || [])[1] || 'info'; };

  // html is already escaped (the server's own message); text is not.
  var announce = function (type, html, isHtml) {
    type = ICONS[type] ? type : 'info';
    var box = document.createElement('div');
    box.className = 'alert alert-' + type + ' live-dock-item';
    box.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    box.innerHTML = '<i class="bi bi-' + ICONS[type] + '" aria-hidden="true"></i><div class="alert-body"></div>'
      + '<button type="button" class="alert-close" data-dismiss="alert" aria-label="Dismiss"><i class="bi bi-x-lg" aria-hidden="true"></i></button>';
    var body = box.querySelector('.alert-body');
    if (isHtml) body.innerHTML = html; else body.textContent = html;
    dock.appendChild(box);
    // Good news leaves by itself; a problem stays until it is read.
    if (type === 'success' || type === 'info') {
      setTimeout(function () {
        if (!box.isConnected) return;
        box.classList.add('is-leaving');
        setTimeout(function () { box.remove(); }, 260);
      }, 6000);
    }
    return box;
  };
  ExamLib.announce = function (type, text) { return announce(type, text, false); };

  // The flashes of a fetched page are lifted out of it and shown in the dock.
  var takeFlashes = function (rootNode) {
    var out = [];
    rootNode.querySelectorAll('.flash-stack .alert').forEach(function (a) {
      var body = a.querySelector('.alert-body');
      out.push({ type: typeOf(a), html: (body ? body.innerHTML : a.textContent).trim() });
    });
    rootNode.querySelectorAll('.flash-stack .alert').forEach(function (n) { n.remove(); });
    rootNode.querySelectorAll('.flash-stack').forEach(function (n) { n.hidden = true; });
    return out;
  };
  var showFlashes = function (list) { list.forEach(function (f) { announce(f.type, f.html, true); }); };

  // A page handed over to a full load carries its messages across.
  var STASH = 'examlib.swap.flashes';
  var stash = function (list) { try { if (list && list.length) sessionStorage.setItem(STASH, JSON.stringify(list)); } catch (e) { /* ignore */ } };
  try {
    var kept = JSON.parse(sessionStorage.getItem(STASH) || '[]');
    sessionStorage.removeItem(STASH);
    var shownHere = main.querySelector('.flash-stack .alert');
    if (Array.isArray(kept) && kept.length && !shownHere) showFlashes(kept);
  } catch (e) { /* ignore */ }

  // ---- Putting a page in ----------------------------------------------------------------
  var sameDocUrl = function (a, b) {
    var x = new URL(a, location.href), y = new URL(b, location.href);
    return x.pathname === y.pathname && x.search === y.search;
  };

  // The content of a page that can be put in place, or null when it needs a full load.
  var canSwap = function (doc) {
    var next = doc.querySelector('main.app-content');
    if (!next) return null;
    if (next.querySelector('script') || next.querySelector(WHOLE)) return null;
    return next;
  };

  var cleanUp = function () {
    document.querySelectorAll('.dialog').forEach(function (d) { d.remove(); });
    document.body.classList.remove('dialog-open');
    var palette = document.getElementById('palette');
    if (palette) palette.hidden = true;
    var menu = document.querySelector('.user-menu[data-open="true"]');
    if (menu) {
      menu.setAttribute('data-open', 'false');
      var panel = menu.querySelector('.user-menu-panel');
      if (panel) panel.hidden = true;
    }
    var app = document.querySelector('.app');
    if (app) app.setAttribute('data-drawer', 'closed');
  };

  // How the person set the system to look lives on <html>, which a swap does not replace (the
  // Appearance form changes it). The theme itself is left to app.js.
  var LOOK = ['data-accent', 'data-density', 'data-surface', 'data-motion'];
  var syncLook = function (doc) {
    var from = doc.documentElement;
    if (!from || from.getAttribute('data-prefs') !== 'server') return;
    LOOK.forEach(function (a) {
      var v = from.getAttribute(a);
      if (v === null) document.documentElement.removeAttribute(a);
      else if (document.documentElement.getAttribute(a) !== v) document.documentElement.setAttribute(a, v);
    });
  };

  var syncShell = function (doc) {
    document.title = doc.title || document.title;
    syncLook(doc);
    var t = doc.querySelector('.topbar-title');
    var mine = document.querySelector('.topbar-title');
    if (t && mine) mine.textContent = t.textContent;
    var bell = doc.querySelector('.app-topbar .bell');
    var myBell = document.querySelector('.app-topbar .bell');
    if (bell && myBell) {
      myBell.innerHTML = bell.innerHTML;
      myBell.className = bell.className;
      myBell.setAttribute('aria-label', bell.getAttribute('aria-label') || 'Inbox');
    }
    var token = doc.querySelector('meta[name="csrf-token"]');
    var myToken = document.querySelector('meta[name="csrf-token"]');
    if (token && myToken) myToken.setAttribute('content', token.getAttribute('content') || '');
    // The sidebar keeps its scroll; only the current page moves.
    var act = doc.querySelector('.sidebar-nav .nav-link.is-active');
    var href = act ? act.getAttribute('href') : null;
    document.querySelectorAll('.sidebar-nav .nav-link.is-active').forEach(function (a) {
      a.classList.remove('is-active');
      a.removeAttribute('aria-current');
    });
    if (href) {
      var link = document.querySelector('.sidebar-nav .nav-link[href="' + CSS.escape(href) + '"]');
      if (link) { link.classList.add('is-active'); link.setAttribute('aria-current', 'page'); }
    }
  };

  // The page on screen. Back changes the address before the page it names is on screen, so
  // skeleton.js reads this to file an outline under the right page.
  var shown = new URL(location.href);
  main.dataset.page = shown.href;

  var swapIn = function (next, doc, url, opts) {
    var y = scrollTop();
    cleanUp();
    skeletonOff();
    var flashes = takeFlashes(next);
    main.innerHTML = next.innerHTML;
    syncShell(doc);
    if (opts.push === 'push') history.pushState({ live: true, y: 0 }, '', url);
    else if (opts.push === 'replace') history.replaceState({ live: true, y: y }, '', url);
    var hash = new URL(url, location.href).hash;
    var target = hash ? document.getElementById(decodeURIComponent(hash.slice(1))) : null;
    if (target) target.scrollIntoView();
    else window.scrollTo(0, opts.keepScroll ? y : (typeof opts.y === 'number' ? opts.y : 0));
    shown = new URL(url, location.href);
    main.dataset.page = shown.href;
    if (!opts.keepScroll && !target) {
      var body = document.getElementById('page-body');
      if (body) body.focus({ preventScroll: true });
    }
    document.dispatchEvent(new CustomEvent('examlib:load', { detail: { root: main } }));
    showFlashes(flashes);
  };

  // ---- Fetching -------------------------------------------------------------------------
  var inflight = null;
  var request = function (url, opts) {
    if (inflight) inflight.abort();
    var ctrl = new AbortController();
    inflight = ctrl;
    var init = { credentials: 'same-origin', redirect: 'follow', signal: ctrl.signal, headers: { Accept: 'text/html', 'X-ExamLib-Live': '1' } };
    Object.keys(opts || {}).forEach(function (k) { init[k] = opts[k]; });
    return fetch(url, init).finally(function () { if (inflight === ctrl) inflight = null; });
  };
  var isHtml = function (res) { return (res.headers.get('content-type') || '').toLowerCase().indexOf('text/html') !== -1; };

  // Handing over to an ordinary load: the bar and the outline stay up until the browser has
  // left, so the reader never sees the page being left again before the next one.
  var leaving = false;
  var leaveTimer = 0;
  var holdUntilGone = function () {
    leaving = true;
    clearTimeout(leaveTimer);
    // Should the browser not leave after all, do not keep the reader behind an outline.
    leaveTimer = setTimeout(function () { leaving = false; progressDone(); skeletonOff(); }, 12000);
  };
  var handOver = function (url, flashes) { holdUntilGone(); stash(flashes || []); location.assign(url); };

  var navigate = function (url, opts) {
    opts = opts || {};
    var push = opts.push || 'push';
    if (holdsWhole()) { holdUntilGone(); progressStart(); skeletonLater(url); location.assign(url); return; }
    progressStart();
    skeletonLater(url);
    request(url, { method: 'GET' }).then(function (res) {
      // A file: the browser downloads it and stays on this page.
      if (!isHtml(res)) { location.assign(url); return; }
      return res.text().then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var next = canSwap(doc);
        if (res.ok && !next && doc.querySelector('main.app-content')) rememberFull(res.url || url);
        if (!next) { handOver(res.url || url); return; }
        // A redirect (to the page that replaced this one) moves the address too.
        var final = res.url || url;
        var hash = new URL(url, location.href).hash;
        if (hash && final.indexOf('#') === -1) final += hash;
        swapIn(next, doc, final, { push: push, keepScroll: false, y: opts.y });
      });
    }).catch(function (err) {
      if (err && err.name === 'AbortError') return;
      handOver(url);
    }).finally(function () {
      if (!leaving) { progressDone(); skeletonOff(); }
    });
  };
  ExamLib.navigate = function (url) { navigate(new URL(url, location.href).href); };

  // The page again, in place: after something finished in the background.
  ExamLib.refresh = function () {
    if (holdsWhole()) { location.reload(); return; }
    progressStart();
    request(location.href, { method: 'GET' }).then(function (res) {
      if (!isHtml(res)) { location.reload(); return; }
      return res.text().then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var next = canSwap(doc);
        if (!res.ok || !next) { handOver(res.url || location.href); return; }
        swapIn(next, doc, res.url || location.href, { push: sameDocUrl(res.url || location.href, location.href) ? 'none' : 'replace', keepScroll: true });
      });
    }).catch(function (err) {
      if (!err || err.name !== 'AbortError') location.reload();
    }).finally(progressDone);
  };

  // ---- Links ------------------------------------------------------------------------------
  var linkTarget = function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return null;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || optedOut(a)) return null;
    if (a.target && a.target !== '_self') return null;
    if (a.hasAttribute('download')) return null;
    var href = a.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return null;
    var url;
    try { url = new URL(a.href, location.href); } catch (err) { return null; }
    if (url.origin !== location.origin) return null;
    if (skipped(url)) return null;
    // A link to the same place with only a fragment scrolls, as usual.
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return null;
    // A full load, with the bar and the outline while it comes.
    if (fullPaths.indexOf(url.pathname) !== -1 || holdsWhole()) { holdUntilGone(); progressStart(); skeletonLater(url.href); return null; }
    return url.href;
  };

  document.addEventListener('click', function (e) {
    var url = linkTarget(e);
    if (!url) return;
    e.preventDefault();
    navigate(url);
  });

  // Back to a page kept by the browser: nothing is loading any more.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) { leaving = false; clearTimeout(leaveTimer); progressDone(); skeletonOff(); }
  });
  window.addEventListener('popstate', function (e) {
    if (location.pathname === shown.pathname && location.search === shown.search) return;
    navigate(location.href, { push: 'none', y: e.state && typeof e.state.y === 'number' ? e.state.y : 0 });
  });
  // Remember where the reader was, so Back returns there.
  var scrollT = 0;
  window.addEventListener('scroll', function () {
    clearTimeout(scrollT);
    scrollT = setTimeout(function () {
      var state = {};
      var old = history.state || {};
      Object.keys(old).forEach(function (k) { state[k] = old[k]; });
      state.live = true;
      state.y = scrollTop();
      try { history.replaceState(state, ''); } catch (err) { /* ignore */ }
    }, 150);
  }, { passive: true });

  // ---- Forms ------------------------------------------------------------------------------
  var STATUS_COPY = {
    400: 'The server could not read that request. Nothing was saved.',
    403: 'You do not have permission to do that. Nothing was saved.',
    404: 'That record no longer exists. Someone may have removed it.',
    405: 'That action is not available here.',
    409: 'Someone else changed this at the same time. Reload the page and try again.',
    413: 'That file is too large to upload.',
    419: 'This page was open too long and its form expired. Reload the page and try again.',
    422: 'Some of what was entered was not accepted. Check the form and try again.',
    429: 'Too many attempts. Wait a minute and try again.'
  };
  var statusMessage = function (status) {
    if (STATUS_COPY[status]) return STATUS_COPY[status];
    if (status >= 500) return 'The server ran into a problem, so the change may not have been saved. Reload the page to check before trying again.';
    return 'The server refused that (error ' + status + '). Nothing was saved.';
  };

  // The button says what it is doing, with the spinner every save shows (app.js).
  var busy = function (form, btn, on) {
    form.toggleAttribute('aria-busy', on);
    form.classList.toggle('is-live-busy', on);
    if (!on && ExamLib.release) { ExamLib.release(form); }
    if (btn && ExamLib.buttonBusy) ExamLib.buttonBusy(btn, on);
    else if (btn) btn.disabled = on;
  };

  // A message beside the form, where the person is looking. A form that is a lone button
  // (Remove, Approve) often sits in a table row, where a sentence would push the row wide; its
  // message goes to the dock instead (returns false).
  var hasFields = function (form) {
    return !!form.querySelector('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea');
  };
  var inlineError = function (form, list) {
    form.querySelectorAll('.live-form-error').forEach(function (n) { n.remove(); });
    if (!list.length) return true;
    if (!hasFields(form)) return false;
    var box = document.createElement('div');
    box.className = 'alert alert-danger live-form-error';
    box.setAttribute('role', 'alert');
    box.innerHTML = '<i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div class="alert-body"></div>';
    box.querySelector('.alert-body').innerHTML = list.map(function (f) { return f.html; }).join(' ');
    // Above the buttons, on a line of its own: a button sitting in a row with other things (a
    // flex or a grid) has the row's start as its place, not the button itself.
    var anchor = form.querySelector('.form-actions') || form.querySelector('button[type="submit"], input[type="submit"]');
    while (anchor && anchor.parentElement && anchor.parentElement !== form && /flex|grid/.test(getComputedStyle(anchor.parentElement).display)) anchor = anchor.parentElement;
    if (anchor && anchor.parentNode && form.contains(anchor)) anchor.parentNode.insertBefore(box, anchor);
    else form.prepend(box);
    var r = box.getBoundingClientRect();
    if (r.top < 70 || r.bottom > window.innerHeight) box.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return true;
  };

  // What disappears at once when it is deleted: the row of a delete or remove that asked first.
  var optimisticTarget = function (form, btn) {
    var sel = (btn && btn.getAttribute('data-live-remove')) || form.getAttribute('data-live-remove');
    if (sel) return form.closest(sel);
    if (form.getAttribute('data-confirm-tone') === 'danger' && /\/(delete|remove)$/.test(new URL(form.action, location.href).pathname)) {
      return form.closest('tr, li');
    }
    return null;
  };

  var download = function (res) {
    return res.blob().then(function (blob) {
      var cd = res.headers.get('content-disposition') || '';
      var name = (cd.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i) || [])[1] || 'download';
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = decodeURIComponent(name);
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
    });
  };

  var formEligible = function (form) {
    if (optedOut(form) || holdsWhole()) return false;
    if (form.target && form.target !== '_self') return false;
    // Forms that answer in JSON have their own handlers in app.js.
    if (form.hasAttribute('data-job-form') || form.hasAttribute('data-save-toggle') || form.hasAttribute('data-chunked-upload')) return false;
    var url = new URL(form.getAttribute('action') || location.href, location.href);
    if (url.origin !== location.origin) return false;
    if (skipped(url)) return false;
    return true;
  };

  var submitLive = function (form, btn) {
    var action = new URL(form.getAttribute('action') || location.href, location.href).href;
    var data;
    try { data = btn ? new FormData(form, btn) : new FormData(form); } catch (err) {
      data = new FormData(form);
      if (btn && btn.name) data.append(btn.name, btn.value);
    }
    var row = optimisticTarget(form, btn);
    if (row) row.classList.add('live-removing');
    var undo = function () { if (row) row.classList.remove('live-removing'); };
    var stop = function () { undo(); busy(form, btn, false); delete form.dataset.confirmed; };
    busy(form, btn, true);
    progressStart();
    fetch(action, { method: 'POST', body: data, credentials: 'same-origin', redirect: 'follow', headers: { Accept: 'text/html', 'X-ExamLib-Live': '1' } })
      .then(function (res) {
        if (!isHtml(res)) {
          if (res.ok) return download(res).then(stop);
          stop();
          announce('danger', statusMessage(res.status));
          return;
        }
        return res.text().then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var finalUrl = res.url || action;
          var next = doc.querySelector('main.app-content');
          if (!next) {
            // The sign-in page, a print layout, an error page of its own.
            if (res.ok || res.status === 401) { handOver(finalUrl); return; }
            stop();
            var msg = statusMessage(res.status);
            if (!inlineError(form, [{ html: escapeHtml(msg) }])) announce('danger', msg);
            return;
          }
          // A form sent back with what was typed and what was wrong (422): put it in place.
          if (!res.ok && res.status !== 422) {
            stop();
            var list = takeFlashes(next);
            var said = list.filter(function (f) { return f.type === 'danger' || f.type === 'warning'; });
            if (!said.length) said = [{ type: 'danger', html: escapeHtml(statusMessage(res.status)) }];
            if (!inlineError(form, said)) showFlashes(said);
            return;
          }
          var types = Array.prototype.map.call(next.querySelectorAll('.flash-stack .alert'), typeOf);
          var refused = types.indexOf('danger') !== -1 && types.indexOf('success') === -1;
          if (refused && sameDocUrl(finalUrl, location.href) && res.status !== 422) {
            // Refused: nothing changed on the server, so nothing changes here. Everything
            // typed stays where it was, with the reason beside it.
            stop();
            var flashes = takeFlashes(next);
            var beside = flashes.filter(function (f) { return f.type === 'danger' || f.type === 'warning'; });
            if (!inlineError(form, beside)) beside = [];
            showFlashes(flashes.filter(function (f) { return beside.indexOf(f) === -1; }));
            return;
          }
          if (!canSwap(doc)) { handOver(finalUrl, takeFlashes(next)); return; }
          swapIn(next, doc, finalUrl, {
            push: sameDocUrl(finalUrl, location.href) ? 'none' : 'push',
            keepScroll: new URL(finalUrl).pathname === location.pathname
          });
        });
      })
      .catch(function () {
        stop();
        announce('danger', 'Could not reach the server, so nothing was saved. Check the connection and try again.');
      })
      .finally(progressDone);
  };

  // On window, so it runs after the confirmation dialog and the JSON forms in app.js, which
  // listen on document and stop the submits they handle.
  window.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || !formEligible(form)) return;
    var btn = e.submitter || null;
    if (btn && (btn.hasAttribute('formtarget') || btn.hasAttribute('formaction') || optedOut(btn))) return;
    // A second press while the first is out.
    if (form.classList.contains('is-live-busy')) { e.preventDefault(); return; }
    var method = ((btn && btn.getAttribute('formmethod')) || form.getAttribute('method') || 'GET').toUpperCase();
    if (method === 'GET') {
      var url = new URL(form.getAttribute('action') || location.pathname, location.href);
      var params = new URLSearchParams();
      new FormData(form).forEach(function (v, k) { if (typeof v === 'string') params.append(k, v); });
      if (btn && btn.name) params.append(btn.name, btn.value);
      url.search = params.toString();
      if (skipped(url)) return;
      e.preventDefault();
      if (ExamLib.release) ExamLib.release(form);
      navigate(url.href);
      return;
    }
    e.preventDefault();
    submitLive(form, btn);
  });

  ExamLib.swapping = true;
})();
