// Shared i18n for the HTML prototypes (browser only, no build step).
// Language list comes from GET /api/languages (reads i18n/ on the server).
// Single source of truth for the chosen language is the "pdf-lang" cookie
// (readable by both JS and PHP). Until the list loads, the select keeps
// its single "en" fallback option.
(function () {
  "use strict";

  var FALLBACK_LANGS = ["en"];
  var LANGS = FALLBACK_LANGS.slice();
  var KEY = "pdf-lang";

  function baseLang(tag) {
    if (!tag) return null;
    var b = String(tag).toLowerCase().split(/[-_]/)[0];
    return LANGS.indexOf(b) !== -1 ? b : null;
  }

  function getCookie(name) {
    try {
      var parts = (document.cookie || "").split(";");
      for (var i = 0; i < parts.length; i++) {
        var kv = parts[i].split("=");
        if (kv[0].trim() === name) return decodeURIComponent((kv[1] || "").trim());
      }
    } catch (e) {}
    return null;
  }

  function setCookie(name, value) {
    document.cookie =
      name + "=" + encodeURIComponent(value) + ";path=/;SameSite=Lax";
  }

  // Single supported URL param: ?lang=xx. Everything else
  // (?locale=, ?language=, ...) is ignored.
  function queryLang() {
    try {
      var m = /[?&]lang=([^&#]*)/.exec(window.location.search || "");
      if (!m) return null;
      return baseLang(decodeURIComponent(m[1]));
    } catch (e) {
      return null;
    }
  }

  function storedLang() {
    var saved = getCookie(KEY);
    return saved && LANGS.indexOf(saved) !== -1 ? saved : null;
  }

  function currentLang() {
    return (
      queryLang() ||
      storedLang() ||
      baseLang(navigator.language || navigator.userLanguage) ||
      "en"
    );
  }

  function applyStrings(strings) {
    var els = document.querySelectorAll("[data-i18n]");
    for (var i = 0; i < els.length; i++) {
      var k = els[i].getAttribute("data-i18n");
      if (strings && strings[k] != null) els[i].textContent = strings[k];
    }
    document.documentElement.lang = window.__pdfLang || "en";
  }

  function loadLang(lang) {
    window.__pdfLang = lang;
    return fetch("/i18n/" + lang + "/translation.json")
      .then(function (r) {
        if (!r.ok) throw new Error("lang load failed: " + lang);
        return r.json();
      })
      .then(applyStrings)
      .catch(function () {
        if (lang !== "en") return loadLang("en");
      });
  }

  // Rebuild <select> options from the server list, replacing the fallback.
  // No-op when there is no select on the page (footer.html).
  function rebuildSelect(langs) {
    var sel = document.getElementById("lang");
    if (!sel) return;
    while (sel.firstChild) sel.removeChild(sel.firstChild);
    langs.forEach(function (l) {
      var opt = document.createElement("option");
      opt.value = l;
      opt.textContent = l;
      sel.appendChild(opt);
    });
  }

  function loadLangList() {
    return fetch("/api/languages")
      .then(function (r) {
        if (!r.ok) throw new Error("languages list failed");
        return r.json();
      })
      .then(function (data) {
        var list = (data && data.languages) || [];
        list = list
          .map(String)
          .filter(function (l, i, a) {
            return l && a.indexOf(l) === i;
          })
          .sort();
        if (!list.length) throw new Error("empty languages list");
        if (list.indexOf("en") === -1) list.unshift("en");
        LANGS = list;
        rebuildSelect(LANGS);
      })
      .catch(function () {
        // Server unreachable (e.g. file:// preview): keep the "en" fallback.
        LANGS = FALLBACK_LANGS.slice();
      });
  }

  function setLang(lang, save) {
    if (LANGS.indexOf(lang) === -1) lang = "en";
    if (save !== false) setCookie(KEY, lang);
    var sel = document.getElementById("lang");
    if (sel) sel.value = lang;
    return loadLang(lang);
  }

  window.PdfI18n = {
    langs: function () {
      return LANGS.slice();
    },
    currentLang: currentLang,
    setLang: setLang,
    init: function () {
      var sel = document.getElementById("lang");
      if (sel) {
        sel.addEventListener("change", function () {
          // Persist the explicit choice, then reload the whole preview
          // shell so every iframe picks it up from the cookie.
          setLang(sel.value, true).then(function () {
            try {
              window.top.location.reload();
            } catch (e) {}
          });
        });
      }
      return loadLangList().then(function () {
        var q = queryLang();
        if (q) {
          // Explicit ?lang= wins and is persisted so sibling
          // iframes (no query string) pick it up via the cookie.
          return setLang(q, true);
        }
        var stored = storedLang();
        if (!stored) {
          // Persist the resolved default so PHP sees the same language.
          return setLang(currentLang(), true);
        }
        return setLang(stored, false);
      });
    },
  };
})();
