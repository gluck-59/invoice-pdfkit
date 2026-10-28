(function () {
  'use strict';

  var STORAGE_KEY = 'inquistador-theme';
  var LABELS = ['auto', 'light', 'dark'];
  var ICONS = { auto: '◐', light: '☀', dark: '☾' };

  function apply(theme) {
    if (theme === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    } else if (theme === 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
    } else {
      var mqDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');
      var mqLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)');
      if (mqDark && mqDark.matches) {
        document.documentElement.setAttribute('data-theme', 'dark');
      } else if (mqLight && mqLight.matches) {
        document.documentElement.setAttribute('data-theme', 'light');
      } else {
        var hour = new Date().getHours();
        document.documentElement.setAttribute('data-theme', (hour >= 6 && hour < 18) ? 'light' : 'dark');
      }
    }
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (e) {}
  }

  function current() {
    try {
      var t = localStorage.getItem(STORAGE_KEY);
      if (t === 'light' || t === 'dark' || t === 'auto') {
        return t;
      }
    } catch (e) {}
    return 'auto';
  }

  apply(current());

  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    if (mq && mq.addEventListener) {
      mq.addEventListener('change', function () {
        apply(current());
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.querySelector('.theme-toggle');
    if (btn) {
      function update() {
        var t = current();
        btn.setAttribute('aria-label', (btn.dataset.label || 'Theme') + ': ' + t);
        btn.textContent = ICONS[t] || ICONS.auto;
      }
      update();
      btn.addEventListener('click', function () {
        var next = LABELS[(LABELS.indexOf(current()) + 1) % LABELS.length];
        apply(next);
        update();
      });
    }

    var burger = document.querySelector('.nav-burger');
    var links = document.querySelector('.nav-links');
    if (burger && links) {
      burger.addEventListener('click', function () {
        var open = links.classList.toggle('open');
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      links.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') {
          links.classList.remove('open');
          burger.setAttribute('aria-expanded', 'false');
        }
      });
    }
  });
})();
