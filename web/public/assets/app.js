// Examtopics front-end. No framework; every dynamic value is inserted with textContent or
// DOM APIs (never innerHTML), and widths are set through the CSSOM (CSP forbids inline styles).
(function () {
  'use strict';

  var BADGE = {
    completed: 'text-bg-success', complete: 'text-bg-success', ready: 'text-bg-success',
    running: 'text-bg-primary', queued: 'text-bg-secondary', partial: 'text-bg-warning',
    failed: 'text-bg-danger', cancelled: 'text-bg-dark'
  };

  function badge(status) {
    var span = document.createElement('span');
    span.className = 'badge ' + (BADGE[status] || 'text-bg-light border');
    span.textContent = status || 'unknown';
    return span;
  }

  function fmtElapsed(s) {
    if (s === null || s === undefined || s === '' || isNaN(s)) return '';
    s = Math.max(0, Math.floor(s));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h ? h + 'h ' + String(m).padStart(2, '0') + 'm' : m + 'm ' + String(sec).padStart(2, '0') + 's') + ' elapsed';
  }

  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }

  // --- Job progress widgets -------------------------------------------------------------
  function setBar(bar, percent, active) {
    if (percent === null || percent === undefined || percent === '') {
      // Unknown total: indeterminate, never an invented percentage.
      bar.classList.add('progress-bar-striped');
      bar.classList.toggle('progress-bar-animated', !!active);
      bar.style.width = '100%';
      bar.classList.add('indeterminate');
      bar.parentElement.removeAttribute('aria-valuenow');
    } else {
      bar.classList.remove('progress-bar-striped', 'progress-bar-animated', 'indeterminate');
      bar.style.width = Math.max(0, Math.min(100, Number(percent))) + '%';
      bar.parentElement.setAttribute('aria-valuenow', String(percent));
    }
  }

  function initJob(el) {
    var url = el.getAttribute('data-status-url');
    var bar = el.querySelector('[data-field="bar"]');
    var elapsedEl = el.querySelector('[data-field="elapsed"]');
    var final = el.getAttribute('data-final') === '1';
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
        var status = el.querySelector('[data-field="status"]');
        status.replaceChildren(badge(j.status));
        el.querySelector('[data-field="phase"]').textContent = j.phase ? j.phase.replace(/_/g, ' ') : '';
        el.querySelector('[data-field="counts"]').textContent =
          j.done === null ? '' : (j.total === null ? String(j.done) : j.done + ' / ' + j.total);
        el.querySelector('[data-field="message"]').textContent = j.message || '';
        if (j.elapsed_seconds !== null) elapsed = j.elapsed_seconds;
        running = j.status === 'running';
        setBar(bar, j.percent, running);
        if (j.final) {
          clearInterval(tick);
          if (el.getAttribute('data-reload-on-finish') === '1') setTimeout(function () { location.reload(); }, 1200);
          return;
        }
        setTimeout(poll, 2000);
      }).catch(function () { setTimeout(poll, 5000); });
    }
    setTimeout(poll, 1500);
  }

  document.querySelectorAll('[data-job-progress]').forEach(initJob);

  // --- Navbar "job running" indicator -----------------------------------------------------
  var indicator = document.getElementById('active-indicator');
  if (indicator) {
    var pollActive = function () {
      getJSON(indicator.getAttribute('data-active-url')).then(function (d) {
        var running = (d.jobs || []).filter(function (j) { return j.status === 'running'; });
        var queued = (d.jobs || []).length - running.length;
        if ((d.jobs || []).length === 0) {
          indicator.classList.add('d-none');
        } else {
          indicator.classList.remove('d-none');
          var text = running.length ? running[0].label + (running[0].percent !== null ? ' · ' + running[0].percent + '%' : '') : 'Job queued';
          if (queued > 0 && running.length) text += ' (+' + queued + ' queued)';
          indicator.querySelector('.label').textContent = text;
        }
        setTimeout(pollActive, (d.jobs || []).length ? 4000 : 15000);
      }).catch(function () { setTimeout(pollActive, 15000); });
    };
    pollActive();
  }

  // --- Forms ------------------------------------------------------------------------------
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

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
        else b.insertAdjacentElement('afterbegin', Object.assign(document.createElement('span'), { className: 'spinner-border spinner-border-sm me-1' }));
      });
    });
  });

  document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
    sel.addEventListener('change', function () { if (sel.form) sel.form.submit(); });
  });

  // Instant client-side filtering of a table (the form still searches server-side).
  document.querySelectorAll('input[data-filter-table]').forEach(function (input) {
    var table = document.querySelector(input.getAttribute('data-filter-table'));
    if (!table) return;
    input.addEventListener('input', function () {
      var needle = input.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr[data-filter-text]').forEach(function (tr) {
        tr.hidden = needle !== '' && tr.getAttribute('data-filter-text').toLowerCase().indexOf(needle) === -1;
      });
    });
  });

  // --- Practice mode: reveal one question's answer on demand ---------------------------------
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }

  function multiline(container, text) {
    String(text).split('\n').forEach(function (line, i) {
      if (i > 0) container.appendChild(document.createElement('br'));
      container.appendChild(document.createTextNode(line));
    });
  }

  document.querySelectorAll('[data-reveal-url]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.nextElementSibling;
      if (!target.hidden && target.childElementCount) { target.hidden = true; btn.textContent = 'Reveal answer'; return; }
      btn.disabled = true;
      getJSON(btn.getAttribute('data-reveal-url')).then(function (d) {
        target.replaceChildren();
        var box = el('div', 'answer-box');
        box.appendChild(el('strong', null, 'Answer: '));
        box.appendChild(document.createTextNode(d.answer || 'not available'));
        if (d.votes) box.appendChild(el('div', 'small text-muted', 'Community votes: ' + d.votes));
        target.appendChild(box);
        (d.answer_images || []).forEach(function (u, i) {
          var img = el('img', 'q-image');
          img.src = u; img.alt = 'Answer image ' + (i + 1); img.referrerPolicy = 'no-referrer'; img.loading = 'lazy';
          target.appendChild(img);
        });
        if (d.explanation) {
          var ex = el('div', 'explanation mt-2');
          ex.appendChild(el('div', 'small text-uppercase fw-semibold text-muted', 'Explanation'));
          multiline(ex, d.explanation);
          target.appendChild(ex);
        }
        if ((d.comments || []).length) {
          var det = el('details', 'mt-2');
          det.appendChild(el('summary', 'small fw-semibold', 'Discussion (' + d.comments.length + ')'));
          d.comments.forEach(function (c) {
            var cm = el('div', 'comment');
            var head = [c.author, c.timestamp, c.upvotes === null ? null : c.upvotes + ' upvotes'].filter(Boolean).join(' · ');
            if (head) cm.appendChild(el('div', 'small text-muted', head));
            var body = el('div');
            multiline(body, c.content);
            cm.appendChild(body);
            det.appendChild(cm);
          });
          target.appendChild(det);
        }
        target.hidden = false;
        btn.textContent = 'Hide answer';
      }).catch(function () {
        target.replaceChildren(el('div', 'text-danger small', 'Could not load the answer. Reload the page and try again.'));
        target.hidden = false;
      }).finally(function () { btn.disabled = false; });
    });
  });
})();
