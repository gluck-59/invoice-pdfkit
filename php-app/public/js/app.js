    (function () {
      var z = document.getElementById('drop');
      if (!z) return;
      var f = document.getElementById('logo-file');
      ['dragover', 'dragenter'].forEach(function (ev) {
        z.addEventListener(ev, function (e) { e.preventDefault(); z.classList.add('over'); });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        z.addEventListener(ev, function (e) { e.preventDefault(); z.classList.remove('over'); });
      });
      z.addEventListener('drop', function (e) {
        if (e.dataTransfer.files.length) f.files = e.dataTransfer.files;
      });
      z.addEventListener('click', function () { f.click(); });
    })();
