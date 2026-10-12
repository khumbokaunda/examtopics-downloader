// Runs in <head> before first paint (an external file because the CSP allows no inline
// script). Signed in, the server renders the saved preferences on <html>; this resolves a
// "system" theme and mirrors the preferences locally so the sign-in page matches. Signed out,
// it applies that local copy.
(function () {
  'use strict';
  var root = document.documentElement;
  var KEY = 'examtopics.appearance';
  var OPTIONS = {
    theme: ['system', 'light', 'dark'],
    accent: ['teal', 'indigo', 'violet', 'amber', 'rose', 'emerald'],
    density: ['comfortable', 'compact', 'cozy'],
    surface: ['classic', 'soft'],
    motion: ['system', 'reduced']
  };
  var ok = function (k, v) { return OPTIONS[k].indexOf(v) !== -1; };
  var prefs = null;
  try {
    if (root.getAttribute('data-prefs') === 'server') {
      prefs = {
        theme: root.getAttribute('data-theme-choice'),
        accent: root.getAttribute('data-accent'),
        density: root.getAttribute('data-density'),
        surface: root.getAttribute('data-surface'),
        motion: root.getAttribute('data-motion') === 'reduced' ? 'reduced' : 'system'
      };
      localStorage.setItem(KEY, JSON.stringify(prefs));
    } else {
      prefs = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (prefs && typeof prefs === 'object') {
        ['accent', 'density', 'surface'].forEach(function (k) { if (ok(k, prefs[k])) root.setAttribute('data-' + k, prefs[k]); });
        if (prefs.motion === 'reduced') root.setAttribute('data-motion', 'reduced');
      }
    }
  } catch (e) { /* storage unavailable: server values stand */ }

  var choice = prefs && ok('theme', prefs.theme) ? prefs.theme : (root.getAttribute('data-theme-choice') || 'system');
  var dark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  root.setAttribute('data-theme-choice', choice);
  root.setAttribute('data-theme', choice === 'system' ? (dark ? 'dark' : 'light') : choice);

  try {
    if (localStorage.getItem('examtopics.sidebar') === 'collapsed') root.setAttribute('data-sidebar-init', 'collapsed');
  } catch (e) { /* ignore */ }
})();
