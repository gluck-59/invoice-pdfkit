(function () {
  var MAX = 200;
  var MIN_BAR_MS = 400;
  var drop = document.getElementById('drop');
  if (!drop) return;
  var input = document.getElementById('logo-file');
  var form = drop.closest('form');
  var errMsg = input && input.dataset.uploadError ? input.dataset.uploadError : 'Upload error';
  var sourceFile = null;
  var resizedFile = null;
  var barShownAt = 0;

  var bar = document.createElement('div');
  bar.id = 'upload-progress';
  document.body.appendChild(bar);
  var style = document.createElement('style');
  style.textContent =
    '#upload-progress{position:absolute;top:10px;left:0;width:100%;height:3px;display:none;overflow:hidden;z-index:9999}' +
    '#upload-progress.on{display:block}' +
    '#upload-progress::before{content:"";position:absolute;top:0;left:0;height:100%;width:40%;background:#2c7;animation:upload-progress-run 1s linear infinite}' +
    '@keyframes upload-progress-run{0%{left:-40%}100%{left:100%}}';
  document.head.appendChild(style);

  function showBar() { barShownAt = Date.now(); bar.classList.add('on'); }
  function hideBar() {
    var wait = Math.max(0, MIN_BAR_MS - (Date.now() - barShownAt));
    window.setTimeout(function () { bar.classList.remove('on'); }, wait);
  }

  function resize(file) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () {
        try {
          var ratio = Math.min(MAX / img.width, MAX / img.height, 1);
          var w = Math.max(1, Math.round(img.width * ratio));
          var h = Math.max(1, Math.round(img.height * ratio));
          var c = document.createElement('canvas');
          c.width = w;
          c.height = h;
          var ctx = c.getContext('2d');
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0, 0, w, h);
          ctx.drawImage(img, 0, 0, w, h);
          c.toBlob(function (blob) {
            try {
              if (!blob) throw new Error('empty canvas');
              var base = (file.name || 'logo').replace(/\.[^.]+$/, '') || 'logo';
              resolve(new File([blob], base + '.jpg', { type: 'image/jpeg' }));
            } catch (err) {
              reject(err);
            }
          }, 'image/jpeg', 0.5);
        } catch (err) {
          reject(err);
        }
      };
      img.onerror = function () { reject(new Error('load')); };
      img.src = URL.createObjectURL(file);
    });
  }

  function acceptFile(file) {
    if (!file) return;
    sourceFile = file;
    resizedFile = null;
    showBar();
    resize(file).then(function (rf) {
      resizedFile = rf;
      var url = URL.createObjectURL(rf);
      var prev = document.querySelector('.logo-preview');
      if (prev) {
        var pimg = prev.querySelector('img');
        if (pimg) pimg.src = url;
        else prev.innerHTML = '<img src="' + url + '" alt="logo">';
      }
    }).catch(function () {
      resizedFile = null;
      alert(errMsg);
    }).finally(function () {
      hideBar();
    });
  }

  ['dragover', 'dragenter'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
  });
  ['dragleave'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function (e) {
    e.preventDefault();
    drop.classList.remove('over');
    if (e.dataTransfer.files.length) acceptFile(e.dataTransfer.files[0]);
  });
  drop.addEventListener('click', function () { if (input) input.click(); });
  if (input) {
    input.addEventListener('change', function () { if (input.files.length) acceptFile(input.files[0]); });
  }

  function submitForm() {
    var fd = new FormData(form);
    if (resizedFile) {
      fd.set('logo', resizedFile);
    } else {
      fd.delete('logo');
    }
    showBar();
    fetch(form.action || '/', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      redirect: 'follow'
    }).then(function (response) {
      hideBar();
      if (response.redirected && response.url) {
        window.location.href = response.url;
      } else {
        alert(errMsg);
      }
    }).catch(function () {
      hideBar();
      alert(errMsg);
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (resizedFile) {
        submitForm();
        return;
      }
      var f = sourceFile || (input && input.files.length ? input.files[0] : null);
      if (!f) {
        submitForm();
        return;
      }
      showBar();
      resize(f).then(function (rf) {
        resizedFile = rf;
        submitForm();
      }).catch(function () {
        resizedFile = null;
        hideBar();
        alert(errMsg);
      });
    });
  }
})();
