// Shared i18n for the HTML prototypes (browser only, no build step).
// Loads translations from /locales/{lang}/translation.json (served by node).
(function () {
  "use strict";

  var LANGS = ["de", "en", "nl", "ru"];
  var KEY = "pdf-lang";

  function baseLang(tag) {
    if (!tag) return null;
    var b = String(tag).toLowerCase().split(/[-_]/)[0];
    return LANGS.indexOf(b) !== -1 ? b : null;
  }

  function currentLang() {
    try {
      var saved = localStorage.getItem(KEY);
      if (saved && LANGS.indexOf(saved) !== -1) return saved;
    } catch (e) {}
    return (
      baseLang(navigator.language || navigator.userLanguage) || "en"
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
    return fetch("/locales/" + lang + "/translation.json")
      .then(function (r) {
        if (!r.ok) throw new Error("lang load failed: " + lang);
        return r.json();
      })
      .then(applyStrings)
      .catch(function () {
        if (lang !== "en") return loadLang("en");
      });
  }

  function setLang(lang, save) {
    if (LANGS.indexOf(lang) === -1) lang = "en";
    if (save !== false) {
      try {
        localStorage.setItem(KEY, lang);
      } catch (e) {}
    }
    var sel = document.getElementById("lang");
    if (sel) sel.value = lang;
    return loadLang(lang);
  }

  // Cross-frame sync (index.html embeds header/footer via iframes)
  window.addEventListener("storage", function (e) {
    if (e.key === KEY && e.newValue) loadLang(e.newValue);
  });

  window.PdfI18n = {
    LANGS: LANGS,
    currentLang: currentLang,
    setLang: setLang,
    init: function () {
      var sel = document.getElementById("lang");
      if (sel) {
        sel.addEventListener("change", function () {
          setLang(sel.value);
        });
      }
      return setLang(currentLang(), false).then(function () {
        // persist the resolved default so other frames pick it up
        try {
          localStorage.setItem(KEY, window.__pdfLang);
        } catch (e) {}
      });
    },
  };
})();
