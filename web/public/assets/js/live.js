/*
 * The live invigilation page: asks the server for every candidate's state every 15 seconds and
 * updates the rows in place (text only, never HTML from the server).
 */
(function () {
  'use strict';
  var box = document.querySelector('[data-live]');
  if (!box) return;
  var url = box.getAttribute('data-live-url');
  var state = box.querySelector('[data-live-state]');
  var labels = { not_started: ['Not started', 'neutral'], writing: ['Writing', 'primary'], paused: ['Paused', 'warning'], handed_in: ['Handed in', 'success'] };
  function chip(text, tone) { var s = document.createElement('span'); s.className = 'chip chip--' + tone; s.textContent = text; return s; }
  function set(row, field, node) { var el = row.querySelector('[data-field="' + field + '"]'); if (!el) return; el.textContent = ''; if (node) el.appendChild(node); }
  function setText(row, field, text) { var el = row.querySelector('[data-field="' + field + '"]'); if (el) el.textContent = text; }
  function poll() {
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
      .then(function (d) {
        (d.rows || []).forEach(function (r) {
          var row = box.querySelector('[data-member="' + r.member + '"]');
          if (!row) return;
          var l = labels[r.state] || labels.not_started;
          set(row, 'state', chip(l[0], l[1]));
          set(row, 'level', r.level === 'review' ? chip('Worth a look', 'warning') : (r.level === 'technical' ? chip('Technical', 'info') : null));
          setText(row, 'progress', r.questions ? r.answered + ' of ' + r.questions + ' answered' : '');
          var live = r.state === 'writing' || r.state === 'paused';
          setText(row, 'time', live ? ' · ' + Math.floor((r.seconds_left || 0) / 60) + ' min left' : '');
          setText(row, 'connection', r.state === 'writing' ? (r.connected ? ' · connected' : ' · not heard from in 2 minutes') : '');
        });
        if (state) state.textContent = 'Updated ' + new Date().toLocaleTimeString();
      })
      .catch(function () { if (state) state.textContent = 'Could not update; trying again'; });
  }
  setInterval(poll, 15000);
})();
