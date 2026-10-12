// Data tables: a progressive enhancement for <table data-table>. Without JavaScript the
// table is a plain, complete list; with it, the table gains a search box, sortable columns
// (Shift+click adds a secondary sort), page sizes, a pager, a status line, column
// visibility, CSV export and copy. Its state (search, sort, page size, page, hidden
// columns) is saved per table in this browser and restored on the next visit.
//
// Markup it reads:
//   <table data-table="key" data-page-length="25" data-order='[[0,"asc"]]'>
//   <th data-nosort> no sorting · <th data-type="num"> numeric sort · <th data-export="false">
//   left out of CSV and copy (action columns) · <th data-novis> cannot be hidden
//   <td data-order="..."> sort value · <td data-search="..."> extra search text
//
// Rows on other pages are hidden, not removed, so forms inside them keep working. Every
// value is inserted with DOM APIs; nothing is parsed as HTML.
(function () {
  'use strict';

  var LENGTHS = [10, 25, 50, 100, 200, -1];
  var STORE = 'examtopics.dt.';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }
  function icon(name) {
    var i = el('i', 'bi bi-' + name);
    i.setAttribute('aria-hidden', 'true');
    return i;
  }
  function cellText(cell) {
    return (cell.textContent || '').replace(/\s+/g, ' ').trim();
  }
  function fmt(n) {
    return Number(n).toLocaleString();
  }
  function load(key) {
    try { return JSON.parse(localStorage.getItem(STORE + key) || 'null'); } catch (e) { return null; }
  }
  function save(key, state) {
    try { localStorage.setItem(STORE + key, JSON.stringify(state)); } catch (e) { /* storage unavailable */ }
  }
  function forget(key) {
    try { localStorage.removeItem(STORE + key); } catch (e) { /* ignore */ }
  }

  function DataTable(table, index) {
    var self = this;
    this.table = table;
    this.key = location.pathname + '#' + (table.getAttribute('data-table') || table.id || String(index));
    this.headers = Array.prototype.slice.call(table.tHead ? table.tHead.rows[0].cells : []);
    this.body = table.tBodies[0];
    this.rows = Array.prototype.slice.call(this.body ? this.body.rows : []).map(function (tr, i) {
      var cells = Array.prototype.slice.call(tr.cells);
      return {
        tr: tr, index: i, cells: cells,
        text: cells.map(function (c, ci) {
          if (self.headers[ci] && self.headers[ci].hasAttribute('data-nosearch')) return '';
          return (cellText(c) + ' ' + (c.getAttribute('data-search') || '')).toLowerCase();
        }).join(' '),
        keys: cells.map(function (c) { return c.hasAttribute('data-order') ? c.getAttribute('data-order') : cellText(c); })
      };
    });
    this.types = this.headers.map(function (th, ci) {
      if (th.getAttribute('data-type')) return th.getAttribute('data-type');
      var values = self.rows.map(function (r) { return r.keys[ci]; }).filter(function (v) { return v !== ''; });
      var numeric = values.length > 0 && values.every(function (v) { return /^-?[\d,]+(\.\d+)?%?$/.test(v) || v === 'n/a'; });
      return numeric ? 'num' : 'text';
    });

    var defaults = {
      q: '', page: 0,
      // <th data-hidden> starts hidden; Columns can show it.
      hidden: this.headers.map(function (th, ci) { return th.hasAttribute('data-hidden') ? ci : -1; }).filter(function (ci) { return ci !== -1; }),
      len: parseInt(table.getAttribute('data-page-length') || '25', 10),
      order: (function () { try { return JSON.parse(table.getAttribute('data-order') || '[]'); } catch (e) { return []; } })()
    };
    var saved = load(this.key) || {};
    this.state = {
      q: typeof saved.q === 'string' ? saved.q : defaults.q,
      page: typeof saved.page === 'number' ? saved.page : defaults.page,
      len: LENGTHS.indexOf(saved.len) !== -1 ? saved.len : defaults.len,
      order: Array.isArray(saved.order) ? saved.order.filter(function (o) { return Array.isArray(o) && self.headers[o[0]] && !self.headers[o[0]].hasAttribute('data-nosort'); }) : defaults.order,
      hidden: Array.isArray(saved.hidden) ? saved.hidden.filter(function (c) { return self.headers[c] && !self.headers[c].hasAttribute('data-novis'); }) : defaults.hidden
    };
    this.defaults = defaults;
    this.build();
    this.draw();
  }

  DataTable.prototype.persist = function () {
    save(this.key, this.state);
  };

  DataTable.prototype.build = function () {
    var self = this;
    var table = this.table;
    table.classList.add('dt-enhanced');
    var anchor = table.parentElement && table.parentElement.classList.contains('table-responsive') ? table.parentElement : table;

    // Toolbar: search on the left, tools on the right.
    var bar = el('div', 'dt-toolbar');
    var search = el('div', 'search-field dt-search');
    search.appendChild(icon('search'));
    this.input = el('input', 'form-control form-control-sm');
    this.input.type = 'search';
    this.input.placeholder = 'Search this table';
    this.input.setAttribute('aria-label', 'Search this table');
    this.input.value = this.state.q;
    search.appendChild(this.input);
    bar.appendChild(search);

    var tools = el('div', 'dt-tools');
    var lenLabel = el('label', 'dt-length');
    lenLabel.appendChild(document.createTextNode('Show'));
    this.lenSelect = el('select', 'form-select form-select-sm');
    this.lenSelect.setAttribute('aria-label', 'Rows per page');
    LENGTHS.forEach(function (n) {
      var o = el('option', null, n === -1 ? 'All' : String(n));
      o.value = String(n);
      if (n === self.state.len) o.selected = true;
      self.lenSelect.appendChild(o);
    });
    lenLabel.appendChild(this.lenSelect);
    tools.appendChild(lenLabel);

    // Column visibility.
    var hideable = this.headers.filter(function (th) { return !th.hasAttribute('data-novis') && cellText(th) !== ''; });
    if (hideable.length > 1) {
      var menu = el('div', 'dt-menu');
      var toggle = el('button', 'btn btn-sm btn-outline-secondary');
      toggle.type = 'button';
      toggle.setAttribute('aria-haspopup', 'true');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.appendChild(icon('layout-three-columns'));
      toggle.appendChild(document.createTextNode('Columns'));
      var panel = el('div', 'dt-menu-panel');
      panel.hidden = true;
      this.headers.forEach(function (th, ci) {
        if (th.hasAttribute('data-novis') || cellText(th) === '') return;
        var lab = el('label', 'dt-menu-item');
        var cb = el('input', 'form-check-input');
        cb.type = 'checkbox';
        cb.checked = self.state.hidden.indexOf(ci) === -1;
        cb.setAttribute('data-col', String(ci));
        cb.addEventListener('change', function () {
          var at = self.state.hidden.indexOf(ci);
          if (cb.checked && at !== -1) self.state.hidden.splice(at, 1);
          if (!cb.checked && at === -1) self.state.hidden.push(ci);
          self.persist();
          self.draw();
        });
        lab.appendChild(cb);
        lab.appendChild(document.createTextNode(cellText(th)));
        panel.appendChild(lab);
      });
      toggle.addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        toggle.setAttribute('aria-expanded', String(!panel.hidden));
      });
      document.addEventListener('click', function (e) {
        if (!panel.hidden && !menu.contains(e.target)) { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); }
      });
      menu.appendChild(toggle);
      menu.appendChild(panel);
      tools.appendChild(menu);
    }

    var csv = el('button', 'btn btn-sm btn-outline-secondary');
    csv.type = 'button';
    csv.title = 'Download the matching rows as a CSV file';
    csv.appendChild(icon('filetype-csv'));
    csv.appendChild(document.createTextNode('CSV'));
    csv.addEventListener('click', function () { self.exportCsv(); });
    tools.appendChild(csv);

    var copy = el('button', 'btn btn-sm btn-outline-secondary');
    copy.type = 'button';
    copy.title = 'Copy the matching rows, ready to paste into a spreadsheet';
    copy.appendChild(icon('clipboard'));
    this.copyLabel = el('span', null, 'Copy');
    copy.appendChild(this.copyLabel);
    copy.addEventListener('click', function () { self.copy(); });
    tools.appendChild(copy);

    var reset = el('button', 'btn btn-sm btn-link');
    reset.type = 'button';
    reset.title = 'Clear the search, sorting and column choices for this table';
    reset.appendChild(icon('arrow-counterclockwise'));
    reset.appendChild(document.createTextNode('Reset'));
    reset.addEventListener('click', function () {
      forget(self.key);
      self.state = { q: '', page: 0, len: self.defaults.len, order: self.defaults.order.slice(), hidden: self.defaults.hidden.slice() };
      self.input.value = '';
      self.lenSelect.value = String(self.state.len);
      bar.querySelectorAll('.dt-menu-item input').forEach(function (cb) { cb.checked = self.state.hidden.indexOf(parseInt(cb.getAttribute('data-col'), 10)) === -1; });
      self.draw();
    });
    tools.appendChild(reset);
    bar.appendChild(tools);
    anchor.parentNode.insertBefore(bar, anchor);

    // Footer: status line and pager.
    var foot = el('div', 'dt-footer');
    this.info = el('div', 'dt-info');
    this.info.setAttribute('aria-live', 'polite');
    this.pager = el('nav', 'dt-pager');
    this.pager.setAttribute('aria-label', 'Table pages');
    foot.appendChild(this.info);
    foot.appendChild(this.pager);
    anchor.parentNode.insertBefore(foot, anchor.nextSibling);

    // A row shown when nothing matches the search.
    this.emptyRow = el('tr', 'dt-empty');
    var td = el('td', null, 'No rows match this search.');
    td.colSpan = Math.max(1, this.headers.length);
    this.emptyRow.appendChild(td);
    this.emptyRow.hidden = true;
    if (this.body) this.body.appendChild(this.emptyRow);

    // Sortable headers become buttons, so they work from the keyboard.
    this.headers.forEach(function (th, ci) {
      if (th.hasAttribute('data-nosort') || cellText(th) === '') return;
      var btn = el('button', 'dt-sort');
      btn.type = 'button';
      while (th.firstChild) btn.appendChild(th.firstChild);
      th.appendChild(btn);
      btn.addEventListener('click', function (e) { self.sortBy(ci, e.shiftKey); });
    });

    var timer = null;
    this.input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { self.state.q = self.input.value; self.state.page = 0; self.persist(); self.draw(); }, 120);
    });
    // Enter in the search box filters instead of submitting a surrounding form.
    this.input.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    this.lenSelect.addEventListener('change', function () {
      self.state.len = parseInt(self.lenSelect.value, 10);
      self.state.page = 0;
      self.persist();
      self.draw();
    });
  };

  DataTable.prototype.sortBy = function (ci, add) {
    var order = this.state.order;
    var at = -1;
    for (var i = 0; i < order.length; i++) if (order[i][0] === ci) at = i;
    if (add) {
      if (at === -1) order.push([ci, 'asc']);
      else order[at][1] = order[at][1] === 'asc' ? 'desc' : 'asc';
    } else {
      var dir = at !== -1 && order[at][1] === 'asc' ? 'desc' : 'asc';
      this.state.order = [[ci, dir]];
    }
    this.state.page = 0;
    this.persist();
    this.draw();
  };

  DataTable.prototype.compare = function (a, b) {
    var order = this.state.order;
    for (var i = 0; i < order.length; i++) {
      var ci = order[i][0], dir = order[i][1] === 'desc' ? -1 : 1;
      var x = a.keys[ci] || '', y = b.keys[ci] || '';
      var r;
      if (this.types[ci] === 'num') {
        var nx = parseFloat(String(x).replace(/[,%]/g, '')), ny = parseFloat(String(y).replace(/[,%]/g, ''));
        if (isNaN(nx) && isNaN(ny)) r = 0;
        else if (isNaN(nx)) r = 1;
        else if (isNaN(ny)) r = -1;
        else r = nx - ny;
      } else {
        r = String(x).localeCompare(String(y), undefined, { numeric: true, sensitivity: 'base' });
      }
      if (r !== 0) return r * dir;
    }
    return a.index - b.index;
  };

  DataTable.prototype.matching = function () {
    var self = this;
    var words = this.state.q.toLowerCase().split(/\s+/).filter(Boolean);
    var rows = this.rows.filter(function (r) {
      return words.every(function (w) { return r.text.indexOf(w) !== -1; });
    });
    if (this.state.order.length) rows.sort(function (a, b) { return self.compare(a, b); });
    return rows;
  };

  DataTable.prototype.draw = function () {
    var self = this;
    var rows = this.matching();
    var total = rows.length;
    var len = this.state.len === -1 ? Math.max(total, 1) : this.state.len;
    var pages = Math.max(1, Math.ceil(total / len));
    if (this.state.page >= pages) this.state.page = pages - 1;
    if (this.state.page < 0) this.state.page = 0;
    var start = this.state.page * len;
    var shown = rows.slice(start, start + len);

    // Reorder the DOM to match the sort, then show only this page.
    var frag = document.createDocumentFragment();
    rows.forEach(function (r) { frag.appendChild(r.tr); });
    var matched = new Set(rows);
    this.rows.forEach(function (r) { if (!matched.has(r)) frag.appendChild(r.tr); });
    if (this.body) {
      this.body.insertBefore(frag, this.emptyRow);
    }
    var visible = new Set(shown);
    this.rows.forEach(function (r) { r.tr.hidden = !visible.has(r); });
    this.emptyRow.hidden = total > 0;

    // Column visibility.
    var hidden = this.state.hidden;
    this.headers.forEach(function (th, ci) { th.classList.toggle('dt-hidden', hidden.indexOf(ci) !== -1); });
    this.rows.forEach(function (r) {
      r.cells.forEach(function (c, ci) { c.classList.toggle('dt-hidden', hidden.indexOf(ci) !== -1); });
    });
    this.emptyRow.cells[0].colSpan = Math.max(1, this.headers.length - hidden.length);

    // Sort indicators.
    this.headers.forEach(function (th, ci) {
      var o = null;
      self.state.order.forEach(function (x, i) { if (x[0] === ci) o = [x[1], i]; });
      if (o) th.setAttribute('aria-sort', o[0] === 'asc' ? 'ascending' : 'descending');
      else th.removeAttribute('aria-sort');
      th.classList.toggle('dt-secondary', !!(o && o[1] > 0));
    });

    // Status line.
    var all = this.rows.length;
    this.info.textContent = total === 0
      ? (all === 0 ? 'No rows' : 'No matching rows (' + fmt(all) + ' in total)')
      : 'Showing ' + fmt(start + 1) + ' to ' + fmt(Math.min(start + len, total)) + ' of ' + fmt(total) + (total < all ? ' (filtered from ' + fmt(all) + ')' : '');

    // Pager.
    this.pager.replaceChildren();
    if (pages > 1) {
      var page = this.state.page;
      var add = function (label, target, opts) {
        var b = el('button', 'dt-page' + (opts && opts.active ? ' is-active' : ''));
        b.type = 'button';
        if (opts && opts.icon) { b.appendChild(icon(opts.icon)); b.setAttribute('aria-label', label); } else b.textContent = label;
        if (opts && opts.active) b.setAttribute('aria-current', 'page');
        b.disabled = target === null;
        if (target !== null) b.addEventListener('click', function () { self.state.page = target; self.persist(); self.draw(); });
        self.pager.appendChild(b);
      };
      add('Previous page', page > 0 ? page - 1 : null, { icon: 'chevron-left' });
      var windowPages = [0, page - 1, page, page + 1, pages - 1].filter(function (p, i, arr) { return p >= 0 && p < pages && arr.indexOf(p) === i; }).sort(function (a, b) { return a - b; });
      var prev = -1;
      windowPages.forEach(function (p) {
        if (prev !== -1 && p > prev + 1) self.pager.appendChild(el('span', 'dt-gap', '…'));
        add(String(p + 1), p === page ? null : p, { active: p === page });
        prev = p;
      });
      add('Next page', page < pages - 1 ? page + 1 : null, { icon: 'chevron-right' });
    }
  };

  DataTable.prototype.exportRows = function () {
    var self = this;
    var cols = this.headers.map(function (th, ci) { return ci; }).filter(function (ci) {
      var th = self.headers[ci];
      return self.state.hidden.indexOf(ci) === -1 && th.getAttribute('data-export') !== 'false' && cellText(th) !== '';
    });
    var out = [cols.map(function (ci) { return cellText(self.headers[ci]); })];
    this.matching().forEach(function (r) {
      out.push(cols.map(function (ci) {
        var c = r.cells[ci];
        return c ? (c.getAttribute('data-export') || cellText(c)) : '';
      }));
    });
    return out;
  };

  DataTable.prototype.exportCsv = function () {
    var csv = this.exportRows().map(function (row) {
      return row.map(function (v) { return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(',');
    }).join('\r\n');
    var name = (this.table.getAttribute('data-table') || 'table') + '-' + new Date().toISOString().slice(0, 10) + '.csv';
    var url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
    var a = el('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  };

  DataTable.prototype.copy = function () {
    var self = this;
    var tsv = this.exportRows().map(function (row) { return row.map(function (v) { return v.replace(/[\t\n\r]+/g, ' '); }).join('\t'); }).join('\n');
    var done = function (ok) {
      self.copyLabel.textContent = ok ? 'Copied' : 'Copy failed';
      setTimeout(function () { self.copyLabel.textContent = 'Copy'; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(tsv).then(function () { done(true); }, function () { done(false); });
      return;
    }
    var ta = el('textarea', 'visually-hidden');
    ta.value = tsv;
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    done(ok);
  };

  document.querySelectorAll('table[data-table]').forEach(function (t, i) { t.dataTable = new DataTable(t, i); });
})();
