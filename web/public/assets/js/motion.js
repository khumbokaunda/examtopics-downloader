// Movement and feedback, on every page that loads it (the app and the public pages):
//
//  - Choice chips for the open questions of the institution form (a script-free page keeps the
//    plain text boxes).
//  - A progress bar and, on a slow page, a skeleton of the next page while it loads.
//  - Figures that count up as the page appears, and pictures that fade in.
//  - On the public pages: sections that rise into view, the drawing of a sitting playing, the
//    questions that open smoothly and a top bar that lifts once the page scrolls.
//
// Nothing here is needed to use a page. A person who asked for less motion (their device or
// their appearance settings) gets the same pages without it.
(function () {
  'use strict';
  var root = document.documentElement;
  var reduced = function () {
    return root.getAttribute('data-motion') === 'reduced' || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  };
  var el = function (tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };

  // --- Choice chips -------------------------------------------------------------------------
  // The text box stays the field that is sent: tapping chips writes "A, B" into it, and what is
  // typed in the extra box follows after a full stop. An answer saved earlier is read back the
  // same way, so editing shows the chips already chosen.
  document.querySelectorAll('[data-chip-field]').forEach(function (box) {
    var area = box.querySelector('textarea');
    var picker = box.querySelector('[data-chip-options]');
    if (!area || !picker) return;
    var chips = Array.prototype.slice.call(picker.querySelectorAll('[data-chip]'));
    var labels = chips.map(function (c) { return c.getAttribute('data-chip'); });
    var required = area.required;
    var min = parseInt(area.getAttribute('minlength') || '0', 10);
    area.required = false;
    area.removeAttribute('minlength');
    area.classList.add('chip-source');
    area.setAttribute('tabindex', '-1');
    area.setAttribute('aria-hidden', 'true');

    var other = el('input', 'form-control chip-other');
    other.type = 'text';
    other.placeholder = box.getAttribute('data-chip-other') || 'Something else?';
    other.maxLength = 500;
    other.setAttribute('data-review-skip', '');
    other.setAttribute('aria-label', other.placeholder);
    picker.after(other);
    var hint = box.querySelector('.form-text');
    if (hint && box.getAttribute('data-chip-hint')) hint.textContent = box.getAttribute('data-chip-hint');

    // Read what the text box holds into chips and the extra text.
    var rest = area.value.trim();
    var chosen = {};
    var sorted = labels.slice().sort(function (a, b) { return b.length - a.length; });
    var found = true;
    while (found && rest !== '') {
      found = false;
      for (var i = 0; i < sorted.length; i++) {
        var l = sorted[i];
        if (rest.toLowerCase().indexOf(l.toLowerCase()) === 0 && /^($|[,.;]\s*)/.test(rest.slice(l.length))) {
          chosen[l] = true;
          rest = rest.slice(l.length).replace(/^[,.;]\s*/, '');
          found = true;
          break;
        }
      }
    }
    other.value = rest;

    var write = function () {
      var picked = chips.filter(function (c) { return c.getAttribute('aria-pressed') === 'true'; }).map(function (c) { return c.getAttribute('data-chip'); });
      var extra = other.value.trim();
      if (picked.length && extra !== '') extra = extra.charAt(0).toUpperCase() + extra.slice(1);
      area.value = picked.join(', ') + (extra !== '' ? (picked.length ? '. ' : '') + extra : '');
      var problem = '';
      if (required && area.value === '') problem = 'Choose at least one, or describe it in the box.';
      else if (required && area.value.length < min) problem = 'Tell us a little more: at least ' + min + ' characters.';
      other.setCustomValidity(problem);
      box.classList.toggle('has-choice', picked.length > 0);
    };
    chips.forEach(function (c) {
      if (chosen[c.getAttribute('data-chip')]) c.setAttribute('aria-pressed', 'true');
      c.addEventListener('click', function () {
        var on = c.getAttribute('aria-pressed') !== 'true';
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (on && !reduced()) { c.classList.remove('is-popping'); void c.offsetWidth; c.classList.add('is-popping'); }
        write();
      });
    });
    other.addEventListener('input', write);
    picker.hidden = false;
    write();
    if (area.classList.contains('is-invalid')) other.classList.add('is-invalid');
  });

  // --- A progress bar, and a skeleton of the next page when it is slow ----------------------
  var bar = el('div', 'nav-progress');
  bar.setAttribute('aria-hidden', 'true');
  document.body.appendChild(bar);
  var body = document.getElementById('page-body');
  var skeleton = null;
  var timers = [];
  var skeletonOf = function () {
    var s = el('div', 'nav-skeleton');
    s.setAttribute('aria-hidden', 'true');
    s.appendChild(el('span', 'skeleton skeleton-title'));
    s.appendChild(el('span', 'skeleton skeleton-line is-short'));
    var row = el('div', 'nav-skeleton-row');
    for (var i = 0; i < 4; i++) row.appendChild(el('span', 'skeleton skeleton-tile'));
    s.appendChild(row);
    var card = el('div', 'nav-skeleton-card');
    for (var j = 0; j < 6; j++) card.appendChild(el('span', 'skeleton skeleton-line' + (j % 3 === 2 ? ' is-short' : '')));
    s.appendChild(card);
    return s;
  };
  var leaving = function () {
    clearTimers();
    bar.classList.remove('is-done');
    bar.classList.add('is-loading');
    // A form that answers with a file never leaves the page: give the page back after a while.
    timers.push(setTimeout(reset, 15000));
    // A page that arrives quickly needs no skeleton; one that keeps the person waiting does.
    timers.push(setTimeout(function () {
      if (!body || reduced()) return;
      skeleton = skeleton || skeletonOf();
      body.parentNode.insertBefore(skeleton, body);
      root.classList.add('is-navigating');
    }, 280));
  };
  var clearTimers = function () { timers.forEach(clearTimeout); timers = []; };
  var reset = function () {
    clearTimers();
    root.classList.remove('is-navigating');
    if (skeleton && skeleton.parentNode) skeleton.parentNode.removeChild(skeleton);
    bar.classList.remove('is-loading');
    bar.classList.add('is-done');
  };
  // Coming back with the browser's Back button shows the page as it was, not the skeleton.
  window.addEventListener('pageshow', function (e) { if (e.persisted) reset(); });
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (a.target && a.target !== '_self') return;
    if (a.hasAttribute('download') || a.hasAttribute('data-no-progress')) return;
    var url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    if (url.origin !== location.origin) return;
    if (url.pathname === location.pathname && url.search === location.search && url.hash !== '') return;
    // Files open or download without leaving the page.
    if (/\.(pdf|csv|zip|json|txt|docx?|xlsx?|png|jpe?g|gif|webp|svg)$/i.test(url.pathname) || /\/(download|json)$|\/(files|documents|media)\//i.test(url.pathname)) return;
    // Let other handlers decide first: a link they take over is not a page change.
    setTimeout(function () { if (!e.defaultPrevented) leaving(); }, 0);
  });
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-no-progress') || (form.target && form.target !== '_self')) return;
    setTimeout(function () { if (!e.defaultPrevented) leaving(); }, 0);
  });

  // --- Figures count up, pictures fade in ----------------------------------------------------
  var countUp = function (node) {
    var text = node.textContent.trim();
    var m = text.match(/^(\D*)([\d,]+(?:\.\d+)?)(\D*)$/);
    if (!m) return;
    var target = parseFloat(m[2].replace(/,/g, ''));
    if (!isFinite(target) || target < 2) return;
    var decimals = (m[2].split('.')[1] || '').length;
    var commas = m[2].indexOf(',') !== -1 || target >= 10000;
    var fmt = function (v) {
      var s = v.toFixed(decimals);
      if (commas) s = s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      return m[1] + s + m[3];
    };
    var start = null;
    var dur = Math.min(1100, 500 + target * 2);
    var step = function (t) {
      if (start === null) start = t;
      var p = Math.min(1, (t - start) / dur);
      node.textContent = fmt(target * (1 - Math.pow(1 - p, 3)));
      if (p < 1) requestAnimationFrame(step); else node.textContent = text;
    };
    node.textContent = fmt(0);
    requestAnimationFrame(step);
  };
  if (!reduced()) {
    document.querySelectorAll('.kpi-value, [data-count-up]').forEach(function (n) {
      if (n.children.length === 0) countUp(n);
    });
  }
  document.querySelectorAll('#page-body img, .site-main img').forEach(function (img) {
    if (img.complete) return;
    img.classList.add('is-loading');
    var done = function () { img.classList.remove('is-loading'); };
    img.addEventListener('load', done);
    img.addEventListener('error', done);
  });

  // --- The public pages ------------------------------------------------------------------------
  var reveals = document.querySelectorAll('[data-reveal]');
  if (reveals.length) {
    if (reduced() || !('IntersectionObserver' in window)) {
      reveals.forEach(function (n) { n.classList.add('is-in'); });
    } else {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;
          en.target.classList.add('is-in');
          io.unobserve(en.target);
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
      reveals.forEach(function (n) { io.observe(n); });
    }
  }

  var siteBar = document.querySelector('.site-bar');
  if (siteBar) {
    var lift = function () { siteBar.classList.toggle('is-scrolled', window.scrollY > 8); };
    window.addEventListener('scroll', lift, { passive: true });
    lift();
  }

  // Questions and answers open and close smoothly.
  document.querySelectorAll('.site-faq details').forEach(function (d) {
    var summary = d.querySelector('summary');
    var answer = d.querySelector('summary + *');
    if (!summary || !answer) return;
    summary.addEventListener('click', function (e) {
      if (reduced()) return;
      e.preventDefault();
      var opening = !d.open;
      var from = d.offsetHeight;
      d.open = true;
      var to = opening ? d.offsetHeight : summary.offsetHeight;
      d.style.overflow = 'hidden';
      var anim = d.animate({ height: [from + 'px', to + 'px'] }, { duration: 260, easing: 'cubic-bezier(.2,.8,.2,1)' });
      anim.onfinish = function () { d.style.overflow = ''; if (!opening) d.open = false; };
    });
  });

  // The drawing of a sitting plays: the clock runs, an answer is picked, the paper moves on.
  var preview = document.querySelector('[data-preview]');
  if (preview && !reduced()) {
    var clock = preview.querySelector('[data-preview-clock]');
    var choices = Array.prototype.slice.call(preview.querySelectorAll('[data-preview-choice]'));
    var dots = Array.prototype.slice.call(preview.querySelectorAll('.site-preview-dots i'));
    var number = preview.querySelector('[data-preview-number]');
    var seconds = 24 * 60 + 16;
    var q = 4;
    setInterval(function () {
      seconds = Math.max(0, seconds - 1);
      if (clock) clock.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
    }, 1000);
    var round = 0;
    var pick = function () {
      var right = choices[(round + 1) % choices.length];
      choices.forEach(function (c) { c.classList.remove('is-picked'); });
      setTimeout(function () { right.classList.add('is-picked'); }, 500);
      setTimeout(function () {
        if (dots[q - 1]) { dots[q - 1].classList.remove('is-now'); dots[q - 1].classList.add('is-done'); }
        q = q >= dots.length ? 1 : q + 1;
        if (q === 1) dots.forEach(function (d) { d.classList.remove('is-done'); });
        if (dots[q - 1]) dots[q - 1].classList.add('is-now');
        if (number) number.textContent = String(q);
        round++;
      }, 2600);
    };
    setTimeout(function () { pick(); setInterval(pick, 3400); }, 1400);
  }
})();
