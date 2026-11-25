// Outlines shaped like the page that is coming (shown by swap.js while a page loads).
//
// A placeholder that is the same everywhere says only that something is loading. One shaped
// like the page asked for (the same header, the same cards, a table with the same columns, a
// form with the same fields) shows where things will be before they arrive.
//
// The shape is learnt: after a page has settled its outline is taken (every word becomes a grey
// bar, every field a grey box) and kept in this browser against the kind of address it lives
// at, so every exam's page shares one outline. An outline keeps no text, no value, no link and
// no attribute but layout classes, so nothing a reader saw is written down. A page not opened
// yet in this browser gets an outline for its kind: a list, a record, a form or the home page.
(function () {
  'use strict';
  var ExamLib = (window.ExamLib = window.ExamLib || {});
  var STORE = 'examlib.sk.v1';
  var MAX_ENTRIES = 80;
  var MAX_BYTES = 60000;
  var ID = /^([0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{16,}|\d+)$/i;

  // /jobs/12 and /jobs/40 are one kind of page. A query value that turns a page into another
  // view (a tab, a status) is part of its kind; a search is not, the page keeps its shape.
  var VIEWS = ['tab', 'view', 'status', 'kind', 'type', 'mode', 'show'];
  var keyFor = function (href) {
    var u = new URL(href, location.href);
    var path = u.pathname.split('/').map(function (s) { return ID.test(s) ? ':id' : s; }).join('/');
    var q = VIEWS.filter(function (k) { return u.searchParams.has(k); }).map(function (k) { return k + '=' + u.searchParams.get(k); });
    return path + (q.length ? '?' + q.join('&') : '');
  };

  var load = function () { try { return JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch (e) { return {}; } };
  var save = function (all) {
    var keys = Object.keys(all);
    if (keys.length > MAX_ENTRIES) {
      keys.sort(function (a, b) { return (all[a].t || 0) - (all[b].t || 0); }).slice(0, keys.length - MAX_ENTRIES).forEach(function (k) { delete all[k]; });
    }
    try { localStorage.setItem(STORE, JSON.stringify(all)); } catch (e) { /* full or private */ }
  };

  // ---- Taking an outline --------------------------------------------------------------------
  var width = function (n) { return 'sk-w' + Math.max(1, Math.min(12, Math.ceil(n / 4))); };
  var grey = function (cls) { var s = document.createElement('span'); s.className = 'skeleton ' + cls; return s; };
  var KEEP_ATTR = ['class', 'colspan', 'rowspan'];

  var outline = function (src) {
    var root = src.cloneNode(true);
    root.querySelectorAll('script, style, template, noscript, dialog, .dialog, .live-skeleton, .flash-stack, [hidden], .visually-hidden, .alert, .dt-menu-panel').forEach(function (n) { n.remove(); });
    root.querySelectorAll('tbody').forEach(function (tb) { Array.prototype.slice.call(tb.rows, 8).forEach(function (r) { r.remove(); }); });
    root.querySelectorAll('ul, ol, .list-group').forEach(function (l) { Array.prototype.slice.call(l.children, 6).forEach(function (c) { c.remove(); }); });
    root.querySelectorAll('.bi, i').forEach(function (n) { n.remove(); });
    root.querySelectorAll('input, select, textarea').forEach(function (f) {
      if (f.matches('input[type="hidden"]')) { f.remove(); return; }
      if (f.matches('input[type="checkbox"], input[type="radio"]')) { f.replaceWith(grey('sk-check')); return; }
      f.replaceWith(grey(f.matches('textarea') ? 'sk-field sk-area' : 'sk-field'));
    });
    root.querySelectorAll('img, canvas, video, iframe, svg, object').forEach(function (m) { m.replaceWith(grey('sk-media')); });
    root.querySelectorAll('.btn, .chip, .badge, .page-link, .nav-link, .tab').forEach(function (b) {
      var n = (b.textContent || '').trim().length || 6;
      var kind = b.matches('.btn') ? 'sk-btn' : b.matches('.nav-link, .page-link, .tab') ? 'sk-tab' : 'sk-pill';
      b.replaceWith(grey(kind + ' ' + width(n + 2)));
    });
    // Every word becomes a bar of about its length.
    var walk = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    var texts = [];
    while (walk.nextNode()) texts.push(walk.currentNode);
    texts.forEach(function (t) {
      var n = t.nodeValue.trim().length;
      if (n === 0) { t.remove(); return; }
      t.replaceWith(grey('sk-t ' + width(n)));
    });
    // Layout classes only: no ids, values, links, titles or data.
    [root].concat(Array.prototype.slice.call(root.querySelectorAll('*'))).forEach(function (n) {
      Array.prototype.slice.call(n.attributes).forEach(function (a) { if (KEEP_ATTR.indexOf(a.name) === -1) n.removeAttribute(a.name); });
      if (n !== root && n.matches('a, button, label, form, details, summary')) {
        var span = document.createElement(n.matches('form, details') ? 'div' : 'span');
        span.className = n.className;
        while (n.firstChild) span.appendChild(n.firstChild);
        n.replaceWith(span);
      }
    });
    var html = root.innerHTML;
    // A very long page keeps its top, which is what is seen first.
    while (html.length > MAX_BYTES && root.lastElementChild) { root.lastElementChild.remove(); html = root.innerHTML; }
    return html;
  };

  var remember = function () {
    var body = document.getElementById('page-body');
    var main = document.querySelector('main.app-content');
    if (!body || !main || main.classList.contains('is-loading') || main.querySelector('.live-skeleton')) return;
    // Back changes the address before the page it names is on screen; an outline taken then
    // would file this page under the other's address.
    if (main.dataset.page && keyFor(main.dataset.page) !== keyFor(location.href)) return;
    try {
      var html = outline(body);
      if (html.length < 40) return;
      var all = load();
      all[keyFor(location.href)] = { h: html, t: Date.now() };
      save(all);
    } catch (e) { /* an outline is a nicety */ }
  };
  var later = function (fn) { if (window.requestIdleCallback) requestIdleCallback(fn, { timeout: 2000 }); else setTimeout(fn, 900); };
  // After the page has settled: tables paged, chips drawn.
  var settle = function () { setTimeout(function () { later(remember); }, 700); };
  if (document.querySelector('main.app-content')) {
    if (document.readyState === 'complete') settle(); else window.addEventListener('load', settle);
    document.addEventListener('examlib:load', settle);
  }

  // ---- Outlines for a page not seen yet -------------------------------------------------------
  var rep = function (n, s) { var out = ''; for (var i = 0; i < n; i++) out += typeof s === 'function' ? s(i) : s; return out; };
  var t = function (w, extra) { return '<span class="skeleton sk-t sk-w' + w + (extra ? ' ' + extra : '') + '"></span>'; };
  var header = function (actions) {
    return '<div class="page-header"><div class="page-header-text">' + t(6, 'sk-h1') + '<div class="mt-2">' + t(10) + '</div></div>'
      + (actions ? '<div class="page-header-actions">' + rep(actions, '<span class="skeleton sk-btn sk-w3"></span>') + '</div>' : '') + '</div>';
  };
  var table = function (cols, rows) {
    return '<div class="card"><div class="card-header">' + t(5) + '</div><table class="table mb-0"><thead><tr>'
      + rep(cols, function (i) { return '<th>' + t(i === 0 ? 4 : 3) + '</th>'; }) + '</tr></thead><tbody>'
      + rep(rows, function (r) { return '<tr>' + rep(cols, function (i) { return '<td>' + t(i === 0 ? 5 + (r % 3) : 2 + ((r + i) % 3)) + '</td>'; }) + '</tr>'; })
      + '</tbody></table></div>';
  };
  var kv = function (n) { return '<div class="row g-3">' + rep(n, function (i) { return '<div class="col-sm-6 col-lg-4">' + t(3) + '<div class="mt-1">' + t(4 + (i % 4)) + '</div></div>'; }) + '</div>'; };
  var fields = function (n) { return '<div class="row g-3">' + rep(n, function (i) { return '<div class="col-md-6">' + t(3 + (i % 3)) + '<span class="skeleton sk-field"></span></div>'; }) + '</div>'; };
  var tiles = function (n) { return '<div class="kpi-strip">' + rep(n, '<div class="kpi"><span class="skeleton sk-icon"></span><div>' + t(4) + '<div class="mt-2">' + t(2, 'sk-h1') + '</div></div></div>') + '</div>'; };

  var KINDS = {
    list: function () { return header(2) + table(5, 8); },
    record: function () { return '<div class="mb-2">' + t(5) + '</div>' + header(2) + '<div class="card mb-4"><div class="card-body">' + kv(6) + '</div></div>' + table(3, 4); },
    form: function () {
      return header(0) + '<div class="card"><div class="card-body">' + fields(8)
        + '<div class="d-flex justify-content-end gap-2 mt-4"><span class="skeleton sk-btn sk-w3"></span><span class="skeleton sk-btn sk-w4"></span></div></div></div>';
    },
    home: function () {
      return header(0) + tiles(4) + '<div class="row g-3"><div class="col-lg-8"><div class="card"><div class="card-body">' + t(5) + '<span class="skeleton sk-media sk-chart"></span></div></div></div>'
        + '<div class="col-lg-4"><div class="card"><div class="card-body">' + rep(5, function (i) { return '<div class="mb-3">' + t(7 - (i % 3)) + '</div>'; }) + '</div></div></div></div>';
    }
  };
  var kindOf = function (href) {
    var p = new URL(href, location.href).pathname.replace(/\/+$/, '') || '/';
    if (/\/(new|edit|create|register)$/.test(p) || /^\/settings(\/|$)/.test(p) || p === '/account') return 'form';
    if (p === '/' || p === '/progress') return 'home';
    if (p.split('/').some(function (s) { return ID.test(s); })) return 'record';
    return 'list';
  };

  ExamLib.skeleton = {
    // The outline to show while href loads.
    forUrl: function (href) {
      var seen = load()[keyFor(href)];
      var inner = seen && seen.h ? seen.h : KINDS[kindOf(href)]();
      return '<div class="live-skeleton' + (seen ? ' is-learnt' : '') + '" aria-hidden="true">' + inner + '</div>';
    },
    keyFor: keyFor,
    kindOf: kindOf,
    remember: remember
  };
})();
