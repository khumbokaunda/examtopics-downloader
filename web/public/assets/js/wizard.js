// Step-by-step forms (registering an institution). Without JavaScript every step shows at once
// and the form sends as usual. With it, one step shows at a time and the track slides to the
// next; a step's fields must be valid before moving on, and the last step lists every answer.
// Values are inserted with textContent only, and sizes are set through the CSSOM (the CSP
// forbids inline styles).
(function () {
  'use strict';

  // A repeated password must match the first.
  document.querySelectorAll('[data-same-as]').forEach(function (el) {
    var other = document.getElementById(el.getAttribute('data-same-as'));
    if (!other) return;
    var check = function () { el.setCustomValidity(el.value !== '' && el.value !== other.value ? 'The passwords do not match.' : ''); };
    el.addEventListener('input', check);
    other.addEventListener('input', check);
  });

  // Extra profile fields asked only of some kinds of institution follow the chosen kind.
  var applies = function (form) {
    var type = form.querySelector('#inst-type');
    var t = type ? type.value : '';
    form.querySelectorAll('[data-applies]').forEach(function (box) {
      var on = t === '' || box.getAttribute('data-applies').split(',').indexOf(t) !== -1;
      box.hidden = !on;
      box.querySelectorAll('input, select, textarea').forEach(function (f) { f.disabled = !on; });
    });
  };
  document.querySelectorAll('form').forEach(function (form) {
    var type = form.querySelector('#inst-type');
    if (!type || !form.querySelector('[data-applies]')) return;
    type.addEventListener('change', function () { applies(form); form.dispatchEvent(new CustomEvent('wizard:refresh')); });
    applies(form);
  });

  document.querySelectorAll('[data-wizard]').forEach(function (wiz) {
    var form = wiz.closest('form');
    var panels = Array.prototype.slice.call(wiz.querySelectorAll('[data-wizard-panel]'));
    var steps = Array.prototype.slice.call(wiz.querySelectorAll('.wizard-step'));
    var track = wiz.querySelector('.wizard-track');
    var viewport = wiz.querySelector('.wizard-viewport');
    var fill = wiz.querySelector('.wizard-bar-fill');
    var back = wiz.querySelector('[data-wizard-back]');
    var next = wiz.querySelector('[data-wizard-next]');
    var count = wiz.querySelector('[data-wizard-count]');
    var review = wiz.querySelector('[data-wizard-review]');
    if (!form || !track || panels.length === 0) return;
    var at = 0;

    var fieldsOf = function (p) {
      return Array.prototype.slice.call(p.querySelectorAll('input, select, textarea')).filter(function (f) { return !f.disabled && f.type !== 'hidden'; });
    };
    // A step with nothing to answer for this kind of institution is skipped.
    var refresh = function () {
      panels.forEach(function (p, i) {
        var skip = !p.hasAttribute('data-wizard-last') && fieldsOf(p).length === 0;
        p.hidden = skip;
        if (steps[i]) steps[i].hidden = skip;
      });
    };
    var shown = function () { return panels.filter(function (p) { return !p.hidden; }); };
    var firstInvalid = function (p) {
      var fs = fieldsOf(p);
      for (var i = 0; i < fs.length; i++) { if (!fs[i].checkValidity()) return fs[i]; }
      return null;
    };
    var fit = function () {
      var p = shown()[at];
      if (p && viewport) viewport.style.height = p.offsetHeight + 'px';
    };

    var labelOf = function (f) {
      var l = f.id ? form.querySelector('label[for="' + f.id + '"]') : null;
      return l ? l.textContent.replace(/\s*\(optional\)\s*$/, '').trim() : (f.name || '');
    };
    var valueOf = function (f) {
      if (f.tagName === 'SELECT') return f.value === '' ? '' : f.options[f.selectedIndex].textContent;
      return f.value.trim();
    };
    var buildReview = function () {
      if (!review) return;
      while (review.firstChild) review.removeChild(review.firstChild);
      shown().forEach(function (p, n) {
        if (p.hasAttribute('data-wizard-last')) return;
        var box = document.createElement('div');
        box.className = 'wizard-review-section';
        var head = document.createElement('div');
        head.className = 'wizard-review-head';
        var title = document.createElement('span');
        title.className = 'fw-semibold';
        title.textContent = p.getAttribute('data-title') || '';
        var edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn btn-sm btn-link p-0';
        edit.textContent = 'Edit';
        edit.addEventListener('click', function () { go(n, true); });
        head.appendChild(title);
        head.appendChild(edit);
        box.appendChild(head);
        var dl = document.createElement('dl');
        fieldsOf(p).forEach(function (f) {
          if (f.type === 'password' || f.type === 'checkbox' || f.type === 'file') return;
          var dt = document.createElement('dt');
          dt.textContent = labelOf(f);
          var dd = document.createElement('dd');
          var v = valueOf(f);
          dd.textContent = v === '' ? 'Not given' : v;
          if (v === '') dd.className = 'text-muted';
          dl.appendChild(dt);
          dl.appendChild(dd);
        });
        box.appendChild(dl);
        review.appendChild(box);
      });
    };

    var go = function (n, focus) {
      var list = shown();
      n = Math.max(0, Math.min(n, list.length - 1));
      at = n;
      var panel = list[n];
      track.style.transform = 'translateX(' + (-100 * n) + '%)';
      panels.forEach(function (p) {
        var on = p === panel;
        p.classList.toggle('is-active', on);
        if (on) { p.removeAttribute('inert'); p.removeAttribute('aria-hidden'); } else { p.setAttribute('inert', ''); p.setAttribute('aria-hidden', 'true'); }
      });
      var visibleSteps = steps.filter(function (s) { return !s.hidden; });
      visibleSteps.forEach(function (s, k) {
        s.classList.toggle('is-done', k < n);
        s.classList.toggle('is-current', k === n);
        var b = s.querySelector('button');
        if (b) { if (k === n) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current'); }
      });
      if (fill) fill.style.width = (list.length > 1 ? (n / (list.length - 1)) * 100 : 100) + '%';
      if (back) back.disabled = n === 0;
      if (next) next.hidden = n === list.length - 1;
      if (count) count.textContent = 'Step ' + (n + 1) + ' of ' + list.length;
      if (panel.hasAttribute('data-wizard-last')) buildReview();
      fit();
      if (focus) {
        var top = wiz.getBoundingClientRect().top;
        if (top < 0) wiz.scrollIntoView({ block: 'start', behavior: 'smooth' });
        setTimeout(function () {
          var f = fieldsOf(panel)[0];
          if (f && !panel.hasAttribute('data-wizard-last')) f.focus({ preventScroll: true });
        }, 360);
      }
    };
    // Moving forward checks every step on the way.
    var forwardTo = function (target) {
      var list = shown();
      for (var k = at; k < target; k++) {
        var bad = firstInvalid(list[k]);
        if (bad) {
          if (k !== at) go(k, false);
          setTimeout(function () { bad.reportValidity(); }, k !== at ? 360 : 0);
          return;
        }
      }
      go(target, true);
    };

    if (next) next.addEventListener('click', function () { forwardTo(at + 1); });
    if (back) back.addEventListener('click', function () { go(at - 1, true); });
    steps.forEach(function (s) {
      var b = s.querySelector('[data-wizard-go]');
      if (!b) return;
      b.addEventListener('click', function () {
        var target = shown().indexOf(panels[parseInt(b.getAttribute('data-wizard-go'), 10)]);
        if (target < 0) return;
        if (target <= at) go(target, true); else forwardTo(target);
      });
    });
    // Enter in a field moves to the next step instead of sending the form early.
    wiz.addEventListener('keydown', function (e) {
      var t = e.target;
      if (e.key !== 'Enter' || t.tagName === 'TEXTAREA' || t.tagName === 'BUTTON' || !t.closest('[data-wizard-panel]')) return;
      if (t.closest('[data-wizard-panel]').hasAttribute('data-wizard-last')) return;
      e.preventDefault();
      forwardTo(at + 1);
    });
    // A field the browser refuses on sending, in a step out of view: go to that step.
    var jumping = false;
    form.addEventListener('invalid', function (e) {
      if (jumping) return;
      var p = e.target.closest ? e.target.closest('[data-wizard-panel]') : null;
      var n = p ? shown().indexOf(p) : -1;
      if (n < 0 || n === at) return;
      jumping = true;
      go(n, false);
      setTimeout(function () { jumping = false; e.target.reportValidity(); }, 380);
    }, true);
    form.addEventListener('wizard:refresh', function () { refresh(); go(Math.min(at, shown().length - 1), false); });
    window.addEventListener('resize', fit);
    if (window.ResizeObserver) {
      var ro = new ResizeObserver(fit);
      panels.forEach(function (p) { ro.observe(p); });
    }

    // Start where the server found a problem, without animating the first view.
    refresh();
    wiz.classList.add('is-ready', 'no-anim');
    var bad = wiz.querySelector('.is-invalid');
    var start = bad ? shown().indexOf(bad.closest('[data-wizard-panel]')) : 0;
    go(start < 0 ? 0 : start, false);
    requestAnimationFrame(function () { requestAnimationFrame(function () { wiz.classList.remove('no-anim'); }); });
  });
})();
