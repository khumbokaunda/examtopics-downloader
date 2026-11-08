/*
 * Sitting an assessment. Without this script every question shows and "Hand in" sends every
 * answer; the server grades it either way.
 *
 * With it:
 *  - one question shows at a time, with a panel to jump between them;
 *  - each answer is saved as it is picked. Saves carry a number that only goes up, so the server
 *    keeps the newest even when an older request arrives late;
 *  - an answer the server has not confirmed is kept on this device (localStorage) and sent again,
 *    with growing pauses, until it is; the bar says what has not reached the server yet;
 *  - the clock counts down the time the server gave, checks it with the server every minute, and
 *    hands in at zero. The server refuses anything after its own end time regardless.
 */
(function () {
  'use strict';
  var form = document.querySelector('form[data-sitting]');
  if (!form) return;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var uuid = form.getAttribute('data-uuid');
  var saveUrl = form.getAttribute('data-save-url');
  var statusUrl = form.getAttribute('data-status-url');
  var storeKey = 'sitting:' + uuid;
  var qs = Array.prototype.slice.call(form.querySelectorAll('.mock-question'));
  var navBtns = Array.prototype.slice.call(form.querySelectorAll('[data-mock-go]'));
  var answeredEl = form.querySelector('[data-mock-answered]');
  var saveState = form.querySelector('[data-save-state]');
  var saveText = saveState ? saveState.querySelector('span') : null;
  var current = 0;
  var lastSeq = parseInt(form.getAttribute('data-seq') || '0', 10) || 0;
  var ended = false;

  function store() { try { return JSON.parse(localStorage.getItem(storeKey) || '{}') || {}; } catch (e) { return {}; } }
  function keep(pending) { try { localStorage.setItem(storeKey, JSON.stringify(pending)); } catch (e) { /* private window: memory only */ } }
  var pending = store();
  Object.keys(pending).forEach(function (k) { lastSeq = Math.max(lastSeq, pending[k].seq || 0); });
  function nextSeq() { lastSeq = Math.max(lastSeq + 1, Date.now()); return lastSeq; }

  function section(pos) { return form.querySelector('.mock-question[data-position="' + pos + '"]'); }
  function answerOf(q) {
    return Array.prototype.slice.call(q.querySelectorAll('.mock-pick:checked')).map(function (i) { return i.value; }).sort().join('');
  }
  function flaggedOf(q) { var f = q.querySelector('[data-flag]'); return !!(f && f.checked); }

  // Answers kept on this device but newer than what the page was drawn with: show them again.
  Object.keys(pending).forEach(function (pos) {
    var q = section(pos);
    if (!q) return;
    var p = pending[pos];
    q.querySelectorAll('.mock-pick').forEach(function (i) { i.checked = p.answer.indexOf(i.value) !== -1; });
    var f = q.querySelector('[data-flag]');
    if (f) f.checked = !!p.flagged;
  });

  function refresh() {
    var n = 0;
    qs.forEach(function (q, i) {
      var done = answerOf(q) !== '';
      n += done ? 1 : 0;
      var b = navBtns[i];
      if (!b) return;
      b.classList.toggle('is-answered', done);
      b.classList.toggle('is-flagged', flaggedOf(q));
      b.classList.toggle('is-current', i === current);
      if (i === current) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
    });
    if (answeredEl) answeredEl.textContent = n + ' of ' + qs.length + ' answered';
    var unanswered = qs.length - n;
    var flagged = qs.filter(flaggedOf).length;
    form.setAttribute('data-confirm', n + ' of ' + qs.length + ' answered' + (unanswered ? ', ' + unanswered + ' unanswered' : '')
      + (flagged ? ', ' + flagged + ' marked to look at again' : '') + '. You cannot change your answers after handing in.');
  }
  function go(i, scroll) {
    current = Math.max(0, Math.min(qs.length - 1, i));
    qs.forEach(function (q, k) { q.hidden = k !== current; });
    refresh();
    if (scroll) window.scrollTo(0, 0);
  }
  var nav = form.querySelector('.mock-nav');
  if (nav) nav.hidden = false;
  form.querySelectorAll('.mock-steps').forEach(function (el) { el.hidden = false; });
  go(0, false);

  function showState() {
    if (!saveState) return;
    var n = Object.keys(pending).length;
    saveState.classList.toggle('is-error', n > 0 && failures > 0);
    saveState.classList.toggle('is-saving', n > 0 && failures === 0);
    if (saveText) saveText.textContent = n === 0 ? 'Saved' : (failures > 0 ? (n === 1 ? '1 answer' : n + ' answers') + ' not saved yet, retrying' : 'Saving…');
  }

  var sending = false;
  var failures = 0;
  var retryTimer = null;
  function flush() {
    if (sending || ended) return;
    var positions = Object.keys(pending);
    if (positions.length === 0) { failures = 0; showState(); return; }
    sending = true;
    showState();
    var pos = positions[0];
    var item = pending[pos];
    var body = 'position=' + encodeURIComponent(pos) + '&answer=' + encodeURIComponent(item.answer) + '&flagged=' + (item.flagged ? '1' : '0') + '&seq=' + item.seq;
    fetch(saveUrl, { method: 'POST', credentials: 'same-origin', body: body,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf, Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }, function () { return { status: r.status, d: {} }; }); })
      .then(function (res) {
        sending = false;
        if (res.d && res.d.ended) { finished(res.d.done_url); return; }
        if (res.status === 200 || res.status === 422) {
          // Saved, or refused for good (a question that does not exist): either way, stop sending it.
          if (pending[pos] && pending[pos].seq === item.seq) { delete pending[pos]; keep(pending); }
          failures = 0;
          flush();
          return;
        }
        throw new Error('HTTP ' + res.status);
      })
      .catch(function () {
        sending = false;
        failures++;
        showState();
        clearTimeout(retryTimer);
        retryTimer = setTimeout(flush, Math.min(30000, 1000 * Math.pow(2, Math.min(failures, 5))));
      });
  }
  window.addEventListener('online', function () { failures = 0; flush(); });

  function queue(q) {
    var pos = q.getAttribute('data-position');
    pending[pos] = { answer: answerOf(q), flagged: flaggedOf(q), seq: nextSeq() };
    keep(pending);
    refresh();
    flush();
  }
  form.addEventListener('change', function (ev) {
    var q = ev.target.closest ? ev.target.closest('.mock-question') : null;
    if (q) queue(q);
  });
  form.addEventListener('click', function (ev) {
    var t = ev.target.closest ? ev.target.closest('[data-mock-go],[data-mock-step],[data-clear]') : null;
    if (!t) return;
    ev.preventDefault();
    if (t.hasAttribute('data-clear')) {
      var q = t.closest('.mock-question');
      q.querySelectorAll('.mock-pick').forEach(function (i) { i.checked = false; });
      queue(q);
      return;
    }
    go(t.hasAttribute('data-mock-go') ? parseInt(t.getAttribute('data-mock-go'), 10) : current + parseInt(t.getAttribute('data-mock-step'), 10), true);
  });
  // Only a hand-in that goes ahead ends the sitting here: the confirmation dialog stops the first
  // submit, and "Cancel" must leave saving and the clock running.
  form.addEventListener('submit', function (ev) {
    if (ev.defaultPrevented) return;
    ended = true;
    try { localStorage.removeItem(storeKey); } catch (e) { /* ignore */ }
  });

  function finished(url) {
    ended = true;
    try { localStorage.removeItem(storeKey); } catch (e) { /* ignore */ }
    window.location.href = url || window.location.href;
  }

  // The clock.
  var timer = form.querySelector('[data-mock-timer]');
  var label = timer ? timer.querySelector('[data-field="time"]') : null;
  var end = Date.now() + (parseInt(form.getAttribute('data-ends-in') || '0', 10) * 1000);
  var low = Math.min(300, Math.round(parseInt(form.getAttribute('data-minutes') || '0', 10) * 60 / 5));
  function tick() {
    if (ended) return;
    var s = Math.max(0, Math.round((end - Date.now()) / 1000));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    if (label) label.textContent = (h > 0 ? h + ':' + (m < 10 ? '0' : '') + m : String(m)) + ':' + (sec < 10 ? '0' : '') + sec;
    if (timer) {
      timer.classList.toggle('is-low', s > 0 && s <= low);
      timer.classList.toggle('is-out', s === 0);
    }
    if (s === 0) {
      var state = form.querySelector('[data-mock-state]');
      if (state) state.textContent = 'Time is up. Handing in the answers the server has…';
      ended = true;
      HTMLFormElement.prototype.submit.call(form);
      return;
    }
    setTimeout(tick, 500);
  }
  tick();
  setInterval(function () {
    if (ended) return;
    fetch(statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.status && d.status !== 'in_progress') { finished(form.action.replace(/\/submit$/, '/done')); return; }
        if (typeof d.seconds_left === 'number') end = Date.now() + d.seconds_left * 1000;
      })
      .catch(function () { /* offline: keep counting */ });
  }, 60000);

  flush();
})();
